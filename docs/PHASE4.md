# Phase 4 — Property posting form

## What was built

**Add Property** (`/owner/properties/new`) now opens an 8-step form. Each step is saved to the database as it is completed, so a refresh or a closed tab loses nothing.

| Step | What the owner sees |
|---|---|
| 1 Property Type | Rent / Lease / PG → Residential / Commercial / Industrial → sub-type. Only the matching sub-types show. PG offers Flat and Bungalow only. |
| 2 Property Details | Only the fields your sheet lists for that type. Examples: bedrooms, bathrooms and balconies for a Flat; area in Sq Ft or Sq Yard for a Shop; no furnishing for plots and industrial types; Available for (Boys / Girls / Any) and Suitable for (Students / Professionals / Both) for PG. |
| 3 Location | The unit label follows the type: Flat No, Bungalow No, Shop No, Office No or Plot No. Building name shows only where the sheet has it. Landmark is required for Industrial. City and locality (the localities follow the city, and the pincode fills in). There is a "My area is not listed" option where the owner types the area. Map pin, with **Approximate** (default) or **Exact** shown publicly. |
| 4 Facilities | Only the facilities for that type. Examples: Personal Washroom and Power Backup for Commercial; Electricity Backup and RCC Road for Industrial; Pooja, Servant and Study Room, Play Area and Garden for Residential and PG. PG also has Food, Laundry, Car Washing and Other, each with an optional monthly price. |
| 5 Rent | Monthly rent, security deposit, maintenance and other charges, where they apply. |
| 6 Photos & Video | YouTube video link. Photo and video-file upload is **Phase 5**, so photos are not yet required to submit. |
| 7 Owner Details | Contact name, two mobile numbers and an email, filled in from the account. |
| 8 Preview & Submit | The listing as visitors will see it, with an **Edit** link on each section, plus a list of anything still missing. Buttons: **Delete draft**, **Save Draft & exit** and **Submit for Admin Approval**. |

Every step has **Previous**, **Save Draft** and **Save & Continue**, and the top shows "Step 3 of 8" with a progress bar.

- **Save Draft** keeps a half-filled step. Wrong values are still refused.
- **Save & Continue** checks every required field.

**Drafts.** My Properties shows "Continue draft (Step N of 8)" and "Delete draft" for each draft. A deleted draft is soft-deleted and frees a slot in the limit.

**Limit and the owner role.**
- The 3-listing limit is checked again when step 1 is saved. A database lock stops two browser tabs from both getting past it.
- Drafts count toward the limit.
- An account becomes an Owner when it starts its first listing.

**Submitting.** The listing becomes **Pending approval**. The admin gets a "New property submitted" notification, and the change is recorded in the status history and activity log. A Rejected or "Changes requested" listing shows the admin's note and a **Resubmit** button.

**Editing a submitted or live listing.**
- My Properties now has **Edit** and **Preview** buttons. Changes to a live listing go live and are logged.
- If the admin setting "Re-approve after owner edits" is on, an edited listing goes back to Pending.
- The property type cannot be changed after submission.
- A live listing can't be saved with a required field left empty.

**Rent changes**, once a listing has been published (setting `rent_change_approval_mode`, default *increase_only*):

| Owner saves | Result |
|---|---|
| Same rent | Nothing recorded, no alert |
| Lower rent | Goes live at once. The change is logged, and the admin gets a "RENTAL CHANGE ALERT". |
| Higher rent | Waits for admin approval and the old rent stays live. It is logged with the owner's name, mobile and email, the property link, the IP address and the time. The admin gets a "RENTAL CHANGE ALERT: … ₹21,000 → ₹23,000 (+₹2,000) · Waiting for your approval". |
| Another rent while one is waiting | The waiting change is replaced. |
| The live rent again | The waiting change is withdrawn. |

The mode can also be *all* (every change waits) or *none* (every change goes live, still logged).

Admin **approve or reject** of rent changes and listings is built in Phase 6 (admin panel), with the alert screens in Phase 10. Until then, the queue fills up correctly but nobody can act on it.

**Map.**
- With `GOOGLE_MAPS_BROWSER_KEY` set in `config/env.php`, step 3 shows Google Places search and a map pin the owner can drag. The latitude, longitude, formatted address and place id are saved.
- With no key, or if Maps fails to load, the owner pastes coordinates from Google Maps or taps **Use my current location**.
- The approximate pin is moved a fixed 150–400 m and stays at the same spot each time.
- To enable the map, create the key in Google Cloud with **Maps JavaScript API** and **Places API (New)** turned on, and restrict it to your domain.

**Security.**
- Every page and save loads the listing with the logged-in owner's id, never trusting the id in the URL.
- The form saves only the fields the map allows for that type. Status, owner, display rank and featured flag can never be set from it.
- Facility and service ids outside the type's list are dropped.
- Output is escaped, and every form has a CSRF token.
- Inactive and suspended accounts cannot open or save the form.

**Database:** migration `2026_10_08_000004_posting_wizard.sql` adds `properties.locality_name`, used when the owner types an area that is not in the list. It doesn't change any existing data.

## How it was tested

`tests/phase4_wizard_test.php` has **130 checks, and all pass**. Phase 2 (72) and Phase 3 (60) still pass. The Phase 3 check for the "posts left" text was updated to the new wording.

What the tests cover:
- **Step 1.** Rent, Lease and PG; Residential, Commercial and Industrial; Flat, Shop, Office, Factory, Commercial Plot and PG Flat. Wrong combinations are refused.
- **Validation.** Required fields, wrong dates, BHK values, pincodes, coordinates outside India, rent like "abc" or below ₹100, non-YouTube links, bad mobiles and a duplicate second mobile.
- **Drafts.** Save Draft vs Save & Continue, the step-skip guard, and switching type on a draft (Flat → Office clears the bedroom data and the Pooja Room facility).
- **Location.** The approximate pin offset, exact privacy and a typed area name.
- **Preview.** It escapes a `<script>` typed into the description.
- **Submit and editing.** Submit, resubmit after rejection, editing a live listing, and re-approval.
- **Rent changes.** Every row of the rent table above, in all three modes.
- **Limits and permissions.**
  - The 4th listing is blocked at 3/3, a deleted draft frees a slot, and only drafts can be deleted.
  - Six attempts by one owner to open, save, submit, delete or retype another owner's listing were all refused.
  - Inactive accounts are blocked, and saving without a CSRF token is refused.

I also filled in the form in a real browser at phone (390 px) and desktop (1280 px) width:
- No sideways scrolling.
- The sub-type and locality filtering works.
- The pincode fills in automatically.
- With a Maps key set but Maps unreachable, the coordinates box takes over.

The live Google map itself could not be tried here (this test environment has no internet access to Google), so please check step 3 once your key is in place.

Testing note: when the Phase 2 tests run back-to-back in this shared project folder, they sometimes miss SMS codes in the log file. The folder can drop lines from the dev log, so this is a test-environment issue, not an app one. A single run passes 72/72.

## How you can test it

1. Upload the files and run the migration: `php bin/migrate.php`, or `/setup/migrate?token=…`.
2. Log in and open **My Account → Owner Dashboard → Add Property**.
3. Try these:
   - **Rent › Residential › Flat**: go through all 8 steps and press **Submit for Admin Approval**. The listing shows as *Pending approval* in My Properties.
   - **Commercial › Shop**: step 2 asks for area instead of bedrooms, and step 3 says "Shop No".
   - **Industrial › Factory**: step 3 needs a landmark, and step 4 offers Electricity Backup and RCC Road.
   - **PG › Flat**: step 2 asks Available for / Suitable for, and step 4 has Food / Laundry with prices.
   - Fill step 2 halfway, press **Save Draft**, close the tab, and use **Continue draft** in My Properties.
   - Start 3 listings: the 4th shows the limit message. Delete a draft and you can post again.
4. To try a rent change before the admin panel exists: in phpMyAdmin, set a listing's `status` to `active` and `published_at` to the current date and time. Then change the rent in step 5:
   - A higher rent shows "waiting for admin approval".
   - A lower rent goes live at once.
   - Both appear under **Rental Change History**, and in the `notifications` table as RENTAL CHANGE ALERT.
5. To get the map: add your browser key to `config/env.php` as `GOOGLE_MAPS_BROWSER_KEY`. Step 3 then shows the search box and a draggable pin.
