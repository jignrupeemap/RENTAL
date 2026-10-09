<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

/**
 * Analytics (spec 33, 58): every number is a live COUNT/SUM over the real
 * tables, grouped by India date. India has no daylight saving, so a UTC
 * timestamp becomes an India date with a fixed +5:30 (330 minute) shift;
 * this needs no MySQL time-zone tables, which Hostinger does not load.
 *
 * Range arguments are UTC [from, to) as produced by
 * Admin\DashboardController::range().
 */
final class AnalyticsRepository
{
    /** Daily series shown on the analytics page, in display order. */
    public const METRICS = [
        'users'      => 'New users',
        'properties' => 'Properties posted',
        'logins'     => 'Logins',
        'views'      => 'Property views',
        'shortlists' => 'Shortlists',
        'contacts'   => 'Contact requests',
        'enquiries'  => 'Enquiries',
        'bookings'   => 'Booking requests',
    ];

    /** Ranges longer than this are charted per month instead of per day. */
    public const MAX_DAILY_POINTS = 62;

    /** A listing counts as "posted" once it was submitted or published; drafts never sent do not. */
    private const POSTED = "status <> 'deleted' AND (submitted_at IS NOT NULL OR published_at IS NOT NULL)";

    private const DAY = 'DATE(%s + INTERVAL 330 MINUTE)';

    /** All-time totals for the spec 33 cards. @return array<string, int> */
    public static function totals(): array
    {
        $row = Database::one(
            'SELECT
                (SELECT COUNT(*) FROM properties WHERE ' . self::POSTED . ") AS properties,
                (SELECT COUNT(*) FROM users WHERE deleted_at IS NULL) AS users,
                (SELECT COUNT(*) FROM login_activity WHERE event = 'login_success' AND context = 'site') AS logins,
                (SELECT COALESCE(SUM(views), 0) FROM property_view_daily) AS views,
                (SELECT COUNT(*) FROM shortlists) AS shortlists,
                (SELECT COUNT(*) FROM contact_history) AS contacts,
                (SELECT COUNT(*) FROM enquiries) AS enquiries,
                (SELECT COUNT(*) FROM bookings) AS bookings"
        );
        return array_map('intval', $row ?? []);
    }

    /**
     * Count per India day for every metric in the range, zero-filled.
     * @return array<string, array<string, int>> metric => [Y-m-d => count]
     */
    public static function daily(string $from, string $to): array
    {
        $out = [];
        foreach (self::dailySql() as $metric => $sql) {
            $days = self::emptyDays($from, $to);
            foreach (Database::all($sql, [$from, $to]) as $r) {
                if (isset($days[$r['d']])) {
                    $days[$r['d']] = (int) $r['n'];
                }
            }
            $out[$metric] = $days;
        }
        return $out;
    }

    /**
     * Turn a daily series into chart points: per day for short ranges, per
     * month for long ones. @return list<array{0: string, 1: int, 2: string}> [short label, value, full label]
     */
    public static function points(array $days): array
    {
        $points = [];
        if (count($days) <= self::MAX_DAILY_POINTS) {
            foreach ($days as $d => $n) {
                $t = strtotime($d . ' 00:00:00 UTC');
                $points[] = [gmdate('j M', $t), $n, gmdate('D, d M Y', $t)];
            }
            return $points;
        }
        $months = [];
        foreach ($days as $d => $n) {
            $months[substr($d, 0, 7)] = ($months[substr($d, 0, 7)] ?? 0) + $n;
        }
        foreach ($months as $m => $n) {
            $t = strtotime($m . '-01 00:00:00 UTC');
            $points[] = [gmdate('M y', $t), $n, gmdate('F Y', $t)];
        }
        return $points;
    }

    /** Enquiries and bookings by status, created in the range. @return array{enquiries: array<string,int>, bookings: array<string,int>} */
    public static function statusMix(string $from, string $to): array
    {
        $mix = ['enquiries' => ['new' => 0, 'contacted' => 0, 'in_progress' => 0, 'closed' => 0],
                'bookings'  => ['pending' => 0, 'accepted' => 0, 'rescheduled' => 0, 'completed' => 0, 'rejected' => 0, 'cancelled' => 0]];
        foreach ($mix as $table => $_) {
            foreach (Database::all("SELECT status, COUNT(*) AS n FROM $table WHERE created_at >= ? AND created_at < ? GROUP BY status", [$from, $to]) as $r) {
                $mix[$table][$r['status']] = (int) $r['n'];
            }
        }
        return $mix;
    }

    /** Live listings per city, most first. @return list<array{id: int, name: string, n: int}> */
    public static function topCities(int $limit = 8): array
    {
        return array_map(static fn ($r) => ['id' => (int) $r['id'], 'name' => $r['name'], 'n' => (int) $r['n']], Database::all(
            "SELECT c.id, c.name, COUNT(*) AS n FROM properties p JOIN cities c ON c.id = p.city_id
              WHERE p.status = 'active' GROUP BY c.id, c.name ORDER BY n DESC, c.name LIMIT " . max(1, $limit)
        ));
    }

    /** Most-viewed listings in the range. @return list<array> */
    public static function topViewed(string $from, string $to, int $limit = 10): array
    {
        return Database::all(
            'SELECT p.id, p.title, p.purpose, p.status, SUM(v.views) AS views, p.enquiries_count, p.shortlists_count
               FROM property_view_daily v JOIN properties p ON p.id = v.property_id
              WHERE v.view_date >= ' . sprintf(self::DAY, '?') . ' AND v.view_date < ' . sprintf(self::DAY, '?') . "
                AND p.status <> 'deleted'
              GROUP BY p.id, p.title, p.purpose, p.status, p.enquiries_count, p.shortlists_count
              ORDER BY views DESC, p.id LIMIT " . max(1, $limit),
            [$from, $to]
        );
    }

    // ------------------------------------------------------------------
    // One listing (owner "Performance" page). The caller has already
    // checked that the listing belongs to the signed-in owner.
    // ------------------------------------------------------------------

    /** @return array<string, array<string, int>> metric => [Y-m-d => count] for views, shortlists, enquiries, bookings */
    public static function propertyDaily(int $propertyId, string $from, string $to): array
    {
        $day = sprintf(self::DAY, 'created_at');
        $sql = [
            'views'      => 'SELECT view_date AS d, SUM(views) AS n FROM property_view_daily WHERE property_id = ? AND view_date >= '
                            . sprintf(self::DAY, '?') . ' AND view_date < ' . sprintf(self::DAY, '?') . ' GROUP BY d',
            'shortlists' => "SELECT $day AS d, COUNT(*) AS n FROM shortlists WHERE property_id = ? AND created_at >= ? AND created_at < ? GROUP BY d",
            'enquiries'  => "SELECT $day AS d, COUNT(*) AS n FROM enquiries WHERE property_id = ? AND created_at >= ? AND created_at < ? GROUP BY d",
            'bookings'   => "SELECT $day AS d, COUNT(*) AS n FROM bookings WHERE property_id = ? AND created_at >= ? AND created_at < ? GROUP BY d",
        ];
        $out = [];
        foreach ($sql as $metric => $q) {
            $days = self::emptyDays($from, $to);
            foreach (Database::all($q, [$propertyId, $from, $to]) as $r) {
                if (isset($days[$r['d']])) {
                    $days[$r['d']] = (int) $r['n'];
                }
            }
            $out[$metric] = $days;
        }
        return $out;
    }

    /** Views per India day across all of one owner's listings. @return array<string, int> */
    public static function ownerViews(int $ownerId, string $from, string $to): array
    {
        $days = self::emptyDays($from, $to);
        $rows = Database::all(
            'SELECT v.view_date AS d, SUM(v.views) AS n FROM property_view_daily v JOIN properties p ON p.id = v.property_id
              WHERE p.owner_id = ? AND v.view_date >= ' . sprintf(self::DAY, '?') . ' AND v.view_date < ' . sprintf(self::DAY, '?') . ' GROUP BY d',
            [$ownerId, $from, $to]
        );
        foreach ($rows as $r) {
            if (isset($days[$r['d']])) {
                $days[$r['d']] = (int) $r['n'];
            }
        }
        return $days;
    }

    /** @return array<string, string> metric => SQL taking [from, to) and returning (d, n) */
    private static function dailySql(): array
    {
        $day = sprintf(self::DAY, 'created_at');
        $range = 'created_at >= ? AND created_at < ?';
        return [
            'users'      => "SELECT $day AS d, COUNT(*) AS n FROM users WHERE deleted_at IS NULL AND $range GROUP BY d",
            'properties' => "SELECT $day AS d, COUNT(*) AS n FROM properties WHERE " . self::POSTED . " AND $range GROUP BY d",
            'logins'     => "SELECT $day AS d, COUNT(*) AS n FROM login_activity WHERE event = 'login_success' AND context = 'site' AND $range GROUP BY d",
            'views'      => 'SELECT view_date AS d, SUM(views) AS n FROM property_view_daily WHERE view_date >= '
                            . sprintf(self::DAY, '?') . ' AND view_date < ' . sprintf(self::DAY, '?') . ' GROUP BY d',
            'shortlists' => "SELECT $day AS d, COUNT(*) AS n FROM shortlists WHERE $range GROUP BY d",
            'contacts'   => "SELECT $day AS d, COUNT(*) AS n FROM contact_history WHERE $range GROUP BY d",
            'enquiries'  => "SELECT $day AS d, COUNT(*) AS n FROM enquiries WHERE $range GROUP BY d",
            'bookings'   => "SELECT $day AS d, COUNT(*) AS n FROM bookings WHERE $range GROUP BY d",
        ];
    }

    /** @return array<string, int> every India date in [from, to) => 0 */
    private static function emptyDays(string $from, string $to): array
    {
        $ist = new \DateTimeZone('Asia/Kolkata');
        $utc = new \DateTimeZone('UTC');
        $d = (new \DateTimeImmutable($from, $utc))->setTimezone($ist)->setTime(0, 0);
        $end = (new \DateTimeImmutable($to, $utc))->setTimezone($ist);
        $days = [];
        for ($i = 0; $d < $end && $i < 3700; $i++, $d = $d->modify('+1 day')) {
            $days[$d->format('Y-m-d')] = 0;
        }
        return $days;
    }
}
