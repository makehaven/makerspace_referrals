<?php

declare(strict_types=1);

namespace Drupal\makerspace_referrals\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\makerspace_referrals\Service\MemberFinder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Search endpoint behind the "who referred you?" picker.
 *
 * Open to any logged-in account, which includes someone part-way through
 * joining — that is the whole point, since the question is asked before they
 * are a full member.
 *
 * The exposure is bounded by the finder, not by this controller: it refuses
 * fewer than two characters and returns at most eight people, so this cannot
 * be walked to produce a membership list. What it returns is people matching a
 * name the searcher already typed.
 */
class MemberSearch extends ControllerBase {

  public function __construct(
    private readonly MemberFinder $finder,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get('makerspace_referrals.member_finder'));
  }

  /**
   * Returns matching members as JSON.
   */
  public function search(Request $request): JsonResponse {
    $term = (string) $request->query->get('q', '');
    $results = $this->finder->search($term, (int) $this->currentUser()->id());

    $response = new JsonResponse(['results' => $results]);
    // Per-person and short-lived: the answer depends on who is asking, and a
    // shared cache entry here would leak one person's search to another.
    $response->setPrivate();
    $response->setMaxAge(0);
    return $response;
  }

}
