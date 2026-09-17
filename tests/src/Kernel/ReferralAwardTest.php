<?php

declare(strict_types=1);

namespace Drupal\Tests\makerspace_referrals\Kernel;

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\makerspace_referrals\Service\ChargebeeCredit;
use Drupal\makerspace_referrals\Service\ReferralAward;
use Drupal\profile\Entity\Profile;
use Drupal\profile\Entity\ProfileType;
use Drupal\user\Entity\User;

/**
 * Tests the money side: who gets paid, who does not, and how often.
 *
 * This feature moves real money on a live billing account, so the tests that
 * matter most are the ones asserting that nothing is paid — twice, late, or to
 * somebody nobody named.
 *
 * @group makerspace_referrals
 */
class ReferralAwardTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'profile',
    'makerspace_referrals',
  ];

  /**
   * A stand-in for Chargebee, so no test can reach the real billing account.
   */
  private TestChargebeeCredit $chargebee;

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

    // Swap the real client out. A test that can call Chargebee is a test that
    // will eventually credit somebody's account by accident.
    $this->chargebee = new TestChargebeeCredit();
    $this->container->set('makerspace_referrals.chargebee_credit', $this->chargebee);
  }

  /**
   * The award service, rebuilt against the stubbed Chargebee client.
   */
  private function awards(): ReferralAward {
    return new ReferralAward(
      $this->container->get('database'),
      $this->container->get('entity_type.manager'),
      $this->container->get('datetime.time'),
      $this->container->get('queue'),
      $this->container->get('makerspace_referrals.review'),
      $this->container->get('makerspace_referrals.invite'),
      $this->chargebee,
      $this->container->get('logger.factory')->get('test'),
    );
  }

  /**
   * Creates an account.
   */
  private function member(string $name, string $mail, string $cb_id = ''): User {
    $user = User::create(['name' => $name, 'mail' => $mail, 'status' => 1]);
    if ($cb_id !== '') {
      $user->set('field_user_chargebee_id', $cb_id);
    }
    $user->save();
    return $user;
  }

  /**
   * Records a confirmed staff decision that $referrer referred $referred.
   */
  private function confirmReview(User $referred, User $referrer): void {
    $profile = Profile::create([
      'type' => 'main',
      'uid' => $referred->id(),
      'field_member_referring' => $referrer->getAccountName(),
    ]);
    $profile->save();

    $review = $this->container->get('makerspace_referrals.review');
    $review->decide(
      (int) $profile->id(),
      $review->source($profile) !== '' ? hash('sha256', $review->source($profile)) : '',
      0,
      'confirmed',
      (int) $referrer->id(),
      1,
    );
  }

  /**
   * An invitation the referred member used is enough to identify a referrer.
   */
  public function testInviteIdentifiesTheReferrer(): void {
    $referrer = $this->member('sender', 'sender@example.com', 'cb_sender');
    $invited = $this->member('joiner', 'joiner@example.com', 'cb_joiner');

    $this->container->get('makerspace_referrals.invite')
      ->create($referrer, 'JOINER@example.com', 'Jo', 'Iner');

    $resolved = $this->awards()->resolveReferrer((int) $invited->id());

    $this->assertNotNull($resolved, 'A stored invitation identifies the referrer.');
    $this->assertSame((int) $referrer->id(), $resolved['uid']);
    $this->assertSame(ReferralAward::SOURCE_INVITE, $resolved['source']);
  }

  /**
   * Naming a member at signup is the other agreed route.
   */
  public function testConfirmedReviewIdentifiesTheReferrer(): void {
    $referrer = $this->member('named', 'named@example.com', 'cb_named');
    $joiner = $this->member('newbie', 'newbie@example.com', 'cb_newbie');
    $this->confirmReview($joiner, $referrer);

    $resolved = $this->awards()->resolveReferrer((int) $joiner->id());

    $this->assertNotNull($resolved);
    $this->assertSame((int) $referrer->id(), $resolved['uid']);
    $this->assertSame(ReferralAward::SOURCE_REVIEW, $resolved['source']);
  }

  /**
   * Nobody named means nobody paid. We never infer a referrer.
   */
  public function testNoIdentificationMeansNoCredit(): void {
    $joiner = $this->member('alone', 'alone@example.com', 'cb_alone');

    $this->assertNull($this->awards()->resolveReferrer((int) $joiner->id()));
    $this->assertNull($this->awards()->recordAndQueue((int) $joiner->id()));
    $this->assertSame(0, $this->chargebee->calls, 'Chargebee must not be touched when nobody was named.');
  }

  /**
   * The same member can never earn two credits, however often we are told.
   *
   * Chargebee replays webhooks, cron retries queue items and staff click
   * things twice. The unique key on referred_uid is what makes all three
   * harmless, and this is the assertion that proves it.
   */
  public function testSecondTriggerPaysNothingExtra(): void {
    $referrer = $this->member('ref', 'ref@example.com', 'cb_ref');
    $joiner = $this->member('join', 'join@example.com', 'cb_join');
    $this->confirmReview($joiner, $referrer);

    $first = $this->awards()->recordAndQueue((int) $joiner->id());
    $this->assertNotNull($first, 'The first trigger records an award.');

    $second = $this->awards()->recordAndQueue((int) $joiner->id());
    $this->assertNull($second, 'A replayed trigger must record nothing.');

    $count = (int) $this->container->get('database')
      ->select('makerspace_referral_award', 'a')
      ->condition('referred_uid', $joiner->id())
      ->countQuery()->execute()->fetchField();
    $this->assertSame(1, $count, 'Exactly one award row, whatever we were told.');
  }

  /**
   * A successful call marks the award paid and records what Chargebee said.
   */
  public function testSuccessfulPaymentIsRecorded(): void {
    $referrer = $this->member('ref', 'ref@example.com', 'cb_ref');
    $joiner = $this->member('join', 'join@example.com', 'cb_join');
    $this->confirmReview($joiner, $referrer);

    $awards = $this->awards();
    $id = $awards->recordAndQueue((int) $joiner->id());
    $this->assertSame('awarded', $awards->pay((int) $id));

    $row = $awards->load((int) $id);
    $this->assertSame('awarded', $row->status);
    $this->assertSame(ReferralAward::AMOUNT_CENTS, (int) $row->amount_cents);
    $this->assertSame('cb_ref', $row->referrer_cb_id, 'The credit is recorded against the account it went to.');
    $this->assertSame(1, (int) $row->attempts);
    $this->assertSame('cb_ref', $this->chargebee->lastCustomer);
  }

  /**
   * A permanent rejection fails immediately rather than retrying forever.
   */
  public function testPermanentFailureDoesNotRetry(): void {
    $referrer = $this->member('ref', 'ref@example.com', 'cb_ref');
    $joiner = $this->member('join', 'join@example.com', 'cb_join');
    $this->confirmReview($joiner, $referrer);

    $this->chargebee->outcome = ChargebeeCredit::PERMANENT;
    $awards = $this->awards();
    $id = $awards->recordAndQueue((int) $joiner->id());

    $this->assertSame('failed', $awards->pay((int) $id));
    $this->assertSame(1, (int) $awards->load((int) $id)->attempts, 'A permanent failure must not burn retries.');
  }

  /**
   * A retryable failure stays pending until the attempts run out.
   */
  public function testRetryableFailureGivesUpEventually(): void {
    $referrer = $this->member('ref', 'ref@example.com', 'cb_ref');
    $joiner = $this->member('join', 'join@example.com', 'cb_join');
    $this->confirmReview($joiner, $referrer);

    $this->chargebee->outcome = ChargebeeCredit::RETRY;
    $awards = $this->awards();
    $id = $awards->recordAndQueue((int) $joiner->id());

    for ($i = 1; $i < ReferralAward::MAX_ATTEMPTS; $i++) {
      $this->assertSame('pending', $awards->pay((int) $id), "Attempt $i should still be pending.");
    }
    $this->assertSame('failed', $awards->pay((int) $id), 'The last attempt gives up loudly.');
    $this->assertSame(ReferralAward::MAX_ATTEMPTS, (int) $awards->load((int) $id)->attempts);
  }

  /**
   * A failed credit can be retried by a human and then succeed.
   */
  public function testStaffCanRetryFailedCredit(): void {
    $referrer = $this->member('ref', 'ref@example.com', 'cb_ref');
    $joiner = $this->member('join', 'join@example.com', 'cb_join');
    $this->confirmReview($joiner, $referrer);

    $this->chargebee->outcome = ChargebeeCredit::PERMANENT;
    $awards = $this->awards();
    $id = $awards->recordAndQueue((int) $joiner->id());
    $awards->pay((int) $id);
    $this->assertSame('failed', $awards->load((int) $id)->status);

    $this->chargebee->outcome = ChargebeeCredit::OK;
    $this->assertTrue($awards->retry((int) $id));
    $this->assertSame('pending', $awards->load((int) $id)->status);
    $this->assertSame('awarded', $awards->pay((int) $id));
  }

  /**
   * A reversal is recorded here but never sent to Chargebee.
   *
   * Money comes back where the bookkeeper can see it, not silently from a
   * Drupal button.
   */
  public function testReversalRecordsButDoesNotCallChargebee(): void {
    $referrer = $this->member('ref', 'ref@example.com', 'cb_ref');
    $joiner = $this->member('join', 'join@example.com', 'cb_join');
    $this->confirmReview($joiner, $referrer);

    $awards = $this->awards();
    $id = $awards->recordAndQueue((int) $joiner->id());
    $awards->pay((int) $id);
    $before = $this->chargebee->calls;

    $this->assertTrue($awards->reverse((int) $id, 1, 'Signed up under two accounts.'));
    $row = $awards->load((int) $id);
    $this->assertSame('reversed', $row->status);
    $this->assertSame('Signed up under two accounts.', $row->note);
    $this->assertSame($before, $this->chargebee->calls, 'Reversing must not call Chargebee.');
  }

  /**
   * Paying an award that is not pending is a no-op, not a second payment.
   */
  public function testPayingSettledAwardDoesNothing(): void {
    $referrer = $this->member('ref', 'ref@example.com', 'cb_ref');
    $joiner = $this->member('join', 'join@example.com', 'cb_join');
    $this->confirmReview($joiner, $referrer);

    $awards = $this->awards();
    $id = $awards->recordAndQueue((int) $joiner->id());
    $awards->pay((int) $id);
    $calls = $this->chargebee->calls;

    $this->assertSame('awarded', $awards->pay((int) $id));
    $this->assertSame($calls, $this->chargebee->calls, 'A settled award must never be sent again.');
  }

  /**
   * A referrer with no Chargebee id fails rather than sending money nowhere.
   */
  public function testMissingChargebeeIdFailsPermanently(): void {
    $referrer = $this->member('ref', 'ref@example.com');
    $joiner = $this->member('join', 'join@example.com', 'cb_join');
    $this->confirmReview($joiner, $referrer);

    $awards = $this->awards();
    $id = $awards->recordAndQueue((int) $joiner->id());
    $this->assertSame('failed', $awards->pay((int) $id));
    $this->assertStringContainsString('customer id', (string) $awards->load((int) $id)->last_error);
  }

  /**
   * Claiming an invitation closes it, so a second person cannot use it.
   */
  public function testClaimingAnInvitationClosesIt(): void {
    $referrer = $this->member('ref', 'ref@example.com', 'cb_ref');
    $joiner = $this->member('join', 'join@example.com', 'cb_join');
    $invites = $this->container->get('makerspace_referrals.invite');
    $invites->create($referrer, 'join@example.com', 'Jo', 'In');

    $this->awards()->recordAndQueue((int) $joiner->id());

    $this->assertNull($invites->findOpenForEmail('join@example.com'), 'A used invitation is no longer open.');
  }

  /**
   * Somebody cannot refer themselves.
   */
  public function testSelfReferralIsRefused(): void {
    $self = $this->member('solo', 'solo@example.com', 'cb_solo');
    $this->container->get('makerspace_referrals.invite')->create($self, 'solo@example.com', 'So', 'Lo');

    $this->assertNull($this->awards()->resolveReferrer((int) $self->id()));
  }

}
