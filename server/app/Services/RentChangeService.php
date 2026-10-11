<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Settings;

/**
 * Detects and records rent changes on listings that have been published.
 * Setting rent_change_approval_mode:
 *   none          every change goes live at once (still logged, admin alerted)
 *   increase_only increases wait for admin approval, decreases go live (default)
 *   all           every change waits for admin approval
 * While a change waits, the old rent stays live and properties.pending_rent_change_id
 * points at the history row. The admin approves or rejects it with review().
 * Rent changed by an admin (admin edit form) goes live at once and is logged.
 * Every owner change alerts the admins (notification, plus email when
 * email notifications are on); the owner hears about each decision.
 */
final class RentChangeService
{
    /**
     * Call inside a transaction with the property row locked (SELECT ... FOR UPDATE).
     * @return string unchanged | pending | applied | withdrawn
     */
    public static function change(array $property, int $newRent, int $actorId, string $ip, bool $byAdmin = false): string
    {
        $live = (int) $property['rent'];
        $pending = $property['pending_rent_change_id']
            ? Database::one("SELECT * FROM rental_change_history WHERE id = ? AND status = 'pending'", [$property['pending_rent_change_id']])
            : null;

        if ($pending !== null && (int) $pending['new_rent'] === $newRent && !$byAdmin) {
            return 'unchanged'; // same increase already waiting
        }
        if ($byAdmin && $newRent === $live) {
            return 'unchanged'; // an admin saving other charges leaves the owner's request for review
        }
        if ($pending !== null) {
            Database::run("UPDATE rental_change_history SET status = 'cancelled', review_note = ? WHERE id = ? AND status = 'pending'",
                [$byAdmin ? 'Replaced by an admin change' : 'Replaced by the owner', $pending['id']]);
            Database::run('UPDATE properties SET pending_rent_change_id = NULL WHERE id = ?', [$property['id']]);
        }
        if ($newRent === $live) {
            if ($pending !== null) {
                NotificationService::toAdmins('rent_change_withdrawn', 'Rent change withdrawn: ' . $property['title'],
                    'Owner set the rent back to ' . money_inr($live) . '.', '/admin/rent-changes/' . $pending['id'], 'rent_change', (int) $pending['id']);
                ActivityLog::event($actorId, 'rent.change_withdrawn', 'property', (int) $property['id'], [], $ip);
                return 'withdrawn';
            }
            return 'unchanged';
        }

        $mode = (string) Settings::get('rent_change_approval_mode', 'increase_only');
        $increase = $newRent > $live;
        $needsApproval = !$byAdmin && ($mode === 'all' || ($mode === 'increase_only' && $increase));

        $owner = $property['owner_id'] ? Database::one('SELECT first_name, last_name, mobile, email FROM users WHERE id = ?', [$property['owner_id']]) : null;
        $ownerName = $owner ? trim($owner['first_name'] . ' ' . $owner['last_name']) : null;
        $id = Database::insert(
            'INSERT INTO rental_change_history
                (property_id, owner_id, changed_by, old_rent, new_rent, difference, change_type, status,
                 property_title, property_url, owner_name, owner_mobile, owner_email, ip_address)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$property['id'], $property['owner_id'], $actorId, $live, $newRent, $newRent - $live,
             $increase ? 'increase' : 'decrease', $needsApproval ? 'pending' : 'auto_applied',
             mb_substr((string) $property['title'], 0, 150), property_url($property),
             $ownerName, $owner['mobile'] ?? null, $owner['email'] ?? null, $ip]
        );
        $diff = $newRent - $live;
        if ($byAdmin) {
            Database::run("UPDATE rental_change_history SET status = 'approved', reviewed_by = ?, reviewed_at = UTC_TIMESTAMP(), review_note = 'Changed by admin' WHERE id = ?", [$actorId, $id]);
            Database::run('UPDATE properties SET rent = ? WHERE id = ?', [$newRent, $property['id']]);
            \App\Services\Admin\AdminLog::record($actorId, 'rent.changed', 'property', (int) $property['id'], ['rent' => $live], ['rent' => $newRent]);
            if ($property['owner_id']) {
                NotificationService::toUser((int) $property['owner_id'], 'rent_changed_by_admin', 'Rent updated by admin: ' . mb_substr((string) $property['title'], 0, 90),
                    money_inr($live) . ' → ' . money_inr($newRent), '/owner/rent-changes', 'rent_change', $id);
            }
            return 'applied';
        }
        if ($needsApproval) {
            Database::run('UPDATE properties SET pending_rent_change_id = ? WHERE id = ?', [$id, $property['id']]);
        } else {
            Database::run('UPDATE properties SET rent = ? WHERE id = ?', [$newRent, $property['id']]);
        }

        NotificationService::toAdmins(
            'rent_changed',
            'RENTAL CHANGE ALERT: ' . mb_substr((string) $property['title'], 0, 100),
            implode(' · ', array_filter([
                $ownerName ? 'Owner ' . $ownerName . ($owner['mobile'] ? ' (' . format_mobile($owner['mobile']) . ')' : '') : null,
                money_inr($live) . ' → ' . money_inr($newRent) . ' (' . ($diff > 0 ? '+' : '') . money_inr($diff) . ')',
                $needsApproval ? 'Waiting for your approval' : 'Applied',
            ])),
            '/admin/rent-changes/' . $id,
            'rent_change',
            $id
        );
        self::emailAdmins($id, $needsApproval);
        ActivityLog::event($actorId, 'rent.changed', 'property', (int) $property['id'],
            ['old' => $live, 'new' => $newRent, 'status' => $needsApproval ? 'pending' : 'applied'], $ip);
        return $needsApproval ? 'pending' : 'applied';
    }

    /**
     * Admin decision on a waiting change. Approve makes the new rent live;
     * reject keeps the old rent. The owner is notified either way.
     * @return array{0: bool, 1: string} [ok, message]
     */
    public static function review(int $changeId, bool $approve, int $adminId, string $note): array
    {
        $note = mb_substr(trim($note), 0, 500);
        if (!$approve && mb_strlen($note) < 5) {
            return [false, 'Please write a short reason for the owner (at least 5 characters).'];
        }
        return Database::transaction(function () use ($changeId, $approve, $adminId, $note): array {
            $c = Database::one('SELECT * FROM rental_change_history WHERE id = ? FOR UPDATE', [$changeId]);
            if ($c === null) {
                return [false, 'Rent change not found.'];
            }
            $p = Database::one('SELECT * FROM properties WHERE id = ? FOR UPDATE', [$c['property_id']]);
            if ($c['status'] !== 'pending' || $p === null || (int) $p['pending_rent_change_id'] !== $changeId) {
                return [false, 'This rent change is no longer waiting (it was ' . str_replace('_', ' ', $c['status']) . ').'];
            }
            $status = $approve ? 'approved' : 'rejected';
            Database::run('UPDATE rental_change_history SET status = ?, reviewed_by = ?, reviewed_at = UTC_TIMESTAMP(), review_note = ? WHERE id = ?',
                [$status, $adminId, $note !== '' ? $note : null, $changeId]);
            if ($approve) {
                Database::run('UPDATE properties SET rent = ?, pending_rent_change_id = NULL WHERE id = ?', [$c['new_rent'], $p['id']]);
            } else {
                Database::run('UPDATE properties SET pending_rent_change_id = NULL WHERE id = ?', [$p['id']]);
            }
            \App\Services\Admin\AdminLog::record($adminId, 'rent.' . $status, 'rent_change', $changeId,
                ['status' => 'pending', 'live_rent' => (int) $p['rent']],
                ['status' => $status, 'live_rent' => $approve ? (int) $c['new_rent'] : (int) $p['rent']] + ($note !== '' ? ['note' => $note] : []));
            if ($p['owner_id']) {
                NotificationService::toUser((int) $p['owner_id'], 'rent_change_' . $status,
                    ($approve ? 'Rent change approved: ' : 'Rent change not approved: ') . mb_substr((string) $p['title'], 0, 90),
                    $approve ? money_inr((int) $c['new_rent']) . ' is now live.' : money_inr((int) $p['rent']) . ' stays live.' . ($note !== '' ? ' ' . $note : ''),
                    '/owner/rent-changes', 'rent_change', $changeId);
                $owner = Database::one("SELECT first_name, last_name, email, status FROM users WHERE id = ? AND deleted_at IS NULL", [$p['owner_id']]);
                if ($owner !== null && $owner['status'] === 'active' && (string) $owner['email'] !== '' && Settings::bool('email_notifications_enabled')) {
                    Mailer::sendTemplate((string) $owner['email'], trim($owner['first_name'] . ' ' . $owner['last_name']),
                        $approve ? 'Rent change approved' : 'Rent change not approved', 'rent-change-decision', [
                            'name' => $owner['first_name'], 'title' => (string) $p['title'], 'approved' => $approve,
                            'newRent' => (int) $c['new_rent'], 'liveRent' => (int) $p['rent'], 'note' => $note, 'link' => url('/owner/rent-changes'),
                        ]);
                }
            }
            return [true, $approve ? 'Approved. ' . money_inr((int) $c['new_rent']) . ' is now live.' : 'Rejected. ' . money_inr((int) $p['rent']) . ' stays live.'];
        });
    }

    /** Email copy of the alert to every active admin, when email notifications are on in Settings. */
    private static function emailAdmins(int $changeId, bool $waiting): void
    {
        if (!Settings::bool('email_notifications_enabled')) {
            return;
        }
        $c = Database::one('SELECT * FROM rental_change_history WHERE id = ?', [$changeId]);
        $admins = Database::all(
            "SELECT DISTINCT u.first_name, u.last_name, u.email FROM users u JOIN user_roles r ON r.user_id = u.id
              WHERE r.role IN ('admin','super_admin') AND u.status = 'active' AND u.deleted_at IS NULL AND u.email IS NOT NULL AND u.email <> ''"
        );
        $diff = (int) $c['difference'];
        $subject = 'Rental change alert: ' . mb_substr((string) $c['property_title'], 0, 80) . ' (' . ($diff > 0 ? '+' : '−') . money_inr(abs($diff)) . ')';
        foreach ($admins as $a) {
            Mailer::sendTemplate((string) $a['email'], trim($a['first_name'] . ' ' . $a['last_name']), $subject, 'rent-change-alert',
                ['c' => $c, 'waiting' => $waiting, 'base' => rtrim(url('/'), '/')]);
        }
    }
}
