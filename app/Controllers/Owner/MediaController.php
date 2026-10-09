<?php
declare(strict_types=1);

namespace App\Controllers\Owner;

use App\Core\Auth;
use App\Core\RateLimiter;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;
use App\Repositories\OwnerRepository;
use App\Services\Media\MediaException;
use App\Services\Media\MediaService;

/**
 * Photo and video actions on step 6 of the posting form.
 *   POST /owner/properties/{id}/photos              upload one or more photos
 *   POST /owner/properties/{id}/video               upload / replace the video
 *   POST /owner/properties/{id}/media/{mid}/cover   make a photo the cover
 *   POST /owner/properties/{id}/media/{mid}/move    direction=up|down
 *   POST /owner/properties/{id}/media/{mid}/delete  remove a photo or the video
 * Uploads answer JSON to fetch() (progress bars) and redirect for plain forms.
 * Admin\MediaController reuses these actions under /admin/properties.
 */
class MediaController
{
    public function uploadPhotos(Request $request): Response
    {
        $p = $this->property($request);
        if ($p === null) {
            return $this->notFound($request);
        }
        $uid = (int) Auth::id();
        if (!RateLimiter::hit('media_upload', 'u' . $uid)) {
            return $this->done($request, $p, 0, ['Too many uploads in a short time. Please wait a few minutes.'], 429);
        }
        $files = self::fileList($request->file('photos'));
        if (!$files) {
            return $this->done($request, $p, 0, ['Please choose at least one photo.'], 422);
        }
        $res = MediaService::addPhotos((int) $p['id'], $uid, $files, $request->ip(), $this->asAdmin());
        return $this->done($request, $p, $res['added'], $res['errors'], $res['added'] > 0 ? 200 : 422);
    }

    public function uploadVideo(Request $request): Response
    {
        $p = $this->property($request);
        if ($p === null) {
            return $this->notFound($request);
        }
        $uid = (int) Auth::id();
        if (!RateLimiter::hit('media_upload', 'u' . $uid)) {
            return $this->done($request, $p, 0, ['Too many uploads in a short time. Please wait a few minutes.'], 429);
        }
        $file = $request->file('video');
        try {
            MediaService::addVideo((int) $p['id'], $uid, is_array($file) && !is_array($file['name'] ?? null) ? $file : [], $request->ip(), $this->asAdmin());
        } catch (MediaException $e) {
            return $this->done($request, $p, 0, [$e->getMessage()], 422);
        }
        return $this->done($request, $p, 1, [], 200, 'Video uploaded.');
    }

    public function cover(Request $request): Response
    {
        $admin = $this->asAdmin();
        return $this->act($request, static fn (int $pid, int $uid, int $mid, string $ip): bool => MediaService::setCover($pid, $uid, $mid, $ip, $admin), 'Cover photo changed.');
    }

    public function move(Request $request): Response
    {
        $dir = $request->str('direction') === 'up' ? 'up' : 'down';
        $admin = $this->asAdmin();
        return $this->act($request, static fn (int $pid, int $uid, int $mid, string $ip): bool => MediaService::move($pid, $uid, $mid, $dir, $ip, $admin), null);
    }

    public function delete(Request $request): Response
    {
        $admin = $this->asAdmin();
        return $this->act($request, static fn (int $pid, int $uid, int $mid, string $ip): bool => MediaService::delete($pid, $uid, $mid, $ip, $admin), 'Removed.');
    }

    // ------------------------------------------------------------------

    private function act(Request $request, callable $fn, ?string $message): Response
    {
        $p = $this->property($request);
        if ($p === null) {
            return $this->notFound($request);
        }
        if (!$fn((int) $p['id'], (int) Auth::id(), (int) $request->params['mid'], $request->ip())) {
            return $this->notFound($request);
        }
        if ($message !== null) {
            Session::flash('success', $message);
        }
        return Response::redirect($this->back($p));
    }

    private function done(Request $request, array $p, int $added, array $errors, int $status, ?string $message = null): Response
    {
        $message ??= $added > 0 ? plural($added, 'photo', 'photos') . ' added.' : null;
        if ($request->isAjax()) {
            return Response::json(['ok' => $added > 0, 'added' => $added, 'errors' => $errors, 'message' => $message], $status);
        }
        if ($message !== null) {
            Session::flash('success', $message);
        }
        if ($errors) {
            Session::flash('error', implode(' ', $errors));
        }
        return Response::redirect($this->back($p));
    }

    protected function back(array $p): string
    {
        return '/owner/properties/' . (int) $p['id'] . '/step/6';
    }

    protected function property(Request $request): ?array
    {
        return OwnerRepository::property((int) Auth::id(), (int) $request->params['id']);
    }

    protected function asAdmin(): bool
    {
        return false;
    }

    private function notFound(Request $request): Response
    {
        if ($request->isAjax()) {
            return Response::json(['ok' => false, 'errors' => ['Not found.']], 404);
        }
        return Response::html(View::render('errors/404', ['title' => 'Not found']), 404);
    }

    /** $_FILES['photos'] (multiple) → list of single-file arrays. */
    private static function fileList(?array $f): array
    {
        if ($f === null || !isset($f['name'])) {
            return [];
        }
        if (!is_array($f['name'])) {
            return [$f];
        }
        $out = [];
        foreach (array_keys($f['name']) as $i) {
            if (($f['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            $out[] = ['name' => $f['name'][$i], 'tmp_name' => $f['tmp_name'][$i], 'error' => $f['error'][$i], 'size' => $f['size'][$i]];
        }
        return array_slice($out, 0, 25);
    }
}
