# Deploy on a Hostinger VPS from GitHub

This guide puts the site live on a Hostinger **VPS** (KVM plans) and sets up
automatic deploys: every time code is merged into `main` on GitHub, the VPS
pulls it, runs new database migrations and reloads PHP within a minute.

For Hostinger **shared/cloud hosting** (hPanel, no root access) use
[DEPLOYMENT.md](DEPLOYMENT.md) instead.

```
GitHub (main) ──push/merge──► GitHub Actions ──SSH──► VPS: deploy.sh
                                                       git pull → migrate → build CSS/JS → reload PHP
Visitors ──HTTPS──► Nginx ──► PHP-FPM 8.3 ──► MySQL 8
```

**Contents**

1. [Which database?](#1-which-database)
2. [Create the VPS and point your domain](#2-create-the-vps-and-point-your-domain)
3. [First login, deploy user and firewall](#3-first-login-deploy-user-and-firewall)
4. [Install Nginx, PHP 8.3, MySQL 8 and Certbot](#4-install-nginx-php-83-mysql-8-and-certbot)
5. [Create the database](#5-create-the-database)
6. [Let the VPS read the GitHub repo and clone it](#6-let-the-vps-read-the-github-repo-and-clone-it)
7. [config/env.php (secrets)](#7-configenvphp-secrets)
8. [PHP-FPM and Nginx](#8-php-fpm-and-nginx)
9. [First deploy and first admin](#9-first-deploy-and-first-admin)
10. [HTTPS with Let's Encrypt](#10-https-with-lets-encrypt)
11. [Cron jobs and backups](#11-cron-jobs-and-backups)
12. [Automatic deploys from GitHub Actions](#12-automatic-deploys-from-github-actions)
13. [Day-to-day: releasing, rolling back, moving data](#13-day-to-day-releasing-rolling-back-moving-data)
14. [Troubleshooting](#14-troubleshooting)

Throughout, replace `example.com` with your domain and `203.0.113.10` with
your VPS IP address. Commands starting with `sudo` are typed as the `deploy`
user (or as `root` without `sudo` before step 3 is done).

---

## 1. Which database?

| Hostinger product | Database it gives you |
| --- | --- |
| Shared / Cloud hosting (hPanel → Databases → "MySQL Databases") | **MariaDB** (Hostinger labels it MySQL) |
| **VPS** | **None pre-installed.** You install it yourself; Ubuntu 24.04 offers **MySQL 8.0** and **MariaDB 10.11** |

This app works on both without changes. It uses only standard SQL: InnoDB
tables, `utf8mb4` / `utf8mb4_unicode_ci`, no MySQL-only or MariaDB-only
features, strict mode set by the app on every connection, and all dates in UTC.
It was tested on MariaDB during development and on **MySQL 8.0.46 (Ubuntu
24.04)** for this guide: all migrations and the Phase 1 schema checks pass,
and the HTTP test suites pass through Nginx + PHP-FPM.

**Use MySQL 8.0** (this guide). Choose MariaDB 10.11 only if you plan to move
the database back and forth with Hostinger shared hosting; then replace
`mysql-server` with `mariadb-server` in step 4 and everything else is the same.

Requirements: PHP **8.2 or newer** (Ubuntu 24.04 ships 8.3) with `pdo_mysql`,
`mbstring`, `gd` (with WebP), `xml` (dom), `curl`, `zip`, `fileinfo`, `openssl`.

## 2. Create the VPS and point your domain

1. hPanel → **VPS** → your server → **OS & Panel → Operating System**.
   Pick plain **Ubuntu 24.04** (no control panel; this guide sets everything up).
   KVM 1 (1 vCPU, 4 GB RAM) is enough to start; KVM 2 gives room to grow.
2. Set a strong **root password** there, and note the **IP address** on the VPS overview.
3. Domain DNS (hPanel → **Domains → DNS / Nameservers**, or wherever your domain is managed):

   | Type | Name | Points to | TTL |
   | --- | --- | --- | --- |
   | A | `@` | `203.0.113.10` | 3600 |
   | A | `www` | `203.0.113.10` | 3600 |

   Remove any older A/AAAA/CNAME records for `@` and `www` (for example ones
   pointing at shared hosting). DNS can take up to a few hours; check with
   `ping example.com`.

## 3. First login, deploy user and firewall

From your computer's terminal (Windows: PowerShell; or hPanel → VPS → **Browser terminal**):

```bash
ssh root@203.0.113.10
```

```bash
apt update && apt -y upgrade
timedatectl set-timezone Asia/Kolkata      # server clock only; the app stores UTC itself

# A normal user that owns the code, runs PHP and receives deploys
adduser deploy                              # choose a password
usermod -aG sudo deploy

# Firewall: SSH + web only
ufw allow OpenSSH
ufw allow 'Nginx Full' 2>/dev/null || { ufw allow 80/tcp; ufw allow 443/tcp; }
ufw --force enable
```

If hPanel → VPS → **Security → Firewall** has rules enabled, allow ports 22, 80 and 443 there too.

Log out and back in as `deploy` from now on: `ssh deploy@203.0.113.10`.

> Optional hardening once you can log in with an SSH key: set
> `PermitRootLogin no` and `PasswordAuthentication no` in
> `/etc/ssh/sshd_config`, then `sudo systemctl restart ssh`.

## 4. Install Nginx, PHP 8.3, MySQL 8 and Certbot

```bash
sudo apt install -y nginx git unzip \
  php8.3-fpm php8.3-cli php8.3-mysql php8.3-mbstring php8.3-gd php8.3-xml php8.3-curl php8.3-zip \
  mysql-server \
  certbot python3-certbot-nginx

php -v                       # PHP 8.3.x
php -m | grep -Ei 'pdo_mysql|gd|mbstring|curl|dom'
mysql --version              # 8.0.x
```

## 5. Create the database

```bash
sudo mysql_secure_installation      # answer Y to the questions; root keeps socket login
sudo mysql
```

In the MySQL prompt (pick your own password, keep it for step 7):

```sql
CREATE DATABASE rental CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'rental'@'localhost' IDENTIFIED BY 'a-long-random-password';
GRANT ALL PRIVILEGES ON rental.* TO 'rental'@'localhost';
FLUSH PRIVILEGES;
EXIT;
```

MySQL listens only on localhost by default; keep it that way (port 3306 stays closed in the firewall).

## 6. Let the VPS read the GitHub repo and clone it

The VPS gets its own **read-only deploy key**, so it can `git pull` without your GitHub password.

```bash
ssh-keygen -t ed25519 -C "rental-vps" -f ~/.ssh/github_deploy -N ""
cat ~/.ssh/github_deploy.pub
```

GitHub → repo **jignrupeemap/rental** → **Settings → Deploy keys → Add deploy key**:
title `VPS`, paste the key, leave **Allow write access** unticked → **Add key**.

```bash
cat >> ~/.ssh/config <<'EOF'
Host github.com
  IdentityFile ~/.ssh/github_deploy
  IdentitiesOnly yes
EOF
ssh -T git@github.com            # answer "yes"; expect "successfully authenticated"

sudo mkdir -p /var/www/rental
sudo chown deploy:deploy /var/www/rental
git clone git@github.com:jignrupeemap/rental.git /var/www/rental
```

The code is now in `/var/www/rental`; the PHP app is `/var/www/rental/server`
and the web root is `/var/www/rental/server/public`.

## 7. config/env.php (secrets)

`config/env.php` is never in git. Create it once on the server:

```bash
cd /var/www/rental/server
cp config/env.example.php config/env.php
chmod 600 config/env.php
php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'     # copy this for APP_KEY
nano config/env.php
```

| Key | Value |
| --- | --- |
| `APP_ENV` | `'production'` |
| `APP_DEBUG` | `false` |
| `APP_URL` | `'https://example.com'` (or `https://www.example.com`; must match the address you use) |
| `APP_KEY` | the 64 characters printed above |
| `PUBLIC_PATH` | leave `''` (the app finds `server/public` itself) |
| `DB_HOST` / `DB_PORT` | `'localhost'` / `3306` |
| `DB_NAME` / `DB_USER` / `DB_PASSWORD` | `'rental'` / `'rental'` / the password from step 5 |
| `GOOGLE_MAPS_BROWSER_KEY` | your Maps key; add `https://example.com/*` to its website restrictions ([GOOGLE-MAPS.md](GOOGLE-MAPS.md)) |
| `MAIL_*` | SMTP details. A Hostinger email account works: `smtp.hostinger.com`, port `465`, `ssl`, the mailbox and its password. Some VPS plans block outgoing port 25 only; 465/587 work |
| `SMS_*` | your OTP provider, or keep `'log'` while OTP is off in Admin → Settings |
| `SETUP_TOKEN` | leave `''`. The `/setup` page is for hosting without SSH; on a VPS use the commands below |

Save with Ctrl+O, Enter, Ctrl+X.

## 8. PHP-FPM and Nginx

The repo ships the server config in `server/deploy/`:

| File | Goes to | What it does |
| --- | --- | --- |
| `php-rental.ini` | `/etc/php/8.3/fpm/conf.d/99-rental.ini` and `/etc/php/8.3/cli/conf.d/99-rental.ini` | 64 MB uploads, 256 MB memory, errors logged not shown, OPcache |
| `php-fpm-rental.conf` | `/etc/php/8.3/fpm/pool.d/rental.conf` | Runs the site as `deploy`, so files uploaded on the website can be cleaned up and backed up by cron |
| `nginx-rental.conf` | `/etc/nginx/sites-available/rental` | Web root `server/public`, clean URLs, blocks dotfiles and config files, uploads serve images/video only and never run PHP, 1-year caching for CSS/JS/images |

```bash
cd /var/www/rental/server/deploy
sudo cp php-rental.ini /etc/php/8.3/fpm/conf.d/99-rental.ini
sudo cp php-rental.ini /etc/php/8.3/cli/conf.d/99-rental.ini
sudo cp php-fpm-rental.conf /etc/php/8.3/fpm/pool.d/rental.conf
sudo systemctl restart php8.3-fpm

sudo cp nginx-rental.conf /etc/nginx/sites-available/rental
sudo sed -i 's/example\.com/YOUR-DOMAIN.com/g' /etc/nginx/sites-available/rental
sudo ln -s /etc/nginx/sites-available/rental /etc/nginx/sites-enabled/rental
sudo rm -f /etc/nginx/sites-enabled/default
sudo nginx -t && sudo systemctl reload nginx
```

Let the deploy script reload PHP without a password prompt (only this one command):

```bash
echo 'deploy ALL=(root) NOPASSWD: /usr/bin/systemctl reload php8.3-fpm' | sudo tee /etc/sudoers.d/rental-deploy
sudo chmod 440 /etc/sudoers.d/rental-deploy
sudo visudo -cf /etc/sudoers.d/rental-deploy      # "parsed OK"
```

## 9. First deploy and first admin

```bash
bash /var/www/rental/server/deploy/deploy.sh main
```

`deploy.sh` pulls `main`, runs `bin/migrate.php` (creates all tables on the
first run, only new migrations later), rebuilds the minified CSS/JS, reloads
PHP-FPM and prints the readiness check. Then create the first admin and check again:

```bash
cd /var/www/rental/server
php bin/create-admin.php       # name, mobile, email, password
php bin/check.php              # every line OK; WARN lines explain themselves
```

Open `http://example.com` (HTTPS comes next). The admin panel is at `/admin/login`.

> The readiness check mentions `.htaccess` files. Nginx ignores them; the same
> rules are in `nginx-rental.conf`. They stay in the repo for Apache/shared hosting.

## 10. HTTPS with Let's Encrypt

Once `example.com` and `www.example.com` both reach the VPS:

```bash
sudo certbot --nginx -d example.com -d www.example.com --redirect -m you@example.com --agree-tos
sudo certbot renew --dry-run          # auto-renewal is installed as a systemd timer
```

Certbot adds the HTTPS block and the HTTP → HTTPS redirect to the Nginx file.
Make sure `APP_URL` in `config/env.php` starts with `https://`, then `php bin/check.php`.

## 11. Cron jobs and backups

As `deploy`, run `crontab -e` and add:

```cron
# Email queue, listing expiry, featured end dates, cleanup (lock file prevents overlap)
*/5 * * * * /usr/bin/php /var/www/rental/server/bin/cron.php >/dev/null 2>&1
# Database backup every night, database + photos + config every Sunday
30 2 * * *  /usr/bin/php /var/www/rental/server/bin/backup.php >/dev/null 2>&1
0 3 * * 0   /usr/bin/php /var/www/rental/server/bin/backup.php --files >/dev/null 2>&1
```

Backups land in `/var/www/rental/server/backups/` (not in git, not web-reachable;
14 nightly + 2 weekly kept). They are on the same disk as the site, so also:

- turn on hPanel → VPS → **Backups & Monitoring** (weekly snapshots, or daily on paid add-on), and
- now and then copy the newest file to your computer:
  `scp deploy@203.0.113.10:/var/www/rental/server/backups/db-*.sql.gz .`

## 12. Automatic deploys from GitHub Actions

`.github/workflows/deploy.yml` runs on every push to `main` (merging a PR
counts) and on demand. It logs into the VPS over SSH and runs `deploy.sh`.
Until the secrets below exist it skips itself instead of failing.

**a. A key for GitHub to log into the VPS** (on the VPS, as `deploy`):

```bash
ssh-keygen -t ed25519 -C "github-actions" -f ~/.ssh/gh_actions -N ""
cat ~/.ssh/gh_actions.pub >> ~/.ssh/authorized_keys
chmod 600 ~/.ssh/authorized_keys
cat ~/.ssh/gh_actions            # the PRIVATE key: copy all of it, including the BEGIN/END lines
```

**b. Repository secrets**: GitHub → repo → **Settings → Secrets and variables → Actions → New repository secret**:

| Name | Value |
| --- | --- |
| `VPS_HOST` | `203.0.113.10` |
| `VPS_USER` | `deploy` |
| `VPS_SSH_KEY` | the private key from (a) |
| `VPS_PORT` | only if SSH is not on port 22 |

Then delete the private key from the server: `rm ~/.ssh/gh_actions` (the `.pub` line stays in `authorized_keys`).

**c. Test it**: GitHub → **Actions → Deploy to VPS → Run workflow**. The log
ends with `Deployed <commit>`. From now on, merging into `main` deploys.

Prefer to deploy by hand? Skip this step and run
`bash /var/www/rental/server/deploy/deploy.sh main` on the VPS whenever you want to release.

## 13. Day-to-day: releasing, rolling back, moving data

**Release**: change code on a branch → open a PR → merge into `main` → the Action deploys.
Database changes are always a **new** file in `server/database/migrations/`
(never edit one that already ran); `deploy.sh` applies it.

**Never edit code on the server.** `deploy.sh` resets the checkout to
GitHub's `main`, so server-side edits to tracked files are thrown away.
Uploads, `config/env.php`, logs, sessions and backups are untracked and are never touched.

**Roll back**: on GitHub, open the merged PR and press **Revert**, then merge
the revert PR; it deploys like any release. (Migrations are not rolled back;
if a release added a table or column, the older code simply ignores it.)

**Moving from Hostinger shared hosting** (only if the old site has real data):

1. Old site: hPanel → phpMyAdmin → Export (SQL), or `php bin/backup.php --files` over SSH.
2. VPS: `gunzip -c db-YYYYMMDD.sql.gz | mysql -u rental -p rental` into the **empty** database
   (do this instead of the first `migrate` in step 9, then run `deploy.sh`, which applies any newer migrations).
3. Copy `public_html/uploads/properties` and `public_html/uploads/sliders` into
   `/var/www/rental/server/public/uploads/` (e.g. with `scp -r` or `rsync`), then
   `chown -R deploy:deploy /var/www/rental/server/public/uploads`.
4. Reuse the old `APP_KEY` in the new `config/env.php`, otherwise existing
   "remember me" logins and pending password-reset links stop working.

## 14. Troubleshooting

| Symptom | Fix |
| --- | --- |
| `502 Bad Gateway` | PHP-FPM pool not running: `sudo systemctl status php8.3-fpm`, check `/etc/php/8.3/fpm/pool.d/rental.conf` and that Nginx uses `unix:/run/php/rental.sock` |
| `500` or blank page | `tail -50 /var/www/rental/server/storage/logs/app-$(date +%F).log`, and `sudo tail /var/log/nginx/error.log` |
| "Application files not found" | Nginx `root` must be `/var/www/rental/server/public` |
| Uploads fail on big videos | `client_max_body_size 70M` in Nginx, `99-rental.ini` copied to the **fpm** folder, PHP-FPM restarted |
| `SQLSTATE[HY000] [1045] Access denied` | `DB_USER` / `DB_PASSWORD` in `config/env.php` don't match step 5 |
| `SQLSTATE[HY000] [2002]` | MySQL stopped: `sudo systemctl status mysql` |
| Action fails at SSH | `VPS_HOST`/`VPS_USER`/`VPS_SSH_KEY` secrets; the `.pub` key in `/home/deploy/.ssh/authorized_keys`; port 22 open in ufw **and** hPanel firewall |
| Action fails at `git fetch` | The deploy key from step 6 is missing on GitHub, or `~/.ssh/config` doesn't point to it |
| Action fails at `systemctl reload` | The sudoers file from step 8 is missing |
| Emails never arrive | Cron line from step 11 missing, or SMTP settings wrong; search the app log for "SMTP" |
| Certbot fails | DNS for both names must already point to the VPS, and port 80 must be open |
