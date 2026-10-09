# Phase 9: Contact Owner, Request Visit and Post your requirement

## What was built

### Property page buttons
The property page now has three buttons: **📞 Contact Owner**, **📅 Request Visit** and **❤️ Shortlist**.
- **Visitors who are not logged in** are sent to the login page, then brought straight back to the form.
- **The owner** never sees these buttons on their own listing.
- **After you contact an owner,** the page says "You contacted the owner on …".
- **While you have an open visit request,** the visit button changes to "Visit requested for 12 Oct" and links to it.
- **The owner's name, mobile number and email are still never shown** on the public page.

### Contact Owner (spec 25, 26)
- **Form:** first name, last name, mobile, alternate mobile (optional), email (optional) and a message.
  - Your details from My Account are filled in already.
  - The form follows the Inquiry Form sheet.
- **The listing's details are attached by the server, not taken from the form.** This means nobody can change them by editing the page. They are:
  - property ID, title, link and owner
  - rent at the time
  - purpose, category, city and locality
  - a snapshot of the main facts (BHK, area, furnishing, deposit, maintenance)
- **After sending:**
  - The enquiry appears in the owner's **Enquiries** inbox and in your **Contacted Properties**.
  - A contact-history record is saved.
  - The listing's enquiry count goes up by one.
  - The owner and the admin each get a notification.
  - The owner gets an email only if **Email notifications** is switched on in Settings. It goes to the listing's contact email, or to their account email if the listing has none.
- **The owner's phone number is not revealed.** The owner gets your number and contacts you. I chose this as the safer default for owner privacy. Tell me if you want the owner's number shown after an enquiry is sent.
- **Limits:**
  - One message per listing every 24 hours.
  - 20 messages an hour per account.
  - Suspended accounts see an explanation instead of the form. Blocked accounts are signed out.
  - A listing that is paused, expired, deleted, or whose owner is suspended cannot be contacted. Its page returns "no longer available".

### Request Visit (spec 28)
- **Choose:**
  - a date, from today up to 60 days ahead
  - a time slot, every 30 minutes from 8:00 AM to 8:00 PM, India time
  - a message (optional)
- **Rules:**
  - A visit for today must be at least an hour from now.
  - You can have only one open request per listing. To change the time, cancel the old request in My Account first.
  - 10 requests an hour per account.
- **What happens:**
  - The request is created as **Pending**, with its first history line.
  - The owner and the admin are notified, and the owner is emailed if emails are on.
  - You land on **My Account → Booking / Visit Requests**.
- **From then on, the existing flows take over:**
  - The owner accepts, declines, reschedules or completes (Phase 3).
  - You cancel or accept the owner's new time (Phase 8).
  - Both of you see the full history.

### Post your requirement (`/post-requirement`, spec 26 Inquiry Form)
- **Who can use it:** anyone, including visitors who are not logged in.
- **Where it's linked:** the footer of every page, and the "No properties match" message on search pages.
- **Fields:**
  - name, mobile, alternate mobile and email
  - **Interested in:** Rent, Lease or PG
  - **Property type:** Residential, Commercial or Industrial
  - budget
  - city and location (locations follow the chosen city)
  - remarks
- **Spam protection:** a hidden trap field for bots, and 5 requests an hour per visitor.
- **Admin follow-up:**
  - The admin is notified.
  - The new **Admin → Enquiries → Requirements** tab lists these requests with what the visitor wants.
  - The admin can now set the status (New, Contacted, In progress, Closed) on any enquiry. Each change is written to the activity log.
- Logged-in users also see their requirements in **Contacted Properties**.

### Other changes
- robots.txt keeps the `/property/…` form pages out of search engines. The form pages are also marked noindex.
- New email templates: `new-enquiry` and `new-booking`.

**Database:** there is no new migration. The enquiries, bookings, contact_history and notifications tables were already in the Phase 1 schema. New rate limits were added in `config/app.php`.

## How it was tested

`tests/phase9_contact_test.php` has **83 checks, and all pass**. They cover:
- **Buttons:** what guests, the owner and viewers each see, and that owner details are never on the page.
- **Contact Owner:**
  - validation and error messages
  - attempts to change the owner, title, rent or user id from the form are ignored
  - mobile numbers are normalised
  - the snapshot, contact history, count, notifications, email and activity log are all saved
  - the 24-hour repeat rule and the hourly limit
  - paused listings, listings whose owner is suspended, and the owner's own listing
  - missing CSRF token
  - suspended and blocked viewers
  - the owner's inbox
  - Contacted Properties keeps the old rent after a rent change
- **Request Visit:**
  - six kinds of bad date or time
  - "today, at least an hour from now"
  - the saved booking and its history, count and notifications
  - only one open request per listing
  - a round trip: owner reschedules, viewer accepts, viewer cancels, and the button comes back
  - paused listings
- **Requirement form:**
  - bad choices are refused
  - the bot trap works
  - a guest request is saved
  - the per-visitor limit
  - a logged-in request is linked to the account
  - the admin Requirements tab and status changes, with logging
  - non-admins are refused

The earlier suites all still pass:
- Phase 1: OK
- Phase 2: 72
- Phase 3: 60
- Phase 4: 131
- Phase 5: 59
- Phase 6: 141
- Phase 7: 78
- Phase 8: 68

I also checked every new page in a real browser at phone and desktop width. There is no sideways scrolling.

## How you can test it

1. Upload the files. There is no migration this time.
2. Log out and open any property. Press **Contact Owner**. You are asked to log in, then land on the form.
3. Log in as a viewer, or use the demo viewer **9000000002** / **Demo@12345**. Send a message.
4. Log in as that listing's owner, or use the demo owner **9000000001**. Open **Enquiries** in the owner dashboard. The message is there with the viewer's mobile number.
5. As the viewer, press **Request Visit**, pick a date and time, and send it.
6. As the owner, open **Booking / Visit Requests** and reschedule it.
7. As the viewer, open **My Account → Booking / Visit Requests** and press **Accept new time**.
8. Open **Post your requirement** from the footer while logged out, and send it.
9. In the admin panel, open **Enquiries → Requirements** and set its status to **Contacted**.
10. Optional: switch on **Email notifications** in Admin → Settings, with SMTP set up. Owners then also get an email for each enquiry and visit request.
