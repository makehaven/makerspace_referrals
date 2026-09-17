<?php

declare(strict_types=1);

namespace Drupal\makerspace_referrals\Form;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\makerspace_referrals\Service\ReferralAward;
use Drupal\makerspace_referrals\Service\ReferralInvite;
use Drupal\user\UserInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Lets a logged-in member invite someone and earn the referral credit.
 *
 * Modelled on the household invitation form (webform_24988) because that
 * shape is proven — 86 uses since 2024 — but built in code rather than cloned
 * as a webform, because this one has to write an attribution record and the
 * webform could not.
 *
 * The member is logged in, so we know exactly who they are and, through
 * field_user_chargebee_id, exactly who they are in Chargebee. That is what
 * removes the name-matching problem the old free-text referral question had.
 */
class ReferralInviteForm extends FormBase {

  public function __construct(
    private readonly AccountProxyInterface $currentUser,
    private readonly ReferralInvite $invites,
    private readonly EntityTypeManagerInterface $entities,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('current_user'),
      $container->get('makerspace_referrals.invite'),
      $container->get('entity_type.manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'makerspace_referrals_invite';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $amount = $this->formatAmount();

    $form['intro'] = [
      '#markup' => '<p>' . $this->t('Know someone who would like MakeHaven? Send them an invitation. When they join and their first payment goes through, you get a @amount credit on your account and they get a discount on theirs.', [
        '@amount' => $amount,
      ]) . '</p>',
    ];

    $form['first_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Their first name'),
      '#required' => TRUE,
      '#maxlength' => 128,
    ];
    $form['last_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Their last name'),
      '#required' => TRUE,
      '#maxlength' => 128,
    ];
    $form['email'] = [
      '#type' => 'email',
      '#title' => $this->t('Their email address'),
      '#required' => TRUE,
      '#description' => $this->t('We send the invitation here. Use the address they are likely to sign up with, so we can match their membership back to you.'),
    ];

    $form['attest'] = [
      '#type' => 'checkbox',
      '#required' => TRUE,
      '#title' => $this->t('I understand that this sends an invitation email to the person above, and that if they join and their first payment succeeds, a @amount credit will be applied to my account.', [
        '@amount' => $amount,
      ]),
    ];

    $form['household_note'] = [
      '#markup' => '<p><em>' . $this->t('Inviting someone in your own household? Use the household member invitation instead — a household signup gets the household rate, and does not also earn a referral credit.') . '</em></p>',
    ];

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Send invitation'),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $email = $this->invites->normalise((string) $form_state->getValue('email'));
    $account = $this->account();

    if ($account && $this->invites->normalise((string) $account->getEmail()) === $email) {
      $form_state->setErrorByName('email', $this->t('That is your own address. Invite someone else.'));
      return;
    }

    // An existing account is not automatically a member, but it does mean this
    // is not a new recruit, and paying a credit for one would be wrong.
    $existing = $this->entities->getStorage('user')->loadByProperties(['mail' => $email]);
    if ($existing) {
      $form_state->setErrorByName('email', $this->t('Someone already has an account with that address, so this would not be a new membership. If you think that is wrong, contact staff.'));
      return;
    }

    if ($this->invites->findOpenForEmail($email)) {
      $form_state->setErrorByName('email', $this->t('That address already has an open invitation. Only the first invitation earns the credit, so there is no need to send another.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $account = $this->account();
    if (!$account) {
      $this->messenger()->addError($this->t('We could not identify your account, so the invitation was not sent.'));
      return;
    }

    $this->invites->create(
      $account,
      (string) $form_state->getValue('email'),
      (string) $form_state->getValue('first_name'),
      (string) $form_state->getValue('last_name'),
    );

    $this->messenger()->addStatus($this->t('Invitation sent to @name. We will credit your account once they join and their first payment goes through.', [
      '@name' => trim($form_state->getValue('first_name') . ' ' . $form_state->getValue('last_name')),
    ]));
  }

  /**
   * The full user entity for the person filling the form.
   */
  private function account(): ?UserInterface {
    $user = $this->entities->getStorage('user')->load($this->currentUser->id());
    return $user instanceof UserInterface ? $user : NULL;
  }

  /**
   * The credit as a currency string, from the one place it is defined.
   */
  private function formatAmount(): string {
    return '$' . number_format(ReferralAward::AMOUNT_CENTS / 100, 2);
  }

}
