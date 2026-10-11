# Testing checklist

There are two parts:
- **Automated suites.** They run on a development copy and never on the live site.
- **Manual launch checklist.** Click through it on the live site before you announce it, and again after each update.

## 1. Automated suites (development copy only)

Set up a local copy as in the README (*Run it locally*). Start the dev server, then run each suite. Every suite cleans up the test rows it creates.

| Suite | Covers (spec section) | Expect |
|---|---|---|
| `php tests/phase1_schema_check.php` | Tables, keys, reference data (40) | `All Phase 1 checks passed.` |
| `php tests/phase2_auth_test.php` | Registration, duplicate mobile/email, OTP, wrong password, blocked account, reset (3, 4, 63) | `72 passed, 0 failed` |
| `php tests/phase3_owner_test.php` | Owner dashboard, limit gate, limit request (5, 6, 53, 63) | `60 passed, 0 failed` |
| `php tests/phase4_wizard_test.php` | Posting form for Rent/PG/Lease × Residential/Commercial/Industrial, drafts, rent change (7–10, 29, 63) | `131 passed, 0 failed` |
| `php tests/phase5_media_test.php` | Multiple images, invalid file, oversized image, video, delete image (12, 39, 63) | `59 passed, 0 failed` |
| `php tests/phase6_admin_test.php` | Admin add/edit/pause/activate/delete, block user, limits, ordering (14–16, 34–35, 57, 63) | `141 passed, 0 failed` |
| `php tests/phase7_public_test.php` | Search, listing pages, detail URLs, SEO (20–22, 50–51) | `78 passed, 0 failed` |
| `php tests/phase8_viewer_test.php` | Viewer dashboard, shortlist history (23–25) | `ALL 68 CHECKS PASSED` |
| `php tests/phase9_contact_test.php` | Shortlist, contact, contact history, booking (24–28, 63) | `83 passed, 0 failed` |
| `php tests/phase10_rent_alert_test.php` | Same rent, decrease, increase, admin alert, approve/reject (29–31, 63) | `42 passed, 0 failed` |
| `php tests/phase11_slider_test.php` | Slider management (19, 36, 63) | `56 passed, 0 failed` |
| `php tests/phase12_analytics_test.php` | Dashboard and analytics numbers from real rows (32–33, 58) | `50 passed, 0 failed` |
| `php tests/phase13_security_test.php` | Ownership, CSRF, XSS, sessions, rate limits, setup token (41–43) | `43 passed, 0 failed` |
| `php tests/phase14_performance_test.php` | Page time and query budgets at 20,000 listings, indexes, images, assets (38, 39, 64) | `49 passed, 0 failed` |
| `php tests/phase15_deployment_test.php` | Release zip, fresh install via `/setup` in production mode, check/cron/backup/restore, `install.sql` (44, 46, 65) | `59 passed, 0 failed` |

The Phase 15 suite starts its own server on port 8099 and needs a MySQL account that may create databases (`TEST_MYSQL`, default `mysql`). It creates and drops its own `rental_p15_*` databases.

## 2. Manual launch checklist (live site)

Use a real phone as well as a desktop browser. Create two test accounts with your own spare numbers: an **owner** and a **viewer**. When you finish, delete or block them in Admin → Users. Their listings, once deleted, never appear publicly.

### Setup and security
- [ ] `https://` loads with a padlock, and `http://` redirects to `https://`.
- [ ] `/setup` shows *Not found* (`SETUP_TOKEN` is empty).
- [ ] `/health` shows `{"ok":true}`.
- [ ] `https://www.example.com/../rental-app/config/env.php`, `/config/env.php`, `/storage/logs/` and `/uploads/` all fail to open.
- [ ] The readiness check (`php bin/check.php`, or `/setup` before closing it) has no **Fix** items.
- [ ] The cron job ran in the last 5 minutes (readiness check → Scheduled jobs).

### Registration and login (spec 63)
- [ ] Register a new account. The welcome or verification email arrives.
- [ ] Registering again with the same mobile, and then with the same email, is refused.
- [ ] If OTP is on: a wrong OTP is refused and the right one works.
- [ ] A wrong password is refused. After 5 wrong tries the account waits 15 minutes.
- [ ] Forgot password → the email arrives → the reset works → the old password no longer works.
- [ ] Admin blocks the viewer test account → the viewer is signed out and cannot sign in.

### Property limit (spec 5, 63)
- [ ] The owner posts properties 1, 2 and 3. The dashboard shows *3 / 3*.
- [ ] Property 4 is blocked with the limit message and **REQUEST MORE PROPERTY LIMIT**.
- [ ] Send a request → Admin approves 3 → 5 → property 4 can be posted.

### Posting (spec 7–13, 63)
- [ ] Post one each of **Rent**, **PG** and **Lease**, covering **Residential**, **Commercial** and **Industrial**. Only fields relevant to the chosen type appear.
- [ ] Refresh mid-way: the draft is kept. *Save Draft* and *Continue Later* work.
- [ ] Location step (with a Maps key): search a building or area → the pin moves and empty address boxes, city, locality and pincode fill in. Clicking the map moves the pin; dragging it updates the coordinates. Without a key the coordinate boxes appear instead.
- [ ] A live listing shows the small map under **Location**: a circle for an approximate pin, a marker for an exact one.
- [ ] Photos: several images upload, a PDF renamed to `.jpg` is refused, a huge image is refused or resized, a video uploads, and deleting an image works.
- [ ] The preview looks like the public page → **Submit for Admin Approval** → the status is *Pending*.

### Admin (spec 14–16, 34–36, 57, 63)
- [ ] Approve, reject (with reason), request changes, pause, activate and delete a listing. The owner gets a notification each time.
- [ ] Admin → Add Property creates a live listing directly.
- [ ] Ordering: move a listing up or down, and set a priority. The public list order follows.
- [ ] Homepage slider: add, reorder, deactivate and delete a slide. The home page follows.
- [ ] Admin → Activity Log shows each of these actions with old and new values.

### Rent change (spec 29–31, 63)
- [ ] Owner saves the same rent: no alert.
- [ ] Owner lowers the rent: recorded, no approval needed.
- [ ] Owner raises the rent: a RENTAL CHANGE ALERT appears on the admin dashboard, and its links open the right property, owner and history.
- [ ] Approve: the new rent goes live. Reject another increase: the old rent stays.

### Viewer (spec 23–28, 63)
- [ ] Browse `/rent`, `/pg` and `/lease` while signed out. Filters and paging work.
- [ ] Shortlist ❤️ while signed out asks you to sign in. After signing in, it is saved and can be removed.
- [ ] Contact Owner: the owner sees the enquiry, and the viewer sees it under *Contacted Properties* with the rent at that time.
- [ ] Request Visit: the owner accepts or reschedules, and the viewer sees the change.
- [ ] Pause the shortlisted listing: the viewer's history shows *Paused* instead of breaking.

### Performance and mobile (spec 38, 47, 64)
- [ ] Home, search, a listing, the owner dashboard and the admin dashboard each open in about a second on mobile data.
- [ ] Listing cards load small thumbnails, and images further down the page load as you scroll.
- [ ] No sideways scrolling on a phone, on public pages or the admin panel.
- [ ] Optional: [PageSpeed Insights](https://pagespeed.web.dev/) for the home page and one listing page.

### Backups (spec 46)
- [ ] `rental-app/backups/` has a `db-*.sql.gz` from last night.
- [ ] Once before launch, practise a restore into a spare empty database ([DEPLOYMENT.md → Restore](DEPLOYMENT.md#6-restore)), then delete that database.
