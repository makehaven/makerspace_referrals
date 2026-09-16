<?php

namespace Drupal\Tests\makerspace_referrals\Functional;

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\profile\Entity\Profile;
use Drupal\profile\Entity\ProfileType;
use Drupal\Tests\BrowserTestBase;

/**
 * Tests access, safe rendering and the actual review form submission.
 *
 * @group makerspace_referrals
 */
class ReferralReviewAccessTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['makerspace_referrals'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * Tests the staff journey and permission boundary.
   */
  public function testReviewJourney(): void {
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
    $member = $this->drupalCreateUser();
    $referrer = $this->drupalCreateUser();
    $staff = $this->drupalCreateUser(['review member referrals']);
    $profile = Profile::create([
      'type' => 'main',
      'uid' => $member->id(),
      'field_member_referring' => '<script>alert(1)</script>',
    ]);
    $profile->save();
    $path = '/admin/people/referrals/' . $profile->id() . '/review';
    $this->drupalGet('/admin/people/referrals');
    $this->assertSession()->statusCodeEquals(403);
    $this->drupalLogin($member);
    $this->drupalGet($path);
    $this->assertSession()->statusCodeEquals(403);
    $this->drupalLogin($staff);
    $this->drupalGet('/admin/people/referrals');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->responseContains('&lt;script&gt;alert(1)&lt;/script&gt;');
    $this->clickLink('Review');
    $this->assertSession()->statusCodeEquals(200);
    $this->submitForm([
      'status' => 'confirmed',
      'referrer' => $referrer->getAccountName() . ' (' . $referrer->id() . ')',
    ], 'Save review');
    $this->assertSession()->pageTextContains('Referral review saved.');
    $this->assertSession()->pageTextContains('Identity confirmed');
    $this->assertSession()->linkByHrefExists('/user/' . $referrer->id());
    $this->drupalGet('/admin/people/referrals', ['query' => ['status' => 'pending']]);
    $this->assertSession()->pageTextContains('0 referrals need identity review.');
    $this->assertSession()->linkByHrefNotExists($path);
    $this->drupalGet('/admin/people/referrals');
    $this->assertSession()->linkByHrefExists('/user/' . $referrer->id());
    $this->assertEquals($referrer->id(), $this->container->get('makerspace_referrals.review')->confirmedReferrer((int) $profile->id())->id());
    $review = $this->container->get('makerspace_referrals.review')->latest((int) $profile->id());
    $this->assertEquals($referrer->id(), $review->referrer_uid);
    $this->assertEquals($staff->id(), $review->reviewer_uid);
    $this->drupalGet($path);
    $this->submitForm(['status' => 'pending'], 'Save review');
    $this->assertSession()->pageTextContains('Needs review');
    $this->drupalGet($path);
    $profile->set('field_member_referring', 'Changed after form opened')->save();
    $this->submitForm(['status' => 'external'], 'Save review');
    $this->assertSession()->pageTextContains('The answer or review changed.');
  }

}
