<?php
declare(strict_types=1);

namespace App\Controllers\Owner;

use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;
use App\Core\Settings;
use App\Core\View;
use App\Repositories\OwnerRepository;

final class RentChangeController
{
    public function index(Request $request): Response
    {
        $page = current_page();
        $result = OwnerRepository::rentChanges((int) Auth::id(), $page);
        return Response::html(View::render('owner/rent-changes', [
            'title'   => 'Rental Change History',
            'section' => 'rent',
            'rows'    => $result['rows'],
            'total'   => $result['total'],
            'page'    => $page,
            'perPage' => 20,
            'mode'    => (string) Settings::get('rent_change_approval_mode', 'increase_only'),
        ]));
    }
}
