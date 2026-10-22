<?php

declare(strict_types=1);

namespace Drupal\makerspace_referrals\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\DestructableInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\profile\Entity\ProfileInterface;

/**
 * Tells staff the moment somebody names a referrer, so nobody has to go look.
 *
 * The thank-you path (ReferralThanks) only asks staff for help on the referred
 * member's first payment, and that webhook usually lands before the Chargebee
 * customer is linked to the account: of 44 members who joined after
 * 2026-09-24, four had been seen by it on 2026-10-22. Kate found Marcus Duff's
 * referral by checking the queue by hand (feedback sid 20834). This alert
 * hangs off the answer itself, which every joiner saves, so it does not
 * depend on billing at all.
 *
 * Deciding waits for the end of the request: the join form saves the typed
 * answer first and records the member's own pick from the list just after
 * (InterestPickerForm), and a referral that is already matched is not waiting
 * on anybody.
 */
class ReviewAlert implements DestructableInterface {

  /**
   * Profile IDs whose answer changed in this request.
   *
   * @var int[]
   */
  private array $changed = [];

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly EntityTypeManagerInterface $entities,
    private readonly MailManagerInterface $mail,
    private readonly ReferralReview $review,
    private readonly LoggerChannelInterface $log,
  ) {}

  /**
   * Notes a saved profile whose referral answer is new.
   *
   * @param \Drupal\profile\Entity\ProfileInterface $profile
   *   The saved profile.
   * @param string $old
   *   The answer before this save; empty on a new profile.
   */
  public function onSaved(ProfileInterface $profile, string $old): void {
    if ($profile->bundle() !== 'main') {
      return;
    }
    $answer = trim($this->review->source($profile));
    // Clearing an answer, or re-saving the same one, asks nobody for anything.
    if ($answer === '' || $answer === trim($old)) {
      return;
    }
    $this->changed[(int) $profile->id()] = (int) $profile->id();
  }

  /**
   * {@inheritdoc}
   */
  public function destruct(): void {
    $ids = $this->changed;
    $this->changed = [];
    foreach ($ids as $id) {
      try {
        $this->alert($id);
      }
      catch (\Throwable $e) {
        $this->log->error('Could not send the referral review alert for profile @p: @m', [
          '@p' => $id,
          '@m' => $e->getMessage(),
        ]);
      }
    }
  }

  /**
   * Emails the review address if this profile's answer still needs a person.
   *
   * @return bool
   *   Whether an alert was sent.
   */
  private function alert(int $profile_id): bool {
    $to = trim((string) $this->configFactory->get('makerspace_referrals.settings')->get('review_email'));
    if ($to === '') {
      return FALSE;
    }
    $profile = $this->entities->getStorage('profile')->load($profile_id);
    if (!$profile instanceof ProfileInterface) {
      return FALSE;
    }
    if ($this->review->status($profile, $this->review->latest($profile_id)) !== 'pending') {
      return FALSE;
    }
    $owner = $profile->getOwner();
    if (!$owner) {
      return FALSE;
    }
    $answer = trim($this->review->source($profile));

    $this->mail->mail(
      'makerspace_referrals',
      'review_needed',
      $to,
      $owner->getPreferredLangcode(),
      ['referred' => $owner, 'answer' => $answer],
    );
    $this->log->notice('Told @to that uid @u named "@a" as their referrer.', [
      '@to' => $to,
      '@u' => $owner->id(),
      '@a' => $answer,
    ]);
    return TRUE;
  }

}
