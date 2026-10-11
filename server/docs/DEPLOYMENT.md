# Deploying on Hostinger

This guide takes the site from the release zip to a live, backed-up website on Hostinger shared hosting (Premium, Business or Cloud). You do not need Docker, Node.js or a VPS. SSH is optional: every step has a no-SSH route.

**Contents**
1. [What you need](#1-what-you-need)
2. [How the files are laid out](#2-how-the-files-are-laid-out)
3. [First installation, step by step](#3-first-installation-step-by-step)
4. [Email, SMS OTP and Google Maps](#4-email-sms-otp-and-google-maps)
5. [Backups](#5-backups)
6. [Restore](#6-restore)
7. [Updating to a new version](#7-updating-to-a-new-version)
8. [Every credential and where it lives](#8-every-credential-and-where-it-lives)
9. [Troubleshooting](#9-troubleshooting)

Related: [SECURITY.md](SECURITY.md) (go-live security checklist) · [TESTING.md](TESTING.md) (what to click through before launch) · [ARCHITECTURE.md](ARCHITECTURE.md)

---

## 1. What you need

| Item | Requirement |
|---|---|
| PHP | **8.2 or newer** (8.3 recommended) |
| PHP extensions | `pdo_mysql`, `mbstring`, `fileinfo`, `gd` (with WebP), `dom`, `json`, `openssl`, and `curl` if you use SMS OTP. All are on by default at Hostinger. |
| PHP limits | `upload_max_filesize` 64M, `post_max_size` 70M, `memory_limit` 256M, `max_execution_time` 120 |
| Database | MySQL 5.7+ or MariaDB 10.4+ (Hostinger provides MariaDB) |
| Web server | Apache or LiteSpeed with `.htaccess` (Hostinger uses LiteSpeed) |
| SSL | Hostinger's free SSL certificate |
| Cron | One job every 5 minutes, plus one daily backup job |

The release zip is `rental-site-hostinger-YYYYMMDD.zip`. To build it yourself from the source folder, run `php bin/package.php` on your computer; it is written to `dist/`.

## 2. How the files are laid out

On Hostinger each domain has its own folder. The application goes **next to** `public_html`, not inside it, so the code, `config/env.php` and the logs can never be opened from a browser:

```text
/home/u123456789/domains/example.com/
├── public_html/              ← web root: only what browsers may load
│   ├── index.php             front controller (finds ../rental-app by itself)
│   ├── .htaccess             clean URLs, HTTPS redirect, compression, caching
│   ├── assets/               CSS, JS, images (minified copies are used in production)
│   └── uploads/              property photos/videos and slider images (scripts blocked)
└── rental-app/               ← application: never reachable from the web
    ├── app/  routes/  views/ code
    ├── bin/                  command-line tools (migrate, cron, backup, check …)
    ├── config/
    │   ├── app.php           non-secret settings
    │   ├── env.example.php   template
    │   └── env.php           YOUR SECRETS — you create this; it is never in a zip
    ├── database/
    │   ├── migrations/       one file per database change, applied once each
    │   └── install.sql       the whole database in one file (phpMyAdmin import)
    ├── backups/              created by bin/backup.php
    ├── docs/
    └── storage/              logs, sessions, cache (must be writable)
```

The release zip already contains exactly these two folders, `public_html/` and `rental-app/`.

> **Fallback layout.** If you ever have to put everything inside `public_html`, keep the folder structure as it is in the source (`public_html/app`, `public_html/public`, …). The root `.htaccess` then blocks `app/`, `config/`, `storage/` and the other folders and serves `public/`. The readiness check flags this layout as "Review", because the two-folder layout above is safer.

## 3. First installation, step by step

### Step 1 · PHP version and limits
hPanel → **Websites** → your site → **Advanced → PHP Configuration**:
1. **PHP version**: choose 8.2 or newer.
2. **PHP options**: set `upload_max_filesize` = 64M, `post_max_size` = 70M, `memory_limit` = 256M, `max_execution_time` = 120.
3. **PHP extensions**: make sure the extensions listed in section 1 are ticked. They are by default.

### Step 2 · SSL first
hPanel → **Security → SSL**: install the free SSL certificate for the domain and wait until it shows *Active*. Do this **before** uploading. `public_html/.htaccess` sends every visitor to `https://`, so without a certificate the site will not open.

### Step 3 · Create the database
hPanel → **Databases → MySQL Databases** (called *Management* in newer hPanel versions):
1. Create a database and a user with a strong password. Hostinger adds a prefix, so you get something like `u123456789_rental` / `u123456789_rentaluser`.
2. Note the **database name**, **user name** and **password**. The host is `localhost`.

Leave the database empty. Step 6 creates the tables.

### Step 4 · Upload the files
hPanel → **Files → File Manager**:
1. Open `domains/example.com/`, the folder that contains `public_html`.
2. If `public_html` still has Hostinger's placeholder page (`default.php` or `index.html`), delete it.
3. Upload `rental-site-hostinger-YYYYMMDD.zip` into `domains/example.com/` and use **Extract**. You should now see `public_html/` (with `index.php`, `assets/`, `uploads/`) and `rental-app/` side by side.
4. Delete the zip.

With SSH or SFTP you can upload the two folders instead. They must end up in the same places.

### Step 5 · Create `config/env.php` (all secrets live here)
In File Manager, open `rental-app/config/`, **copy** `env.example.php` to `env.php` and edit `env.php`:

| Key | What to put |
|---|---|
| `APP_ENV` | `'production'` |
| `APP_DEBUG` | `false` |
| `APP_URL` | `'https://www.example.com'`: your real address with `https://` and no trailing `/`. Use the same `www` or non-`www` form people will use. |
| `APP_KEY` | 64 random characters. With SSH: `php -r "echo bin2hex(random_bytes(32));"`. Without SSH: use your password manager's generator (64 characters, letters and digits). **Never change it after launch**: it signs OTP codes and setup sessions. |
| `PUBLIC_PATH` | Leave `''`. Cron and command-line tools find `public_html` next to `rental-app` by themselves. |
| `DB_HOST` / `DB_PORT` | `'localhost'` / `3306` |
| `DB_NAME` / `DB_USER` / `DB_PASSWORD` | From step 3. |
| `MAIL_*` | Section 4.1. You can finish this after launch. Until then, set `MAIL_DRIVER` to `'smtp'` with your mailbox details, or the readiness check shows "Fix". |
| `SMS_*` | Leave `SMS_DRIVER` as `'log'` while Mobile OTP is off (the default). Section 4.2. |
| `GOOGLE_MAPS_BROWSER_KEY` | `''` for now. Section 4.3. |
| `SETUP_TOKEN` | A **temporary** random string of 24+ characters (generate it like `APP_KEY`). It switches on the setup page in step 6. |

Save. **Never** upload `env.php` to GitHub or email it. It is listed in `.gitignore` and is never part of a release zip.

### Step 6 · Run the setup page
Open **`https://www.example.com/setup`**:
1. Paste your `SETUP_TOKEN` and press **Continue**. The page stays unlocked in this browser for 30 minutes. Five wrong tokens per hour lock it for that IP address.
2. **1. Database tables** → press **Run migrations**. All tables and the reference data (property types, amenities, cities, default settings, pages) are created. No users or properties are created.
3. **2. First admin account** → enter your name, mobile, email and a password (at least 10 characters with a letter and a number; common passwords are refused). The form only works while the site has **no** admin. To add more admins later, use `php bin/create-admin.php` over SSH; it can also give admin rights to an existing account.
4. **3. Readiness check** → work through anything marked **Fix**. "Review" items are advice. Reload the page after each change.

<details><summary>Alternatives to the setup page</summary>

- **With SSH** (hPanel → Advanced → SSH Access): `cd domains/example.com/rental-app`, then `php bin/migrate.php`, `php bin/create-admin.php` and `php bin/check.php`.
- **phpMyAdmin** (hPanel → Databases → phpMyAdmin → your database → **Import**): import `rental-app/database/install.sql` into the **empty** database. It equals all migrations so far and marks them as applied, so later updates still migrate normally. You still need the setup page or SSH to create the first admin.
</details>

### Step 7 · Sign in and configure
1. Open `https://www.example.com/admin/login` and sign in with the admin you created.
2. In **Admin → Settings**, review the site name, contact details, default posting limit (3), rent-increase approval, listing expiry and email notifications.
3. In **Admin → Homepage Sliders**, add at least one slide. Until you do, the home page shows its plain header.

### Step 8 · Cron jobs
hPanel → **Advanced → Cron Jobs** → *Custom*. Replace `u123456789` and `example.com` with your own values. File Manager shows the full path in its address bar.

| What | Schedule | Command |
|---|---|---|
| Scheduled jobs (email queue, listing expiry, featured end dates, cleanup) | every 5 minutes: `*/5 * * * *` | `/usr/bin/php /home/u123456789/domains/example.com/rental-app/bin/cron.php` |
| Database backup | daily at 02:30: `30 2 * * *` | `/usr/bin/php /home/u123456789/domains/example.com/rental-app/bin/backup.php` |
| Database + photos + config backup | weekly, Sunday 03:00: `0 3 * * 0` | `/usr/bin/php /home/u123456789/domains/example.com/rental-app/bin/backup.php --files` |

`cron.php` uses a lock file, so runs never overlap. Without it, emails stay queued and listings never expire. Within 5 minutes, the readiness check should show "bin/cron.php last ran just now".

### Step 9 · Close setup
In `config/env.php`, set `'SETUP_TOKEN' => ''`. Every `/setup` address now returns *404 Not Found*. To use the setup page again later, for example to run migrations after an update without SSH, set a new token, use the page, and clear the token again.

### Step 10 · Launch checks
- `https://www.example.com/health` shows `{"ok":true}`.
- Go through [SECURITY.md](SECURITY.md) → *Before going live* and the short launch list in [TESTING.md](TESTING.md).
- Demo data is never part of a release. If you ever loaded it, remove it with `php bin/purge-demo.php`; the readiness check shows "Fix" while any demo rows exist.

## 4. Email, SMS OTP and Google Maps

### 4.1 Email (password reset, verification, alerts)
1. hPanel → **Emails** → create a mailbox such as `no-reply@example.com`.
2. In the mailbox's **Configuration** (or *Connect apps & devices*), find the SMTP details. For Hostinger they are normally `smtp.hostinger.com`, port `465`, SSL.
3. In `env.php`, set:
   ```php
   'MAIL_DRIVER' => 'smtp', 'MAIL_HOST' => 'smtp.hostinger.com', 'MAIL_PORT' => 465, 'MAIL_ENCRYPTION' => 'ssl',
   'MAIL_USERNAME' => 'no-reply@example.com', 'MAIL_PASSWORD' => '(mailbox password)',
   'MAIL_FROM' => 'no-reply@example.com', 'MAIL_FROM_NAME' => 'Your Site Name',
   ```
4. Test it: on the site, use **Forgot password** with your admin email. The reset email is sent at once; other emails go out with the next cron run.
5. To email owners and admins about enquiries, bookings, rent changes and approvals, turn on **Admin → Settings → Send email notifications**.
6. To stop your mail landing in spam, use hPanel → **Emails → Email authentication** (or DNS records) to make sure SPF, DKIM and DMARC are set for the domain.

### 4.2 SMS OTP (optional)
Mobile OTP verification is **off** by default. To turn it on:
1. Create an account with **MSG91** or **2Factor.in**. Indian SMS needs a DLT-registered sender ID and template: the provider guides you through DLT registration.
2. In `env.php`, set `SMS_DRIVER` to `'msg91'` or `'twofactor'`, and fill in `SMS_API_KEY`, `SMS_SENDER_ID` and `SMS_TEMPLATE_ID`. For MSG91, the template must contain the variable `##otp##`.
3. Turn on **Admin → Settings → Mobile OTP verification**.
4. Register a test account and check that the code arrives. Codes are rate-limited: 3 per number per 10 minutes and 10 per IP per hour.

`SMS_DRIVER => 'log'` only writes codes to a log file and refuses to send in production, so never switch OTP on with it.

### 4.3 Google Maps
Until a key is added, owners type latitude/longitude, or leave the map pin out, and listings still show **View on Google Maps**. To enable the map and place search in the posting form:
1. Go to [Google Cloud Console](https://console.cloud.google.com/), create a project and attach a billing account. Google's monthly free usage covers a small site.
2. In **APIs & Services → Library**, enable **Maps JavaScript API** and **Places API (New)**.
3. In **APIs & Services → Credentials → Create credentials → API key**, set these restrictions:
   - **Application restrictions → Websites**: `https://example.com/*` and `https://www.example.com/*`
   - **API restrictions**: only the two APIs above
4. Paste the key into `'GOOGLE_MAPS_BROWSER_KEY'` in `env.php`.
5. Check it: Owner → Add Property → step 3 should show the map and the search box.

The key is visible in the browser by design (it is a *browser* key). The website restriction is what protects it. Never put a server or unrestricted key here.

## 5. Backups

There are three layers. Use at least the first two.

| Layer | What it covers | How |
|---|---|---|
| **Hostinger automatic backups** | Whole account: files and databases | hPanel → **Files → Backups**. How often depends on your plan (weekly or daily). |
| **`bin/backup.php`** (cron, step 8) | Database every night. With `--files` weekly: `public_html/uploads` (all photos, videos and slider images) plus `config/env.php` | Saved in `rental-app/backups/`, outside the web root, with private permissions. The newest 14 database backups (`--keep=N` to change) and 2 file archives are kept. |
| **Off-site copy** | Protection if the hosting account itself is lost | Once a month, download the newest `db-*.sql.gz` and `files-*.zip` from File Manager (`rental-app/backups/`) to your computer or cloud drive. |

What makes up a complete backup:
- **Database**: `db-YYYYMMDD-HHMMSS.sql.gz`, one consistent snapshot of every table.
- **Media**: `public_html/uploads/`, inside `files-*.zip`.
- **Configuration**: `rental-app/config/env.php`, inside `files-*.zip`. **That zip therefore contains your passwords.** Keep downloaded copies private.
- The code itself is the release zip, so keep the zip of the version you are running.

## 6. Restore

The restore tool never overwrites live data. It only loads a backup into an **empty** database.

**A. Database, from a `bin/backup.php` file**
1. hPanel → Databases: create a **new, empty** database (and user).
2. In `config/env.php`, change `DB_NAME`, `DB_USER` and `DB_PASSWORD` to the new database.
3. Load the backup with one of these:
   - SSH: `php bin/restore-db.php backups/db-20261009-023000.sql.gz`
   - phpMyAdmin → the new database → **Import** → choose the `.sql.gz` file.
4. Run migrations (`php bin/migrate.php`, or the setup page with a temporary `SETUP_TOKEN`). This adds any database change that is newer than the backup.
5. Check `/health` and sign in to `/admin`. Delete the old database only once you are sure.

**B. Photos, videos and config, from `files-*.zip`**
Upload the zip to `domains/example.com/` and extract it somewhere temporary. Copy its `uploads/` folder into `public_html/uploads/`, and its `config/env.php` into `rental-app/config/` if needed. Existing files with the same name are the same photos, so overwriting them is safe.

**C. Everything, from Hostinger**
hPanel → **Files → Backups** → choose a date → restore files and/or a database. This replaces the current state with that day's copy.

## 7. Updating to a new version

1. **Back up**: `php bin/backup.php --files`, or use hPanel → Backups → *Generate new backup*.
2. Upload the new `rental-site-hostinger-*.zip` to `domains/example.com/` and **Extract**, overwriting existing files. The zip never contains `config/env.php`, uploaded media, logs, sessions or backups, so those stay as they are.
3. Apply database changes. Migrations only *add* what is missing and never reset data:
   - SSH: `php bin/migrate.php`
   - No SSH: set a `SETUP_TOKEN`, open `/setup` and press **Run migrations**, then clear the token.
4. Run `php bin/check.php` (or look at `/setup`'s readiness check before clearing the token) and open the site.

If a new version needs a new `env.php` key, the release notes (`docs/PHASE*.md`) say so. Compare your `env.php` with the new `env.example.php`.

## 8. Every credential and where it lives

No credential is written in the code. Everything secret is in `rental-app/config/env.php`, which is outside the web root, or is stored hashed in the database.

| Credential | Created by | Stored in | Notes |
|---|---|---|---|
| Database name, user, password | You, in hPanel → Databases | `env.php` (`DB_*`) | Use a long random password. |
| `APP_KEY` | You (random, 64 characters) | `env.php` | Never change it after launch. |
| `SETUP_TOKEN` | You (random, 24+ characters) | `env.php` | Only while installing or updating without SSH. Then set it to `''`. |
| First admin login | You, on `/setup` or with `php bin/create-admin.php` | Database: password **hashed** (`password_hash`) | Sign in at `/admin/login`. Admin sessions end after 30 minutes idle or 12 hours. |
| More admins | You, with `php bin/create-admin.php` over SSH | Database (hashed) | `/setup` refuses once any admin exists. The script can also promote an existing account. |
| SMTP mailbox password | You, in hPanel → Emails | `env.php` (`MAIL_PASSWORD`) | |
| SMS provider API key, sender ID, template | MSG91 / 2Factor.in | `env.php` (`SMS_*`) | Only if OTP is switched on. |
| Google Maps browser key | Google Cloud Console | `env.php` (`GOOGLE_MAPS_BROWSER_KEY`) | Restricted to your domain. |
| hPanel login, FTP/SSH password | Hostinger | Your password manager | Turn on two-factor login for hPanel. |

## 9. Troubleshooting

| You see | Cause and fix |
|---|---|
| *Application files not found* | `rental-app` is not next to `public_html`, or is named differently. Check the layout in section 2. |
| *Missing config/env.php* or *APP_KEY … must be at least 32 characters* | Step 5 is not done, or `APP_KEY` is too short. |
| *Something went wrong* (with a reference number) | The details are in `rental-app/storage/logs/app-YYYY-MM-DD.log`. Search for the reference. Visitors never see raw errors. |
| Every page except the home page is *404* | `public_html/.htaccess` is missing. It is a hidden file, so in File Manager turn on *Show hidden files* and re-extract the zip. |
| Endless redirect or *too many redirects* | SSL is not active yet (step 2), or Cloudflare is set to "Flexible". Use "Full" in Cloudflare. |
| Photo or video upload fails | PHP limits in step 1. The readiness check shows the current upload limit. |
| Emails never arrive | The cron job is missing (the queue is sent by cron), or the SMTP details are wrong. Check `storage/logs/app-*.log` for "SMTP". |
| `/setup` is *404* | `SETUP_TOKEN` is empty or shorter than 24 characters. That is the safe state; set a token only when you need the page. |
| *Too many attempts* on `/setup` | Five wrong tokens in an hour from your IP. Wait an hour, or use SSH. |
| Readiness check: folder *not writable* | File Manager → right-click the folder → **Permissions** → 755. Never use 777. |
