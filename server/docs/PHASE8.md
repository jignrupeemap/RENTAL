# Phase 8 — Viewer dashboard

## What was built

Anyone with an account now has **My Account** (`/account`). It has its own menu with these pages:
- My Dashboard
- Shortlisted Properties
- Contacted Properties
- Booking / Visit Requests
- Profile
- Owner Dashboard
- Logout

On phones the menu becomes a row of tabs you can swipe.

### My Dashboard
- **Counts:** shortlisted, contacted, visit requests (with how many wait for the owner) and upcoming visits. Every number comes from the database.
- **Recent items:** the latest shortlisted properties, open visit requests and enquiries, each with a **View all** link.
- **Notifications:** for example, when an owner accepts or reschedules a visit. **Mark all read** clears them. A notification only links to pages on this site.
- **Account notices:** a notice appears if the account is suspended or the mobile still needs verifying.
- **"Own a property?" card:** links to the Owner Dashboard and Post Property.

### Shortlisted Properties (spec 24)
- **Each row shows:** photo, title, type, location, rent, and the date it was shortlisted.
- **Live listings** have **View Property**. A listing that is paused, expired or removed stays in the list with a label such as "Paused by the owner", so the history never breaks.
- **Remove** works on every row, including removed listings, and keeps the listing's shortlist count correct.

### Contacted Properties (spec 25)
- **Each enquiry shows what the viewer saw when they sent it:** title, owner name, rent and their message. It also shows today's rent if it has changed since, and the listing's current state.
- The enquiry form itself arrives in Phase 9. Until then this page lists enquiries already in the database.

### Booking / Visit Requests (spec 28)
- **Tabs:** All, Open and Past, each with a count.
- **Each request shows:**
  - the preferred time
  - the owner's proposed new time, when they rescheduled
  - the owner's note
  - your message
  - a **History** trail of every status change
- **Actions:**
  - **Cancel request**, while the request is pending, rescheduled or accepted.
  - **Accept new time**, when the owner proposed one. The visit becomes accepted at that time.
  - Either action notifies the owner.
- **Safety:**
  - A request can only be changed by the person who made it.
  - An action that no longer fits the request, such as cancelling a rejected request, is refused.
  - Suspended accounts can read this page but cannot change anything.

### Profile
- **Edit:** first name, last name and email.
  - Changing the email sends a new verification link.
  - An email already used by another account is refused.
- **Mobile number:** it is the login, so it is shown but cannot be changed here.
- **Change password:** asks for the current password. Other devices are signed out. The password is stored only as a hash.
- The owner's profile page uses the same code.

**Database:** there is no new migration. Every table used was in the Phase 1 schema.

## How it was tested

`tests/phase8_viewer_test.php` has **68 checks, and all pass**. They cover:
- Every /account page needs a login.
- Counts match the database.
- **Notifications:** only your own are shown, off-site links are dropped, and Mark all read works.
- **Shortlist:** live, paused and removed rows are labelled correctly, Remove works and keeps the count in step, and off-site "return" links are refused.
- **Contacted:** the snapshot and the current rent are shown, and you see only your own enquiries.
- **Bookings:**
  - tabs and history
  - accepting a new time, including twice in a row
  - cancelling
  - actions that don't fit the status
  - another user's booking id
  - a missing CSRF token
  - a suspended account
- **Profile:**
  - errors are shown
  - a duplicate email is refused
  - the mobile and a posted user id are ignored
  - a new email must be verified again
- **Password:** a wrong current password is refused, and a successful change keeps you logged in.

The earlier suites all still pass:
- Phase 1: OK
- Phase 2: 72
- Phase 3: 60
- Phase 4: 131
- Phase 5: 59
- Phase 6: 141
- Phase 7: 78

I also checked every page in a real browser at phone and desktop width. There is no sideways scrolling.

## How you can test it

1. Upload the files. There is no migration this time.
2. Log in as a viewer, or use the demo viewer **9000000002** / **Demo@12345** on the test copy.
3. Open **My Account** from the top menu.
4. Shortlist a property, then remove it from **Shortlisted Properties**.
5. In the admin panel, pause that listing. In Shortlisted Properties it now shows "Paused by the owner" instead of a link.
6. On **Booking / Visit Requests**, press **Cancel request**. Then log in as the owner: the request shows as cancelled and a notification is waiting.
7. As the owner, reschedule a visit. As the viewer, press **Accept new time**.
8. On **Profile**, change your name and email, then change your password.
