<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;
use App\Repositories\AdminRepository;
use App\Repositories\UserRepository;
use App\Services\Admin\AdminPropertyService;
use App\Services\Media\MediaService;
use App\Services\PropertyForm;

/**
 * Admin property management (spec 14, 35, 60):
 *   GET  /admin/properties                 search + filters, paginated
 *   GET  /admin/properties/{id}            full listing, history, actions
 *   POST /admin/properties/{id}/action     approve | reject | changes | pause | activate | delete | restore | publish
 *   POST /admin/properties/{id}/featured   featured on/off + optional end date
 *   POST /admin/properties/{id}/owner      assign to an account or admin/system
 *   POST /admin/properties/{id}/note       private admin note
 * Add/Edit use the posting form (Admin\PropertyWizardController).
 */
final class PropertyController
{
    public function index(Request $request): Response
    {
        $filters = [
            'q'        => $request->str('q'),
            'purpose'  => in_array($request->str('purpose'), ['rent', 'pg', 'lease'], true) ? $request->str('purpose') : '',
            'category' => $request->int('category') ?: '',
            'city'     => $request->int('city') ?: '',
            'locality' => $request->int('locality') ?: '',
            'status'   => self::statusFilter($request->str('status')),
            'owner'    => $request->str('owner') === 'system' ? 'system' : ($request->int('owner') ?: ''),
            'featured' => $request->str('featured') === '1' ? '1' : '',
        ];
        $page = current_page();
        $result = AdminRepository::properties($filters, $page);
        $owner = is_int($filters['owner']) ? UserRepository::find($filters['owner']) : null;
        return Response::html(View::render('admin/properties/index', [
            'title'      => $filters['status'] === 'pending' ? 'Approvals' : 'Properties',
            'section'    => $filters['status'] === 'pending' ? 'approvals' : 'properties',
            'filters'    => $filters,
            'rows'       => $result['rows'],
            'total'      => $result['total'],
            'page'       => $page,
            'perPage'    => AdminRepository::PER_PAGE,
            'counts'     => AdminRepository::propertyStatusCounts(),
            'categories' => Database::all('SELECT id, name FROM property_categories ORDER BY sort_order'),
            'cities'     => PropertyForm::cities(),
            'localities' => PropertyForm::localities(),
            'ownerRow'   => $owner,
        ]));
    }

    public function show(Request $request): Response
    {
        $id = (int) $request->params['id'];
        $p = AdminRepository::property($id);
        if ($p === null) {
            return $this->notFound();
        }
        $type = PropertyForm::type((int) $p['type_id']);
        $profile = PropertyForm::profile((string) $p['purpose'], $type);
        $selected = array_map('intval', array_column(Database::all('SELECT amenity_id FROM property_amenities WHERE property_id = ?', [$id]), 'amenity_id'));
        $amenities = $selected ? Database::all('SELECT name FROM amenities WHERE id IN (' . implode(',', $selected) . ') ORDER BY sort_order, name') : [];
        $pending = $p['pending_rent_change_id']
            ? Database::one("SELECT * FROM rental_change_history WHERE id = ? AND status = 'pending'", [$p['pending_rent_change_id']]) : null;
        $photos = MediaService::photos($id);
        $video = MediaService::video($id);
        $location = Database::one('SELECT * FROM property_locations WHERE property_id = ?', [$id]);
        return Response::html(View::render('admin/properties/show', [
            'title'     => 'Property #' . $id,
            'section'   => $p['status'] === 'pending' ? 'approvals' : 'properties',
            'p'         => $p,
            'type'      => $type,
            'allowed'   => AdminPropertyService::allowed($p),
            'missing'   => $p['status'] !== 'deleted' ? PropertyForm::missing($p, $type) : [],
            'steps'     => PropertyForm::steps(),
            'history'   => AdminRepository::statusHistory($id),
            'pending'   => $pending,
            'rentLog'   => Database::all('SELECT * FROM rental_change_history WHERE property_id = ? ORDER BY id DESC LIMIT 10', [$id]),
            'adminLog'  => AdminRepository::adminLog(['entity' => 'property', 'id' => $id], 1)['rows'],
            'preview'   => [
                'p' => $p, 'type' => $type, 'profile' => $profile,
                'city' => $p['city_name'], 'locality' => $p['locality_label'],
                'amenities' => array_column($amenities, 'name'),
                'services' => array_column(Database::all('SELECT service, price FROM property_pg_services WHERE property_id = ?', [$id]), 'price', 'service'),
                'location' => $location, 'pendingRent' => $pending ? (int) $pending['new_rent'] : null,
                'photos' => $photos, 'video' => $video,
            ],
        ]));
    }

    public function action(Request $request): Response
    {
        $id = (int) $request->params['id'];
        [$ok, $message] = AdminPropertyService::act($id, $request->str('action'), (int) Auth::id(), $request->str('reason'));
        Session::flash($ok ? 'success' : 'error', $message);
        return Response::redirect(self::returnTo($request, '/admin/properties/' . $id));
    }

    public function featured(Request $request): Response
    {
        $id = (int) $request->params['id'];
        $on = $request->str('featured') === '1';
        $until = $request->str('featured_until');
        if ($until !== '' && (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $until) || $until < (new \DateTimeImmutable('today', new \DateTimeZone('Asia/Kolkata')))->format('Y-m-d'))) {
            Session::flash('error', 'Please choose an end date from today onwards, or leave it empty.');
        } elseif (!AdminPropertyService::setFeatured($id, $on, $until !== '' ? $until : null, (int) Auth::id())) {
            return $this->notFound();
        } else {
            Session::flash('success', $on ? 'Marked as featured.' : 'No longer featured.');
        }
        return Response::redirect(self::returnTo($request, '/admin/properties/' . $id));
    }

    public function owner(Request $request): Response
    {
        $id = (int) $request->params['id'];
        $userId = null;
        if ($request->str('assign') !== 'system') {
            $u = UserRepository::findByIdentifier($request->str('owner'));
            if ($u === null) {
                Session::flash('error', 'No account found with that mobile number or email.');
                return Response::redirect('/admin/properties/' . $id);
            }
            $userId = (int) $u['id'];
        }
        $error = AdminPropertyService::assignOwner($id, $userId, (int) Auth::id());
        Session::flash($error ? 'error' : 'success', $error ?? 'Owner updated.');
        return Response::redirect('/admin/properties/' . $id);
    }

    public function note(Request $request): Response
    {
        $id = (int) $request->params['id'];
        if (AdminRepository::property($id) === null) {
            return $this->notFound();
        }
        AdminPropertyService::setNote($id, $request->str('admin_note'), (int) Auth::id());
        Session::flash('success', 'Note saved.');
        return Response::redirect('/admin/properties/' . $id);
    }

    /** Hidden "return" field: back to the filtered list the admin came from (admin paths only). */
    private static function returnTo(Request $request, string $fallback): string
    {
        $to = safe_next($request->str('return'), $fallback);
        return str_starts_with($to, '/admin/') ? $to : $fallback;
    }

    private static function statusFilter(string $s): string
    {
        return in_array($s, ['draft', 'pending', 'changes_requested', 'approved', 'active', 'paused', 'rejected', 'expired', 'deleted', 'attention'], true) ? $s : '';
    }

    private function notFound(): Response
    {
        return Response::html(View::render('errors/404', ['title' => 'Not found']), 404);
    }
}
