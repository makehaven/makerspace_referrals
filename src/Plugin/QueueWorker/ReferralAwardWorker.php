<?php

declare(strict_types=1);

namespace Drupal\makerspace_referrals\Plugin\QueueWorker;

use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\makerspace_referrals\Service\ReferralAward;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Sends queued referral credits to Chargebee.
 *
 * Separate from the webhook on purpose: Chargebee is waiting on our response
 * when the webhook fires, so a slow or failing API call there turns a payment
 * notification into a timeout and a retry storm.
 *
 * @QueueWorker(
 *   id = "makerspace_referral_award",
 *   title = @Translation("Referral credit payments"),
 *   cron = {"time" = 30}
 * )
 */
class ReferralAwardWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    private readonly ReferralAward $awards,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static($configuration, $plugin_id, $plugin_definition, $container->get('makerspace_referrals.award'));
  }

  /**
   * {@inheritdoc}
   *
   * Consumes the item whatever happens. pay() records the outcome on the award
   * row, and a still-pending award is re-queued deliberately rather than by
   * throwing — an exception here would retry the same item forever without
   * anything recording why.
   */
  public function processItem($data): void {
    $award_id = (int) ($data['award_id'] ?? 0);
    if ($award_id <= 0) {
      return;
    }

    $status = $this->awards->pay($award_id);

    if ($status === 'pending') {
      // Retryable failure that has not exhausted its attempts. Put it back so
      // the next cron tries again.
      \Drupal::queue('makerspace_referral_award')->createItem(['award_id' => $award_id]);
    }
  }

}
