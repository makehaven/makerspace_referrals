<?php

namespace Drupal\makerspace_referrals\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Link;
use Drupal\makerspace_referrals\Service\ReferralReview;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

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
  public function build(Request $request): array {
    $pending_only = $request->query->get('status') === 'pending';
    $rows = [];
    $labels = [
      'pending' => $this->t('Needs review'),
      'confirmed' => $this->t('Identity confirmed'),
      'external' => $this->t('External / not a member'),
    ];
    foreach ($this->review->profiles($pending_only) as $profile) {
      $latest = $this->review->latest((int) $profile->id());
      $status = $this->review->status($profile, $latest);
      $referrer = $this->review->confirmedReferrer((int) $profile->id());
      $rows[] = [
        $profile->getOwner()?->toLink() ?? $this->t('Account unavailable'),
        ['data' => ['#plain_text' => $this->review->source($profile)]],
        $labels[$status] ?? $labels['pending'],
        $referrer ? $referrer->toLink() : $this->t('Not linked'),
        Link::createFromRoute($this->t('Review'), 'makerspace_referrals.review', ['profile' => $profile->id()]),
      ];
    }
    return [
      'intro' => ['#markup' => '<p>' . $this->t('Review who referred each person. Identity confirmation does not establish reward eligibility or whether a credit has been paid. Referrals are listed newest first. Check billing history before applying any credit.') . '</p>'],
      'pending' => ['#markup' => '<p>' . $this->formatPlural($this->review->pendingCount(), '1 referral needs identity review.', '@count referrals need identity review.') . '</p>'],
      'filters' => [
        '#theme' => 'item_list',
        '#items' => [
          Link::createFromRoute($this->t('All answers'), 'makerspace_referrals.queue'),
          Link::createFromRoute($this->t('Needs review'), 'makerspace_referrals.queue', [], ['query' => ['status' => 'pending']]),
        ],
      ],
      'table' => [
        '#type' => 'table',
        '#header' => [
          $this->t('Person referred'),
          $this->t('Original answer'),
          $this->t('Attribution'),
          $this->t('Linked referrer'),
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
