<?php

declare(strict_types=1);

namespace Drupal\Tests\makerspace_referrals\Functional;

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\Core\Test\AssertMailTrait;
use Drupal\Tests\BrowserTestBase;

/**
 * Walks the member and staff journeys in a real browser.
 *
 * The kernel tests prove the money rules. This proves the pages a person
 * actually touches exist, are locked to the right people, and that sending an
 * invitation really does produce an email and a stored record — the two things
 * Kate's July request was asking for.
 *
 * @group makerspace_referrals
 */
class ReferralInviteJourneyTest extends BrowserTestBase {

  use AssertMailTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['makerspace_referrals'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $fields = [
      ['user', 'user', 'field_first_name'],
      ['user', 'user', 'field_last_name'],
      ['user', 'user', 'field_user_chargebee_id'],
    ];
    foreach ($fields as [$entity, $bundle, $field]) {
      FieldStorageConfig::create(['entity_type' => $entity, 'field_name' => $field, 'type' => 'string'])->save();
      FieldConfig::create(['entity_type' => $entity, 'bundle' => $bundle, 'field_name' => $field])->save();
    }
  }

  /**
   * A member sends an invitation; it is stored and emailed.
   */
  public function testMemberCanSendAnInvitation(): void {
    $this->config('makerspace_referrals.settings')
      ->set('join_url', 'https://example.chargebee.com/hosted_pages/plans/standard')
      ->set('invitee_coupon', 'REFERRED')
      ->save();

    $member = $this->drupalCreateUser(['send member referral invitations']);
    $member->set('field_first_name', 'Sam')->set('field_last_name', 'Sender')->save();
    $this->drupalLogin($member);

    $this->drupalGet('/referral/invite');
    $this->assertSession()->statusCodeEquals(200);
    // The amount comes from one constant; the page must actually say it.
    $this->assertSession()->pageTextContains('$50.00');

    $this->submitForm([
      'first_name' => 'Jo',
      'last_name' => 'Invitee',
      'email' => 'jo.invitee@example.com',
      'attest' => TRUE,
    ], 'Send invitation');
    $this->assertSession()->pageTextContains('Invitation sent to Jo Invitee');

    $invite = \Drupal::database()->select('makerspace_referral_invite', 'i')
      ->fields('i')
      ->condition('invitee_email', 'jo.invitee@example.com')
      ->execute()
      ->fetchObject();
    $this->assertNotFalse($invite, 'The invitation is recorded, which is what makes attribution structural.');
    $this->assertSame((int) $member->id(), (int) $invite->inviter_uid);

    $mails = $this->getMails();
    $this->assertCount(1, $mails);
    $this->assertStringContainsString('invited you to join MakeHaven', $mails[0]['subject']);
    $this->assertStringContainsString('Sam Sender', $mails[0]['subject']);
    // The link must carry the coupon and their address, or the offer is empty.
    $this->assertStringContainsString('REFERRED', $mails[0]['body']);
    $this->assertStringContainsString('jo.invitee%40example.com', $mails[0]['body']);
  }

  /**
   * The form refuses the cases that would pay a credit wrongly.
   */
  public function testFormRefusesSelfAndExistingAccounts(): void {
    $member = $this->drupalCreateUser(['send member referral invitations']);
    $existing = $this->drupalCreateUser();
    $this->drupalLogin($member);

    $this->drupalGet('/referral/invite');
    $this->submitForm([
      'first_name' => 'Me',
      'last_name' => 'Myself',
      'email' => $member->getEmail(),
      'attest' => TRUE,
    ], 'Send invitation');
    $this->assertSession()->pageTextContains('That is your own address.');

    $this->drupalGet('/referral/invite');
    $this->submitForm([
      'first_name' => 'Already',
      'last_name' => 'Here',
      'email' => $existing->getEmail(),
      'attest' => TRUE,
    ], 'Send invitation');
    $this->assertSession()->pageTextContains('already has an account');

    $this->assertSame(0, (int) \Drupal::database()->select('makerspace_referral_invite', 'i')->countQuery()->execute()->fetchField());
  }

  /**
   * Anonymous visitors cannot invite anyone.
   */
  public function testAnonymousCannotInvite(): void {
    $this->drupalGet('/referral/invite');
    $this->assertSession()->statusCodeEquals(403);
  }

  /**
   * The staff console renders, and says plainly why nothing is being paid.
   */
  public function testStaffConsoleWarnsWhenNothingWillPay(): void {
    $staff = $this->drupalCreateUser(['review member referrals']);
    $this->drupalLogin($staff);

    $this->drupalGet('/admin/people/referrals/awards');
    $this->assertSession()->statusCodeEquals(200);
    // Shipped off, unconfigured: the page has to say so rather than look calm.
    $this->assertSession()->pageTextContains('Automatic credits are switched OFF');
    $this->assertSession()->pageTextContains('No start date is set');
    $this->assertSession()->pageTextContains('No referral credits yet');
  }

  /**
   * A member without the staff permission cannot see the money pages.
   */
  public function testConsoleIsStaffOnly(): void {
    $member = $this->drupalCreateUser(['send member referral invitations']);
    $this->drupalLogin($member);

    $this->drupalGet('/admin/people/referrals/awards');
    $this->assertSession()->statusCodeEquals(403);

    $this->drupalGet('/admin/config/people/referrals');
    $this->assertSession()->statusCodeEquals(403);
  }

  /**
   * Settings refuse the combination that promises a discount and gives none.
   */
  public function testSettingsRefuseEnablingWithoutCoupon(): void {
    $admin = $this->drupalCreateUser(['administer member referral awards']);
    $this->drupalLogin($admin);

    $this->drupalGet('/admin/config/people/referrals');
    $this->assertSession()->statusCodeEquals(200);

    $this->submitForm([
      'awards_enabled' => TRUE,
      'invitee_coupon' => '',
      'awards_start' => '2026-09-17',
    ], 'Save configuration');
    $this->assertSession()->pageTextContains('Set the coupon before switching credits on');
    $this->assertFalse((bool) $this->config('makerspace_referrals.settings')->get('awards_enabled'));
  }

}
