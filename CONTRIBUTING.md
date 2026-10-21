# Contributing

Thanks for considering a contribution to Belvedere.

## Reporting a bug

Open an issue with the Laravel and PHP versions, the database driver, the queue configuration (`config/queue.php`) and the smallest job that reproduces the problem. A failing Pest test is the most useful report.

## Proposing a change

1. Fork the repository and create a branch from `master`.
2. Install the dependencies: `composer install`, and `npm install` if you touch the front end.
3. Make the change, with tests for any new behaviour. The suite runs against SQLite by default; `BELVEDERE_TEST_DB=mysql` or `pgsql` (with `BELVEDERE_TEST_DB_HOST`, `_DATABASE`, `_USERNAME`, `_PASSWORD`) runs it against a real server, which is what the CI does on MySQL 8.4 and 9.7, MariaDB 11.8 and PostgreSQL 17.
4. Make sure everything is green before opening the pull request:

```bash
composer test
composer analyse
composer lint
```

5. Rebuild the assets if you changed `resources/js` or `resources/sass` (`npm run build`) and commit `resources/dist` with the change: the CI rebuilds them and fails when the committed files differ. `npm run test:ui` runs the Playwright smoke test, which opens every screen against the seeded workbench and fails on any console error or failed request (`npx playwright install chromium` the first time).

Keep pull requests focused: one change, its tests and, when it affects users, a line in `CHANGELOG.md` under *Unreleased*.

## Design rules

- Belvedere never unserializes job payloads in the web process; everything shown comes from the JSON payload or from the snapshot recorded at dispatch.
- Laravel's `jobs`, `failed_jobs` and `job_batches` tables are read, never altered, except through the actions a user triggers.
- The interface keeps Horizon's layout and vocabulary on purpose, so that people who know Horizon are not lost.
