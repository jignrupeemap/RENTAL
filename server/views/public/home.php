<?php
/**
 * Homepage (spec 17, 19, 20): the admin-managed hero slider (when any slide
 * is live), then the search card, purpose tiles and listings.
 * @var array $slides @var array $cities @var array $localities @var array $categories @var array $budgets @var array $listings @var array $counts @var array $shortlisted
 */
use App\Services\SliderService;
$this->layout('layouts/main');
$many = count($slides) > 1;
?>
<?php if ($slides): ?>
<section class="hero-slider carousel slide<?= $many ? ' carousel-fade' : '' ?>" id="heroSlider" aria-roledescription="carousel" aria-label="Featured"
    <?= $many ? 'data-bs-ride="carousel" data-bs-interval="6000" data-hero-slider' : '' ?>>
    <?php if ($many): ?>
        <div class="carousel-indicators">
            <?php foreach ($slides as $i => $sl): ?>
                <button type="button" data-bs-target="#heroSlider" data-bs-slide-to="<?= $i ?>"<?= $i === 0 ? ' class="active" aria-current="true"' : '' ?> aria-label="Slide <?= $i + 1 ?>"></button>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
    <div class="carousel-inner h-100">
        <?php foreach ($slides as $i => $sl): $link = (string) $sl['button_link']; $external = preg_match('#^https?://#i', $link) === 1; ?>
            <div class="carousel-item h-100<?= $i === 0 ? ' active' : '' ?>">
                <?= media_picture(SliderService::media($sl), '100vw', (string) ($sl['title'] ?? ''), $i > 0, 'hero-slider-img', 'lg') ?>
                <?php if ($sl['title'] || $sl['subtitle'] || $sl['button_text']): ?>
                    <div class="hero-slider-caption">
                        <div class="container">
                            <?php if ($sl['title']): ?><p class="hero-slider-title"><?= e($sl['title']) ?></p><?php endif; ?>
                            <?php if ($sl['subtitle']): ?><p class="hero-slider-sub"><?= e($sl['subtitle']) ?></p><?php endif; ?>
                            <?php if ($sl['button_text'] && $link !== ''): ?>
                                <a class="btn btn-light btn-lg" href="<?= e($external ? $link : url($link)) ?>"<?= $external ? ' rel="noopener" target="_blank"' : '' ?>><?= e($sl['button_text']) ?></a>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
    <?php if ($many): ?>
        <button class="carousel-control-prev" type="button" data-bs-target="#heroSlider" data-bs-slide="prev"><span class="carousel-control-prev-icon" aria-hidden="true"></span><span class="visually-hidden">Previous</span></button>
        <button class="carousel-control-next" type="button" data-bs-target="#heroSlider" data-bs-slide="next"><span class="carousel-control-next-icon" aria-hidden="true"></span><span class="visually-hidden">Next</span></button>
    <?php endif; ?>
</section>
<?php endif; ?>
<section class="home-hero<?= $slides ? ' has-slider' : '' ?>">
    <div class="container py-4<?= $slides ? '' : ' py-md-5' ?>">
        <h1 class="<?= $slides ? 'h4' : 'display-6' ?> fw-semibold mb-2">Find your next home, PG or business space</h1>
        <p class="<?= $slides ? '' : 'lead ' ?>text-muted mb-<?= $slides ? '3' : '4' ?>">Rent, PG and lease properties, listed directly by owners.</p>

        <form class="search-card card border-0 shadow" method="get" action="<?= e(url('/search')) ?>" data-home-search>
            <div class="card-body">
                <div class="purpose-switch mb-3" role="radiogroup" aria-label="Purpose">
                    <?php foreach (['rent' => 'Rent', 'pg' => 'PG', 'lease' => 'Lease'] as $k => $l): ?>
                        <input class="btn-check" type="radio" name="purpose" id="hp-<?= $k ?>" value="<?= $k ?>"<?= $k === 'rent' ? ' checked' : '' ?>>
                        <label class="btn btn-outline-primary" for="hp-<?= $k ?>"><?= $l ?></label>
                    <?php endforeach; ?>
                </div>
                <div class="row g-2 align-items-end">
                    <div class="col-12 col-md-6 col-lg">
                        <label class="form-label small fw-semibold" for="hs-city">City</label>
                        <select class="form-select" id="hs-city" name="city" data-city-select>
                            <option value="">All cities</option>
                            <?php foreach ($cities as $c): ?><option value="<?= (int) $c['id'] ?>"><?= e($c['name']) ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12 col-md-6 col-lg">
                        <label class="form-label small fw-semibold" for="hs-locality">Locality</label>
                        <select class="form-select" id="hs-locality" name="locality" data-locality-select>
                            <option value="">All localities</option>
                            <?php foreach ($cities as $c): ?>
                                <optgroup label="<?= e($c['name']) ?>" data-city="<?= (int) $c['id'] ?>">
                                    <?php foreach ($localities as $l): if ((int) $l['city_id'] !== (int) $c['id']) continue; ?>
                                        <option value="<?= (int) $l['id'] ?>"><?= e($l['name']) ?></option>
                                    <?php endforeach; ?>
                                </optgroup>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-6 col-md-4 col-lg">
                        <label class="form-label small fw-semibold" for="hs-category">Property type</label>
                        <select class="form-select" id="hs-category" name="category">
                            <option value="">Any</option>
                            <?php foreach ($categories as $c): ?><option value="<?= (int) $c['id'] ?>" data-pg="<?= $c['code'] === 'residential' ? '1' : '0' ?>"><?= e($c['name']) ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-6 col-md-4 col-lg">
                        <label class="form-label small fw-semibold" for="hs-budget">Budget</label>
                        <select class="form-select" id="hs-budget" name="budget">
                            <option value="">Any budget</option>
                            <?php foreach ($budgets as $i => $b): ?><option value="<?= $i ?>"><?= e($b['label']) ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12 col-md-4 col-lg-auto">
                        <button class="btn btn-primary w-100 px-4" type="submit">SEARCH PROPERTY</button>
                    </div>
                </div>
            </div>
        </form>
    </div>
</section>

<div class="container py-4">
    <div class="row g-3 mb-4">
        <?php foreach (['rent' => ['Rent', 'Flats, houses, shops and offices'], 'pg' => ['PG', 'Rooms for students and professionals'], 'lease' => ['Lease', 'Commercial and industrial spaces']] as $k => [$l, $sub]): ?>
            <div class="col-md-4">
                <a class="purpose-tile card border-0 shadow-sm h-100 text-reset text-decoration-none" href="<?= e(url('/' . $k)) ?>">
                    <div class="card-body">
                        <div class="fw-semibold fs-5"><?= $l ?></div>
                        <div class="small text-muted"><?= e($sub) ?></div>
                        <div class="small mt-2 text-primary"><?= e(plural((int) $counts[$k], 'property', 'properties')) ?> →</div>
                    </div>
                </a>
            </div>
        <?php endforeach; ?>
    </div>

    <?php if ($listings): ?>
        <h2 class="h5 mb-3">Featured and latest properties</h2>
        <div class="row row-cols-1 row-cols-sm-2 row-cols-lg-4 g-3">
            <?php foreach ($listings as $i => $r): ?>
                <div class="col"><?php $on = in_array((int) $r['id'], $shortlisted, true); $return = '/'; $eager = $i < 2; require APP_ROOT . '/views/partials/property-card.php'; ?></div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if (!App\Core\Auth::check()): ?>
        <div class="owner-cta card border-0 mt-5">
            <div class="card-body d-flex flex-wrap justify-content-between align-items-center gap-3">
                <div>
                    <div class="fw-semibold">Own a property?</div>
                    <div class="small text-muted">List it free and get enquiries directly from tenants.</div>
                </div>
                <a class="btn btn-outline-primary" href="<?= e(url('/register')) ?>">Create a free account</a>
            </div>
        </div>
    <?php endif; ?>
</div>
