<?php

declare(strict_types=1);

namespace Drupal\makerspace_referrals\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\IntegrityConstraintViolationException;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\user\UserInterface;

/**
 * Thanks the member who introduced somebody, and chases the ones we cannot.
 *
 * Phase 3 of the referral track, and deliberately independent of the money:
 * 88% of people who say a member referred them volunteer that member's name
 * unprompted, and until now not one of them was ever acknowledged. This ships
 * the promise the site already makes, needs no billing involvement, and is the
 * cheapest test of whether the programme does anything at all.
 *
 * It runs on the same trigger as the credit — the referred member's first
 * successful charge — so we never thank somebody for a signup that bounced.
 */
class ReferralThanks {

  /**
   * The referrer was identified and emailed.
   */
  public const SENT = 'sent';

  /**
   * Identified, but their address is suppressed, so nothing was sent.
   */
  public const SUPPRESSED = 'suppressed';

  /**
   * They named somebody, but staff have not confirmed who it is yet.
   */
  public const UNRESOLVED = 'unresolved';

  /**
   * They named nobody. There is nothing owed and nobody to thank.
   */
  public const NONE = 'none';

  public function __construct(
    private readonly Connection $database,
    private readonly EntityTypeManagerInterface $entities,
    private readonly TimeInterface $time,
    private readonly MailManagerInterface $mail,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly ReferralAward $awards,
    private readonly ReferralReview $review,
    private readonly LoggerChannelInterface $log,
  ) {}

  /**
   * Handles the thank-you for one newly-paying member.
   *
   * Exactly one row is written per referred member, whatever the outcome —
   * including "nobody was named" — because the states we most need to see are
   * the ones that produce no email. A queue that grows while nothing sends is
   * how the referral answer sat unused for two years.
   *
   * @return string
   *   One of the class constants, or an empty string when already handled.
   */
  public function handle(int $referred_uid): string {
    $resolved = $this->awards->resolveReferrer($referred_uid);
    $referrer_uid = (int) ($resolved['uid'] ?? 0);

    // Work out what we are going to do BEFORE claiming the row, but send
    // nothing yet.
    if ($resolved) {
      $status = $this->canEmail($referrer_uid) ? self::SENT : self::SUPPRESSED;
    }
    elseif ($this->namedSomebodyUnconfirmed($referred_uid)) {
      // They told us who introduced them and nobody has confirmed it. That is
      // a person waiting on a decision, not an absence of information.
      $status = self::UNRESOLVED;
    }
    else {
      $status = self::NONE;
    }

    // Claim first, send second. The insert is the lock: if a replayed webhook
    // gets here concurrently the unique key refuses it, and the email has not
    // gone out yet. Sending first and recording afterwards looks equivalent
    // and is not — it thanks people twice.
    if (!$this->record($referred_uid, $referrer_uid, $status)) {
      return '';
    }

    if ($status === self::SENT) {
      $this->send($referrer_uid, $referred_uid);
    }
    elseif ($status === self::SUPPRESSED) {
      $this->log->notice('Not thanking uid @r for referring uid @u: their address is suppressed.', [
        '@r' => $referrer_uid,
        '@u' => $referred_uid,
      ]);
    }
    elseif ($status === self::UNRESOLVED) {
      $this->tellStaff($referred_uid);
    }

    return $status;
  }

  /**
   * Whether we may email this account at all.
   */
  private function canEmail(int $uid): bool {
    $user = $this->entities->getStorage('user')->load($uid);
    return $user instanceof UserInterface && !$this->isSuppressed($user);
  }

  /**
   * Sends the thank-you.
   */
  private function send(int $referrer_uid, int $referred_uid): void {
    $referrer = $this->entities->getStorage('user')->load($referrer_uid);
    $referred = $this->entities->getStorage('user')->load($referred_uid);
    if (!$referrer instanceof UserInterface || !$referred instanceof UserInterface) {
      return;
    }

    $this->mail->mail(
      'makerspace_referrals',
      'thanks',
      $referrer->getEmail(),
      $referrer->getPreferredLangcode(),
      [
        'referrer' => $referrer,
        'referred' => $referred,
        // Only mention money when money actually exists for this referral, so
        // the email cannot promise a credit that was never recorded.
        'award' => $this->awardFor($referred_uid),
      ],
    );

    $this->log->notice('Thanked uid @r for referring uid @u.', ['@r' => $referrer_uid, '@u' => $referred_uid]);
  }

  /**
   * Tells staff that somebody named a referrer nobody has confirmed.
   *
   * This is the half that makes the review queue get worked. As of
   * 2026-09-17 it held 545 pending answers against 2 decisions ever recorded,
   * which is what happens when a queue only grows and never asks for anything.
   */
  private function tellStaff(int $referred_uid): void {
    $to = trim((string) $this->configFactory->get('makerspace_referrals.settings')->get('staff_email'));
    if ($to === '') {
      return;
    }
    $referred = $this->entities->getStorage('user')->load($referred_uid);
    if (!$referred instanceof UserInterface) {
      return;
    }
    $this->mail->mail(
      'makerspace_referrals',
      'unresolved',
      $to,
      $referred->getPreferredLangcode(),
      ['referred' => $referred, 'answer' => $this->answerFor($referred_uid)],
    );
  }

  /**
   * Whether this member named somebody that staff have not confirmed.
   */
  private function namedSomebodyUnconfirmed(int $referred_uid): bool {
    return $this->answerFor($referred_uid) !== '';
  }

  /**
   * The referrer name this member typed, if any.
   */
  private function answerFor(int $referred_uid): string {
    if (!$this->entities->hasDefinition('profile')) {
      return '';
    }
    $ids = $this->entities->getStorage('profile')->getQuery()
      ->accessCheck(FALSE)
      ->condition('uid', $referred_uid)
      ->condition('type', 'main')
      ->execute();
    foreach ($ids as $id) {
      $profile = $this->entities->getStorage('profile')->load($id);
      if ($profile) {
        $answer = trim($this->review->source($profile));
        if ($answer !== '') {
          return $answer;
        }
      }
    }
    return '';
  }

  /**
   * The award row for a referral, when one exists.
   */
  private function awardFor(int $referred_uid): ?object {
    $row = $this->database->select('makerspace_referral_award', 'a')
      ->fields('a')
      ->condition('referred_uid', $referred_uid)
      ->execute()
      ->fetchObject();
    return $row ?: NULL;
  }

  /**
   * Whether we are allowed to email this person at all.
   *
   * The field carries a reason (moved, unsubscribed, bad address, other); any
   * value at all means do not send. A thank-you is a nice email, but it is
   * still an email somebody asked us to stop sending.
   */
  private function isSuppressed(UserInterface $user): bool {
    if (!$user->hasField('field_mail_suppression')) {
      return FALSE;
    }
    return trim((string) $user->get('field_mail_suppression')->value) !== '';
  }

  /**
   * Writes the outcome, refusing a second row for the same member.
   */
  private function record(int $referred_uid, int $referrer_uid, string $status): bool {
    try {
      $this->database->insert('makerspace_referral_thanks')
        ->fields([
          'referred_uid' => $referred_uid,
          'referrer_uid' => $referrer_uid,
          'status' => $status,
          'created' => $this->time->getRequestTime(),
        ])
        ->execute();
      return TRUE;
    }
    catch (IntegrityConstraintViolationException) {
      return FALSE;
    }
  }

  /**
   * Whether this member's referral has already been handled.
   */
  public function handled(int $referred_uid): bool {
    return (bool) $this->database->select('makerspace_referral_thanks', 't')
      ->fields('t', ['id'])
      ->condition('referred_uid', $referred_uid)
      ->range(0, 1)
      ->execute()
      ->fetchField();
  }

  /**
   * The members somebody has been credited with introducing.
   *
   * Used by the member's own page. Only referrals that reached the
   * thank-you stage appear, so this never shows a name before it is real.
   */
  public function referredBy(int $referrer_uid): array {
    $rows = $this->database->select('makerspace_referral_thanks', 't')
      ->fields('t')
      ->condition('referrer_uid', $referrer_uid)
      ->condition('status', [self::SENT, self::SUPPRESSED], 'IN')
      ->orderBy('t.id', 'DESC')
      ->execute()
      ->fetchAll();

    $out = [];
    foreach ($rows as $row) {
      $user = $this->entities->getStorage('user')->load((int) $row->referred_uid);
      if ($user instanceof UserInterface) {
        $out[] = ['user' => $user, 'when' => (int) $row->created];
      }
    }
    return $out;
  }

  /**
   * Counts outcomes, for the staff console.
   */
  public function countsByStatus(): array {
    $counts = [self::SENT => 0, self::SUPPRESSED => 0, self::UNRESOLVED => 0, self::NONE => 0];
    $query = $this->database->select('makerspace_referral_thanks', 't');
    $query->addField('t', 'status');
    $query->addExpression('COUNT(*)', 'n');
    $query->groupBy('t.status');
    foreach ($query->execute() as $row) {
      $counts[$row->status] = (int) $row->n;
    }
    return $counts;
  }

}
