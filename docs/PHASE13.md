# Phase 13 — Security hardening

## What was done

The whole code base was reviewed in three parts:
- **Access control:** every route was traced to its database query.
- **Injection and output:** SQL, XSS, redirects and uploads.
- **Login, sessions and configuration.**

There were **no high-severity problems**. These were found and fixed:

| # | Problem | Fix |
|---|---------|-----|
| 1 | The login lock (5 wrong tries in 15 minutes) could be avoided by writing the mobile number differently, for example `+91 98765 43210` vs `9876543210`. Reset-email limits had the same gap with capital letters. | Limits now count per account, whatever the format. |
| 2 | A plain admin could change their **own** posting limit, or another admin's, and could approve their own limit request. | Same rule as status changes: never your own account, and admin accounts only by a super admin. |
| 3 | When an admin moved a listing to a new owner, the **old owner kept seeing its enquiries and visit requests** (visitor names and mobiles) and could still answer them. | Enquiries, visit requests and waiting rent changes now move with the listing. |
| 4 | A suspended or inactive account could still edit its name and email. | Profile changes now need an active account. Password changes stay allowed so people can always secure their account. |
| 5 | About, Terms and Privacy page content was printed as raw HTML. There is no editor yet, but a future one could have let scripts through. | Content is cleaned on every view: only headings, paragraphs, lists, emphasis and safe links are kept. |
| 6 | The browser migration runner took its secret token in the URL, so it could end up in server logs and browser history. | `/setup/migrate` is now a small form: you paste the token and press **Run migrations**. It is CSRF-protected and allows 5 wrong tries per hour. |
| 7 | If `APP_DEBUG` was left on in production, visitors could see technical error details. | Error details can never show in production. |
| 8 | With a missing or misspelled mail setting in production, password-reset links would have been written to a log file. | In production, email is sent only over SMTP. |
| 9 | Error logs recorded full addresses, including any token in them. | Logs now leave out everything after `?`. |
| 10 | Admin sessions only had a 30-minute idle timeout. | Admin sessions also end **12 hours after sign-in**, even while in use. |
| 11 | Common passwords such as `Password@123` or `qwerty123` were accepted. | About 70 of the most-guessed passwords are now refused, with a clear message. |
| 12 | The fake check that hides whether an account exists ran faster than a real one on newer PHP versions. | It now uses the same strength as real passwords. |

Also added: the `Cross-Origin-Opener-Policy: same-origin` header.

### Already in place and confirmed by the review
- **Database:** prepared statements everywhere; sort and filter names come from fixed lists.
- **Escaping:** all output is escaped, and structured data is JSON-escaped.
- **CSRF:** checked on every form.
- **Redirects:** the site never sends visitors to another website.
- **Passwords and tokens:** passwords are hashed with `password_hash`. Reset and verify tokens are random, stored only as hashes, expire and work once. OTPs are hashed, expire and allow 5 tries.
- **Sessions:** cookies are HttpOnly and SameSite (Secure on HTTPS); a new session is created at login; every request is checked against the database, so blocked users are signed out at once.
- **Admin:** the admin area is separate, with its own cookie and the admin role checked on every page.
- **Ownership:** checked on every owner and viewer action. Extra form fields cannot change owner, status, ranking or limits.
- **Uploads:** the file type is read from the bytes and images are re-saved. Files get random names, and PHP is switched off in the uploads folder.
- **Folders:** `app/`, `config/`, `storage/`, `database/` and the other code folders are blocked from the web in both Hostinger layouts.
- **Secrets:** none in public files or JavaScript.

### Accepted as they are (your call if you want them changed)
- **Registration says when a mobile/email is already registered.** The spec's test list asks for this ("Duplicate mobile / Duplicate email"). It is rate-limited to 10 per hour per address.
- **Opening an admin alert marks it as read.** Opening one is a normal link (GET). The worst case is that a crafted link marks an alert as read.
- **Admin two-step login (OTP) is not built.** Recommended later. For now admins rely on strong passwords, the login lock and the 12-hour limit.

See [SECURITY.md](SECURITY.md) for the checklist to follow when going live.

**Database:** there is no new migration.

## How it was tested

`tests/phase13_security_test.php` has **43 checks, and all pass**. There is at least one check for every fix above, plus checks that the baseline protections are still there:
- headers and cookie flags
- CSRF
- friendly 404 pages
- no outside redirects
- blocked folders
- no secrets in public files

The earlier suites all still pass:
- Phase 1: OK
- Phase 2: 72
- Phase 3: 60
- Phase 4: 131
- Phase 5: 59
- Phase 6: 141
- Phase 7: 78
- Phase 8: 68
- Phase 9: 83
- Phase 10: 42
- Phase 11: 56
- Phase 12: 50

## How you can test it

1. Upload the files. There is no migration this time.
2. On the login page, enter a wrong password 5 times, writing your mobile differently each time (with +91, with spaces). The 6th try is locked, even with the right password.
3. Try registering with `Password@123`. It is refused as too common.
4. Open `/setup/migrate` (only works while `SETUP_TOKEN` is set). You get a form instead of a page that runs straight away.
5. In Admin → Users, open your own account and try to change your posting limit. It is refused.
