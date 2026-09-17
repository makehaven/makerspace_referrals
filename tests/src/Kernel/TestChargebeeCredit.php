<?php

declare(strict_types=1);

namespace Drupal\Tests\makerspace_referrals\Kernel;

use Drupal\makerspace_referrals\Service\ChargebeeCredit;

/**
 * A Chargebee client that records calls instead of making them.
 */
class TestChargebeeCredit extends ChargebeeCredit {

  /**
   * How many credit calls were made.
   */
  public int $calls = 0;

  /**
   * The customer id of the last call.
   */
  public ?string $lastCustomer = NULL;

  /**
   * The outcome to return.
   */
  public string $outcome = ChargebeeCredit::OK;

  /**
   * Constructs the stub without the real collaborators.
   */
  public function __construct() {}

  /**
   * {@inheritdoc}
   */
  public function isConfigured(): bool {
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function addPromotionalCredit(string $customer_id, int $amount_cents, string $description): array {
    // Mirror the real guard: no customer id is a permanent failure, and it is
    // the one that would otherwise send money nowhere.
    if ($customer_id === '') {
      return [
        'outcome' => ChargebeeCredit::PERMANENT,
        'status' => NULL,
        'body' => '',
        'message' => 'No Chargebee customer id for the referrer.',
      ];
    }
    $this->calls++;
    $this->lastCustomer = $customer_id;
    return [
      'outcome' => $this->outcome,
      'status' => $this->outcome === ChargebeeCredit::OK ? 200 : 500,
      'body' => '{"stub":true}',
      'message' => $this->outcome === ChargebeeCredit::OK ? 'Credit applied.' : 'Stubbed failure.',
    ];
  }

}
