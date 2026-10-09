# Phase 7 — Public search and listings

## What was built

Visitors can now find and open listings without logging in.

### Homepage
- **Search card:**
  - Choose **Rent**, **PG** or **Lease**, then City, Locality, Property type and Budget, and press **SEARCH PROPERTY**.
  - Localities follow the chosen city.
  - Choosing PG hides Commercial and Industrial.
  - The budget choices come from the admin setting "Budget filter ranges", so you can change them without code.
- **Rent / PG / Lease tiles**, each with the real count of live listings.
- **Featured and latest properties:** up to 8 cards, featured listings first.
- The large slider comes in Phase 11 and replaces the plain top band. The search card stays below it.

### Listing pages: /rent, /pg, /lease
- **Filters:** city, locality, property type, sub-type, budget (presets or a custom min/max), bedrooms (1, 2, 3, 4+) and furnishing.
- **PG filters:** "Available for" (boys, girls, any) and "Suitable for" (students, professionals, both).
- On phones the filters fold into a **Filters** button.
- **Sorting:**
  - **Recommended** (the default): featured first, then your Display Order from the admin panel, then newest.
  - **Newest first.**
  - **Rent low to high** and **Rent high to low.**
- **Pages:** 12 listings per page by default, set by the admin setting "Listings per page". Filtering and paging run in MySQL on indexed columns. PHP never loads every listing.
- **Cards** follow spec 49:
  - photo (a small thumbnail, lazy-loaded)
  - title
  - type
  - locality and city
  - rent per month
  - three key facts, such as "2 BHK | 2 Bath | Semi-Furnished", "450 Sq Ft | Unfurnished" or "For Girls | Students | Furnished"
  - Shortlist and View Details buttons

### Property detail page
- **Address:** a permanent SEO address, for example `/rent/2-bhk-flat-vesu-surat-p10006`.
  - The number at the end is the real identifier.
  - If the title, slug or purpose in the address is wrong or old, the page sends the visitor to the correct address (301). Old links keep working after rent or title changes.
- **Page content:** gallery, video, price card with deposit and maintenance, details, facilities, PG services, location with **VIEW ON GOOGLE MAPS**, and similar properties.
- **Privacy:**
  - The public page never shows the flat or shop number, the owner's name, mobile number or email.
  - The street line appears only when the owner chose an exact pin.
  - For an approximate pin, the map link uses the shifted point.
- **Shortlist ❤️ works now.**
  - It saves to the database, with no duplicates and a per-listing count. It updates without a page reload.
  - Guests are sent to login and brought back to the same page.
  - The owner's own listing shows "This is your listing" instead.
  - Paused or removed listings can't be added.
- **Contact Owner** and **Request Visit** come in Phase 9. I left them off the page until then, so there are no buttons that do nothing.
- **Removed listings:** a paused, expired or deleted listing, or one whose owner is suspended or blocked, shows "This listing is no longer available" (HTTP 410) with a link to similar listings.

### Who sees what
Only **active** listings that have **not expired**, whose owner account is **active**, appear anywhere public. That includes search, the homepage, the detail page and the sitemap.

### View counting
- Each visit to a detail page adds to the listing's view count. The admin dashboard's "Property views" now has real numbers.
- These visits are not counted:
  - search-engine bots and link previews
  - the owner looking at their own listing
- No cookie is used for this.

### SEO (spec 50)
- Every page has its own title and meta description. Descriptions never contain owner details.
- Canonical links and Open Graph tags let shared links show the photo.
- Detail pages have schema.org `RealEstateListing` data: price in INR, and locality and city only.
- Filtered search pages are marked `noindex`, so Google indexes the main pages rather than thousands of filter combinations.
- **/sitemap.xml** lists the homepage, the three listing pages, the CMS pages and every live listing.
- **/robots.txt** keeps admin, owner and account pages out of search engines. On any copy that is not the live site, it blocks everything, so a test copy is never indexed.

### New cron jobs (in `bin/cron.php`)
- **Expire listings:** an active listing past its expiry date ("Listing expiry days" setting) becomes **Expired**. This is written to its status history and the owner is notified. The admin can re-activate it from the property page.
- **End featured:** "Featured until" dates now switch featured off automatically.

**Database:** there is no new migration. Every table and index used was in the Phase 1 schema.

## How it was tested

`tests/phase7_public_test.php` has **78 checks, and all pass**. They cover:
- **Visibility:** paused, expired, pending and suspended-owner listings are hidden.
- **Order:** featured first, then display order.
- **Sorting and filters:** all four sorts, and every filter including PG.
- **Bad input:** junk values in the address are ignored safely, and titles are escaped.
- **Pages:** paging and a page number past the end.
- **Detail page:** 301s to the correct address, 410 and 404 pages, privacy (no unit number, street, owner name, mobile or email), approximate pin, canonical, JSON-LD and meta description.
- **View counting:** visitors are counted; bots and the owner are not.
- **Shortlist:** add, remove, no duplicates, AJAX, a missing CSRF token, guest, suspended viewer, own listing, and paused listing.
- **Search and SEO files:** homepage search, sitemap and robots.txt.
- **Cron:** both expiry jobs.

The Phase 1–6 suites all still pass:
- Phase 1: OK
- Phase 2: 72
- Phase 3: 60
- Phase 4: 131
- Phase 5: 59
- Phase 6: 141

In a real browser at phone and desktop width, I checked these:
- There is no sideways scrolling.
- Choosing PG on the homepage hides Commercial.
- Localities follow the city.
- The shortlist heart toggles without a reload.

## How you can test it

1. Upload the files. There is no migration this time.
2. Open the homepage, choose **PG**, pick a city and press **SEARCH PROPERTY**.
3. On **/rent**:
   - Try the filters and sorting.
   - On a phone, use the **Filters** button.
   - Press **View Details** on a card.
4. On the detail page:
   - Press **Shortlist** while logged out. You are asked to log in, then brought back to the page.
   - Log in and press it again. It turns into **Shortlisted**.
   - Change the slug part of the address. You land back on the correct address.
5. In the admin panel, **Pause** a listing and open its page again. It says the listing is no longer available.
6. Open **/sitemap.xml** and **/robots.txt**. On the live site, robots.txt lists the sitemap.
