<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Settings;

/**
 * Listing lifecycle behind the posting form: create draft (limit enforced
 * under a row lock), save wizard steps, submit for approval, delete a draft.
 * Every owner call re-reads the row with owner_id = the logged-in user, so
 * an id from the URL can never reach someone else's listing. Admin calls
 * ($asAdmin = true, only from /admin routes) may open any listing, skip the
 * limit, and their rent changes go live at once; they are logged in
 * admin_activity_logs instead of sending the listing back for approval.
 */
final class PropertyService
{
    /** Listing statuses an owner may submit for approval from. */
    public const SUBMITTABLE = ['draft', 'rejected', 'changes_requested'];

    /**
     * @return array{0: ?int, 1: ?string} [new property id, error]
     */
    public static function createDraft(int $uid, string $purpose, array $type, string $ip): array
    {
        return Database::transaction(function () use ($uid, $purpose, $type, $ip): array {
            // Lock the owner's row so two tabs cannot both pass the limit check.
            Database::run('SELECT id FROM users WHERE id = ? FOR UPDATE', [$uid]);
            if (PropertyLimitService::usage($uid)['reached']) {
                return [null, 'limit'];
            }
            $id = Database::insert(
                "INSERT INTO properties (owner_id, created_by, purpose, category_id, type_id, status, draft_step, slug)
                 VALUES (?, ?, ?, ?, ?, 'draft', 1, ?)",
                [$uid, $uid, $purpose, $type['category_id'], $type['id'], slugify($type['name'] . ($purpose === 'pg' ? ' pg' : ''))]
            );
            // Same account becomes an owner with its first listing (spec 3).
            Database::run("INSERT IGNORE INTO user_roles (user_id, role) VALUES (?, 'owner')", [$uid]);
            Database::run("INSERT INTO property_status_history (property_id, old_status, new_status, changed_by, actor_role, reason)
                           VALUES (?, NULL, 'draft', ?, 'owner', 'Draft started')", [$id, $uid]);
            ActivityLog::event($uid, 'property.created', 'property', $id, ['purpose' => $purpose, 'type' => $type['code']], $ip);
            return [$id, null];
        });
    }

    /**
     * Admin "Add Property": no posting limit; owned by $ownerId (an existing
     * account, which gains the owner role) or by admin/system when null.
     */
    public static function createForAdmin(int $adminId, ?int $ownerId, string $purpose, array $type, string $ip): int
    {
        return Database::transaction(function () use ($adminId, $ownerId, $purpose, $type): int {
            $id = Database::insert(
                "INSERT INTO properties (owner_id, created_by, purpose, category_id, type_id, status, draft_step, slug)
                 VALUES (?, ?, ?, ?, ?, 'draft', 1, ?)",
                [$ownerId, $adminId, $purpose, $type['category_id'], $type['id'], slugify($type['name'] . ($purpose === 'pg' ? ' pg' : ''))]
            );
            if ($ownerId !== null) {
                Database::run("INSERT IGNORE INTO user_roles (user_id, role, granted_by) VALUES (?, 'owner', ?)", [$ownerId, $adminId]);
            }
            Database::run("INSERT INTO property_status_history (property_id, old_status, new_status, changed_by, actor_role, reason)
                           VALUES (?, NULL, 'draft', ?, 'admin', 'Created by admin')", [$id, $adminId]);
            Admin\AdminLog::record($adminId, 'property.created', 'property', $id, null,
                ['purpose' => $purpose, 'type' => $type['code'], 'owner' => $ownerId !== null ? '#' . $ownerId : 'Admin / system']);
            return $id;
        });
    }

    /** Switch a draft to another purpose/type and clear every field the new type does not use. */
    public static function changeType(int $propertyId, int $uid, string $purpose, array $type, bool $asAdmin = false): void
    {
        Database::transaction(function () use ($propertyId, $uid, $purpose, $type, $asAdmin): void {
            $p = self::lock($propertyId, $uid, $asAdmin);
            if ($p === null || $p['status'] !== 'draft') {
                throw new \RuntimeException('type change on non-draft');
            }
            $profile = PropertyForm::profile($purpose, $type);
            $sets = ['purpose = ?', 'category_id = ?', 'type_id = ?'];
            $params = [$purpose, $type['category_id'], $type['id']];
            foreach (PropertyForm::unusedColumns($profile, $type) as $col) {
                $sets[] = "{$col} = NULL";
            }
            $params[] = $propertyId;
            Database::run('UPDATE properties SET ' . implode(', ', $sets) . ' WHERE id = ?', $params);

            $allowed = array_map('intval', array_column(PropertyForm::amenities($profile), 'id')) ?: [0];
            Database::run('DELETE FROM property_amenities WHERE property_id = ? AND amenity_id NOT IN (' . implode(',', $allowed) . ')', [$propertyId]);
            if ($profile !== 'pg') {
                Database::run('DELETE FROM property_pg_services WHERE property_id = ?', [$propertyId]);
            }
            self::refreshSlug($propertyId, $type);
        });
    }

    /**
     * Save one validated wizard step.
     * @param array $res output of PropertyForm::validate()
     * @return array{changed: bool, rent: ?string, reapproval: bool}
     */
    public static function saveStep(int $propertyId, int $uid, int $step, array $res, bool $complete, string $ip, bool $asAdmin = false): array
    {
        return Database::transaction(function () use ($propertyId, $uid, $step, $res, $complete, $ip, $asAdmin): array {
            $p = self::lock($propertyId, $uid, $asAdmin);
            if ($p === null) {
                throw new \RuntimeException('property not found');
            }
            $type = PropertyForm::type((int) $p['type_id']);
            $data = $res['data'];
            $rent = null;

            // Published listings: rent goes through the change-approval flow, never straight in.
            if ($step === 5 && $p['published_at'] !== null && array_key_exists('rent', $data)) {
                $newRent = $data['rent'];
                unset($data['rent']);
                if ($newRent !== null) {
                    $rent = RentChangeService::change($p, (int) $newRent, $uid, $ip, $asAdmin);
                }
            }

            $changed = false;
            foreach ($data as $col => $v) {
                if (!self::same($p[$col] ?? null, $v)) {
                    $changed = true;
                    break;
                }
            }
            if ($data) {
                $cols = array_keys($data);
                Database::run(
                    'UPDATE properties SET ' . implode(', ', array_map(static fn ($c) => "{$c} = ?", $cols)) . ' WHERE id = ?',
                    [...array_values($data), $propertyId]
                );
            }
            if ($res['location'] !== null) {
                $changed = self::saveLocation($propertyId, $res['location']) || $changed;
            }
            if ($res['amenities'] !== null) {
                $changed = self::replaceAmenities($propertyId, $res['amenities']) || $changed;
            }
            if ($res['services'] !== null) {
                $changed = self::replaceServices($propertyId, $res['services']) || $changed;
            }
            if ($complete && (int) $p['draft_step'] < $step) {
                Database::run('UPDATE properties SET draft_step = ? WHERE id = ?', [$step, $propertyId]);
            }
            if (in_array($step, [2, 3], true)) {
                self::refreshSlug($propertyId, $type);
            }

            $reapproval = false;
            if ($changed) {
                $reapproval = self::recordEdit($p, $uid, $ip, ['step' => $step], $asAdmin);
            } elseif ($asAdmin) {
                // rent changes by an admin are logged by RentChangeService
            } elseif ($rent !== null && $p['status'] !== 'draft') {
                ActivityLog::event($uid, 'property.edited', 'property', $propertyId, ['step' => $step], $ip);
            }
            return ['changed' => $changed, 'rent' => $rent, 'reapproval' => $reapproval];
        });
    }

    /**
     * @return array{0: bool, 1: array<int, list<string>>} [submitted, missing fields by step]
     */
    public static function submit(int $propertyId, int $uid, string $ip): array
    {
        return Database::transaction(function () use ($propertyId, $uid, $ip): array {
            $p = self::lock($propertyId, $uid, false);
            if ($p === null || !in_array($p['status'], self::SUBMITTABLE, true)) {
                return [false, []];
            }
            $missing = PropertyForm::missing($p, PropertyForm::type((int) $p['type_id']));
            if ($missing) {
                return [false, $missing];
            }
            Database::run("UPDATE properties SET status = 'pending', submitted_at = UTC_TIMESTAMP(), draft_step = ?, rejection_reason = NULL WHERE id = ?",
                [PropertyForm::LAST_STEP, $propertyId]);
            $again = $p['status'] !== 'draft';
            Database::run("INSERT INTO property_status_history (property_id, old_status, new_status, changed_by, actor_role, reason)
                           VALUES (?, ?, 'pending', ?, 'owner', ?)", [$propertyId, $p['status'], $uid, $again ? 'Resubmitted by owner' : 'Submitted for approval']);
            NotificationService::toAdmins(
                $again ? 'property_resubmitted' : 'property_submitted',
                ($again ? 'Listing resubmitted: ' : 'New property submitted: ') . mb_substr((string) $p['title'], 0, 100),
                'Property #' . $propertyId . ' · ' . purpose_label((string) $p['purpose']) . ' · waiting for approval',
                '/admin/properties/' . $propertyId,
                'property',
                $propertyId
            );
            ActivityLog::event($uid, 'property.submitted', 'property', $propertyId, [], $ip);
            return [true, []];
        });
    }

    /** Owners can discard their own drafts (soft delete; frees the slot in the limit). */
    public static function deleteDraft(int $propertyId, int $uid, string $ip): bool
    {
        return Database::transaction(function () use ($propertyId, $uid, $ip): bool {
            $p = self::lock($propertyId, $uid, false);
            if ($p === null || $p['status'] !== 'draft') {
                return false;
            }
            Database::run("UPDATE properties SET status = 'deleted', deleted_at = UTC_TIMESTAMP() WHERE id = ?", [$propertyId]);
            Database::run("INSERT INTO property_status_history (property_id, old_status, new_status, changed_by, actor_role, reason)
                           VALUES (?, 'draft', 'deleted', ?, 'owner', 'Draft discarded by owner')", [$propertyId, $uid]);
            ActivityLog::event($uid, 'property.draft_deleted', 'property', $propertyId, [], $ip);
            return true;
        });
    }

    /**
     * After an owner changes a submitted/live listing (details or photos):
     * log it and, when "Re-approve after owner edits" is on, send an
     * active listing back to Pending. Call inside the transaction.
     * @return bool true when the listing went back to Pending
     */
    public static function recordOwnerEdit(array $p, int $uid, string $ip, array $meta = []): bool
    {
        return self::recordEdit($p, $uid, $ip, $meta, false);
    }

    /** recordOwnerEdit(), or for an admin: log it in admin_activity_logs and never re-queue the listing. */
    public static function recordEdit(array $p, int $uid, string $ip, array $meta, bool $asAdmin): bool
    {
        if ($asAdmin) {
            Admin\AdminLog::record($uid, 'property.edited', 'property', (int) $p['id'], null, $meta);
            return false;
        }
        if ($p['status'] === 'draft') {
            return false;
        }
        $id = (int) $p['id'];
        ActivityLog::event($uid, 'property.edited', 'property', $id, $meta, $ip);
        if (!in_array($p['status'], ['active', 'approved'], true) || !Settings::bool('reapprove_on_owner_edit')) {
            return false;
        }
        Database::run("UPDATE properties SET status = 'pending', submitted_at = UTC_TIMESTAMP() WHERE id = ?", [$id]);
        Database::run("INSERT INTO property_status_history (property_id, old_status, new_status, changed_by, actor_role, reason)
                       VALUES (?, ?, 'pending', ?, 'owner', 'Edited by owner; waiting for re-approval')", [$id, $p['status'], $uid]);
        NotificationService::toAdmins('property_edited', 'Edited listing needs approval: ' . $p['title'],
            'Property #' . $id, '/admin/properties/' . $id, 'property', $id);
        return true;
    }

    // ------------------------------------------------------------------

    /** Owner: only their own listing. Admin: any listing that is not deleted. */
    public static function lock(int $propertyId, int $uid, bool $asAdmin): ?array
    {
        if ($asAdmin) {
            return Database::one("SELECT * FROM properties WHERE id = ? AND status <> 'deleted' FOR UPDATE", [$propertyId]);
        }
        return Database::one("SELECT * FROM properties WHERE id = ? AND owner_id = ? AND status <> 'deleted' FOR UPDATE", [$propertyId, $uid]);
    }

    private static function same(mixed $a, mixed $b): bool
    {
        $emptyA = $a === null || $a === '';
        $emptyB = $b === null || $b === '';
        if ($emptyA || $emptyB) {
            return $emptyA && $emptyB;
        }
        if (is_numeric($a) && is_numeric($b)) {
            return (float) $a === (float) $b;
        }
        return (string) $a === (string) $b;
    }

    private static function refreshSlug(int $propertyId, array $type): void
    {
        $p = Database::one('SELECT id, purpose, bedrooms, city_id, locality_id, locality_name FROM properties WHERE id = ?', [$propertyId]);
        Database::run('UPDATE properties SET slug = ? WHERE id = ?', [PropertyForm::slugFor($p, $type), $propertyId]);
    }

    /** Saves the pin. Approximate privacy stores a fixed offset of 150-400 m, chosen once per pin. */
    private static function saveLocation(int $propertyId, array $loc): bool
    {
        $old = Database::one('SELECT * FROM property_locations WHERE property_id = ?', [$propertyId]);
        $samePin = $old !== null && (float) $old['latitude'] === $loc['latitude'] && (float) $old['longitude'] === $loc['longitude'];
        if ($samePin && $old['privacy'] === $loc['privacy']
            && (string) $old['formatted_address'] === (string) $loc['formatted_address']) {
            return false;
        }
        if ($loc['privacy'] === 'exact') {
            [$pubLat, $pubLng] = [$loc['latitude'], $loc['longitude']];
        } elseif ($samePin && $old['privacy'] === 'approximate') {
            [$pubLat, $pubLng] = [(float) $old['public_latitude'], (float) $old['public_longitude']];
        } else {
            $distance = random_int(150, 400);
            $bearing = deg2rad(random_int(0, 359));
            $pubLat = round($loc['latitude'] + ($distance * cos($bearing)) / 111320, 7);
            $pubLng = round($loc['longitude'] + ($distance * sin($bearing)) / (111320 * cos(deg2rad($loc['latitude']))), 7);
        }
        Database::run(
            'INSERT INTO property_locations (property_id, latitude, longitude, public_latitude, public_longitude, formatted_address, google_place_id, privacy)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE latitude = VALUES(latitude), longitude = VALUES(longitude), public_latitude = VALUES(public_latitude),
                 public_longitude = VALUES(public_longitude), formatted_address = VALUES(formatted_address),
                 google_place_id = VALUES(google_place_id), privacy = VALUES(privacy)',
            [$propertyId, $loc['latitude'], $loc['longitude'], $pubLat, $pubLng, $loc['formatted_address'], $loc['google_place_id'], $loc['privacy']]
        );
        return true;
    }

    /** @param list<int> $ids already filtered to this type's amenities */
    private static function replaceAmenities(int $propertyId, array $ids): bool
    {
        $old = array_map('intval', array_column(Database::all('SELECT amenity_id FROM property_amenities WHERE property_id = ?', [$propertyId]), 'amenity_id'));
        sort($old);
        sort($ids);
        if ($old === $ids) {
            return false;
        }
        Database::run('DELETE FROM property_amenities WHERE property_id = ?', [$propertyId]);
        foreach ($ids as $id) {
            Database::run('INSERT INTO property_amenities (property_id, amenity_id) VALUES (?, ?)', [$propertyId, $id]);
        }
        return true;
    }

    /** @param array<string, ?int> $services code => monthly price */
    private static function replaceServices(int $propertyId, array $services): bool
    {
        $old = [];
        foreach (Database::all('SELECT service, price FROM property_pg_services WHERE property_id = ?', [$propertyId]) as $r) {
            $old[$r['service']] = $r['price'] === null ? null : (int) $r['price'];
        }
        ksort($old);
        ksort($services);
        if ($old === $services) {
            return false;
        }
        Database::run('DELETE FROM property_pg_services WHERE property_id = ?', [$propertyId]);
        foreach ($services as $code => $price) {
            Database::run('INSERT INTO property_pg_services (property_id, service, price) VALUES (?, ?, ?)', [$propertyId, $code, $price]);
        }
        return true;
    }
}
