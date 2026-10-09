<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\Settings;
use App\Core\View;
use App\Services\Admin\AdminLog;

/**
 * Website settings (spec 31, 43): every row of the settings table, grouped.
 * Secrets (database, SMTP, SMS, Maps keys) are never here; they live in
 * config/env.php. Each changed value is validated and logged.
 */
final class SettingsController
{
    /** Allowed values for choice settings. */
    private const CHOICES = [
        'rent_change_approval_mode' => ['increase_only' => 'Increases need approval, decreases go live (recommended)', 'all' => 'Every change needs approval', 'none' => 'Every change goes live at once'],
        'default_location_privacy'  => ['approximate' => 'Approximate (about 150–400 m away)', 'exact' => 'Exact pin'],
    ];

    /** [min, max] for number settings. */
    private const RANGES = [
        'default_property_limit' => [0, 1000], 'listing_expiry_days' => [0, 3650], 'listings_per_page' => [6, 60],
        'max_images_per_property' => [1, 50], 'max_image_upload_mb' => [1, 64], 'max_video_upload_mb' => [1, 512],
        'cookie_policy_version' => [1, 1000],
    ];

    private const GROUPS = ['general' => 'General', 'listings' => 'Listings', 'media' => 'Photos & video', 'auth' => 'Login',
        'notifications' => 'Notifications', 'engagement' => 'Enquiries', 'search' => 'Search', 'privacy' => 'Privacy'];

    public function index(Request $request): Response
    {
        $rows = Database::all('SELECT * FROM settings ORDER BY setting_group, setting_key');
        $grouped = [];
        foreach ($rows as $r) {
            $grouped[$r['setting_group']][] = $r;
        }
        $pos = static fn (string $g): int => ($i = array_search($g, array_keys(self::GROUPS), true)) === false ? 99 : $i;
        uksort($grouped, static fn ($a, $b) => $pos((string) $a) <=> $pos((string) $b));
        return Response::html(View::render('admin/settings', [
            'title' => 'Settings', 'section' => 'settings', 'grouped' => $grouped,
            'groups' => self::GROUPS, 'choices' => self::CHOICES, 'ranges' => self::RANGES,
        ]));
    }

    public function save(Request $request): Response
    {
        $rows = Database::all('SELECT * FROM settings');
        $input = $request->input('s');
        $input = is_array($input) ? $input : [];
        $errors = [];
        $changes = [];
        foreach ($rows as $r) {
            $key = $r['setting_key'];
            $raw = $input[$key] ?? null;
            [$value, $error] = self::parse($r, $raw);
            if ($error !== null) {
                $errors['s.' . $key] = $error;
                continue;
            }
            if ($value !== null && (string) $value !== (string) $r['setting_value']) {
                $changes[$key] = [$r['setting_value'], $value, $r['value_type']];
            }
        }
        if ($errors) {
            return back_with_errors('/admin/settings', $errors, $request->all());
        }
        $adminId = (int) Auth::id();
        Database::transaction(function () use ($changes, $adminId): void {
            foreach ($changes as $key => [$old, $new, $type]) {
                Settings::set($key, $type === 'bool' ? $new === '1' : $new, $adminId);
                AdminLog::record($adminId, 'setting.changed', 'setting', null, [$key => $old], [$key => $new]);
            }
        });
        Session::flash('success', $changes ? plural(count($changes), 'setting', 'settings') . ' saved.' : 'No changes to save.');
        return Response::redirect('/admin/settings');
    }

    /**
     * @return array{0: ?string, 1: ?string} [stored value or null to leave alone, error]
     */
    private static function parse(array $row, mixed $raw): array
    {
        $key = $row['setting_key'];
        switch ($row['value_type']) {
            case 'bool':
                return [$raw === '1' ? '1' : '0', null];
            case 'int':
                if ($raw === null) {
                    return [null, null];
                }
                [$min, $max] = self::RANGES[$key] ?? [0, 1000000];
                if (!is_string($raw) || !preg_match('/^\d+$/', trim($raw)) || (int) $raw < $min || (int) $raw > $max) {
                    return [null, "Please enter a whole number from {$min} to {$max}."];
                }
                return [(string) (int) $raw, null];
            case 'json':
                if ($raw === null) {
                    return [null, null];
                }
                if (!is_string($raw)) {
                    return [null, 'Invalid value.'];
                }
                $data = json_decode($raw, true);
                if ($key === 'budget_ranges') {
                    if (!is_array($data) || !array_is_list($data) || count($data) < 1 || count($data) > 10) {
                        return [null, 'Please enter 1 to 10 ranges as a JSON list.'];
                    }
                    foreach ($data as $range) {
                        $okMax = !array_key_exists('max', $range) || $range['max'] === null || (is_int($range['max']) && $range['max'] > ($range['min'] ?? 0));
                        if (!is_array($range) || !is_string($range['label'] ?? null) || trim($range['label']) === '' || !is_int($range['min'] ?? null) || $range['min'] < 0 || !$okMax) {
                            return [null, 'Each range needs a label, a whole-number min, and a max that is larger (or null for no upper limit).'];
                        }
                    }
                } elseif (json_last_error() !== JSON_ERROR_NONE) {
                    return [null, 'This is not valid JSON.'];
                }
                return [json_encode($data, JSON_UNESCAPED_UNICODE), null];
            default:
                if ($raw === null) {
                    return [null, null];
                }
                $v = is_string($raw) ? trim($raw) : '';
                if (isset(self::CHOICES[$key]) && !isset(self::CHOICES[$key][$v])) {
                    return [null, 'Please choose one of the options.'];
                }
                if ($key === 'site_name' && (mb_strlen($v) < 2 || mb_strlen($v) > 80)) {
                    return [null, 'Please enter a name of 2 to 80 characters.'];
                }
                if ($key === 'support_email' && $v !== '' && !filter_var($v, FILTER_VALIDATE_EMAIL)) {
                    return [null, 'Please enter a valid email address, or leave it empty.'];
                }
                if (mb_strlen($v) > 255) {
                    return [null, 'Please keep this under 255 characters.'];
                }
                return [$v, null];
        }
    }
}
