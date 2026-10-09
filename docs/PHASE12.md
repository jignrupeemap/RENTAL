# Phase 12 — Analytics

## What was built

### Admin → Analytics (spec 33 and 58)
A new **Analytics** page sits in the admin menu, right under Dashboard. Every number on it comes from a live count in the database, so there are no sample or made-up figures.

- **Date range:** **Today**, **This week**, **This month** (the default) or **Custom** dates. Everything uses India time.
- **Totals:** 8 cards show the all-time total for each item, plus how many were added in the chosen range:
  - Total properties posted (with "posted this month")
  - Total users (with "new users this month")
  - Total logins
  - Property views
  - Total shortlists
  - Total contact requests
  - Total enquiries
  - Total booking requests
- **Trend charts:** one chart for each of the 8 items above.
  - Ranges up to about two months get one column per day; longer ranges get one column per month.
  - Hovering a column shows its value. **Show numbers** lists every value, which helps on phones.
- **From views to visits:** how many views led to shortlists, contact requests and booking requests, as a percentage of views.
- **Breakdowns:**
  - listings by purpose (Rent / PG / Lease) and by category (Residential / Commercial / Industrial)
  - live listings by city
  - enquiries and booking requests by status
  - Each bar links to the matching filtered list in the admin panel.
- **Most viewed listings** in the chosen range.
- **Download CSV:** the daily counts as a spreadsheet file. It contains numbers only, with no names, emails or mobile numbers.

The charts are drawn with plain HTML and CSS. No chart library is loaded, so the page stays fast on shared hosting. Custom ranges can be up to 3 years long.

### Owner → Performance (spec 6, "view property performance/history")
- **Where:** each listing in **My Properties** has a new **Performance** button.
- **What it shows:**
  - **Views**, **shortlists**, **enquiries** and **visit requests** for the last 30 days, 90 days or 12 months, each with a chart and the total since the listing was posted
  - **Rent history** for that listing
  - **Listing history**: each status change and who made it (you, the admin team or automatic)
    - The admin's reason is shown only for "rejected" and "changes requested", as on My Properties. Internal notes, such as a pause note, stay hidden.
- **Who can see it:** only the listing's owner. Anyone else gets "not found".
- **Owner dashboard:** a new chart shows the views of all the owner's listings over the last 30 days.

### Small fixes to make the numbers agree
- **Views by India date:** property views are now recorded under the India date. Before this fix, a view between midnight and 5:30 AM India time was counted on the previous day.
- **"Posted" counts:** the admin dashboard's "Posted" card now uses the same rule as Analytics. A listing counts once it has been submitted or published, so listings the admin publishes directly are now included.

**Database:** there is no new migration. Analytics reads the tables that already exist.

## How it was tested

`tests/phase12_analytics_test.php` has **50 checks, and all pass**. They cover:
- **Real activity:** the test makes real views, a shortlist, a contact request, a visit request and a login.
- **Totals:** each of the 8 totals on the page is compared with a direct database count.
- **Range lines:** the "+N · Today" lines and the chart values are checked.
- **Chart layout:**
  - one column per day for a week
  - one column per month for 4 months
  - ranges over 3 years, end dates before start dates and nonsense dates are all handled safely
- **India dates:** an event at 23:30 India time and one at 00:30 land on different days.
- **Breakdown links:** they open the matching filtered lists.
- **Most viewed:** our test listing appears with its views. Titles are escaped (XSS).
- **CSV:** it downloads as a file, has one row per day, matches the database and contains no personal data.
- **Dashboard:** its figures follow the same rules.
- **Owner page:**
  - the 30-day figures and charts
  - the 90-day and 12-month switch
  - rent and listing history, with the internal note hidden
  - another owner, a viewer and a guest are all refused
- **Owner dashboard chart:** it counts only the owner's own listings.
- **Access:** guests and site users cannot open Analytics or the CSV.

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

## How you can test it

1. Upload the files. There is no migration this time.
2. Log in as an admin and open **Analytics**. Try **Today**, **This week**, **This month** and a **Custom** range.
3. Open a property page in another browser (not logged in), then refresh Analytics. **Property views** goes up.
4. Press **Download CSV** and open the file in Excel or Google Sheets.
5. Log in as an owner (demo **9000000001** / **Demo@12345**). Open **My Properties** and press **Performance** on a listing. Try **Last 90 days** and **Last 12 months**.
6. The owner dashboard now shows **Views of your listings · last 30 days**.
