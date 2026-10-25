# User report API notes

Reclaim user-entered found-item found_at and missing-item lost_at times represent Philippine local time (Asia/Manila). Browser clients should send an explicit offset, for example 2026-10-25T09:30:00+08:00, so the intended event time is independent of the client device timezone. The API keeps the existing Manila-local storage format.

A 409 Conflict from a finder-report withdrawal, missing-report update or status action, or report-image mutation means the requested change was not applied. Do not replay the mutation automatically. Fetch the caller's own report through GET /api/my/finder-reports/{report} or GET /api/my/missing-reports/{report} and refresh its image list before displaying available actions.

A received finder report's received_item_id is a reference only. It is not an owner-safe item detail URL. Public item details are state-dependent, so clients must not construct a clickable item link from this ID.
