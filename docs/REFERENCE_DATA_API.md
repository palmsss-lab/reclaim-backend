# Staff people eligibility and corrections

People responses also include the server-derived boolean `is_account_holder`. It does not expose account IDs or credentials and cannot be set by clients. Staff may correct only walk-in people (`false`) through the existing PATCH/PUT endpoint, preserving `person_id` and all historical links. Account-holder edits return 403; owners use their existing profile endpoint. The React staff directory is `/staff/people`. Browser requests use the session-cookie and CSRF contract in [authentication setup](../README.md).
