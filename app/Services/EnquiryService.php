<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\RateLimiter;
use App\Core\Settings;
use App\Core\Validator;
use App\Repositories\ListingRepository;
use App\Repositories\UserRepository;

/**
 * Contact Owner (spec 25, 26) and the general requirement form. The listing
 * details on a property enquiry come from the database, never from the form,
 * and are copied into the enquiry so later edits don't rewrite history.
 */
final class EnquiryService
{
    /** Hours before the same viewer can message the same listing again. */
    public const REPEAT_HOURS = 24;

    public const INTERESTS = ['rent' => 'Rent', 'lease' => 'Lease', 'pg' => 'PG'];

    /**
     * Enquiry from a property page by a logged-in, active viewer.
     * @return array{0: array<string,string>, 1: ?int} [errors, enquiry id]
     */
    public static function fromProperty(array $user, int $propertyId, array $input, string $ip): array
    {
        $p = ListingRepository::find($propertyId);
        if ($p === null) {
            return [['_form' => 'This listing is no longer available.'], null];
        }
        if ((int) ($p['owner_id'] ?? 0) === (int) $user['id']) {
            return [['_form' => 'This is your own listing.'], null];
        }
        $errors = self::validateContact($input);
        if (!$errors && self::recentlyContacted((int) $user['id'], $propertyId)) {
            $errors['_form'] = 'You already sent the owner a message about this property today. They have your details.';
        }
        if (!$errors && !RateLimiter::hit('enquiry_user', (string) $user['id'])) {
            $errors['_form'] = 'You have sent a lot of messages in the last hour. Please try again later.';
        }
        if ($errors) {
            return [$errors, null];
        }

        $owner = $p['owner_id'] !== null ? UserRepository::find((int) $p['owner_id']) : null;
        $ownerName = $owner !== null ? UserRepository::fullName($owner) : Settings::get('site_name', 'Rental Website');
        $snapshot = [
            'purpose' => $p['purpose'], 'category' => $p['category_code'] ?? null, 'type' => $p['type_name'],
            'bedrooms' => $p['bedrooms'], 'area' => $p['area_value'] !== null ? $p['area_value'] . ' ' . $p['area_unit'] : null,
            'furnishing' => $p['furnishing'], 'city' => $p['city_name'], 'locality' => $p['locality_label'],
            'rent' => $p['rent'], 'deposit' => $p['security_deposit'], 'maintenance' => $p['maintenance'],
        ];
        $id = Database::transaction(static function () use ($user, $p, $input, $ip, $owner, $ownerName, $snapshot): int {
            $id = Database::insert(
                'INSERT INTO enquiries (source, property_id, owner_id, user_id, first_name, last_name, mobile_1, mobile_2, email,
                                        interested_in, category_id, city_id, locality_id, message, property_title, property_url,
                                        owner_name, rent_at_enquiry, property_snapshot, ip_address)
                 VALUES (\'property_page\', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $p['id'], $p['owner_id'], $user['id'], trim((string) $input['first_name']), trim((string) $input['last_name']),
                    normalize_mobile((string) $input['mobile_1']), self::optionalMobile($input['mobile_2'] ?? ''),
                    self::optionalEmail($input['email'] ?? ''), $p['purpose'], $p['category_id'], $p['city_id'], $p['locality_id'],
                    self::message($input['message'] ?? ''), mb_substr((string) ($p['title'] !== '' ? $p['title'] : $p['type_name']), 0, 150),
                    mb_substr(url(property_url($p)), 0, 255), mb_substr($ownerName, 0, 170),
                    $p['rent'], json_encode($snapshot, JSON_UNESCAPED_UNICODE), $ip,
                ]
            );
            Database::run('INSERT INTO contact_history (user_id, property_id, owner_id, enquiry_id, action, rent_at_contact) VALUES (?, ?, ?, ?, \'enquiry\', ?)',
                [$user['id'], $p['id'], $p['owner_id'], $id, $p['rent']]);
            Database::run('UPDATE properties SET enquiries_count = enquiries_count + 1 WHERE id = ?', [$p['id']]);
            return $id;
        });

        $title = (string) ($p['title'] !== '' ? $p['title'] : $p['type_name']);
        $from = trim((string) $input['first_name'] . ' ' . (string) $input['last_name']);
        if ($owner !== null) {
            NotificationService::toUser((int) $owner['id'], 'new_enquiry', 'New enquiry from ' . $from, mb_substr($title, 0, 500), '/owner/enquiries', 'enquiry', $id);
            self::emailOwner($owner, $p, 'New enquiry for your property', 'new-enquiry', [
                'from' => $from, 'title' => $title, 'message' => self::message($input['message'] ?? ''),
                'mobile' => format_mobile(normalize_mobile((string) $input['mobile_1'])),
            ]);
        }
        NotificationService::toAdmins('new_enquiry', 'New enquiry: ' . mb_substr($title, 0, 120), $from, '/admin/enquiries', 'enquiry', $id);
        ActivityLog::event((int) $user['id'], 'contacted', 'property', (int) $p['id'], ['enquiry_id' => $id], $ip);
        return [[], $id];
    }

    /**
     * General requirement form (spec 26 "Inquiry Form"): guests may use it.
     * @return array{0: array<string,string>, 1: ?int}
     */
    public static function general(?array $user, array $input, string $ip): array
    {
        $errors = self::validateContact($input);
        $interest = (string) ($input['interested_in'] ?? '');
        if (!isset(self::INTERESTS[$interest])) {
            $errors['interested_in'] = 'Please choose Rent, Lease or PG.';
        }
        $categoryId = (int) ($input['category_id'] ?? 0);
        if (!Database::value('SELECT 1 FROM property_categories WHERE id = ?', [$categoryId])) {
            $errors['category_id'] = 'Please choose a property type.';
        }
        $budgetRaw = preg_replace('/[\s,₹]/u', '', (string) ($input['budget'] ?? '')) ?? '';
        if ($budgetRaw !== '' && (!ctype_digit($budgetRaw) || (int) $budgetRaw > 100000000)) {
            $errors['budget'] = 'Please enter the budget in rupees, digits only.';
        }
        $cityId = (int) ($input['city_id'] ?? 0) ?: null;
        if ($cityId !== null && !Database::value('SELECT 1 FROM cities WHERE id = ? AND is_active = 1', [$cityId])) {
            $errors['city_id'] = 'Please choose a city from the list.';
        }
        $localityId = (int) ($input['locality_id'] ?? 0) ?: null;
        if ($localityId !== null && !Database::value('SELECT 1 FROM localities WHERE id = ? AND city_id = ?', [$localityId, (int) $cityId])) {
            $errors['locality_id'] = 'Please choose a location in the selected city.';
        }
        if (!$errors) {
            $key = $user !== null ? 'u' . $user['id'] : 'ip' . $ip;
            if (!RateLimiter::hit('enquiry_general', $key)) {
                $errors['_form'] = 'We have received several requests from you recently. Please try again later.';
            }
        }
        if ($errors) {
            return [$errors, null];
        }
        $id = Database::insert(
            'INSERT INTO enquiries (source, user_id, first_name, last_name, mobile_1, mobile_2, email, interested_in, category_id, budget, city_id, locality_id, message, ip_address)
             VALUES (\'general_form\', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $user['id'] ?? null, trim((string) $input['first_name']), trim((string) $input['last_name']),
                normalize_mobile((string) $input['mobile_1']), self::optionalMobile($input['mobile_2'] ?? ''), self::optionalEmail($input['email'] ?? ''),
                $interest, $categoryId, $budgetRaw !== '' ? (int) $budgetRaw : null, $cityId, $localityId, self::message($input['message'] ?? ''), $ip,
            ]
        );
        $from = trim((string) $input['first_name'] . ' ' . (string) $input['last_name']);
        NotificationService::toAdmins('new_requirement', 'New requirement: ' . self::INTERESTS[$interest], $from, '/admin/enquiries', 'enquiry', $id);
        if ($user !== null) {
            ActivityLog::event((int) $user['id'], 'requirement_sent', 'enquiry', $id, [], $ip);
        }
        return [[], $id];
    }

    public static function recentlyContacted(int $userId, int $propertyId): ?string
    {
        $at = Database::value(
            'SELECT MAX(created_at) FROM enquiries WHERE user_id = ? AND property_id = ? AND created_at > UTC_TIMESTAMP() - INTERVAL ' . self::REPEAT_HOURS . ' HOUR',
            [$userId, $propertyId]
        );
        return $at !== null && $at !== false ? (string) $at : null;
    }

    /** Latest enquiry this user sent about the listing (for "You contacted the owner on …"). */
    public static function lastContact(int $userId, int $propertyId): ?string
    {
        $at = Database::value('SELECT MAX(created_at) FROM enquiries WHERE user_id = ? AND property_id = ?', [$userId, $propertyId]);
        return $at !== null && $at !== false ? (string) $at : null;
    }

    /** Name, mobile(s), email, message: shared by both forms. */
    private static function validateContact(array $input): array
    {
        $v = new Validator($input, [
            'first_name' => 'required|name|max:80',
            'last_name'  => 'required|name|max:80',
            'mobile_1'   => 'required|mobile',
            'mobile_2'   => 'mobile',
            'email'      => 'email',
            'message'    => 'max:1000',
        ], ['mobile_1' => 'Mobile number', 'mobile_2' => 'Alternate mobile', 'message' => 'Message']);
        $errors = $v->fails() ? $v->errors() : [];
        if (!isset($errors['mobile_2']) && ($input['mobile_2'] ?? '') !== ''
            && normalize_mobile((string) $input['mobile_2']) === normalize_mobile((string) ($input['mobile_1'] ?? ''))) {
            $errors['mobile_2'] = 'The alternate mobile is the same as the first one.';
        }
        return $errors;
    }

    private static function optionalMobile(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? normalize_mobile($value) : null;
    }

    private static function optionalEmail(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? strtolower(trim($value)) : null;
    }

    private static function message(mixed $value): ?string
    {
        $m = is_string($value) ? trim(str_replace("\r\n", "\n", $value)) : '';
        return $m !== '' ? mb_substr($m, 0, 1000) : null;
    }

    /** Email copy for the owner when email notifications are switched on in Settings. */
    public static function emailOwner(array $owner, array $p, string $subject, string $template, array $data): void
    {
        if (!Settings::bool('email_notifications_enabled')) {
            return;
        }
        $to = (string) ($p['contact_email'] ?? '') !== '' ? (string) $p['contact_email'] : (string) ($owner['email'] ?? '');
        if ($to === '' || $owner['status'] !== 'active') {
            return;
        }
        Mailer::sendTemplate($to, UserRepository::fullName($owner), $subject, $template, $data + [
            'name' => $owner['first_name'], 'dashboard' => url('/owner'),
        ]);
    }
}
