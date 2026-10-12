-- =====================================================================
-- Migration 0008: phone for location enquiries
-- =====================================================================
-- On listing pages "View on Google Maps" now shows "Contact/whatsapp on
-- <support_phone> for location and more detail" instead of the map link.
-- Only fills the setting while it is still empty, so a number the admin
-- already set (Admin → Settings) is kept.

SET NAMES utf8mb4;

UPDATE settings SET setting_value = '9925133440'
WHERE setting_key = 'support_phone' AND (setting_value IS NULL OR setting_value = '');
