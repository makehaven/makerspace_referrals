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
`/admin/people/referrals`. The list includes all nonempty answers on main profiles,
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
No profile-save hooks, automatic matching/backfill, emails, cron, billing calls,
credits or reward-status assumptions are included. Historical names are visible
without a migration; a stored name is not evidence that a credit remains unpaid.

Uninstalling the module drops its review table via Drupal's normal schema cleanup.
Export required history before uninstalling. Profile source answers are retained.

## Validation

Kernel tests exercise provenance, exact-name suggestions, duplicate decisions,
source changes, concurrent reviews, reopening and self-referral rejection.
Functional tests exercise anonymous/member denial, staff listing, escaped source
text, actual confirmation/reopening and stale source protection through the form.
Use a MariaDB test database with Drupal's test-prefix isolation.
