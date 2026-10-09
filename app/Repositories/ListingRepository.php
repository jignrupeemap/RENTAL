<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use App\Core\Settings;

/**
 * Public listing queries (spec 20-22, 38, 64). Only listings visitors may see:
 * status active, not past expiry, and owned by an active account (or by
 * admin/system). Filtering, sorting and paging happen in SQL on the indexed
 * columns, never in PHP.
 */
final class ListingRepository
{
    /**
     * Only the join VISIBLE needs. Lists pick and sort ids on this narrow
     * join first, then load names and covers for just one page of rows
     * (sorting 20,000 wide joined rows was the slowest part of a page).
     */
    private const LEAN_FROM = 'FROM properties p LEFT JOIN users o ON o.id = p.owner_id';

    private const FEATURED_NOW = '(p.is_featured = 1 AND (p.featured_until IS NULL OR p.featured_until > UTC_TIMESTAMP()))';

    /** Joins + WHERE fragment shared by every public query. Alias p = properties. */
    public const FROM = 'FROM properties p
        LEFT JOIN users o ON o.id = p.owner_id
        JOIN property_types t ON t.id = p.type_id
        JOIN property_categories c ON c.id = p.category_id
        LEFT JOIN cities ci ON ci.id = p.city_id
        LEFT JOIN localities l ON l.id = p.locality_id';

    public const VISIBLE = "p.status = 'active'
        AND (p.expires_at IS NULL OR p.expires_at > UTC_TIMESTAMP())
        AND (p.owner_id IS NULL OR (o.status = 'active' AND o.deleted_at IS NULL))";

    private const COLUMNS = 'p.id, p.purpose, p.slug, p.title, p.rent, p.security_deposit, p.bedrooms, p.bathrooms, p.furnishing,
        p.area_value, p.area_unit, p.pg_available_for, p.pg_suitable_for, p.cover_media_id, p.published_at,
        p.city_id, p.locality_id, p.category_id, p.type_id,
        (p.is_featured = 1 AND (p.featured_until IS NULL OR p.featured_until > UTC_TIMESTAMP())) AS featured_now,
        t.name AS type_name, t.field_profile, c.code AS category_code, c.name AS category_name,
        ci.name AS city_name, COALESCE(l.name, p.locality_name) AS locality_label';

    public const SORTS = [
        'recommended' => 'Recommended',
        'newest'      => 'Newest first',
        'rent_asc'    => 'Rent: low to high',
        'rent_desc'   => 'Rent: high to low',
    ];

    /** Budget presets from settings (spec 20), each ['label','min','max'|null]. */
    public static function budgets(): array
    {
        $raw = Settings::get('budget_ranges', []);
        $list = is_array($raw) ? $raw : (json_decode((string) $raw, true) ?: []);
        return array_values(array_filter($list, static fn ($b) => is_array($b) && isset($b['label'])));
    }

    /**
     * @param array{purpose:string, city?:?int, locality?:?int, category?:?int, type?:?int, min?:?int, max?:?int,
     *              bedrooms?:?int, furnishing?:?string, pg_for?:?string, pg_suitable?:?string, sort?:string} $f
     * @return array{rows: list<array>, total: int}
     */
    public static function search(array $f, int $page, int $perPage): array
    {
        [$where, $params] = self::where($f);
        $total = (int) Database::value('SELECT COUNT(*) ' . self::LEAN_FROM . ' WHERE ' . $where, $params);
        $order = match ($f['sort'] ?? 'recommended') {
            'newest'    => 'p.published_at DESC, p.id DESC',
            'rent_asc'  => 'p.rent IS NULL, p.rent ASC, p.id DESC',
            'rent_desc' => 'p.rent DESC, p.id DESC',
            // Spec 16: featured first, then the admin's display order, then newest.
            default     => self::FEATURED_NOW . ' DESC, p.display_rank ASC, p.published_at DESC, p.id DESC',
        };
        $ids = self::pickIds($where . ' ORDER BY ' . $order . ' LIMIT ' . (int) $perPage . ' OFFSET ' . (int) (max(0, $page - 1) * $perPage), $params);
        return ['rows' => self::rowsFor($ids), 'total' => $total];
    }

    /** @return array{0: string, 1: list<mixed>} */
    private static function where(array $f): array
    {
        $sql = [self::VISIBLE, 'p.purpose = ?'];
        $params = [$f['purpose']];
        $eq = ['city' => 'p.city_id', 'locality' => 'p.locality_id', 'category' => 'p.category_id', 'type' => 'p.type_id',
            'furnishing' => 'p.furnishing', 'pg_for' => 'p.pg_available_for', 'pg_suitable' => 'p.pg_suitable_for'];
        foreach ($eq as $key => $col) {
            if (!empty($f[$key])) {
                $sql[] = $col . ' = ?';
                $params[] = $f[$key];
            }
        }
        if (!empty($f['min'])) {
            $sql[] = 'p.rent >= ?';
            $params[] = (int) $f['min'];
        }
        if (!empty($f['max'])) {
            $sql[] = 'p.rent <= ?';
            $params[] = (int) $f['max'];
        }
        if (!empty($f['bedrooms'])) {
            // 1, 2, 3 = exact; 4 = "4+" (stored 5 means 5+)
            $sql[] = (int) $f['bedrooms'] >= 4 ? 'p.bedrooms >= ?' : 'p.bedrooms = ?';
            $params[] = (int) $f['bedrooms'];
        }
        return [implode(' AND ', $sql), $params];
    }

    /** Featured listings first, then newest, across all purposes (homepage). */
    public static function homepage(int $limit): array
    {
        // Two index-backed reads instead of sorting every live listing: the
        // (few) featured ones first, then the newest of the rest.
        $ids = self::pickIds(self::VISIBLE . ' AND ' . self::FEATURED_NOW . ' ORDER BY p.published_at DESC, p.id DESC LIMIT ' . (int) $limit);
        if (count($ids) < $limit) {
            $ids = [...$ids, ...self::pickIds(self::VISIBLE . ($ids ? ' AND p.id NOT IN (' . implode(',', $ids) . ')' : '')
                . ' ORDER BY p.published_at DESC, p.id DESC LIMIT ' . ($limit - count($ids)))];
        }
        return self::rowsFor($ids);
    }

    /** Count of visible listings per purpose, for the homepage tabs. */
    public static function counts(): array
    {
        $rows = Database::all('SELECT p.purpose, COUNT(*) AS n ' . self::LEAN_FROM . ' WHERE ' . self::VISIBLE . ' GROUP BY p.purpose');
        return array_column($rows, 'n', 'purpose') + ['rent' => 0, 'pg' => 0, 'lease' => 0];
    }

    /** One listing if visitors may see it, with names joined in. */
    public static function find(int $id): ?array
    {
        return Database::one(
            'SELECT p.*, ' . self::COLUMNS . ', ci.state AS city_state ' . self::FROM . ' WHERE p.id = ? AND ' . self::VISIBLE,
            [$id]
        );
    }

    /** Up to $limit other visible listings with the same purpose, same city first. */
    public static function similar(array $p, int $limit = 4): array
    {
        return self::rowsFor(self::pickIds(
            self::VISIBLE . ' AND p.purpose = ? AND p.id <> ?
             ORDER BY (p.city_id <=> ?) DESC, (p.category_id = ?) DESC, ' . self::FEATURED_NOW . ' DESC, p.published_at DESC, p.id DESC LIMIT ' . (int) $limit,
            [$p['purpose'], $p['id'], $p['city_id'], $p['category_id']]
        ));
    }

    /** @return list<int> ids matching "$whereOrderLimit" on the narrow join */
    private static function pickIds(string $whereOrderLimit, array $params = []): array
    {
        return array_map('intval', array_column(Database::all('SELECT p.id ' . self::LEAN_FROM . ' WHERE ' . $whereOrderLimit, $params), 'id'));
    }

    /** Full card rows (names, cover photo) for $ids, in the same order. */
    private static function rowsFor(array $ids): array
    {
        if (!$ids) {
            return [];
        }
        $in = implode(',', array_map('intval', $ids));
        $rows = Database::all('SELECT ' . self::COLUMNS . ' ' . self::FROM . " WHERE p.id IN ($in) ORDER BY FIELD(p.id, $in)");
        return self::withCovers($rows);
    }

    /** Ids from $ids the user has shortlisted. */
    public static function shortlistedIds(?int $userId, array $ids): array
    {
        if ($userId === null || !$ids) {
            return [];
        }
        $in = implode(',', array_map('intval', $ids));
        return array_map('intval', array_column(
            Database::all("SELECT property_id FROM shortlists WHERE user_id = ? AND property_id IN ($in)", [$userId]),
            'property_id'
        ));
    }

    /** Every visible listing for the sitemap. */
    public static function sitemap(int $limit = 45000): array
    {
        return Database::all('SELECT p.id, p.purpose, p.slug, p.updated_at ' . self::FROM . ' WHERE ' . self::VISIBLE
            . ' ORDER BY p.id DESC LIMIT ' . (int) $limit);
    }

    /**
     * Attach the cover photo (or first photo) to each row as 'cover', in one
     * query for the whole page.
     */
    public static function withCovers(array $rows): array
    {
        if (!$rows) {
            return [];
        }
        $ids = array_map(static fn ($r) => (int) $r['id'], $rows);
        $first = [];
        $pick = static function (string $extra, array $ids) use (&$first): void {
            $in = implode(',', $ids);
            foreach (Database::all("SELECT * FROM property_media WHERE property_id IN ($in) AND media_type = 'image' AND status = 'active' $extra
                                    ORDER BY property_id, sort_order, id") as $m) {
                $first[(int) $m['property_id']] ??= $m;
            }
        };
        $pick('AND is_cover = 1', $ids);
        // Listings whose cover flag is missing fall back to their first photo.
        if ($missing = array_values(array_diff($ids, array_keys($first)))) {
            $pick('', $missing);
        }
        foreach ($rows as &$r) {
            $r['cover'] = $first[(int) $r['id']] ?? null;
        }
        return $rows;
    }
}
