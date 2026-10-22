# Belvedere

Horizon-style queue dashboard for Laravel's `database` queue driver.

Horizon only works with Redis. Belvedere gives applications that run on the `database` driver (Laravel's default since version 11) the same visibility and the same actions: pending, running, completed and failed jobs, batches, workers, per-queue metrics, retries, purges and queue pausing. Since 0.2.0 it also comes with Horizon's other half, a supervisor (`php artisan belvedere`) that starts the workers, balances them between queues, restarts them and stops them cleanly. No Redis, no Node, no third-party service.

![Belvedere dashboard](art/dashboard.png)

> **Status:** 0.2.1, complete and tested on MySQL, MariaDB, PostgreSQL and SQLite. Until 1.0, a minor version may still change a configuration key, an API response or a table; the changelog says so when it happens.

## Requirements

- PHP 8.2+
- Laravel 12 or 13 (Laravel 11 no longer receives security fixes and Composer refuses to install it by default)
- MySQL 8.0 or newer (tested on 8.4 LTS and 9.7 LTS), MariaDB (tested on 11.8), PostgreSQL (tested on 17) or SQLite
- For `php artisan belvedere` only: Linux or macOS with the `pcntl` and `posix` extensions (the dashboard and the collector need neither)

## Installation

```bash
composer require alexisgeneau/laravel-belvedere
php artisan belvedere:install
php artisan migrate
```

The dashboard is served at `/belvedere` with its assets inlined, so there is nothing to publish and nothing to refresh after an update. Outside the `local` environment, access is denied until you define the `viewBelvedere` gate:

```php
use Illuminate\Support\Facades\Gate;

Gate::define('viewBelvedere', fn ($user) => in_array($user->email, ['you@example.com']));
```

or register a callback:

```php
use Belvedere\Belvedere;

Belvedere::auth(fn ($request) => $request->user()?->isAdmin() ?? false);
```

Add the two commands to your scheduler:

```php
Schedule::command('belvedere:prune')->daily();
Schedule::command('belvedere:check')->everyMinute();
```

Then either keep starting your workers the way you do (`queue:work` under Supervisor, systemd or Docker: Belvedere observes them as they are) or let `php artisan belvedere` start and watch them for you, as described under [Supervisor](#supervisor).

Upgrading from 0.1.0: run `php artisan belvedere:install` again (it publishes the migration 0.2.0 adds and leaves the rest alone), then `php artisan migrate`. Until the migration has run, everything works as in 0.1.0 and the supervision stays off.

## Dashboard

The interface is a fork of Horizon's, with the same layout and the same screens so nobody has to relearn anything: Dashboard (throughput, backlog, workers, per-queue workload with pause, resume and purge, and a card per supervisor with its pools and their pause buttons), Monitoring (tags), Metrics (throughput, runtime and wait time charts by job or by queue over 1 hour, 24 hours or 7 days), Batches, Pending Jobs (pending and delayed), Running Jobs (running and stuck), Completed Jobs (with silenced, released, timed-out and lost attempts), Failed Jobs (search, retry, retry all, flush) and Workers (with their supervisor, and a filter by supervisor).

Every list refreshes itself (`refresh_seconds`), follows the system color scheme with a light/dark toggle, and can switch between observed connections. With `read_only` enabled, every action disappears from the interface and the API rejects it.

If the application sends a `Content-Security-Policy` header, call `Vite::useCspNonce()` in a middleware: Belvedere reuses that nonce on its inline style and script tags.

![Metrics of a queue](art/metrics.png)

![Dashboard in dark mode](art/dashboard-dark.png)

## How it works

Belvedere listens to the queue events Laravel already dispatches (`JobQueued`, `JobProcessing`, `JobProcessed`, `JobFailed`, `JobReleasedAfterException`, `JobTimedOut`, `Looping`, `WorkerStarting`, `WorkerStopping`, `QueueBusy`) and stores one row per job execution in its own tables (`belvedere_jobs`, `belvedere_job_tags`, `belvedere_workers`, `belvedere_metrics`, `belvedere_actions`, `belvedere_monitored_tags`; the supervisor adds `belvedere_masters`, `belvedere_supervisors` and `belvedere_commands`). Live state (pending, delayed, running, stuck) is read straight from Laravel's `jobs`, `failed_jobs` and `job_batches` tables, which are never modified except through the actions you trigger.

Tags follow Horizon's convention: a `tags()` method on the job, otherwise the Eloquent models found in its public properties. Tags, the batch id and a redacted snapshot of the job's public properties are computed when Laravel builds the payload (`Queue::createPayloadUsing`) and stored under its `belvedere` key, so they travel with the job through bulk pushes, batches and `queue:retry`. Jobs implementing `ShouldBeEncrypted` get tags but no snapshot. Payloads are never unserialized in the web process.

A failure to record (database down, lock timeout, deadlock) is reported through the application's exception handler and never reaches the worker: the job runs and finishes as if Belvedere were not there, and the execution is later marked lost if its outcome was never stored.

### Cost

Each job costs four statements in three commits: one `INSERT` for the execution and one for its tags when the job is queued, in the same transaction, then one `UPDATE` when it starts and one when it finishes. Metrics are accumulated in memory by each worker and flushed at most every 5 seconds, so a worker killed with `SIGKILL` loses at most a few seconds of them; heartbeats are written every few seconds, not per job.

`composer bench` measures the overhead against a run with the collector disabled. On a laptop running the servers in Docker, with 2,000 jobs and PHP 8.4:

| | Dispatch (p50) | Processing, per job |
| --- | --- | --- |
| MySQL 8.4 | 0.7 ms → 2.1 ms | 2.8 ms → 4.7 ms |
| MariaDB 11.8 | 0.2 ms → 0.7 ms | 1.1 ms → 1.8 ms |
| PostgreSQL 17 | 0.2 ms → 0.8 ms | 1.4 ms → 2.8 ms |
| SQLite 3.46 | 1.0 ms → 3.2 ms | 2.9 ms → 5.9 ms |

Each statement costs about what a commit costs on the server: MySQL 8.4 syncs its binary log as well as its redo log on every commit, and SQLite syncs its journal and its file on every statement outside a transaction. Figures move by about a third from one run to the next. Use `sampling`, disable snapshots or point `storage.connection` to a dedicated database to lower the cost further. On SQLite, setting `journal_mode` to `wal` and `synchronous` to `normal` on the connection makes every statement several times cheaper, Laravel's own queue table included.

With a million rows of history, every endpoint of the API answers in under 50 ms on the four databases (the slowest draws the 7-day metrics charts), and `belvedere:prune` deletes a million rows in 4 seconds on PostgreSQL and in about two minutes on MySQL, MariaDB and SQLite — in production it only ever has one day's worth to delete.

### Lost executions

An execution whose outcome never arrives is marked **lost** instead of staying "running" or "queued" forever. `belvedere:check` does it every minute: a running execution is lost once its worker is reported dead or stopped, once a later attempt of the same job exists, or once it is older than the connection's `retry_after` (the queue has released the job by then); a queued execution is lost once its job has left the `jobs` table without being processed, which only happens when the queue is cleared or the job deleted outside the dashboard. Deleting or purging jobs and forgetting a worker from the dashboard mark the matching executions on the spot. The reason is stored where the exception would be, lost executions have their own tab under Completed Jobs and a counter on the dashboard, and they follow the `completed` retention. A worker that reports late overrides the verdict, and a queued execution is revived if its job runs after all.

## Configuration

`config/belvedere.php`:

| Key | Default | Purpose |
| --- | --- | --- |
| `enabled` | `true` | Collect queue events and payload metadata (the dashboard itself stays available) |
| `path` | `belvedere` | URI prefix of the dashboard and its API |
| `middleware` | `['web']` | Middleware applied before the authorization check |
| `read_only` | `false` | Disable every action while keeping the dashboard readable |
| `connections` | `['database']` | Queue connections to observe |
| `storage.connection` | `null` | Database connection for Belvedere's tables (`null` = default) |
| `storage.prefix` | `belvedere_` | Table prefix |
| `sampling` | `1` | Record one job out of *n* (metrics stay exact for sampled jobs only) |
| `snapshot.*` | enabled, 16 KB, common secret keys | Snapshot of the job's public properties |
| `silenced` | `[]` | Job classes kept out of the Completed Jobs list, shown under a Silenced tab instead (still recorded and counted) |
| `retention.*` | 7 days completed (released, timed-out and lost included), 30 days failed | Retention applied by `belvedere:prune` |
| `workers.dead_after_seconds` | `90` | A worker without heartbeat for this long is reported dead |
| `supervisor.defaults`, `supervisor.environments` | one supervisor on `default` | The workers `php artisan belvedere` runs, per environment (see [Supervisor](#supervisor)) |
| `supervisor.memory_limit` | `64` | Memory (MB) past which the master supervisor exits for the process monitor to restart it |
| `supervisor.heartbeat_seconds`, `supervisor.inactive_after_seconds` | `5`, `30` | How often masters and supervisors report, and when a silent one counts as inactive |
| `alerts.*` | mail / Slack routes, thresholds, cooldown | Alerts evaluated by `belvedere:check` (see below) |

## Alerts

`belvedere:check` sends a notification to the configured `alerts.routes` (mail, Slack) when a queue holds more than `queue_size` jobs, when the oldest job ready to run on a queue has waited longer than `wait_seconds` (`wait_seconds_per_queue` overrides it per `connection:queue`, `0` exempts a queue; paused queues are never reported), when the failure rate of a queue over `window_minutes` exceeds `failure_rate_percent` (once at least `failure_rate_minimum_jobs` were processed) and when a worker stops reporting. With `alerts.supervisor` on, it also reports a master supervisor gone silent with no other master alive on its host, and the workers a killed supervisor left behind; the wait-time alert adds a line when the supervisor of that queue is already at `maxProcesses`, which means the limit is elsewhere. Each alert is sent once per `cooldown_minutes`.

## Queue pausing

Pausing a queue sets a flag in the cache. Every `queue:work` process checks it through the `Looping` hook and sleeps while **all** the queues it consumes are paused. A worker started with `--queue=high,default` keeps running while only `high` is paused.

## Supervisor

`php artisan belvedere` is to the `database` driver what `php artisan horizon` is to Redis: a master process that starts the workers described in the configuration, balances them between queues, replaces them when they exit, and stops them without losing a job. The workers are plain `queue:work` processes, only named after their supervisor (`--name=web-1-k3f9:supervisor-1`), so they appear on the dashboard like any other, each under its supervisor, and the collector, the alerts and the API need nothing more. The command is optional: without it, Belvedere keeps observing the workers you start yourself.

### Configuration

The `supervisor` section of `config/belvedere.php` takes Horizon's keys:

```php
'supervisor' => [
    'defaults' => [
        'supervisor-1' => [
            'connection' => 'database',
            'queue' => ['default'],
            'balance' => 'auto',             // auto, simple or false
            'autoScalingStrategy' => 'time', // time or size
            'minProcesses' => 1,
            'maxProcesses' => 1,
            'balanceMaxShift' => 1,
            'balanceCooldown' => 3,
            'maxTime' => 0,
            'maxJobs' => 0,
            'memory' => 128,
            'tries' => 1,
            'timeout' => 60,
            'nice' => 0,
        ],
    ],

    'environments' => [
        'production' => [
            'supervisor-1' => ['maxProcesses' => 6],
        ],
        'local' => [
            'supervisor-1' => ['maxProcesses' => 2],
        ],
    ],
],
```

`defaults` are merged under the supervisor of the same name in every environment. The environment is `APP_ENV` (or `--environment=`), matched exactly first, then with Horizon's wildcards (`production-*`, `*`) in the order of the file. `backoff`, `sleep`, `rest` and `force` are accepted too, and `processes` and `max_tries` as Horizon's older spellings of `maxProcesses` and `tries`. A supervisor whose `maxProcesses` is 0 stays configured without running. The connection must use the `database` driver, since the load the supervisors balance on is read from the `jobs` table. The configuration is checked when the master starts, and a mistake is refused with its key: `belvedere.supervisor.environments.production.supervisor-1.maxProcesses must be at least 2: minProcesses (1) for each of the 2 queues.`

### Balancing

With `balance` set to `auto`, a supervisor runs one pool of workers per queue and gives each pool a share of `maxProcesses` proportional to the time its queue needs to clear (`time`: jobs ready × their average runtime over the last ten minutes, from Belvedere's metrics) or to its number of jobs (`size`); when every queue is empty each pool comes back to `minProcesses`, and while no runtime is known yet the queues that hold jobs get the maximum. `simple` splits `maxProcesses` evenly between the queues. `false` runs a single pool on `--queue=a,b` in that order, sized between `minProcesses` and `maxProcesses` by the number of jobs ready (Horizon runs `maxProcesses` flat there). A cycle runs every `balanceCooldown` seconds and never moves more than `balanceMaxShift` processes per pool, serving the busiest queue first when several want to grow; a pool that shrinks lets its leaving workers finish their current job and keeps counting them until they exit, so the pools never oscillate. A cycle costs one `COUNT` query on the `jobs` table per supervisor, plus one on `belvedere_metrics` every minute with the `time` strategy: a `balanceCooldown` of 10 seconds is plenty for most applications, and advised on SQLite and on small instances.

How far `maxProcesses` is worth raising depends on the database more than on the CPU: the throughput of a `database` queue is bounded by the locks on the `jobs` table well before the machine runs out of cores, so past a few workers per database, more processes no longer process more jobs. Start with two to four per supervisor, raise it while the throughput still follows, and watch the dashboard: a supervisor shown *at max* while the wait time of its queue keeps growing means the limit is the database, not the number of workers. On SQLite, one process at a time can pop from the queue, so the master runs every supervisor with a single process and says so when it starts.

### Lifecycle

A worker that exits (`--max-time`, `--max-jobs`, `--memory`, `queue:restart`, a crash) is started again a second later; one that crashes within a minute of its launch waits a minute before the next attempt, so a broken deployment does not spin. A supervisor process that dies is started again by the master, unless it exited with code 13 because another supervisor of its name is alive. The master exits with code 12 when it outgrows `memory_limit`, for the process monitor to start it again, and refuses to start while another master is alive on the machine. Masters are named after the machine's hostname and four random characters (`web-1-k3f9`); `Belvedere::nameMasterUsing(fn () => 'worker-a')` replaces the hostname where it is not stable. Every master and supervisor writes a heartbeat every `heartbeat_seconds` into `belvedere_masters` and `belvedere_supervisors` (name, host, pid, status, processes per queue); one silent for `inactive_after_seconds` shows as inactive.

Signals and commands follow Horizon's contract: no running job is ever interrupted by an order you give. Sent to the master, `SIGTERM` (or `belvedere:terminate`, or Ctrl-C in the foreground) tells every worker to finish its current job and exit, waits at most the longest `timeout` of the supervisors, then exits with code 0; `SIGUSR2` (`belvedere:pause`) puts the workers to sleep without exiting and stops the balancing, `SIGCONT` (`belvedere:continue`) wakes them, and `SIGUSR1` makes every supervisor finish its jobs, exit and come back with fresh workers, so a deployment loads the new code without stopping the master. `belvedere:terminate`, `belvedere:pause` and `belvedere:continue` act on the masters of the machine they run on, like Horizon's, and `belvedere:terminate` also sets the `queue:restart` flag so the workers started by hand restart too; `belvedere:pause-supervisor <name>` and `belvedere:continue-supervisor <name>` target one supervisor of the machine by its configuration key. From the dashboard, or from another host, the same orders go through a row in `belvedere_commands` that the target reads at its next heartbeat. While a worker is paused it no longer loops, so its supervisor reports for it: paused workers stay listed as paused, not dead.

`belvedere:orphans` lists the workers of the machine that carry a supervisor's name but that no live supervisor started (after a `kill -9`, for instance), and `--kill` stops them; `belvedere:check` reports them as well. `belvedere:supervisors` prints every master and supervisor with their processes, and `belvedere:status` summarises the supervision with an exit code for scripts: 0 running, 1 paused, 2 inactive.

### Deployment

Run `php artisan belvedere` in the foreground under a process monitor, exactly like `horizon`. With Supervisor:

```ini
[program:belvedere]
command=php /var/www/app/artisan belvedere
user=www-data
autostart=true
autorestart=true
stopwaitsecs=3600
```

With systemd, a service with `Restart=always` and `TimeoutStopSec=` longer than your longest job; with Docker, `stop_grace_period` longer than the longest `timeout`, and the command as PID 1 so that `docker stop` reaches it. `stopwaitsecs` matters: on stop, the master waits for the jobs in progress before exiting. To deploy, run `php artisan belvedere:terminate` after the new code is in place and let the monitor start the new master, as you would with `horizon:terminate`.

The master starts its supervisors, and the supervisors their workers, with the PHP binary running the master; set `BELVEDERE_PHP` to use another one (`BELVEDERE_PHP=/usr/bin/php8.4`).

## Other queue drivers

On `redis`, `sqs` or `beanstalkd`, Belvedere still records history, metrics, failures and workers from the events, but the live lists come from the database driver only: the dashboard shows the queue size and marks the connection as degraded.

## Retrying failed jobs

A retry pushes the stored payload back with its attempts reset, exactly like `queue:retry`, and keeps the job UUID so batches stay consistent. Because the payload is not unserialized, a `retryUntil` deadline is dropped: the retried job then relies on `tries`.

## Commands

| Command | Purpose |
| --- | --- |
| `belvedere:install` | Publish the configuration and the migrations |
| `belvedere:status` | Print every queue, the worker count and the supervision in the console; exit code 0 running, 1 paused, 2 inactive |
| `belvedere:check` | Mark lost executions, then evaluate backlogs, wait times, failure rates, dead workers and the supervisors, and send alerts |
| `belvedere:prune` | Apply the retention periods |
| `belvedere:clear` | Delete everything Belvedere stored |
| `belvedere` | Start the master supervisor in the foreground (`--environment=` to pick another environment than `APP_ENV`) |
| `belvedere:terminate` | Stop the masters of this machine once their jobs are done, and restart the workers started by hand |
| `belvedere:pause` · `belvedere:continue` | Pause or resume every worker of this machine's masters |
| `belvedere:pause-supervisor <name>` · `belvedere:continue-supervisor <name>` | The same for one supervisor, by its configuration key |
| `belvedere:supervisors` | List the masters and supervisors with their processes per queue |
| `belvedere:orphans` | List the workers of this machine that no live supervisor started; `--kill` stops them (`--signal=` to choose the signal) |

## JSON API

Every endpoint lives under `/belvedere/api` and accepts `?connection=` when several connections are observed.

| Method | Path | Purpose |
| --- | --- | --- |
| GET | `overview` | Counts, throughput, wait and run times per queue, worker summary |
| GET | `queues` | Per-queue counts and pause state |
| POST | `queues/{connection}/{queue}/pause` · `/resume` | Pause or resume a queue |
| DELETE | `queues/{connection}/{queue}` | Purge the pending jobs of a queue |
| GET | `jobs/pending` · `delayed` · `running` · `stuck` | Live jobs from the `jobs` table |
| DELETE | `jobs/{connection}/{id}` | Delete a pending job |
| POST | `jobs/{connection}/{id}/run-now` · `/release` | Make a delayed job available now, release a stuck job |
| GET | `jobs/history` · `jobs/history/{uuid}` | Processed jobs with filters (`status`, `queue`, `name`, `tag`, `search`, `from`, `to`, `silenced`), detail with every attempt |
| GET | `jobs/failed` · `jobs/failed/{id}` | Failed jobs with filters, detail with the exception |
| POST | `jobs/failed/{id}/retry` · `jobs/failed/retry` | Retry one job, a list of `ids` or the current filter |
| DELETE | `jobs/failed/{id}` · `jobs/failed` | Forget one failed job or flush them all |
| GET | `batches` · `batches/{id}` | Batches with progress |
| POST | `batches/{id}/cancel` · `/retry` | Cancel a batch, retry its failed jobs |
| GET | `workers` | Workers with their status, supervisor and current job (`?supervisor=` keeps one supervisor's) |
| DELETE | `workers/{id}` | Forget a dead worker |
| GET | `supervisors` | Masters and supervisors with their status, processes per queue, balancing and range |
| POST | `supervisors/{master}/pause` · `/continue` · `supervisors/{master}/{supervisor}/pause` · `/continue` | Pause or resume a master or one of its supervisors, through the commands table |
| GET | `metrics/{queue\|job}` · `metrics/{type}/{name}?range=1h\|24h\|7d` | Aggregates and time series |
| GET/POST/DELETE | `monitoring` · `monitoring/{tag}` · `monitoring/{tag}/jobs` | Monitored tags and their jobs |

## Development

```bash
composer install
composer test      # Pest
composer analyse   # PHPStan (Larastan, level 6)
composer lint      # Pint
```

The front end lives in `resources/js` and `resources/sass` (Vue 3, Vite, Bootstrap, Chart.js) and is compiled into `resources/dist`, which is committed; the CI fails when the committed build no longer matches the sources:

```bash
npm install
npm run build
npm run test:ui    # Playwright smoke test of every screen against the workbench
```

To try the dashboard against a disposable application seeded with sample jobs:

```bash
composer serve
```

then open `http://127.0.0.1:8000/belvedere`.

`composer bench` measures the overhead per job against a run with the collector disabled, the latency of every read endpoint once the history holds a million rows, and the duration of `belvedere:prune`. It runs on SQLite by default; set `BENCH_DB_CONNECTION=mysql` or `pgsql` with `BENCH_DB_HOST`, `BENCH_DB_PORT`, `BENCH_DB_DATABASE`, `BENCH_DB_USERNAME` and `BENCH_DB_PASSWORD` to measure the database you run in production. The script drops and recreates the queue and Belvedere tables of that database. `composer bench -- --skip-api` measures the overhead per job alone; `--jobs=` and `--rows=` change the sizes.

## License

MIT. The user interface is derived from [Laravel Horizon](https://github.com/laravel/horizon), also published under the MIT license; see `NOTICE.md`.
