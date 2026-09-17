<?php

declare(strict_types=1);

namespace Drupal\makerspace_referrals\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\user\UserInterface;

/**
 * Records invitations a member sent, and emails the person they invited.
 *
 * The point of storing them is attribution: when the invited person pays for
 * the first time we can say who sent them without anyone remembering. This is
 * the structural half of the two agreed routes — the other is the invited
 * person naming someone at signup.
 */
class ReferralInvite {

  public function __construct(
    private readonly Connection $database,
    private readonly EntityTypeManagerInterface $entities,
    private readonly TimeInterface $time,
    private readonly MailManagerInterface $mail,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly LoggerChannelInterface $log,
  ) {}

  /**
   * Normalises an address for comparison.
   *
   * Everything stored and everything looked up passes through here, so the
   * invitee typing a different capitalisation at checkout than their friend
   * typed on the form cannot silently cost them the credit.
   */
  public function normalise(string $email): string {
    return mb_strtolower(trim($email));
  }

  /**
   * Stores an invitation and emails the invitee.
   *
   * @return int
   *   The invitation id.
   */
  public function create(UserInterface $inviter, string $email, string $first, string $last): int {
    $email = $this->normalise($email);
    $now = $this->time->getRequestTime();

    $id = (int) $this->database->insert('makerspace_referral_invite')
      ->fields([
        'inviter_uid' => (int) $inviter->id(),
        'invitee_email' => $email,
        'invitee_first' => mb_substr(trim($first), 0, 128),
        'invitee_last' => mb_substr(trim($last), 0, 128),
        'created' => $now,
      ])
      ->execute();

    $this->log->notice('Referral invitation @id: uid @u invited @e.', [
      '@id' => $id,
      '@u' => $inviter->id(),
      '@e' => $email,
    ]);

    $this->mail->mail(
      'makerspace_referrals',
      'invitation',
      $email,
      $inviter->getPreferredLangcode(),
      [
        'inviter' => $inviter,
        'first' => trim($first),
        'last' => trim($last),
        'email' => $email,
        'join_url' => $this->joinUrl($email, $first, $last),
      ],
      $inviter->getEmail(),
    );

    return $id;
  }

  /**
   * Finds an unclaimed invitation for an address.
   *
   * Oldest first: if two members both invited the same person, the one who
   * asked first is the one who gets the credit, which is the only tie-break
   * that does not require a judgement call.
   */
  public function findOpenForEmail(string $email): ?object {
    $email = $this->normalise($email);
    if ($email === '') {
      return NULL;
    }
    $row = $this->database->select('makerspace_referral_invite', 'i')
      ->fields('i')
      ->condition('invitee_email', $email)
      ->condition('claimed_uid', 0)
      ->orderBy('i.id', 'ASC')
      ->range(0, 1)
      ->execute()
      ->fetchObject();
    return $row ?: NULL;
  }

  /**
   * Marks the open invitation for an address as claimed.
   */
  public function markClaimed(string $email, int $claimed_uid): void {
    $invite = $this->findOpenForEmail($email);
    if (!$invite) {
      return;
    }
    $this->database->update('makerspace_referral_invite')
      ->fields(['claimed_uid' => $claimed_uid, 'claimed' => $this->time->getRequestTime()])
      ->condition('id', $invite->id)
      ->execute();
  }

  /**
   * Invitations a member has sent, newest first.
   */
  public function sentBy(int $uid, int $limit = 50): array {
    return $this->database->select('makerspace_referral_invite', 'i')
      ->fields('i')
      ->condition('inviter_uid', $uid)
      ->orderBy('i.id', 'DESC')
      ->range(0, $limit)
      ->execute()
      ->fetchAll();
  }

  /**
   * Recent invitations across everyone, for the staff console.
   */
  public function recent(int $limit = 100): array {
    return $this->database->select('makerspace_referral_invite', 'i')
      ->fields('i')
      ->orderBy('i.id', 'DESC')
      ->range(0, $limit)
      ->execute()
      ->fetchAll();
  }

  /**
   * The Chargebee checkout link, pre-filled the way the household invite is.
   *
   * The household invitation (webform_24988, 86 uses since 2024) proved this
   * shape: a hosted-page URL carrying the invitee's name and email so they do
   * not retype them. The difference here is the coupon, which is what makes
   * the offer real.
   */
  public function joinUrl(string $email, string $first, string $last): string {
    $settings = $this->configFactory->get('makerspace_referrals.settings');
    $base = trim((string) $settings->get('join_url'));
    if ($base === '') {
      return '';
    }
    $params = [
      'customer[email]' => $this->normalise($email),
      'customer[first_name]' => trim($first),
      'customer[last_name]' => trim($last),
    ];
    $coupon = trim((string) $settings->get('invitee_coupon'));
    if ($coupon !== '') {
      $params['subscription[coupon]'] = $coupon;
    }
    return $base . (str_contains($base, '?') ? '&' : '?') . http_build_query($params);
  }

}
