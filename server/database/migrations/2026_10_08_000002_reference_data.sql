-- =====================================================================
-- Migration 0002: production reference data
-- Categories, sub-types and facilities come from Rental flow.xlsx
-- (sheets RentLease and PG). Everything here is editable later by admin.
-- No demo users/properties here: those live in database/seeds/demo.sql.
-- =====================================================================

SET NAMES utf8mb4;

INSERT INTO property_categories (id, code, name, sort_order) VALUES
 (1, 'residential', 'Residential', 1),
 (2, 'commercial',  'Commercial',  2),
 (3, 'industrial',  'Industrial',  3);

-- field_profile: residential | commercial | commercial_plot | industrial
INSERT INTO property_types (category_id, code, name, field_profile, unit_label, show_building_name, allows_pg, sort_order) VALUES
 -- Residential (sheet columns B-D)
 (1, 'flat',             'Flat',               'residential',     'Flat No',        1, 1, 1),
 (1, 'bungalow',         'Bungalow',           'residential',     'Bungalow No',    0, 1, 2),
 (1, 'farmhouse_villa',  'Farmhouse / Villa',  'residential',     'Plot No',        0, 0, 3),
 -- Commercial (sheet columns F-P)
 (2, 'office',           'Office',             'commercial',      'Office No',      1, 0, 1),
 (2, 'shop',             'Shop',               'commercial',      'Shop No',        1, 0, 2),
 (2, 'showroom',         'Showroom',           'commercial',      'Shop No',        1, 0, 3),
 (2, 'house_building',   'House / Building',   'commercial',      'Plot No',        0, 0, 4),
 (2, 'commercial_plot',  'Commercial Plot',    'commercial_plot', 'Plot No',        0, 0, 5),
 (2, 'restaurant',       'Restaurant',         'commercial',      'Shop / Unit No', 1, 0, 6),
 (2, 'hospital',         'Hospital',           'commercial',      'Shop / Unit No', 1, 0, 7),
 (2, 'school',           'School',             'commercial',      'Shop / Unit No', 1, 0, 8),
 (2, 'hotel',            'Hotel',              'commercial',      'Shop / Unit No', 1, 0, 9),
 (2, 'gym',              'Gym',                'commercial',      'Shop / Unit No', 1, 0, 10),
 (2, 'hall',             'Hall',               'commercial',      'Shop / Unit No', 1, 0, 11),
 -- Industrial (sheet columns R-V)
 (3, 'factory',          'Factory',            'industrial',      'Plot No',        0, 0, 1),
 (3, 'warehouse_godown', 'Warehouse / Godown', 'industrial',      'Plot No',        0, 0, 2),
 (3, 'industrial_plot',  'Industrial Plot',    'industrial',      'Plot No',        0, 0, 3),
 (3, 'cold_storage',     'Cold Storage',       'industrial',      'Plot No',        0, 0, 4),
 (3, 'shed',             'Shed',               'industrial',      'Plot No',        0, 0, 5);

INSERT INTO amenities (code, name, amenity_group, scopes, sort_order) VALUES
 -- Yes/No features from the sheet
 ('personal_washroom',  'Personal Washroom',   'feature',  'commercial', 1),
 ('power_backup',       'Power Backup',        'feature',  'residential,commercial,pg', 2),
 ('electricity_backup', 'Electricity Backup',  'feature',  'industrial', 3),
 ('rcc_road',           'RCC Road',            'feature',  'industrial,commercial_plot', 4),
 -- "Other Facility" list from the PG sheet
 ('pooja_room',         'Pooja Room',          'facility', 'residential,pg', 10),
 ('servant_room',       'Servant Room',        'facility', 'residential,pg', 11),
 ('study_room',         'Study Room',          'facility', 'residential,pg', 12),
 ('play_area',          'Play Area',           'facility', 'residential,pg', 13),
 ('garden',             'Garden',              'facility', 'residential,pg', 14),
 -- Common facilities (admin can add/disable more)
 ('lift',               'Lift',                'facility', 'residential,commercial,pg', 20),
 ('car_parking',        'Car Parking',         'facility', 'residential,commercial,industrial,pg', 21),
 ('two_wheeler_parking','Two-wheeler Parking', 'facility', 'residential,commercial,pg', 22),
 ('security',           '24x7 Security',       'facility', 'residential,commercial,industrial,pg', 23),
 ('cctv',               'CCTV',                'facility', 'residential,commercial,industrial,pg', 24),
 ('water_supply',       '24x7 Water Supply',   'facility', 'residential,commercial,industrial,pg', 25),
 ('gated_society',      'Gated Society',       'facility', 'residential,pg', 26),
 ('wifi',               'Wi-Fi',               'facility', 'pg,commercial', 27),
 ('air_conditioning',   'Air Conditioning',    'facility', 'residential,commercial,pg', 28),
 ('loading_dock',       'Loading / Unloading Area', 'facility', 'industrial', 29),
 ('three_phase_power',  'Three-phase Power',   'facility', 'industrial,commercial', 30);

-- Starter city list; admin manages cities/localities from the panel.
INSERT INTO cities (id, name, slug, state, sort_order) VALUES
 (1, 'Surat',     'surat',     'Gujarat', 1),
 (2, 'Ahmedabad', 'ahmedabad', 'Gujarat', 2),
 (3, 'Vadodara',  'vadodara',  'Gujarat', 3),
 (4, 'Rajkot',    'rajkot',    'Gujarat', 4);

INSERT INTO localities (city_id, name, slug, pincode) VALUES
 (1, 'Vesu',          'vesu',          '395007'),
 (1, 'Adajan',        'adajan',        '395009'),
 (1, 'Pal',           'pal',           '395009'),
 (1, 'Piplod',        'piplod',        '395007'),
 (1, 'City Light',    'city-light',    '395007'),
 (1, 'Althan',        'althan',        '395017'),
 (1, 'Varachha',      'varachha',      '395006'),
 (1, 'Katargam',      'katargam',      '395004'),
 (1, 'Udhna',         'udhna',         '394210'),
 (1, 'Sachin GIDC',   'sachin-gidc',   '394230'),
 (1, 'Pandesara GIDC','pandesara-gidc','394221'),
 (1, 'Hazira',        'hazira',        '394270');

INSERT INTO settings (setting_key, setting_value, value_type, setting_group, label, description) VALUES
 ('site_name',                    'Rental Website',        'string', 'general',  'Website name', 'Shown in header, titles and emails'),
 ('site_tagline',                 'Rent, PG & Lease properties', 'string', 'general', 'Tagline', NULL),
 ('support_email',                '',                'string', 'general',  'Support email', 'Shown in footer and emails'),
 ('support_phone',                '',                'string', 'general',  'Support phone', NULL),
 ('default_property_limit',       '3',               'int',    'listings', 'Default property limit', 'Applied to each new account at registration'),
 ('rent_change_approval_mode',    'increase_only',   'string', 'listings', 'Rent change approval', 'none | increase_only | all'),
 ('reapprove_on_owner_edit',      '0',               'bool',   'listings', 'Re-approve after owner edits', 'If on, owner edits to an active listing send it back to Pending'),
 ('listing_expiry_days',          '90',              'int',    'listings', 'Listing expiry (days)', '0 = never expire'),
 ('listings_per_page',            '12',              'int',    'listings', 'Listings per page', NULL),
 ('allow_owner_pause',            '1',               'bool',   'listings', 'Owners can pause listings', NULL),
 ('max_images_per_property',      '20',              'int',    'media',    'Max photos per property', NULL),
 ('max_image_upload_mb',          '10',              'int',    'media',    'Max photo upload size (MB)', 'Before optimisation'),
 ('max_video_upload_mb',          '50',              'int',    'media',    'Max video upload size (MB)', 'Also limited by PHP upload_max_filesize'),
 ('keep_original_images',         '0',               'bool',   'media',    'Keep original photos', 'Off saves disk space'),
 ('auth_otp_enabled',             '0',               'bool',   'auth',     'Mobile OTP verification', 'Needs an SMS provider in config/env.php'),
 ('notify_admin_new_registration','1',               'bool',   'notifications', 'Notify admin of new registrations', NULL),
 ('email_notifications_enabled',  '0',               'bool',   'notifications', 'Send email notifications', 'Needs SMTP in config/env.php'),
 ('contact_reveals_phone',        '1',               'bool',   'engagement', 'Show owner phone after enquiry', NULL),
 ('budget_ranges',                '[{"label":"Up to ₹15,000","min":0,"max":15000},{"label":"₹15,000 – ₹25,000","min":15000,"max":25000},{"label":"₹25,000 – ₹50,000","min":25000,"max":50000},{"label":"More than ₹50,000","min":50000,"max":null}]', 'json', 'search', 'Budget filter ranges', NULL),
 ('cookie_policy_version',        '1',               'int',    'privacy',  'Cookie policy version', 'Raise to ask everyone for consent again'),
 ('default_location_privacy',     'approximate',     'string', 'privacy',  'Default map privacy', 'exact | approximate');

INSERT INTO cms_pages (slug, title, body, meta_description) VALUES
 ('about',          'About Us',         '<p>Edit this page from Admin → Pages.</p>', NULL),
 ('terms',          'Terms of Use',     '<p>Edit this page from Admin → Pages.</p>', NULL),
 ('privacy',        'Privacy Policy',   '<p>Edit this page from Admin → Pages.</p>', NULL),
 ('cookie-policy',  'Cookie Policy',    '<p>Edit this page from Admin → Pages.</p>', NULL);
