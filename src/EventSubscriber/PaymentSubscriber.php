<?php

declare(strict_types=1);

namespace Drupal\makerspace_referrals\EventSubscriber;

use Drupal\chargebee_status_sync\Event\ChargebeeWebhookEvent;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\makerspace_referrals\Service\ReferralAward;
use Drupal\makerspace_referrals\Service\ReferralThanks;
use Drupal\user\UserInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Turns a referred member's first successful charge into a credit.
 *
 * "First successful charge" is the trigger Kate and JR chose on 2026-09-17,
 * over the published fine print's 30-day wait: simpler to explain, simpler to
 * check, and the rare delayed charge is explicitly not worth handling.
 *
 * Chargebee sends payment_succeeded for every renewal too, so this class is
 * mostly about deciding when NOT to pay.
 */
class PaymentSubscriber implements EventSubscriberInterface {

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly EntityTypeManagerInterface $entities,
    private readonly ReferralAward $awards,
    private readonly ReferralThanks $thanks,
    private readonly LoggerChannelInterface $log,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [ChargebeeWebhookEvent::NAME => ['onWebhook']];
  }

  /**
   * Reacts to a Chargebee webhook.
   */
  public function onWebhook(ChargebeeWebhookEvent $event): void {
    if ($event->eventType !== 'payment_succeeded') {
      return;
    }

    $settings = $this->configFactory->get('makerspace_referrals.settings');

    // Two switches, deliberately independent. Thanking somebody costs nothing
    // and can run long before anyone is comfortable paying automatically;
    // making the recognition wait on the billing switch would be the wrong
    // way round, because the recognition is the part that has never existed.
    $thanks_on = (bool) $settings->get('thanks_enabled');
    $awards_on = (bool) $settings->get('awards_enabled');
    if (!$thanks_on && !$awards_on) {
      return;
    }

    $customer_id = $event->customerId;
    if ($customer_id === NULL || $customer_id === '') {
      return;
    }

    $member = $this->userByChargebeeId($customer_id);
    if (!$member) {
      // Not our problem to solve here: chargebee_status_sync already logs
      // unknown customers, and adding a second warning would double the noise.
      return;
    }

    // The forward-only cutoff gates both halves. Thanking a member for a
    // referral from three years ago is as wrong as paying for it, just
    // cheaper.
    if (!$this->joinedAfterCutoff($member, (int) $settings->get('awards_start'))) {
      return;
    }

    $uid = (int) $member->id();

    if ($thanks_on && !$this->thanks->handled($uid)) {
      // Runs first and independently: a failure to pay must not cost somebody
      // their thank-you, and a switched-off credit must not silence it.
      $this->thanks->handle($uid);
    }

    if ($awards_on && !$this->awards->hasAward($uid)) {
      // recordAndQueue() writes first and pays later, and the unique key on
      // referred_uid is what makes a replayed webhook or a renewal harmless.
      $this->awards->recordAndQueue($uid);
    }
  }

  /**
   * Whether this member joined recently enough for their referral to count.
   *
   * This is how "let the past be the past" (JR, 2026-09-17) is enforced in
   * code. Chargebee sends payment_succeeded for every renewal, so without a
   * cutoff the next monthly payment of any long-standing member who once named
   * a referrer would mint a credit — and a thank-you — years late. The
   * historical backlog is a separate decision nobody has made yet.
   */
  private function joinedAfterCutoff(UserInterface $member, int $awards_start): bool {
    return $awards_start <= 0 || $member->getCreatedTime() >= $awards_start;
  }

  /**
   * Finds the Drupal account behind a Chargebee customer id.
   */
  private function userByChargebeeId(string $customer_id): ?UserInterface {
    $uids = $this->entities->getStorage('user')->getQuery()
      ->accessCheck(FALSE)
      ->condition('field_user_chargebee_id', $customer_id)
      ->range(0, 2)
      ->execute();

    if (count($uids) !== 1) {
      if (count($uids) > 1) {
        $this->log->warning('Chargebee customer @c maps to more than one account; refusing to guess who earned a referral.', ['@c' => $customer_id]);
      }
      return NULL;
    }

    $user = $this->entities->getStorage('user')->load(reset($uids));
    return $user instanceof UserInterface ? $user : NULL;
  }

}
