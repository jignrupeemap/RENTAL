<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\RateLimiter;
use App\Repositories\ListingRepository;
use App\Repositories\UserRepository;

/**
 * Visit-request state machine. Owners act in Phase 3; viewers cancel and
 * accept a proposed time in Phase 8; viewers create requests in Phase 9.
 */
final class BookingService
{
    /** Owner actions allowed from each status. */
    public const OWNER_ACTIONS = [
        'pending'     => ['accept', 'reject', 'reschedule'],
        'rescheduled' => ['reschedule', 'cancel'],
        'accepted'    => ['complete', 'reschedule', 'cancel'],
        'rejected'    => [],
        'completed'   => [],
        'cancelled'   => [],
    ];

    /** Viewer actions allowed from each status (Phase 8). */
    public const VIEWER_ACTIONS = [
        'pending'     => ['cancel'],
        'rescheduled' => ['accept_time', 'cancel'],
        'accepted'    => ['cancel'],
    ];

    private const RESULT_STATUS = [
        'accept' => 'accepted', 'reject' => 'rejected', 'reschedule' => 'rescheduled',
        'complete' => 'completed', 'cancel' => 'cancelled',
    ];

    /**
     * @return string|null error message, or null on success
     */
    public static function ownerAction(array $booking, int $ownerId, string $action, ?string $date, ?string $time, string $note): ?string
    {
        if (!in_array($action, self::OWNER_ACTIONS[$booking['status']] ?? [], true)) {
            return 'That action is not available for this request any more.';
        }
        $note = mb_substr(trim($note), 0, 500);
        $newStatus = self::RESULT_STATUS[$action];
        $proposedDate = $booking['proposed_date'];
        $proposedTime = $booking['proposed_time'];

        if ($action === 'reschedule') {
            $today = (new \DateTimeImmutable('now', new \DateTimeZone('Asia/Kolkata')))->format('Y-m-d');
            $d = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $date);
            if ($d === false || $d->format('Y-m-d') !== $date || $date < $today) {
                return 'Please choose a valid date from today onwards.';
            }
            if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string) $time)) {
                return 'Please choose a valid time.';
            }
            [$proposedDate, $proposedTime] = [$date, $time . ':00'];
        }
        if ($action === 'reject' && $note === '') {
            $note = 'Not available';
        }

        Database::transaction(function () use ($booking, $ownerId, $newStatus, $proposedDate, $proposedTime, $note, $action): void {
            // Status in WHERE: two clicks / two tabs cannot both apply.
            $changed = Database::run(
                'UPDATE bookings SET status = ?, proposed_date = ?, proposed_time = ?, owner_response = ?,
                        cancelled_by = IF(? = "cancelled", "owner", cancelled_by)
                  WHERE id = ? AND owner_id = ? AND status = ?',
                [$newStatus, $proposedDate, $proposedTime, $note !== '' ? $note : $booking['owner_response'],
                 $newStatus, $booking['id'], $ownerId, $booking['status']]
            )->rowCount();
            if ($changed !== 1) {
                throw new \RuntimeException('booking changed concurrently');
            }
            Database::run(
                'INSERT INTO booking_status_history (booking_id, old_status, new_status, changed_by, actor_role, note) VALUES (?, ?, ?, ?, "owner", ?)',
                [$booking['id'], $booking['status'], $newStatus, $ownerId,
                 $action === 'reschedule' ? trim('New time ' . $proposedDate . ' ' . substr((string) $proposedTime, 0, 5) . '. ' . $note) : ($note ?: null)]
            );
        });

        $titles = [
            'accepted'    => 'Visit accepted',
            'rejected'    => 'Visit request declined',
            'rescheduled' => 'Owner proposed a new visit time',
            'completed'   => 'Visit marked as completed',
            'cancelled'   => 'Visit cancelled by owner',
        ];
        $body = $booking['property_title'] . ($action === 'reschedule'
            ? ' · ' . date('d M Y', strtotime((string) $proposedDate)) . ', ' . date('h:i A', strtotime((string) $proposedTime))
            : '') . ($note !== '' ? ' · ' . $note : '');
        NotificationService::toUser((int) $booking['user_id'], 'booking_' . $newStatus, $titles[$newStatus], mb_substr($body, 0, 500), '/account/bookings', 'booking', (int) $booking['id']);
        ActivityLog::event($ownerId, 'booking.' . $newStatus, 'booking', (int) $booking['id']);
        return null;
    }

    /**
     * Viewer cancels, or accepts the time the owner proposed. Ownership is
     * checked in the UPDATE itself (user_id) so a changed id in the form
     * cannot touch someone else's request.
     * @return string|null error message, or null on success
     */
    public static function viewerAction(int $bookingId, int $userId, string $action): ?string
    {
        $booking = Database::one('SELECT * FROM bookings WHERE id = ? AND user_id = ?', [$bookingId, $userId]);
        if ($booking === null) {
            return 'Visit request not found.';
        }
        if (!in_array($action, self::VIEWER_ACTIONS[$booking['status']] ?? [], true)) {
            return 'That action is not available for this request any more.';
        }
        $accept = $action === 'accept_time';
        if ($accept && ($booking['proposed_date'] === null || $booking['proposed_time'] === null)) {
            return 'There is no new time to accept.';
        }
        $newStatus = $accept ? 'accepted' : 'cancelled';
        $changed = Database::transaction(static function () use ($booking, $userId, $newStatus, $accept): bool {
            $n = Database::run(
                $accept
                    ? 'UPDATE bookings SET status = ?, preferred_date = proposed_date, preferred_time = proposed_time WHERE id = ? AND user_id = ? AND status = ?'
                    : 'UPDATE bookings SET status = ?, cancelled_by = \'viewer\' WHERE id = ? AND user_id = ? AND status = ?',
                [$newStatus, $booking['id'], $userId, $booking['status']]
            )->rowCount();
            if ($n !== 1) {
                return false;
            }
            Database::run(
                'INSERT INTO booking_status_history (booking_id, old_status, new_status, changed_by, actor_role, note) VALUES (?, ?, ?, ?, \'viewer\', ?)',
                [$booking['id'], $booking['status'], $newStatus, $userId, $accept ? 'Visitor accepted the new time' : 'Cancelled by visitor']
            );
            return true;
        });
        if (!$changed) {
            return 'This request was just updated. Please check it again.';
        }
        if ($booking['owner_id'] !== null) {
            $when = $accept ? ' · ' . date('d M Y', strtotime((string) $booking['proposed_date'])) . ', ' . date('h:i A', strtotime((string) $booking['proposed_time'])) : '';
            NotificationService::toUser((int) $booking['owner_id'], $accept ? 'booking_time_accepted' : 'booking_cancelled_by_viewer',
                $accept ? 'Visitor accepted your new time' : 'Visit request cancelled by the visitor',
                mb_substr($booking['property_title'] . $when, 0, 500), '/owner/bookings', 'booking', (int) $booking['id']);
        }
        ActivityLog::event($userId, 'booking.' . ($accept ? 'time_accepted' : 'cancelled'), 'booking', (int) $booking['id']);
        return null;
    }

    /** How far ahead a visit can be asked for. */
    public const MAX_DAYS_AHEAD = 60;

    /** Visit times offered: every 30 minutes, 08:00 to 20:00 (India time). */
    public static function timeSlots(): array
    {
        $out = [];
        for ($m = 8 * 60; $m <= 20 * 60; $m += 30) {
            $t = sprintf('%02d:%02d', intdiv($m, 60), $m % 60);
            $out[$t] = date('h:i A', strtotime($t));
        }
        return $out;
    }

    /** Today's date in India (visits are booked in local time). */
    public static function todayIst(): string
    {
        return gmdate('Y-m-d', time() + 19800);
    }

    /** The viewer's open request for a listing, if any (one at a time per listing). */
    public static function openRequest(int $userId, int $propertyId): ?array
    {
        return Database::one(
            "SELECT * FROM bookings WHERE user_id = ? AND property_id = ? AND status IN ('pending','accepted','rescheduled') ORDER BY id DESC LIMIT 1",
            [$userId, $propertyId]
        );
    }

    /**
     * Request Visit (spec 28) by a logged-in, active viewer.
     * @return array{0: array<string,string>, 1: ?int} [errors, booking id]
     */
    public static function request(array $user, int $propertyId, array $input, string $ip): array
    {
        $p = ListingRepository::find($propertyId);
        if ($p === null) {
            return [['_form' => 'This listing is no longer available.'], null];
        }
        if ((int) ($p['owner_id'] ?? 0) === (int) $user['id']) {
            return [['_form' => 'This is your own listing.'], null];
        }
        $errors = [];
        $date = (string) ($input['preferred_date'] ?? '');
        $time = (string) ($input['preferred_time'] ?? '');
        $today = self::todayIst();
        $last = gmdate('Y-m-d', strtotime($today . ' UTC') + self::MAX_DAYS_AHEAD * 86400);
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if ($d === false || $d->format('Y-m-d') !== $date) {
            $errors['preferred_date'] = 'Please choose a date.';
        } elseif ($date < $today || $date > $last) {
            $errors['preferred_date'] = 'Please choose a date between today and ' . date('d M Y', strtotime($last)) . '.';
        }
        if (!isset(self::timeSlots()[$time])) {
            $errors['preferred_time'] = 'Please choose a time.';
        } elseif (!isset($errors['preferred_date']) && $date === $today && $time < gmdate('H:i', time() + 19800 + 3600)) {
            $errors['preferred_time'] = 'For today, please choose a time at least one hour from now.';
        }
        $message = trim(str_replace("\r\n", "\n", (string) ($input['message'] ?? '')));
        if (mb_strlen($message) > 1000) {
            $errors['message'] = 'Message must be at most 1000 characters.';
        }
        if (!$errors && ($open = self::openRequest((int) $user['id'], $propertyId)) !== null) {
            $errors['_form'] = 'You already have a visit request for this property (' . date('d M Y', strtotime($open['preferred_date'])) . '). You can cancel it from My Account first.';
        }
        if (!$errors && !RateLimiter::hit('booking_user', (string) $user['id'])) {
            $errors['_form'] = 'You have sent a lot of visit requests in the last hour. Please try again later.';
        }
        if ($errors) {
            return [$errors, null];
        }

        $title = (string) ($p['title'] !== '' ? $p['title'] : $p['type_name']);
        $id = Database::transaction(static function () use ($user, $p, $date, $time, $message, $title): int {
            $id = Database::insert(
                'INSERT INTO bookings (property_id, owner_id, user_id, preferred_date, preferred_time, message, status, property_title, rent_at_request)
                 VALUES (?, ?, ?, ?, ?, ?, \'pending\', ?, ?)',
                [$p['id'], $p['owner_id'], $user['id'], $date, $time . ':00', $message !== '' ? mb_substr($message, 0, 1000) : null, mb_substr($title, 0, 150), $p['rent']]
            );
            Database::run('INSERT INTO booking_status_history (booking_id, old_status, new_status, changed_by, actor_role, note) VALUES (?, NULL, \'pending\', ?, \'viewer\', ?)',
                [$id, $user['id'], 'Visit requested']);
            Database::run('UPDATE properties SET bookings_count = bookings_count + 1 WHERE id = ?', [$p['id']]);
            return $id;
        });

        $when = date('d M Y', strtotime($date)) . ', ' . self::timeSlots()[$time];
        $from = UserRepository::fullName($user);
        if ($p['owner_id'] !== null && ($owner = UserRepository::find((int) $p['owner_id'])) !== null) {
            NotificationService::toUser((int) $owner['id'], 'new_booking', 'Visit request from ' . $from, mb_substr($title . ' · ' . $when, 0, 500), '/owner/bookings', 'booking', $id);
            EnquiryService::emailOwner($owner, $p, 'New visit request for your property', 'new-booking', [
                'from' => $from, 'title' => $title, 'when' => $when, 'message' => $message,
            ]);
        }
        NotificationService::toAdmins('new_booking', 'New visit request: ' . mb_substr($title, 0, 120), $from . ' · ' . $when, '/admin/bookings', 'booking', $id);
        ActivityLog::event((int) $user['id'], 'booking_requested', 'property', (int) $p['id'], ['booking_id' => $id], $ip);
        return [[], $id];
    }
}
