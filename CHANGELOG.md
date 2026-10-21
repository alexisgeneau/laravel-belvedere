# Changelog

## Unreleased

- Event collector storing one row per job execution, with tags and a redacted snapshot.
- Live inspection of the `jobs`, `failed_jobs` and `job_batches` tables.
- Actions: retry, forget, flush, delete, run now, release, purge, pause, resume, cancel and retry batches.
- Per-minute metrics by queue and by job class.
- Worker heartbeats and dead worker detection.
- Alerts for backlogs, failure rates and dead workers (mail, Slack).
- JSON API and console commands.
- Dashboard derived from Horizon's interface: overview, monitoring, metrics, batches, pending, running, completed and failed jobs, workers, light and dark schemes.
- `enabled` option (`BELVEDERE_ENABLED`) to switch the collector off, for test suites for instance.
- Workbench application (`composer serve`) and benchmark (`composer bench`).
