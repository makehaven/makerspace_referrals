<?php

declare(strict_types=1);

namespace Drupal\makerspace_referrals\Service;

use Drupal\Core\Database\Connection;
use Drupal\Core\Database\IntegrityConstraintViolationException;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\user\UserInterface;
use Drupal\Component\Datetime\TimeInterface;

/**
 * Decides who is owed a referral credit, and pays it.
 *
 * The rules were settled by Kate and JR on 2026-09-17 and are deliberately
 * simple: a flat amount to the referrer, on the referred member's first
 * successful charge, and only when the referred member actually identified
 * somebody. We never infer a referrer.
 */
class ReferralAward {

  /**
   * The flat credit, in cents.
   *
   * Flat by decision, not by plan. The published page once said "up to $50
   * based on plan type"; that was replaced with a single number because the
   * variable version bought nothing and cost a formula nobody could check.
   */
  public const AMOUNT_CENTS = 5000;

  /**
   * Awarded because the referred member used the emailed invitation link.
   */
  public const SOURCE_INVITE = 'invite';

  /**
   * Awarded because they named a member at signup and staff confirmed it.
   */
  public const SOURCE_REVIEW = 'review';

  /**
   * Maximum automatic attempts before an award waits for a human.
   */
  public const MAX_ATTEMPTS = 5;

  public function __construct(
    private readonly Connection $database,
    private readonly EntityTypeManagerInterface $entities,
    private readonly TimeInterface $time,
    private readonly QueueFactory $queueFactory,
    private readonly ReferralReview $review,
    private readonly ReferralInvite $invites,
    private readonly ChargebeeCredit $chargebee,
    private readonly LoggerChannelInterface $log,
  ) {}

  /**
   * Finds who referred a member, by either of the two agreed routes.
   *
   * Order matters. An invitation is a record of something that happened; a
   * self-reported name is a claim a human confirmed. When both exist they
   * should agree, and if they do not, the invitation is the harder evidence.
   *
   * @return array|null
   *   ['uid' => int, 'source' => string] or NULL when nobody is identified.
   */
  public function resolveReferrer(int $referred_uid): ?array {
    $user = $this->entities->getStorage('user')->load($referred_uid);
    if (!$user instanceof UserInterface) {
      return NULL;
    }

    $invite = $this->invites->findOpenForEmail((string) $user->getEmail());
    if ($invite && (int) $invite->inviter_uid !== $referred_uid) {
      return ['uid' => (int) $invite->inviter_uid, 'source' => self::SOURCE_INVITE];
    }

    foreach ($this->mainProfileIds($referred_uid) as $profile_id) {
      // Entity queries return ids as strings.
      $referrer = $this->review->confirmedReferrer((int) $profile_id);
      if ($referrer && (int) $referrer->id() !== $referred_uid) {
        return ['uid' => (int) $referrer->id(), 'source' => self::SOURCE_REVIEW];
      }
    }

    return NULL;
  }

  /**
   * Records that a member is owed a credit and queues the payment.
   *
   * Returns the award id, or NULL when nothing is owed or one already exists.
   * Writing the row first and paying second is what makes this safe to call
   * from a webhook that Chargebee may replay: the unique key on referred_uid
   * refuses the second insert, so the second call queues nothing.
   */
  public function recordAndQueue(int $referred_uid): ?int {
    $resolved = $this->resolveReferrer($referred_uid);
    if (!$resolved) {
      $this->log->notice('No referrer identified for uid @u; no credit is owed.', ['@u' => $referred_uid]);
      return NULL;
    }

    $now = $this->time->getRequestTime();
    try {
      $id = (int) $this->database->insert('makerspace_referral_award')
        ->fields([
          'referrer_uid' => $resolved['uid'],
          'referred_uid' => $referred_uid,
          'referrer_cb_id' => $this->chargebeeIdFor($resolved['uid']),
          'amount_cents' => self::AMOUNT_CENTS,
          'source' => $resolved['source'],
          'status' => 'pending',
          'created' => $now,
          'updated' => $now,
        ])
        ->execute();
    }
    catch (IntegrityConstraintViolationException) {
      // Already recorded. This is the expected path for a replayed webhook,
      // and it is a no-op rather than an error.
      $this->log->notice('Referral credit for uid @u already recorded; ignoring duplicate trigger.', ['@u' => $referred_uid]);
      return NULL;
    }

    $this->invites->markClaimed((string) $this->entities->getStorage('user')->load($referred_uid)?->getEmail(), $referred_uid);

    // Never call Chargebee inside the webhook request: Chargebee is waiting on
    // our response, and a slow or failing API call there turns a payment
    // notification into a timeout.
    $this->queueFactory->get('makerspace_referral_award')->createItem(['award_id' => $id]);

    $this->log->notice('Referral credit owed: uid @r referred uid @u (@s); award @id queued.', [
      '@r' => $resolved['uid'],
      '@u' => $referred_uid,
      '@s' => $resolved['source'],
      '@id' => $id,
    ]);

    return $id;
  }

  /**
   * Sends one pending award to Chargebee and records what came back.
   *
   * @return string
   *   The award's status after the attempt.
   */
  public function pay(int $award_id): string {
    $award = $this->load($award_id);
    if (!$award) {
      return 'missing';
    }
    if ($award->status !== 'pending') {
      // Already settled or deliberately parked. Not an error.
      return $award->status;
    }

    $customer_id = $award->referrer_cb_id !== '' ? $award->referrer_cb_id : $this->chargebeeIdFor((int) $award->referrer_uid);
    $referred = $this->entities->getStorage('user')->load((int) $award->referred_uid);
    $description = sprintf(
      'MakeHaven referral credit — referred %s (uid %d)',
      $referred ? $referred->getAccountName() : 'member',
      (int) $award->referred_uid,
    );

    $result = $this->chargebee->addPromotionalCredit($customer_id, (int) $award->amount_cents, $description);
    $attempts = (int) $award->attempts + 1;

    $status = match ($result['outcome']) {
      ChargebeeCredit::OK => 'awarded',
      ChargebeeCredit::PERMANENT => 'failed',
      default => $attempts >= self::MAX_ATTEMPTS ? 'failed' : 'pending',
    };

    $this->database->update('makerspace_referral_award')
      ->fields([
        'status' => $status,
        'attempts' => $attempts,
        'referrer_cb_id' => $customer_id,
        'last_error' => $result['outcome'] === ChargebeeCredit::OK ? NULL : $result['message'],
        'chargebee_response' => $result['body'],
        'updated' => $this->time->getRequestTime(),
      ])
      ->condition('id', $award_id)
      ->execute();

    if ($status === 'failed') {
      // Loud on purpose. A referral we promised and did not pay is the single
      // worst outcome this feature has, and it must not be a quiet log line.
      $this->log->error('Referral credit FAILED for award @id (referrer uid @r, referred uid @u) after @n attempt(s): @m', [
        '@id' => $award_id,
        '@r' => $award->referrer_uid,
        '@u' => $award->referred_uid,
        '@n' => $attempts,
        '@m' => $result['message'],
      ]);
    }
    elseif ($status === 'awarded') {
      $this->log->notice('Referral credit paid: award @id, uid @r credited @a cents.', [
        '@id' => $award_id,
        '@r' => $award->referrer_uid,
        '@a' => $award->amount_cents,
      ]);
    }

    return $status;
  }

  /**
   * Marks an award reversed, without touching Chargebee.
   *
   * Reversing money is a decision, and the reversal itself happens in
   * Chargebee where the bookkeeper can see it. This only records that someone
   * made that decision here, and why.
   */
  public function reverse(int $award_id, int $actor_uid, string $why): bool {
    $award = $this->load($award_id);
    if (!$award || $award->status === 'reversed') {
      return FALSE;
    }
    $this->database->update('makerspace_referral_award')
      ->fields([
        'status' => 'reversed',
        'note' => trim($why),
        'updated' => $this->time->getRequestTime(),
      ])
      ->condition('id', $award_id)
      ->execute();
    $this->log->notice('Referral award @id reversed by uid @a: @w', [
      '@id' => $award_id,
      '@a' => $actor_uid,
      '@w' => $why,
    ]);
    return TRUE;
  }

  /**
   * Puts a failed award back in the queue for another attempt.
   */
  public function retry(int $award_id): bool {
    $award = $this->load($award_id);
    if (!$award || $award->status !== 'failed') {
      return FALSE;
    }
    $this->database->update('makerspace_referral_award')
      ->fields(['status' => 'pending', 'attempts' => 0, 'updated' => $this->time->getRequestTime()])
      ->condition('id', $award_id)
      ->execute();
    $this->queueFactory->get('makerspace_referral_award')->createItem(['award_id' => $award_id]);
    return TRUE;
  }

  /**
   * Whether this member has already earned their referrer a credit.
   *
   * Cheap enough to call on every renewal webhook, which is the point.
   */
  public function hasAward(int $referred_uid): bool {
    return (bool) $this->database->select('makerspace_referral_award', 'a')
      ->fields('a', ['id'])
      ->condition('referred_uid', $referred_uid)
      ->range(0, 1)
      ->execute()
      ->fetchField();
  }

  /**
   * Loads one award row.
   */
  public function load(int $award_id): ?object {
    $row = $this->database->select('makerspace_referral_award', 'a')
      ->fields('a')
      ->condition('id', $award_id)
      ->execute()
      ->fetchObject();
    return $row ?: NULL;
  }

  /**
   * Counts awards by status — the number Kate's console leads with.
   */
  public function countsByStatus(): array {
    $counts = ['pending' => 0, 'awarded' => 0, 'failed' => 0, 'reversed' => 0];
    $query = $this->database->select('makerspace_referral_award', 'a');
    $query->addField('a', 'status');
    $query->addExpression('COUNT(*)', 'n');
    $query->groupBy('a.status');
    foreach ($query->execute() as $row) {
      $counts[$row->status] = (int) $row->n;
    }
    return $counts;
  }

  /**
   * Lists awards, newest first.
   */
  public function listAwards(?string $status = NULL, int $limit = 100): array {
    $query = $this->database->select('makerspace_referral_award', 'a')
      ->fields('a')
      ->orderBy('a.id', 'DESC')
      ->range(0, $limit);
    if ($status !== NULL && $status !== '') {
      $query->condition('a.status', $status);
    }
    return $query->execute()->fetchAll();
  }

  /**
   * The referrer's Chargebee customer id, or an empty string.
   */
  private function chargebeeIdFor(int $uid): string {
    $user = $this->entities->getStorage('user')->load($uid);
    if (!$user instanceof UserInterface || !$user->hasField('field_user_chargebee_id')) {
      return '';
    }
    return trim((string) $user->get('field_user_chargebee_id')->value);
  }

  /**
   * Main profile ids belonging to a user.
   */
  private function mainProfileIds(int $uid): array {
    if (!$this->entities->hasDefinition('profile')) {
      return [];
    }
    return $this->entities->getStorage('profile')->getQuery()
      ->accessCheck(FALSE)
      ->condition('uid', $uid)
      ->condition('type', 'main')
      ->execute();
  }

}
