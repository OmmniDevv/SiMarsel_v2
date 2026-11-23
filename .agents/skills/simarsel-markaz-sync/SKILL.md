---
name: simarsel-markaz-sync
description: Develop or review SiMarsel V2's Markaz ingestion system, including transaction and downloader sync, retry, reconciliation, backfill, snapshots, and related bugs. Do not use for routine dashboard UI work.
---

# Develop Markaz sync in SiMarsel V2

This skill is for building and fixing the sync system. It does not authorize running a production import or changing live Markaz data.

## Establish the current contract

Read `AGENTS.md`, the current ticket and parent, the latest approved Markaz design, `docs/transaction-dates-coverage.md`, relevant migrations and models, and the promo usage flow before editing. Check the current branch and local changes. Treat the approved ticket/design as the requirement and the code as the current state. Report any conflict explicitly instead of silently choosing one. Keep the implementation within the requested ticket.

The existing schema may lag the approved contract. In particular, verify transaction identity, nullable payment dates, statuses, and snapshot storage before implementing writes. Do not assume the current global `trx_id` unique key is the final Markaz identity.

## Build small, repeatable units

Use one platform, dataset, and date window as a retryable unit. Keep normal sync responsive while historical backfill runs at lower priority. Use Laravel services and jobs following the repository's existing patterns, and avoid loading the entire backfill into memory.

For normal transactions, request `/api/transaction` with `date_by=created` and without a status filter. Fetch every page in order with `per_page` no higher than the approved limit. Validate page metadata and records. A failed or missing page makes the unit partial. Saving validated pages incrementally is acceptable only when rerunning the unit cannot duplicate or corrupt data. Never mark a partial unit or snapshot complete.

Use the approved platform + Markaz transaction ID as the upsert identity. Preserve source status, effective status, latest Markaz payment timestamp, and the last valid report payment date as separate concepts. Numeric status `0` is a valid value. Reconcile later status and payment changes without dropping the historical promo classification. If a bulk upsert bypasses Eloquent `saved` events, invoke promo normalization explicitly.

Sync downloader data from `/api/user-daily` as its own unit, using the existing platform/date uniqueness rule. Distinguish a valid zero result from a failed request.

## Protect the API and server

Honor the approved request-per-minute limit across workers using the same API key. Make HTTP timeouts and retries finite. Follow `Retry-After` on 429. Do not retry bad parameters or authentication failures indefinitely. Record each unit's running, success, partial, or failed outcome in `sync_logs`, including safe counts and error context. Do not log tokens or customer personal data.

Create a complete dashboard snapshot only after all required input for its unit has succeeded. A later reconciliation updates current data and future snapshots, not an older complete snapshot.

## Verify before delivery

Add focused tests for multiple pages, a later-page failure, 429, malformed responses, repeated sync, the same Markaz ID on different platforms, status changes, missing payment dates, and promo usage after updates. Test downloader zero versus API failure. Use HTTP fakes for API behavior. SQLite in-memory tests are useful but cannot prove MySQL uniqueness, worker overlap, rate limiting, or memory capacity; test those separately in a safe integration environment when the ticket requires them.

Report the files changed, contract used, tests run, and any unverified capacity assumption. Do not claim the sync is production-ready from unit tests alone.
