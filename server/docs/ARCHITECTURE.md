# Rental, PG & Lease Marketplace — Architecture & Design

Status: **All 15 phases built and tested.** Deployment: [DEPLOYMENT.md](DEPLOYMENT.md).
Stack: PHP 8.2+, MariaDB 10.4+/MySQL 5.7+, Apache/LiteSpeed `.htaccess`, Bootstrap 5 (self-hosted CSS), vanilla JS with `fetch()`, Google Maps JavaScript + Places API.
Target: Hostinger shared hosting (Premium/Business). No VPS, Docker or Node.js at runtime.

Contents
- [A. Proposed architecture](#a-proposed-architecture)
- [B. Folder structure](#b-folder-structure)
- [C. Database table design](#c-database-table-design)
- [D. Relationships](#d-database-relationships)
- [E. Roles and permissions](#e-user-roles-and-permissions)
- [F. Property posting flow](#f-property-posting-flow)
- [G. Owner dashboard flow](#g-owner-dashboard-flow)
- [H. Viewer dashboard flow](#h-viewer-dashboard-flow)
- [I. Admin dashboard flow](#i-admin-dashboard-flow)
- [J. Security architecture](#j-security-architecture)
- [K. Hostinger deployment architecture](#k-hostinger-deployment-architecture)
- [Key decisions and their effect](#key-decisions-and-their-effect)
- [Phase plan](#phase-plan)

---

## A. Proposed architecture

A small, framework-free MVC application with one front controller.

```text
Browser
  │  HTTPS
  ▼
public_html/.htaccess ── real file? (css/js/uploads) ──► served directly by Apache, cached 1 year
  │ no
  ▼
public_html/index.php  (front controller)
  │
  ▼
app/bootstrap.php ── config/env.php (secrets) + config/app.php + autoloader + ErrorHandler
  │
  ▼
Router ── Middleware chain (Session → Auth → ActiveUser → Role → CSRF)
  │
  ▼
Controller (thin: read request, call service, pick view/JSON)
  │
  ├──► Services (business rules: PropertyLimitService, RentChangeService, ImageProcessor,
  │              NotificationService, OtpService, Mailer, SlugService, AnalyticsService)
  │        │
  │        ▼
  ├──► Repositories (all SQL lives here, PDO prepared statements only)
  │        │
  │        ▼
  │     MariaDB / MySQL  (source of truth for everything)
  │
  └──► Views (plain PHP templates, every value printed through e())  or  JSON for fetch()

Cron (hPanel → Cron Jobs) ── bin/cron.php: email queue, listing expiry, cleanup, sitemap
```

Layers and what they own:

| Layer | Folder | Owns | Never does |
|---|---|---|---|
| Configuration | `config/` | env secrets, constants | Business logic |
| Core | `app/Core/` | Router, Request/Response, View, Database, Session, CSRF, Settings, Logger, ErrorHandler, Migrator, Security | Know about properties |
| Middleware | `app/Middleware/` | Auth, admin/owner role checks, account status, CSRF, rate limit | Render pages |
| Controllers | `app/Controllers/{Public,Account,Owner,Admin,Api}` | Request → service → response | Run SQL directly |
| Services | `app/Services/` | Rules: limits, rent approval, uploads, notifications | Read `$_POST` |
| Repositories | `app/Repositories/` | SQL and ownership-scoped queries | Output HTML |
| Views | `views/{layouts,partials,public,account,owner,admin,emails}` | Markup | Query the DB |
| Field map | `app/Config/property_fields.php` | Which fields each property type shows/requires (from Rental flow.xlsx) | — |

Data flow rule: **MySQL is the only source of truth.** Wizard drafts are saved to `properties` (status `draft`) after every step; cookies hold only the session id and consent choice; no `localStorage` for application data.

## B. Folder structure

```text
rental-site/                      ← upload to /home/uXXXX/domains/yourdomain/rental-app/
├── app/
│   ├── bootstrap.php             shared start-up for web + CLI
│   ├── Config/property_fields.php  field map from Rental flow.xlsx
│   ├── Core/                     Config, Database, Router, Request, Response, View,
│   │                             Session, Csrf, Settings, Logger, ErrorHandler, Migrator, Security
│   ├── Middleware/               Authenticate, RequireAdmin, RequireActiveAccount, VerifyCsrf, RateLimit
│   ├── Controllers/
│   │   ├── Public/               Home, Listing (/rent /pg /lease), Property detail, Auth, Pages, Sitemap
│   │   ├── Account/              viewer dashboard: shortlist, contacted, bookings, profile
│   │   ├── Owner/                dashboard, property wizard, media, enquiries, bookings, rent history, limit
│   │   ├── Admin/                dashboard, users, properties, ordering, approvals, rent alerts,
│   │   │                         limit requests, enquiries, bookings, sliders, cities, settings, pages, logs
│   │   └── Api/                  JSON for fetch(): localities, shortlist toggle, uploads, map
│   ├── Repositories/             UserRepo, PropertyRepo, EnquiryRepo, BookingRepo, ...
│   ├── Services/                 PropertyLimitService, RentChangeService, ImageProcessor, VideoUploader,
│   │                             NotificationService, Mailer, OtpService, SmsGateway, SlugService, AnalyticsService
│   └── Helpers/functions.php     e(), url(), asset(), money_inr(), format_ist(), slugify()
├── bin/                          CLI: migrate, create-admin, cron, check, backup, restore-db, package, build-assets, build-install-sql, seed-demo, purge-demo
├── config/
│   ├── app.php                   non-secret constants (sizes, limits, timezones)
│   ├── env.example.php           template (committed)
│   └── env.php                   real secrets (NOT committed, outside public_html)
├── database/
│   ├── migrations/               0001 schema, 0002 reference data, 0003… future changes
│   └── seeds/                    (demo seed is bin/seed-demo.php, flagged is_demo=1)
├── docs/                         ARCHITECTURE, DEPLOYMENT (incl. backup/restore), SECURITY, TESTING, PHASEn
├── routes/web.php                every URL in one place
├── storage/                      logs/, cache/, tmp/  (writable, web access denied)
├── tests/                        CLI checks per phase
├── views/                        layouts/, partials/, public/, account/, owner/, admin/, emails/, errors/
└── public/                       ← contents go to public_html/
    ├── index.php                 front controller
    ├── .htaccess                 HTTPS, rewrites, caching, compression
    ├── robots.txt, sitemap.xml (generated)
    ├── assets/css, js, img       minified in production
    └── uploads/                  properties/YYYY/MM/, sliders/  (.htaccess: no script execution)
```

## C. Database table design

Full SQL: `database/migrations/2026_10_08_000001_initial_schema.sql`. 35 tables, InnoDB, utf8mb4, all `DATETIME` in UTC, money in whole rupees.

**Users and access**

| Table | Purpose | Key columns |
|---|---|---|
| `users` | One account per person (viewer and owner together) | first/last name, `mobile` UNIQUE, `email` UNIQUE, `password_hash`, `status` (active/inactive/blocked/suspended), `property_limit`, `property_limit_unlimited`, verification timestamps, `is_demo`, soft `deleted_at` |
| `user_roles` | Roles per account | (user_id, role) PK; role = viewer / owner / admin / super_admin |
| `user_sessions` | Logged-in devices, force logout | sha256 of session id, context site/admin, ip, revoked_at |
| `password_resets` | Reset links | sha256 token, expiry, used_at |
| `otp_codes` | SMS/email OTP | hashed code, attempts, expiry |
| `rate_limits` | Brute-force throttling | bucket, hits, reset_at |
| `login_activity` | Login/logout/failed attempts | masked identifier, ip, user agent |

**Reference data (from Rental flow.xlsx, admin-editable)**

| Table | Contents |
|---|---|
| `property_categories` | Residential, Commercial, Industrial |
| `property_types` | 19 sub-types. Each has `field_profile`, `unit_label` (Flat No / Bungalow No / Office No / Shop No / Plot No), `show_building_name`, `allows_pg` (Flat, Bungalow) |
| `amenities` | Yes/No features (Personal Washroom, Power Backup, Electricity Backup, RCC Road) and facilities (Pooja Room, Servant Room, Study Room, Play Area, Garden, Lift, Parking…), with `scopes` saying which forms show them |
| `cities`, `localities` | City dropdown and city-dependent locality dropdown; seeded with Surat + localities, more via admin |
| `settings` | Admin-editable options: default property limit (3), rent approval mode, expiry days, per-page, upload limits, OTP on/off, budget ranges, cookie policy version |
| `cms_pages` | About, Terms, Privacy, Cookie Policy |

**Properties**

| Table | Purpose |
|---|---|
| `properties` | One row per listing. Purpose (rent/lease/pg), category, type, status, wizard step, title, slug; address (unit no, building, project, landmark, address, city, locality, pincode); bedrooms/bathrooms/balconies/furnishing/available_from; area value + unit + normalised `area_sqft`; PG available-for / suitable-for; rent, deposit, maintenance, other charges; contact name + two mobiles (sheet: Owner Mob No 1/2); admin-only `is_featured`, `display_rank`, notes; counters; lifecycle timestamps. IDs start at 10001 |
| `property_locations` | Map pin: lat/lng, public lat/lng (offset when approximate), formatted address, place id, privacy exact/approximate |
| `property_media` | Photos and video: random 32-hex `file_key`, folder, dimensions, size, MIME, cover flag, order, soft delete |
| `property_amenities` | Listing ↔ amenity |
| `property_pg_services` | Food / Laundry / Car washing / Other, with optional monthly price |
| `property_status_history` | Every status change, who, role, reason |
| `property_view_daily` | Views per property per day (small table, fast analytics) |
| `rental_change_history` | Old/new rent, difference, increase/decrease, status (pending/approved/rejected/auto_applied/cancelled), snapshot of owner name/mobile/email and property title/URL, IP, reviewer |
| `property_limit_requests` | Current limit/count, requested limit or unlimited, reason, decision |

**Viewer engagement**

| Table | Purpose |
|---|---|
| `shortlists` | UNIQUE (user_id, property_id), so no duplicates |
| `enquiries` | Inquiry Form fields (name, two mobiles, email, interested in, property type, budget, city, locality, remarks) plus snapshot of property title, URL, owner name, rent at enquiry and a JSON of property facts. Status new/contacted/in_progress/closed |
| `contact_history` | Each Contact Owner action (enquiry, phone reveal) with rent at that moment |
| `bookings` | Visit requests: preferred date/time, message, status (pending/accepted/rejected/rescheduled/completed/cancelled), proposed new date/time, snapshot title/rent |
| `booking_status_history` | Timeline both viewer and owner see |

**Site, notifications, logs**

| Table | Purpose |
|---|---|
| `homepage_sliders` | Image key, title, subtitle, button text/link, order, active, optional schedule |
| `notifications` | In-app notifications; `audience='admin'` rows are shared by all admins |
| `email_queue` | Outgoing mail sent by cron (pages never wait for SMTP) |
| `admin_activity_logs` | Every admin action with old/new values (JSON) |
| `activity_logs` | User events: registration, property created/submitted, shortlist, contact, booking |
| `cookie_consents` | Proof of consent (hashed IP, choice, policy version) |
| `schema_migrations` | Which migrations have run, with checksum |

Indexes chosen for the real queries:

| Query | Index |
|---|---|
| `/rent` listing ordered by featured, rank, newest | `idx_prop_listing (status, purpose, is_featured, display_rank, published_at)` |
| Search purpose + city + budget | `idx_prop_search_city_rent (status, purpose, city_id, rent)` — verified with EXPLAIN as a covering range scan |
| Search by locality | `idx_prop_search_locality (status, city_id, locality_id, rent)` |
| Category filter | `idx_prop_category (status, category_id, purpose)` |
| Owner's properties / limit count | `idx_prop_owner (owner_id, status)` |
| Admin alerts | `idx_rch_status`, `idx_plr_status`, `idx_notifications_admin` |
| Admin user search | UNIQUE mobile, UNIQUE email, `idx_users_name` |

## D. Database relationships

```text
users 1──* user_roles
users 1──* user_sessions / password_resets / login_activity / activity_logs
users 1──* properties (owner_id; NULL = admin/system owned)    users 1──* properties (created_by)
property_categories 1──* property_types 1──* properties
cities 1──* localities;  cities/localities 1──* properties
properties 1──1 property_locations
properties 1──* property_media
properties *──* amenities              (via property_amenities)
properties 1──* property_pg_services
properties 1──* property_status_history
properties 1──* property_view_daily
properties 1──* rental_change_history *──1 users (owner, changed_by, reviewed_by)
users 1──* property_limit_requests
users *──* properties                  (via shortlists, UNIQUE pair)
properties 1──* enquiries *──1 users   (enquirer, owner)
enquiries 1──* contact_history
properties 1──* bookings 1──* booking_status_history
users 1──* notifications
```

Delete rules protect history: deleting is a status change (`status='deleted'`, `deleted_at`). Foreign keys from shortlists, enquiries, bookings and rent history to `properties` are RESTRICT, so a property with history cannot be hard-deleted by accident (tested). Child data with no meaning on its own (media rows, location, amenities) cascades.

## E. User roles and permissions

One account, many roles. Every registered user is a **viewer**; the **owner** role is added automatically the first time they start a listing. Admins are users with the **admin** role and sign in at `/admin/login` with a separate session cookie.

| Action | Visitor | Viewer | Owner | Admin |
|---|:-:|:-:|:-:|:-:|
| Browse, search, view details, map | ✓ | ✓ | ✓ | ✓ |
| Shortlist, contact owner, request visit, history | — | ✓ | ✓ | ✓ |
| Create listing (within own limit) | — | ✓ (becomes owner) | ✓ | ✓ (no limit) |
| Edit own listing, change own rent | — | — | ✓ own only | ✓ any |
| Rent increase goes live | — | — | after admin approval* | immediately |
| Pause own listing | — | — | ✓ if setting allows | ✓ |
| Approve/reject/request changes, delete | — | — | — | ✓ |
| Featured, display order, ownership, status | — | — | — | ✓ |
| Change property limits, user status | — | — | — | ✓ |
| Sliders, cities, settings, pages, logs | — | — | — | ✓ |

\* Controlled by setting `rent_change_approval_mode`: `increase_only` (default), `all`, or `none`.

Account status effects (checked on every restricted request, not just at login):

| Status | Can log in | Browse | Shortlist/contact/book | Add/edit listings, change rent | Listings receive new enquiries |
|---|:-:|:-:|:-:|:-:|:-:|
| active | ✓ | ✓ | ✓ | ✓ | ✓ |
| inactive | ✓ (sees notice) | ✓ | — | — | — |
| suspended | ✓ (sees notice) | ✓ | — | — | — |
| blocked | — | ✓ as visitor | — | — | — |

When an owner is blocked/inactive/suspended, their listings are hidden from search (query joins on owner status) but stay in the database for admin.

**Property limit rule:** a listing counts toward the limit unless its status is `deleted`. Drafts count, so the limit cannot be bypassed by stacking drafts. Limit is `users.property_limit` (copied from setting `default_property_limit` = 3 at registration) or unlimited via `property_limit_unlimited`. Nothing is hard-coded.

## F. Property posting flow

```text
[+ Post Property]
   │
   ├─ limit check (server) ── used ≥ limit ──► "You have reached your current property posting
   │                                            limit of 3 properties." [REQUEST MORE PROPERTY LIMIT]
   ▼
Step 1 Property Type   Purpose: Rent | Lease | PG
                       Rent/Lease → Category: Residential | Commercial | Industrial → Sub-type (filtered)
                       PG → Flat | Bungalow only
   ▼  (draft row created in properties, status=draft)
Step 2 Property Details   fields from the type's profile
Step 3 Location           address fields with type-specific labels + Google Places search + draggable pin
                          + Exact / Approximate privacy
Step 4 Facilities         only amenities whose scope matches; PG: extra-cost services with price
Step 5 Rent               monthly rent, deposit, maintenance, other charges
Step 6 Photos & Video     multi-upload with progress, choose cover, reorder, delete; one video (MP4/WebM) or YouTube link
Step 7 Owner Details      contact name, mobile 1, mobile 2, email (prefilled from account)
Step 8 Preview            rendered with the real listing template → [Edit] [Save Draft] [Submit for Admin Approval]
   ▼
status = pending → admin notified → Approve → active (public)  |  Reject (reason)  |  Request changes
```

Every step: `Step N of 8` header, progress bar, [Previous] [Save & Continue] [Save Draft]. Each Save & Continue posts to the server, is validated against the field map, and saved to the draft row, so a refresh or closed tab loses nothing and "Continue later" lists drafts on the dashboard.

How the sheet becomes a dynamic form (`app/Config/property_fields.php`):

| Profile | Sub-types | Shown in step 2 | Address label | Building name |
|---|---|---|---|---|
| residential | Flat, Bungalow, Farmhouse/Villa | BHK, bathrooms, balconies, area (optional), furnishing, available from | Flat No / Bungalow No / Plot No | Flat only |
| commercial | Office, Shop, Showroom, House/Building, Restaurant, Hospital, School, Hotel, Gym, Hall | Area (Sq Ft/Sq Yard), furnishing, available from | Office No / Shop No / Plot No | not for House/Building |
| commercial_plot | Commercial Plot | Area, available from (no furnishing) | Plot No | no |
| industrial | Factory, Warehouse/Godown, Industrial Plot, Cold Storage, Shed | Area (Sq Ft/Sq Yard), available from; Landmark required | Plot No | no |
| pg | Flat, Bungalow | BHK, bathrooms, balconies, furnishing, available from, Available for Boys/Girls/Any, Suitable for Students/Professionals/Both | Flat No / Bungalow No | Flat only |

Step 4 then shows, for example, Personal Washroom + Power Backup for Commercial → Shop, Electricity Backup + RCC Road for Industrial → Factory, and Pooja/Servant/Study Room, Play Area, Garden for Residential and PG. The same map drives server validation: a field not in the profile is rejected and cleared, so switching Flat → Shop cannot leave stale bedroom data.

**Editing a live listing:** owner edits go live immediately and are logged, unless setting `reapprove_on_owner_edit` is on. Rent is the exception (below).

**Rent change flow** (applies once a listing has been published; before the first approval the owner edits rent freely, since the admin reviews the whole listing anyway):

```text
Owner saves rent 20,000 → 22,000
  same rent?      → nothing recorded, no alert
  decrease?       → applied now, history row (auto_applied), admin notified
  increase?       → history row (pending), old rent stays live, properties.pending_rent_change_id set,
                    admin notification "RENTAL CHANGE ALERT" with owner name/mobile, property link,
                    old/new/increase, time (IST)
Admin Approve → new rent live, owner notified   |   Reject → old rent stays, owner notified with note
```

## G. Owner dashboard flow

```text
/owner
 Welcome, Rahul Patel
 Properties 3 / 3   Active 2   Pending 1   Paused 0   Enquiries 12   Bookings 4   Shortlisted 9
 [ + POST NEW PROPERTY ]    alerts: listing rejected / changes requested / rent change pending
Menu: Dashboard · My Properties · Add Property · Enquiries · Booking / Visit Requests ·
      Rental Change History · Property Limit · Profile · Logout
```

- **My Properties:** table/cards with status badge, rent (with "₹22,000 pending approval" tag), views, enquiries, shortlists; actions Edit, Preview, Pause/Resume (if allowed), Continue draft.
- **Enquiries:** tabs New / Contacted / In Progress / Closed; enquirer name, mobile, email, property, message, time, linked visit request; owner moves status and adds a private note.
- **Bookings:** Accept / Reject / Reschedule (propose new date/time) / Mark completed; full timeline.
- **Rental Change History:** every change with status.
- **Property Limit:** "3 of 3 used", request form (requested limit, reason), past requests and decisions.
- All counts are live `COUNT(*)` queries scoped to `owner_id = current user`.

## H. Viewer dashboard flow

```text
/account
 My Dashboard
 Shortlisted Properties · Contacted Properties · Booking / Visit Requests · Notifications · Profile
```

- Shortlist ❤️ is a `fetch()` toggle; logged-out users get a login/register modal that returns them to the same property afterwards.
- **Shortlisted:** photo, title, rent, location, date shortlisted, current status ("No longer available" for paused/deleted instead of a broken card); View / Remove.
- **Contacted Properties:** property, owner, rent at time of enquiry, date, message, status, [View Property]. Values come from the snapshot so later rent changes do not rewrite history.
- **Bookings:** preferred date/time, status, owner's proposed time with Accept/Cancel.
- Contact Owner on a property page opens the Inquiry Form prefilled from the account; property id, title, URL, owner and current details attach automatically.

## I. Admin dashboard flow

```text
/admin  (separate login, 30-min idle timeout)
 ALERTS  Pending approvals 4 · Rental changes 2 · Limit requests 1 · New enquiries 7 · New bookings 3
 USERS   Total · Active · Inactive · Blocked · New this month
 PROPS   Total · Rent · PG · Lease · Residential · Commercial · Industrial · Pending · Active · Paused
 ENGAGE  Logins · Property views · Shortlists · Contact requests · Enquiries · Bookings
 Date filter: Today · This week · This month · Custom
 Simple bar/line chart (inline SVG, no chart library) for posts and registrations by day
Menu: Dashboard · Properties · Approvals · Display Order · Rental Changes · Limit Requests · Users ·
      Enquiries · Bookings · Sliders · Cities & Localities · Amenities · Pages · Settings · Activity Log
```

- **Properties:** search (ID, title, city, locality, owner, mobile) and filters (purpose, category, city, locality, owner, status), paginated server-side; quick actions Approve, Reject (reason), Request changes, Edit, Pause, Activate, Delete; Add Property assigns to an existing user or admin/system and can publish immediately.
- **Display Order:** one list each for Rent, PG, Lease; ↑ ↓ buttons and a priority number, [Save Order]; Featured toggle with optional end date.
- **Rental Changes:** the alert card from the spec, with VIEW PROPERTY, VIEW OWNER, VIEW CHANGE HISTORY links and Approve / Reject.
- **Limit Requests:** user, current limit, requested, reason, date; Approve (editable number or Unlimited) / Reject.
- **Users:** search by name/mobile/email; profile, properties, enquiries, login history; Activate, Deactivate, Block, Unblock, Suspend, Delete (soft), change limit.
- **Sliders:** image, title, subtitle, button, order, status; add/edit/activate/deactivate/delete.
- Every admin write goes to `admin_activity_logs` with old and new values ("Admin Rahul changed property status Active → Paused, 08 Oct 2026 10:30 AM").

## J. Security architecture

| Threat | Control |
|---|---|
| SQL injection | PDO with real prepared statements (`ATTR_EMULATE_PREPARES=false`); the DB class has no string-interpolating method |
| XSS | Every output through `e()`; strict Content-Security-Policy with per-request nonce; user rich text not allowed (plain text + line breaks) |
| CSRF | Per-session token on every POST form and `fetch()` header; SameSite=Lax cookies |
| Session theft/fixation | HttpOnly, Secure (on HTTPS), SameSite cookies; `session_regenerate_id(true)` at login and privilege change; separate admin cookie with 30-min idle timeout; sessions tracked in `user_sessions` for force-logout |
| Passwords | `password_hash()` / `password_verify()` with automatic rehash; min length 8 (admins 10); never logged |
| Brute force | `rate_limits` buckets per IP and per account for login, OTP send, OTP verify, password reset, enquiries; lockout logged |
| IDOR ("change property_id=123") | Every owner query is scoped `WHERE id = ? AND owner_id = ?`; IDs from the request are never trusted; admin routes behind role middleware |
| Mass assignment | Wizard saves only fields allowed by the field map; owner can never set owner_id, status, display_rank, is_featured, approved_* |
| Uploads | `finfo` MIME check + `getimagesize()`; re-encode every image with GD (strips embedded payloads and EXIF/GPS); random filenames; fixed safe extensions; size, dimension and count limits; uploads folder refuses script execution and serves only `[hex]_size.(webp|jpg|png|mp4|webm)` |
| Secrets | `config/env.php` outside `public_html`, git-ignored; only the domain-restricted Google Maps browser key reaches the browser |
| Errors | Visitors see "Something went wrong. Please try again." with a reference code; full detail in `storage/logs` (outside web root, secrets masked) |
| Clickjacking/sniffing | `X-Frame-Options`, `X-Content-Type-Options`, `Referrer-Policy`, HSTS |
| Private data | Owner phone shown only to logged-in viewers after Contact Owner (setting); approximate map pin by default; no personal data in SEO metadata |
| Admin accountability | All admin actions logged with before/after values |
| Open redirect | Redirects accept site-relative paths only |

## K. Hostinger deployment architecture

```text
/home/u123456789/domains/yourdomain.com/
├── public_html/            ← contents of rental-site/public/
│   ├── index.php           finds ../rental-app automatically
│   ├── .htaccess
│   ├── assets/
│   └── uploads/            photos/videos (backed up with files)
└── rental-app/             ← everything else (not web-accessible)
    ├── app/  config/env.php  database/  routes/  storage/  views/  bin/
```

- **PHP:** hPanel → Advanced → PHP Configuration → PHP 8.2 or 8.3; extensions pdo_mysql, gd (with WebP), fileinfo, mbstring, dom, openssl (curl for SMS). Set `upload_max_filesize`/`post_max_size` to 64M for video.
- **Database:** hPanel → Databases → create DB + user; put credentials in `rental-app/config/env.php`.
- **Migrations and first admin:** SSH (`php bin/migrate.php`, `php bin/create-admin.php`) or, without SSH, set `SETUP_TOKEN` and open `/setup`: paste the token (posted, never in the URL), press Run migrations, create the first admin (only while none exists), read the readiness check, then clear the token. `database/install.sql` (generated) is the one-file phpMyAdmin import.
- **Release:** `php bin/package.php` builds a zip with `public_html/` and `rental-app/`, without `env.php`, tests, logs or media, so it can be extracted over a live site.
- **Readiness:** `php bin/check.php` (also on `/setup`) checks PHP, extensions, limits, folders, `env.php`, database, admin, demo data and cron.
- **HTTPS:** free SSL in hPanel; `.htaccess` forces HTTPS; cookies become Secure automatically.
- **Cron** (hPanel → Advanced → Cron Jobs): `php /home/u…/rental-app/bin/cron.php` every 5 minutes (email queue, OTP/rate-limit cleanup, listing expiry, featured end dates, deleted media files), plus `bin/backup.php` daily.
- **Email:** Hostinger mailbox SMTP (`smtp.hostinger.com:465`), sent from the cron queue.
- **Fallback layout:** if everything must live inside `public_html`, the root `.htaccess` routes all requests into `public/` and denies `app/`, `config/`, `storage/` etc.
- **Backups:** Hostinger daily/weekly backups plus `bin/backup.php` (plain-PHP consistent dump to a dated `.sql.gz` in `rental-app/backups/`, outside the web root; `--files` adds `uploads/` and `config/env.php`). `bin/restore-db.php` only loads into an empty database. Steps in `docs/DEPLOYMENT.md`.
- **Health check:** `/health` returns `{"ok":true}` in production, and the full PHP/extension/DB/migration report in development.

---

## Key decisions and their effect

| Decision | Hostinger compatibility | Performance | Security | Future scalability |
|---|---|---|---|---|
| Framework-free MVC instead of Laravel | Runs on any shared plan, no Composer/SSH needed, small upload | ~1–3 ms bootstrap, no framework overhead | Small surface we fully control | Clean layers mean moving to Laravel later is a port, not a rewrite |
| No Composer at runtime (any library, e.g. PHPMailer, is committed under `vendor/`) | Deploy by File Manager or FTP | No autoload scanning | Pinned, reviewed versions | Composer can be introduced later |
| App outside `public_html` | Supported on Hostinger (domain folder) with fallback | — | Code, config, logs unreachable from web | — |
| Bootstrap 5 CSS self-hosted, vanilla JS, no jQuery/React | Static files only | ~25 KB CSS gz, small JS | No third-party CDN script injection | — |
| GD image pipeline: 3 sizes (480/960/1600 px) WebP + JPEG fallback, originals discarded | GD is on every Hostinger plan; no Imagick needed | Listing cards load ~30–60 KB thumbnails | Re-encoding strips malicious payloads and GPS EXIF | Files can move to object storage/CDN later via `storage_dir` |
| Video: MP4/WebM up to 50 MB (configurable) or YouTube link; no server transcoding | ffmpeg is not available on shared hosting | Video loads only on click (`preload="none"`) | MIME + size checks | YouTube option keeps disk use low |
| Password login primary, SMS OTP optional (`log` driver in development; MSG91/2Factor in production) | Pure HTTPS calls | — | Rate-limited OTP, hashed codes | Swap providers in one class |
| One users table + roles | — | One join | Admin is still separated by route, role middleware and separate cookie | Add roles without schema change |
| Denormalised `city_id`, `locality_id`, `rent`, counters on `properties` | — | Search and cards need one indexed table, no N+1 | — | Fine to hundreds of thousands of listings on MariaDB |
| DB-backed settings | — | Loaded once per request | Secrets never stored there | Admin changes behaviour without code |
| Cron-driven email queue | Hostinger cron available on all paid plans | Pages never wait for SMTP | — | Swap to a mail API later |

## Phase plan

| Phase | Scope | Status |
|---|---|---|
| 1 | Architecture + database | **Done** (schema, reference data, core bootstrap, migrator, demo seed/purge, checks) |
| 2 | Authentication: register, login, logout, forgot/reset password, OTP option, sessions, status checks, rate limits | **Done** (72 end-to-end checks) |
| 3 | Owner dashboard | **Done** (60 end-to-end checks) |
| 4 | Property posting wizard (dynamic fields, drafts, preview, submit) | **Done** (131 end-to-end checks) |
| 5 | Image/video upload pipeline | **Done** (59 end-to-end checks) |
| 6 | Admin panel (users, properties, approvals, ordering, limits, settings) | **Done** (141 end-to-end checks) |
| 7 | Public homepage, search, listing and detail pages, SEO | **Done** (78 end-to-end checks) |
| 8 | Viewer dashboard | **Done** (68 checks) |
| 9 | Shortlist, contact, booking | **Done** (83 checks) |
| 10 | Rent-change approval + alerts | **Done** (42 checks) |
| 11 | Homepage slider | **Done** (56 checks) |
| 12 | Analytics | **Done** (50 checks) |
| 13 | Security hardening review | **Done** (43 checks) |
| 14 | Performance optimisation | **Done** (49 checks) |
| 15 | Hostinger deployment pack (docs, backup, checklists) | **Done** (59 checks) |
