<?php

declare(strict_types=1);

namespace Drupal\Tests\makerspace_referrals\Kernel;

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\profile\Entity\Profile;
use Drupal\profile\Entity\ProfileType;
use Drupal\user\Entity\User;

/**
 * Tests the projection and the numbers built on it.
 *
 * The headline case is taken straight from live: counted as free text, the
 * standings name the wrong winner because "lior trestman" and "lior" are two
 * rows for one person and "climatehaven" is an organisation. Reproducing that
 * shape and asserting the right answer is the whole reason the projection
 * exists.
 *
 * @group makerspace_referrals
 */
class ReferralStatsTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'field', 'profile', 'makerspace_referrals'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installSchema('user', ['users_data']);
    $this->installEntitySchema('profile');
    $this->installSchema('makerspace_referrals', [
      'makerspace_referral_review',
      'makerspace_referral_invite',
      'makerspace_referral_award',
      'makerspace_referral_thanks',
    ]);
    $this->installConfig(['makerspace_referrals']);

    ProfileType::create(['id' => 'main', 'label' => 'Main'])->save();

    FieldStorageConfig::create([
      'entity_type' => 'profile',
      'field_name' => 'field_member_referring',
      'type' => 'string',
    ])->save();
    FieldConfig::create([
      'entity_type' => 'profile',
      'bundle' => 'main',
      'field_name' => 'field_member_referring',
    ])->save();

    FieldStorageConfig::create([
      'entity_type' => 'profile',
      'field_name' => 'field_member_referral',
      'type' => 'entity_reference',
      'settings' => ['target_type' => 'user'],
    ])->save();
    FieldConfig::create([
      'entity_type' => 'profile',
      'bundle' => 'main',
      'field_name' => 'field_member_referral',
      'label' => 'Referred by (resolved)',
    ])->save();

    foreach (['field_first_name', 'field_last_name'] as $field) {
      FieldStorageConfig::create(['entity_type' => 'user', 'field_name' => $field, 'type' => 'string'])->save();
      FieldConfig::create(['entity_type' => 'user', 'bundle' => 'user', 'field_name' => $field])->save();
    }
  }

  /**
   * Creates an account.
   */
  private function member(string $name): User {
    $user = User::create(['name' => $name, 'mail' => $name . '@example.com', 'status' => 1]);
    $user->save();
    return $user;
  }

  /**
   * Creates a profile carrying an answer, optionally confirmed.
   */
  private function joiner(string $name, string $answer, ?User $confirmed_as = NULL): Profile {
    $user = $this->member($name);
    $profile = Profile::create(['type' => 'main', 'uid' => $user->id(), 'field_member_referring' => $answer]);
    $profile->save();

    if ($confirmed_as) {
      $review = $this->container->get('makerspace_referrals.review');
      $review->decide((int) $profile->id(), hash('sha256', $review->source($profile)), 0, 'confirmed', (int) $confirmed_as->id(), 1);
      $this->container->get('makerspace_referrals.projector')->project((int) $profile->id());
    }
    return $profile;
  }

  /**
   * The case that is wrong today: one person split across two spellings.
   *
   * Counted as text, the same member appears twice with two each and is
   * nowhere near the top, while an organisation sits in the standings.
   * Counted as resolved accounts, they appear once at their real total and
   * the organisation is gone.
   */
  public function testTopReferrersCountsPeopleNotStrings(): void {
    $lior = $this->member('lior');
    $ericka = $this->member('ericka');

    // Two spellings, same person.
    $this->joiner('a', 'lior trestman', $lior);
    $this->joiner('b', 'lior trestman', $lior);
    $this->joiner('c', 'lior', $lior);
    $this->joiner('d', 'lior', $lior);

    // A different real member.
    $this->joiner('e', 'ericka saracho', $ericka);
    $this->joiner('f', 'ericka saracho', $ericka);
    $this->joiner('g', 'ericka saracho', $ericka);

    // An organisation. Named twice, never resolvable to an account.
    $this->joiner('h', 'climatehaven');
    $this->joiner('i', 'climatehaven');

    $top = $this->container->get('makerspace_referrals.stats')->topReferrers(10);

    $names = array_column($top, 'name');
    $this->assertNotContains('climatehaven', $names, 'An organisation must never appear in the standings.');
    $this->assertSame(1, count(array_keys($names, 'lior', TRUE)), 'One person must appear once, not once per spelling.');

    $by_uid = array_column($top, 'count', 'uid');
    $this->assertSame(4, $by_uid[(int) $lior->id()], 'Both spellings count toward the same person.');
    $this->assertSame(3, $by_uid[(int) $ericka->id()]);
    $this->assertSame((int) $lior->id(), $top[0]['uid'], 'The right person is top.');
  }

  /**
   * Resolution counts separate what we were told from what we confirmed.
   */
  public function testResolutionBreakdownSeparatesNamedFromResolved(): void {
    $referrer = $this->member('sam');
    $this->joiner('a', 'sam', $referrer);
    $this->joiner('b', 'sam', $referrer);
    $this->joiner('c', 'someone nobody has looked at');
    $this->joiner('d', 'also unreviewed');

    $stats = $this->container->get('makerspace_referrals.stats');

    $this->assertSame(4, $stats->namedCount());
    $this->assertSame(2, $stats->resolvedCount());
    $this->assertSame(50.0, $stats->resolutionRate());

    $breakdown = $stats->resolutionBreakdown();
    $this->assertSame(2, $breakdown['resolved']);
    $this->assertSame(2, $breakdown['pending'], 'Unreviewed answers are pending, not absent.');
  }

  /**
   * Editing the answer clears the projection built from the old one.
   *
   * A card that outlives the fact is worse than one that never existed.
   */
  public function testChangingTheAnswerClearsTheProjection(): void {
    $referrer = $this->member('sam');
    $profile = $this->joiner('a', 'sam', $referrer);

    $this->assertSame(1, $this->container->get('makerspace_referrals.stats')->resolvedCount());

    $profile->set('field_member_referring', 'actually it was someone else')->save();

    $this->assertSame(
      0,
      $this->container->get('makerspace_referrals.stats')->resolvedCount(),
      'The projection must not survive the answer it was derived from.',
    );
  }

  /**
   * The projector is idempotent — re-running changes nothing.
   */
  public function testProjectingTwiceChangesNothing(): void {
    $referrer = $this->member('sam');
    $this->joiner('a', 'sam', $referrer);

    $projector = $this->container->get('makerspace_referrals.projector');
    $first = $projector->projectAll();
    $second = $projector->projectAll();

    $this->assertSame(0, $second['set'], 'Nothing to set the second time.');
    $this->assertSame(0, $second['cleared']);
    $this->assertSame($first['scanned'], $second['scanned']);
  }

  /**
   * Monthly counts show the backlog as the gap between the two series.
   */
  public function testMonthlyCountsShowNamedAndResolvedSeparately(): void {
    $referrer = $this->member('sam');
    $this->joiner('a', 'sam', $referrer);
    $this->joiner('b', 'unreviewed');

    $months = $this->container->get('makerspace_referrals.stats')->referralsByMonth(3);
    $this_month = $months[date('Y-m')];

    $this->assertSame(2, $this_month['named']);
    $this->assertSame(1, $this_month['resolved'], 'The gap between the series is the queue.');
  }

  /**
   * With nothing resolved, the standings are empty rather than wrong.
   */
  public function testNothingResolvedMeansNoStandings(): void {
    $this->joiner('a', 'somebody');
    $this->joiner('b', 'somebody else');

    $stats = $this->container->get('makerspace_referrals.stats');
    $this->assertSame([], $stats->topReferrers(10), 'Better empty than built on unresolved text.');
    $this->assertSame(2, $stats->namedCount(), 'The answers are still recorded.');
  }

}
