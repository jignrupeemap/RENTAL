# Phase 15: Hostinger deployment pack

This phase gets the site from a zip to a live, backed-up Hostinger website without needing SSH (spec 44, 46 and 65). All 15 phases are now built.

## What was built

| Piece | What it does |
|---|---|
| **Release zip** (`php bin/package.php`) | Builds `rental-site-hostinger-YYYYMMDD.zip` with two folders, `public_html/` and `rental-app/`, ready to extract on Hostinger. It never contains `config/env.php`, tests, the demo-data seeder, logs, sessions, backups or uploaded photos. You can therefore extract a later version over a live site without touching its data. Minified CSS/JS and `install.sql` are rebuilt first. |
| **Setup page** (`/setup`) | For plans without SSH. Paste `SETUP_TOKEN` to unlock it for 30 minutes in that browser. Then: **Run migrations**, **create the first admin** (only while the site has no admin, with the password rules plus 10 characters minimum) and a **readiness check**. If the token is empty, every `/setup` address returns 404. Changing the token locks browsers that were already unlocked. Five wrong tokens per hour per IP are allowed, and the limit also works before the database exists. |
| **Readiness check** (`php bin/check.php`, also shown on `/setup`) | About 35 checks: PHP version, extensions and limits, writable folders, upload protection, app folder outside the web root, every `env.php` value (production mode, URL, key, SMTP, SMS, Maps key, setup token), database connection, migrations, admin account, demo data and whether cron is running. Each check is OK, Review or Fix, with the hPanel menu to use. |
| **Backups** (`php bin/backup.php [--files] [--keep=N]`) | A consistent database dump in plain PHP (`db-*.sql.gz`), so it doesn't need `mysqldump`. With `--files` it also writes a zip of `uploads/` plus `config/env.php`. Everything goes in `rental-app/backups/`, outside the web root, with private permissions. It keeps 14 database dumps and 2 file archives. The dump also imports in phpMyAdmin. |
| **Restore** (`php bin/restore-db.php <file>`) | Loads a backup **only into an empty database**, so it can never overwrite live data. It refuses files it did not make. |
| **One-file database** (`database/install.sql`, built by `php bin/build-install-sql.php`) | All migrations in one file for phpMyAdmin → Import, which marks them as applied so later updates migrate normally. |
| **Docs** | [DEPLOYMENT.md](DEPLOYMENT.md) covers install step by step, email, SMS OTP, the Google Maps key, cron, backups, restore, updates, a table of every credential and where it lives, and troubleshooting. [TESTING.md](TESTING.md) has the automated suites and a manual launch checklist following spec 63/64. SECURITY.md, README and ARCHITECTURE are updated. |

## Smaller changes
- `bin/create-admin.php` uses the same rules as the setup page: valid mobile and email, the common-password check, and at least 10 characters.
- Cron records each run, so the readiness check can tell you whether the cron job works.
- Command-line tools find `public_html` next to `rental-app` by themselves, so `PUBLIC_PATH` in `env.php` can stay empty.
- Corrected: extra admins are added with `php bin/create-admin.php` over SSH. The admin panel has no "make admin" button, so the docs no longer say it does.
- The Phase 14 test seed now works when the development database has no admin account.

## Database changes
None. There is no new migration in this phase.

## Tested
- **`tests/phase15_deployment_test.php`: 59 checks pass.** It is a full rehearsal:
  - It builds the release zip and checks its contents and that no secret is inside.
  - It extracts the zip into a fake Hostinger home folder and runs it in **production mode** on a brand-new empty database.
  - It then installs through `/setup` exactly as you would without SSH: wrong token, right token, Run migrations, refused weak or mismatched passwords, first admin created, a second admin refused, and admin login.
  - It runs check, cron, backup and restore in that layout. Restored data and schema are identical to the original (checksums of every table).
  - It imports `install.sql` and gets exactly the schema the migrations give.
  - It clears the token, and every `/setup` address returns 404.
- All earlier suites still pass:

  | Phase | 1 | 2 | 3 | 4 | 5 | 6 | 7 | 8 | 9 | 10 | 11 | 12 | 13 | 14 |
  |---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|
  | Checks | OK | 72 | 60 | 131 | 59 | 141 | 78 | 68 | 83 | 42 | 56 | 50 | 43 | 49 |

## How to test it yourself
1. Follow [DEPLOYMENT.md](DEPLOYMENT.md), steps 1 to 6, on your Hostinger account or a subdomain such as `test.yourdomain.com`.
2. On `/setup`, press Run migrations, create your admin, and fix anything marked **Fix** in the readiness check.
3. Add the cron jobs (step 8). Within 5 minutes the readiness check shows "bin/cron.php last ran just now".
4. Clear `SETUP_TOKEN`: `/setup` now shows *Not found*.
5. With SSH, run `php bin/backup.php --files` and look in `rental-app/backups/`. Practise a restore into a spare empty database (DEPLOYMENT.md, section 6).
6. Work through [TESTING.md](TESTING.md), section 2.
