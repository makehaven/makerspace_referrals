<?php

declare(strict_types=1);

namespace Drupal\Tests\makerspace_referrals\Kernel;

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\makerspace_referrals\Service\MemberFinder;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;

/**
 * Tests finding a member by whatever the person actually types.
 *
 * Every case here is taken from the live backlog. The old matcher required
 * exactly two words and an exact match on both, so of 544 stored answers it
 * could suggest nothing for 124 of them — 60 single words like "Michael" and
 * "regina", and 64 with three or more. Those are the tests that matter.
 *
 * @group makerspace_referrals
 */
class MemberFinderTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'field', 'profile', 'file', 'image', 'makerspace_referrals'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installSchema('user', ['users_data']);
    $this->installEntitySchema('profile');
    $this->installEntitySchema('file');
    $this->installConfig(['user']);

    foreach (['field_first_name', 'field_last_name'] as $field) {
      FieldStorageConfig::create(['entity_type' => 'user', 'field_name' => $field, 'type' => 'string'])->save();
      FieldConfig::create(['entity_type' => 'user', 'bundle' => 'user', 'field_name' => $field])->save();
    }

    Role::create(['id' => 'member', 'label' => 'Member'])->save();
  }

  /**
   * Creates an active member.
   */
  private function member(string $first, string $last, bool $active = TRUE): User {
    $user = User::create([
      'name' => strtolower($first . '.' . $last),
      'mail' => strtolower($first . '.' . $last) . '@example.com',
      'status' => $active ? 1 : 0,
      'field_first_name' => $first,
      'field_last_name' => $last,
    ]);
    $user->addRole('member');
    $user->save();
    return $user;
  }

  /**
   * The finder.
   */
  private function finder(): MemberFinder {
    return $this->container->get('makerspace_referrals.member_finder');
  }

  /**
   * Names only, for readable assertions.
   */
  private function names(array $results): array {
    return array_values(array_filter(array_column($results, 'name')));
  }

  /**
   * A first name alone finds the person. This is the case that fails today.
   */
  public function testFirstNameAloneFindsTheMember(): void {
    $lior = $this->member('Lior', 'Trestman');
    $this->member('Michael', 'Stone');

    $results = $this->finder()->search('Lior');

    $this->assertSame(['Lior Trestman'], $this->names($results));
    $this->assertSame((int) $lior->id(), $results[0]['uid']);
  }

  /**
   * A last name alone works too — people type either.
   */
  public function testLastNameAloneFindsTheMember(): void {
    $this->member('Lior', 'Trestman');

    $this->assertSame(['Lior Trestman'], $this->names($this->finder()->search('Trestman')));
  }

  /**
   * Punctuation is noise. "Lior!" is a real stored answer.
   */
  public function testPunctuationIsIgnored(): void {
    $this->member('Lior', 'Trestman');

    $this->assertSame(['Lior Trestman'], $this->names($this->finder()->search('Lior!')));
    $this->assertSame(['Lior Trestman'], $this->names($this->finder()->search('LIOR!!!!!!!!!!!')));
  }

  /**
   * Matching is case-insensitive — "regina" is stored lowercase.
   */
  public function testMatchingIgnoresCase(): void {
    $this->member('Regina', 'Cole');

    $this->assertSame(['Regina Cole'], $this->names($this->finder()->search('regina')));
  }

  /**
   * A partial name works, because people stop typing once they see the face.
   */
  public function testPartialNameMatches(): void {
    $this->member('Benjamin', 'Ward');

    $this->assertSame(['Benjamin Ward'], $this->names($this->finder()->search('Benj')));
  }

  /**
   * Both orders work. People type "Trestman Lior" too.
   */
  public function testEitherNameOrderMatches(): void {
    $this->member('Lior', 'Trestman');

    $this->assertSame(['Lior Trestman'], $this->names($this->finder()->search('Lior Trestman')));
    $this->assertSame(['Lior Trestman'], $this->names($this->finder()->search('Trestman Lior')));
  }

  /**
   * One character returns nothing — this is a search, not a directory.
   */
  public function testTooShortReturnsNothing(): void {
    $this->member('Lior', 'Trestman');

    $this->assertSame([], $this->finder()->search('L'));
    $this->assertSame([], $this->finder()->search(''));
    $this->assertSame([], $this->finder()->search('  '));
  }

  /**
   * The membership cannot be paged through, however broad the term.
   */
  public function testResultsAreCapped(): void {
    for ($i = 0; $i < 20; $i++) {
      $this->member('Samuel' . $i, 'Smith');
    }

    $results = $this->finder()->search('Samuel');
    $real = array_filter($results, static fn(array $r) => empty($r['more']));

    $this->assertCount(MemberFinder::MAX_RESULTS, $real, 'Never more than the cap.');
    $this->assertTrue(
      (bool) array_filter($results, static fn(array $r) => !empty($r['more'])),
      'And it says there are more rather than pretending that is everyone.',
    );
  }

  /**
   * You cannot refer yourself.
   */
  public function testSelfIsExcluded(): void {
    $self = $this->member('Lior', 'Trestman');

    $this->assertSame([], $this->finder()->search('Lior', (int) $self->id()));
  }

  /**
   * Non-members and blocked accounts are not offered.
   */
  public function testOnlyActiveMembersAreOffered(): void {
    $blocked = $this->member('Blocked', 'Person', FALSE);

    $stranger = User::create([
      'name' => 'stranger',
      'mail' => 'stranger@example.com',
      'status' => 1,
      'field_first_name' => 'Stranger',
      'field_last_name' => 'Person',
    ]);
    $stranger->save();

    $this->assertSame([], $this->names($this->finder()->search('Person')));
    $this->assertFalse($this->finder()->isSelectableMember((int) $blocked->id()));
    $this->assertFalse($this->finder()->isSelectableMember((int) $stranger->id()));
  }

  /**
   * What the browser posts is a claim, so submit-time checking is separate.
   */
  public function testSelectableMemberCheckIsIndependentOfSearch(): void {
    $member = $this->member('Lior', 'Trestman');

    $this->assertTrue($this->finder()->isSelectableMember((int) $member->id()));
    $this->assertFalse($this->finder()->isSelectableMember((int) $member->id(), (int) $member->id()), 'Not yourself.');
    $this->assertFalse($this->finder()->isSelectableMember(0));
    $this->assertFalse($this->finder()->isSelectableMember(999999));
  }

}
