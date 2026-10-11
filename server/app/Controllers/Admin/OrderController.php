<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;
use App\Repositories\AdminRepository;
use App\Services\Admin\AdminPropertyService;

/**
 * Display order (spec 16, 57): one list each for Rent, PG and Lease.
 * ↑ / ↓ move a listing one place; priority numbers + [Save Order] set many
 * at once. Priority 1 shows first; listings without a priority follow,
 * newest first. Featured listings are shown above the rest on public pages.
 */
final class OrderController
{
    private const PURPOSES = ['rent' => 'Rental', 'pg' => 'PG', 'lease' => 'Lease'];

    public function index(Request $request): Response
    {
        $purpose = self::purpose($request);
        return Response::html(View::render('admin/order', [
            'title'    => 'Display order',
            'section'  => 'order',
            'purpose'  => $purpose,
            'purposes' => self::PURPOSES,
            'rows'     => AdminRepository::orderList($purpose),
        ]));
    }

    public function save(Request $request): Response
    {
        $purpose = self::purpose($request);
        $rows = AdminRepository::orderList($purpose);
        $ids = array_map(static fn (array $r): int => (int) $r['id'], $rows);
        $ranked = count(array_filter($rows, static fn (array $r): bool => (int) $r['display_rank'] < 1000000));

        $move = $request->str('move');
        if (preg_match('/^(\d+):(up|down)$/', $move, $m)) {
            $i = array_search((int) $m[1], $ids, true);
            $j = $i === false ? false : ($m[2] === 'up' ? $i - 1 : $i + 1);
            if ($i === false || $j < 0 || $j >= count($ids)) {
                return Response::redirect('/admin/order?purpose=' . $purpose);
            }
            [$ids[$i], $ids[$j]] = [$ids[$j], $ids[$i]];
            // Everything above the moved pair gets an explicit priority so the move sticks.
            $keep = max($ranked, max($i, $j) + 1);
            $n = AdminPropertyService::saveOrder($purpose, array_slice($ids, 0, $keep), [], (int) Auth::id());
        } else {
            $input = $request->input('rank');
            $input = is_array($input) ? $input : [];
            $withRank = [];
            $without = [];
            foreach ($ids as $pos => $id) {
                $v = trim((string) ($input[$id] ?? ''));
                if ($v !== '' && ctype_digit($v) && (int) $v >= 1) {
                    $withRank[] = [(int) $v, $pos, $id];
                } else {
                    $without[] = $id;
                }
            }
            usort($withRank, static fn (array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);
            $n = AdminPropertyService::saveOrder($purpose, array_column($withRank, 2), $without, (int) Auth::id());
        }
        Session::flash('success', $n > 0 ? 'Order saved (' . plural($n, 'listing', 'listings') . ' moved).' : 'Order unchanged.');
        return Response::redirect('/admin/order?purpose=' . $purpose);
    }

    private static function purpose(Request $request): string
    {
        $p = $request->str('purpose', 'rent');
        return isset(self::PURPOSES[$p]) ? $p : 'rent';
    }
}
