<?php
/**
 * Public property detail (spec 22): gallery, facts, facilities, area map
 * link and the visitor actions. Contact details are never printed here.
 * @var array $p @var array $preview @var string $headline @var string $place @var list<int> $shortlisted @var bool $isOwner @var array $similar
 * @var ?array $openVisit @var ?string $lastContact
 */
use App\Core\Auth;
$this->layout('layouts/main');
$path = property_url($p);
$guest = !Auth::check();
$contactUrl = '/property/' . (int) $p['id'] . '/contact';
$visitUrl = '/property/' . (int) $p['id'] . '/visit';
?>
<div class="container py-4">
    <nav aria-label="breadcrumb" class="small mb-2">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= e(url('/')) ?>">Home</a></li>
            <li class="breadcrumb-item"><a href="<?= e(url('/' . $p['purpose'])) ?>"><?= e(purpose_label($p['purpose'])) ?></a></li>
            <?php if ($p['city_id']): ?><li class="breadcrumb-item"><a href="<?= e(url('/' . $p['purpose'] . '?city=' . (int) $p['city_id'])) ?>"><?= e($p['city_name']) ?></a></li><?php endif; ?>
            <li class="breadcrumb-item active text-truncate" aria-current="page">#<?= (int) $p['id'] ?></li>
        </ol>
    </nav>
    <p class="text-muted small mb-1"><?= e($headline) ?><?= $place !== '' ? ' in ' . e($place) : '' ?> · ID <?= (int) $p['id'] ?></p>
    <h1 class="h3 mb-3"><?= e($p['title'] !== '' ? $p['title'] : $headline) ?></h1>
    <div class="row g-4">
        <div class="col-lg-8">
            <?php $preview['hideTitle'] = true; require APP_ROOT . '/views/partials/property-detail.php'; ?>
        </div>
        <aside class="col-lg-4 order-first order-lg-last">
            <div class="card border-0 shadow-sm action-card">
                <div class="card-body d-grid gap-2">
                    <div>
                        <span class="fs-4 fw-bold"><?= e(money_inr($p['rent'] !== null ? (int) $p['rent'] : null)) ?></span> <span class="text-muted">/ month</span>
                        <div class="small text-muted d-flex flex-wrap gap-2 column-gap-3">
                            <?php if ($p['security_deposit'] !== null): ?><span>Deposit <?= e(money_inr((int) $p['security_deposit'])) ?></span><?php endif; ?>
                            <?php if ($p['maintenance'] !== null): ?><span>Maintenance <?= e(money_inr((int) $p['maintenance'])) ?> / month</span><?php endif; ?>
                            <?php if ($p['other_charges'] !== null): ?><span>Other: <?= e($p['other_charges']) ?></span><?php endif; ?>
                        </div>
                    </div>
                    <?php if ($isOwner): ?>
                        <div class="alert alert-info small mb-0">This is your listing. <a href="<?= e(url('/owner/properties/' . (int) $p['id'] . '/edit')) ?>">Edit it</a> from your owner dashboard.</div>
                    <?php else: ?>
                        <a class="btn btn-primary" href="<?= e(url($guest ? '/login?next=' . rawurlencode($contactUrl) : $contactUrl)) ?>"><span aria-hidden="true">📞</span> Contact Owner</a>
                        <?php if ($openVisit): ?>
                            <a class="btn btn-outline-primary" href="<?= e(url('/account/bookings?status=open')) ?>"><span aria-hidden="true">📅</span> Visit requested for <?= e(date('d M', strtotime($openVisit['preferred_date']))) ?></a>
                        <?php else: ?>
                            <a class="btn btn-outline-primary" href="<?= e(url($guest ? '/login?next=' . rawurlencode($visitUrl) : $visitUrl)) ?>"><span aria-hidden="true">📅</span> Request Visit</a>
                        <?php endif; ?>
                        <?php $sid = (int) $p['id']; $son = in_array((int) $p['id'], $shortlisted, true); $sreturn = $path; $sclass = 'btn btn-outline-primary w-100'; require APP_ROOT . '/views/partials/shortlist-button.php'; ?>
                        <?php if ($lastContact): ?><div class="small text-success">You contacted the owner on <?= e(format_ist($lastContact, 'd M Y')) ?>.</div><?php endif; ?>
                        <?php if ($guest): ?><div class="small text-muted">Log in or register to contact the owner or book a visit.</div><?php endif; ?>
                    <?php endif; ?>
                    <div class="small text-muted">Listed <?= e(format_ist($p['published_at'], 'd M Y')) ?> · Property ID <?= (int) $p['id'] ?></div>
                </div>
            </div>
        </aside>
    </div>

    <?php if ($similar): ?>
        <section class="mt-5" aria-labelledby="similar-h">
            <h2 class="h5 mb-3" id="similar-h">Similar properties</h2>
            <div class="row row-cols-2 row-cols-lg-4 g-2 g-md-3">
                <?php foreach ($similar as $r): ?>
                    <div class="col"><?php $on = in_array((int) $r['id'], $shortlisted, true); $return = $path; $eager = false; require APP_ROOT . '/views/partials/property-card.php'; ?></div>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>
</div>
