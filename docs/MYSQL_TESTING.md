# Testing on real MySQL (Phase 9B)

The normal suite (`php artisan test`) runs on an in-memory SQLite database. SQLite cannot show what MySQL (XAMPP's
MariaDB) does with two requests at the same moment, row locks, deadlocks, strict GROUP BY, LIKE escapes, case
insensitive collations or speed on many rows. This setup runs those checks on a throwaway MySQL database.

## Safety first

- It only ever uses the database **`jhunlea_test`**. Your real database (the one in `.env`) is never touched.
- `phpunit.mysql.xml` forces every database value (`DB_CONNECTION=mysql`, `DB_HOST=127.0.0.1`,
  `DB_DATABASE=jhunlea_test`, user `root`, empty password) with `force="true"` and a `<server>` copy, exactly like
  `phpunit.xml` does for SQLite. It also sets the marker `JHUNLEA_MYSQL_TESTS=1`; `phpunit.xml` forces it empty.
- `tests/Support/TestDatabaseGuard.php` stops the run before anything is touched unless: APP_ENV is testing, the
  connection is MySQL on this machine (127.0.0.1, localhost or ::1), no `DB_URL`, the configured name ends with
  `_test`, and the LIVE connection (`select database()`) also reports a name ending with `_test`.
- `tests/MySqlTestCase.php` checks the live name again before it migrates (`migrate:fresh`, once per run) and before
  it empties the tables (before every test). Only tables of the database the connection opened are emptied.
- The race workers (`php artisan jhunlea:race-worker`, test only, defined in `tests/` and registered only in the
  testing environment) get their database settings from the test's own environment and refuse to run unless the
  marker is set and the live database ends with `_test`.
- If your XAMPP root user has a password, change `DB_PASSWORD` in `phpunit.mysql.xml` on your machine only.

## One-time setup

1. Start MySQL in the XAMPP Control Panel.
2. Create the empty database (phpMyAdmin, or in a terminal):

```bash
C:\xampp\mysql\bin\mysql.exe -h 127.0.0.1 -u root -e "CREATE DATABASE IF NOT EXISTS jhunlea_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
```

The tables are created by the tests themselves (`migrate:fresh` on `jhunlea_test` only).

## Running

Use a clean terminal (no `.env` values exported; see AGENTS.md, "Running the tests safely").

```bash
php -d memory_limit=1G vendor/bin/phpunit -c phpunit.mysql.xml --testsuite MySql
```

The MySQL-only tests: setup, races, SQL behaviour and speed (about 6 to 10 minutes; the speed test seeds about
130,000 rows).

```bash
php -d memory_limit=2G vendor/bin/phpunit -c phpunit.mysql.xml --testsuite Unit,Feature
```

The whole normal suite again, on MySQL (long: 30 minutes or more on XAMPP).

One file or one test:

```bash
php -d memory_limit=1G vendor/bin/phpunit -c phpunit.mysql.xml tests/MySql/RaceConditionsTest.php --filter test_b
```

The normal SQLite run is unchanged and never runs `tests/MySql`:

```bash
php -d memory_limit=1G artisan test
```

## Reading the output

- "Refusing to run the MySQL tests: ..." means the guard stopped the run: nothing was touched. Read the reason
  (wrong database name, MySQL not running, a terminal with exported variables) and fix that, never the guard.
- Race tests print nothing when they pass. A failure names the scenario and shows the outcomes of the workers:
  `ok`, `refused` (a rule of the app said no, with its message, for example "already paid") or `error` (an
  exception that would reach the owner or customer, with the MySQL code: 1213 deadlock, 1205 lock wait timeout).
- The mixed load test prints one line, for example
  `[mixed] actions: 2100, ok: 600, refused: 1500, errors: []`.
- The speed test prints the median time of each screen in ms (`[speed] {...}`) and writes the details (row counts,
  query count, the slowest query, and EXPLAIN for a slow screen) to `storage/logs/mysql-speed.json`.

## Results of the first run (Phase 9B, XAMPP MariaDB 10.4.32, 16 MB InnoDB buffer pool)

- The whole normal suite on MySQL: green after seven tests that assumed SQLite were adjusted (the column order of
  `assistant_runs`, the test connection's name, ids that MySQL never reuses after a rollback, a 301 character name in a
  255 character column, DDL inside a test), plus one test skipped on MySQL with its reason (reverting a slice of the
  migration history works only where foreign keys are not checked on DROP; MySqlSetupTest reverts the whole history
  instead).
- Three deadlocks found by the races and fixed with the smallest change (lock order, no retry loops):
  1. `ReferenceNumberService::next()` ran INSERT IGNORE before SELECT ... FOR UPDATE. On an existing row INSERT IGNORE
     takes a shared lock; two transactions holding it both wait for the exclusive lock: 1213. Now it locks first and
     inserts only when the row is missing. Payments hid it behind their 5 retries; a walk-in order could not retry.
  2. A walk-in order wrote audit rows (the chain head lock) before it took the payment number, while a normal payment
     takes the number before the head: a lock-order cycle. The walk-in transaction (service and controller) now locks
     the payment counter first (`ReferenceNumberService::lock()`). Without it, 323 deadlocks in 20 seconds.
  3. The schedule lock (`BookingTransitionService::takeScheduleLock()`) had the same INSERT IGNORE pattern (two
     confirms at once could deadlock). The same guard was added in `ShopSettingsService` and `ScheduleEditService`.
- Speed: with the data shaped like a shop's year (the newest 400 orders open, older ones completed or cancelled),
  the owner pages took 0.5 to 1.8 s; the slowest query of EVERY owner page was the notification bell's "latest 8"
  (300 to 700 ms: it sorts all 25,000 of the owner's notifications). Phase 9B-2 added the index
  `notifications_notifiable_created_at_index` on `notifications (notifiable_type, notifiable_id, created_at)`
  (migration `2026_11_14_100000`, which also creates the PR, PB and PAY counter rows so even the first number of a
  prefix cannot deadlock). That query now takes about 2 ms, `SpeedTest` checks that MySQL uses the index without a
  filesort, and every screen is under one second except the Income report "All time" (1.1 to 1.6 s; it adds up every
  payment four ways) and the payments ledger search by customer name (0.9 to 1.3 s; `LIKE '%...%'` through order and
  booking subqueries, which no index serves). Both are listed in `SpeedTest::KNOWN_OVER_A_SECOND` and held to 2.5 s.
  Times on this laptop vary by about 40% between runs; run the speed test with nothing else running. With an even spread of statuses (43% of 20,000 orders open, not realistic) the awaiting-payment
  queries scan the whole orders table and the owner pages take up to 3 s.
- `audit:verify` on 20,001 rows: about 1.1 to 1.5 s.
- XAMPP's InnoDB buffer pool is only 16 MB, so every big query reads from disk. Set it in `C:\xampp\mysql\bin\my.ini`
  (`innodb_buffer_pool_size=256M` on the laptop; at least 128M on the hosting server) and restart MySQL in the XAMPP
  Control Panel. The timings above were measured with 16 MB.

## What each part proves

| File | Proves |
|---|---|
| `tests/MySql/MySqlSetupTest.php` | the run is on the `_test` database, in Laravel's strict mode |
| `tests/MySql/RaceConditionsTest.php` | real parallel processes: audit chain, double payment, shared GCash reference, payment against a price change, two confirmations for one slot, the open request limit, the studio thread and `client_id`, 20 seconds of mixed load |
| `tests/MySql/SqlBehaviourTest.php` | strict GROUP BY on every heavy screen, LIKE with `%` and `_` as text, case-insensitive emails and reference numbers, money in cents, JSON and utf8mb4 in the audit chain, the Manila midnight |
| `tests/MySql/SpeedTest.php` | the main screens under one second on about 130,000 fake rows |
