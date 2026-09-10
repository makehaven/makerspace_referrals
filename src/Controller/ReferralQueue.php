<?php

namespace Drupal\makerspace_referrals\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Link;
use Drupal\makerspace_referrals\Service\ReferralReview;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Lists captured recruitment referrals and their review state.
 */
class ReferralQueue extends ControllerBase {

  public function __construct(protected ReferralReview $review) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static($container->get('makerspace_referrals.review'));
  }

  /**
   * Builds the staff list. Access is restricted by the route permission.
   */
  public function build(): array {
    $rows = [];
    $labels = [
      'pending' => $this->t('Needs review'),
      'confirmed' => $this->t('Identity confirmed'),
      'external' => $this->t('External / not a member'),
    ];
    foreach ($this->review->profiles() as $profile) {
      $latest = $this->review->latest((int) $profile->id());
      $status = $this->review->status($profile, $latest);
      $rows[] = [
        $profile->getOwner()?->toLink() ?? $this->t('Account unavailable'),
        ['data' => ['#plain_text' => $this->review->source($profile)]],
        $labels[$status] ?? $labels['pending'],
        Link::createFromRoute($this->t('Review'), 'makerspace_referrals.review', ['profile' => $profile->id()]),
      ];
    }
    return [
      'intro' => ['#markup' => '<p>' . $this->t('Review who referred each person. Identity confirmation does not establish reward eligibility or whether a credit has been paid. Referrals are listed newest first, including previously reviewed answers.') . '</p>'],
      'table' => [
        '#type' => 'table',
        '#header' => [
          $this->t('Person referred'),
          $this->t('Original answer'),
          $this->t('Attribution'),
          $this->t('Action'),
        ],
        '#rows' => $rows,
        '#empty' => $this->t('No referral answers are available.'),
      ],
      'pager' => ['#type' => 'pager'],
      '#cache' => ['max-age' => 0],
    ];
  }

}
