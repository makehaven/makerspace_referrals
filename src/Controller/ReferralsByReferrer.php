<?php

declare(strict_types=1);

namespace Drupal\makerspace_referrals\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Url;
use Drupal\makerspace_referrals\Service\ReferralStats;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * The queue, grouped by the person being credited.
 *
 * The dashboard answers *how are we doing*. Staff acting on a specific
 * referral need a worklist, and "who do I thank, and for whom" is a different
 * question from the chronological queue — it is the one you ask when you are
 * about to talk to somebody.
 */
class ReferralsByReferrer extends ControllerBase {

  public function __construct(
    private readonly ReferralStats $stats,
    private readonly DateFormatterInterface $dates,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('makerspace_referrals.stats'),
      $container->get('date.formatter'),
    );
  }

  /**
   * Builds the page.
   */
  public function build(): array {
    $build = [];

    $named = $this->stats->namedCount();
    $resolved = $this->stats->resolvedCount();

    $build['summary'] = [
      '#markup' => '<p>' . $this->t('@resolved of @named stored answers have been matched to an account (@rate%). Everyone below is somebody a new member said introduced them.', [
        '@resolved' => $resolved,
        '@named' => $named,
        '@rate' => $this->stats->resolutionRate(),
      ]) . '</p>',
    ];

    if ($resolved === 0 && $named > 0) {
      // The honest empty state. Nothing here is broken; the decisions have
      // simply not been made, and saying so is more useful than a blank table.
      $build['none'] = [
        '#markup' => '<p><strong>' . $this->t('Nothing has been matched yet, so there is nobody to list.') . '</strong> '
        . $this->t('Every one of the @n answers is waiting on someone deciding which account it means. Until that happens nobody is thanked and no credit is applied.', ['@n' => $named])
        . ' ' . $this->t('Start at <a href=":url">the review queue</a>.', [':url' => Url::fromRoute('makerspace_referrals.queue')->toString()]) . '</p>',
      ];
      $build['#cache']['max-age'] = 0;
      return $build;
    }

    $rows = [];
    foreach ($this->stats->topReferrers(100) as $entry) {
      $user = $this->entityTypeManager()->getStorage('user')->load($entry['uid']);
      $rows[] = [
        [
          'data' => $user
            ? ['#type' => 'link', '#title' => $entry['name'], '#url' => $user->toUrl()]
            : ['#markup' => $entry['name']],
        ],
        ['data' => $entry['count']],
        ['data' => $this->dates->format($entry['most_recent'], 'custom', 'j M Y')],
        [
          'data' => [
            '#type' => 'link',
            '#title' => $this->t('Who they introduced'),
            '#url' => Url::fromRoute('makerspace_referrals.mine', ['user' => $entry['uid']]),
          ],
        ],
      ];
    }

    $build['table'] = [
      '#type' => 'table',
      '#header' => [
        $this->t('Member'),
        $this->t('Introduced'),
        $this->t('Most recent'),
        $this->t('Detail'),
      ],
      '#rows' => $rows,
      '#empty' => $this->t('Nobody has been matched to a referral yet.'),
    ];

    $build['#cache']['max-age'] = 0;
    return $build;
  }

}
