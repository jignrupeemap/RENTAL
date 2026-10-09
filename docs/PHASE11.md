# Phase 11 — Homepage slider

## What was built

Admins can now manage the large picture slider at the top of the homepage (spec 19 and 36) without touching any code.

### Admin → Homepage Sliders
- **List of slides:** a thumbnail, title, subtitle and button for each slide, plus its status: **Live**, **Inactive** or **Scheduled**.
- **Add and edit a slide:**
  - **Image:** required for a new slide. It must be a wide (landscape) picture at least 1200 pixels wide.
  - **Title** (up to 120 characters) and **subtitle** (up to 255).
  - **Button text and link:** both or neither. The link can be a page on this site, such as `/rent`, or a full `https://` address.
  - **Optional start and end dates** in India time. A slide shows from the start of its first day to the end of its last day.
- **Order:** the ↑ and ↓ buttons move a slide up or down.
- **Activate / Deactivate** hides a slide without deleting it.
- **Delete** asks for confirmation, then removes the slide and its image files.
- **Logging:** every change is written to the admin activity log.

### Images
Slide images go through the same safe pipeline as listing photos:
- the file type is checked from its contents, not its name
- the image is re-saved, which removes hidden data such as GPS location
- three sizes are made (480, 960 and 1600 px), each as JPEG and the smaller WebP

Phones download the small version and desktops the large one. Replacing a slide's image deletes the old files.

### Homepage
- **With live slides:**
  - The slider sits at the top of the homepage at about 40% of the screen height on desktop, and 32% on phones.
  - It moves on its own when there is more than one slide. It has dots and arrows; the arrows are hidden on phones.
  - Visitors who ask their device for reduced motion get no automatic movement.
  - The first image loads first; the others load only when needed, so the page stays fast.
  - Buttons that link to another website open in a new tab.
- **Search box:** the search box and categories stay right below the slider.
- **With no live slides:** the original homepage header shows, so the page never looks empty.

**Database:** no new migration. The `homepage_sliders` table has been in place since Phase 1.

## How it was tested

`tests/phase11_slider_test.php` has **56 checks, and all pass**. They cover:
- **Access:** guests and non-admins cannot open the slider pages.
- **Validation:**
  - an image is required
  - small, portrait or non-image files are refused
  - text that is too long, unsafe links (such as `javascript:` or `//other-site`), a button with only text or only a link, and an end date before the start date are all refused
- **Create and edit:** the image sizes and WebP are made, and replacing the image deletes the old files.
- **Order, activate/deactivate and delete:** delete also removes the files.
- **Scheduling:** a future slide shows as Scheduled and is hidden, and an expired slide is hidden.
- **Homepage:**
  - only live slides show, in order
  - the text is escaped against XSS
  - external links get a new tab
  - the original header shows when no slides are live
- **Admin activity log entries.**
- **Security:** the form is protected against cross-site requests (CSRF).

During the test run, any existing slides are hidden and then put back exactly as they were.

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

## How you can test it

1. Upload the files. There is no migration this time.
2. Log in as an admin and open **Homepage Sliders** in the admin menu.
3. Press **Add slide** and choose a wide photo, at least 1200 px wide. Add a title, a subtitle, the button text **Browse rentals** and the link **/rent**.
4. Open the homepage. The slide appears at the top, and its button goes to the rent listings.
5. Add a second slide. The slider now moves on its own, with dots and arrows.
6. Use ↑ and ↓ to change the order, and **Deactivate** to hide a slide.
7. Give a slide a start date of tomorrow. It shows as **Scheduled** and is not on the homepage yet.
8. Try a tall (portrait) photo. It is refused with a clear message.
9. Deactivate or delete every slide. The homepage goes back to its normal header.
