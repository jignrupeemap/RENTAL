<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Config;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Repositories\ListingRepository;

/**
 * /robots.txt and /sitemap.xml (spec 50). Outside production the site asks
 * search engines to stay away, so a staging copy is never indexed.
 */
final class SeoController
{
    public function robots(Request $request): Response
    {
        $body = Config::isProduction()
            ? "User-agent: *\nDisallow: /admin\nDisallow: /owner\nDisallow: /account\nDisallow: /login\nDisallow: /register\n"
              . "Disallow: /forgot-password\nDisallow: /reset-password\nDisallow: /search\nDisallow: /shortlist\nDisallow: /property/\n\nSitemap: " . url('/sitemap.xml') . "\n"
            : "User-agent: *\nDisallow: /\n";
        return new Response($body, 200, ['Content-Type' => 'text/plain; charset=utf-8']);
    }

    public function sitemap(Request $request): Response
    {
        $urls = [[url('/'), null], [url('/rent'), null], [url('/pg'), null], [url('/lease'), null]];
        foreach (Database::all('SELECT slug, updated_at FROM cms_pages WHERE is_published = 1') as $pg) {
            $urls[] = [url('/page/' . $pg['slug']), $pg['updated_at']];
        }
        foreach (ListingRepository::sitemap() as $p) {
            $urls[] = [url(property_url($p)), $p['updated_at']];
        }
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
        foreach ($urls as [$loc, $mod]) {
            $xml .= '  <url><loc>' . htmlspecialchars($loc, ENT_XML1) . '</loc>'
                . ($mod ? '<lastmod>' . gmdate('Y-m-d', strtotime($mod . ' UTC')) . '</lastmod>' : '') . "</url>\n";
        }
        return new Response($xml . "</urlset>\n", 200, ['Content-Type' => 'application/xml; charset=utf-8']);
    }
}
