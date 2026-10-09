<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

/**
 * Every query here is scoped to one owner: the owner id always comes from
 * the session (Auth::id()), never from the request, so an owner can only
 * ever read or change their own listings, enquiries and bookings.
 */
final class OwnerRepository
{
    /** @return array<string, int> */
    public static function propertyCounts(int $ownerId): array
    {
        $row = Database::one(
            "SELECT COUNT(*) AS total,
                    COALESCE(SUM(status = 'active'), 0) AS active,
                    COALESCE(SUM(status = 'pending'), 0) AS pending,
                    COALESCE(SUM(status = 'paused'), 0) AS paused,
                    COALESCE(SUM(status = 'draft'), 0) AS draft,
                    COALESCE(SUM(status IN ('rejected', 'changes_requested')), 0) AS needs_attention,
                    COALESCE(SUM(status = 'expired'), 0) AS expired,
                    COALESCE(SUM(views_count), 0) AS views,
                    COALESCE(SUM(shortlists_count), 0) AS shortlists
               FROM properties WHERE owner_id = ? AND status <> 'deleted'",
            [$ownerId]
        );
        return array_map('intval', $row ?? []);
    }

    /** @return array<string, int> */
    public static function enquiryCounts(int $ownerId): array
    {
        $row = Database::one(
            "SELECT COUNT(*) AS total,
                    COALESCE(SUM(status = 'new'), 0) AS new,
                    COALESCE(SUM(status = 'contacted'), 0) AS contacted,
                    COALESCE(SUM(status = 'in_progress'), 0) AS in_progress,
                    COALESCE(SUM(status = 'closed'), 0) AS closed
               FROM enquiries WHERE owner_id = ?",
            [$ownerId]
        );
        return array_map('intval', $row ?? []);
    }

    /** @return array<string, int> */
    public static function bookingCounts(int $ownerId): array
    {
        $row = Database::one(
            "SELECT COUNT(*) AS total,
                    COALESCE(SUM(status = 'pending'), 0) AS pending,
                    COALESCE(SUM(status = 'accepted'), 0) AS accepted,
                    COALESCE(SUM(status = 'rescheduled'), 0) AS rescheduled,
                    COALESCE(SUM(status IN ('rejected', 'completed', 'cancelled')), 0) AS closed
               FROM bookings WHERE owner_id = ?",
            [$ownerId]
        );
        return array_map('intval', $row ?? []);
    }

    public static function pendingRentChanges(int $ownerId): int
    {
        return (int) Database::value("SELECT COUNT(*) FROM rental_change_history WHERE owner_id = ? AND status = 'pending'", [$ownerId]);
    }

    // ------------------------------------------------------------------
    // Properties
    // ------------------------------------------------------------------

    /** @return array{rows: list<array>, total: int} */
    public static function properties(int $ownerId, ?string $status, int $page, int $perPage = 20): array
    {
        $where = "p.owner_id = ? AND p.status <> 'deleted'";
        $params = [$ownerId];
        if ($status === 'attention') {
            $where .= " AND p.status IN ('rejected', 'changes_requested')";
        } elseif ($status !== null) {
            $where .= ' AND p.status = ?';
            $params[] = $status;
        }
        $total = (int) Database::value("SELECT COUNT(*) FROM properties p WHERE {$where}", $params);
        $offset = ($page - 1) * $perPage;
        $rows = Database::all(
            "SELECT p.id, p.purpose, p.status, p.title, p.slug, p.rent, p.draft_step, p.views_count, p.enquiries_count,
                    p.shortlists_count, p.bookings_count, p.rejection_reason, p.updated_at, p.published_at, p.expires_at,
                    t.name AS type_name, c.name AS category_name, ci.name AS city_name, l.name AS locality_name,
                    rc.new_rent AS pending_rent,
                    m.storage_dir AS cover_dir, m.file_key AS cover_key, m.has_webp AS cover_webp
               FROM properties p
               JOIN property_types t ON t.id = p.type_id
               JOIN property_categories c ON c.id = p.category_id
          LEFT JOIN cities ci ON ci.id = p.city_id
          LEFT JOIN localities l ON l.id = p.locality_id
          LEFT JOIN rental_change_history rc ON rc.id = p.pending_rent_change_id AND rc.status = 'pending'
          LEFT JOIN property_media m ON m.id = p.cover_media_id AND m.status = 'active'
              WHERE {$where}
           ORDER BY p.updated_at DESC, p.id DESC
              LIMIT {$perPage} OFFSET {$offset}",
            $params
        );
        return ['rows' => $rows, 'total' => $total];
    }

    public static function property(int $ownerId, int $propertyId): ?array
    {
        return Database::one(
            "SELECT * FROM properties WHERE id = ? AND owner_id = ? AND status <> 'deleted'",
            [$propertyId, $ownerId]
        );
    }

    /** Rent changes of one of the owner's listings, newest first. @return list<array> */
    public static function propertyRentChanges(int $ownerId, int $propertyId): array
    {
        return Database::all(
            'SELECT old_rent, new_rent, difference, status, review_note, created_at
               FROM rental_change_history WHERE property_id = ? AND owner_id = ? ORDER BY id DESC LIMIT 50',
            [$propertyId, $ownerId]
        );
    }

    /**
     * Status changes of a listing the caller already checked belongs to the owner.
     * Only the reason for a rejection or change request is shown, as on My Properties.
     * @return list<array>
     */
    public static function propertyStatusHistory(int $propertyId): array
    {
        return Database::all(
            "SELECT old_status, new_status, actor_role, created_at,
                    CASE WHEN new_status IN ('rejected', 'changes_requested') THEN reason END AS reason
               FROM property_status_history WHERE property_id = ? ORDER BY id DESC LIMIT 50",
            [$propertyId]
        );
    }

    /** Who made the most recent status change to this listing (owner / admin / system). */
    public static function lastStatusActor(int $propertyId): ?string
    {
        $v = Database::value('SELECT actor_role FROM property_status_history WHERE property_id = ? ORDER BY id DESC LIMIT 1', [$propertyId]);
        return $v === null ? null : (string) $v;
    }

    // ------------------------------------------------------------------
    // Enquiries
    // ------------------------------------------------------------------

    /** @return array{rows: list<array>, total: int} */
    public static function enquiries(int $ownerId, ?string $status, string $search, int $page, int $perPage = 20): array
    {
        $where = 'e.owner_id = ?';
        $params = [$ownerId];
        if ($status !== null) {
            $where .= ' AND e.status = ?';
            $params[] = $status;
        }
        if ($search !== '') {
            $like = '%' . addcslashes($search, '%_\\') . '%';
            $digits = preg_replace('/\D/', '', $search) ?? '';
            $where .= ' AND (e.first_name LIKE ? OR e.last_name LIKE ? OR e.email LIKE ? OR e.property_title LIKE ?'
                . (strlen($digits) >= 3 ? ' OR e.mobile_1 LIKE ?' : '') . ')';
            array_push($params, $like, $like, $like, $like);
            if (strlen($digits) >= 3) {
                $params[] = '%' . $digits . '%';
            }
        }
        $total = (int) Database::value("SELECT COUNT(*) FROM enquiries e WHERE {$where}", $params);
        $offset = ($page - 1) * $perPage;
        $rows = Database::all(
            "SELECT e.*, p.status AS property_status, p.purpose, p.slug,
                    (SELECT b.id FROM bookings b WHERE b.property_id = e.property_id AND b.user_id = e.user_id
                      ORDER BY b.id DESC LIMIT 1) AS booking_id
               FROM enquiries e
          LEFT JOIN properties p ON p.id = e.property_id
              WHERE {$where}
           ORDER BY e.created_at DESC, e.id DESC
              LIMIT {$perPage} OFFSET {$offset}",
            $params
        );
        return ['rows' => $rows, 'total' => $total];
    }

    public static function enquiry(int $ownerId, int $enquiryId): ?array
    {
        return Database::one('SELECT * FROM enquiries WHERE id = ? AND owner_id = ?', [$enquiryId, $ownerId]);
    }

    // ------------------------------------------------------------------
    // Bookings
    // ------------------------------------------------------------------

    /** @return array{rows: list<array>, total: int} */
    public static function bookings(int $ownerId, ?string $status, int $page, int $perPage = 20): array
    {
        $where = 'b.owner_id = ?';
        $params = [$ownerId];
        if ($status === 'closed') {
            $where .= " AND b.status IN ('rejected', 'completed', 'cancelled')";
        } elseif ($status !== null) {
            $where .= ' AND b.status = ?';
            $params[] = $status;
        }
        $total = (int) Database::value("SELECT COUNT(*) FROM bookings b WHERE {$where}", $params);
        $offset = ($page - 1) * $perPage;
        $rows = Database::all(
            "SELECT b.*, u.first_name, u.last_name, u.mobile, u.email, p.purpose, p.slug, p.status AS property_status
               FROM bookings b
               JOIN users u ON u.id = b.user_id
               JOIN properties p ON p.id = b.property_id
              WHERE {$where}
           ORDER BY (b.status IN ('pending', 'rescheduled', 'accepted')) DESC, b.preferred_date ASC, b.id DESC
              LIMIT {$perPage} OFFSET {$offset}",
            $params
        );
        return ['rows' => $rows, 'total' => $total];
    }

    public static function booking(int $ownerId, int $bookingId): ?array
    {
        return Database::one('SELECT * FROM bookings WHERE id = ? AND owner_id = ?', [$bookingId, $ownerId]);
    }

    /** @return array<int, list<array>> history rows grouped by booking id */
    public static function bookingHistory(array $bookingIds): array
    {
        if (!$bookingIds) {
            return [];
        }
        $in = implode(',', array_map('intval', $bookingIds));
        $out = [];
        foreach (Database::all("SELECT * FROM booking_status_history WHERE booking_id IN ({$in}) ORDER BY id") as $h) {
            $out[(int) $h['booking_id']][] = $h;
        }
        return $out;
    }

    // ------------------------------------------------------------------
    // Rent changes, notifications
    // ------------------------------------------------------------------

    /** @return array{rows: list<array>, total: int} */
    public static function rentChanges(int $ownerId, int $page, int $perPage = 20): array
    {
        $total = (int) Database::value('SELECT COUNT(*) FROM rental_change_history WHERE owner_id = ?', [$ownerId]);
        $offset = ($page - 1) * $perPage;
        $rows = Database::all(
            "SELECT r.*, p.purpose, p.slug, p.rent AS current_rent
               FROM rental_change_history r JOIN properties p ON p.id = r.property_id
              WHERE r.owner_id = ?
           ORDER BY r.id DESC LIMIT {$perPage} OFFSET {$offset}",
            [$ownerId]
        );
        return ['rows' => $rows, 'total' => $total];
    }

    /** @return list<array> */
    public static function notifications(int $userId, int $limit = 8): array
    {
        return Database::all(
            "SELECT * FROM notifications WHERE audience = 'user' AND user_id = ? ORDER BY read_at IS NULL DESC, id DESC LIMIT " . max(1, $limit),
            [$userId]
        );
    }

    public static function unreadNotifications(int $userId): int
    {
        return (int) Database::value("SELECT COUNT(*) FROM notifications WHERE audience = 'user' AND user_id = ? AND read_at IS NULL", [$userId]);
    }
}
