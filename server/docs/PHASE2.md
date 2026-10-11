# Phase 2 — Registration, login and password reset

## What was built

| Area | What it does |
|---|---|
| Register | First name, last name, mobile, email, password, terms. One account for both viewing and posting. Duplicate mobile/email blocked (any format: `98765 43210`, `+91…`, `0…`). Bots stopped by a hidden field and a 10-per-hour limit per network. |
| Login | Mobile **or** email + password. Optional "Login with a one-time code" when SMS OTP is switched on. Returns the user to the page they came from (only same-site pages). |
| Logout | Ends the session on the server too (the device record is revoked). |
| Forgot / reset password | Email link valid 60 minutes, single use. With OTP on, a mobile number gets a 6-digit SMS code instead. A reset signs the account out on every device. The form never reveals whether an account exists. |
| Mobile verification | Off by default. When Admin turns on `auth_otp_enabled`, new users verify their mobile by SMS before they can shortlist, contact or post. |
| Email verification | Link sent on registration (valid 2 days); "Resend link" on My Account. |
| My Account | Profile summary, verification badges, change password (signs out other devices). |
| Account status | **Blocked**: cannot log in and is logged out on the next click. **Inactive / Suspended**: can log in and browse but sees a notice; restricted actions (added from Phase 3) are refused. |
| Admin login | `/admin/login` with its own cookie, admin role required, 30-minute idle timeout. A normal site login never opens `/admin`. Dashboard shows live user counts and recent login activity. |
| Security | CSRF token on every form, secure/HttpOnly/SameSite cookies, new session id at login, bcrypt passwords with auto-upgrade, login lockout after 5 wrong tries per account (20 per network) for 15 minutes, OTP limits (3 sends per 10 min per number, 5 wrong tries), all tokens and codes stored only as hashes, identifiers masked in logs, no session cookie for anonymous visitors. |
| Email sending | Built-in SMTP client (no library). Every email goes into `email_queue`; urgent ones are sent immediately and cron retries failures. |

New database migration: `2026_10_08_000003_auth_additions.sql` (email verification table, `terms_accepted_at`, `password_changed_at`). It only adds; no existing data changes.

## How it was tested

`tests/phase2_auth_test.php` drives the real site over HTTP and checks the database: **72 of 72 pass** on PHP 8.3 + MariaDB 10.11. It covers new registration, duplicate mobile, duplicate email, invalid mobile, weak password, wrong password, unknown account, CSRF, lockout, blocked/inactive accounts, email verification, reset by email and by OTP, token reuse, invalid OTP, OTP login, admin separation and security headers. SMTP sending was tested against a local SMTP server. Pages were checked at 390 px phone width (no horizontal scrolling).

## How you can test it on Hostinger

1. Upload the new files (same layout as Phase 1) and run migrations again: `php bin/migrate.php`, or open `/setup/migrate?token=…`. Only migration 0003 is applied.
2. In `config/env.php` set `MAIL_DRIVER => 'smtp'` and your Hostinger mailbox details (`MAIL_HOST smtp.hostinger.com`, port 465, `ssl`, username = full email address, password, `MAIL_FROM` = same address).
3. Create the first admin over SSH: `php bin/create-admin.php` (it asks for name, mobile, email, password).
4. Add the cron job (hPanel → Advanced → Cron Jobs, every 5 minutes): `/usr/bin/php /home/uXXXX/domains/yourdomain/rental-app/bin/cron.php`
5. Try it:
   - Open `/register`, create an account → you land on **My Account** and receive a verification email.
   - Try registering the same mobile again → "already registered".
   - Log out, log in with mobile, then with email.
   - Enter a wrong password 5 times → the 6th try is locked for 15 minutes.
   - `/forgot-password` with your email → reset link arrives → set a new password → old one stops working.
   - Open `/admin/login` with the admin from step 3 → dashboard with real counts. Open `/admin` while logged in only on the site → it asks for admin login.
   - In phpMyAdmin set a test user's `status` to `blocked` → that user is logged out on their next click and cannot log in.

SMS OTP stays off until you pick a provider (MSG91 or 2Factor) and register a DLT template; then set `SMS_DRIVER`, `SMS_API_KEY`, `SMS_TEMPLATE_ID` in `config/env.php` and switch `auth_otp_enabled` to 1 (an admin settings screen comes in Phase 6).
