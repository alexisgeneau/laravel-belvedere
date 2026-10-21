# Changelog

## Unreleased

- Event collector storing one row per job execution, with tags and a redacted snapshot.
- Live inspection of the `jobs`, `failed_jobs` and `job_batches` tables.
- Actions: retry, forget, flush, delete, run now, release, purge, pause, resume, cancel and retry batches.
- Per-minute metrics by queue and by job class.
- Worker heartbeats and dead worker detection.
- `lost` status for the executions whose outcome never arrives (worker killed, job removed from the queue table), reconciled by `belvedere:check` and set on the spot by the delete, purge and forget-worker actions; own tab under Completed Jobs and counter on the dashboard.
- Alerts for backlogs, wait times, failure rates and dead workers (mail, Slack).
- JSON API and console commands.
- Dashboard derived from Horizon's interface: overview, monitoring, metrics, batches, pending, running, completed and failed jobs, workers, light and dark schemes.
- `silenced` option: job classes kept out of the Completed Jobs list and shown under their own tab.
- `enabled` option (`BELVEDERE_ENABLED`) to switch the collector off, for test suites for instance.
- Workbench application (`composer serve`), benchmark (`composer bench`) and Playwright smoke test of the dashboard (`npm run test:ui`).
