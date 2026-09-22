<?php

namespace Drupal\Tests\makerspace_referrals\Functional;

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\makerspace_referrals\Service\ReferralReview;
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
    $this->createReferringField();
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
    // The queue names who decided, not a uid.
    $this->assertSession()->pageTextContains('Decided by');
    $this->assertSession()->linkExists($staff->getDisplayName());
    $this->assertSession()->pageTextNotContains('The member, at signup');
    $this->assertSession()->elementTextNotContains('css', 'table', 'Identity matched');
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

  /**
   * A member's own pick at signup is labelled as theirs, never left blank.
   *
   * The first self-pick on live (2026-09-22) rendered as "Identity confirmed"
   * with nothing in the reviewer position, and staff read it as an AI
   * confirming referrals unprompted. The row is correct; the wording was not.
   */
  public function testSelfPickIsNamedAsTheMembers(): void {
    $this->createReferringField();
    $member = $this->drupalCreateUser();
    $referrer = $this->drupalCreateUser();
    $staff = $this->drupalCreateUser(['review member referrals']);
    $profile = Profile::create([
      'type' => 'main',
      'uid' => $member->id(),
      'field_member_referring' => $referrer->getDisplayName(),
    ]);
    $profile->save();

    // What interests_civi_bridge records when the joiner picks from the list.
    $review = $this->container->get('makerspace_referrals.review');
    $review->decide((int) $profile->id(), hash('sha256', $review->source($profile)), 0, 'confirmed', (int) $referrer->id(), ReferralReview::SELF_PICK);
    $this->assertTrue($review->isSelfPick($review->latest((int) $profile->id())));
    $this->assertEquals(0, $review->pendingCount(), 'A self-pick is a settled decision, not outstanding work.');

    $this->drupalLogin($staff);
    $this->drupalGet('/admin/people/referrals');
    // Kate's wording: an automatic match is never labelled as a staff decision.
    $this->assertSession()->pageTextContains('Identity matched');
    $this->assertSession()->pageTextContains('The member, at signup');
    $this->assertSession()->pageTextContains('A row can arrive as Identity matched');
    $this->assertSession()->elementTextNotContains('css', 'table', 'Identity confirmed');
    // Nothing on the page attributes the decision to a staff account or a uid.
    $this->assertSession()->pageTextNotContains('Staff account 0');
    $this->assertSession()->pageTextNotContains('Awaiting staff');

    $this->clickLink('Review');
    $this->assertSession()->pageTextContains('Identity matched · decided by the member themselves, from the list on the thank-you page');
    $this->assertSession()->pageTextNotContains('reviewer account 0');

    // Staff overriding it becomes a staff decision, attributed to them.
    $this->submitForm(['status' => 'pending'], 'Save review');
    $this->drupalGet('/admin/people/referrals');
    $this->assertSession()->pageTextContains('Awaiting staff');
    $this->assertSession()->pageTextNotContains('The member, at signup');
    $this->clickLink('Review');
    $this->assertSession()->pageTextContains('Needs review · decided by ' . $staff->getDisplayName());
  }

  /**
   * The profile field the referral answer lives in, as on the real site.
   */
  protected function createReferringField(): void {
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
  }

}
