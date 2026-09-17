<?php

declare(strict_types=1);

namespace Drupal\Tests\makerspace_referrals\Kernel;

use Drupal\chargebee_status_sync\Event\ChargebeeWebhookEvent;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\makerspace_referrals\Service\ReferralThanks;
use Drupal\profile\Entity\Profile;
use Drupal\profile\Entity\ProfileType;
use Drupal\user\Entity\User;

/**
 * Tests the thank-you: who hears from us, and who deliberately does not.
 *
 * The valuable assertions here are the ones about silence. A thank-you that
 * never sends and leaves no trace is exactly the failure this phase exists to
 * end — 88% of people who say a member referred them name that member, and not
 * one of them had ever been acknowledged.
 *
 * @group makerspace_referrals
 */
class ReferralThanksTest extends KernelTestBase {

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
      ['user', 'user', 'field_user_chargebee_id', 'string'],
      ['user', 'user', 'field_mail_suppression', 'string'],
    ];
    foreach ($fields as [$entity, $bundle, $field, $type]) {
      FieldStorageConfig::create(['entity_type' => $entity, 'field_name' => $field, 'type' => $type])->save();
      FieldConfig::create(['entity_type' => $entity, 'bundle' => $bundle, 'field_name' => $field])->save();
    }

    $this->config('makerspace_referrals.settings')
      ->set('thanks_enabled', TRUE)
      ->set('staff_email', 'membership@example.com')
      ->set('awards_start', 0)
      ->save();
  }

  /**
   * Creates an account.
   */
  private function member(string $name, string $mail, string $cb = '', string $first = ''): User {
    $user = User::create(['name' => $name, 'mail' => $mail, 'status' => 1]);
    if ($cb !== '') {
      $user->set('field_user_chargebee_id', $cb);
    }
    if ($first !== '') {
      $user->set('field_first_name', $first);
    }
    $user->save();
    return $user;
  }

  /**
   * Gives a member a referral answer, optionally confirmed by staff.
   */
  private function named(User $referred, string $answer, ?User $confirmed_as = NULL): void {
    $profile = Profile::create([
      'type' => 'main',
      'uid' => $referred->id(),
      'field_member_referring' => $answer,
    ]);
    $profile->save();

    if ($confirmed_as) {
      $review = $this->container->get('makerspace_referrals.review');
      $review->decide((int) $profile->id(), hash('sha256', $review->source($profile)), 0, 'confirmed', (int) $confirmed_as->id(), 1);
    }
  }

  /**
   * Collected mail.
   */
  private function mails(): array {
    return $this->container->get('state')->get('system.test_mail_collector') ?: [];
  }

  /**
   * The service under test.
   */
  private function thanks(): ReferralThanks {
    return $this->container->get('makerspace_referrals.thanks');
  }

  /**
   * A confirmed referrer gets thanked, by name.
   */
  public function testConfirmedReferrerIsThanked(): void {
    $referrer = $this->member('sam', 'sam@example.com', 'cb_sam', 'Sam');
    $joiner = $this->member('jo', 'jo@example.com', 'cb_jo', 'Jo');
    $this->named($joiner, 'sam', $referrer);

    $this->assertSame(ReferralThanks::SENT, $this->thanks()->handle((int) $joiner->id()));

    $mails = $this->mails();
    $this->assertCount(1, $mails);
    $this->assertSame('sam@example.com', $mails[0]['to']);
    $this->assertStringContainsString('Thank you for introducing Jo', $mails[0]['subject']);
  }

  /**
   * With no credit recorded, the email must not mention one.
   *
   * Thanks can run with awards switched off; promising money that was never
   * recorded would be worse than saying nothing.
   */
  public function testThanksWithoutAnAwardPromisesNoMoney(): void {
    $referrer = $this->member('sam', 'sam@example.com', 'cb_sam', 'Sam');
    $joiner = $this->member('jo', 'jo@example.com', 'cb_jo', 'Jo');
    $this->named($joiner, 'sam', $referrer);

    $this->thanks()->handle((int) $joiner->id());

    $this->assertStringNotContainsString('$', $this->mails()[0]['body']);
    $this->assertStringNotContainsString('credit', $this->mails()[0]['body']);
  }

  /**
   * When a credit exists, the thank-you says so.
   */
  public function testThanksMentionsTheCreditWhenThereIsOne(): void {
    $referrer = $this->member('sam', 'sam@example.com', 'cb_sam', 'Sam');
    $joiner = $this->member('jo', 'jo@example.com', 'cb_jo', 'Jo');
    $this->named($joiner, 'sam', $referrer);

    $this->container->get('makerspace_referrals.award')->recordAndQueue((int) $joiner->id());
    $this->thanks()->handle((int) $joiner->id());

    $this->assertStringContainsString('$50.00', $this->mails()[0]['body']);
  }

  /**
   * A suppressed address is recorded as suppressed, not silently skipped.
   */
  public function testSuppressedReferrerIsRecordedNotEmailed(): void {
    $referrer = $this->member('sam', 'sam@example.com', 'cb_sam', 'Sam');
    $referrer->set('field_mail_suppression', 'unsubsribe')->save();
    $joiner = $this->member('jo', 'jo@example.com', 'cb_jo', 'Jo');
    $this->named($joiner, 'sam', $referrer);

    $this->assertSame(ReferralThanks::SUPPRESSED, $this->thanks()->handle((int) $joiner->id()));
    $this->assertCount(0, $this->mails(), 'A suppressed address must not be emailed, however nice the email.');
  }

  /**
   * An unconfirmed name asks staff to decide, rather than going quiet.
   */
  public function testUnconfirmedNameChasesStaff(): void {
    $joiner = $this->member('jo', 'jo@example.com', 'cb_jo', 'Jo');
    $this->named($joiner, 'somebody nobody matched');

    $this->assertSame(ReferralThanks::UNRESOLVED, $this->thanks()->handle((int) $joiner->id()));

    $mails = $this->mails();
    $this->assertCount(1, $mails);
    $this->assertSame('membership@example.com', $mails[0]['to']);
    $this->assertStringContainsString('somebody nobody matched', $mails[0]['subject']);
  }

  /**
   * Naming nobody is recorded too, so the silence is visible.
   */
  public function testNamingNobodyIsStillRecorded(): void {
    $joiner = $this->member('jo', 'jo@example.com', 'cb_jo', 'Jo');

    $this->assertSame(ReferralThanks::NONE, $this->thanks()->handle((int) $joiner->id()));
    $this->assertCount(0, $this->mails());
    $this->assertTrue($this->thanks()->handled((int) $joiner->id()), 'Even "nobody" is a recorded outcome.');
  }

  /**
   * Nobody is ever thanked twice.
   */
  public function testNobodyIsThankedTwice(): void {
    $referrer = $this->member('sam', 'sam@example.com', 'cb_sam', 'Sam');
    $joiner = $this->member('jo', 'jo@example.com', 'cb_jo', 'Jo');
    $this->named($joiner, 'sam', $referrer);

    $this->assertSame(ReferralThanks::SENT, $this->thanks()->handle((int) $joiner->id()));
    $this->assertSame('', $this->thanks()->handle((int) $joiner->id()), 'A repeat is a no-op.');
    $this->assertCount(1, $this->mails());
  }

  /**
   * Renewals do not re-thank, month after month.
   */
  public function testRenewalsDoNotReThank(): void {
    $referrer = $this->member('sam', 'sam@example.com', 'cb_sam', 'Sam');
    $joiner = $this->member('jo', 'jo@example.com', 'cb_jo', 'Jo');
    $this->named($joiner, 'sam', $referrer);

    for ($i = 0; $i < 3; $i++) {
      $this->container->get('event_dispatcher')->dispatch(
        new ChargebeeWebhookEvent('payment_succeeded', 'cb_jo', ['event_type' => 'payment_succeeded']),
        ChargebeeWebhookEvent::NAME
      );
    }

    $this->assertCount(1, $this->mails(), 'One member, one thank-you, however many payments.');
  }

  /**
   * Thanks run even while automatic credits are switched off.
   *
   * This is the whole point of the phase: the recognition must not be held
   * hostage by the billing switch.
   */
  public function testThanksRunWhileAwardsAreOff(): void {
    $this->assertFalse((bool) $this->config('makerspace_referrals.settings')->get('awards_enabled'));

    $referrer = $this->member('sam', 'sam@example.com', 'cb_sam', 'Sam');
    $joiner = $this->member('jo', 'jo@example.com', 'cb_jo', 'Jo');
    $this->named($joiner, 'sam', $referrer);

    $this->container->get('event_dispatcher')->dispatch(
      new ChargebeeWebhookEvent('payment_succeeded', 'cb_jo', ['event_type' => 'payment_succeeded']),
      ChargebeeWebhookEvent::NAME
    );

    $this->assertCount(1, $this->mails());
    $awards = (int) $this->container->get('database')->select('makerspace_referral_award', 'a')
      ->countQuery()->execute()->fetchField();
    $this->assertSame(0, $awards, 'No money moved.');
  }

  /**
   * Switched off, nothing is sent and nothing is recorded.
   */
  public function testNothingHappensWhileThanksAreOff(): void {
    $this->config('makerspace_referrals.settings')->set('thanks_enabled', FALSE)->save();

    $referrer = $this->member('sam', 'sam@example.com', 'cb_sam', 'Sam');
    $joiner = $this->member('jo', 'jo@example.com', 'cb_jo', 'Jo');
    $this->named($joiner, 'sam', $referrer);

    $this->container->get('event_dispatcher')->dispatch(
      new ChargebeeWebhookEvent('payment_succeeded', 'cb_jo', ['event_type' => 'payment_succeeded']),
      ChargebeeWebhookEvent::NAME
    );

    $this->assertCount(0, $this->mails());
    $this->assertFalse($this->thanks()->handled((int) $joiner->id()));
  }

  /**
   * The forward-only cutoff applies to thanks as well as to money.
   */
  public function testOldMembersAreNotThankedYearsLate(): void {
    $cutoff = time() - 86400;
    $this->config('makerspace_referrals.settings')->set('awards_start', $cutoff)->save();

    $referrer = $this->member('sam', 'sam@example.com', 'cb_sam', 'Sam');
    $joiner = User::create([
      'name' => 'jo',
      'mail' => 'jo@example.com',
      'status' => 1,
      'field_user_chargebee_id' => 'cb_jo',
      'created' => $cutoff - 86400 * 400,
    ]);
    $joiner->save();
    $this->named($joiner, 'sam', $referrer);

    $this->container->get('event_dispatcher')->dispatch(
      new ChargebeeWebhookEvent('payment_succeeded', 'cb_jo', ['event_type' => 'payment_succeeded']),
      ChargebeeWebhookEvent::NAME
    );

    $this->assertCount(0, $this->mails(), 'A renewal by a long-standing member must not thank anyone.');
  }

  /**
   * The member's own list shows who they introduced.
   */
  public function testReferredByListsTheirPeople(): void {
    $referrer = $this->member('sam', 'sam@example.com', 'cb_sam', 'Sam');
    foreach (['a', 'b'] as $n) {
      $joiner = $this->member($n, $n . '@example.com', 'cb_' . $n, strtoupper($n));
      $this->named($joiner, 'sam', $referrer);
      $this->thanks()->handle((int) $joiner->id());
    }

    $this->assertCount(2, $this->thanks()->referredBy((int) $referrer->id()));
  }

}
