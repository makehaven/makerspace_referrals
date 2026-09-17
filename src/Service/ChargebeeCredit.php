<?php

declare(strict_types=1);

namespace Drupal\makerspace_referrals\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use GuzzleHttp\ClientInterface;

/**
 * Adds promotional credit to a Chargebee customer.
 *
 * Deliberately the only place in this module that talks to Chargebee, and it
 * does exactly one thing. Credentials and site come from
 * `chargebee_portal.settings`, which already holds the live API key and portal
 * URL, rather than introducing a second place to configure the same account.
 */
class ChargebeeCredit {

  /**
   * Result of a credit call that succeeded.
   */
  public const OK = 'ok';

  /**
   * Result of a credit call that failed and is worth retrying.
   */
  public const RETRY = 'retry';

  /**
   * Result of a credit call that failed and will never succeed as sent.
   */
  public const PERMANENT = 'permanent';

  public function __construct(
    private readonly ClientInterface $http,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly LoggerChannelInterface $log,
  ) {}

  /**
   * Whether the Chargebee credentials needed to award anything are present.
   */
  public function isConfigured(): bool {
    return $this->apiKey() !== '' && $this->apiBase() !== '';
  }

  /**
   * Adds promotional credit to a customer.
   *
   * @param string $customer_id
   *   The Chargebee customer id.
   * @param int $amount_cents
   *   Credit in cents. Chargebee takes the smallest currency unit.
   * @param string $description
   *   Shown on the customer's Chargebee record. This is what a bookkeeper
   *   reads months later, so it should name the referral, not just say
   *   "credit".
   *
   * @return array
   *   Keys: outcome (one of the class constants), status (int|null),
   *   body (string), message (string).
   */
  public function addPromotionalCredit(string $customer_id, int $amount_cents, string $description): array {
    if ($customer_id === '') {
      return $this->result(self::PERMANENT, NULL, '', 'No Chargebee customer id for the referrer.');
    }
    if ($amount_cents <= 0) {
      return $this->result(self::PERMANENT, NULL, '', 'Refusing to send a non-positive credit.');
    }
    if (!$this->isConfigured()) {
      // Retryable on purpose: missing configuration is an operator problem
      // that gets fixed, and the award should still be waiting when it is.
      return $this->result(self::RETRY, NULL, '', 'Chargebee API key or site is not configured.');
    }

    $url = $this->apiBase() . '/customers/' . rawurlencode($customer_id) . '/add_promotional_credit';

    try {
      $response = $this->http->request('POST', $url, [
        'headers' => [
          'Content-Type' => 'application/x-www-form-urlencoded',
          'Authorization' => 'Basic ' . base64_encode($this->apiKey() . ':'),
        ],
        'form_params' => [
          'amount' => $amount_cents,
          'description' => $description,
          'credit_type' => 'promotional_credits',
        ],
        'timeout' => 20,
        'http_errors' => FALSE,
      ]);
    }
    catch (\Throwable $e) {
      // A transport failure says nothing about whether Chargebee acted, so
      // this is retryable and the unique key on referred_uid is what stops a
      // retry from paying twice.
      $this->log->error('Chargebee credit transport failure for @c: @e', [
        '@c' => $customer_id,
        '@e' => $e->getMessage(),
      ]);
      return $this->result(self::RETRY, NULL, '', $e->getMessage());
    }

    $status = $response->getStatusCode();
    $body = (string) $response->getBody();

    if ($status >= 200 && $status < 300) {
      return $this->result(self::OK, $status, $body, 'Credit applied.');
    }

    // 4xx other than 429 means the request itself is wrong — a bad customer
    // id, a rejected amount — and sending it again changes nothing. Retrying
    // those forever is how a queue turns into a log flood.
    $outcome = ($status >= 400 && $status < 500 && $status !== 429) ? self::PERMANENT : self::RETRY;

    $this->log->error('Chargebee credit failed for @c: HTTP @s @b', [
      '@c' => $customer_id,
      '@s' => $status,
      '@b' => mb_substr($body, 0, 400),
    ]);

    return $this->result($outcome, $status, $body, 'Chargebee returned HTTP ' . $status . '.');
  }

  /**
   * Builds the API base from the configured portal URL.
   *
   * The chargebee_portal module stores the full portal-sessions endpoint; the
   * site's API root is that minus the trailing path, which keeps the account
   * name in one place instead of hardcoding it here.
   */
  private function apiBase(): string {
    $portal = (string) $this->configFactory->get('chargebee_portal.settings')->get('live_portal_url');
    if ($portal === '') {
      return '';
    }
    $cut = strpos($portal, '/api/v2');
    if ($cut === FALSE) {
      return '';
    }
    return substr($portal, 0, $cut) . '/api/v2';
  }

  /**
   * Returns the live API key.
   */
  private function apiKey(): string {
    return trim((string) $this->configFactory->get('chargebee_portal.settings')->get('live_api_key'));
  }

  /**
   * Shapes a result array.
   */
  private function result(string $outcome, ?int $status, string $body, string $message): array {
    return [
      'outcome' => $outcome,
      'status' => $status,
      'body' => mb_substr($body, 0, 2000),
      'message' => $message,
    ];
  }

}
