# Reclaim backend

Private in-app notifications are an additive application extension. See [the API, privacy rules, setup and isolated tests](docs/NOTIFICATIONS_API.md) and [notification schema details](docs/SCHEMA_EXTENSIONS.md).

The notification migration is pending on development/production. Publishing this code does not apply it. Review and back up the intended target before applying the ordinary additive migration; do not reset the original seven domain tables. No email/push notification worker is required.
