<?php

declare(strict_types=1);

namespace Drupal\makerspace_referrals\Controller;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Url;
use Drupal\makerspace_referrals\Service\ChargebeeCredit;
use Drupal\makerspace_referrals\Service\ReferralAward;
use Drupal\makerspace_referrals\Service\ReferralInvite;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Staff view of the money side: what we owe, what we paid, what broke.
 *
 * Kate asked for automation *and* transparency — to be able to watch it and
 * know when it is going wrong. That second half is this page, and it is a
 * deliverable rather than a nicety: an automatic payment nobody can see is
 * how you find out about a problem from the member instead of from the system.
 */
class ReferralAwards extends ControllerBase {

  public function __construct(
    private readonly ReferralAward $awards,
    private readonly ReferralInvite $invites,
    private readonly ChargebeeCredit $chargebee,
    private readonly ConfigFactoryInterface $settingsConfig,
    private readonly DateFormatterInterface $dates,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('makerspace_referrals.award'),
      $container->get('makerspace_referrals.invite'),
      $container->get('makerspace_referrals.chargebee_credit'),
      $container->get('config.factory'),
      $container->get('date.formatter'),
    );
  }

  /**
   * Builds the console.
   */
  public function build(): array {
    $build = [];
    $counts = $this->awards->countsByStatus();
    $settings = $this->settingsConfig->get('makerspace_referrals.settings');

    $build['state'] = $this->stateNotices($settings, $counts);

    $build['summary'] = [
      '#theme' => 'item_list',
      '#title' => $this->t('Referral credits'),
      '#items' => [
        $this->t('Paid: @n', ['@n' => $counts['awarded']]),
        $this->t('Waiting to send: @n', ['@n' => $counts['pending']]),
        $this->t('Failed — need attention: @n', ['@n' => $counts['failed']]),
        $this->t('Reversed: @n', ['@n' => $counts['reversed']]),
      ],
    ];

    $build['awards'] = $this->awardsTable();
    $build['invites'] = $this->invitesTable();

    // Staff act on this page and then expect it to be right; nothing here is
    // worth serving stale.
    $build['#cache']['max-age'] = 0;

    return $build;
  }

  /**
   * The things that stop credits being paid, said plainly and at the top.
   */
  private function stateNotices($settings, array $counts): array {
    $items = [];

    if (!$settings->get('awards_enabled')) {
      $items[] = [
        '#markup' => '<strong>' . $this->t('Automatic credits are switched OFF.') . '</strong> '
        . $this->t('Invitations still send and referrals are still recorded, but nothing is being paid. Turn it on at the referral settings once you are happy with what you see here.'),
      ];
    }
    if (!$this->chargebee->isConfigured()) {
      $items[] = [
        '#markup' => '<strong>' . $this->t('Chargebee is not configured.') . '</strong> '
        . $this->t('No credit can be sent until the Chargebee API key is set on the Chargebee Portal settings.'),
      ];
    }
    if (trim((string) $settings->get('join_url')) === '') {
      $items[] = [
        '#markup' => '<strong>' . $this->t('No signup link is set.') . '</strong> '
        . $this->t('Invitation emails will go out telling the person staff will follow up, instead of linking them to a checkout.'),
      ];
    }
    if ((int) $settings->get('awards_start') === 0) {
      $items[] = [
        '#markup' => '<strong>' . $this->t('No start date is set.') . '</strong> '
        . $this->t('Without one, a long-standing member paying their next renewal could earn a credit years after they joined. Set the start date before switching credits on.'),
      ];
    }
    if ($counts['failed'] > 0) {
      $items[] = [
        '#markup' => '<strong>' . $this->t('@n credit(s) failed and nobody has been paid for them.', ['@n' => $counts['failed']]) . '</strong> '
        . $this->t('These are listed below with the reason. Use Retry once the cause is fixed.'),
      ];
    }

    if (!$items) {
      return [];
    }
    return [
      '#theme' => 'item_list',
      '#title' => $this->t('Needs your attention'),
      '#items' => $items,
    ];
  }

  /**
   * Recent credits.
   */
  private function awardsTable(): array {
    $rows = [];
    foreach ($this->awards->listAwards(NULL, 100) as $a) {
      $actions = [];
      if ($a->status === 'failed') {
        $actions[] = $this->actionLink($this->t('Retry'), (int) $a->id, 'retry');
      }
      if ($a->status === 'awarded') {
        $actions[] = $this->actionLink($this->t('Record a reversal'), (int) $a->id, 'reverse');
      }

      $rows[] = [
        ['data' => $a->id],
        ['data' => $this->userLink((int) $a->referrer_uid)],
        ['data' => $this->userLink((int) $a->referred_uid)],
        ['data' => '$' . number_format(((int) $a->amount_cents) / 100, 2)],
        ['data' => $a->source === ReferralAward::SOURCE_INVITE ? $this->t('Invitation') : $this->t('Named at signup')],
        ['data' => $this->statusLabel($a)],
        ['data' => $this->dates->format((int) $a->updated, 'short')],
        ['data' => ['#theme' => 'item_list', '#items' => $actions]],
      ];
    }

    return [
      '#type' => 'table',
      '#caption' => $this->t('Credits'),
      '#header' => [
        $this->t('#'),
        $this->t('Credited to'),
        $this->t('For referring'),
        $this->t('Amount'),
        $this->t('How we knew'),
        $this->t('Status'),
        $this->t('Updated'),
        $this->t('Actions'),
      ],
      '#rows' => $rows,
      '#empty' => $this->t('No referral credits yet.'),
    ];
  }

  /**
   * Recent invitations, so an unclaimed one is visible rather than forgotten.
   */
  private function invitesTable(): array {
    $rows = [];
    foreach ($this->invites->recent(50) as $i) {
      $rows[] = [
        ['data' => $this->userLink((int) $i->inviter_uid)],
        ['data' => trim($i->invitee_first . ' ' . $i->invitee_last)],
        ['data' => $i->invitee_email],
        ['data' => $this->dates->format((int) $i->created, 'short')],
        ['data' => $i->claimed_uid ? $this->t('Joined') : $this->t('Not yet')],
      ];
    }
    return [
      '#type' => 'table',
      '#caption' => $this->t('Invitations sent'),
      '#header' => [
        $this->t('From'),
        $this->t('Invited'),
        $this->t('Email'),
        $this->t('Sent'),
        $this->t('Joined?'),
      ],
      '#rows' => $rows,
      '#empty' => $this->t('No invitations sent yet.'),
    ];
  }

  /**
   * A status cell that carries the failure reason where there is one.
   */
  private function statusLabel(object $award): array {
    $labels = [
      'pending' => $this->t('Waiting to send'),
      'awarded' => $this->t('Paid'),
      'failed' => $this->t('FAILED'),
      'reversed' => $this->t('Reversed'),
    ];
    $text = $labels[$award->status] ?? $award->status;
    $detail = trim((string) ($award->last_error ?? '')) ?: trim((string) ($award->note ?? ''));
    if ($detail === '') {
      return ['#markup' => $text];
    }
    return ['#markup' => $text . '<br /><small>' . htmlspecialchars($detail, ENT_QUOTES) . '</small>'];
  }

  /**
   * Link to a user, or a plain marker when the account has gone.
   */
  private function userLink(int $uid): array {
    $user = $this->entityTypeManager()->getStorage('user')->load($uid);
    if (!$user) {
      return ['#markup' => $this->t('deleted account (@uid)', ['@uid' => $uid])];
    }
    return [
      '#type' => 'link',
      '#title' => $user->getDisplayName(),
      '#url' => $user->toUrl(),
    ];
  }

  /**
   * Builds one action link.
   */
  private function actionLink($title, int $award_id, string $op): array {
    return [
      '#type' => 'link',
      '#title' => $title,
      '#url' => Url::fromRoute('makerspace_referrals.award_action', ['award' => $award_id, 'op' => $op]),
    ];
  }

}
