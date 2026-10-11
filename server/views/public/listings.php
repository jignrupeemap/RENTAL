<?php
/**
 * /rent, /pg, /lease (spec 21): filters on the left (a collapsible panel on
 * phones), results with sort and server-side pagination.
 * @var string $purpose @var string $heading @var array $f @var array $rows @var int $total @var int $page @var int $perPage
 * @var array $shortlisted @var array $cities @var array $localities @var array $categories @var array $types @var array $budgets
 */
use App\Repositories\ListingRepository;
use App\Services\PropertyForm;

$this->layout('layouts/main');
$here = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) . (($_SERVER['QUERY_STRING'] ?? '') !== '' ? '?' . $_SERVER['QUERY_STRING'] : '');
$sel = static fn ($a, $b): string => (string) $a === (string) $b && $b !== null ? ' selected' : '';
$isPg = $purpose === 'pg';
$hasFilters = (bool) array_filter(array_diff_key($f, ['purpose' => 1, 'sort' => 1]), static fn ($v) => $v !== null && $v !== '');
$opts = static fn (string $k): array => PropertyForm::options($k);
?>
<div class="container py-4">
    <nav class="purpose-tabs mb-3" aria-label="Purpose">
        <?php foreach (['rent' => 'Rent', 'pg' => 'PG', 'lease' => 'Lease'] as $k => $l): ?>
            <a class="purpose-tab<?= $k === $purpose ? ' active' : '' ?>" href="<?= e(url('/' . $k . ($f['city'] ? '?city=' . $f['city'] : ''))) ?>"<?= $k === $purpose ? ' aria-current="page"' : '' ?>><?= $l ?></a>
        <?php endforeach; ?>
    </nav>
    <div class="d-flex flex-wrap justify-content-between align-items-end gap-2 mb-3">
        <div>
            <h1 class="h3 mb-1"><?= e($heading) ?></h1>
            <div class="text-muted small"><?= e(plural($total, 'property', 'properties')) ?> found</div>
        </div>
        <button class="btn btn-outline-secondary btn-sm d-lg-none" type="button" data-bs-toggle="collapse" data-bs-target="#filters" aria-expanded="false" aria-controls="filters">
            Filters<?= $hasFilters ? ' (on)' : '' ?>
        </button>
    </div>

    <div class="row g-4">
        <aside class="col-lg-3">
            <form id="filters" class="collapse d-lg-block filter-panel card border-0 shadow-sm" method="get" action="<?= e(url('/' . $purpose)) ?>">
                <div class="card-body d-grid gap-3">
                    <div>
                        <label class="form-label small fw-semibold" for="fl-city">City</label>
                        <select class="form-select form-select-sm" id="fl-city" name="city" data-city-select>
                            <option value="">All cities</option>
                            <?php foreach ($cities as $c): ?><option value="<?= (int) $c['id'] ?>"<?= $sel($c['id'], $f['city']) ?>><?= e($c['name']) ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="form-label small fw-semibold" for="fl-locality">Locality</label>
                        <select class="form-select form-select-sm" id="fl-locality" name="locality" data-locality-select>
                            <option value="">All localities</option>
                            <?php foreach ($cities as $c): ?>
                                <optgroup label="<?= e($c['name']) ?>" data-city="<?= (int) $c['id'] ?>">
                                    <?php foreach ($localities as $l): if ((int) $l['city_id'] !== (int) $c['id']) continue; ?>
                                        <option value="<?= (int) $l['id'] ?>"<?= $sel($l['id'], $f['locality']) ?>><?= e($l['name']) ?></option>
                                    <?php endforeach; ?>
                                </optgroup>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php if (count($categories) > 1): ?>
                        <div>
                            <label class="form-label small fw-semibold" for="fl-category">Property type</label>
                            <select class="form-select form-select-sm" id="fl-category" name="category" data-category-select>
                                <option value="">All types</option>
                                <?php foreach ($categories as $c): ?><option value="<?= (int) $c['id'] ?>"<?= $sel($c['id'], $f['category']) ?>><?= e($c['name']) ?></option><?php endforeach; ?>
                            </select>
                        </div>
                    <?php endif; ?>
                    <div>
                        <label class="form-label small fw-semibold" for="fl-type"><?= $isPg ? 'Building' : 'Sub-type' ?></label>
                        <select class="form-select form-select-sm" id="fl-type" name="type" data-type-select>
                            <option value="">Any</option>
                            <?php foreach ($types as $t): ?><option value="<?= (int) $t['id'] ?>" data-category="<?= (int) $t['category_id'] ?>"<?= $sel($t['id'], $f['type']) ?>><?= e($t['name']) ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="form-label small fw-semibold" for="fl-budget">Budget</label>
                        <select class="form-select form-select-sm" id="fl-budget" name="budget">
                            <option value="">Any budget</option>
                            <?php foreach ($budgets as $i => $b): ?><option value="<?= $i ?>"<?= $sel($i, $f['budget']) ?>><?= e($b['label']) ?></option><?php endforeach; ?>
                        </select>
                        <?php if ($f['budget'] === null && ($f['min'] || $f['max'])): ?>
                            <div class="small text-muted mt-1">Custom: <?= e(money_inr($f['min'] ?? 0)) ?> to <?= e($f['max'] ? money_inr($f['max']) : 'any') ?>
                                <input type="hidden" name="min" value="<?= (int) $f['min'] ?>"><input type="hidden" name="max" value="<?= (int) $f['max'] ?>">
                            </div>
                        <?php endif; ?>
                    </div>
                    <div data-residential-only>
                        <span class="form-label small fw-semibold d-block">Bedrooms</span>
                        <div class="btn-group btn-group-sm w-100" role="group" aria-label="Bedrooms">
                            <input type="radio" class="btn-check" name="bedrooms" id="bed-any" value=""<?= $f['bedrooms'] === null ? ' checked' : '' ?>><label class="btn btn-outline-secondary" for="bed-any">Any</label>
                            <?php foreach ([1 => '1', 2 => '2', 3 => '3', 4 => '4+'] as $v => $l): ?>
                                <input type="radio" class="btn-check" name="bedrooms" id="bed-<?= $v ?>" value="<?= $v ?>"<?= $f['bedrooms'] === $v ? ' checked' : '' ?>><label class="btn btn-outline-secondary" for="bed-<?= $v ?>"><?= $l ?></label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div>
                        <label class="form-label small fw-semibold" for="fl-furnishing">Furnishing</label>
                        <select class="form-select form-select-sm" id="fl-furnishing" name="furnishing">
                            <option value="">Any</option>
                            <?php foreach ($opts('furnishing') as $k => $l): ?><option value="<?= e($k) ?>"<?= $sel($k, $f['furnishing']) ?>><?= e($l) ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <?php if ($isPg): ?>
                        <div>
                            <label class="form-label small fw-semibold" for="fl-pgfor">Available for</label>
                            <select class="form-select form-select-sm" id="fl-pgfor" name="pg_for">
                                <option value="">Anyone</option>
                                <?php foreach ($opts('pg_available_for') as $k => $l): ?><option value="<?= e($k) ?>"<?= $sel($k, $f['pg_for']) ?>><?= e($l) ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label class="form-label small fw-semibold" for="fl-pgsuit">Suitable for</label>
                            <select class="form-select form-select-sm" id="fl-pgsuit" name="pg_suitable">
                                <option value="">Anyone</option>
                                <?php foreach ($opts('pg_suitable_for') as $k => $l): ?><option value="<?= e($k) ?>"<?= $sel($k, $f['pg_suitable']) ?>><?= e($l) ?></option><?php endforeach; ?>
                            </select>
                        </div>
                    <?php endif; ?>
                    <?php if ($f['sort'] !== 'recommended'): ?><input type="hidden" name="sort" value="<?= e($f['sort']) ?>"><?php endif; ?>
                    <div class="d-flex gap-2">
                        <button class="btn btn-primary btn-sm flex-grow-1" type="submit">Show properties</button>
                        <?php if ($hasFilters): ?><a class="btn btn-link btn-sm" href="<?= e(url('/' . $purpose)) ?>">Clear</a><?php endif; ?>
                    </div>
                </div>
            </form>
        </aside>

        <section class="col-lg-9" aria-label="Results">
            <form class="d-flex justify-content-end align-items-center gap-2 mb-3" method="get" action="<?= e(url('/' . $purpose)) ?>" data-sort-form>
                <?php foreach (array_diff_key($_GET, ['sort' => 1, 'page' => 1]) as $k => $v): if (!is_string($v) || $v === '') continue; ?>
                    <input type="hidden" name="<?= e($k) ?>" value="<?= e($v) ?>">
                <?php endforeach; ?>
                <label class="small text-muted" for="sort">Sort by</label>
                <select class="form-select form-select-sm w-auto" id="sort" name="sort">
                    <?php foreach (ListingRepository::SORTS as $k => $l): ?><option value="<?= e($k) ?>"<?= $sel($k, $f['sort']) ?>><?= e($l) ?></option><?php endforeach; ?>
                </select>
                <noscript><button class="btn btn-sm btn-outline-secondary" type="submit">Sort</button></noscript>
            </form>

            <?php if ($rows): ?>
                <div class="row row-cols-1 row-cols-sm-2 row-cols-xl-3 g-3">
                    <?php foreach ($rows as $i => $r): ?>
                        <div class="col"><?php $on = in_array((int) $r['id'], $shortlisted, true); $return = $here; $eager = $i < 3; require APP_ROOT . '/views/partials/property-card.php'; ?></div>
                    <?php endforeach; ?>
                </div>
                <?php require APP_ROOT . '/views/partials/pagination.php'; ?>
            <?php else: ?>
                <div class="empty-state card border-0 shadow-sm">
                    <div class="card-body text-center py-5">
                        <h2 class="h5">No properties match these filters</h2>
                        <p class="text-muted mb-3">Try another locality or a wider budget.</p>
                        <div class="d-flex flex-wrap justify-content-center gap-2">
                            <?php if ($hasFilters): ?><a class="btn btn-outline-primary" href="<?= e(url('/' . $purpose)) ?>">Clear all filters</a><?php endif; ?>
                            <a class="btn btn-primary" href="<?= e(url('/post-requirement')) ?>">Post your requirement</a>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </section>
    </div>
</div>
