# client/

This site has no separate front-end app. Pages are rendered on the server by
PHP (`server/views/`) and the browser files live in the web root that Nginx
serves:

| What | Where |
| --- | --- |
| Page templates (HTML) | `server/views/` |
| Our CSS | `server/public/assets/css/app.css` |
| Our JavaScript | `server/public/assets/js/*.js` (`app.js`, `listings.js`, `property-map.js`, `property-wizard.js`) |
| Bootstrap 5.3.3 (self-hosted) | `server/public/assets/css/bootstrap.min.css`, `server/public/assets/js/bootstrap.bundle.min.js` |

After editing CSS or JS, run `php bin/build-assets.php` in `server/` (the VPS
deploy script does this too). No Node.js or npm is needed.

`client/src/` is kept for a future separate front end (for example a mobile
app or a React/Vue client talking to a JSON API). Nothing reads it today.
