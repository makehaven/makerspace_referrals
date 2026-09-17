<?php

declare(strict_types=1);

namespace Drupal\makerspace_referrals\EventSubscriber;

use Drupal\chargebee_status_sync\Event\ChargebeeWebhookEvent;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\makerspace_referrals\Service\ReferralAward;
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

    // Master switch, shipped off. Awards move real money, so turning them on
    // is a deliberate act and not a side effect of deploying the code.
    if (!$settings->get('awards_enabled')) {
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

    if (!$this->isEligible($member, (int) $settings->get('awards_start'))) {
      return;
    }

    // recordAndQueue() writes first and pays later, and the unique key on
    // referred_uid is what makes a replayed webhook or a renewal harmless.
    $this->awards->recordAndQueue((int) $member->id());
  }

  /**
   * Whether this member can still earn their referrer a credit.
   *
   * Two gates, both deliberate:
   *
   * 1. The account must have been created on or after `awards_start`. This is
   *    how "let the past be the past" (JR, 2026-09-17) is enforced in code —
   *    without it, the next renewal of any long-standing member who once named
   *    a referrer would mint a $50 credit years late. The historical backlog
   *    is a separate decision nobody has made yet.
   * 2. They must not already have an award. The unique key enforces that too,
   *    but checking here keeps renewals from doing pointless work every month
   *    for the rest of a member's life.
   */
  private function isEligible(UserInterface $member, int $awards_start): bool {
    if ($awards_start > 0 && $member->getCreatedTime() < $awards_start) {
      return FALSE;
    }
    return !$this->awards->hasAward((int) $member->id());
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
