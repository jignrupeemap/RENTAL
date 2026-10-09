# Phase 10 — Rental change alerts

## What was built

Most of the rent change system was built earlier, alongside the edit form (Phase 4) and the admin panel (Phase 6). That covers:
- detecting a rent change
- the approval queue
- the full alert page with Approve and Reject

Phase 10 finishes spec 29–31, and the "Rental changed" emails from spec 52. It also tests the whole flow from start to finish.

### RENTAL CHANGE ALERT on the admin dashboard (spec 30)
- **Where:** a new **Rental change alerts** section sits right under the Alerts counts.
- **What each card shows:**
  - owner and mobile (tap to call)
  - property
  - old rent, new rent, and the increase or decrease
  - date and time of the change (India time)
  - the buttons **Review**, **VIEW PROPERTY**, **VIEW OWNER** and **VIEW CHANGE HISTORY**
- **Which changes appear:**
  - Changes **waiting for approval** come first, oldest first.
  - Changes the owner made that went live without approval stay for **7 days**. These are decreases, or any change when approval is off.
- **All rental changes** links to the full list.

### Email alerts (when switched on)
These emails are sent only when **Email notifications** is on in Admin → Settings and SMTP is set up. The dashboard and the in-app notifications work either way.
- **To every active admin:** an email copy of the RENTAL CHANGE ALERT, with the same details and the same four links.
- **To the owner:** an email when their change is **approved** or **not approved**, including the admin's note.

### How the rules work (already in place, now tested end to end)
- **Detection:**
  - Saving the rent step with the **same rent** creates no record and sends no alert.
  - A **different rent** creates a record with every spec 29 field: property, link, owner name, mobile, email, old rent, new rent, difference, time, who changed it, IP address and status.
- **Admin setting "Rent change approval":**
  - **Increases need approval** (default): decreases go live at once, and increases wait.
  - **Every change needs approval.**
  - **Every change goes live at once:** the admin is still alerted.
- **While a change waits:**
  - The **old rent stays live** everywhere, including the listing page, search and enquiries.
  - The owner sees "₹22,000 waiting for approval".
- **If the owner changes their mind:**
  - Entering a new amount replaces the waiting request.
  - Setting the rent back to the live amount withdraws the request, and the admin is told.
- **Approving and rejecting:**
  - **Approve:** the new rent goes live, and the property address (URL) stays the same.
  - **Reject:** a reason is required, and the old rent stays live.
  - A change that has been decided cannot be decided again.
  - Every decision is in the admin activity log.
- **Who can change rent:**
  - Only the owner can change their listing's rent. A suspended account cannot.
  - Extra form fields cannot skip approval.
  - Owners cannot approve their own changes.

**Database:** there is no new migration.

**Test clean-up fix:** the test suites now also remove the "New registration" alerts for their own test accounts. Before this fix, those alerts were left on the dashboard of the development copy.

## How it was tested

`tests/phase10_rent_alert_test.php` has **42 checks, and all pass**. They cover:
- **Detection:** same rent, decrease and increase.
- **The record:** every spec 29 field.
- **Alerts:** the notification text and link, and the admin email with its links.
- **While waiting:** the old rent stays on the public page and in search, and the owner sees the waiting amount and their history.
- **The dashboard card:** every field, all four links, and that each link opens.
- **Replacing and withdrawing a request.**
- **Decisions:**
  - reject without a reason is refused
  - reject and approve each send the owner a notification and an email
  - a decided change cannot be decided again
  - the public price after approval
- **All three approval modes.**
- **The 7-day window on the dashboard.**
- **Emails switched off.**
- **Rules:** another owner, extra form fields, suspended owner, and an owner trying to approve.

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

## How you can test it

1. Upload the files. There is no migration this time.
2. Log in as an owner (demo **9000000001** / **Demo@12345**). Edit an active listing, go to the **Rent** step and raise the rent.
3. Open the **admin dashboard**. The **Rental change alerts** card shows the owner, mobile, old and new rent, the increase and the time. Try each button.
4. Press **Review**, then **Reject** with a reason. The old rent stays on the website.
5. As the owner, raise the rent again. As the admin, press **Approve**. The new rent appears on the property page.
6. In **Admin → Settings**, try the other "Rent change approval" options.
7. Optional: switch on **Email notifications**, with SMTP set up. Admins then get the alert by email, and owners get the decision by email.
