<?php
/**
 * Non-secret application constants. Secrets come from config/env.php,
 * admin-editable options from the `settings` table.
 */
return [
    'timezone_storage' => 'UTC',
    'timezone_display' => 'Asia/Kolkata',
    'locale'           => 'en_IN',
    'currency_symbol'  => '₹',

    'session' => [
        'name'          => 'rw_sess',
        'admin_name'    => 'rw_admin',
        'lifetime'      => 60 * 60 * 24 * 7, // site: 7 days
        'admin_idle'    => 60 * 30,          // admin: 30 min idle timeout
        'admin_max'     => 60 * 60 * 12,     // admin: signed out 12 hours after login, even if active
    ],

    'uploads' => [
        'image_mimes' => ['image/jpeg', 'image/png', 'image/webp'],
        'video_mimes' => ['video/mp4', 'video/webm'],
        // Generated sizes (longest edge, px). Originals are discarded unless
        // settings.keep_original_images is on.
        'image_sizes' => ['thumb' => 480, 'md' => 960, 'lg' => 1600],
        'jpeg_quality' => 80,
        'webp_quality' => 78,
        'min_image_px' => 600,      // longest edge must be at least this
        'max_image_px' => 12000,
        // Bigger photos are refused before decoding so GD stays inside
        // shared-hosting memory (about 4 bytes per pixel while resizing).
        'max_image_megapixels' => 30,
        // Browsers shrink photos to this longest edge before uploading,
        // so phone photos upload fast; the server re-checks everything.
        'client_resize_px' => 2000,
    ],

    'rate_limits' => [
        // bucket => [max hits, window seconds]
        'login_ip'        => [20, 900],
        'login_account'   => [5, 900],
        'otp_send_target' => [3, 600],
        'otp_send_ip'     => [10, 3600],
        'otp_verify'      => [5, 600],
        'enquiry_user'    => [20, 3600],
        'enquiry_general' => [5, 3600],
        'booking_user'    => [10, 3600],
        'password_reset'  => [5, 3600],
        'setup'           => [5, 3600],   // wrong SETUP_TOKEN attempts per IP
        'register_ip'     => [10, 3600],
        'media_upload'    => [300, 3600],
    ],
];
