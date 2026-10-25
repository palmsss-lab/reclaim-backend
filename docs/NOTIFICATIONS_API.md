# Private in-app notifications

Notifications are an additive application extension, separate from the seven Reclaim domain tables. There is no email or push delivery, automatic matching, ownership decision, claim creation, or automatic report resolution.

## Authentication and privacy

Use the existing authentication contract. React uses Sanctum session cookies, `credentials: include`, the CSRF cookie and `X-XSRF-TOKEN` for PATCH/POST; it does not store tokens in browser storage. Existing non-browser Bearer clients remain supported with their existing abilities. Responses use `Cache-Control: private, no-store`.

Notification list/count/read endpoints require an active authenticated **user account**, including an account awaiting email verification. They scope every query/write to the authenticated account; staff accounts cannot use them. Existing report/claim destinations retain their verified-email and ownership guards. A notification identifier grants no access to another account's notice or record. Cross-user mark-read returns 404; absent authentication returns 401; staff access returns 403.

Messages are fixed server text. Neither requests nor responses include another person's claims, evidence, contact details, storage, recipient identity, reviewer identity, or internal event keys. React renders message text normally, never as HTML. Routes are built from allowlisted target types and numeric identifiers, never API-supplied URLs.

## Staff finder handover reminders

`POST /api/staff/finder-reports/{report}/request-handover` requires the existing active staff authorization/ability and `manageItems` permission. The route uses the existing `throttle:10,1` limit. Send `{ "request_id": "00000000-0000-4000-8000-000000000001" }`: a new UUID for an intentional reminder, the **same UUID** for retries of an uncertain request. Recipient IDs, message, status, item, and actor fields are prohibited. React retains the UUID in memory across uncertain failures; it does not automatically retry writes or store credentials.

Only a PENDING report with no received item is eligible. The transaction locks the finder report before checking status, recipient, previous request, and last reminder. The owner comes solely from that locked report. Existing account-owned notification storage, read/count APIs and safe own-report links are reused; no email or push is sent. No report, claim, inventory, or storage state is modified.

Success: `{ "message": "Handover reminder recorded.", "data": { "sent_at": "2026-10-25T11:00:00+08:00", "next_reminder_at": "2026-10-25T12:00:00+08:00", "deduplicated": false } }`. Dates are Asia/Manila with an offset. Retrying a recorded UUID returns success with `deduplicated: true` and preserves its original creation/read state, even after a later reminder. Eligibility is rechecked before replay, so closed, withdrawn or received reports return 409. A different UUID within one hour of the latest reminder returns 409 (`A handover reminder was already sent within the last hour.`); at exactly one hour a new reminder is allowed. A missing report returns 404; missing/invalid UUID or prohibited fields return 422; the route throttle returns 429. Staff should refresh after 409, never automatically resubmit.

Staff finder-report list/detail responses include nullable `last_handover_reminder_at`, a database-local Philippine timestamp without an offset. The frontend's existing formatter treats it as Asia/Manila. This field is derived from notification history, not a report status. Older once-only handover notices, if present, also count toward this time. Private message: “Staff have not received the item from your found-item report yet. Please bring it to the office for physical handover. Contact the office to arrange handover.” No address is invented. Staff must still use the separate physical-receipt/initial-storage workflow after actual handover.

## User endpoint contract

| Method | Path (under `/api`) | Contract |
| --- | --- | --- |
| GET | `/my/notifications?per_page=20&page=1` | Laravel paginator, newest creation first, descending notification ID on ties. `per_page` 1–100, `page` ≥1. |
| GET | `/my/notifications/unread-count` | `{ "unread_count": 2 }` |
| PATCH | `/my/notifications/{notification}/read` | `{ "data": notification }`; sets `read_at` only if previously unread. Repeating it preserves the original read time. |
| PATCH | `/my/notifications/read-all` | Marks the caller's unread notices read and returns the current `{ "unread_count": 0 }`. A concurrently committed new notice can make this count nonzero. |

Each list entry and single-read response contains only:

```json
{
  "notification_id": 17,
  "kind": "possible_match",
  "message": "Staff identified a possible match for your missing-item report. This does not confirm ownership or promise a return.",
  "target_type": "missing_report",
  "target_id": 12,
  "possible_item_id": 25,
  "created_at": "2026-10-24T11:00:00+08:00",
  "read_at": null
}
```

`possible_item_id` is nullable and appears in lists only while the possible item is currently published, stored, and unreturned. Single-read responses set it null; refresh the list for authoritative availability. Public item links still use the normal public API, which may return 404 after a later return/unpublication. Messages and own-record links remain available as history. All API notification dates include the Philippine `+08:00` offset; database DATETIME(6) values use Asia/Manila.

Targets: `missing_report` → own missing report, `finder_report` → own finder report, `claim` → own claim for `target_id` item. The browser links to `/my/missing-reports?report={id}`, `/my/finder-reports?report={id}`, or `/my/claims?item={id}`. Those pages retrieve the selected record through the existing owner-scoped show endpoint, including records outside the current list page/status tab.

Opening the bell loads recent notices without marking all read. Explicitly selecting a notification's record/item link marks that notice read before navigation; failed read requests retain the notice and show an error. **Mark all read** is explicit. The unread count refreshes on navigation, opening the bell, read actions, window focus, visible-tab return and page restoration. Focus/visibility events are debounced; there is no polling timer or mock fallback.

## Staff-triggered possible matches

`POST /api/staff/missing-reports/{report}/possible-matches`

```json
{ "item_id": 25 }
```

Requires the existing `manageItems` staff authorization/ability and an OPEN missing report. The item must currently have an active public listing, an open storage assignment and no RETURNED claim. It uses an item-first transaction lock, then the report and current locking reads of item state. The recipient comes exclusively from `missing_item_report.user_id`. Client `user_id`, `recipient_id`, `recipient_user_id`, `person_id`, `staff_id`, and `message` are prohibited (422); unknown fields are ignored and cannot set recipients. Invalid item IDs return 422, unknown reports 404, closed reports/unavailable items 409. Limited to 10 requests/minute under the existing staff route group.

Success and an identical retry return 200:

```json
{ "message": "Possible-match notification recorded." }
```

The staff missing-report detail screen offers **Notify possible match** only for OPEN reports. It selects from paginated real public listings. A return or report closure between selection and submission is rechecked on the server and rejected. A retry does not resend or reset the read state, even if the notification was read already.

## Existing workflow events

| Event | Notification kind | Recipient and retry identity |
| --- | --- | --- |
| Staff receives a finder report | `finder_received` | Report owner; report ID + RECEIVED |
| Staff closes a finder report | `finder_closed` | Report owner; report ID + CLOSED |
| Staff records a missing-report review | `missing_reviewed` | Report owner; one notice per reviewed report |
| Staff rejects a claim | `claim_rejected` | Account linked to that claim's person; item + person + outcome |
| Staff returns an item | `claim_returned` for selected claimant; `claim_rejected` for competing pending claimants | Each linked user account sees only its own outcome. Previously rejected claims receive no new notice. Walk-in people with no account receive none. |

Missing-report OPEN/RESOLVED/WITHDRAWN status changes remain **owner-only**; there is currently no staff status-change endpoint. A staff review preserves that status and sends a review notice. Owner resolution/withdrawal and owner edits do not generate staff-action notifications. This extension does not introduce new staff status powers.

Every workflow writes its notices inside the same existing database transaction, after the state mutation succeeds. A notification persistence failure rolls back the workflow; an outer rollback removes both. No queue/worker is needed. Unique `(user_id,event_key)` makes retries a no-op without overwriting original text, creation time or read state. Terminal workflow retries keep their existing 409 responses. Repeated missing reviews keep the existing review behavior but generate at most one notice.

## Setup and verification

Review/backup the intended target before applying the pending additive migration `2026_10_24_000001_create_in_app_notifications.php` using ordinary `php artisan migrate`. Do not reset the schema. The API and frontend must be released with this migration available; existing accounts begin with an empty notification history. Historical events are not backfilled. See [schema extension](SCHEMA_EXTENSIONS.md#private-in-app-notifications-2026-10-24).

From `reclaim-frontend`, the guarded runner starts its own MySQL 8.4 process with a fresh per-run data directory on loopback port 3307, verifies the actual engine/port/data directory, migrates only that disposable database, and isolates image/view storage and array mail. It refuses occupied service ports. It does not read live TiDB or modify development records:

```powershell
node scripts/verify-notifications-isolated.mjs --notifications-tests
node scripts/verify-notifications-isolated.mjs --notifications-tests --full-backend
npm test
npm run build
node scripts/verify-notifications.mjs
node scripts/verify-notifications-isolated.mjs
```

`verify-notifications.mjs` uses intercepted API-shaped browser fixtures and no database. The connected harness uses real Laravel sessions, normal delivered test-mail verification codes, disposable accounts/records and an actual Chromium browser; it also reruns the prior business-rule workflows. These MySQL checks do **not** establish TiDB runtime/concurrency compatibility. No development/production migration, email, push, deployment, commit or push is part of this task.

For separate checkouts, place `reclaim-frontend` and `reclaim-backend` beside each other, or set `RECLAIM_TEST_BACKEND` to the backend checkout path before running the isolated verifier. This selects source code only; the runner still overrides database, storage and mail settings and checks its disposable database identity. Publishing source does not apply the notification migration. TiDB runtime compatibility remains unverified.
