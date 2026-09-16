# Makerspace Referrals

Staff review of member recruitment referral answers, with human-confirmed
account attribution and an append-only decision history. This is the first
review slice of the member-referral program, not automated rewards.

## Setup

Requires Drupal 10/11, Profile, a `main` profile type and its existing string
field `field_member_referring`. Optional user fields `field_first_name` and
`field_last_name` enable exact first/last-name suggestions. Without those name
fields, account autocomplete remains available. No names are auto-confirmed;
former/blocked accounts can be valid historical referrers. Account attribution
does not establish membership at the referral date or reward eligibility.

Enable `makerspace_referrals` and grant **Review member recruitment referrals**
only to appropriate staff. Visit People → Member recruitment referrals at
`/admin/people/referrals`, or Staff Tools → Membership → Queues & actions.

**Both menu links carry the number still awaiting review**, via
`ReferralReview::pendingCount()` and the `ReferralQueueMenuLink` plugin. That
number does not reach the Staff Tools *page*, which renders its own curated
titles from `makerspace_staff_tools`' `data/staff_tools.inventory.yml` rather
than the menu link's title — and note that adding a `staff-tools` menu link is
not enough on its own: a link that is not also filed in that inventory is
invisible on `/staff-tools`. Both are done for this module; keep them together
if the route ever moves. The list includes all nonempty answers on main profiles,
newest first, regardless of the discovery category. It is paginated, 25 per page.

Review an answer, confirm an account, mark an external/non-member referral, or
leave it pending. Self-referrals and nonexistent accounts cannot be confirmed.
The original profile answer is never overwritten. Changed source answers return
to pending; reviews of deleted referrer accounts also return to pending. Staff
can reopen/correct an attribution. A database lock serializes staff decisions;
source hashes and prior decision IDs reject stale forms. Decisions record the
source text, chosen account, reviewer and time; repeated identical saves are
idempotent. Route permissions protect all pages; Form API protects write requests.

## Data boundary

`makerspace_referral_review` stores review evidence. It does not yet populate the
legacy `field_member_referral`, create CiviCRM Referral activities, or change the
existing dashboard KPI. Those integrations should consume confirmed, current
reviews in a later slice with explicit identity mapping and retry protection.
No automatic matching/backfill, emails, cron, billing calls, credits or
reward-status assumptions are included. There is exactly one profile hook,
`makerspace_referrals_profile_presave()`, and it **writes nothing** — it
invalidates the two menu cache tags when the referral answer itself changes, so
the pending count in the menu link title does not go stale. Every other profile
edit is ignored. Historical names are visible
without a migration; a stored name is not evidence that a credit remains unpaid.

Uninstalling the module drops its review table via Drupal's normal schema cleanup.
Export required history before uninstalling. Profile source answers are retained.

## Validation

Kernel tests exercise provenance, exact-name suggestions, duplicate decisions,
source changes, concurrent reviews, reopening and self-referral rejection.
Functional tests exercise anonymous/member denial, staff listing, escaped source
text, actual confirmation/reopening and stale source protection through the form.
Use a MariaDB test database with Drupal's test-prefix isolation.

## Confirmed account link

The list displays a clickable **Linked referrer** account after confirmation.
The stored `referrer_uid` is the Drupal account ID, not a name string. Consumers
can call `makerspace_referrals.review->confirmedReferrer($profile_id)` to get
the current confirmed user entity, or NULL for pending/external/changed answers,
missing profiles or deleted referrers. This reloads the profile before checking
its source against the review. Reward code can use the returned account's ID to
resolve its billing customer; it must independently check eligibility, successful
payment and an idempotent reward ledger. No credit is issued by this lookup.
