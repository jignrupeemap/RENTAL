<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\Owner\MediaController as OwnerMedia;
use App\Core\Database;
use App\Core\Request;

/** Photo and video actions for any listing, from the admin edit form. */
final class MediaController extends OwnerMedia
{
    protected function back(array $p): string
    {
        return '/admin/properties/' . (int) $p['id'] . '/step/6';
    }

    protected function property(Request $request): ?array
    {
        return Database::one("SELECT * FROM properties WHERE id = ? AND status <> 'deleted'", [(int) $request->params['id']]);
    }

    protected function asAdmin(): bool
    {
        return true;
    }
}
