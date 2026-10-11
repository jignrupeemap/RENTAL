-- =====================================================================
-- Migration 0006: site name "Homeforrent"
-- =====================================================================
-- Renames the placeholder site name. Only touches the row while it still
-- holds the placeholder, so a name the admin already set is kept.

SET NAMES utf8mb4;

UPDATE settings SET setting_value = 'Homeforrent'
WHERE setting_key = 'site_name' AND setting_value = 'Rental Website';
