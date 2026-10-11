# Security checklist (go-live and ongoing)

## Before going live
- [ ] `config/env.php` is **outside** `public_html` (or the root `.htaccess` fallback is in place) and has `APP_ENV => 'production'` and `APP_DEBUG => false`.
- [ ] Strong, unique `DB_PASSWORD` and `APP_KEY` (it also signs OTP codes). Never commit `env.php` to GitHub.
- [ ] `MAIL_DRIVER => 'smtp'` with working Hostinger mailbox details. Send yourself a password reset to check.
- [ ] `SETUP_TOKEN` is empty, except while you use `/setup`. With it empty, every `/setup` address is a 404.
- [ ] Free SSL is on in hPanel, and the HTTPS redirect in `public/.htaccess` works. HSTS is sent automatically over HTTPS.
- [ ] Create the first admin on `/setup` or with `php bin/create-admin.php`, using a long, unique password ([DEPLOYMENT.md](DEPLOYMENT.md), step 6).
- [ ] Do not upload `storage/logs/*`, `storage/sessions/*` or any development `uploads/` files. The release zips already leave them out.
- [ ] Demo data is not imported into production. The demo seed is development only.
- [ ] Folder permissions: `storage/` and `public/uploads/` writable (755 for folders, 644 for files); nothing set to 777.

## Regular checks
- [ ] Admin → Activity Log: review admin actions every week.
- [ ] Admin → Users: look for unexpected admin accounts.
- [ ] `storage/logs/app-*.log`: errors carry a reference number that visitors see on the error page.
- [ ] Keep PHP on a supported version (8.2 or later) in hPanel.
- [ ] Backups run every night (`bin/backup.php` cron) and a copy is downloaded off the server monthly ([DEPLOYMENT.md → Backups](DEPLOYMENT.md#5-backups)).
- [ ] Run `php bin/check.php` after every update: it must report `0 to fix`.

## What the application enforces
- **Passwords and login:**
  - Passwords are hashed with `password_hash`. They need at least 8 characters with a letter and a number, and common passwords are refused.
  - Login is locked after 5 wrong tries per account (any mobile format) and 20 per IP within 15 minutes.
  - Reset, OTP, enquiry, booking and setup forms are rate-limited.
- **Sessions:**
  - Cookies are HttpOnly and SameSite=Lax, and Secure on HTTPS.
  - A new session is created at login, and every request is checked against `user_sessions`.
  - Admin sessions end after 30 minutes idle or 12 hours after sign-in.
- **Forms and output:**
  - Every POST is CSRF-checked and every query is prepared.
  - All output is escaped, and page content goes through an allow-list.
  - The CSP uses a script nonce.
- **Ownership and accounts:**
  - Ownership is checked on every owner and viewer action.
  - Blocked users are signed out on their next request, and suspended or inactive accounts are read-only.
- **Uploads:** files are type-checked from their bytes and re-encoded. They get random names and are stored where PHP cannot run.
- **Errors:** production shows "Something went wrong" with a reference number, and details go to the log only.
