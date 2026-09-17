<?php

declare(strict_types=1);

namespace Drupal\Tests\makerspace_referrals\Kernel;

use Drupal\chargebee_status_sync\Event\ChargebeeWebhookEvent;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\profile\Entity\Profile;
use Drupal\profile\Entity\ProfileType;
use Drupal\user\Entity\User;

/**
 * Tests when a payment webhook is allowed to mint a credit.
 *
 * Chargebee sends payment_succeeded for every renewal, so almost all of this
 * class is about the payments that must NOT pay anyone — the master switch,
 * the forward-only cutoff, and the fact that a member only earns once.
 *
 * @group makerspace_referrals
 */
class ReferralEligibilityTest extends KernelTestBase {

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
    ]);
    $this->installConfig(['makerspace_referrals']);

    ProfileType::create(['id' => 'main', 'label' => 'Main'])->save();
    $fields = [
      ['profile', 'main', 'field_member_referring'],
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
   * Builds a referrer, a referred member, and a confirmed link between them.
   *
   * @return array
   *   [referrer, referred]
   */
  private function pair(int $referred_created): array {
    $referrer = User::create([
      'name' => 'referrer',
      'mail' => 'referrer@example.com',
      'status' => 1,
      'field_user_chargebee_id' => 'cb_referrer',
    ]);
    $referrer->save();

    $referred = User::create([
      'name' => 'referred',
      'mail' => 'referred@example.com',
      'status' => 1,
      'field_user_chargebee_id' => 'cb_referred',
      'created' => $referred_created,
    ]);
    $referred->save();

    $profile = Profile::create([
      'type' => 'main',
      'uid' => $referred->id(),
      'field_member_referring' => 'referrer',
    ]);
    $profile->save();

    $review = $this->container->get('makerspace_referrals.review');
    $review->decide((int) $profile->id(), hash('sha256', $review->source($profile)), 0, 'confirmed', (int) $referrer->id(), 1);

    return [$referrer, $referred];
  }

  /**
   * Fires a payment_succeeded webhook for a Chargebee customer.
   */
  private function paymentSucceeded(string $customer_id): void {
    $this->container->get('event_dispatcher')->dispatch(
      new ChargebeeWebhookEvent('payment_succeeded', $customer_id, [
        'event_type' => 'payment_succeeded',
        'content' => ['customer' => ['id' => $customer_id]],
      ]),
      ChargebeeWebhookEvent::NAME
    );
  }

  /**
   * How many awards exist.
   */
  private function awardCount(): int {
    return (int) $this->container->get('database')
      ->select('makerspace_referral_award', 'a')
      ->countQuery()->execute()->fetchField();
  }

  /**
   * Turns the programme on with a cutoff.
   */
  private function enable(int $start): void {
    $this->config('makerspace_referrals.settings')
      ->set('awards_enabled', TRUE)
      ->set('awards_start', $start)
      ->save();
  }

  /**
   * Shipped off: a payment pays nobody until someone decides otherwise.
   */
  public function testNothingIsPaidWhileSwitchedOff(): void {
    [, $referred] = $this->pair(time());
    $this->assertFalse((bool) $this->config('makerspace_referrals.settings')->get('awards_enabled'), 'Ships off.');

    $this->paymentSucceeded('cb_referred');

    $this->assertSame(0, $this->awardCount(), 'The master switch must gate everything.');
  }

  /**
   * A member who joined after the cutoff earns their referrer a credit.
   */
  public function testMemberWhoJoinedAfterTheCutoffEarnsCredit(): void {
    $cutoff = time() - 86400;
    [$referrer, $referred] = $this->pair(time());
    $this->enable($cutoff);

    $this->paymentSucceeded('cb_referred');

    $this->assertSame(1, $this->awardCount());
    $award = $this->container->get('database')
      ->select('makerspace_referral_award', 'a')->fields('a')
      ->condition('referred_uid', $referred->id())->execute()->fetchObject();
    $this->assertSame((int) $referrer->id(), (int) $award->referrer_uid);
    $this->assertSame('pending', $award->status, 'Queued, not paid inside the webhook.');
  }

  /**
   * Let the past be the past: a long-standing member earns nothing.
   *
   * Without this, the next monthly renewal of anyone who ever named a referrer
   * would mint a $50 credit years late, which is exactly the historical
   * backlog JR put out of scope on 2026-09-17.
   */
  public function testMemberWhoJoinedBeforeTheCutoffEarnsNothing(): void {
    $cutoff = time() - 86400;
    $this->pair($cutoff - 86400 * 365);
    $this->enable($cutoff);

    $this->paymentSucceeded('cb_referred');

    $this->assertSame(0, $this->awardCount(), 'A renewal by an old member must not pay a historical referral.');
  }

  /**
   * Renewals do not pay again, month after month.
   */
  public function testRenewalsDoNotPayAgain(): void {
    $this->pair(time());
    $this->enable(time() - 86400);

    $this->paymentSucceeded('cb_referred');
    $this->paymentSucceeded('cb_referred');
    $this->paymentSucceeded('cb_referred');

    $this->assertSame(1, $this->awardCount(), 'One member, one credit, however many payments.');
  }

  /**
   * Other billing events are ignored.
   */
  public function testOtherWebhookEventsDoNothing(): void {
    $this->pair(time());
    $this->enable(time() - 86400);

    $this->container->get('event_dispatcher')->dispatch(
      new ChargebeeWebhookEvent('subscription_renewed', 'cb_referred', ['event_type' => 'subscription_renewed']),
      ChargebeeWebhookEvent::NAME
    );

    $this->assertSame(0, $this->awardCount(), 'Only a successful payment triggers a credit.');
  }

  /**
   * An unknown Chargebee customer is ignored rather than guessed at.
   */
  public function testUnknownCustomerIsIgnored(): void {
    $this->pair(time());
    $this->enable(time() - 86400);

    $this->paymentSucceeded('cb_nobody');

    $this->assertSame(0, $this->awardCount());
  }

  /**
   * An ambiguous Chargebee id pays nobody rather than picking one.
   */
  public function testDuplicateChargebeeIdPaysNobody(): void {
    $this->pair(time());
    $this->enable(time() - 86400);

    // A second account carrying the same Chargebee id: a data problem, and
    // guessing which of them earned a referral would be worse than stopping.
    User::create([
      'name' => 'twin',
      'mail' => 'twin@example.com',
      'status' => 1,
      'field_user_chargebee_id' => 'cb_referred',
    ])->save();

    $this->paymentSucceeded('cb_referred');

    $this->assertSame(0, $this->awardCount(), 'Ambiguity must stop the payment, not resolve it.');
  }

}
