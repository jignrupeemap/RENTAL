<?php
declare(strict_types=1);

use App\Core\Config;

/** Escape for HTML output. Use for every value that came from a user or the DB. */
function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Absolute URL for a site-relative path. */
function url(string $path = '/'): string
{
    return rtrim((string) Config::env('APP_URL', ''), '/') . '/' . ltrim($path, '/');
}

/** Asset URL with a cache-busting version from the file's mtime. */
function asset(string $path): string
{
    $path = ltrim($path, '/');
    $file = PUBLIC_ROOT . '/assets/' . $path;
    $v = is_file($file) ? (int) filemtime($file) : 0;
    // Production serves the minified copy from bin/build-assets.php when it is up to date.
    if (App\Core\Config::isProduction() && !str_contains($path, '.min.') && preg_match('/\.(css|js)$/', $path, $m)) {
        $minPath = substr($path, 0, -strlen($m[0])) . '.min' . $m[0];
        $min = PUBLIC_ROOT . '/assets/' . $minPath;
        if (is_file($min) && filemtime($min) >= $v) {
            [$path, $v] = [$minPath, (int) filemtime($min)];
        }
    }
    return url('assets/' . $path) . '?v=' . $v;
}

/** ₹22,000 style formatting with Indian digit grouping (22,00,000). */
function money_inr(?int $amount): string
{
    if ($amount === null) {
        return '—';
    }
    $s = (string) abs($amount);
    if (strlen($s) > 3) {
        $last3 = substr($s, -3);
        $rest = substr($s, 0, -3);
        $rest = preg_replace('/\B(?=(\d{2})+(?!\d))/', ',', $rest);
        $s = $rest . ',' . $last3;
    }
    return ($amount < 0 ? '-' : '') . '₹' . $s;
}

/** Convert a UTC DATETIME string from MySQL into India time for display. */
function format_ist(?string $utc, string $format = 'd M Y, h:i A'): string
{
    if ($utc === null || $utc === '') {
        return '';
    }
    $dt = new DateTimeImmutable($utc, new DateTimeZone('UTC'));
    return $dt->setTimezone(new DateTimeZone((string) Config::get('timezone_display', 'Asia/Kolkata')))->format($format);
}

/** Current UTC time in MySQL DATETIME format. */
function now_utc(): string
{
    return gmdate('Y-m-d H:i:s');
}

/** URL-safe slug: "2 BHK Flat, Vesu" => "2-bhk-flat-vesu" */
function slugify(string $text, int $max = 80): string
{
    $text = strtolower(trim((string) preg_replace('/[^A-Za-z0-9]+/', '-', $text), '-'));
    return rtrim(substr($text, 0, $max), '-') ?: 'property';
}

/** 98XXXXXX10 style masking for logs and admin lists where full numbers aren't needed. */
function mask_mobile(string $mobile): string
{
    $digits = preg_replace('/\D/', '', $mobile) ?? '';
    return strlen($digits) < 6 ? '******' : substr($digits, 0, 2) . str_repeat('X', strlen($digits) - 4) . substr($digits, -2);
}

/**
 * Normalise an Indian mobile to 12 digits with country code (919876543210).
 * Accepts 9876543210, 09876543210, +91 98765 43210, 91-9876543210.
 */
function normalize_mobile(string $input): ?string
{
    $digits = preg_replace('/\D/', '', $input) ?? '';
    if (strlen($digits) === 11 && $digits[0] === '0') {
        $digits = substr($digits, 1);
    }
    if (strlen($digits) === 12 && str_starts_with($digits, '91')) {
        $digits = substr($digits, 2);
    }
    return preg_match('/^[6-9]\d{9}$/', $digits) ? '91' . $digits : null;
}

/** 919876543210 => +91 98765 43210 */
function format_mobile(?string $mobile): string
{
    if ($mobile === null || strlen($mobile) !== 12) {
        return (string) $mobile;
    }
    return '+91 ' . substr($mobile, 2, 5) . ' ' . substr($mobile, 7);
}

/** Hidden CSRF input for every POST form. */
function csrf_field(): string
{
    return App\Core\Csrf::field();
}

/** Previously submitted value after a validation error (never passwords). */
function old(string $field, string $default = ''): string
{
    $old = App\Core\Session::peekFlash('_old');
    return is_array($old) && isset($old[$field]) && is_string($old[$field]) ? $old[$field] : $default;
}

/** Validation error for a field, or null. */
function field_error(string $field): ?string
{
    $errors = App\Core\Session::peekFlash('_errors');
    return is_array($errors) ? ($errors[$field] ?? null) : null;
}

/** Bootstrap "is-invalid" class when a field has an error. */
function invalid(string $field): string
{
    return field_error($field) !== null ? ' is-invalid' : '';
}

/** Redirect back with errors and old input (passwords stripped). */
function back_with_errors(string $path, array $errors, array $input = []): App\Core\Response
{
    foreach (array_keys($input) as $k) {
        if (stripos((string) $k, 'password') !== false || $k === '_token' || $k === 'code') {
            unset($input[$k]);
        }
    }
    App\Core\Session::flash('_errors', $errors);
    App\Core\Session::flash('_old', $input);
    return App\Core\Response::redirect($path);
}

/** Only allow a same-site relative path as a post-login destination. */
function safe_next(?string $next, string $fallback = '/account'): string
{
    if (!is_string($next) || $next === '' || !str_starts_with($next, '/') || str_starts_with($next, '//')
        || str_contains($next, '\\') || preg_match('/[\r\n]/', $next)) {
        return $fallback;
    }
    return $next;
}

/** Render a labelled Bootstrap form field with its validation error (views/partials/field.php). */
function field(string $name, string $label, string $type = 'text', string $attrs = '', string $help = '', ?string $value = null): string
{
    return App\Core\View::render('partials/field', compact('name', 'label', 'type', 'attrs', 'help') + ($value !== null ? ['value' => $value] : []));
}

/** Public URL of a listing: /rent/2-bhk-flat-vesu-surat-p10001 (route added in Phase 7). */
function property_url(array $p): string
{
    return '/' . $p['purpose'] . '/' . ($p['slug'] !== '' ? $p['slug'] : 'property') . '-p' . $p['id'];
}

/** Human label + Bootstrap colour for every status used in the app. */
function status_badge(string $status): string
{
    static $map = [
        // properties
        'draft' => ['Draft', 'secondary'], 'pending' => ['Pending approval', 'warning'],
        'changes_requested' => ['Changes requested', 'warning'], 'approved' => ['Approved', 'info'],
        'active' => ['Active', 'success'], 'paused' => ['Paused', 'secondary'], 'rejected' => ['Rejected', 'danger'],
        'expired' => ['Expired', 'dark'], 'deleted' => ['Deleted', 'dark'],
        // enquiries
        'new' => ['New', 'primary'], 'contacted' => ['Contacted', 'info'], 'in_progress' => ['In progress', 'warning'], 'closed' => ['Closed', 'secondary'],
        // bookings
        'accepted' => ['Accepted', 'success'], 'rescheduled' => ['Rescheduled', 'info'], 'completed' => ['Completed', 'dark'], 'cancelled' => ['Cancelled', 'secondary'],
        // rent changes / limit requests
        'auto_applied' => ['Applied', 'success'],
    ];
    [$label, $color] = $map[$status] ?? [ucfirst(str_replace('_', ' ', $status)), 'secondary'];
    return '<span class="badge rounded-pill text-bg-' . $color . '">' . e($label) . '</span>';
}

/** Badge for an account status: active / inactive / suspended / blocked. */
function user_status_badge(string $status): string
{
    $color = ['active' => 'success', 'inactive' => 'secondary', 'suspended' => 'warning', 'blocked' => 'danger'][$status] ?? 'secondary';
    return '<span class="badge rounded-pill text-bg-' . $color . '">' . e(ucfirst($status)) . '</span>';
}

/** "Flat · Rent" style label. */
function purpose_label(string $purpose): string
{
    return ['rent' => 'Rent', 'lease' => 'Lease', 'pg' => 'PG'][$purpose] ?? ucfirst($purpose);
}

/** Current page number from ?page=, minimum 1. */
function current_page(): int
{
    return max(1, (int) ($_GET['page'] ?? 1));
}

/** Build a URL to the current path with some query parameters replaced. */
function url_with(array $params): string
{
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    $query = array_filter(array_merge($_GET, $params), static fn ($v) => $v !== null && $v !== '');
    return url($path) . ($query ? '?' . http_build_query($query) : '');
}

/** "1 enquiry" / "3 enquiries" */
function plural(int $n, string $one, string $many): string
{
    return number_format($n) . ' ' . ($n === 1 ? $one : $many);
}

/** URL of one generated photo size: thumb (480), md (960) or lg (1600). */
function media_url(array $m, string $size = 'md', bool $webp = false): string
{
    return url('uploads/' . $m['storage_dir'] . '/' . $m['file_key'] . '_' . $size . '.' . ($webp && $m['has_webp'] ? 'webp' : 'jpg'));
}

/** URL of an uploaded video file. */
function video_url(array $m): string
{
    return url('uploads/' . $m['storage_dir'] . '/' . $m['file_key'] . '.' . $m['ext']);
}

/**
 * Responsive <picture>: WebP with JPEG fallback, srcset of the three sizes,
 * width/height to avoid layout shift, lazy unless it is the first image.
 */
function media_picture(array $m, string $sizes, string $alt, bool $lazy = true, string $class = '', string $default = 'md'): string
{
    $w = (int) $m['width'];
    $h = (int) $m['height'];
    $set = static function (bool $webp) use ($m, $w, $h): string {
        $parts = [];
        foreach (App\Core\Config::get('uploads.image_sizes') as $name => $target) {
            [$vw] = App\Services\Media\ImageProcessor::variantSize($w, $h, (int) $target);
            $parts[$vw] = media_url($m, $name, $webp) . ' ' . $vw . 'w'; // equal widths collapse (small originals)
        }
        return implode(', ', $parts);
    };
    [$dw, $dh] = App\Services\Media\ImageProcessor::variantSize($w, $h, (int) App\Core\Config::get('uploads.image_sizes.' . $default, 960));
    $img = '<img src="' . e(media_url($m, $default)) . '" srcset="' . e($set(false)) . '" sizes="' . e($sizes) . '" alt="' . e($alt) . '"'
        . ' width="' . $dw . '" height="' . $dh . '"' . ($lazy ? ' loading="lazy" decoding="async"' : ' fetchpriority="high"')
        . ($class !== '' ? ' class="' . e($class) . '"' : '') . '>';
    if (!$m['has_webp']) {
        return $img;
    }
    return '<picture><source type="image/webp" srcset="' . e($set(true)) . '" sizes="' . e($sizes) . '">' . $img . '</picture>';
}

/**
 * Allow-list HTML for admin-written page content (About, Terms, Privacy):
 * headings, paragraphs, lists, emphasis and links only. Every other tag is
 * unwrapped to its text, every attribute except a safe href is dropped, so
 * scripts, event handlers and javascript: links can never reach a visitor.
 */
function safe_html(string $html): string
{
    static $tags = ['p', 'br', 'h2', 'h3', 'h4', 'ul', 'ol', 'li', 'strong', 'b', 'em', 'i', 'a', 'blockquote', 'hr'];
    $doc = new DOMDocument();
    $prev = libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="UTF-8"><div id="safe-root">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);
    $root = $doc->getElementById('safe-root');
    if ($root === null) {
        return e(strip_tags($html));
    }
    $clean = static function (DOMNode $node) use (&$clean, $tags): void {
        foreach (iterator_to_array($node->childNodes) as $child) {
            if ($child instanceof DOMElement) {
                $clean($child);
                $name = strtolower($child->tagName);
                if (in_array($name, ['script', 'style', 'iframe', 'object', 'embed', 'template', 'noscript'], true)) {
                    $node->removeChild($child);
                    continue;
                }
                if (!in_array($name, $tags, true)) {
                    while ($child->firstChild) {
                        $node->insertBefore($child->firstChild, $child);
                    }
                    $node->removeChild($child);
                    continue;
                }
                $href = $name === 'a' ? trim($child->getAttribute('href')) : '';
                foreach (iterator_to_array($child->attributes) as $attr) {
                    $child->removeAttribute($attr->name);
                }
                if ($href !== '' && preg_match('~^(https?://|mailto:|tel:|/(?!/)|#)~i', $href)) {
                    $child->setAttribute('href', $href);
                    if (preg_match('~^https?://~i', $href)) {
                        $child->setAttribute('rel', 'noopener nofollow');
                    }
                }
            } elseif (!($child instanceof DOMText)) {
                $node->removeChild($child); // comments, processing instructions
            }
        }
    };
    $clean($root);
    $out = '';
    foreach ($root->childNodes as $child) {
        $out .= $doc->saveHTML($child);
    }
    return $out;
}
