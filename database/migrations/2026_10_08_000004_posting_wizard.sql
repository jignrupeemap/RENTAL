-- =====================================================================
-- Migration 0004: Phase 4 property posting wizard
-- =====================================================================
-- Owners can type their area when it is not in the admin's locality list
-- (only Surat has localities seeded). locality_id stays NULL in that case;
-- the admin can later add the area to the list and link it.

SET NAMES utf8mb4;

ALTER TABLE properties
    ADD COLUMN locality_name VARCHAR(100) NULL COMMENT 'typed by owner when the area is not in localities' AFTER locality_id;
