-- =====================================================================
-- Migration 0009: homepage text for Google results
-- =====================================================================
-- The homepage <title> and meta description are what Google shows for
-- homeforrent.co.in. Both are editable in Admin → Settings → General.
-- INSERT IGNORE keeps a value the admin already saved.

SET NAMES utf8mb4;

INSERT IGNORE INTO settings (setting_key, setting_value, value_type, setting_group, label, description) VALUES
 ('seo_home_title', 'Rent, PG & Lease in Surat, Ankleshwar, Bharuch, Vapi', 'string', 'general',
  'Homepage title (Google)', 'Blue link text in Google results; the site name is added after it. Keep under 60 characters.'),
 ('seo_home_description', 'Find flats, houses, PG, shops and offices for rent or lease in Surat, Ankleshwar, Bharuch, Vapi and across Gujarat. Listed direct by owners. Search free.', 'string', 'general',
  'Homepage description (Google)', 'Grey text under the link in Google results. Keep between 120 and 160 characters.');
