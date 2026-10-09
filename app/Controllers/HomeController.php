<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Config;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\Settings;
use App\Core\View;
use App\Repositories\ListingRepository;
use App\Services\PropertyForm;
use App\Services\SliderService;

final class HomeController
{
    /** Homepage: search card + featured/latest listings (the slider arrives in Phase 11). */
    public function index(Request $request): Response
    {
        $listings = ListingRepository::homepage(8);
        return Response::html(View::render('public/home', [
            'title'           => (string) Settings::get('site_name', 'Rental Website'),
            'metaDescription' => (string) Settings::get('site_tagline', '') ?: 'Rent, PG and lease properties listed directly by owners. Search by city, locality, type and budget.',
            'canonical'       => url('/'),
            'slides'          => SliderService::live(),
            'cities'          => PropertyForm::cities(),
            'localities'      => PropertyForm::localities(),
            'categories'      => Database::all('SELECT id, code, name FROM property_categories WHERE is_active = 1 ORDER BY sort_order'),
            'budgets'         => ListingRepository::budgets(),
            'listings'        => $listings,
            'counts'          => ListingRepository::counts(),
            'shortlisted'     => ListingRepository::shortlistedIds(Auth::id(), array_map(static fn ($r) => (int) $r['id'], $listings)),
            'scripts'         => ['js/listings.js'],
        ]));
    }

    /** /page/{slug}: About, Terms, Privacy, Cookie policy from cms_pages. */
    public function page(Request $request): Response
    {
        $page = Database::one('SELECT * FROM cms_pages WHERE slug = ? AND is_published = 1', [strtolower($request->params['slug'])]);
        if ($page === null) {
            return Response::html(View::render('errors/404', ['title' => 'Page not found']), 404);
        }
        return Response::html(View::render('public/page', [
            'title' => $page['title'], 'page' => $page, 'metaDescription' => (string) $page['meta_description'],
        ]));
    }

    /**
     * Deployment check: confirms PHP version, extensions, DB connection and
     * migration state. Details are only shown outside production.
     */
    public function health(Request $request): Response
    {
        $checks = [
            'php'        => PHP_VERSION,
            'pdo_mysql'  => extension_loaded('pdo_mysql'),
            'gd'         => extension_loaded('gd'),
            'gd_webp'    => function_exists('imagewebp'),
            'fileinfo'   => extension_loaded('fileinfo'),
            'mbstring'   => extension_loaded('mbstring'),
            'storage_writable' => is_writable(APP_ROOT . '/storage/logs'),
            'uploads_writable' => is_writable(PUBLIC_ROOT . '/uploads'),
        ];
        try {
            $checks['database'] = (bool) Database::value('SELECT 1');
            $checks['migrations'] = (int) Database::value('SELECT COUNT(*) FROM schema_migrations');
        } catch (\Throwable) {
            $checks['database'] = false;
        }
        $ok = $checks['database'] && $checks['pdo_mysql'] && $checks['gd'] && $checks['fileinfo'];

        return Config::isProduction()
            ? Response::json(['ok' => $ok], $ok ? 200 : 503)
            : Response::json(['ok' => $ok, 'checks' => $checks], $ok ? 200 : 503);
    }
}
