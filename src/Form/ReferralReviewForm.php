<?php

namespace Drupal\makerspace_referrals\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\makerspace_referrals\Service\ReferralReview;
use Drupal\profile\Entity\ProfileInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Staff-only attribution review; all writes use Drupal form CSRF protection.
 */
class ReferralReviewForm extends FormBase {

  public function __construct(protected ReferralReview $review, protected EntityTypeManagerInterface $entities) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static($container->get('makerspace_referrals.review'), $container->get('entity_type.manager'));
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'makerspace_referrals_review';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?ProfileInterface $profile = NULL): array {
    if (!$profile || $profile->bundle() !== 'main' || trim($this->review->source($profile)) === '') {
      throw new NotFoundHttpException();
    }
    $source = $this->review->source($profile);
    $latest = $this->review->latest((int) $profile->id());
    $form_state->set('profile_id', (int) $profile->id());
    $form['source_hash'] = [
      '#type' => 'hidden',
      '#default_value' => hash('sha256', $source),
    ];
    $form['decision_id'] = [
      '#type' => 'hidden',
      '#default_value' => (int) ($latest->id ?? 0),
    ];
    $form['person'] = [
      '#type' => 'item',
      '#title' => $this->t('Person referred'),
      'link' => $profile->getOwner()->toLink()->toRenderable(),
    ];
    $form['answer'] = ['#type' => 'item', '#title' => $this->t('Original answer'), 'text' => ['#plain_text' => $source]];
    $candidates = [];
    foreach ($this->review->candidates($profile) as $user) {
      $candidates[] = $user->toLink()->toRenderable();
    }
    $form['candidates'] = [
      '#theme' => 'item_list',
      '#title' => $this->t('Exact-name suggestions — verify before selecting'),
      '#items' => $candidates,
      '#empty' => $this->t('No exact first-and-last-name match. Use the account search below; leave unresolved if uncertain.'),
    ];
    $status = $this->review->status($profile, $latest);
    $form['status'] = [
      '#type' => 'radios',
      '#title' => $this->t('Review decision'),
      '#options' => [
        'pending' => $this->t('Needs further review'),
        'confirmed' => $this->t('Confirm the referrer account'),
        'external' => $this->t('External referral / not a member'),
      ],
      '#default_value' => $status,
      '#required' => TRUE,
    ];
    $form['referrer'] = [
      '#type' => 'entity_autocomplete',
      '#target_type' => 'user',
      '#title' => $this->t('Referrer account'),
      '#default_value' => $status === 'confirmed' ? $this->entities->getStorage('user')->load($latest->referrer_uid) : NULL,
      '#description' => $this->t('Select the person who referred this member. A name match alone is not proof. This records attribution only; credit eligibility and payment history require separate review.'),
    ];
    if ($latest) {
      $form['previous'] = [
        '#type' => 'item',
        '#title' => $this->t('Last recorded review'),
        '#plain_text' => $this->t('@status · reviewer account @reviewer · @date. Earlier decisions and original answers remain in the review history.', [
          '@status' => $latest->status,
          '@reviewer' => $latest->reviewer_uid,
          '@date' => date('Y-m-d H:i', $latest->created),
        ]),
      ];
    }
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = ['#type' => 'submit', '#value' => $this->t('Save review')];
    $form['#cache']['max-age'] = 0;
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    if ($form_state->getValue('status') === 'confirmed' && !$form_state->getValue('referrer')) {
      $form_state->setErrorByName('referrer', $this->t('Select a referrer account to confirm.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    try {
      $this->review->decide(
        $form_state->get('profile_id'),
        $form_state->getValue('source_hash'),
        (int) $form_state->getValue('decision_id'),
        $form_state->getValue('status'),
        (int) $form_state->getValue('referrer'),
        (int) $this->currentUser()->id(),
      );
    }
    catch (\RuntimeException $e) {
      $this->messenger()->addError($e->getMessage());
      $form_state->setRedirect('makerspace_referrals.review', ['profile' => $form_state->get('profile_id')]);
      return;
    }
    // Keep the queryable projection in step with the decision just made.
    // Views cannot call the review service, so a decision that does not reach
    // field_member_referral is invisible to the dashboard and to the member's
    // own card — it would look like nobody had reviewed anything.
    \Drupal::service('makerspace_referrals.projector')->project((int) $form_state->get('profile_id'));

    $this->messenger()->addStatus($this->t('Referral review saved.'));
    $form_state->setRedirect('makerspace_referrals.queue');
  }

}
