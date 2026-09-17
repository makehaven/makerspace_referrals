<?php

declare(strict_types=1);

namespace Drupal\makerspace_referrals\Service;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Referral numbers, for the dashboard and anything else that asks.
 *
 * Lives here rather than in `makerspace_dashboard`, the same way
 * `lending_library.stats_collector` and `storage_manager.statistics_service`
 * do: the dashboard depends on this module, not the other way round.
 *
 * Two constraints run through every method:
 *
 * 1. **Counts are of resolved uids, never of strings.** The free-text answers
 *    collapse 547 rows into 429 distinct strings, so counting strings splits
 *    one member across two rows and puts an organisation in the standings.
 * 2. **Drupal tables only, no CiviCRM, no per-row entity loads.** The KPI
 *    prewarm already runs ~15 heavy CiviCRM scans an hour and is a suspect in
 *    "the site is slow". These queries must stay cheap.
 */
class ReferralStats {

  public function __construct(
    private readonly Connection $database,
    private readonly EntityTypeManagerInterface $entities,
  ) {}

  /**
   * Named versus resolved, by month.
   *
   * The gap between the two series is the review backlog, visible without
   * anyone having to explain it.
   *
   * @param int $months
   *   How many months back to cover, including this one.
   *
   * @return array
   *   [YYYY-MM => ['named' => int, 'resolved' => int]], oldest first.
   */
  public function referralsByMonth(int $months = 12): array {
    $out = [];
    $cursor = new \DateTimeImmutable('first day of this month 00:00:00');
    for ($i = $months - 1; $i >= 0; $i--) {
      $out[$cursor->modify("-$i month")->format('Y-m')] = ['named' => 0, 'resolved' => 0];
    }
    $since = $cursor->modify('-' . ($months - 1) . ' month')->getTimestamp();

    if (!$this->database->schema()->tableExists('profile__field_member_referring')) {
      return $out;
    }

    // Named: a profile carrying a non-empty answer, bucketed by when the
    // profile was created — which is when the person told us.
    $named = $this->database->select('profile__field_member_referring', 'r');
    $named->innerJoin('profile', 'p', 'p.profile_id = r.entity_id');
    $named->addExpression("DATE_FORMAT(FROM_UNIXTIME(p.created), '%Y-%m')", 'period');
    $named->addExpression('COUNT(DISTINCT p.profile_id)', 'n');
    $named->condition('r.deleted', 0)
      ->condition('p.created', $since, '>=')
      ->condition('r.field_member_referring_value', '', '<>')
      ->groupBy('period');
    foreach ($named->execute() as $row) {
      if (isset($out[$row->period])) {
        $out[$row->period]['named'] = (int) $row->n;
      }
    }

    if (!$this->database->schema()->tableExists('profile__' . ReferralProjector::FIELD)) {
      return $out;
    }

    $resolved = $this->database->select('profile__' . ReferralProjector::FIELD, 'x');
    $resolved->innerJoin('profile', 'p', 'p.profile_id = x.entity_id');
    $resolved->addExpression("DATE_FORMAT(FROM_UNIXTIME(p.created), '%Y-%m')", 'period');
    $resolved->addExpression('COUNT(DISTINCT p.profile_id)', 'n');
    $resolved->condition('x.deleted', 0)
      ->condition('p.created', $since, '>=')
      ->groupBy('period');
    foreach ($resolved->execute() as $row) {
      if (isset($out[$row->period])) {
        $out[$row->period]['resolved'] = (int) $row->n;
      }
    }

    return $out;
  }

  /**
   * Who has actually introduced the most members.
   *
   * Distinct resolved uids only. The acceptance case for this method is that
   * the member whose name is spelled two ways appears **once**, at their real
   * total, and `climatehaven` does not appear at all — that is the answer the
   * free text gets wrong today.
   *
   * @param int $limit
   *   How many to return.
   * @param int|null $since_year
   *   Restrict to profiles created in or after this calendar year.
   *
   * @return array
   *   [['uid' => int, 'name' => string, 'count' => int, 'most_recent' => int]]
   */
  public function topReferrers(int $limit = 10, ?int $since_year = NULL): array {
    $table = 'profile__' . ReferralProjector::FIELD;
    if (!$this->database->schema()->tableExists($table)) {
      return [];
    }

    $column = ReferralProjector::FIELD . '_target_id';
    $query = $this->database->select($table, 'x');
    $query->innerJoin('profile', 'p', 'p.profile_id = x.entity_id');
    $query->addField('x', $column, 'uid');
    $query->addExpression('COUNT(DISTINCT p.profile_id)', 'n');
    $query->addExpression('MAX(p.created)', 'most_recent');
    $query->condition('x.deleted', 0)->groupBy('x.' . $column);
    if ($since_year !== NULL) {
      $query->condition('p.created', (new \DateTimeImmutable($since_year . '-01-01 00:00:00'))->getTimestamp(), '>=');
    }
    $query->orderBy('n', 'DESC')->orderBy('most_recent', 'DESC')->range(0, $limit);

    $rows = $query->execute()->fetchAll();
    if (!$rows) {
      return [];
    }

    // One loadMultiple rather than a load per row — this runs inside the KPI
    // prewarm, which is already heavy enough.
    $users = $this->entities->getStorage('user')->loadMultiple(array_map(static fn($r) => (int) $r->uid, $rows));

    $out = [];
    foreach ($rows as $row) {
      $user = $users[(int) $row->uid] ?? NULL;
      if (!$user) {
        // The account went away after the projection was written. Skip rather
        // than render a blank bar.
        continue;
      }
      $out[] = [
        'uid' => (int) $row->uid,
        'name' => $user->getDisplayName(),
        'count' => (int) $row->n,
        'most_recent' => (int) $row->most_recent,
      ];
    }
    return $out;
  }

  /**
   * Where the stored answers stand.
   *
   * - resolved: projected to a real account.
   * - pending: named somebody, no confirmed decision yet.
   * - external: staff decided it was not a member.
   * - unmatched: decided, but the account it pointed at is gone.
   *
   * @return array
   *   Counts keyed by state.
   */
  public function resolutionBreakdown(): array {
    $out = ['resolved' => 0, 'pending' => 0, 'external' => 0, 'unmatched' => 0];
    if (!$this->database->schema()->tableExists('profile__field_member_referring')) {
      return $out;
    }

    $out['resolved'] = $this->resolvedCount();

    $named = (int) $this->database->select('profile__field_member_referring', 'r')
      ->condition('r.deleted', 0)
      ->condition('r.field_member_referring_value', '', '<>')
      ->countQuery()->execute()->fetchField();

    if ($this->database->schema()->tableExists('makerspace_referral_review')) {
      // Latest decision per profile, so an answer that was re-decided counts
      // once and counts as whatever it is now.
      $latest = $this->database->select('makerspace_referral_review', 'v');
      $latest->addField('v', 'profile_id');
      $latest->addExpression('MAX(v.id)', 'last_id');
      $latest->groupBy('v.profile_id');

      $decided = $this->database->select('makerspace_referral_review', 'd');
      $decided->innerJoin($latest, 'l', 'l.last_id = d.id');
      $decided->addField('d', 'status');
      $decided->addExpression('COUNT(*)', 'n');
      $decided->groupBy('d.status');
      foreach ($decided->execute() as $row) {
        if ($row->status === 'external') {
          $out['external'] = (int) $row->n;
        }
      }
    }

    $out['pending'] = max(0, $named - $out['resolved'] - $out['external']);
    return $out;
  }

  /**
   * How many answers have been resolved to a real account.
   */
  public function resolvedCount(): int {
    $table = 'profile__' . ReferralProjector::FIELD;
    if (!$this->database->schema()->tableExists($table)) {
      return 0;
    }
    return (int) $this->database->select($table, 'x')
      ->condition('x.deleted', 0)
      ->countQuery()->execute()->fetchField();
  }

  /**
   * How many answers have been given at all.
   */
  public function namedCount(): int {
    if (!$this->database->schema()->tableExists('profile__field_member_referring')) {
      return 0;
    }
    return (int) $this->database->select('profile__field_member_referring', 'r')
      ->condition('r.deleted', 0)
      ->condition('r.field_member_referring_value', '', '<>')
      ->countQuery()->execute()->fetchField();
  }

  /**
   * Resolved as a share of named — whether the queue is being worked.
   */
  public function resolutionRate(): float {
    $named = $this->namedCount();
    return $named > 0 ? round(($this->resolvedCount() / $named) * 100, 1) : 0.0;
  }

  /**
   * Referrals where somebody named this account but nobody has confirmed it.
   *
   * These deliberately do **not** count toward a member's total. Someone
   * suggesting a name is not the same as staff deciding it, and a card that
   * counted suggestions would tell people they had introduced members they
   * had not introduced.
   *
   * @return int
   *   How many answers point at this account without a confirmed decision.
   */
  public function awaitingConfirmationFor(int $uid): int {
    if (!$this->database->schema()->tableExists('makerspace_referral_review')) {
      return 0;
    }

    $latest = $this->database->select('makerspace_referral_review', 'v');
    $latest->addField('v', 'profile_id');
    $latest->addExpression('MAX(v.id)', 'last_id');
    $latest->groupBy('v.profile_id');

    $query = $this->database->select('makerspace_referral_review', 'd');
    $query->innerJoin($latest, 'l', 'l.last_id = d.id');
    $query->condition('d.referrer_uid', $uid)
      ->condition('d.status', 'pending');

    return (int) $query->countQuery()->execute()->fetchField();
  }

  /**
   * The members somebody has been resolved as having introduced.
   *
   * @return int[]
   *   Profile ids.
   */
  public function profilesReferredBy(int $uid): array {
    $table = 'profile__' . ReferralProjector::FIELD;
    if (!$this->database->schema()->tableExists($table)) {
      return [];
    }
    return $this->database->select($table, 'x')
      ->fields('x', ['entity_id'])
      ->condition('x.deleted', 0)
      ->condition('x.' . ReferralProjector::FIELD . '_target_id', $uid)
      ->execute()
      ->fetchCol();
  }

}
