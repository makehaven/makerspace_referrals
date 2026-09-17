<?php

declare(strict_types=1);

namespace Drupal\makerspace_referrals\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\profile\Entity\ProfileInterface;

/**
 * Keeps `field_member_referral` in step with the confirmed review.
 *
 * **Views cannot call a service.** `ReferralReview::confirmedReferrer()` is the
 * truth, but a dashboard chart and a member's own card both need a *queryable
 * column* holding the resolved uid. This projects one into the entity-reference
 * field that already exists on the profile and has sat empty and labelled
 * DEACTIVATED NOT WORKING since it was added.
 *
 * Why the free text cannot be used directly: measured on live, the 547 stored
 * answers collapse into 429 distinct strings. "lior trestman" and "lior" are
 * two rows for one person, and "climatehaven" is an organisation. A leaderboard
 * built on that names the wrong winner, and a member card built on it tells
 * people they referred fewer members than they did.
 *
 * Rules this class exists to enforce:
 * - Nothing writes the field except this service.
 * - The free text is never overwritten. It is the human's answer.
 * - The projection is cleared whenever the review stops being confirmed —
 *   the answer was edited, or the referrer's account was deleted — because a
 *   card that outlives the fact is worse than one that never existed.
 */
class ReferralProjector {

  /**
   * The projected field.
   */
  public const FIELD = 'field_member_referral';

  public function __construct(
    private readonly EntityTypeManagerInterface $entities,
    private readonly ReferralReview $review,
    private readonly LoggerChannelInterface $log,
  ) {}

  /**
   * Brings one profile's projection in line with its review.
   *
   * @return string
   *   'set', 'cleared' or 'unchanged' — so the backfill can report honestly.
   */
  public function project(int $profile_id): string {
    $storage = $this->entities->getStorage('profile');
    $storage->resetCache([$profile_id]);
    $profile = $storage->load($profile_id);

    if (!$profile instanceof ProfileInterface || !$profile->hasField(self::FIELD)) {
      return 'unchanged';
    }

    $referrer = $this->review->confirmedReferrer($profile_id);
    $want = $referrer ? (int) $referrer->id() : NULL;
    $have = $profile->get(self::FIELD)->isEmpty()
      ? NULL
      : (int) $profile->get(self::FIELD)->target_id;

    if ($want === $have) {
      return 'unchanged';
    }

    $profile->set(self::FIELD, $want);
    // Derived state should not look like the member edited their profile, and
    // should not trip anything watching for a human change.
    $profile->setSyncing(TRUE);
    $profile->save();

    $this->log->notice('Referral projection @what for profile @p.', [
      '@what' => $want === NULL ? 'cleared' : 'set to uid ' . $want,
      '@p' => $profile_id,
    ]);

    return $want === NULL ? 'cleared' : 'set';
  }

  /**
   * Re-projects every main profile that carries an answer.
   *
   * @return array
   *   Counts keyed 'set', 'cleared', 'unchanged', 'scanned'.
   */
  public function projectAll(): array {
    $counts = ['set' => 0, 'cleared' => 0, 'unchanged' => 0, 'scanned' => 0];

    $ids = $this->entities->getStorage('profile')->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'main')
      ->exists('field_member_referring')
      ->execute();

    foreach ($ids as $id) {
      $counts['scanned']++;
      $counts[$this->project((int) $id)]++;
    }

    return $counts;
  }

}
