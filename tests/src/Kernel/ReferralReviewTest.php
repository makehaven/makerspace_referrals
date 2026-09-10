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
    $this->assertEquals([$referrer->id()], array_keys($service->candidates($profile)));
    $service->decide($id, $hash, 0, 'confirmed', (int) $referrer->id(), (int) $owner->id());
    $decision = $service->latest($id);
    $this->assertSame('confirmed', $service->status($profile, $decision));
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
    $service->decide($id, $hash, (int) $service->latest($id)->id, 'pending', 0, (int) $owner->id());
    $this->assertSame('pending', $service->status($profile, $service->latest($id)));
    $this->assertEquals(3, $this->container->get('database')->select('makerspace_referral_review')->countQuery()->execute()->fetchField());
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
