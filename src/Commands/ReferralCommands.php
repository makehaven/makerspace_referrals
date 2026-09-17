<?php

declare(strict_types=1);

namespace Drupal\makerspace_referrals\Commands;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\makerspace_referrals\Service\ChargebeeCredit;
use Drupal\makerspace_referrals\Service\ReferralAward;
use Drupal\makerspace_referrals\Service\ReferralProjector;
use Drupal\makerspace_referrals\Service\ReferralStats;
use Drush\Commands\DrushCommands;

/**
 * Drush commands for the referral programme.
 */
class ReferralCommands extends DrushCommands {

  public function __construct(
    private readonly ReferralAward $awards,
    private readonly ChargebeeCredit $chargebee,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly Connection $database,
    private readonly ReferralProjector $projector,
    private readonly ReferralStats $stats,
  ) {
    parent::__construct();
  }

  /**
   * Show what the referral programme is doing, without changing anything.
   *
   * Read-only. One command that answers "is this working?" so the answer does
   * not have to be assembled from watchdog.
   *
   * @command referrals:status
   * @usage drush referrals:status
   *   Switch state, configuration gaps, and credit counts.
   */
  public function status(): void {
    $settings = $this->configFactory->get('makerspace_referrals.settings');
    $counts = $this->awards->countsByStatus();
    $o = $this->output();

    $o->writeln('Automatic credits:  ' . ($settings->get('awards_enabled') ? 'ON' : 'OFF (nothing is being paid)'));
    $o->writeln('Chargebee ready:    ' . ($this->chargebee->isConfigured() ? 'yes' : 'NO — API key missing'));
    $o->writeln('Signup link set:    ' . (trim((string) $settings->get('join_url')) !== '' ? 'yes' : 'NO'));
    $o->writeln('Invitee coupon:     ' . (trim((string) $settings->get('invitee_coupon')) ?: '(none set)'));
    $start = (int) $settings->get('awards_start');
    $o->writeln('Paying for joins:   ' . ($start ? 'on or after ' . date('Y-m-d', $start) : 'NO CUTOFF SET — a long-standing member could earn a credit on their next renewal'));
    $o->writeln('');
    $o->writeln(sprintf('Credits: %d paid, %d waiting, %d FAILED, %d reversed',
      $counts['awarded'], $counts['pending'], $counts['failed'], $counts['reversed']));

    $o->writeln(sprintf('Answers: %d named, %d resolved to an account (%s%%)',
      $this->stats->namedCount(), $this->stats->resolvedCount(), $this->stats->resolutionRate()));

    $invites = (int) $this->database->select('makerspace_referral_invite', 'i')->countQuery()->execute()->fetchField();
    $claimed = (int) $this->database->select('makerspace_referral_invite', 'i')
      ->condition('claimed_uid', 0, '>')->countQuery()->execute()->fetchField();
    $o->writeln(sprintf('Invitations: %d sent, %d joined', $invites, $claimed));

    if ($counts['failed'] > 0) {
      $o->writeln('');
      $o->writeln('Failed credits (nobody has been paid for these):');
      foreach ($this->awards->listAwards('failed', 25) as $a) {
        $o->writeln(sprintf('  #%d  uid %d  $%s  %s', $a->id, $a->referrer_uid, number_format($a->amount_cents / 100, 2), $a->last_error ?: 'no reason recorded'));
      }
      $o->writeln('Retry them with: drush referrals:retry-failed');
    }
  }

  /**
   * Rebuild the resolved-referrer projection from the review decisions.
   *
   * Views cannot call a service, so the confirmed referrer is projected into
   * field_member_referral for the dashboard and the member card to query. This
   * rebuilds it from scratch; it is safe to re-run and changes nothing when
   * everything already agrees.
   *
   * @command referrals:project
   * @usage drush referrals:project
   *   Backfill or repair the projection.
   */
  public function project(): void {
    $counts = $this->projector->projectAll();
    $this->output()->writeln(sprintf(
      'Scanned %d profile(s) with an answer: %d set, %d cleared, %d already correct.',
      $counts['scanned'], $counts['set'], $counts['cleared'], $counts['unchanged'],
    ));

    $resolved = $this->stats->resolvedCount();
    $named = $this->stats->namedCount();
    $this->output()->writeln(sprintf('Resolution rate: %d of %d named (%s%%).', $resolved, $named, $this->stats->resolutionRate()));

    if ($named > 0 && $resolved === 0) {
      $this->output()->writeln('');
      $this->output()->writeln('Nothing resolved. That is the review queue, not a bug in this command:');
      $this->output()->writeln('staff confirm who each name refers to at /admin/people/referrals.');
    }
  }

  /**
   * Show who has introduced the most members.
   *
   * @command referrals:top
   * @option limit How many to list.
   * @option year Restrict to members who joined in or after this year.
   * @usage drush referrals:top --year=2025
   *   The standings since 2025.
   */
  public function top(array $options = ['limit' => 10, 'year' => NULL]): void {
    $year = $options['year'] !== NULL ? (int) $options['year'] : NULL;
    $rows = $this->stats->topReferrers((int) $options['limit'], $year);
    if (!$rows) {
      $this->output()->writeln('Nobody is resolved yet. Run drush referrals:project, and check the review queue.');
      return;
    }
    foreach ($rows as $i => $row) {
      $this->output()->writeln(sprintf('%2d. %-28s %3d  (most recent %s)',
        $i + 1, $row['name'], $row['count'], date('Y-m-d', $row['most_recent'])));
    }
  }

  /**
   * Re-queue every failed referral credit.
   *
   * @command referrals:retry-failed
   * @option limit Maximum number to re-queue.
   * @usage drush referrals:retry-failed
   *   Queue all failed credits for another attempt on the next cron run.
   */
  public function retryFailed(array $options = ['limit' => 100]): void {
    $failed = $this->awards->listAwards('failed', (int) $options['limit']);
    if (!$failed) {
      $this->output()->writeln('No failed credits.');
      return;
    }
    $n = 0;
    foreach ($failed as $a) {
      if ($this->awards->retry((int) $a->id)) {
        $n++;
      }
    }
    $this->output()->writeln(sprintf('Re-queued %d credit(s). They send on the next cron run.', $n));
  }

}
