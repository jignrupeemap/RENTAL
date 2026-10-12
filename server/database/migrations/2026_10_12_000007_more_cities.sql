-- =====================================================================
-- Migration 0007: Ankleshwar, Bharuch and Vapi with common localities
-- =====================================================================
-- INSERT IGNORE skips a city or locality the admin already added (same slug).

SET NAMES utf8mb4;

INSERT IGNORE INTO cities (name, slug, state, sort_order) VALUES
 ('Ankleshwar', 'ankleshwar', 'Gujarat', 5),
 ('Bharuch',    'bharuch',    'Gujarat', 6),
 ('Vapi',       'vapi',       'Gujarat', 7);

INSERT IGNORE INTO localities (city_id, name, slug, pincode)
SELECT c.id, l.name, l.slug, l.pincode
FROM cities c
JOIN (
    SELECT 'ankleshwar' AS city, 'Ankleshwar GIDC' AS name, 'ankleshwar-gidc' AS slug, '393002' AS pincode
    UNION ALL SELECT 'ankleshwar', 'Old Ankleshwar',    'old-ankleshwar',    '393001'
    UNION ALL SELECT 'ankleshwar', 'Piraman',           'piraman',           '393001'
    UNION ALL SELECT 'ankleshwar', 'Gadkhol',           'gadkhol',           '393010'
    UNION ALL SELECT 'ankleshwar', 'Jitali',            'jitali',            '393001'
    UNION ALL SELECT 'ankleshwar', 'Panoli GIDC',       'panoli-gidc',       '394116'
    UNION ALL SELECT 'bharuch',    'Zadeshwar',         'zadeshwar',         '392011'
    UNION ALL SELECT 'bharuch',    'Station Road',      'station-road',      '392001'
    UNION ALL SELECT 'bharuch',    'Link Road',         'link-road',         '392001'
    UNION ALL SELECT 'bharuch',    'Shaktinagar',       'shaktinagar',       '392012'
    UNION ALL SELECT 'bharuch',    'Bholav',            'bholav',            '392001'
    UNION ALL SELECT 'bharuch',    'Dahej GIDC',        'dahej-gidc',        '392130'
    UNION ALL SELECT 'bharuch',    'Jhagadia GIDC',     'jhagadia-gidc',     '393110'
    UNION ALL SELECT 'vapi',       'Vapi GIDC',         'vapi-gidc',         '396195'
    UNION ALL SELECT 'vapi',       'Chala',             'chala',             '396191'
    UNION ALL SELECT 'vapi',       'Chanod',            'chanod',            '396195'
    UNION ALL SELECT 'vapi',       'Koparli Road',      'koparli-road',      '396191'
    UNION ALL SELECT 'vapi',       'Vapi Town',         'vapi-town',         '396191'
    UNION ALL SELECT 'vapi',       'Balitha',           'balitha',           '396191'
) l ON l.city = c.slug COLLATE utf8mb4_unicode_ci;
