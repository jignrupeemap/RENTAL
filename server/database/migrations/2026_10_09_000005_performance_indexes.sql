-- =====================================================================
-- Migration 0005: Phase 14 performance indexes
-- =====================================================================
-- Measured with 20,000 listings:
-- * (status, expires_at) made MariaDB pick it for every public listing
--   query (key on status only), reading all active rows before sorting.
--   Expiry is now indexed on its own; the cron job still finds expired
--   listings with a range scan.
-- * The admin property list sorts by updated_at (and the approval queue by
--   submitted_at); both were full-table sorts.
-- * The homepage "newest listings" across all purposes needs
--   (status, published_at) to read the newest rows straight from the index.
-- Indexes only: no data is changed.

SET NAMES utf8mb4;

ALTER TABLE properties
    DROP INDEX idx_prop_expires,
    ADD KEY idx_prop_expiry (expires_at),
    ADD KEY idx_prop_updated (updated_at),
    ADD KEY idx_prop_status_submitted (status, submitted_at),
    ADD KEY idx_prop_published (status, purpose, published_at),
    ADD KEY idx_prop_status_published (status, published_at);
