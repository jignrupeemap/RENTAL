# Rental, PG & Lease Marketplace (PHP + MySQL, Hostinger)

Design: [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) · Phase notes: [2](docs/PHASE2.md), [3](docs/PHASE3.md), [4](docs/PHASE4.md), [5](docs/PHASE5.md), [6](docs/PHASE6.md), [7](docs/PHASE7.md), [8](docs/PHASE8.md), [9](docs/PHASE9.md), [10](docs/PHASE10.md), [11](docs/PHASE11.md), [12](docs/PHASE12.md), [13](docs/PHASE13.md), [14](docs/PHASE14.md), [15](docs/PHASE15.md) · **[Deploy on Hostinger](docs/DEPLOYMENT.md)** · [Security checklist](docs/SECURITY.md) · [Testing checklist](docs/TESTING.md) · [Google Maps](docs/GOOGLE-MAPS.md)

## Phase 15 — Hostinger deployment pack

`php bin/package.php` builds the release zip with `public_html/` and `rental-app/` side by side, without secrets, tests, demo seeder, logs or media. Install without SSH from `/setup` (token-protected readiness check, Run migrations, first admin), or import `database/install.sql` in phpMyAdmin. `php bin/check.php` is the same readiness check on the command line. `php bin/backup.php [--files]` backs up the database (and media + config), and `php bin/restore-db.php` restores into an empty database only. Guides: [DEPLOYMENT.md](docs/DEPLOYMENT.md), [TESTING.md](docs/TESTING.md). Tests: `php tests/phase15_deployment_test.php` (a full fresh install from the zip in production mode).

## Phase 14 — performance

Load-tested with 20,000 listings: lists pick ids on a narrow query and then load one page of rows (same results, 2–10× faster), migration 0005 adds indexes, `php bin/build-assets.php` writes minified CSS/JS that production serves automatically (with a fallback if stale), Brotli/gzip for HTML/CSS/JS/XML. Dev pages report query count/time in a `Server-Timing` header when `APP_DEBUG` is on. Tests: `php tests/phase14_performance_test.php` (`PERF_N=5000` for a quicker run).

## Phase 13 — security hardening

Full audit (access control, injection/output, login/session/config) with every finding fixed: per-account login lock regardless of mobile format, admin limit guards, enquiries/bookings follow an owner change, read-only suspended profiles, allow-list for page HTML, POST-only `/setup/migrate`, production guards for debug output and mail, 12-hour admin session cap, common-password check. Tests: `php tests/phase13_security_test.php`. Go-live list: [docs/SECURITY.md](docs/SECURITY.md).

## Phase 12 — analytics

Admin → Analytics (`/admin/analytics`): spec 33 totals with "+N in range", 8 daily/monthly trend charts (plain HTML/CSS, no chart library), views-to-visits funnel, breakdowns by purpose, category, city and status, most viewed listings, and a numbers-only CSV (`/admin/analytics/export`). Owners get a Performance page per listing (`/owner/properties/{id}/performance`) and a 30-day views chart on their dashboard. Views are now stored by India date. Tests: `php tests/phase12_analytics_test.php`.

## Phase 11 — homepage slider

Admin → Homepage Sliders (`/admin/sliders`): add, edit, reorder, activate/deactivate and delete hero slides with title, subtitle, optional button and optional India-date schedule. Images are landscape ≥1200 px and processed to 480/960/1600 px JPEG + WebP. The homepage shows live slides in a Bootstrap carousel, or the original header when none are live. Tests: `php tests/phase11_slider_test.php`.

## Phase 10 — rental change alerts

RENTAL CHANGE ALERT cards on the admin dashboard (owner, mobile, property, old/new rent, increase, time; Review, VIEW PROPERTY, VIEW OWNER, VIEW CHANGE HISTORY), admin alert emails and owner decision emails when email notifications are on. Detection and approval came in Phases 4 and 6. Tests: `php tests/phase10_rent_alert_test.php`.

## Phase 9 — contact owner and request visit

Property pages get 📞 Contact Owner (`/property/{id}/contact`: enquiry with the listing snapshot, contact history, owner/admin notifications and optional email) and 📅 Request Visit (`/property/{id}/visit`: date, 30-minute slot, message; one open request per listing). `/post-requirement` is the Inquiry Form for visitors who found nothing; the admin follows it up under Enquiries → Requirements. Tests: `php tests/phase9_contact_test.php`.

## Phase 8 — viewer dashboard

`/account`: My Dashboard (real counts, recent shortlist, open visits, latest enquiries, notifications), Shortlisted Properties (remove, current status), Contacted Properties (what was seen at the time plus the listing's state now), Booking / Visit Requests (All/Open/Past, cancel, accept the owner's new time, history), Profile and password. Tests: `php tests/phase8_viewer_test.php`.

## Phase 7 — public search and listings

Homepage search card, `/rent` `/pg` `/lease` with filters, sorting and paging, SEO detail pages `/rent/{slug}-p{id}` (301 to canonical, 410 when no longer live), shortlist ❤️, view counting, sitemap.xml, robots.txt, listing-expiry cron. Tests: `php tests/phase7_public_test.php`.

## Phase 6 — admin panel

`/admin` (separate admin login): dashboard with alerts and date ranges, property search/approvals (approve, reject, request changes, pause, activate, delete, restore, publish), admin Add/Edit through the posting form, display order and featured, rental change and limit approvals, user management (status, limits, sign out everywhere, soft delete), enquiries, bookings, notifications, settings, activity log. Create the first admin with `php bin/create-admin.php`. Tests: `php tests/phase6_admin_test.php`.

## Phase 5 — photos and video

Step 6 of the posting form: multi-photo upload with progress (phones shrink photos first), GD resizing to 480/960/1600 px JPEG + WebP, cover, reorder, delete, one MP4/WebM video or a YouTube link; at least one photo needed to submit. Tests: `php tests/phase5_media_test.php`.

## Phase 4 — property posting form

`/owner/properties/new`: 8-step form (type, details, location with Google Maps pin, facilities, rent, photos & video, owner details, preview & submit) driven by the field map, with drafts, edit of live listings, rent-change detection and approval queue. Migration `2026_10_08_000004_posting_wizard.sql`. Tests: `php tests/phase4_wizard_test.php`.

## Phase 3 — owner dashboard

`/owner`: dashboard, My Properties (pause/resume), posting-limit gate and limit requests, enquiries inbox, visit requests, rent change history, profile. Tests: `php tests/phase3_owner_test.php`.

## Phase 2 — authentication

Register, login (mobile or email), logout, forgot/reset password, optional SMS OTP, email verification, My Account, separate admin login. Tests: `php tests/phase2_auth_test.php` (needs the dev server running, MAIL_DRIVER=log, SMS_DRIVER=log).

## Phase 1 — what exists now

- Database schema (35 tables) and reference data from Rental flow.xlsx: `database/migrations/`
- Migration runner that only applies new files and never resets data: `bin/migrate.php`, or `/setup/migrate` (paste `SETUP_TOKEN`) without SSH
- Core: front controller, router, PDO wrapper, settings, logger, friendly error pages, security headers, `.htaccess` for Apache/LiteSpeed
- Field map that turns the spreadsheet into dynamic forms: `app/Config/property_fields.php`
- Admin bootstrap without hard-coded credentials: `bin/create-admin.php`
- DEVELOPMENT/DEMO data kept separate (`is_demo = 1`): `bin/seed-demo.php`, removed by `bin/purge-demo.php`
- Self-check: `tests/phase1_schema_check.php`

## Run it locally (PHP 8.2+, MariaDB/MySQL)

```bash
cp config/env.example.php config/env.php     # set APP_ENV=development, APP_DEBUG=true, APP_URL, APP_KEY, DB_*
php -r "echo bin2hex(random_bytes(32));"     # paste as APP_KEY
php bin/migrate.php                          # creates tables + reference data
php tests/phase1_schema_check.php            # expect "All Phase 1 checks passed."
php bin/seed-demo.php                        # optional demo data (development only)
php -d upload_max_filesize=64M -d post_max_size=70M -S 127.0.0.1:8088 -t public public/index.php
php tests/phase2_auth_test.php               # in another terminal; expect "72 passed, 0 failed"
php tests/phase3_owner_test.php              # expect "60 passed, 0 failed"
php tests/phase4_wizard_test.php             # expect "131 passed, 0 failed"
php tests/phase5_media_test.php              # expect "59 passed, 0 failed"
php tests/phase6_admin_test.php              # expect "141 passed, 0 failed"
php tests/phase7_public_test.php             # expect "78 passed, 0 failed"
php tests/phase8_viewer_test.php             # expect "ALL 68 CHECKS PASSED"
php tests/phase9_contact_test.php            # expect "83 passed, 0 failed"
php tests/phase10_rent_alert_test.php        # expect "42 passed, 0 failed"
php tests/phase11_slider_test.php            # expect "56 passed, 0 failed"
php tests/phase12_analytics_test.php         # expect "50 passed, 0 failed"
php tests/phase13_security_test.php          # expect "43 passed, 0 failed"
php tests/phase14_performance_test.php       # expect "49 passed, 0 failed" (adds and removes 20,000 test listings)
php tests/phase15_deployment_test.php        # expect "59 passed, 0 failed" (own server on :8099, own rental_p15_* databases)
# open http://127.0.0.1:8088/health  → {"ok":true,"checks":{...}}
```

## Deploy it on Hostinger

Follow [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md). In short: PHP 8.2+, SSL on, create a database, extract the release zip next to `public_html`, write `rental-app/config/env.php`, open `/setup` (Run migrations, create the first admin, fix what the readiness check flags), add the cron jobs, then clear `SETUP_TOKEN`.

Never commit `config/env.php`.
