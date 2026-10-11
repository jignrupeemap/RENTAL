<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\Owner\PropertyWizardController as OwnerWizard;
use App\Core\Auth;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Repositories\UserRepository;
use App\Services\PropertyForm;
use App\Services\PropertyService;

/**
 * Admin "Add Property" and "Edit" (spec 15, 35): the same 8-step form as
 * owners, under /admin/properties, with these differences:
 *   - no posting limit; the listing is assigned to an existing account or to admin/system
 *   - any listing can be opened (no owner scoping; routes are behind RequireAdmin)
 *   - rent changes go live at once (still recorded in the rental change history)
 *   - edits are logged in admin_activity_logs and never send a listing back for approval
 *   - step 8 offers "Publish now" for admin-created drafts
 */
final class PropertyWizardController extends OwnerWizard
{
    public function start(Request $request): Response
    {
        $prefill = $request->int('owner');
        $owner = $prefill ? UserRepository::find($prefill) : null;
        return $this->render(null, 1, ['ownerPrefill' => $owner ? ($owner['email'] ?: format_mobile($owner['mobile'])) : '']);
    }

    public function create(Request $request): Response
    {
        $errors = [];
        $ownerId = null;
        if ($request->str('assign') === 'user') {
            $owner = UserRepository::findByIdentifier($request->str('owner'));
            if ($owner === null || $owner['deleted_at'] !== null) {
                $errors['owner'] = 'No account found with that mobile number or email.';
            } else {
                $ownerId = (int) $owner['id'];
            }
        }
        [$type, $typeErrors] = PropertyForm::validateType($request->str('purpose'), (int) $request->int('category_id', 0), (int) $request->int('type_id', 0));
        $errors += $typeErrors;
        if ($errors) {
            return back_with_errors('/admin/properties/new', $errors, $request->all());
        }
        $id = PropertyService::createForAdmin((int) Auth::id(), $ownerId, $request->str('purpose'), $type, $request->ip());
        return Response::redirect('/admin/properties/' . $id . '/step/2');
    }

    public function submit(Request $request): Response
    {
        return $this->notFound(); // admins publish or approve from the listing page instead
    }

    public function delete(Request $request): Response
    {
        return $this->notFound();
    }

    // ------------------------------------------------------------------

    protected function property(Request $request): ?array
    {
        return Database::one("SELECT * FROM properties WHERE id = ? AND status <> 'deleted'", [(int) $request->params['id']]);
    }

    protected function asAdmin(): bool
    {
        return true;
    }

    protected function base(): string
    {
        return '/admin/properties';
    }

    protected function exitUrl(int $id): string
    {
        return '/admin/properties/' . $id;
    }

    protected function savedDraft(int $id): Response
    {
        Session::flash('success', 'Draft saved.');
        return Response::redirect('/admin/properties/' . $id);
    }

    /** Step 7 pre-fill: the owner's details, or the admin's own for admin/system listings. */
    protected function contactDefaults(array $p): array
    {
        if ($p['owner_id'] !== null && ($owner = UserRepository::find((int) $p['owner_id'])) !== null) {
            return $owner;
        }
        return Auth::user();
    }

    protected function viewExtras(?array $p): array
    {
        return [
            'layout'  => 'layouts/admin',
            'section' => 'properties',
            'title'   => $p ? ($p['status'] === 'draft' ? 'Add property #' . $p['id'] : 'Edit listing #' . $p['id']) : 'Add property',
        ];
    }
}
