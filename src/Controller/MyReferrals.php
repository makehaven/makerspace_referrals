<?php

declare(strict_types=1);

namespace Drupal\makerspace_referrals\Controller;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\makerspace_referrals\Service\ReferralInvite;
use Drupal\makerspace_referrals\Service\ReferralThanks;
use Drupal\user\UserInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Shows a member the people they introduced.
 *
 * The recognition half of Phase 3. The thank-you email is a moment; this is
 * the standing version of it, and it is also the only place a member can see
 * whether an invitation they sent ever went anywhere.
 */
class MyReferrals extends ControllerBase {

  public function __construct(
    private readonly ReferralThanks $thanks,
    private readonly ReferralInvite $invites,
    private readonly DateFormatterInterface $dates,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('makerspace_referrals.thanks'),
      $container->get('makerspace_referrals.invite'),
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
    $referred = $this->thanks->referredBy((int) $user->id());
    $invites = $this->invites->sentBy((int) $user->id());

    $build = [];

    $build['intro'] = [
      '#markup' => '<p>' . $this->t('People who told us you are the reason they joined.') . '</p>',
    ];

    if ($referred) {
      $items = [];
      foreach ($referred as $entry) {
        $items[] = $this->t('@name — joined @when', [
          '@name' => $entry['user']->getDisplayName(),
          '@when' => $this->dates->format($entry['when'], 'custom', 'F Y'),
        ]);
      }
      $build['referred'] = [
        '#theme' => 'item_list',
        '#title' => $this->formatPlural(count($items), '1 member', '@count members'),
        '#items' => $items,
      ];
    }
    else {
      $build['referred'] = [
        '#markup' => '<p>' . $this->t('Nobody yet. If you have told someone about us, ask them to name you when they join — that is how we know.') . '</p>',
      ];
    }

    if ($invites) {
      $rows = [];
      foreach ($invites as $invite) {
        $rows[] = [
          trim($invite->invitee_first . ' ' . $invite->invitee_last),
          $this->dates->format((int) $invite->created, 'custom', 'j M Y'),
          $invite->claimed_uid ? $this->t('Joined') : $this->t('Not yet'),
        ];
      }
      $build['invites'] = [
        '#type' => 'table',
        '#caption' => $this->t('Invitations you have sent'),
        '#header' => [$this->t('Who'), $this->t('Sent'), $this->t('Joined?')],
        '#rows' => $rows,
      ];
    }

    $build['invite_link'] = [
      '#type' => 'link',
      '#title' => $this->t('Invite someone'),
      '#url' => Url::fromRoute('makerspace_referrals.invite'),
      '#attributes' => ['class' => ['button']],
      '#access' => $this->currentUser()->hasPermission('send member referral invitations'),
    ];

    // Changes the moment somebody they referred joins.
    $build['#cache']['max-age'] = 0;

    return $build;
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
