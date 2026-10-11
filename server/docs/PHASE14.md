# Phase 14 — Performance

## What was measured

Each main page was timed and its database queries counted, first with today's handful of listings and then with **20,000 test listings** added (about 16,000 of them live). The test listings were removed afterwards.

| Page (20,000 listings) | Before | After |
|---|---|---|
| Homepage | 121 ms | **15 ms** |
| /rent (any filter or sort) | 58–66 ms | **11–28 ms** |
| /pg, /lease | 6–57 ms | **5–26 ms** |
| Admin → Properties | 134 ms | **12 ms** |
| Admin → Properties, approval queue | 25 ms | **8 ms** |

Server time on the test machine. Hostinger shared hosting is slower, but the ratio stays the same.

Every page uses a fixed number of queries (about 10), however many listings it shows, so there are no "one query per card" slow-downs.

## What was changed

### Faster database queries
- **Lists pick the page's listings first, then load their details.**
  - Search, the homepage and "similar listings" used to sort every matching listing with all its joined names and only then take the 12 to show.
  - Now they choose the 12 ids on a small query and load the full card details for those 12 only.
  - The same applies to the admin property list.
  - The test proves the results and their order are **exactly the same as before** for every sort and filter.
- **Homepage:** the few featured listings are read first, then the newest of the rest, both straight from an index.
- **New indexes (migration `0005`, indexes only, no data touched):**
  - newest listings
  - admin list by last update
  - approval queue
  - listing expiry, which now has its own index so the database no longer picks it by mistake for every search

### Smaller, compressed files
- **Minified files:** `php bin/build-assets.php` makes minified copies of our CSS and JavaScript (about 20–30% smaller). In production the site serves the minified copy automatically. If a minified file is older than the original (you edited the CSS and forgot to rebuild), the original is served, so you never get stale styles. Bootstrap is already minified.
- **Compression:** Brotli where the server supports it (LiteSpeed on Hostinger does), gzip otherwise, now also for the sitemap.
- **Already in place and confirmed:**
  - pictures in 3 sizes with WebP, with lazy loading and fixed width/height so the page does not jump
  - scripts load without blocking the page
  - CSS/JS/images cached for a year, with automatic refresh when a file changes
  - no external libraries or fonts
  - 12 listings per page
  - sessions start only for visitors who log in

### Developer aid
With `APP_DEBUG` on (development only), every page reports its query count and time in the browser's Network → Timing panel. This is never sent in production.

## How it was tested

`tests/phase14_performance_test.php` has **49 checks, and all pass**. It adds 20,000 test listings, then checks:
- **Budgets:** 11 public pages each under 150 ms with at most 12 queries, and 6 admin pages each under 300 ms.
- **No N+1:** the query count does not grow with the number of results.
- **Same results:** search (4 sorts × 2 pages), the homepage, similar listings and the admin list return exactly the same rows, order and totals as the old queries.
- **Indexes:** the key queries use their new indexes.
- **Paging:** a page number past the end goes to the last page.
- **Assets:** minified files in production, the fallback when one is out of date, readable files in development, and the compression and caching rules.
- **Clean-up:** all 20,000 test listings are removed.

A browser check also confirmed that pages using the minified CSS/JS look **pixel-identical** to the originals, with no JavaScript errors.

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
- Phase 13: 43

## How you can test it

1. Upload the files and run the migration (`php bin/migrate.php`, or open `/setup/migrate` and paste your `SETUP_TOKEN`). Only `0005_performance_indexes` is applied; no data changes.
2. The zip already includes the minified files. After you change any CSS or JS later, run `php bin/build-assets.php`.
3. Open the site and browse **Rent**, **PG** and **Lease** with filters. Pages should feel instant.
4. Optional: run Google PageSpeed Insights on the homepage once the site is on HTTPS.
