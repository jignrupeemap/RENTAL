<?php
declare(strict_types=1);

namespace App\Controllers\Public;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;
use App\Repositories\ListingRepository;
use App\Services\BookingService;
use App\Services\EnquiryService;

/**
 * 📞 Contact Owner and 📅 Request Visit (spec 22, 25, 26, 28), plus the
 * general "Post your requirement" form. Contact pages need a login; sending
 * needs an active account (RequireActiveAccount on the POST routes). The
 * listing is always re-read from the database by its id.
 */
final class ContactController
{
    public function contactForm(Request $request): Response
    {
        return $this->form($request, 'contact');
    }

    public function visitForm(Request $request): Response
    {
        return $this->form($request, 'visit');
    }

    public function contact(Request $request): Response
    {
        $id = (int) $request->params['id'];
        [$errors] = EnquiryService::fromProperty(Auth::user(), $id, $request->all(), $request->ip());
        if ($errors) {
            return $this->failed($id, '/property/' . $id . '/contact', $errors, $request->all());
        }
        $p = ListingRepository::find($id);
        Session::flash('success', 'Your message has been sent to the owner. They will contact you on ' . format_mobile(normalize_mobile($request->str('mobile_1'))) . '.');
        return Response::redirect($p !== null ? property_url($p) : '/account/contacted');
    }

    public function visit(Request $request): Response
    {
        $id = (int) $request->params['id'];
        [$errors] = BookingService::request(Auth::user(), $id, $request->all(), $request->ip());
        if ($errors) {
            return $this->failed($id, '/property/' . $id . '/visit', $errors, $request->all());
        }
        Session::flash('success', 'Visit requested. The owner will accept it or suggest another time; you can follow it here.');
        return Response::redirect('/account/bookings?status=open');
    }

    public function requirementForm(Request $request): Response
    {
        return Response::html(View::render('public/requirement', [
            'title'           => 'Post your requirement',
            'metaDescription' => 'Tell us what you are looking for to rent, lease or as a PG, and we will help you find it.',
            'canonical'       => url('/post-requirement'),
            'user'            => Auth::user(),
            'categories'      => Database::all('SELECT id, code, name FROM property_categories ORDER BY sort_order, id'),
            'cities'          => Database::all('SELECT id, name FROM cities WHERE is_active = 1 ORDER BY sort_order, name'),
            'localities'      => Database::all('SELECT l.id, l.city_id, l.name FROM localities l JOIN cities c ON c.id = l.city_id
                                                WHERE l.is_active = 1 AND c.is_active = 1 ORDER BY l.name'),
            'scripts'         => ['js/listings.js'],
        ]));
    }

    public function requirement(Request $request): Response
    {
        // Honeypot: real people never fill the hidden "website" field.
        if ($request->str('website') !== '') {
            Session::flash('success', 'Thank you. Our team will contact you soon.');
            return Response::redirect('/post-requirement');
        }
        $user = Auth::user();
        if ($user !== null && !Auth::canAct()) {
            Session::flash('error', 'Your account is ' . $user['status'] . '. Please contact support to restore access.');
            return Response::redirect('/post-requirement');
        }
        [$errors] = EnquiryService::general($user, $request->all(), $request->ip());
        if ($errors) {
            return back_with_errors('/post-requirement', $errors, $request->all());
        }
        Session::flash('success', 'Thank you. We have your requirement and our team will contact you soon.');
        return Response::redirect('/post-requirement');
    }

    private function form(Request $request, string $kind): Response
    {
        $id = (int) $request->params['id'];
        $p = ListingRepository::find($id);
        if ($p === null) {
            return ListingController::gone($id);
        }
        $uid = (int) Auth::id();
        if ((int) ($p['owner_id'] ?? 0) === $uid) {
            Session::flash('error', 'This is your own listing.');
            return Response::redirect(property_url($p));
        }
        $open = $kind === 'visit' ? BookingService::openRequest($uid, $id) : null;
        return Response::html(View::render('public/' . ($kind === 'visit' ? 'request-visit' : 'contact-owner'), [
            'title'      => ($kind === 'visit' ? 'Request a visit: ' : 'Contact owner: ') . ListingController::headline($p),
            'noindex'    => true,
            'p'          => $p,
            'cover'      => ListingRepository::withCovers([$p])[0]['cover'] ?? null,
            'user'       => Auth::user(),
            'canAct'     => Auth::canAct(),
            'open'       => $open,
            'lastContact' => $kind === 'contact' ? EnquiryService::lastContact($uid, $id) : null,
            'slots'      => BookingService::timeSlots(),
            'today'      => BookingService::todayIst(),
            'lastDay'    => gmdate('Y-m-d', strtotime(BookingService::todayIst() . ' UTC') + BookingService::MAX_DAYS_AHEAD * 86400),
        ]));
    }

    /** Back to the form with errors; a listing that just went offline goes to its 410 page instead. */
    private function failed(int $id, string $form, array $errors, array $input): Response
    {
        if (isset($errors['_form'])) {
            Session::flash('error', $errors['_form']);
            unset($errors['_form']);
        }
        if (ListingRepository::find($id) === null) {
            return Response::redirect('/account');
        }
        return back_with_errors($form, $errors, $input);
    }
}
