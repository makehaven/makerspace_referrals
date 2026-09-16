<?php

namespace Drupal\Tests\makerspace_referrals\Kernel;

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\profile\Entity\Profile;
use Drupal\profile\Entity\ProfileType;
use Drupal\user\Entity\User;

/**
 * Tests referral decisions, provenance and stale-review protection.
 *
 * @group makerspace_referrals
 */
class ReferralReviewTest extends KernelTestBase {

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
    $this->installSchema('makerspace_referrals', ['makerspace_referral_review']);
    ProfileType::create(['id' => 'main', 'label' => 'Main'])->save();
    $fields = [
      ['profile', 'main', 'field_member_referring'],
      ['user', 'user', 'field_first_name'],
      ['user', 'user', 'field_last_name'],
    ];
    foreach ($fields as [$entity, $bundle, $field]) {
      FieldStorageConfig::create(['entity_type' => $entity, 'field_name' => $field, 'type' => 'string'])->save();
      FieldConfig::create(['entity_type' => $entity, 'bundle' => $bundle, 'field_name' => $field])->save();
    }
  }

  /**
   * The pending count must agree with status(), row for row.
   *
   * It is rendered in a menu link, so staff act on it; a count that drifts from
   * the page it advertises is worse than no count. Covers every branch of
   * status(): no decision, confirmed, changed answer, and a confirmed decision
   * pointing at the person themselves.
   */
  public function testPendingCountMatchesStatus(): void {
    $service = $this->container->get('makerspace_referrals.review');
    $this->assertSame(0, $service->pendingCount(), 'No answers, nothing pending.');

    $owner = User::create(['name' => 'counted_member']);
    $owner->save();
    $referrer = User::create(['name' => 'counted_referrer']);
    $referrer->save();
    $profile = Profile::create([
      'type' => 'main',
      'uid' => $owner->id(),
      'field_member_referring' => 'Grace Hopper',
    ]);
    $profile->save();
    $id = (int) $profile->id();

    // An answer with no decision is work outstanding.
    $this->assertSame(1, $service->pendingCount());

    // A confirmed decision clears it.
    $service->decide($id, hash('sha256', 'Grace Hopper'), 0, 'confirmed', (int) $referrer->id(), 1);
    $this->assertSame('confirmed', $service->status($profile, $service->latest($id)));
    $this->assertSame(0, $service->pendingCount());

    // Marking it external is also a decision, not outstanding work.
    $service->decide($id, hash('sha256', 'Grace Hopper'), (int) $service->latest($id)->id, 'external', 0, 1);
    $this->assertSame(0, $service->pendingCount());

    // Changing the answer reopens it — the old decision describes a different
    // name, so it cannot stand in for a review of this one.
    $profile->set('field_member_referring', 'Grace Hopper Jr')->save();
    $reloaded = $this->reloadProfile($id);
    $this->assertSame('pending', $service->status($reloaded, $service->latest($id)));
    $this->assertSame(1, $service->pendingCount());

    // A confirmed decision naming the person themselves is not a review.
    $service->decide($id, hash('sha256', 'Grace Hopper Jr'), (int) $service->latest($id)->id, 'confirmed', (int) $referrer->id(), 1);
    $this->assertSame(0, $service->pendingCount());
    $this->container->get('database')->update('makerspace_referral_review')
      ->fields(['referrer_uid' => (int) $owner->id()])
      ->condition('id', (int) $service->latest($id)->id)
      ->execute();
    $this->assertSame('pending', $service->status($this->reloadProfile($id), $service->latest($id)));
    $this->assertSame(1, $service->pendingCount(), 'A self-referral is never a completed review.');

    // A deleted referrer reopens it too.
    $this->container->get('database')->update('makerspace_referral_review')
      ->fields(['referrer_uid' => 99999])
      ->condition('id', (int) $service->latest($id)->id)
      ->execute();
    $this->assertSame(1, $service->pendingCount());

    // An empty answer is not a referral at all.
    $profile->set('field_member_referring', '')->save();
    $this->assertSame(0, $service->pendingCount());
  }

  /**
   * The worklist excludes decisions and reopens case-only source corrections.
   */
  public function testPendingWorklist(): void {
    $owner = User::create(['name' => 'worklist_member']);
    $owner->save();
    $profile = Profile::create([
      'type' => 'main',
      'uid' => $owner->id(),
      'field_member_referring' => 'An External Referrer',
    ]);
    $profile->save();
    $id = (int) $profile->id();
    $service = $this->container->get('makerspace_referrals.review');
    $this->assertArrayHasKey($id, $service->profiles(TRUE));
    $service->decide($id, hash('sha256', 'An External Referrer'), 0, 'external', 0, (int) $owner->id());
    $this->assertSame([], $service->profiles(TRUE));
    $this->assertArrayHasKey($id, $service->profiles());
    $profile->set('field_member_referring', 'an external referrer')->save();
    $this->assertSame(1, $service->pendingCount());
    $this->assertArrayHasKey($id, $service->profiles(TRUE));
    $this->assertSame('pending', $service->status($profile, $service->latest($id)));
  }

  /**
   * Reloads a profile so a stale entity cannot mask a changed answer.
   */
  protected function reloadProfile(int $id): Profile {
    $storage = $this->container->get('entity_type.manager')->getStorage('profile');
    $storage->resetCache([$id]);
    return $storage->load($id);
  }

  /**
   * Confirms provenance, duplicate prevention, reopening and changed answers.
   */
  public function testReviewLifecycle(): void {
    $owner = User::create(['name' => 'new_member']);
    $owner->save();
    $referrer = User::create(['name' => 'referrer', 'field_first_name' => 'Ada', 'field_last_name' => 'Lovelace']);
    $referrer->save();
    $profile = Profile::create(['type' => 'main', 'uid' => $owner->id(), 'field_member_referring' => '  Ada Lovelace  ']);
    $profile->save();
    $service = $this->container->get('makerspace_referrals.review');
    $id = (int) $profile->id();
    $hash = hash('sha256', $service->source($profile));
    $this->assertSame('pending', $service->status($profile, NULL));
    $this->assertNull($service->confirmedReferrer($id));
    $this->assertEquals([$referrer->id()], array_keys($service->candidates($profile)));
    $service->decide($id, $hash, 0, 'confirmed', (int) $referrer->id(), (int) $owner->id());
    $decision = $service->latest($id);
    $this->assertSame('confirmed', $service->status($profile, $decision));
    $this->assertEquals($referrer->id(), $service->confirmedReferrer($id)->id());
    $this->assertSame('  Ada Lovelace  ', $decision->source_text);
    $this->assertSame('  Ada Lovelace  ', $service->source($profile));
    $service->decide($id, $hash, (int) $decision->id, 'confirmed', (int) $referrer->id(), (int) $owner->id());
    $this->assertEquals($decision->id, $service->latest($id)->id);
    try {
      $service->decide($id, $hash, 0, 'external', 0, (int) $owner->id());
      $this->fail('Stale staff review should be rejected.');
    }
    catch (\RuntimeException $e) {
      $this->assertStringContainsString('changed', $e->getMessage());
    }
    $profile->set('field_member_referring', 'Another person')->save();
    $this->assertSame('pending', $service->status($profile, $decision));
    $this->assertNull($service->confirmedReferrer($id));
    try {
      $service->decide($id, $hash, (int) $decision->id, 'external', 0, (int) $owner->id());
      $this->fail('Changed source should be rejected.');
    }
    catch (\RuntimeException $e) {
      $this->assertStringContainsString('changed', $e->getMessage());
    }
    $hash = hash('sha256', 'Another person');
    $service->decide($id, $hash, (int) $decision->id, 'external', 0, (int) $owner->id());
    $this->assertSame('external', $service->status($profile, $service->latest($id)));
    $this->assertNull($service->confirmedReferrer($id));
    $service->decide($id, $hash, (int) $service->latest($id)->id, 'pending', 0, (int) $owner->id());
    $this->assertSame('pending', $service->status($profile, $service->latest($id)));
    $this->assertNull($service->confirmedReferrer($id));
    $this->assertEquals(3, $this->container->get('database')->select('makerspace_referral_review')->countQuery()->execute()->fetchField());
  }

  /**
   * A removed account cannot remain a reward recipient through old history.
   */
  public function testDeletedReferrerIsNotReturned(): void {
    $owner = User::create(['name' => 'recruited']);
    $owner->save();
    $referrer = User::create(['name' => 'referrer_to_delete']);
    $referrer->save();
    $profile = Profile::create(['type' => 'main', 'uid' => $owner->id(), 'field_member_referring' => 'A member']);
    $profile->save();
    $service = $this->container->get('makerspace_referrals.review');
    $id = (int) $profile->id();
    $service->decide($id, hash('sha256', 'A member'), 0, 'confirmed', (int) $referrer->id(), (int) $owner->id());
    $this->assertEquals($referrer->id(), $service->confirmedReferrer($id)->id());
    $referrer->delete();
    $this->assertNull($service->confirmedReferrer($id));
    $this->assertNotNull($service->latest($id));
    $this->assertNull($service->confirmedReferrer(999999));
  }

  /**
   * Rejects self-referrals without recording an attribution decision.
   */
  public function testSelfReferralRejected(): void {
    $owner = User::create(['name' => 'self']);
    $owner->save();
    $profile = Profile::create(['type' => 'main', 'uid' => $owner->id(), 'field_member_referring' => 'Self']);
    $profile->save();
    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('other than');
    $this->container->get('makerspace_referrals.review')->decide((int) $profile->id(), hash('sha256', 'Self'), 0, 'confirmed', (int) $owner->id(), (int) $owner->id());
  }

}
