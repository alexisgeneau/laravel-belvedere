# Changelog

## Unreleased

- The README gives the throughput of the `jobs` table by number of workers, measured on MySQL, MariaDB and PostgreSQL, in place of a rule of thumb for `maxProcesses`; the wait-time alert and the *at max* badge of the dashboard say that the balancing can add no more, instead of blaming the database.

## 0.2.1 - 2026-10-22

- When every pool of a supervisor asks for one more process than it has, the last free slot goes to the queue with the most work; the pools that shrink are handled before the ones that grow.
- An application updated to 0.2.0 without running the new migration keeps working as in 0.1.0: the dashboard, the API, the collector, `belvedere:check`, `belvedere:prune` and `belvedere:clear` leave the supervision aside while its tables are missing, and `php artisan belvedere` says to run `belvedere:install` and `migrate` instead of failing on a query.

## 0.2.0 - 2026-10-22

Horizon's other half for the `database` driver: a supervisor. Run `php artisan belvedere:install` again, then `php artisan migrate`: 0.2.0 adds three tables and a column.

- `php artisan belvedere`: a master supervisor that starts the workers described under `supervisor.defaults` and `supervisor.environments` in the configuration (Horizon's keys), one supervisor process per entry, with plain `queue:work` workers named after their supervisor.
- Balancing between queues (`auto` by time to clear or by size, `simple`, `false`), with `minProcesses`, `maxProcesses`, `balanceMaxShift` and `balanceCooldown`; the load is read from the `jobs` table and Belvedere's metrics. On SQLite, every supervisor is capped to one process, on all its queues at once.
- Workers started again a second after they exit, with a minute of cooldown after a crash; supervisors started again by the master; the master exits with code 12 past `supervisor.memory_limit`.
- Signals as in Horizon (`SIGTERM`, `SIGUSR2`, `SIGCONT`, `SIGUSR1`) and the commands `belvedere:terminate`, `belvedere:pause`, `belvedere:continue`, `belvedere:pause-supervisor`, `belvedere:continue-supervisor`, `belvedere:supervisors` and `belvedere:orphans`; `belvedere:status` reports the supervision with an exit code.
- Tables `belvedere_masters`, `belvedere_supervisors` (heartbeats) and `belvedere_commands` (orders from the dashboard or another host); `supervisor` column on `belvedere_workers`, filled from Laravel's `WorkerStarting` event or the command line.
- Dashboard: a card per master with its supervisors, their processes per queue, balancing, range and pause buttons; the Workers screen shows each worker's supervisor and filters by it; the status reflects the supervision when there is one.
- API: `GET supervisors`, `POST supervisors/{master}/pause|continue`, `POST supervisors/{master}/{supervisor}/pause|continue`, `?supervisor=` on `GET workers`.
- Alerts: a master gone silent with no other master on its host, workers left behind by a dead supervisor, and the wait-time alert says when the supervisor of the queue is already at `maxProcesses`.
- `belvedere:prune` forgets the masters and supervisors not seen for `retention.workers_hours` and the orders nobody read; `belvedere:clear` empties the new tables too.
- `Belvedere::nameMasterUsing()` names the masters after something else than the hostname.
- `symfony/process` is now required; `pcntl` and `posix` are needed by `php artisan belvedere` only.

## 0.1.0 - 2026-10-22

First release, tested on MySQL 8.4 and 9.7, MariaDB 11.8, PostgreSQL 17 and SQLite with Laravel 12 and 13.

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
