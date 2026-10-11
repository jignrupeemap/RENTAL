<?php
declare(strict_types=1);

namespace App\Controllers\Owner;

use App\Core\Auth;
use App\Core\Config;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\Settings;
use App\Core\View;
use App\Repositories\OwnerRepository;
use App\Services\Media\MediaService;
use App\Services\PropertyForm;
use App\Services\PropertyLimitService;
use App\Services\PropertyService;

/**
 * Step-by-step posting form (spec sections 7-13).
 *   GET  /owner/properties/new                 step 1, or the limit message
 *   POST /owner/properties/new                 creates the draft from step 1
 *   GET  /owner/properties/{id}/step/{1-8}     a step (8 = preview & submit)
 *   POST /owner/properties/{id}/step/{1-7}     save a step (Save & Continue / Save Draft)
 *   POST /owner/properties/{id}/submit         submit for admin approval
 *   POST /owner/properties/{id}/delete         discard a draft
 * The listing is always loaded with owner_id = Auth::id().
 * Admin\PropertyWizardController reuses this form under /admin/properties
 * by overriding the protected hooks at the bottom.
 */
class PropertyWizardController
{
    public function start(Request $request): Response
    {
        $uid = (int) Auth::id();
        $limit = PropertyLimitService::usage($uid);
        if ($limit['reached']) {
            return Response::html(View::render('owner/property-new', [
                'title' => 'Post a property', 'section' => 'add', 'limit' => $limit,
                'limitRequest' => PropertyLimitService::pendingRequest($uid),
            ]), 403);
        }
        return $this->render(null, 1, ['limit' => $limit]);
    }

    public function create(Request $request): Response
    {
        $uid = (int) Auth::id();
        [$type, $errors] = PropertyForm::validateType($request->str('purpose'), (int) $request->int('category_id', 0), (int) $request->int('type_id', 0));
        if ($errors) {
            return back_with_errors($this->base() . '/new', $errors, $request->all());
        }
        [$id, $error] = PropertyService::createDraft($uid, $request->str('purpose'), $type, $request->ip());
        if ($error === 'limit') {
            return Response::redirect('/owner/properties/new');
        }
        return Response::redirect('/owner/properties/' . $id . '/step/2');
    }

    /** "Edit" / "Continue draft": drafts resume where they stopped; others open the overview. */
    public function edit(Request $request): Response
    {
        $p = $this->property($request);
        if ($p === null) {
            return $this->notFound();
        }
        $step = $p['status'] === 'draft' ? min((int) $p['draft_step'] + 1, PropertyForm::LAST_STEP) : PropertyForm::LAST_STEP;
        return Response::redirect($this->base() . '/' . $p['id'] . '/step/' . $step);
    }

    public function show(Request $request): Response
    {
        $p = $this->property($request);
        if ($p === null) {
            return $this->notFound();
        }
        $step = (int) $request->params['step'];
        $reachable = $this->reachable($p);
        if ($step > $reachable) {
            return Response::redirect($this->base() . '/' . $p['id'] . '/step/' . $reachable);
        }
        return $this->render($p, $step);
    }

    public function save(Request $request): Response
    {
        $p = $this->property($request);
        if ($p === null) {
            return $this->notFound();
        }
        $uid = (int) Auth::id();
        $step = (int) $request->params['step'];
        $base = $this->base() . '/' . $p['id'] . '/step/';
        if ($step > $this->reachable($p)) {
            return Response::redirect($base . $this->reachable($p));
        }
        $isDraft = $p['status'] === 'draft';
        $exit = $request->str('action') === 'draft';

        if ($step === 1) {
            if (!$isDraft) {
                Session::flash('error', 'The property type cannot be changed after the listing has been submitted. Please contact support if it is wrong.');
                return Response::redirect($base . '2');
            }
            [$type, $errors] = PropertyForm::validateType($request->str('purpose'), (int) $request->int('category_id', 0), (int) $request->int('type_id', 0));
            if ($errors) {
                return back_with_errors($base . '1', $errors, $request->all());
            }
            if ((int) $type['id'] !== (int) $p['type_id'] || $request->str('purpose') !== $p['purpose']) {
                PropertyService::changeType((int) $p['id'], $uid, $request->str('purpose'), $type, $this->asAdmin());
                Session::flash('info', 'Property type changed. Details that do not apply to a ' . $type['name'] . ' were cleared.');
            }
            return $exit ? $this->savedDraft((int) $p['id']) : Response::redirect($base . '2');
        }

        $type = PropertyForm::type((int) $p['type_id']);
        $profile = PropertyForm::profile((string) $p['purpose'], $type);
        // Live listings must stay complete, so "save" always checks required fields there.
        $partial = $exit && $isDraft;
        $res = PropertyForm::validate($step, $request->all(), $profile, $type, $partial);
        if ($step === 6 && !$partial && PropertyForm::PHOTOS_REQUIRED && !MediaService::photos((int) $p['id'])) {
            $res['errors']['photos'] = 'Please add at least one photo.';
        }
        if ($res['errors']) {
            return back_with_errors($base . $step, $res['errors'], $request->all());
        }
        $out = PropertyService::saveStep((int) $p['id'], $uid, $step, $res, !$partial, $request->ip(), $this->asAdmin());

        $messages = [];
        if ($out['rent'] !== null) {
            $fresh = Database::one('SELECT rent FROM properties WHERE id = ?', [$p['id']]);
            $messages[] = match ($out['rent']) {
                'pending'   => 'Your new rent is waiting for admin approval. ' . money_inr((int) $fresh['rent']) . ' stays live until then.',
                'applied'   => 'Rent updated to ' . money_inr((int) $fresh['rent']) . '.',
                'withdrawn' => 'Rent change withdrawn. ' . money_inr((int) $fresh['rent']) . ' stays live.',
                default     => null,
            };
        }
        if ($out['reapproval']) {
            $messages[] = 'Your changes were sent to the admin team; the listing is hidden until it is approved again.';
        }
        $messages = array_filter($messages);

        if ($exit) {
            if ($isDraft) {
                return $this->savedDraft((int) $p['id']);
            }
            Session::flash('success', implode(' ', $messages) ?: ($out['changed'] ? 'Changes saved.' : 'No changes to save.'));
            return Response::redirect($this->exitUrl((int) $p['id']));
        }
        if ($messages) {
            Session::flash('success', implode(' ', $messages));
        }
        return Response::redirect($base . ($isDraft ? $step + 1 : PropertyForm::LAST_STEP));
    }

    public function submit(Request $request): Response
    {
        $p = $this->property($request);
        if ($p === null) {
            return $this->notFound();
        }
        [$ok, $missing] = PropertyService::submit((int) $p['id'], (int) Auth::id(), $request->ip());
        if (!$ok) {
            Session::flash('error', $missing
                ? 'A few details are still missing. Please complete them and submit again.'
                : 'This listing has already been submitted.');
            return Response::redirect('/owner/properties/' . $p['id'] . '/step/' . PropertyForm::LAST_STEP);
        }
        Session::flash('success', 'Submitted for approval. You will be notified as soon as the admin team reviews it.');
        return Response::redirect('/owner/properties?status=pending');
    }

    public function delete(Request $request): Response
    {
        $p = $this->property($request);
        if ($p === null) {
            return $this->notFound();
        }
        if (!PropertyService::deleteDraft((int) $p['id'], (int) Auth::id(), $request->ip())) {
            Session::flash('error', 'Only drafts can be deleted by the owner. Please contact support to remove a submitted listing.');
        } else {
            Session::flash('success', 'Draft deleted.');
        }
        return Response::redirect('/owner/properties');
    }

    // ------------------------------------------------------------------

    // ---- hooks overridden by the admin version -------------------------

    protected function property(Request $request): ?array
    {
        return OwnerRepository::property((int) Auth::id(), (int) $request->params['id']);
    }

    protected function asAdmin(): bool
    {
        return false;
    }

    /** URL prefix of the form: /owner/properties or /admin/properties. */
    protected function base(): string
    {
        return '/owner/properties';
    }

    /** Where "Save & exit" on a submitted/live listing goes. */
    protected function exitUrl(int $id): string
    {
        return '/owner/properties';
    }

    /** Pre-fill for step 7 (contact details). */
    protected function contactDefaults(array $p): array
    {
        return Auth::user();
    }

    /** Extra view variables (layout, menu section). */
    protected function viewExtras(?array $p): array
    {
        return [];
    }

    /** Highest step the owner may open: drafts go in order, other listings are complete. */
    protected function reachable(array $p): int
    {
        return $p['status'] === 'draft' ? min((int) $p['draft_step'] + 1, PropertyForm::LAST_STEP) : PropertyForm::LAST_STEP;
    }

    protected function savedDraft(int $id): Response
    {
        Session::flash('success', 'Draft saved. Continue any time from My Properties → Drafts.');
        return Response::redirect('/owner/properties?status=draft');
    }

    protected function notFound(): Response
    {
        return Response::html(View::render('errors/404', ['title' => 'Property not found']), 404);
    }

    protected function render(?array $p, int $step, array $extra = []): Response
    {
        $type = $p ? PropertyForm::type((int) $p['type_id']) : null;
        $profile = $p ? PropertyForm::profile((string) $p['purpose'], $type) : null;
        $data = [
            'title'    => $p ? ($p['status'] === 'draft' ? 'Post a property' : 'Edit listing #' . $p['id']) : 'Post a property',
            'section'  => $p && $p['status'] !== 'draft' ? 'properties' : 'add',
            'property' => $p,
            'type'     => $type,
            'profile'  => $profile,
            'step'     => $step,
            'steps'    => PropertyForm::steps(),
            'reachable' => $p ? $this->reachable($p) : 1,
            'fields'   => $p && $step > 1 && $step < PropertyForm::LAST_STEP ? PropertyForm::fields($profile, $step, $type) : [],
        ] + $extra;

        if ($step === 1) {
            $data += PropertyForm::catalog();
        }
        if ($step === 3 || $step === PropertyForm::LAST_STEP) {
            $data['location'] = Database::one('SELECT * FROM property_locations WHERE property_id = ?', [$p['id']]);
        }
        if ($step === 3) {
            $data['cities'] = PropertyForm::cities();
            $data['localities'] = PropertyForm::localities();
            $data['mapsKey'] = (string) Config::env('GOOGLE_MAPS_BROWSER_KEY', '');
            $data['defaultPrivacy'] = Settings::get('default_location_privacy', 'approximate') === 'exact' ? 'exact' : 'approximate';
        }
        if ($step === 4) {
            $data['amenities'] = PropertyForm::amenities($profile);
        }
        if ($step === 4 || $step === PropertyForm::LAST_STEP) {
            $data['selectedAmenities'] = array_map('intval', array_column(
                Database::all('SELECT amenity_id FROM property_amenities WHERE property_id = ?', [$p['id']]), 'amenity_id'));
            $data['services'] = array_column(Database::all('SELECT service, price FROM property_pg_services WHERE property_id = ?', [$p['id']]), 'price', 'service');
        }
        if ($step === 5) {
            $data['pendingRent'] = $p['pending_rent_change_id']
                ? Database::one("SELECT * FROM rental_change_history WHERE id = ? AND status = 'pending'", [$p['pending_rent_change_id']]) : null;
            $data['rentMode'] = (string) Settings::get('rent_change_approval_mode', 'increase_only');
        }
        if ($step === 6 || $step === PropertyForm::LAST_STEP) {
            $data['photos'] = MediaService::photos((int) $p['id']);
            $data['video'] = MediaService::video((int) $p['id']);
            $data['mediaLimits'] = MediaService::limits();
        }
        if ($step === 7) {
            $data['user'] = $this->contactDefaults($p);
        }
        if ($step === PropertyForm::LAST_STEP) {
            $data['missing'] = PropertyForm::missing($p, $type);
            $data['preview'] = $this->previewData($p, $type, $profile, $data);
        }
        $data['scripts'] = ['js/property-wizard.js'];
        $data['base'] = $this->base();
        $data['isAdmin'] = $this->asAdmin();
        $data = $this->viewExtras($p) + $data;
        return Response::html(View::render('owner/wizard', $data));
    }

    /** Everything the listing template needs, from the saved row. */
    private function previewData(array $p, array $type, string $profile, array $data): array
    {
        $amenities = $data['selectedAmenities']
            ? Database::all('SELECT name FROM amenities WHERE id IN (' . implode(',', $data['selectedAmenities']) . ') ORDER BY sort_order, name')
            : [];
        $pending = $p['pending_rent_change_id']
            ? Database::value("SELECT new_rent FROM rental_change_history WHERE id = ? AND status = 'pending'", [$p['pending_rent_change_id']]) : null;
        return [
            'p'         => $p,
            'type'      => $type,
            'profile'   => $profile,
            'city'      => $p['city_id'] ? Database::value('SELECT name FROM cities WHERE id = ?', [$p['city_id']]) : null,
            'locality'  => $p['locality_id'] ? Database::value('SELECT name FROM localities WHERE id = ?', [$p['locality_id']]) : $p['locality_name'],
            'amenities' => array_column($amenities, 'name'),
            'services'  => $data['services'],
            'location'  => $data['location'],
            'pendingRent' => $pending !== null ? (int) $pending : null,
            'photos'    => $data['photos'],
            'video'     => $data['video'],
        ];
    }
}
