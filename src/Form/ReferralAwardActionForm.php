<?php

declare(strict_types=1);

namespace Drupal\makerspace_referrals\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Url;
use Drupal\makerspace_referrals\Service\ReferralAward;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Lets staff retry a failed credit or record that one was reversed.
 *
 * Kate must be able to act without a developer. Both operations are confirmed
 * rather than one-click, because both concern money and neither is something
 * you want to do by mis-clicking a table row.
 */
class ReferralAwardActionForm extends FormBase {

  /**
   * The award being acted on.
   */
  private ?object $award = NULL;

  /**
   * Either 'retry' or 'reverse'.
   */
  private string $op = '';

  public function __construct(
    private readonly ReferralAward $awards,
    private readonly AccountProxyInterface $currentUser,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('makerspace_referrals.award'),
      $container->get('current_user'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'makerspace_referrals_award_action';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, $award = NULL, $op = NULL): array {
    $this->award = $this->awards->load((int) $award);
    $this->op = (string) $op;

    if (!$this->award) {
      $form['gone'] = ['#markup' => $this->t('That referral credit no longer exists.')];
      return $form;
    }

    $amount = '$' . number_format(((int) $this->award->amount_cents) / 100, 2);

    if ($this->op === 'retry') {
      $form['what'] = [
        '#markup' => '<p>' . $this->t('This will try to send the @amount credit to Chargebee again. It last failed with: <em>@why</em>', [
          '@amount' => $amount,
          '@why' => $this->award->last_error ?: $this->t('no reason recorded'),
        ]) . '</p>',
      ];
    }
    else {
      $form['what'] = [
        '#markup' => '<p>' . $this->t('This records that the @amount credit was reversed. <strong>It does not move any money</strong> — reverse the credit in Chargebee itself, where your bookkeeper can see it, then note it here so this page stays true.', [
          '@amount' => $amount,
        ]) . '</p>',
      ];
      $form['why'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Why was it reversed?'),
        '#required' => TRUE,
        '#maxlength' => 255,
        '#description' => $this->t('Whoever reads this row in six months needs to know what happened.'),
      ];
    }

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->op === 'retry' ? $this->t('Send it again') : $this->t('Record the reversal'),
    ];
    $form['actions']['cancel'] = [
      '#type' => 'link',
      '#title' => $this->t('Cancel'),
      '#url' => Url::fromRoute('makerspace_referrals.awards'),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    if (!$this->award) {
      return;
    }

    if ($this->op === 'retry') {
      if ($this->awards->retry((int) $this->award->id)) {
        $this->messenger()->addStatus($this->t('Queued to send again. It will go out on the next cron run, and this page will show the result.'));
      }
      else {
        $this->messenger()->addWarning($this->t('That credit is not in a failed state, so there was nothing to retry.'));
      }
    }
    else {
      $done = $this->awards->reverse(
        (int) $this->award->id,
        (int) $this->currentUser->id(),
        (string) $form_state->getValue('why'),
      );
      $this->messenger()->addStatus($done
        ? $this->t('Recorded as reversed.')
        : $this->t('That credit was already reversed.'));
    }

    $form_state->setRedirect('makerspace_referrals.awards');
  }

}
