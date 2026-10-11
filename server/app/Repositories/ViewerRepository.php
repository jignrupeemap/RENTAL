<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

/**
 * Viewer dashboard queries (spec 23-25, 28). Every query is scoped to the
 * logged-in user's id. History is never hidden when a listing is paused or
 * removed: each row carries the listing's current state instead.
 */
final class ViewerRepository
{
    public const PER_PAGE = 12;

    /** SQL expression: 1 when visitors can open the listing right now. Needs aliases p (properties) and o (owner). */
    private const LIVE = "(p.status = 'active' AND (p.expires_at IS NULL OR p.expires_at > UTC_TIMESTAMP())
        AND (p.owner_id IS NULL OR (o.status = 'active' AND o.deleted_at IS NULL)))";

    public static function counts(int $userId): array
    {
        return Database::one(
            "SELECT (SELECT COUNT(*) FROM shortlists WHERE user_id = :u1) AS shortlisted,
                    (SELECT COUNT(*) FROM enquiries WHERE user_id = :u2) AS contacted,
                    (SELECT COUNT(*) FROM bookings WHERE user_id = :u3) AS visits,
                    (SELECT COUNT(*) FROM bookings WHERE user_id = :u4 AND status IN ('pending','rescheduled')) AS visits_waiting,
                    (SELECT COUNT(*) FROM bookings WHERE user_id = :u5 AND status = 'accepted' AND preferred_date >= DATE(UTC_TIMESTAMP() + INTERVAL 330 MINUTE)) AS visits_upcoming",
            ['u1' => $userId, 'u2' => $userId, 'u3' => $userId, 'u4' => $userId, 'u5' => $userId]
        ) ?? [];
    }

    /** @return array{rows: list<array>, total: int} */
    public static function shortlist(int $userId, int $page, int $perPage = self::PER_PAGE): array
    {
        $total = (int) Database::value('SELECT COUNT(*) FROM shortlists WHERE user_id = ?', [$userId]);
        $rows = Database::all(
            'SELECT s.created_at AS shortlisted_at, p.id, p.purpose, p.slug, p.title, p.rent, p.status, p.bedrooms, p.bathrooms, p.furnishing,
                    p.area_value, p.area_unit, p.pg_available_for, p.pg_suitable_for, p.published_at,
                    (p.is_featured = 1 AND (p.featured_until IS NULL OR p.featured_until > UTC_TIMESTAMP())) AS featured_now,
                    t.name AS type_name, t.field_profile, ci.name AS city_name, COALESCE(l.name, p.locality_name) AS locality_label,
                    ' . self::LIVE . ' AS is_live
               FROM shortlists s
               JOIN properties p ON p.id = s.property_id
               LEFT JOIN users o ON o.id = p.owner_id
               JOIN property_types t ON t.id = p.type_id
               LEFT JOIN cities ci ON ci.id = p.city_id
               LEFT JOIN localities l ON l.id = p.locality_id
              WHERE s.user_id = ?
              ORDER BY s.created_at DESC, s.id DESC
              LIMIT ' . (int) $perPage . ' OFFSET ' . self::offset($page, $perPage),
            [$userId]
        );
        return ['rows' => ListingRepository::withCovers($rows), 'total' => $total];
    }

    /**
     * Enquiries this user sent (spec 25): what they saw at the time (title,
     * owner, rent) from the snapshot columns, plus the listing's state now.
     * @return array{rows: list<array>, total: int}
     */
    public static function enquiries(int $userId, int $page, int $perPage = self::PER_PAGE): array
    {
        $total = (int) Database::value('SELECT COUNT(*) FROM enquiries WHERE user_id = ?', [$userId]);
        $rows = Database::all(
            'SELECT e.id, e.source, e.property_id, e.property_title, e.owner_name, e.rent_at_enquiry, e.message, e.status, e.created_at,
                    e.interested_in, e.budget, p.purpose, p.slug, p.status AS property_status, p.rent AS rent_now,
                    IF(p.id IS NULL, 0, ' . self::LIVE . ') AS is_live
               FROM enquiries e
               LEFT JOIN properties p ON p.id = e.property_id
               LEFT JOIN users o ON o.id = p.owner_id
              WHERE e.user_id = ?
              ORDER BY e.created_at DESC, e.id DESC
              LIMIT ' . (int) $perPage . ' OFFSET ' . self::offset($page, $perPage),
            [$userId]
        );
        return ['rows' => $rows, 'total' => $total];
    }

    /** @return array{rows: list<array>, total: int} */
    public static function bookings(int $userId, ?string $status, int $page, int $perPage = self::PER_PAGE): array
    {
        $where = 'b.user_id = ?';
        $params = [$userId];
        if ($status === 'open') {
            $where .= " AND b.status IN ('pending','accepted','rescheduled')";
        } elseif ($status === 'past') {
            $where .= " AND b.status IN ('rejected','completed','cancelled')";
        }
        $total = (int) Database::value("SELECT COUNT(*) FROM bookings b WHERE $where", $params);
        $rows = Database::all(
            "SELECT b.*, p.purpose, p.slug, p.status AS property_status, COALESCE(l.name, p.locality_name) AS locality_label, ci.name AS city_name,
                    IF(p.id IS NULL, 0, " . self::LIVE . ") AS is_live
               FROM bookings b
               JOIN properties p ON p.id = b.property_id
               LEFT JOIN users o ON o.id = p.owner_id
               LEFT JOIN cities ci ON ci.id = p.city_id
               LEFT JOIN localities l ON l.id = p.locality_id
              WHERE $where
              ORDER BY (b.status IN ('pending','accepted','rescheduled')) DESC, b.preferred_date DESC, b.id DESC
              LIMIT " . (int) $perPage . ' OFFSET ' . self::offset($page, $perPage),
            $params
        );
        return ['rows' => $rows, 'total' => $total];
    }

    /** Status trail for the given bookings, grouped by booking id (only this user's bookings are passed in). */
    public static function bookingHistory(array $bookingIds): array
    {
        if (!$bookingIds) {
            return [];
        }
        $in = implode(',', array_map('intval', $bookingIds));
        $out = [];
        foreach (Database::all("SELECT * FROM booking_status_history WHERE booking_id IN ($in) ORDER BY id") as $h) {
            $out[(int) $h['booking_id']][] = $h;
        }
        return $out;
    }

    public static function notifications(int $userId, int $limit = 6): array
    {
        return OwnerRepository::notifications($userId, $limit);
    }

    /** Short label for a listing that is no longer open to visitors. */
    public static function unavailableLabel(?string $status): string
    {
        return match ($status) {
            'paused'  => 'Paused by the owner',
            'expired' => 'Listing expired',
            'deleted' => 'Removed',
            null      => 'Removed',
            default   => 'Not available right now',
        };
    }

    private static function offset(int $page, int $perPage): int
    {
        return max(0, $page - 1) * $perPage;
    }
}
