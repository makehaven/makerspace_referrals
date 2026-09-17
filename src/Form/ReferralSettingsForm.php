<?php

declare(strict_types=1);

namespace Drupal\makerspace_referrals\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\makerspace_referrals\Service\ReferralAward;

/**
 * Settings for the referral programme.
 */
class ReferralSettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'makerspace_referrals_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['makerspace_referrals.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('makerspace_referrals.settings');
    $amount = '$' . number_format(ReferralAward::AMOUNT_CENTS / 100, 2);

    $form['amount_note'] = [
      '#markup' => '<p>' . $this->t('The credit is a flat <strong>@amount</strong>, paid to the referring member on the referred member’s first successful charge. The amount is set in code rather than here, because changing what we pay people should be a deliberate release and not a text box.', [
        '@amount' => $amount,
      ]) . '</p>',
    ];

    $form['awards_start'] = [
      '#type' => 'date',
      '#title' => $this->t('Only pay for members who joined on or after'),
      '#default_value' => $config->get('awards_start')
        ? date('Y-m-d', (int) $config->get('awards_start'))
        : date('Y-m-d'),
      '#required' => TRUE,
      '#description' => $this->t('Set this before switching credits on. Without it, the next renewal of a member who joined years ago could earn a credit today. Historical referrals are a separate decision.'),
    ];

    $form['thanks_enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Thank the member who introduced someone'),
      '#default_value' => (bool) $config->get('thanks_enabled'),
      '#description' => $this->t('Emails the referring member when the person they introduced makes their first payment. Costs nothing and does not involve billing, so it can be on long before automatic credits are. Until now nobody who named a referrer was ever acknowledged at all.'),
    ];

    $form['staff_email'] = [
      '#type' => 'email',
      '#title' => $this->t('Tell this address about unconfirmed referrers'),
      '#default_value' => $config->get('staff_email'),
      '#description' => $this->t('When a new member names someone nobody has matched to an account, this address gets a note with a link to confirm it. Without it the review queue only grows — it held 545 unanswered names against 2 decisions on 2026-09-17.'),
    ];

    $form['awards_enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Pay referral credits automatically'),
      '#default_value' => (bool) $config->get('awards_enabled'),
      '#description' => $this->t('Off: invitations still send and referrals are still recorded, but nothing is paid. Turn this on once the credits page looks right to you.'),
    ];

    $form['join_url'] = [
      '#type' => 'url',
      '#title' => $this->t('Chargebee signup link for invited members'),
      '#default_value' => $config->get('join_url'),
      '#description' => $this->t('The hosted page the invitation email links to. Their name and email are added to it automatically.'),
    ];

    $form['invitee_coupon'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Coupon applied to the invited member'),
      '#default_value' => $config->get('invitee_coupon'),
      '#maxlength' => 64,
      '#description' => $this->t('Must match the coupon code in Chargebee exactly.'),
    ];

    $form['notify_email'] = [
      '#type' => 'email',
      '#title' => $this->t('Tell this address when a credit fails'),
      '#default_value' => $config->get('notify_email'),
      '#description' => $this->t('A credit we promised and did not pay should reach a person, not just the log.'),
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);

    // Refuse the combination that quietly pays nobody: switched on with no
    // coupon means the invited member is promised a discount they never get.
    if ($form_state->getValue('awards_enabled') && trim((string) $form_state->getValue('invitee_coupon')) === '') {
      $form_state->setErrorByName('invitee_coupon', $this->t('Set the coupon before switching credits on, or invited members are offered a discount that never applies.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $start = (string) $form_state->getValue('awards_start');
    $this->config('makerspace_referrals.settings')
      ->set('thanks_enabled', (bool) $form_state->getValue('thanks_enabled'))
      ->set('staff_email', trim((string) $form_state->getValue('staff_email')))
      ->set('awards_enabled', (bool) $form_state->getValue('awards_enabled'))
      ->set('awards_start', $start ? (int) strtotime($start . ' 00:00:00') : 0)
      ->set('join_url', trim((string) $form_state->getValue('join_url')))
      ->set('invitee_coupon', trim((string) $form_state->getValue('invitee_coupon')))
      ->set('notify_email', trim((string) $form_state->getValue('notify_email')))
      ->save();

    parent::submitForm($form, $form_state);
  }

}
