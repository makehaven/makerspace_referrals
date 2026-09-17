<?php

declare(strict_types=1);

namespace Drupal\makerspace_referrals\Service;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;

/**
 * Finds members by name, for the "who referred you?" picker.
 *
 * Exists because asking for a name as free text does not work. Of 544 stored
 * answers, 60 are a single word and 64 are three or more; the old matcher
 * handled only the exactly-two-words case, so **23% of the queue arrived with
 * no suggestion at all** — "Michael", "regina", "Ulla", "Lior!" — and someone
 * had to go and research it. That is a large part of why 544 answers produced
 * two decisions.
 *
 * Letting the person point at a face instead removes the guessing entirely:
 * 841 of 853 active members have a photo.
 *
 * **Deliberately a search, not a directory.** It refuses to answer without at
 * least two characters and never returns more than a handful, so it cannot be
 * used to page through the membership. You see people whose name you already
 * typed — which is a name you already knew.
 */
class MemberFinder {

  /**
   * Below this, we return nothing rather than most of the membership.
   */
  public const MIN_CHARS = 2;

  /**
   * Never return more than this, whatever was typed.
   */
  public const MAX_RESULTS = 8;

  public function __construct(
    private readonly Connection $database,
    private readonly EntityTypeManagerInterface $entities,
    private readonly FileUrlGeneratorInterface $fileUrls,
  ) {}

  /**
   * Searches active members by first or last name.
   *
   * Matching is a prefix match on either name, which is what people type. It
   * deliberately does NOT require two words: the whole point is that "Lior"
   * should find Lior.
   *
   * @param string $term
   *   What the person typed.
   * @param int $exclude_uid
   *   An account to leave out — you cannot refer yourself.
   * @param bool $active_members_only
   *   TRUE for the member-facing picker: you may only choose somebody who is
   *   a member now, because only they can be credited. FALSE for the staff
   *   queue, which is resolving historical answers where the referrer has
   *   often since left — measured against the live backlog, active-only
   *   resolves 37.9% of stored answers and including lapsed accounts resolves
   *   **73%**. Those 35 points are almost entirely people who have gone.
   *
   * @return array
   *   [['uid' => int, 'name' => string, 'photo' => string|null]]
   */
  public function search(string $term, int $exclude_uid = 0, bool $active_members_only = TRUE): array {
    $term = trim(preg_replace('/\s+/u', ' ', $term));
    // Punctuation is noise here. The stored answers include "Lior!" and
    // "LIOR!!!!!!!!!!!!!!!!!!!!!!!!!", which no exact matcher was ever going
    // to resolve.
    $term = trim(preg_replace('/[^\p{L}\p{N} \'-]/u', '', $term));

    if (mb_strlen($term) < self::MIN_CHARS) {
      return [];
    }

    // Both name fields are joined unconditionally below, so a site without
    // them would produce a SQL error rather than an empty result. Nothing here
    // is important enough to take a page down for.
    if (!$this->database->schema()->tableExists('user__field_first_name')
      || !$this->database->schema()->tableExists('user__field_last_name')) {
      return [];
    }

    $parts = explode(' ', $term, 2);
    $first = $parts[0];
    $last = $parts[1] ?? NULL;

    $query = $this->database->select('users_field_data', 'u');
    if ($active_members_only) {
      $query->innerJoin('user__roles', 'r', "r.entity_id = u.uid AND r.roles_target_id = 'member'");
      $query->condition('u.status', 1);
    }
    $query->leftJoin('user__field_first_name', 'fn', 'fn.entity_id = u.uid AND fn.deleted = 0');
    $query->leftJoin('user__field_last_name', 'ln', 'ln.entity_id = u.uid AND ln.deleted = 0');
    $query->addField('u', 'uid');
    $query->condition('u.uid', 0, '>');
    if ($exclude_uid > 0) {
      $query->condition('u.uid', $exclude_uid, '<>');
    }

    if ($last !== NULL && $last !== '') {
      // Two words: treat them as first then last, but do not insist — people
      // type "Trestman Lior" and middle names too.
      $group = $query->orConditionGroup()
        ->condition($query->andConditionGroup()
          ->condition('fn.field_first_name_value', $this->database->escapeLike($first) . '%', 'LIKE')
          ->condition('ln.field_last_name_value', $this->database->escapeLike($last) . '%', 'LIKE'))
        ->condition($query->andConditionGroup()
          ->condition('fn.field_first_name_value', $this->database->escapeLike($last) . '%', 'LIKE')
          ->condition('ln.field_last_name_value', $this->database->escapeLike($first) . '%', 'LIKE'));
      $query->condition($group);
    }
    else {
      // One word: match it against either name. This is the case the old
      // matcher refused to answer at all.
      $query->condition($query->orConditionGroup()
        ->condition('fn.field_first_name_value', $this->database->escapeLike($first) . '%', 'LIKE')
        ->condition('ln.field_last_name_value', $this->database->escapeLike($first) . '%', 'LIKE'));
    }

    $query->orderBy('fn.field_first_name_value');
    $query->orderBy('ln.field_last_name_value');
    // One extra, so the caller can tell "there are more" from "that is all".
    $query->range(0, self::MAX_RESULTS + 1);

    $uids = $query->distinct()->execute()->fetchCol();
    if (!$uids) {
      return [];
    }

    $truncated = count($uids) > self::MAX_RESULTS;
    $uids = array_slice($uids, 0, self::MAX_RESULTS);

    $out = [];
    foreach ($this->entities->getStorage('user')->loadMultiple($uids) as $user) {
      $out[] = [
        'uid' => (int) $user->id(),
        'name' => $this->displayName($user),
        'photo' => $this->photoUrl((int) $user->id()),
        // Staff resolving an old answer need to see that the person has since
        // left — the referral is still a real fact for reporting, but nobody
        // is going to be credited for it.
        'current_member' => $user->isActive() && in_array('member', $user->getRoles(), TRUE),
      ];
    }

    if ($truncated) {
      // Not a result — a marker the caller renders as "keep typing".
      $out[] = ['uid' => 0, 'name' => '', 'photo' => NULL, 'more' => TRUE];
    }

    return $out;
  }

  /**
   * A member's full name, falling back to the account name.
   */
  public function displayName($user): string {
    $name = trim(
      ($user->hasField('field_first_name') ? (string) $user->get('field_first_name')->value : '') . ' ' .
      ($user->hasField('field_last_name') ? (string) $user->get('field_last_name')->value : '')
    );
    return $name !== '' ? $name : $user->getAccountName();
  }

  /**
   * The member photo for an account, as a URL.
   *
   * Lives on the main profile rather than the account — 3,382 profiles carry
   * one against 12 core user pictures, so the profile is where to look.
   */
  public function photoUrl(int $uid): ?string {
    // A photo is a nicety; the name is the answer. Anything missing here —
    // the field, the file module, the image module — degrades to no picture
    // rather than taking the page down with it.
    if (!$this->database->schema()->tableExists('profile__field_member_photo')
      || !$this->entities->hasDefinition('file')) {
      return NULL;
    }

    $fid = $this->database->select('profile', 'p')
      ->fields('ph', ['field_member_photo_target_id'])
      ->condition('p.uid', $uid)
      ->condition('p.type', 'main')
      ->condition('p.status', 1)
      ->range(0, 1);
    $fid->innerJoin('profile__field_member_photo', 'ph', 'ph.entity_id = p.profile_id AND ph.deleted = 0');
    $fid = $fid->execute()->fetchField();

    if (!$fid) {
      return NULL;
    }

    $file = $this->entities->getStorage('file')->load($fid);
    if (!$file) {
      return NULL;
    }

    $uri = $file->getFileUri();

    // A style rather than the original: these render eight at a time in a
    // dropdown, and the originals are full-size member photos.
    if ($this->entities->hasDefinition('image_style')) {
      $style = $this->entities->getStorage('image_style')->load('thumbnail');
      if ($style) {
        return $style->buildUrl($uri);
      }
    }

    return $this->fileUrls->generateAbsoluteString($uri);
  }

  /**
   * Whether an account is a plausible referrer.
   *
   * Checked on submit, because what the browser sends is not evidence.
   */
  public function isSelectableMember(int $uid, int $exclude_uid = 0): bool {
    if ($uid <= 0 || $uid === $exclude_uid) {
      return FALSE;
    }
    $user = $this->entities->getStorage('user')->load($uid);
    return $user && $user->isActive() && in_array('member', $user->getRoles(), TRUE);
  }

}
