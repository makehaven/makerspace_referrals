<?php

declare(strict_types=1);

namespace Drupal\Tests\makerspace_referrals\Kernel;

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\makerspace_referrals\Service\ReferralReview;
use Drupal\profile\Entity\Profile;
use Drupal\profile\Entity\ProfileType;
use Drupal\user\Entity\User;

/**
 * Tests that staff hear about a new referral without waiting on billing.
 *
 * Kate found a pending referral by checking the queue by hand (feedback sid
 * 20834), because the only staff email waited on a payment webhook that
 * rarely matched a new account.
 *
 * @group makerspace_referrals
 */
class ReviewAlertTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'profile',
    'chargebee_status_sync',
    'makerspace_referrals',
  ];

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
    $this->installConfig(['makerspace_referrals', 'system']);

    ProfileType::create(['id' => 'main', 'label' => 'Main'])->save();
    $fields = [
      ['profile', 'main', 'field_member_referring', 'string'],
      ['user', 'user', 'field_first_name', 'string'],
      ['user', 'user', 'field_last_name', 'string'],
    ];
    foreach ($fields as [$entity, $bundle, $field, $type]) {
      FieldStorageConfig::create(['entity_type' => $entity, 'field_name' => $field, 'type' => $type])->save();
      FieldConfig::create(['entity_type' => $entity, 'bundle' => $bundle, 'field_name' => $field])->save();
    }

    $this->config('makerspace_referrals.settings')
      ->set('review_email', 'hello@example.com')
      ->save();
  }

  /**
   * Creates an account.
   */
  private function member(string $name, string $first = '', string $last = ''): User {
    $user = User::create([
      'name' => $name,
      'mail' => $name . '@example.com',
      'status' => 1,
      'field_first_name' => $first,
      'field_last_name' => $last,
    ]);
    $user->save();
    return $user;
  }

  /**
   * Saves a main profile with this answer.
   */
  private function profile(User $owner, string $answer): Profile {
    $profile = Profile::create([
      'type' => 'main',
      'uid' => $owner->id(),
      'field_member_referring' => $answer,
    ]);
    $profile->save();
    return $profile;
  }

  /**
   * Ends the request, which is when the alert decides.
   */
  private function endRequest(): void {
    $this->container->get('makerspace_referrals.review_alert')->destruct();
  }

  /**
   * Alert mails collected so far.
   */
  private function alerts(): array {
    $mails = $this->container->get('state')->get('system.test_mail_collector') ?: [];
    return array_values(array_filter($mails, fn ($m) => $m['id'] === 'makerspace_referrals_review_needed'));
  }

  /**
   * A new, unconfirmed answer reaches the review address with a queue link.
   */
  public function testNewAnswerAlertsStaff(): void {
    $this->profile($this->member('marcus', 'Marcus', 'Duff'), 'Maya Duff');
    $this->endRequest();

    $alerts = $this->alerts();
    $this->assertCount(1, $alerts);
    $this->assertSame('hello@example.com', $alerts[0]['to']);
    $this->assertStringContainsString('Marcus Duff', (string) $alerts[0]['subject']);
    $this->assertStringContainsString('Maya Duff', (string) $alerts[0]['subject']);
    $this->assertStringContainsString('/admin/people/referrals', (string) $alerts[0]['body']);
  }

  /**
   * A matched answer still tells staff, who add coupon codes by hand.
   */
  public function testSelfPickedReferrerStillAlertsStaff(): void {
    $referrer = $this->member('maya', 'Maya', 'Duff');
    $profile = $this->profile($this->member('marcus'), 'Maya Duff');
    $review = $this->container->get('makerspace_referrals.review');
    $review->decide((int) $profile->id(), hash('sha256', $review->source($profile)), 0, 'confirmed', (int) $referrer->id(), ReferralReview::SELF_PICK);
    $this->endRequest();

    $alerts = $this->alerts();
    $this->assertCount(1, $alerts);
    $this->assertStringContainsString('New referral', (string) $alerts[0]['subject']);
    $this->assertStringNotContainsString('awaiting review', (string) $alerts[0]['subject']);
    $this->assertStringContainsString('already matched to Maya Duff', (string) $alerts[0]['body']);
  }

  /**
   * Re-saving the same answer, or editing something else, sends nothing.
   */
  public function testUnchangedAnswerIsSilent(): void {
    $profile = $this->profile($this->member('marcus'), 'Maya Duff');
    $this->endRequest();
    $this->assertCount(1, $this->alerts());

    $profile->save();
    $this->endRequest();
    $this->assertCount(1, $this->alerts());

    $profile->set('field_member_referring', 'Maya D.')->save();
    $this->endRequest();
    $this->assertCount(2, $this->alerts());
  }

  /**
   * With no address configured the queue is the only signal, as before.
   */
  public function testNoAddressNoMail(): void {
    $this->config('makerspace_referrals.settings')->set('review_email', '')->save();
    $this->profile($this->member('marcus'), 'Maya Duff');
    $this->endRequest();

    $this->assertCount(0, $this->alerts());
  }

}
