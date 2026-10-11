<?php
/**
 * Copy this file to config/env.php and fill in real values.
 *
 * config/env.php holds every secret. It sits OUTSIDE public_html on
 * Hostinger, is listed in .gitignore and must never be committed.
 * Non-secret, admin-editable options live in the `settings` table.
 */
return [
    // development | production
    'APP_ENV'   => 'production',
    // Never true in production: raw errors would be shown to visitors.
    'APP_DEBUG' => false,
    // Full public URL, no trailing slash.
    'APP_URL'   => 'https://www.example.com',
    // 64 random hex chars. Generate with: php -r "echo bin2hex(random_bytes(32));"
    'APP_KEY'   => '',

    // Absolute path of the web root, for CLI/cron scripts. Leave empty for the
    // standard layout (public_html next to rental-app): it is found by itself.
    // Only set it if public_html is somewhere else,
    // e.g. '/home/u123456789/domains/example.com/public_html'
    'PUBLIC_PATH' => '',

    // Hostinger: hPanel → Databases → MySQL Databases
    'DB_HOST'     => 'localhost',
    'DB_PORT'     => 3306,
    'DB_NAME'     => '',
    'DB_USER'     => '',
    'DB_PASSWORD' => '',

    // Google Maps browser key: the posting form's map (search a place, drop a pin)
    // and the small map on each listing. Leave '' to show a coordinates box instead.
    // In Google Cloud Console enable "Maps JavaScript API" and "Places API (New)",
    // and restrict the key: Application restrictions → Websites →
    // https://www.example.com/*  (plus http://127.0.0.1:8088/* while testing).
    'GOOGLE_MAPS_BROWSER_KEY' => '',

    // Email. MAIL_DRIVER: smtp (production) | log (development: written to storage/logs/mail-*.log)
    // SMTP details: hPanel → Emails → your mailbox → Configuration
    'MAIL_DRIVER'     => 'smtp',
    'MAIL_HOST'       => 'smtp.hostinger.com',
    'MAIL_PORT'       => 465,
    'MAIL_ENCRYPTION' => 'ssl',
    'MAIL_USERNAME'   => '',
    'MAIL_PASSWORD'   => '',
    'MAIL_FROM'       => '',
    'MAIL_FROM_NAME'  => '',

    // SMS OTP provider: log (writes codes to storage/logs, development only) | msg91 | twofactor
    'SMS_DRIVER'      => 'log',
    'SMS_API_KEY'     => '',
    'SMS_SENDER_ID'   => '',
    'SMS_TEMPLATE_ID' => '',

    // Turns on the setup page https://your-domain/setup (readiness check,
    // migrations, first admin) for Hostinger plans without SSH. At least 24
    // characters; generate one like APP_KEY. Set it back to '' when setup is
    // finished: the page then no longer exists.
    'SETUP_TOKEN' => '',
];
