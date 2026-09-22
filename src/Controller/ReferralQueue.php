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
    foreach ($this->review->profiles($pending_only) as $profile) {
      $latest = $this->review->latest((int) $profile->id());
      $status = $this->review->status($profile, $latest);
      $referrer = $this->review->confirmedReferrer((int) $profile->id());
      $rows[] = [
        $profile->getOwner()?->toLink() ?? $this->t('Account unavailable'),
        ['data' => ['#plain_text' => $this->review->source($profile)]],
        $this->review->attributionLabel($status, $latest),
        $this->decidedBy($status, $latest),
        $referrer ? $referrer->toLink() : $this->t('Not linked'),
        Link::createFromRoute($this->t('Review'), 'makerspace_referrals.review', ['profile' => $profile->id()]),
      ];
    }
    $intro = '<p>' . $this->t('Review who referred each person. Identity confirmation does not establish reward eligibility or whether a credit has been paid. Referrals are listed newest first. Check billing history before applying any credit.') . '</p>';
    $intro .= '<p>' . $this->t('A row can arrive as Identity matched: the person joining picked their referrer from the list on the thank-you page, and that choice counts as the identification. Nobody on staff reviewed it, and nothing needs doing unless the choice looks wrong. Identity confirmed is a decision a staff member made.') . '</p>';
    return [
      'intro' => ['#markup' => $intro],
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
          $this->t('Decided by'),
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

  /**
   * Names who settled the current answer, in words rather than a uid.
   *
   * A pending row has no settled decision to attribute, whatever history it
   * carries. A self-pick says so explicitly: a blank in this column is how a
   * member's own choice came to look like a decision nobody made.
   */
  protected function decidedBy(string $status, ?object $latest): Link|string|\Stringable {
    if ($status === 'pending' || !$latest) {
      return $this->t('Awaiting staff');
    }
    if ($this->review->isSelfPick($latest)) {
      return $this->t('The member, at signup');
    }
    $reviewer = $this->entityTypeManager()->getStorage('user')->load($latest->reviewer_uid);
    return $reviewer ? $reviewer->toLink() : $this->t('Staff account @uid (removed)', ['@uid' => $latest->reviewer_uid]);
  }

}
