<?php

namespace Drupal\makerspace_referrals\Plugin\Menu;

use Drupal\Core\Menu\MenuLinkDefault;
use Drupal\Core\Menu\StaticMenuLinkOverridesInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\makerspace_referrals\Service\ReferralReview;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Menu link that carries the number of referrals still awaiting review.
 *
 * The queue shipped on 2026-09-10 and had zero reviews five days later against
 * 546 stored names — not because staff refused to work it, but because nothing
 * anywhere said there was work. A count in the link is the cheapest honest
 * signal: it costs one query, needs no new email, and it is visible in the
 * admin menu and in Coffee search without opening the page.
 *
 * Note the count does NOT reach the Staff Tools page. That page renders its own
 * curated titles from `staff_tools.inventory.yml` rather than the menu link's
 * title, deliberately — see StaffToolsController::buildInventoryLink().
 */
class ReferralQueueMenuLink extends MenuLinkDefault {

  use StringTranslationTrait;

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    StaticMenuLinkOverridesInterface $static_override,
    protected ReferralReview $review,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $static_override);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('menu_link.static.overrides'),
      $container->get('makerspace_referrals.review'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getTitle() {
    $title = parent::getTitle();
    // A failure here would break every admin menu render, which is a far worse
    // outcome than a missing badge.
    try {
      $pending = $this->review->pendingCount();
    }
    catch (\Throwable $e) {
      return $title;
    }
    if ($pending < 1) {
      return $title;
    }
    return $this->t('@title (@count)', ['@title' => $title, '@count' => $pending]);
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheTags() {
    // profile_list is invalidated by Drupal on any profile save, which covers a
    // new answer arriving. A decision changes no profile, so the short max-age
    // below is what makes a reviewed item leave the count.
    return array_merge(parent::getCacheTags(), ['profile_list']);
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheMaxAge() {
    return 900;
  }

}
