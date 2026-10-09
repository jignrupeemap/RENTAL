<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/**
 * Turns the field map (app/Config/property_fields.php) into form rules and
 * server-side validation. The wizard, the preview and the "what is still
 * missing" checklist all ask this class, so a field the map hides for a
 * type can never be shown, saved or required for that type.
 */
final class PropertyForm
{
    public const LAST_STEP = 8;

    /** At least one photo (which becomes the cover) is needed to submit. */
    public const PHOTOS_REQUIRED = true;

    /** Every column each step may write, across all profiles. Columns the current profile does not use are cleared. */
    private const STEP_COLUMNS = [
        2 => ['title', 'description', 'bedrooms', 'bathrooms', 'balconies', 'area_value', 'area_unit', 'area_sqft',
              'furnishing', 'available_from', 'pg_available_for', 'pg_suitable_for'],
        3 => ['unit_no', 'building_name', 'project_name', 'landmark', 'address_line', 'city_id', 'locality_id', 'locality_name', 'pincode'],
        5 => ['rent', 'security_deposit', 'maintenance', 'other_charges'],
        6 => ['video_url'],
        7 => ['contact_name', 'contact_mobile_1', 'contact_mobile_2', 'contact_email'],
    ];

    private const LABELS = [
        'title' => 'Listing title', 'description' => 'Description', 'bedrooms' => 'Bedrooms (BHK)', 'bathrooms' => 'Bathrooms',
        'balconies' => 'Balconies', 'area_value' => 'Area', 'area_unit' => 'Area unit', 'furnishing' => 'Furnishing',
        'available_from' => 'Available from', 'pg_available_for' => 'Available for', 'pg_suitable_for' => 'Suitable for',
        'unit_no' => 'Unit number', 'building_name' => 'Building name', 'project_name' => 'Project / society name',
        'landmark' => 'Landmark', 'address_line' => 'Address', 'city_id' => 'City', 'locality_id' => 'Locality / area',
        'pincode' => 'Pincode', 'map' => 'Map location', 'amenities' => 'Facilities', 'pg_services' => 'Extra services',
        'rent' => 'Monthly rent', 'security_deposit' => 'Security deposit', 'maintenance' => 'Maintenance (monthly)',
        'other_charges' => 'Other charges', 'photos' => 'Photos', 'cover_media_id' => 'Cover photo', 'video' => 'Video',
        'video_url' => 'YouTube video link', 'contact_name' => 'Contact name', 'contact_mobile_1' => 'Mobile number',
        'contact_mobile_2' => 'Second mobile number', 'contact_email' => 'Contact email',
    ];

    /** India, generously: used to catch swapped or mistyped coordinates. */
    private const LAT_RANGE = [6.0, 37.6];
    private const LNG_RANGE = [68.0, 97.5];

    private static ?array $map = null;

    public static function map(): array
    {
        return self::$map ??= require APP_ROOT . '/app/Config/property_fields.php';
    }

    /** @return array<int, string> */
    public static function steps(): array
    {
        return self::map()['steps'];
    }

    /** @return array<string|int, string> */
    public static function options(string $key): array
    {
        return self::map()['options'][$key] ?? [];
    }

    public static function profile(string $purpose, array $type): string
    {
        return $purpose === 'pg' ? 'pg' : (string) $type['field_profile'];
    }

    /** Field rules for one step for this type: ['title' => 'required', ...]. */
    public static function fields(string $profile, int $step, array $type): array
    {
        $map = self::map();
        $fields = $map['profiles'][$profile][$step] ?? $map['common'][$step] ?? [];
        if (!(int) $type['show_building_name']) {
            unset($fields['building_name']);
        }
        return $fields;
    }

    public static function label(string $field, ?array $type = null): string
    {
        if ($field === 'unit_no' && $type !== null) {
            return (string) $type['unit_label'];
        }
        return self::LABELS[$field] ?? ucfirst(str_replace('_', ' ', $field));
    }

    // ------------------------------------------------------------------
    // Reference data
    // ------------------------------------------------------------------

    /** Active categories and types, for step 1. */
    public static function catalog(): array
    {
        return [
            'categories' => Database::all('SELECT id, code, name FROM property_categories WHERE is_active = 1 ORDER BY sort_order, id'),
            'types'      => Database::all('SELECT * FROM property_types WHERE is_active = 1 ORDER BY category_id, sort_order, id'),
        ];
    }

    public static function type(int $typeId): ?array
    {
        return Database::one('SELECT t.*, c.code AS category_code, c.name AS category_name
                                FROM property_types t JOIN property_categories c ON c.id = t.category_id WHERE t.id = ?', [$typeId]);
    }

    public static function cities(): array
    {
        return Database::all('SELECT id, name FROM cities WHERE is_active = 1 ORDER BY sort_order, name');
    }

    public static function localities(): array
    {
        return Database::all('SELECT id, city_id, name, pincode FROM localities WHERE is_active = 1 ORDER BY name');
    }

    /** Amenities offered for a profile, split into Yes/No features and other facilities. */
    public static function amenities(string $profile): array
    {
        return Database::all(
            'SELECT id, code, name, amenity_group FROM amenities WHERE is_active = 1 AND FIND_IN_SET(?, scopes) ORDER BY sort_order, name',
            [$profile]
        );
    }

    // ------------------------------------------------------------------
    // Step 1: purpose, category, sub-type
    // ------------------------------------------------------------------

    /**
     * @return array{0: ?array, 1: array<string,string>} [type row with purpose, errors]
     */
    public static function validateType(string $purpose, int $categoryId, int $typeId): array
    {
        $errors = [];
        if (!in_array($purpose, ['rent', 'lease', 'pg'], true)) {
            $errors['purpose'] = 'Please choose Rent, Lease or PG.';
        }
        $type = $typeId > 0 ? self::type($typeId) : null;
        if ($type === null || !(int) $type['is_active']) {
            $errors['type_id'] = 'Please choose the property type.';
        } elseif ($purpose === 'pg' && !(int) $type['allows_pg']) {
            $errors['type_id'] = 'PG can be listed for a Flat or a Bungalow only.';
        } elseif ($purpose !== 'pg' && $categoryId > 0 && (int) $type['category_id'] !== $categoryId) {
            $errors['type_id'] = 'Please choose a type from the selected category.';
        }
        return [$errors ? null : $type, $errors];
    }

    // ------------------------------------------------------------------
    // Steps 2-7: field validation
    // ------------------------------------------------------------------

    /**
     * Validate one step's input against the field map.
     * $partial = Save Draft: empty required fields are allowed, wrong values are not.
     *
     * @return array{data: array<string,mixed>, errors: array<string,string>, amenities: ?list<int>, services: ?array, location: ?array}
     */
    public static function validate(int $step, array $in, string $profile, array $type, bool $partial): array
    {
        $fields = self::fields($profile, $step, $type);
        $out = ['data' => [], 'errors' => [], 'amenities' => null, 'services' => null, 'location' => null];

        // Clear every column of this step that this profile does not use.
        foreach (self::STEP_COLUMNS[$step] ?? [] as $col) {
            $out['data'][$col] = null;
        }

        foreach ($fields as $field => $rule) {
            $required = $rule === 'required' && !$partial;
            $label = self::label($field, $type);
            $raw = $in[$field] ?? null;
            $str = is_string($raw) ? trim($raw) : '';
            $err = null;

            switch ($field) {
                case 'title':
                    $err = self::text($str, $label, $required, 5, 150);
                    $out['data']['title'] = $str !== '' ? $str : '';
                    break;
                case 'description':
                    $err = self::text($str, $label, $required, 0, 5000);
                    $out['data']['description'] = $str !== '' ? $str : null;
                    break;
                case 'unit_no':
                    $err = self::text($str, $label, $required, 1, 40);
                    $out['data'][$field] = $str !== '' ? $str : null;
                    break;
                case 'building_name':
                case 'project_name':
                    $err = self::text($str, $label, $required, 2, 120);
                    $out['data'][$field] = $str !== '' ? $str : null;
                    break;
                case 'landmark':
                    $err = self::text($str, $label, $required, 2, 150);
                    $out['data'][$field] = $str !== '' ? $str : null;
                    break;
                case 'address_line':
                    $err = self::text($str, $label, $required, 5, 255);
                    $out['data'][$field] = $str !== '' ? $str : null;
                    break;
                case 'other_charges':
                    $err = self::text($str, $label, $required, 0, 255);
                    $out['data'][$field] = $str !== '' ? $str : null;
                    break;
                case 'contact_name':
                    $err = self::text($str, $label, $required, 2, 120);
                    if ($err === null && $str !== '' && !preg_match("/^[\\p{L}][\\p{L} .'-]*$/u", $str)) {
                        $err = 'Please use letters only.';
                    }
                    $out['data'][$field] = $str !== '' ? $str : null;
                    break;

                case 'bedrooms':
                case 'bathrooms':
                case 'balconies':
                    if ($str === '') {
                        $err = $required ? "Please choose {$label}." : null;
                    } elseif (!ctype_digit($str) || !array_key_exists((int) $str, self::options($field))) {
                        $err = "Please choose {$label}.";
                    }
                    $out['data'][$field] = $str !== '' && $err === null ? (int) $str : null;
                    break;

                case 'furnishing':
                case 'pg_available_for':
                case 'pg_suitable_for':
                    if ($str === '') {
                        $err = $required ? "Please choose {$label}." : null;
                    } elseif (!array_key_exists($str, self::options($field))) {
                        $err = "Please choose {$label}.";
                    }
                    $out['data'][$field] = $str !== '' && $err === null ? $str : null;
                    break;

                case 'area_value':
                    $unit = is_string($in['area_unit'] ?? null) ? $in['area_unit'] : 'sqft';
                    $str = str_replace([',', ' '], '', $str);
                    if ($str === '') {
                        $err = $required ? 'Please enter the area.' : null;
                    } elseif (!preg_match('/^\d{1,9}(\.\d{1,2})?$/', $str) || (float) $str <= 0) {
                        $err = 'Please enter the area as a number, e.g. 1200.';
                    } elseif (!array_key_exists($unit, self::options('area_unit'))) {
                        $err = 'Please choose Sq Ft or Sq Yard.';
                    }
                    if ($str !== '' && $err === null) {
                        $out['data']['area_value'] = $str;
                        $out['data']['area_unit'] = $unit;
                        $out['data']['area_sqft'] = round((float) $str * ($unit === 'sqyd' ? 9 : 1), 2);
                    }
                    break;
                case 'area_unit':
                    break; // saved together with area_value

                case 'available_from':
                    if ($str === '') {
                        $err = $required ? 'Please choose the date it is available from.' : null;
                    } else {
                        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $str);
                        $max = (new \DateTimeImmutable('now', new \DateTimeZone('Asia/Kolkata')))->modify('+2 years')->format('Y-m-d');
                        if ($d === false || $d->format('Y-m-d') !== $str || $str < '2000-01-01' || $str > $max) {
                            $err = 'Please choose a valid date (up to 2 years ahead).';
                        }
                    }
                    $out['data'][$field] = $str !== '' && $err === null ? $str : null;
                    break;

                case 'city_id':
                    $cityId = ctype_digit($str) ? (int) $str : 0;
                    if ($str === '') {
                        $err = $required ? 'Please choose the city.' : null;
                    } elseif (!Database::value('SELECT 1 FROM cities WHERE id = ? AND is_active = 1', [$cityId])) {
                        $err = 'Please choose the city.';
                    }
                    $out['data']['city_id'] = $cityId > 0 && $err === null ? $cityId : null;
                    break;

                case 'locality_id':
                    $cityId = $out['data']['city_id'] ?? null;
                    $other = is_string($in['locality_name'] ?? null) ? trim($in['locality_name']) : '';
                    if ($str === 'other') {
                        if ($other === '') {
                            $err = $required ? 'Please type your area name.' : null;
                        } elseif (mb_strlen($other) < 2 || mb_strlen($other) > 100 || !preg_match("/^[\\p{L}\\p{N}][\\p{L}\\p{N} .,'()\\/-]*$/u", $other)) {
                            $err = 'Please type a valid area name.';
                        }
                        $out['data']['locality_name'] = $other !== '' && $err === null ? $other : null;
                    } elseif ($str === '') {
                        $err = $required ? 'Please choose the locality / area.' : null;
                    } elseif (!ctype_digit($str) || $cityId === null
                        || !Database::value('SELECT 1 FROM localities WHERE id = ? AND city_id = ? AND is_active = 1', [(int) $str, $cityId])) {
                        $err = 'Please choose a locality in the selected city.';
                    } else {
                        $out['data']['locality_id'] = (int) $str;
                    }
                    if ($err !== null) {
                        $out['errors']['locality_id'] = $err;
                        $err = null;
                    }
                    break;

                case 'pincode':
                    if ($str === '') {
                        $err = $required ? 'Please enter the 6-digit pincode.' : null;
                    } elseif (!preg_match('/^[1-9]\d{5}$/', $str)) {
                        $err = 'Please enter a valid 6-digit pincode.';
                    }
                    $out['data'][$field] = $str !== '' && $err === null ? $str : null;
                    break;

                case 'map':
                    [$loc, $err] = self::location($in, $required);
                    $out['location'] = $loc;
                    break;

                case 'amenities':
                    $allowed = array_map('intval', array_column(self::amenities($profile), 'id'));
                    $picked = is_array($raw) ? array_map('intval', array_filter($raw, 'is_scalar')) : [];
                    // Ids outside this type's list are silently dropped (never trusted).
                    $out['amenities'] = array_values(array_intersect(array_unique($picked), $allowed));
                    break;

                case 'pg_services':
                    $services = [];
                    $prices = is_array($in['pg_service_price'] ?? null) ? $in['pg_service_price'] : [];
                    foreach (is_array($raw) ? $raw : [] as $code) {
                        if (!is_string($code) || !array_key_exists($code, self::options('pg_services'))) {
                            continue;
                        }
                        $p = is_string($prices[$code] ?? null) ? str_replace([',', ' ', '₹'], '', $prices[$code]) : '';
                        if ($p !== '' && (!ctype_digit($p) || (int) $p > 1000000)) {
                            $out['errors']['pg_services'] = 'Please enter service prices as whole rupees, e.g. 3000 (or leave blank).';
                            continue;
                        }
                        $services[$code] = $p === '' ? null : (int) $p;
                    }
                    $out['services'] = $services;
                    break;

                case 'rent':
                    [$v, $err] = self::money($str, $label, $required, 100, 10000000);
                    $out['data'][$field] = $v;
                    break;
                case 'security_deposit':
                    [$v, $err] = self::money($str, $label, $required, 0, 100000000);
                    $out['data'][$field] = $v;
                    break;
                case 'maintenance':
                    [$v, $err] = self::money($str, $label, $required, 0, 1000000);
                    $out['data'][$field] = $v;
                    break;

                case 'video_url':
                    if ($str === '') {
                        $out['data'][$field] = null;
                    } elseif (($id = self::youtubeId($str)) === null) {
                        $err = 'Please paste a YouTube link (youtube.com or youtu.be).';
                    } else {
                        $out['data'][$field] = 'https://www.youtube.com/watch?v=' . $id;
                    }
                    break;

                case 'contact_mobile_1':
                case 'contact_mobile_2':
                    $m = $str !== '' ? normalize_mobile($str) : null;
                    if ($str === '') {
                        $err = $required ? 'Please enter a 10-digit mobile number.' : null;
                    } elseif ($m === null) {
                        $err = 'Please enter a valid 10-digit Indian mobile number.';
                    } elseif ($field === 'contact_mobile_2' && $m === ($out['data']['contact_mobile_1'] ?? null)) {
                        $err = 'The second number is the same as the first.';
                    }
                    $out['data'][$field] = $err === null ? $m : null;
                    break;

                case 'contact_email':
                    if ($str === '') {
                        $err = $required ? 'Please enter an email address.' : null;
                    } elseif (strlen($str) > 191 || filter_var($str, FILTER_VALIDATE_EMAIL) === false) {
                        $err = 'Please enter a valid email address.';
                    }
                    $out['data'][$field] = $str !== '' && $err === null ? strtolower($str) : null;
                    break;

                // Photos and uploaded video are handled by the media step (Phase 5).
                case 'photos':
                case 'cover_media_id':
                case 'video':
                    break;
            }
            if ($err !== null) {
                $out['errors'][$field] = $err;
            }
        }
        return $out;
    }

    /**
     * Required fields still empty on a saved listing, grouped by step.
     * @return array<int, list<string>> step => labels
     */
    public static function missing(array $property, array $type): array
    {
        $profile = self::profile((string) $property['purpose'], $type);
        $hasLocation = (bool) Database::value('SELECT 1 FROM property_locations WHERE property_id = ?', [$property['id']]);
        $missing = [];
        for ($step = 2; $step < self::LAST_STEP; $step++) {
            foreach (self::fields($profile, $step, $type) as $field => $rule) {
                if ($rule !== 'required') {
                    continue;
                }
                $ok = match ($field) {
                    'map'         => $hasLocation,
                    'locality_id' => $property['locality_id'] !== null || ($property['locality_name'] ?? null) !== null,
                    'photos'      => !self::PHOTOS_REQUIRED || $property['cover_media_id'] !== null,
                    'cover_media_id', 'amenities', 'pg_services', 'video' => true,
                    default       => ($property[$field] ?? null) !== null && $property[$field] !== '',
                };
                if (!$ok) {
                    $missing[$step][] = self::label($field, $type);
                }
            }
        }
        return $missing;
    }

    /** Every column a step may write. */
    public static function stepColumns(int $step): array
    {
        return self::STEP_COLUMNS[$step] ?? [];
    }

    /**
     * Columns of steps 2-7 that this purpose/type does NOT use. Used when a
     * draft switches type (Flat -> Shop) so no stale bedroom data is left.
     * @return list<string>
     */
    public static function unusedColumns(string $profile, array $type): array
    {
        $used = [];
        foreach (array_keys(self::STEP_COLUMNS) as $step) {
            foreach (array_keys(self::fields($profile, $step, $type)) as $field) {
                $used = [...$used, ...match ($field) {
                    'area_value'  => ['area_value', 'area_unit', 'area_sqft'],
                    'locality_id' => ['locality_id', 'locality_name'],
                    default       => [$field],
                }];
            }
        }
        $all = array_merge(...array_values(self::STEP_COLUMNS));
        return array_values(array_diff($all, $used, ['title']));
    }

    /** "2 BHK Flat Vesu Surat" → used for the URL slug. */
    public static function slugFor(array $p, array $type): string
    {
        $locality = $p['locality_id'] ? Database::value('SELECT name FROM localities WHERE id = ?', [$p['locality_id']]) : ($p['locality_name'] ?? null);
        $city = $p['city_id'] ? Database::value('SELECT name FROM cities WHERE id = ?', [$p['city_id']]) : null;
        $bhk = $p['bedrooms'] ? ($p['bedrooms'] >= 5 ? '5 plus bhk' : $p['bedrooms'] . ' bhk') : '';
        return slugify(implode(' ', array_filter([$bhk, $type['name'], $p['purpose'] === 'pg' ? 'pg' : '', $locality, $city])));
    }

    public static function youtubeId(string $url): ?string
    {
        return preg_match('~^(?:https?://)?(?:www\.|m\.)?(?:youtube\.com/(?:watch\?(?:[^#]*&)?v=|shorts/|embed/|live/)|youtu\.be/)([A-Za-z0-9_-]{11})(?:[?&#/].*)?$~', $url, $m) ? $m[1] : null;
    }

    // ------------------------------------------------------------------

    private static function text(string $v, string $label, bool $required, int $min, int $max): ?string
    {
        if ($v === '') {
            return $required ? "Please enter {$label}." : null;
        }
        $len = mb_strlen($v);
        if ($len < $min) {
            return "{$label} must be at least {$min} characters.";
        }
        if ($len > $max) {
            return "{$label} can be up to {$max} characters.";
        }
        return null;
    }

    /** @return array{0: ?int, 1: ?string} */
    private static function money(string $v, string $label, bool $required, int $min, int $max): array
    {
        $v = str_replace([',', ' ', '₹'], '', $v);
        if ($v === '') {
            return [null, $required ? "Please enter {$label}." : null];
        }
        if (!ctype_digit($v) || strlen($v) > 10) {
            return [null, "Please enter {$label} in whole rupees, e.g. 22000."];
        }
        $n = (int) $v;
        if ($n < $min || $n > $max) {
            return [null, $label . ' must be between ' . money_inr($min) . ' and ' . money_inr($max) . '.'];
        }
        return [$n, null];
    }

    /**
     * Map pin: latitude/longitude from the map (or a pasted "lat, lng"
     * when no Maps key is configured), plus privacy choice.
     * @return array{0: ?array, 1: ?string}
     */
    private static function location(array $in, bool $required): array
    {
        $lat = is_string($in['latitude'] ?? null) ? trim($in['latitude']) : '';
        $lng = is_string($in['longitude'] ?? null) ? trim($in['longitude']) : '';
        $pasted = is_string($in['coordinates'] ?? null) ? trim($in['coordinates']) : '';
        if (($lat === '' || $lng === '') && $pasted !== '') {
            if (preg_match('/^\s*(-?\d{1,2}(?:\.\d+)?)\s*[, ]\s*(-?\d{1,3}(?:\.\d+)?)\s*$/', $pasted, $m)) {
                [$lat, $lng] = [$m[1], $m[2]];
            } else {
                return [null, 'Please paste the location as "latitude, longitude", e.g. 21.1702, 72.8311.'];
            }
        }
        if ($lat === '' || $lng === '') {
            return [null, $required ? 'Please mark the property on the map.' : null];
        }
        if (!is_numeric($lat) || !is_numeric($lng)) {
            return [null, 'Please mark the property on the map.'];
        }
        $la = round((float) $lat, 7);
        $lo = round((float) $lng, 7);
        if ($la < self::LAT_RANGE[0] || $la > self::LAT_RANGE[1] || $lo < self::LNG_RANGE[0] || $lo > self::LNG_RANGE[1]) {
            return [null, 'That location is outside India. Please check the pin.'];
        }
        $privacy = ($in['location_privacy'] ?? '') === 'exact' ? 'exact' : 'approximate';
        $addr = is_string($in['formatted_address'] ?? null) ? mb_substr(trim($in['formatted_address']), 0, 255) : '';
        $place = is_string($in['place_id'] ?? null) && preg_match('/^[A-Za-z0-9_-]{10,255}$/', $in['place_id']) ? $in['place_id'] : null;
        return [['latitude' => $la, 'longitude' => $lo, 'privacy' => $privacy,
                 'formatted_address' => $addr !== '' ? $addr : null, 'google_place_id' => $place], null];
    }
}
