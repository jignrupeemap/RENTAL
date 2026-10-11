# Phase 6 — Admin panel

## What was built

The admin panel lives at **/admin**. It has its own login, separate from the website login.
- A normal website login never opens an admin page.
- Only accounts with the **admin** or **super admin** role can sign in there.

On a computer, the menu sits on the left. On a phone it opens from the ☰ button. Items that need action show a yellow count.

### Dashboard
- **Alerts:** pending approvals, rental changes, limit requests, new enquiries and new booking requests. Each card opens the matching list.
- **Users:** total, active, inactive, suspended and blocked users, plus new registrations.
- **Properties:** total properties, then Rent, PG and Lease; Residential, Commercial and Industrial; then Pending, Active, Paused, Rejected/changes requested, Drafts, and posted in the period.
- **Engagement:** logins, property views, shortlists, contact owner requests, enquiries and booking requests.
- **Date ranges:** Today, This week, This month, or a custom From/To range, all in Indian time.
- **Latest alerts** and **Recent login activity**.

All numbers come from the live database. There are no sample figures.

### Properties
- **Search** by property ID, title, city, locality, or the owner's name, mobile or email.
- **Filters:** purpose, category, city, locality, status, owner, and featured only. There are 25 listings per page.
- **Property page:** the full listing preview with photos, video and amenities, the owner, status history, rent history, admin activity on this listing, and a private admin note.
- **Actions.** Only the actions that make sense for the current status are shown.

| Action | What happens |
|---|---|
| Approve | Goes live now. Expiry is set from "Listing expiry days". |
| Reject | Needs a reason. The owner sees the reason. |
| Request changes | Needs a note. The owner sees the note, fixes the listing and resubmits. |
| Pause / Activate | Hides the listing or shows it again. |
| Delete / Restore | Soft delete only; nothing is erased. Restore brings it back as paused, or as a draft if it was never live. |
| Publish | Only for listings an admin created. It goes live without the approval queue. |

- A listing can't go live while required fields are missing. The page lists what is missing, with a link to each step.
- Every action notifies the owner. It is also written to the status history and to the admin activity log, with old and new values.
- **Featured:** turn it on or off, with an optional end date.
- **Change owner:** assign the listing to any account by mobile or email, or to admin/system. The new owner is notified.

### Add or edit a property as admin
**+ Add Property** uses the same 8-step posting form as owners, with these differences:
- Step 1 asks who the listing belongs to: **admin/system** or an existing account, found by mobile or email. The owner's posting limit does not apply to admins.
- Contact details in step 7 are filled in from that owner's account.
- Admin edits to a live listing stay live and do not go back for approval.
- Admin rent changes go live at once and are logged. The owner is told.
- An owner's pending rent request is not touched unless the admin actually changes the rent.
- Step 8 has **Publish now** and **Save Draft & exit**.

### Display Order
- Choose Rent, PG or Lease to see the live listings in their current order.
- Use **↑ / ↓** to move a listing, or type position numbers and press **Save order**.
- A blank number means "no fixed position": the listing goes after the ranked ones, newest first.
- The public search page in Phase 7 will use this order.

### Rental Changes
- This is the waiting list of rent increases from owners.
- Each request opens the **Rental change alert** card. It shows the property, owner, mobile, old rent, new rent, the increase, and **VIEW PROPERTY**, **VIEW OWNER** and **VIEW CHANGE HISTORY** buttons.
- **Approve** puts the new rent live.
- **Reject** needs a note and keeps the old rent.
- The owner is notified either way.

### Limit Requests
- Owners' requests for more listings appear here.
- **Approve** with a new limit (it must be higher than the current one), or choose **unlimited**.
- **Reject** needs a note.

### Users
- Search and filter by status and role.
- The user page shows the profile, listings, login history and activity.
- **Edit profile:** name, mobile, email and city.
  - Mobile and email must stay unique.
  - A changed mobile or email has to be verified again.
- **Status:** active, inactive, suspended or blocked.
  - Anything other than active needs a reason, which the user sees.
  - Blocking signs the user out everywhere.
- **Posting limit:** set a number from 0 to 1000, or unlimited. This also approves any waiting limit request.
- **Sign out everywhere**, and **Delete/Restore**. Delete is soft: the account is blocked, hidden, and can be restored.
- **Safety:**
  - An admin cannot change their own account.
  - Only a super admin can change another admin.

### Enquiries and Bookings
- These are read-only lists across all listings.
- Filter by status, owner, user, property and date.

### Notifications
- Admin alerts such as a new listing waiting, a rent increase or a limit request.
- Click an alert to open the item. **Mark all read** is available.

### Settings
- Every website setting, grouped as General, Listings, Photos & video, Login, Notifications, Enquiries, Search and Privacy.
- Each value is checked before saving. Numbers must be in range and choices must be valid.
- Only changed values are saved, and each change is logged.
- Secrets are not in Settings and stay in `config/env.php`. That means database, SMTP, SMS and Google Maps keys.

### Activity Log
- **Admin actions:** who did what, when, from which IP, with old → new values.
- **User activity:** logins, failed logins, lock-outs and password resets.

### Not in this phase
- Home page slider: Phase 11.
- Charts: Phase 12.
- Editing cities, amenities and pages: later, with the public pages they belong to.

**Database:** there is no new migration. The Phase 1 schema already had every table and column used here.

## How it was tested

`tests/phase6_admin_test.php` has **141 checks, and all pass**. They cover:
- **Access:**
  - A website login can't open or post to admin pages.
  - A normal account is refused at the admin login.
  - Every admin page loads.
- **Approvals:** approve, reject, request changes, pause, activate, delete and restore, each with owner notifications and log entries. Actions that don't fit the status are refused. Incomplete listings can't go live.
- **Admin posting:**
  - Create for an owner beyond their limit.
  - Create as admin/system.
  - Step 7 is pre-filled from the owner.
  - Photo upload works through admin routes.
  - Publish now works.
  - Change owner works.
- **Admin edits and rent:**
  - Admin edits stay live.
  - Admin rent changes go live and are logged.
  - The owner's waiting rent request survives unrelated admin edits.
- **Rental changes:** the waiting list, the alert card and its links, approve, and reject (which needs a note).
- **Display order:** move up and down, typed positions, and blanks.
- **Featured:** on, off, and the end date.
- **Limits:** approve, reject, direct limit and unlimited.
- **Users:** search, profile edit with uniqueness checks, every status with its reason, sign out everywhere, delete and restore, and the self and super-admin protections.
- **Settings:** valid and invalid values, only changes saved, and logging.
- **Dashboard:** counts and date ranges.
- **Lists:** notifications, enquiries, bookings and the activity log.

The Phase 1–5 suites all still pass:
- Phase 1: OK
- Phase 2: 72
- Phase 3: 60
- Phase 4: 131
- Phase 5: 59

I also opened every admin page in a real browser at phone (390 px) and desktop (1280 px) width. All pages loaded with no errors and no sideways scrolling.

## How you can test it

1. Upload the files. There is no migration this time.
2. Create your admin account once, over SSH in the site folder:
   ```
   php bin/create-admin.php
   ```
   - It asks for your name, mobile, email and password. Nothing is printed or saved in a file.
   - If the account already exists, it is given the admin role.
   - If you have no SSH access, tell me and I'll add a one-time setup page instead.
3. Sign in at **yourdomain.com/admin/login**.
4. Try these:
   - **Dashboard:** switch between Today, This week and This month.
   - With a second, normal account, submit a listing. It appears under **Approvals**. Open it, press **Request changes** with a note, and check that the owner sees the note in **My Properties**. Resubmit it, then **Approve** it.
   - As the owner, raise the rent on that live listing. It appears under **Rental Changes**. Open the alert card and approve or reject it.
   - **+ Add Property:** create a listing for that owner by mobile number, fill the steps, then press **Publish now**. It shows in the owner's dashboard.
   - **Display Order:** move a listing up, or type positions, then **Save order**.
   - **Users:** suspend the test account with a reason, and try to log in as it. You can't. Then set it back to Active.
   - **Settings:** change "Listings per page" and save. The change shows in **Activity Log**.
