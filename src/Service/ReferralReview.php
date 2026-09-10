<?php

namespace Drupal\makerspace_referrals\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
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
   * Lists profiles with an answer, independent of discovery type.
   */
  public function profiles(): array {
    $storage = $this->entities->getStorage('profile');
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
    }
    finally {
      $this->lock->release($key);
    }
  }

}
