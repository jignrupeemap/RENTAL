# Phase 3 — Owner dashboard

## What was built

Owner area at `/owner`. It's linked from **My Account** and from the **My Account ▾** menu (Owner Dashboard). Any logged-in account can open it, so nobody needs a second account to become an owner.

| Page | What it does |
|---|---|
| **Dashboard** `/owner` | "Welcome, Rahul Patel". Shows Properties **3 / 3** (or "/ Unlimited") with a "Limit reached" flag, plus Active, Pending, Paused, Drafts, Enquiries (with the number of new ones), Bookings (with the number waiting) and Shortlisted. Below that are the latest enquiries, visit requests waiting, notifications (with "Mark all read"), and alerts for rejected listings and pending rent changes. Every number is a live database count. |
| **My Properties** `/owner/properties` | Tabs: All / Active / Pending / Drafts / Paused / Needs attention / Expired. Each card shows the status badge, ID, purpose and type, location, rent (and any increase waiting for approval), views, enquiries, shortlists and the admin's note when rejected. **Pause listing / Resume listing** is available when the `allow_owner_pause` setting is on. An owner can't resume a listing that the admin paused. |
| **Add Property** `/owner/properties/new` | Checks the posting limit on the server. At 3 / 3 it shows *"You have reached your current property posting limit of 3 properties. Please request permission from Admin…"* with **REQUEST MORE PROPERTY LIMIT**. Below the limit, it shows how many listings are left; the step-by-step form opens from here in Phase 4. |
| **Enquiries** `/owner/enquiries` | Tabs: New / Contacted / In Progress / Closed. Shows the enquirer's name, tap-to-call mobile numbers, email, the property (with rent at the time of the enquiry), the message, the date and time, and any linked visit request. The owner can change the status and add a private note. Search works by name, mobile, email or property. |
| **Booking / Visit Requests** `/owner/bookings` | Tabs: Waiting / Accepted / Rescheduled / Closed. Actions are **Accept**, **Decline**, **Propose new time** (date and time, today or later), **Mark completed** and **Cancel visit**. Only the actions that make sense for the current status are shown (see below). Each request has its full history, and the visitor is notified of every change. |
| **Rental Change History** `/owner/rent-changes` | Old rent, new rent, change, status (Pending / Approved / Rejected / Applied), admin note, date. Changes start being recorded in Phase 4, when owners can edit rent. |
| **Property Limit** `/owner/limit` | Shows usage with a progress bar. **Request More Listings** asks for a number above the current limit, or Unlimited, plus a reason. Only one request can be pending at a time. The admin is notified, and past requests are listed with their decisions. Admin approval is the Phase 6 screen. |
| **Profile** `/owner/profile` | The owner can edit their first name, last name and email. Changing the email sends a new verification link. The mobile number is the login and is shown read-only. |

Visit request rules (the owner's side):

| Current status | Owner can |
|---|---|
| Waiting (pending) | Accept · Decline · Propose new time |
| Rescheduled | Propose another time · Cancel (the visitor confirms the new time in Phase 9) |
| Accepted | Mark completed · Propose new time · Cancel |
| Declined / Completed / Cancelled | nothing (final) |

**Ownership and status checks:**
- Every query is limited to the logged-in owner's ID from the session, never an ID from the URL. Opening or changing another owner's listing, enquiry or visit request returns "not found" and changes nothing.
- Inactive or suspended owners can view the dashboard but can't change anything or start a listing.
- Blocked owners are signed out.

No database changes were needed in this phase.

## How it was tested

`tests/phase3_owner_test.php`: **60 of 60 pass**. Phase 2's 72 checks still pass.

The tests cover:
- The dashboard counts, including that deleted listings don't count toward the limit.
- Property 4 being blocked at 3 / 3.
- Validation of the limit request, the request reaching the admin, and a second request being blocked while one is pending.
- Posting being allowed again after the limit was raised to 5.
- Enquiry status, notes, tabs and search.
- Rescheduling (a past date is rejected), accepting, completing, and blocked status changes.
- Pause and resume, including that an owner can't resume a listing the admin paused.
- Profile email change and duplicate email.
- Notifications.
- Inactive and blocked owners.
- Six attempts to read or change another owner's data, all refused.

The pages were checked at 390 px phone width and 1280 px desktop width, with no sideways scrolling.

## How you can test it

1. Upload the files and open `/owner` while logged in.
2. To see it with data before Phase 4 exists, use a **development** copy: run `php bin/seed-demo.php` and log in as `9000000001` / `Demo@12345`. Rahul has 3 listings, 1 enquiry, 1 visit request, a pending rent increase and a limit request. Remove the demo data with `php bin/purge-demo.php` before going live.
3. Try these:
   - **Add Property** at 3 / 3 shows the limit message. Send a limit request.
   - In phpMyAdmin, set `users.property_limit` to 5 for that user. **Add Property** now says 2 left. (The admin screen for this comes in Phase 6.)
   - Change an enquiry's status to Contacted and add a note.
   - On a visit request, propose a new time, or accept it and then mark it completed.
   - Pause a listing, then resume it.
   - Change the user's `status` to `inactive`. The dashboard still opens, but saving anything shows the account notice.
