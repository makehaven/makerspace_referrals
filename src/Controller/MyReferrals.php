<?php

declare(strict_types=1);

namespace Drupal\makerspace_referrals\Controller;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\makerspace_referrals\Service\ReferralInvite;
use Drupal\makerspace_referrals\Service\ReferralStats;
use Drupal\makerspace_referrals\Service\ReferralThanks;
use Drupal\user\UserInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Shows a member the people they introduced.
 *
 * The page is about money we have promised, so the rule running through it is
 * that it reports **what we know, never what we intend**:
 *
 * - A name nobody has confirmed does not count toward the total. Somebody
 *   suggesting you is not the same as staff deciding it was you.
 * - A credit is shown only because a ledger row records the API call that
 *   applied it, never because the rules say one was due. We have already been
 *   in the position of not knowing what was paid; a page asserting payment
 *   from policy would make that worse.
 * - A member who has introduced nobody sees an invitation, not a zero.
 *   "You have referred 0 members" is a reproach.
 */
class MyReferrals extends ControllerBase {

  public function __construct(
    private readonly ReferralThanks $thanks,
    private readonly ReferralInvite $invites,
    private readonly ReferralStats $stats,
    private readonly Connection $database,
    private readonly DateFormatterInterface $dates,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('makerspace_referrals.thanks'),
      $container->get('makerspace_referrals.invite'),
      $container->get('makerspace_referrals.stats'),
      $container->get('database'),
      $container->get('date.formatter'),
    );
  }

  /**
   * Your own page, or a staff member looking at somebody else's.
   *
   * Who introduced whom is not public. A member sees their own; staff who
   * already review referrals see anyone's.
   */
  public function access(AccountInterface $account, UserInterface $user): AccessResultInterface {
    if ((int) $account->id() === (int) $user->id()) {
      return AccessResult::allowed()->cachePerUser()->addCacheableDependency($user);
    }
    return AccessResult::allowedIfHasPermission($account, 'review member referrals')
      ->cachePerPermissions()
      ->addCacheableDependency($user);
  }

  /**
   * Builds the page.
   */
  public function build(UserInterface $user): array {
    $uid = (int) $user->id();
    $rows = $this->resolvedReferrals($uid);
    $awaiting = $this->stats->awaitingConfirmationFor($uid);
    $invites = $this->invites->sentBy($uid);

    $build = [];

    if ($rows) {
      $items = [];
      foreach ($rows as $row) {
        $items[] = ['#markup' => $this->describe($row)];
      }
      $build['referred'] = [
        '#theme' => 'item_list',
        '#title' => $this->formatPlural(count($items), 'You introduced 1 member', 'You introduced @count members'),
        '#items' => $items,
      ];
    }
    else {
      // No counter. Somebody who has introduced nobody is shown the thing that
      // would change that, not a score of zero.
      $build['empty'] = [
        '#markup' => '<p>' . $this->t('Know someone who would like it here? Invite them — when they join and mention you, they show up on this page.') . '</p>',
      ];
    }

    if ($awaiting > 0) {
      $build['awaiting'] = [
        '#markup' => '<p>' . $this->formatPlural(
          $awaiting,
          'Someone named you as the reason they joined. We are confirming it.',
          '@count people named you as the reason they joined. We are confirming those.',
        ) . '</p>',
      ];
    }

    if ($invites) {
      $invite_rows = [];
      foreach ($invites as $invite) {
        $invite_rows[] = [
          trim($invite->invitee_first . ' ' . $invite->invitee_last),
          $this->dates->format((int) $invite->created, 'custom', 'j M Y'),
          $invite->claimed_uid ? $this->t('Joined') : $this->t('Not yet'),
        ];
      }
      $build['invites'] = [
        '#type' => 'table',
        '#caption' => $this->t('Invitations you have sent'),
        '#header' => [$this->t('Who'), $this->t('Sent'), $this->t('Joined?')],
        '#rows' => $invite_rows,
      ];
    }

    $build['invite_link'] = [
      '#type' => 'link',
      '#title' => $this->t('Invite someone'),
      '#url' => Url::fromRoute('makerspace_referrals.invite'),
      '#attributes' => ['class' => ['button']],
      '#access' => $this->currentUser()->hasPermission('send member referral invitations'),
    ];

    // Changes the moment somebody they referred joins or is confirmed.
    $build['#cache']['max-age'] = 0;

    return $build;
  }

  /**
   * One line per referred member, saying only what is recorded.
   */
  private function describe(array $row): string {
    $line = $this->t('@name — joined @when', [
      '@name' => $row['name'],
      '@when' => $this->dates->format($row['joined'], 'custom', 'F Y'),
    ]);

    if ($row['credited_at'] !== NULL) {
      // From the award ledger, and only from there.
      return $line . '<br /><small>' . $this->t('@amount credit applied on @date', [
        '@amount' => '$' . number_format($row['credited_cents'] / 100, 2),
        '@date' => $this->dates->format($row['credited_at'], 'custom', 'j F Y'),
      ]) . '</small>';
    }

    if ($row['thanked_at'] !== NULL) {
      return $line . '<br /><small>' . $this->t('We thanked you on @date', [
        '@date' => $this->dates->format($row['thanked_at'], 'custom', 'j F Y'),
      ]) . '</small>';
    }

    return (string) $line;
  }

  /**
   * The confirmed referrals for an account, with whatever else is recorded.
   */
  private function resolvedReferrals(int $uid): array {
    $profile_ids = $this->stats->profilesReferredBy($uid);
    if (!$profile_ids) {
      return [];
    }

    $profiles = $this->entityTypeManager()->getStorage('profile')->loadMultiple($profile_ids);
    $rows = [];

    foreach ($profiles as $profile) {
      $referred = $profile->getOwner();
      if (!$referred instanceof UserInterface) {
        continue;
      }
      $referred_uid = (int) $referred->id();

      // Only an 'awarded' row may produce a credited state. A pending or
      // failed award means nobody has been paid, and saying otherwise is the
      // exact mistake this page exists to avoid.
      $award = $this->database->select('makerspace_referral_award', 'a')
        ->fields('a', ['amount_cents', 'updated'])
        ->condition('a.referred_uid', $referred_uid)
        ->condition('a.status', 'awarded')
        ->execute()
        ->fetchObject();

      $thanked = $this->database->select('makerspace_referral_thanks', 't')
        ->fields('t', ['created'])
        ->condition('t.referred_uid', $referred_uid)
        ->condition('t.status', ReferralThanks::SENT)
        ->execute()
        ->fetchField();

      $rows[] = [
        'name' => $this->shortName($referred),
        'joined' => (int) $profile->getCreatedTime(),
        'thanked_at' => $thanked !== FALSE ? (int) $thanked : NULL,
        'credited_at' => $award ? (int) $award->updated : NULL,
        'credited_cents' => $award ? (int) $award->amount_cents : 0,
      ];
    }

    usort($rows, static fn(array $a, array $b) => $b['joined'] <=> $a['joined']);
    return $rows;
  }

  /**
   * First name and last initial.
   *
   * The referrer knows this person, but the referred member never agreed to
   * appear on somebody else's page. A first name and an initial is enough for
   * recognition without publishing a roster.
   */
  private function shortName(UserInterface $user): string {
    $first = $user->hasField('field_first_name') ? trim((string) $user->get('field_first_name')->value) : '';
    $last = $user->hasField('field_last_name') ? trim((string) $user->get('field_last_name')->value) : '';

    if ($first === '') {
      return $user->getDisplayName();
    }
    return $last === '' ? $first : $first . ' ' . mb_substr($last, 0, 1) . '.';
  }

  /**
   * Page title.
   */
  public function title(UserInterface $user): string {
    return (int) $this->currentUser()->id() === (int) $user->id()
      ? (string) $this->t('People you introduced')
      : (string) $this->t('People @name introduced', ['@name' => $user->getDisplayName()]);
  }

}
