<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;
use App\Services\SliderService;

/**
 * Homepage Sliders (spec 19, 36):
 *   GET  /admin/sliders                 list: image, title, subtitle, button, order, status, actions
 *   GET  /admin/sliders/new             add form;  POST /admin/sliders            create
 *   GET  /admin/sliders/{id}/edit       edit form; POST /admin/sliders/{id}       update
 *   POST /admin/sliders/{id}/status     active=1|0
 *   POST /admin/sliders/{id}/move       direction=up|down
 *   POST /admin/sliders/{id}/delete
 */
final class SliderController
{
    public function index(Request $request): Response
    {
        return Response::html(View::render('admin/sliders/index', [
            'title' => 'Homepage sliders', 'section' => 'sliders',
            'rows' => SliderService::all(), 'liveIds' => array_map(static fn ($s) => (int) $s['id'], SliderService::live()),
        ]));
    }

    public function create(Request $request): Response
    {
        return $this->form(null);
    }

    public function edit(Request $request): Response
    {
        $s = SliderService::find((int) $request->params['id']);
        return $s === null ? Response::html(View::render('errors/404', ['title' => 'Not found']), 404) : $this->form($s);
    }

    public function store(Request $request): Response
    {
        return $this->save($request, null);
    }

    public function update(Request $request): Response
    {
        return $this->save($request, (int) $request->params['id']);
    }

    public function status(Request $request): Response
    {
        $on = $request->str('active') === '1';
        $ok = SliderService::setActive((int) $request->params['id'], $on, (int) Auth::id());
        Session::flash($ok ? 'success' : 'error', $ok ? ($on ? 'Slide activated.' : 'Slide deactivated. It no longer shows on the homepage.') : 'Slide not found.');
        return Response::redirect('/admin/sliders');
    }

    public function move(Request $request): Response
    {
        $dir = $request->str('direction') === 'up' ? 'up' : 'down';
        if (!SliderService::move((int) $request->params['id'], $dir, (int) Auth::id())) {
            Session::flash('error', 'Slide not found.');
        }
        return Response::redirect('/admin/sliders');
    }

    public function delete(Request $request): Response
    {
        $ok = SliderService::delete((int) $request->params['id'], (int) Auth::id());
        Session::flash($ok ? 'success' : 'error', $ok ? 'Slide deleted.' : 'Slide not found.');
        return Response::redirect('/admin/sliders');
    }

    private function save(Request $request, ?int $id): Response
    {
        [$errors, $newId] = SliderService::save($id, $request->all(), $request->file('image'), (int) Auth::id());
        if ($errors) {
            if (isset($errors['_form'])) {
                Session::flash('error', $errors['_form']);
                return Response::redirect('/admin/sliders');
            }
            return back_with_errors($id === null ? '/admin/sliders/new' : '/admin/sliders/' . $id . '/edit', $errors, $request->all());
        }
        Session::flash('success', $id === null ? 'Slide added. It is live on the homepage.' : 'Slide saved.');
        return Response::redirect('/admin/sliders');
    }

    private function form(?array $s): Response
    {
        return Response::html(View::render('admin/sliders/form', [
            'title' => $s ? 'Edit slide' : 'Add slide', 'section' => 'sliders', 's' => $s,
            'maxMb' => (int) floor(\App\Services\Media\MediaService::limits()['image_bytes'] / 1048576),
        ]));
    }
}
