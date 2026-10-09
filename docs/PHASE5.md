# Phase 5 — Photo and video upload

## What was built

Step 6 of the posting form (**Photos & Video**) now takes real uploads.

**Photos.**
- Owners can pick several photos at once. Each one gets its own progress bar.
- Before uploading, the browser shrinks each photo to 2000 px on its longest side, so a 5 MB phone photo uploads as a few hundred KB.
- The server then makes three sizes of every photo, each as both JPEG and WebP:

| Size | Longest edge | Used for |
|---|---|---|
| thumb | 480 px | listing cards, admin lists |
| md | 960 px | detail page on phones |
| lg | 1600 px | detail page on desktop |

- Small photos are never stretched.
- Sideways phone photos are turned the right way up.
- All hidden photo data (EXIF) is removed, including the **GPS position** phones store. A listing photo never reveals where the owner was standing.
- Files are saved with random names in `public/uploads/properties/YYYY/MM/`.

**Managing photos.** Each photo has **←**, **→**, **Make cover** and **Delete**.
- The first photo becomes the cover automatically.
- If the cover is deleted, the next photo takes its place.
- Up to **20 photos** per listing (admin setting `max_images_per_property`).

**Video.**
- One video file per listing, **MP4 or WebM**, up to **50 MB** (setting `max_video_upload_mb`).
- Uploading a new video replaces the old one.
- iPhone MOV files are refused with a message asking for MP4 or WebM, or a YouTube link instead.
- The YouTube link field from Phase 4 is still there.

**Photos are now required.** A listing can't move past step 6, or be submitted, without at least one photo. Save Draft still works without one.

**Preview and detail page.**
- The preview shows a photo gallery and the video player.
- Images use WebP where the browser supports it and fall back to JPEG.
- Photos below the first load lazily, with width and height set so the page doesn't jump.

**What is refused** (the owner sees a plain message for each):
- Any file that isn't really a JPEG, PNG or WebP. The file's content is checked, never its name, so a PHP script renamed to `.jpg` is refused.
- Photos under 600 px or over 30 megapixels.
- Photos over 10 MB (setting `max_image_upload_mb`).
- A 21st photo.
- A video that isn't really MP4 or WebM, or is over the size limit.
- An upload bigger than the server allows. This now shows "File too large" instead of a confusing "page expired" error.

**Security.**
- Every upload, cover change, move and delete checks that the listing belongs to the logged-in owner.
- Suspended and inactive accounts can't upload.
- Uploads are rate-limited to 300 an hour per account.
- Every form has a CSRF token.
- The uploads folder already blocks PHP from running (`.htaccess` from Phase 1).

**Deleting.**
- A deleted photo or video is soft-deleted, so the database row stays.
- A nightly cron job removes the files 30 days later, keeping disk use down on Hostinger.
- `bin/purge-demo.php` now also removes demo photos.

**Editing a live listing.** Adding, deleting or reordering photos counts as an owner edit. If "Re-approve after owner edits" is on, the listing goes back to Pending, the same as for other edits.

**Database:** no new migration. Phase 1 already created the `property_media` table.

## How it was tested

`tests/phase5_media_test.php` has **59 checks, and all pass**.

What the tests cover:
- **Sizes.** All three sizes are made at the right dimensions, and WebP is served.
- **Photo data.** A sideways photo with GPS data comes out upright, with no EXIF left.
- **Refused files.** PHP disguised as a JPG, a text file, a tiny photo, a 35 MP photo and an oversized file are all refused.
- **Management.** Batch upload, upload with no file, missing CSRF token, cover, move, delete, the 20-photo limit and the 30-day cron purge.
- **Video.** MP4 and WebM are accepted. MOV, a fake MP4 and an oversized video are refused. Replace and delete work.
- **Permissions.** Another owner can't upload to, reorder or delete your photos, and a suspended owner can't upload.
- **Rules.** Step 6 needs a photo, submit is blocked without photos, the preview markup is correct, and re-approval works.
- **Over-size posts.** A post bigger than the server limit gets a clear message.

The Phase 4 suite now uploads a photo before step 6 and has **131 checks**. Phases 1–4 all still pass.

I also tried it in a real browser at phone (390 px) and desktop (1280 px) width:
- Three photos uploaded with progress bars.
- A 346 KB photo was shrunk to 136 KB in the browser and stored at 1600×1200.
- There was no sideways scrolling.

## Hostinger setting to check

PHP's own upload limits must be at least as large as the video limit. In **hPanel → Advanced → PHP Configuration → PHP options**, set:
- `upload_max_filesize` = **64M**
- `post_max_size` = **70M**

If they are lower, the app automatically uses the lower value and tells owners the real limit.

## How you can test it

1. Upload the files. There is no migration this time.
2. Log in and open a draft in **My Properties**, or start a new one, then go to **Step 6 Photos & Video**.
3. Try these:
   - Pick 3–4 phone photos at once. Each shows a progress bar, and the first becomes the cover.
   - Use **→** and **Make cover**, then **Delete** the cover. The next photo becomes the cover.
   - Press **Save & Continue** with no photos. It asks for at least one.
   - Rename a `.txt` or `.pdf` file to `.jpg` and upload it. It is refused.
   - Upload a short MP4 video, then upload another. The second replaces the first.
4. Go to **Step 8 Preview**. The gallery and video show.
5. Optional: download one of the stored photos from `public/uploads/properties/…` and check its properties. There is no camera or location data.
