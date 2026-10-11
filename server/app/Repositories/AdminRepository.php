<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

/**
 * Read queries for the admin panel. Every number is a live COUNT/SUM on
 * the real tables (spec 58); searches are server-side and paginated.
 */
final class AdminRepository
{
    public const PER_PAGE = 25;

    // ------------------------------------------------------------------
    // Dashboard
    // ------------------------------------------------------------------

    /** @return array<string, int> */
    public static function alerts(): array
    {
        $row = Database::one(
            "SELECT
                (SELECT COUNT(*) FROM properties WHERE status = 'pending') AS approvals,
                (SELECT COUNT(*) FROM rental_change_history WHERE status = 'pending') AS rent_changes,
                (SELECT COUNT(*) FROM property_limit_requests WHERE status = 'pending') AS limit_requests,
                (SELECT COUNT(*) FROM enquiries WHERE status = 'new') AS new_enquiries,
                (SELECT COUNT(*) FROM bookings WHERE status = 'pending') AS new_bookings,
                (SELECT COUNT(*) FROM notifications WHERE audience = 'admin' AND read_at IS NULL) AS unread"
        );
        return array_map('intval', $row ?? []);
    }

    /** @return array<string, int> */
    public static function userStats(string $from, string $to): array
    {
        $row = Database::one(
            "SELECT COUNT(*) AS total,
                    COALESCE(SUM(status = 'active'), 0) AS active, COALESCE(SUM(status = 'inactive'), 0) AS inactive,
                    COALESCE(SUM(status = 'blocked'), 0) AS blocked, COALESCE(SUM(status = 'suspended'), 0) AS suspended,
                    COALESCE(SUM(created_at >= ? AND created_at < ?), 0) AS new_in_range
               FROM users WHERE deleted_at IS NULL",
            [$from, $to]
        );
        return array_map('intval', $row ?? []);
    }

    /** @return array<string, int> */
    public static function propertyStats(string $from, string $to): array
    {
        $row = Database::one(
            "SELECT COUNT(*) AS total,
                    COALESCE(SUM(p.purpose = 'rent'), 0) AS rent, COALESCE(SUM(p.purpose = 'pg'), 0) AS pg, COALESCE(SUM(p.purpose = 'lease'), 0) AS lease,
                    COALESCE(SUM(c.code = 'residential'), 0) AS residential, COALESCE(SUM(c.code = 'commercial'), 0) AS commercial,
                    COALESCE(SUM(c.code = 'industrial'), 0) AS industrial,
                    COALESCE(SUM(p.status = 'draft'), 0) AS draft, COALESCE(SUM(p.status = 'pending'), 0) AS pending,
                    COALESCE(SUM(p.status = 'active'), 0) AS active, COALESCE(SUM(p.status = 'paused'), 0) AS paused,
                    COALESCE(SUM(p.status IN ('rejected', 'changes_requested')), 0) AS attention,
                    COALESCE(SUM(p.status = 'expired'), 0) AS expired,
                    COALESCE(SUM((p.submitted_at IS NOT NULL OR p.published_at IS NOT NULL) AND p.created_at >= ? AND p.created_at < ?), 0) AS posted_in_range
               FROM properties p JOIN property_categories c ON c.id = p.category_id
              WHERE p.status <> 'deleted'",
            [$from, $to]
        );
        return array_map('intval', $row ?? []);
    }

    /** Engagement in the date range. @return array<string, int> */
    public static function engagement(string $from, string $to): array
    {
        $row = Database::one(
            "SELECT
                (SELECT COUNT(*) FROM login_activity WHERE event = 'login_success' AND context = 'site' AND created_at >= ? AND created_at < ?) AS logins,
                (SELECT COALESCE(SUM(views), 0) FROM property_view_daily WHERE view_date >= DATE(? + INTERVAL 330 MINUTE) AND view_date < DATE(? + INTERVAL 330 MINUTE)) AS views,
                (SELECT COUNT(*) FROM shortlists WHERE created_at >= ? AND created_at < ?) AS shortlists,
                (SELECT COUNT(*) FROM contact_history WHERE created_at >= ? AND created_at < ?) AS contacts,
                (SELECT COUNT(*) FROM enquiries WHERE created_at >= ? AND created_at < ?) AS enquiries,
                (SELECT COUNT(*) FROM bookings WHERE created_at >= ? AND created_at < ?) AS bookings",
            [$from, $to, $from, $to, $from, $to, $from, $to, $from, $to, $from, $to]
        );
        return array_map('intval', $row ?? []);
    }

    /** @return list<array> */
    public static function notifications(int $limit, bool $unreadOnly = false): array
    {
        return Database::all(
            "SELECT * FROM notifications WHERE audience = 'admin'" . ($unreadOnly ? ' AND read_at IS NULL' : '') . ' ORDER BY id DESC LIMIT ' . max(1, $limit)
        );
    }

    /** @return list<array> */
    public static function recentLogins(int $limit): array
    {
        return Database::all(
            'SELECT l.event, l.context, l.identifier, l.ip_address, l.created_at, u.id AS uid, u.first_name, u.last_name
               FROM login_activity l LEFT JOIN users u ON u.id = l.user_id ORDER BY l.id DESC LIMIT ' . max(1, $limit)
        );
    }

    /** @return array{rows: list<array>, total: int} */
    public static function notificationPage(bool $unreadOnly, int $page): array
    {
        $where = "audience = 'admin'" . ($unreadOnly ? ' AND read_at IS NULL' : '');
        return [
            'rows'  => Database::all("SELECT * FROM notifications WHERE {$where} ORDER BY id DESC LIMIT " . self::PER_PAGE . ' OFFSET ' . self::offset($page)),
            'total' => (int) Database::value("SELECT COUNT(*) FROM notifications WHERE {$where}"),
        ];
    }

    // ------------------------------------------------------------------
    // Properties
    // ------------------------------------------------------------------

    /**
     * @param array<string, mixed> $f q, purpose, category, city, locality, status, owner, featured
     * @return array{rows: list<array>, total: int}
     */
    public static function properties(array $f, int $page): array
    {
        $where = [];
        $params = [];
        $status = (string) ($f['status'] ?? '');
        if ($status === 'attention') {
            $where[] = "p.status IN ('rejected', 'changes_requested')";
        } elseif ($status !== '') {
            $where[] = 'p.status = ?';
            $params[] = $status;
        } else {
            $where[] = "p.status <> 'deleted'";
        }
        foreach (['purpose' => 'p.purpose', 'category' => 'p.category_id', 'city' => 'p.city_id', 'locality' => 'p.locality_id'] as $key => $col) {
            if (($f[$key] ?? '') !== '' && $f[$key] !== null) {
                $where[] = "{$col} = ?";
                $params[] = $f[$key];
            }
        }
        if (($f['owner'] ?? '') === 'system') {
            $where[] = 'p.owner_id IS NULL';
        } elseif (!empty($f['owner'])) {
            $where[] = 'p.owner_id = ?';
            $params[] = (int) $f['owner'];
        }
        if (!empty($f['featured'])) {
            $where[] = 'p.is_featured = 1';
        }
        $q = trim((string) ($f['q'] ?? ''));
        if ($q !== '') {
            [$sql, $qp] = self::propertySearch($q);
            $where[] = $sql;
            array_push($params, ...$qp);
        }
        $w = implode(' AND ', $where);
        $from = "FROM properties p
                 JOIN property_types t ON t.id = p.type_id
                 JOIN property_categories c ON c.id = p.category_id
            LEFT JOIN users u ON u.id = p.owner_id
            LEFT JOIN cities ci ON ci.id = p.city_id
            LEFT JOIN localities l ON l.id = p.locality_id
            LEFT JOIN property_media m ON m.id = p.cover_media_id";
        // Filter, count and sort on the narrow join; load the full rows for one page only.
        $lean = 'FROM properties p LEFT JOIN users u ON u.id = p.owner_id LEFT JOIN cities ci ON ci.id = p.city_id LEFT JOIN localities l ON l.id = p.locality_id';
        $ids = array_map('intval', array_column(Database::all(
            "SELECT p.id {$lean} WHERE {$w} ORDER BY " . ($status === 'pending' ? 'p.submitted_at ASC, p.id ASC' : 'p.updated_at DESC, p.id DESC')
            . ' LIMIT ' . self::PER_PAGE . ' OFFSET ' . self::offset($page),
            $params
        ), 'id'));
        $total = (int) Database::value("SELECT COUNT(*) {$lean} WHERE {$w}", $params);
        if (!$ids) {
            return ['rows' => [], 'total' => $total];
        }
        $in = implode(',', $ids);
        $rows = Database::all(
            "SELECT p.id, p.owner_id, p.created_by, p.purpose, p.status, p.title, p.slug, p.rent, p.is_featured, p.display_rank,
                    p.pending_rent_change_id, p.views_count, p.enquiries_count, p.submitted_at, p.published_at, p.created_at, p.updated_at,
                    t.name AS type_name, c.name AS category_name, ci.name AS city_name, COALESCE(l.name, p.locality_name) AS locality_name,
                    u.first_name, u.last_name, u.mobile AS owner_mobile, u.status AS owner_status,
                    m.storage_dir, m.file_key, m.has_webp, m.width, m.height
               {$from} WHERE p.id IN ({$in}) ORDER BY FIELD(p.id, {$in})"
        );
        return ['rows' => $rows, 'total' => $total];
    }

    /** @return array<string, int> status => count, for the filter tabs */
    public static function propertyStatusCounts(): array
    {
        $out = [];
        foreach (Database::all('SELECT status, COUNT(*) AS n FROM properties GROUP BY status') as $r) {
            $out[$r['status']] = (int) $r['n'];
        }
        return $out;
    }

    public static function property(int $id): ?array
    {
        return Database::one(
            "SELECT p.*, t.name AS type_name, t.code AS type_code, c.name AS category_name,
                    ci.name AS city_name, COALESCE(l.name, p.locality_name) AS locality_label,
                    u.first_name, u.last_name, u.mobile AS owner_mobile, u.email AS owner_email, u.status AS owner_status,
                    cb.first_name AS creator_first, cb.last_name AS creator_last
               FROM properties p
               JOIN property_types t ON t.id = p.type_id
               JOIN property_categories c ON c.id = p.category_id
          LEFT JOIN users u ON u.id = p.owner_id
          LEFT JOIN users cb ON cb.id = p.created_by
          LEFT JOIN cities ci ON ci.id = p.city_id
          LEFT JOIN localities l ON l.id = p.locality_id
              WHERE p.id = ?",
            [$id]
        );
    }

    /** @return list<array> */
    public static function statusHistory(int $propertyId): array
    {
        return Database::all(
            'SELECT h.*, u.first_name, u.last_name FROM property_status_history h LEFT JOIN users u ON u.id = h.changed_by
              WHERE h.property_id = ? ORDER BY h.id DESC LIMIT 50',
            [$propertyId]
        );
    }

    /** Active listings of one purpose in display order (spec 16, 57). @return list<array> */
    public static function orderList(string $purpose, int $limit = 200): array
    {
        return Database::all(
            "SELECT p.id, p.title, p.rent, p.display_rank, p.is_featured, p.featured_until, p.published_at,
                    t.name AS type_name, ci.name AS city_name, COALESCE(l.name, p.locality_name) AS locality_name
               FROM properties p
               JOIN property_types t ON t.id = p.type_id
          LEFT JOIN cities ci ON ci.id = p.city_id
          LEFT JOIN localities l ON l.id = p.locality_id
              WHERE p.status = 'active' AND p.purpose = ?
              ORDER BY p.display_rank ASC, p.published_at DESC, p.id DESC
              LIMIT " . max(1, $limit),
            [$purpose]
        );
    }

    // ------------------------------------------------------------------
    // Users
    // ------------------------------------------------------------------

    /** @return array{rows: list<array>, total: int} */
    public static function users(array $f, int $page): array
    {
        $where = [];
        $params = [];
        if (($f['status'] ?? '') === 'deleted') {
            $where[] = 'u.deleted_at IS NOT NULL';
        } else {
            $where[] = 'u.deleted_at IS NULL';
            if (in_array($f['status'] ?? '', ['active', 'inactive', 'suspended', 'blocked'], true)) {
                $where[] = 'u.status = ?';
                $params[] = $f['status'];
            }
        }
        if (in_array($f['role'] ?? '', ['viewer', 'owner', 'admin'], true)) {
            $where[] = 'EXISTS (SELECT 1 FROM user_roles r WHERE r.user_id = u.id AND r.role = ?)';
            $params[] = $f['role'];
        }
        $q = trim((string) ($f['q'] ?? ''));
        if ($q !== '') {
            [$sql, $qp] = self::userSearch($q);
            $where[] = $sql;
            array_push($params, ...$qp);
        }
        $w = implode(' AND ', $where);
        $rows = Database::all(
            "SELECT u.id, u.first_name, u.last_name, u.mobile, u.email, u.status, u.property_limit, u.property_limit_unlimited,
                    u.last_login_at, u.created_at, u.deleted_at, u.is_demo,
                    (SELECT GROUP_CONCAT(r.role ORDER BY r.role) FROM user_roles r WHERE r.user_id = u.id) AS roles,
                    (SELECT COUNT(*) FROM properties p WHERE p.owner_id = u.id AND p.status <> 'deleted') AS listings
               FROM users u WHERE {$w}
              ORDER BY u.id DESC LIMIT " . self::PER_PAGE . ' OFFSET ' . self::offset($page),
            $params
        );
        return ['rows' => $rows, 'total' => (int) Database::value("SELECT COUNT(*) FROM users u WHERE {$w}", $params)];
    }

    /** Includes deleted accounts (admins can still open them). */
    public static function user(int $id): ?array
    {
        $u = Database::one('SELECT * FROM users WHERE id = ?', [$id]);
        if ($u !== null) {
            unset($u['password_hash']);
            $u['roles'] = array_column(Database::all('SELECT role FROM user_roles WHERE user_id = ? ORDER BY role', [$id]), 'role');
        }
        return $u;
    }

    /** @return list<array> */
    public static function userProperties(int $userId): array
    {
        return Database::all(
            "SELECT p.id, p.title, p.purpose, p.status, p.rent, p.created_at, t.name AS type_name
               FROM properties p JOIN property_types t ON t.id = p.type_id
              WHERE p.owner_id = ? ORDER BY p.id DESC LIMIT 100",
            [$userId]
        );
    }

    /** @return list<array> */
    public static function userLogins(int $userId): array
    {
        return Database::all('SELECT * FROM login_activity WHERE user_id = ? ORDER BY id DESC LIMIT 20', [$userId]);
    }

    /** @return list<array> */
    public static function userActivity(int $userId): array
    {
        return Database::all('SELECT * FROM activity_logs WHERE user_id = ? ORDER BY id DESC LIMIT 20', [$userId]);
    }

    /** @return array<string, int> */
    public static function userCounts(int $userId): array
    {
        $row = Database::one(
            "SELECT
                (SELECT COUNT(*) FROM enquiries WHERE user_id = ?) AS enquiries_sent,
                (SELECT COUNT(*) FROM enquiries WHERE owner_id = ?) AS enquiries_received,
                (SELECT COUNT(*) FROM bookings WHERE user_id = ?) AS bookings_made,
                (SELECT COUNT(*) FROM bookings WHERE owner_id = ?) AS bookings_received,
                (SELECT COUNT(*) FROM shortlists WHERE user_id = ?) AS shortlists,
                (SELECT COUNT(*) FROM user_sessions WHERE user_id = ? AND revoked_at IS NULL) AS sessions",
            [$userId, $userId, $userId, $userId, $userId, $userId]
        );
        return array_map('intval', $row ?? []);
    }

    // ------------------------------------------------------------------
    // Rent changes, limit requests
    // ------------------------------------------------------------------

    /** @return array{rows: list<array>, total: int} */
    public static function rentChanges(?string $status, ?int $propertyId, int $page): array
    {
        $where = ['1 = 1'];
        $params = [];
        if ($status !== null && $status !== '') {
            $where[] = 'r.status = ?';
            $params[] = $status;
        }
        if ($propertyId) {
            $where[] = 'r.property_id = ?';
            $params[] = $propertyId;
        }
        $w = implode(' AND ', $where);
        return [
            'rows' => Database::all(
                "SELECT r.*, p.rent AS live_rent, p.status AS property_status, v.first_name AS reviewer_first, v.last_name AS reviewer_last
                   FROM rental_change_history r JOIN properties p ON p.id = r.property_id LEFT JOIN users v ON v.id = r.reviewed_by
                  WHERE {$w} ORDER BY " . ($status === 'pending' ? 'r.id ASC' : 'r.id DESC') . ' LIMIT ' . self::PER_PAGE . ' OFFSET ' . self::offset($page),
                $params
            ),
            'total' => (int) Database::value("SELECT COUNT(*) FROM rental_change_history r WHERE {$w}", $params),
        ];
    }

    /**
     * RENTAL CHANGE ALERT cards for the dashboard (spec 30): changes waiting
     * for approval (oldest first), then owner changes that went live in the
     * last 7 days.
     */
    public static function rentAlerts(int $limit = 4): array
    {
        return Database::all(
            "SELECT r.*, p.rent AS live_rent, p.pending_rent_change_id
               FROM rental_change_history r JOIN properties p ON p.id = r.property_id
              WHERE r.status = 'pending' OR (r.status = 'auto_applied' AND r.created_at > UTC_TIMESTAMP() - INTERVAL 7 DAY)
              ORDER BY (r.status = 'pending') DESC, IF(r.status = 'pending', r.id, -r.id)
              LIMIT " . max(1, $limit)
        );
    }

    public static function rentChange(int $id): ?array
    {
        return Database::one(
            "SELECT r.*, p.rent AS live_rent, p.status AS property_status, p.pending_rent_change_id, p.purpose, p.slug, p.title AS live_title,
                    v.first_name AS reviewer_first, v.last_name AS reviewer_last, cb.first_name AS changer_first, cb.last_name AS changer_last
               FROM rental_change_history r JOIN properties p ON p.id = r.property_id
          LEFT JOIN users v ON v.id = r.reviewed_by LEFT JOIN users cb ON cb.id = r.changed_by
              WHERE r.id = ?",
            [$id]
        );
    }

    /** @return array{rows: list<array>, total: int} */
    public static function limitRequests(?string $status, int $page): array
    {
        $w = $status ? 'r.status = ?' : '1 = 1';
        $params = $status ? [$status] : [];
        return [
            'rows' => Database::all(
                "SELECT r.*, u.first_name, u.last_name, u.mobile, u.email, u.property_limit, u.property_limit_unlimited,
                        (SELECT COUNT(*) FROM properties p WHERE p.owner_id = u.id AND p.status <> 'deleted') AS used_now
                   FROM property_limit_requests r JOIN users u ON u.id = r.user_id
                  WHERE {$w} ORDER BY " . ($status === 'pending' ? 'r.id ASC' : 'r.id DESC') . ' LIMIT ' . self::PER_PAGE . ' OFFSET ' . self::offset($page),
                $params
            ),
            'total' => (int) Database::value("SELECT COUNT(*) FROM property_limit_requests r WHERE {$w}", $params),
        ];
    }

    // ------------------------------------------------------------------
    // Enquiries, bookings, activity
    // ------------------------------------------------------------------

    /** Search by user, property, mobile and date (spec 60). @return array{rows: list<array>, total: int} */
    public static function enquiries(array $f, int $page): array
    {
        [$where, $params] = self::enquiryFilters($f);
        $w = implode(' AND ', $where);
        return [
            'rows' => Database::all(
                "SELECT e.*, o.first_name AS owner_first, o.last_name AS owner_last,
                        pc.name AS category_name, ci.name AS city_name, l.name AS locality_name
                   FROM enquiries e LEFT JOIN users o ON o.id = e.owner_id
                   LEFT JOIN property_categories pc ON pc.id = e.category_id
                   LEFT JOIN cities ci ON ci.id = e.city_id
                   LEFT JOIN localities l ON l.id = e.locality_id
                  WHERE {$w} ORDER BY e.id DESC LIMIT " . self::PER_PAGE . ' OFFSET ' . self::offset($page),
                $params
            ),
            'total' => (int) Database::value("SELECT COUNT(*) FROM enquiries e WHERE {$w}", $params),
        ];
    }

    /** @return array{rows: list<array>, total: int} */
    public static function bookings(array $f, int $page): array
    {
        $where = ['1 = 1'];
        $params = [];
        if (in_array($f['status'] ?? '', ['pending', 'accepted', 'rejected', 'rescheduled', 'completed', 'cancelled'], true)) {
            $where[] = 'b.status = ?';
            $params[] = $f['status'];
        }
        foreach (['user' => 'b.user_id', 'owner' => 'b.owner_id', 'property' => 'b.property_id'] as $key => $col) {
            if (!empty($f[$key])) {
                $where[] = "{$col} = ?";
                $params[] = (int) $f[$key];
            }
        }
        $q = trim((string) ($f['q'] ?? ''));
        if ($q !== '') {
            $digits = preg_replace('/\D/', '', $q);
            $parts = ['b.property_title LIKE ?', "CONCAT(v.first_name, ' ', v.last_name) LIKE ?"];
            array_push($params, '%' . $q . '%', '%' . $q . '%');
            if (ctype_digit(ltrim($q, '#p')) && $digits !== '') {
                $parts[] = 'b.property_id = ?';
                $params[] = (int) $digits;
            }
            if (strlen($digits) >= 6) {
                $parts[] = 'v.mobile LIKE ?';
                $params[] = '%' . $digits . '%';
            }
            $where[] = '(' . implode(' OR ', $parts) . ')';
        }
        self::dateFilter($f, 'b.created_at', $where, $params);
        $w = implode(' AND ', $where);
        $from = 'FROM bookings b JOIN users v ON v.id = b.user_id LEFT JOIN users o ON o.id = b.owner_id';
        return [
            'rows' => Database::all(
                "SELECT b.*, v.first_name, v.last_name, v.mobile, o.first_name AS owner_first, o.last_name AS owner_last
                   {$from} WHERE {$w} ORDER BY b.id DESC LIMIT " . self::PER_PAGE . ' OFFSET ' . self::offset($page),
                $params
            ),
            'total' => (int) Database::value("SELECT COUNT(*) {$from} WHERE {$w}", $params),
        ];
    }

    /** @return array{rows: list<array>, total: int} */
    public static function adminLog(array $f, int $page): array
    {
        $where = ['1 = 1'];
        $params = [];
        if (!empty($f['entity']) && !empty($f['id'])) {
            $where[] = 'a.entity_type = ? AND a.entity_id = ?';
            array_push($params, $f['entity'], (int) $f['id']);
        }
        if (!empty($f['admin'])) {
            $where[] = 'a.admin_id = ?';
            $params[] = (int) $f['admin'];
        }
        if (!empty($f['action'])) {
            $where[] = 'a.action LIKE ?';
            $params[] = str_replace(['%', '_'], ['\%', '\_'], (string) $f['action']) . '%';
        }
        self::dateFilter($f, 'a.created_at', $where, $params);
        $w = implode(' AND ', $where);
        return [
            'rows' => Database::all(
                "SELECT a.*, u.first_name, u.last_name FROM admin_activity_logs a LEFT JOIN users u ON u.id = a.admin_id
                  WHERE {$w} ORDER BY a.id DESC LIMIT " . self::PER_PAGE . ' OFFSET ' . self::offset($page),
                $params
            ),
            'total' => (int) Database::value("SELECT COUNT(*) FROM admin_activity_logs a WHERE {$w}", $params),
        ];
    }

    /** @return array{rows: list<array>, total: int} */
    public static function userEvents(array $f, int $page): array
    {
        $where = ['1 = 1'];
        $params = [];
        if (!empty($f['event'])) {
            $where[] = 'a.event LIKE ?';
            $params[] = str_replace(['%', '_'], ['\%', '\_'], (string) $f['event']) . '%';
        }
        self::dateFilter($f, 'a.created_at', $where, $params);
        $w = implode(' AND ', $where);
        return [
            'rows' => Database::all(
                "SELECT a.*, u.first_name, u.last_name FROM activity_logs a LEFT JOIN users u ON u.id = a.user_id
                  WHERE {$w} ORDER BY a.id DESC LIMIT " . self::PER_PAGE . ' OFFSET ' . self::offset($page),
                $params
            ),
            'total' => (int) Database::value("SELECT COUNT(*) FROM activity_logs a WHERE {$w}", $params),
        ];
    }

    /** @return list<array> id, name of admin accounts (for the log filter) */
    public static function admins(): array
    {
        return Database::all("SELECT DISTINCT u.id, u.first_name, u.last_name FROM users u JOIN user_roles r ON r.user_id = u.id AND r.role IN ('admin', 'super_admin') ORDER BY u.first_name");
    }

    // ------------------------------------------------------------------

    public static function offset(int $page): int
    {
        return (max(1, $page) - 1) * self::PER_PAGE;
    }

    /**
     * Property search: "10001", "#10001", "p10001" match the id; otherwise
     * title, city, locality, owner name, owner or contact mobile.
     * @return array{0: string, 1: list<mixed>}
     */
    private static function propertySearch(string $q): array
    {
        if (preg_match('/^[#pP]?(\d{4,})$/', $q, $m) && strlen($m[1]) <= 9) {
            return ['p.id = ?', [(int) $m[1]]];
        }
        $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $q) . '%';
        $parts = ['p.title LIKE ?', 'ci.name LIKE ?', 'l.name LIKE ?', 'p.locality_name LIKE ?', "CONCAT(u.first_name, ' ', u.last_name) LIKE ?"];
        $params = [$like, $like, $like, $like, $like];
        $digits = preg_replace('/\D/', '', $q);
        if (strlen($digits) >= 6) {
            $parts[] = 'u.mobile LIKE ?';
            $parts[] = 'p.contact_mobile_1 LIKE ?';
            array_push($params, '%' . $digits . '%', '%' . $digits . '%');
        }
        if (str_contains($q, '@')) {
            $parts[] = 'u.email = ?';
            $params[] = strtolower($q);
        }
        return ['(' . implode(' OR ', $parts) . ')', $params];
    }

    /** @return array{0: string, 1: list<mixed>} */
    private static function userSearch(string $q): array
    {
        if (preg_match('/^#(\d+)$/', $q, $m)) {
            return ['u.id = ?', [(int) $m[1]]];
        }
        $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $q) . '%';
        $parts = ["CONCAT(u.first_name, ' ', u.last_name) LIKE ?", 'u.email LIKE ?'];
        $params = [$like, $like];
        $digits = preg_replace('/\D/', '', $q);
        if (strlen($digits) >= 4) {
            $parts[] = 'u.mobile LIKE ?';
            $params[] = '%' . $digits . '%';
        }
        return ['(' . implode(' OR ', $parts) . ')', $params];
    }

    /** @return array{0: list<string>, 1: list<mixed>} */
    private static function enquiryFilters(array $f): array
    {
        $where = ['1 = 1'];
        $params = [];
        if (in_array($f['status'] ?? '', ['new', 'contacted', 'in_progress', 'closed'], true)) {
            $where[] = 'e.status = ?';
            $params[] = $f['status'];
        }
        if (in_array($f['source'] ?? '', ['property_page', 'general_form'], true)) {
            $where[] = 'e.source = ?';
            $params[] = $f['source'];
        }
        foreach (['user' => 'e.user_id', 'owner' => 'e.owner_id', 'property' => 'e.property_id'] as $key => $col) {
            if (!empty($f[$key])) {
                $where[] = "{$col} = ?";
                $params[] = (int) $f[$key];
            }
        }
        $q = trim((string) ($f['q'] ?? ''));
        if ($q !== '') {
            $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $q) . '%';
            $parts = ["CONCAT(e.first_name, ' ', e.last_name) LIKE ?", 'e.email LIKE ?', 'e.property_title LIKE ?'];
            array_push($params, $like, $like, $like);
            $digits = preg_replace('/\D/', '', $q);
            if (preg_match('/^[#pP]?\d{4,9}$/', $q)) {
                $parts[] = 'e.property_id = ?';
                $params[] = (int) $digits;
            }
            if (strlen($digits) >= 6) {
                $parts[] = 'e.mobile_1 LIKE ?';
                $params[] = '%' . $digits . '%';
            }
            $where[] = '(' . implode(' OR ', $parts) . ')';
        }
        self::dateFilter($f, 'e.created_at', $where, $params);
        return [$where, $params];
    }

    /** ?from=YYYY-MM-DD&to=YYYY-MM-DD in India time → UTC range on $col. */
    private static function dateFilter(array $f, string $col, array &$where, array &$params): void
    {
        foreach (['from' => '>=', 'to' => '<'] as $key => $op) {
            $d = (string) ($f[$key] ?? '');
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) || !checkdate((int) substr($d, 5, 2), (int) substr($d, 8, 2), (int) substr($d, 0, 4))) {
                continue;
            }
            $dt = new \DateTimeImmutable($d . ' 00:00:00', new \DateTimeZone('Asia/Kolkata'));
            if ($key === 'to') {
                $dt = $dt->modify('+1 day');
            }
            $where[] = "{$col} {$op} ?";
            $params[] = $dt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        }
    }
}
