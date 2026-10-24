# Application schema extensions

## Private in-app notifications (2026-10-24)

Additive migration `2026_10_24_000001_create_in_app_notifications.php` creates `in_app_notification` **outside the original seven domain tables**. No domain columns, keys, checks, history or foreign keys are changed.

The table contains an auto-increment unsigned BIGINT `notification_id`, account FK `user_id`, server-only event key, fixed kind/message, own-record target type/ID, optional public possible-item reference, nullable `read_at`, and `created_at` (both DATETIME(6), Philippine local time). Targets are historical application references, not polymorphic domain foreign keys. Responses explicitly select safe fields and recheck public visibility before exposing a possible-item reference.

Unique `(user_id,event_key)` prevents duplicate workflow/retry notices and preserves existing read/creation times. Indexes `(user_id,created_at,notification_id)` and `(user_id,read_at,notification_id)` support recent lists and unread operations. The account FK uses CASCADE DELETE for disposable account metadata and RESTRICT UPDATE; this is not a cascading domain-history relationship. Existing account deletion restrictions from other extensions remain in force.

Notices commit/roll back with their originating staff transaction. No mail, queue, push, automatic claim, ownership decision or historical backfill is added. Staff missing-report review still leaves status unchanged; resolve/withdraw remain owner-only. See [API, privacy, setup and tests](NOTIFICATIONS_API.md). This migration is applied only to task-owned disposable MySQL databases during implementation; deployment/development application remains pending. TiDB runtime testing is separate and must not be inferred from MySQL results.
