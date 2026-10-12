# Google Maps: location picker and listing map

Added 2026-10-11, after the 15 phases.

## What the owner sees (posting form, step 3)
- **Search box** (Places Autocomplete, results limited to India). Picking a result:
  - moves the map and the pin to that place;
  - fills the empty boxes above: building name (for a building or business), address line, pincode, and selects the city and locality when they match the site's lists. An area that isn't in the list becomes "My area is not listed" + its name. Boxes the owner already typed in are left alone.
- **Click the map** to drop the pin; **drag the pin** to fine-tune. A new listing has no pin until the owner picks a spot, so the default city view is never saved by mistake.
- **Use my current location** and **Enter coordinates instead** still work, and are what appears if Maps can't load (no key, wrong key, website not allowed on the key, or the script is blocked).
- The coordinates box is what the server saves, so the form works the same with or without JavaScript.

## What visitors see (listing page, owner preview, admin view)
A small map under **Location**, loaded only when it scrolls into view:
- approximate privacy (default): a 500 m shaded circle around the public point, which is itself 150-400 m from the real spot, so the property is inside it without being pinpointed;
- exact privacy: a pin.
If the map can't load, the box is removed. If it loads but its tiles never arrive (a grey box), the browser console (F12) shows a "Listing map:" warning after 15 seconds.

**View on Google Maps** on the public page does not open Google Maps (added 2026-10-12): it shows "Contact/whatsapp on *support phone* for location and more detail", with call and WhatsApp links. The number is the **Support phone** in Admin → Settings → General (migration 0008 sets it to 9925133440 if it was empty). The owner's preview and the admin view keep the real Google Maps link.

## Setup
`GOOGLE_MAPS_BROWSER_KEY` in `config/env.php` (never in git or the release zip). Enable **Maps JavaScript API** and **Places API (New)**, and restrict the key to your website. Steps: [DEPLOYMENT.md §4.3](DEPLOYMENT.md#43-google-maps).

## Files
| File | Change |
|---|---|
| `public/assets/js/property-wizard.js` | Step 3 map: search fills address fields, click-to-pin, pin created on first pick, works with `Marker` or `AdvancedMarkerElement`, search error message. |
| `public/assets/js/property-map.js` | New: lazy listing map (circle or pin). |
| `views/owner/wizard/step3.php` | Help text and search error note. |
| `views/partials/property-detail.php` | Map box + script when a key and a location exist. |
| `app/Helpers/functions.php` | `maps_browser_key()`. |
| `.gitignore` | `storage/sessions/.gitkeep` is now committed, so a fresh clone has the folder. |

## Tested
In a browser against the live Google APIs with the real key: the map loads on step 3 and on a listing page (approximate circle drawn), clicking the map sets the coordinates, and the search box shows its error note when Places API (New) is off. Not verified here: picking a search result (needs Places API (New) enabled) and dragging the pin (the test network blocked Google's pin image). All 15 phase suites still pass (phase 7 has one shortlist check that also fails on the code before this change).
