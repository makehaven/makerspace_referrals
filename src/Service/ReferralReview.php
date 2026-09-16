<?php

namespace Drupal\makerspace_referrals\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Query\SelectInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\profile\Entity\ProfileInterface;
use Drupal\user\UserInterface;

/**
 * Preserves source answers and records explicit staff attribution decisions.
 */
class ReferralReview {

  public function __construct(
    protected Connection $database,
    protected EntityTypeManagerInterface $entities,
    protected TimeInterface $time,
    protected LockBackendInterface $lock,
  ) {}

  /**
   * Gets the original answer without normalizing away provenance.
   */
  public function source(ProfileInterface $profile): string {
    return $profile->hasField('field_member_referring')
      ? (string) $profile->get('field_member_referring')->value : '';
  }

  /**
   * Loads the latest decision, including decisions for superseded answers.
   */
  public function latest(int $profile_id): ?object {
    $query = $this->database->select('makerspace_referral_review', 'r');
    $query->fields('r')->condition('profile_id', $profile_id);
    return $query->orderBy('id', 'DESC')->range(0, 1)->execute()->fetchObject() ?: NULL;
  }

  /**
   * Determines whether the current answer still has a valid decision.
   */
  public function status(ProfileInterface $profile, ?object $decision): string {
    if (!$decision || $decision->source_hash !== hash('sha256', $this->source($profile))) {
      return 'pending';
    }
    if ($decision->status === 'confirmed') {
      $user = $this->entities->getStorage('user')->load($decision->referrer_uid);
      if (!$user || (int) $user->id() === (int) $profile->getOwnerId()) {
        return 'pending';
      }
    }
    return $decision->status;
  }

  /**
   * Returns the confirmed account for the current answer, or NULL.
   *
   * Reward consumers must separately verify eligibility and prior fulfillment.
   * Reload the profile so a caller holding an old entity cannot use stale text.
   */
  public function confirmedReferrer(int $profile_id): ?UserInterface {
    $storage = $this->entities->getStorage('profile');
    $storage->resetCache([$profile_id]);
    $profile = $storage->load($profile_id);
    if (!$profile || $profile->bundle() !== 'main' || trim($this->source($profile)) === '') {
      return NULL;
    }
    $decision = $this->latest($profile_id);
    if ($this->status($profile, $decision) !== 'confirmed') {
      return NULL;
    }
    return $this->entities->getStorage('user')->load($decision->referrer_uid);
  }

  /**
   * Suggests exact first/last-name matches; never confirms them automatically.
   */
  public function candidates(ProfileInterface $profile): array {
    $answer = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $this->source($profile))));
    $parts = explode(' ', $answer, 2);
    if (count($parts) !== 2 || !$this->database->schema()->tableExists('user__field_first_name') || !$this->database->schema()->tableExists('user__field_last_name')) {
      return [];
    }
    $query = $this->database->select('users_field_data', 'u');
    $query->innerJoin('user__field_first_name', 'fn', 'fn.entity_id = u.uid AND fn.deleted = 0');
    $query->innerJoin('user__field_last_name', 'ln', 'ln.entity_id = u.uid AND ln.deleted = 0');
    $query->addField('u', 'uid');
    $query->condition('u.uid', 0, '>')->condition('u.uid', $profile->getOwnerId(), '<>');
    $query->where('LOWER(TRIM(fn.field_first_name_value)) = :first', [':first' => $parts[0]]);
    $query->where('LOWER(TRIM(ln.field_last_name_value)) = :last', [':last' => $parts[1]]);
    return $this->entities->getStorage('user')->loadMultiple($query->distinct()->range(0, 10)->execute()->fetchCol());
  }

  /**
   * Counts answers that still need a staff decision.
   *
   * Mirrors status() exactly — an answer is pending when it has no decision,
   * when its text changed since the decision was made, when the decision itself
   * is 'pending', or when a 'confirmed' decision points at an account that has
   * since been deleted or turns out to be the person's own. Doing that per row
   * through status() would load every profile; this is the same rule expressed
   * once in SQL so it is cheap enough to render in a menu title.
   *
   * It is a count of work outstanding, not of credits owed. A stored name is
   * not evidence that anything is unpaid.
   *
   * Shares the pending worklist query, including byte-exact source comparison.
   */
  public function pendingCount(): int {
    return (int) $this->pendingQuery()->countQuery()->execute()->fetchField();
  }

  /**
   * Queryable pending identities, shared by pagination and the menu count.
   */
  protected function pendingQuery(): SelectInterface {
    $query = $this->database->select('profile', 'p');
    $query->innerJoin('profile__field_member_referring', 'r', 'r.entity_id = p.profile_id AND r.deleted = 0');
    $query->addField('p', 'profile_id');
    $query->addField('p', 'created');
    $query->distinct()->condition('p.type', 'main');
    $query->where("TRIM(r.field_member_referring_value) <> ''");
    // MySQL's normal collation ignores case and trailing spaces, whereas the
    // source hash used by status() does not. Cast both sides for equality.
    $source_equal = $this->database->driver() === 'mysql'
      ? 'BINARY d.source_text = BINARY r.field_member_referring_value'
      : 'd.source_text = r.field_member_referring_value COLLATE "C"';
    if ($this->database->driver() === 'sqlite') {
      $source_equal = 'd.source_text = r.field_member_referring_value COLLATE BINARY';
    }
    $query->where(<<<SQL
        NOT EXISTS (
          SELECT 1
          FROM {makerspace_referral_review} d
          LEFT JOIN {users_field_data} u ON u.uid = d.referrer_uid
          WHERE d.profile_id = p.profile_id
            AND d.id = (
              SELECT MAX(d2.id) FROM {makerspace_referral_review} d2
              WHERE d2.profile_id = p.profile_id
            )
            AND $source_equal
            AND (
              d.status = :external
              OR (d.status = :confirmed AND u.uid IS NOT NULL AND u.uid <> p.uid)
            )
        )
      SQL, [
        ':external' => 'external',
        ':confirmed' => 'confirmed',
      ]);
    return $query;
  }

  /**
   * Lists profiles with an answer, independent of discovery type.
   */
  public function profiles(bool $pending_only = FALSE): array {
    $storage = $this->entities->getStorage('profile');
    if ($pending_only) {
      $ids = $this->pendingQuery()->orderBy('p.created', 'DESC')->orderBy('p.profile_id', 'DESC')
        ->extend('Drupal\Core\Database\Query\PagerSelectExtender')->limit(25)->execute()->fetchCol();
      return $storage->loadMultiple($ids);
    }
    $ids = $storage->getQuery()->accessCheck(FALSE)
      ->condition('type', 'main')
      ->condition('field_member_referring', '', '<>')
      ->sort('created', 'DESC')->sort('profile_id', 'DESC')->pager(25)->execute();
    return $storage->loadMultiple($ids);
  }

  /**
   * Saves a decision with optimistic concurrency and an append-only history.
   */
  public function decide(int $profile_id, string $expected_hash, int $expected_decision, string $status, int $referrer_uid, int $reviewer_uid): void {
    if (!in_array($status, ['confirmed', 'external', 'pending'], TRUE)) {
      throw new \InvalidArgumentException('Invalid referral decision.');
    }
    $key = 'makerspace_referrals:' . $profile_id;
    if (!$this->lock->acquire($key)) {
      throw new \RuntimeException('Another review is being saved. Reload and try again.');
    }
    try {
      $storage = $this->entities->getStorage('profile');
      $storage->resetCache([$profile_id]);
      $profile = $storage->load($profile_id);
      if (!$profile || $profile->bundle() !== 'main' || trim($this->source($profile)) === '') {
        throw new \RuntimeException('This referral answer is no longer available.');
      }
      $source = $this->source($profile);
      $latest = $this->latest($profile_id);
      if (!hash_equals(hash('sha256', $source), $expected_hash) || (int) ($latest->id ?? 0) !== $expected_decision) {
        throw new \RuntimeException('The answer or review changed. Reload before saving.');
      }
      if ($status === 'confirmed') {
        $user = $this->entities->getStorage('user')->load($referrer_uid);
        if (!$user || $referrer_uid <= 0 || $referrer_uid === (int) $profile->getOwnerId()) {
          throw new \RuntimeException('Select a valid referrer other than the person referred.');
        }
      }
      else {
        $referrer_uid = 0;
      }
      // Repeated saves with the same current state do not add duplicate events.
      if ($latest && $latest->source_hash === $expected_hash && $latest->status === $status && (int) $latest->referrer_uid === $referrer_uid) {
        return;
      }
      $this->database->insert('makerspace_referral_review')->fields([
        'profile_id' => $profile_id,
        'source_hash' => $expected_hash,
        'source_text' => $source,
        'referrer_uid' => $referrer_uid,
        'status' => $status,
        'reviewer_uid' => $reviewer_uid,
        'created' => $this->time->getCurrentTime(),
      ])->execute();
      $this->invalidatePendingCount();
    }
    finally {
      $this->lock->release($key);
    }
  }

  /**
   * Drops the cached menus that render the pending count in a link title.
   *
   * The count lives in a menu link title, and Drupal's menu render cache does
   * not honour a link plugin's own max-age — measured 2026-09-16: after a
   * decision the service returned 544 while the toolbar still drew 545. Only a
   * cache tag moves it, so both menus carrying the link are invalidated here
   * and from the profile hook in makerspace_referrals.module. Decisions and new
   * answers are both rare, so this costs nothing in practice.
   */
  public function invalidatePendingCount(): void {
    Cache::invalidateTags([
      'config:system.menu.admin',
      'config:system.menu.staff-tools',
    ]);
  }

}
