<?php
/**
 * "Post your requirement": the Inquiry Form sheet (spec 26) for visitors who
 * haven't found a listing. Goes to the admin team. Guests may use it.
 * @var ?array $user @var array $categories @var array $cities @var array $localities
 */
use App\Services\EnquiryService;
$this->layout('layouts/main');
$mobile = $user && strlen((string) $user['mobile']) === 12 ? substr((string) $user['mobile'], 2) : '';
$sel = static fn (string $name, string $value): string => old($name) === $value ? ' selected' : '';
$byCity = [];
foreach ($localities as $l) {
    $byCity[(int) $l['city_id']][] = $l;
}
?>
<div class="container py-4 form-narrow">
    <h1 class="h4 mb-1">Post your requirement</h1>
    <p class="text-muted mb-3">Can't find the right place? Tell us what you need and our team will contact you.</p>
    <div class="card border-0 shadow-sm">
        <div class="card-body p-4">
            <form method="post" action="<?= e(url('/post-requirement')) ?>" novalidate>
                <?= csrf_field() ?>
                <div class="visually-hidden" aria-hidden="true"><label for="f_website">Website</label><input id="f_website" name="website" tabindex="-1" autocomplete="off"></div>
                <div class="row g-2">
                    <div class="col-sm-6"><?= field('first_name', 'First name', 'text', 'maxlength="80" required autocomplete="given-name"', '', old('first_name', (string) ($user['first_name'] ?? ''))) ?></div>
                    <div class="col-sm-6"><?= field('last_name', 'Last name', 'text', 'maxlength="80" required autocomplete="family-name"', '', old('last_name', (string) ($user['last_name'] ?? ''))) ?></div>
                    <div class="col-sm-6"><?= field('mobile_1', 'Mobile number', 'tel', 'inputmode="numeric" maxlength="14" required autocomplete="tel-national"', '', old('mobile_1', $mobile)) ?></div>
                    <div class="col-sm-6"><?= field('mobile_2', 'Alternate mobile (optional)', 'tel', 'inputmode="numeric" maxlength="14"') ?></div>
                </div>
                <?= field('email', 'Email (optional)', 'email', 'maxlength="191" autocomplete="email"', '', old('email', (string) ($user['email'] ?? ''))) ?>

                <div class="row g-2">
                    <div class="col-sm-6 mb-3">
                        <span class="form-label d-block" id="lbl-interest">Interested in</span>
                        <div class="btn-group w-100" role="group" aria-labelledby="lbl-interest">
                            <?php foreach (EnquiryService::INTERESTS as $k => $l): ?>
                                <input class="btn-check" type="radio" name="interested_in" id="ii-<?= e($k) ?>" value="<?= e($k) ?>"<?= old('interested_in', 'rent') === $k ? ' checked' : '' ?>>
                                <label class="btn btn-outline-primary" for="ii-<?= e($k) ?>"><?= e($l) ?></label>
                            <?php endforeach; ?>
                        </div>
                        <?php if ($err = field_error('interested_in')): ?><div class="small text-danger mt-1"><?= e($err) ?></div><?php endif; ?>
                    </div>
                    <div class="col-sm-6 mb-3">
                        <label class="form-label" for="f_category_id">Property type</label>
                        <select class="form-select<?= invalid('category_id') ?>" id="f_category_id" name="category_id" required>
                            <option value="">Choose</option>
                            <?php foreach ($categories as $c): ?><option value="<?= (int) $c['id'] ?>"<?= $sel('category_id', (string) $c['id']) ?>><?= e($c['name']) ?></option><?php endforeach; ?>
                        </select>
                        <?php if ($err = field_error('category_id')): ?><div class="invalid-feedback"><?= e($err) ?></div><?php endif; ?>
                    </div>
                </div>
                <?= field('budget', 'Budget per month in ₹ (optional)', 'text', 'inputmode="numeric" maxlength="12" placeholder="For example 20000"') ?>
                <p class="form-label mb-2">Looking for a property in</p>
                <div class="row g-2">
                    <div class="col-sm-6 mb-3">
                        <label class="form-label small" for="f_city_id">City</label>
                        <select class="form-select<?= invalid('city_id') ?>" id="f_city_id" name="city_id" data-city-select>
                            <option value="">Any city</option>
                            <?php foreach ($cities as $c): ?><option value="<?= (int) $c['id'] ?>"<?= $sel('city_id', (string) $c['id']) ?>><?= e($c['name']) ?></option><?php endforeach; ?>
                        </select>
                        <?php if ($err = field_error('city_id')): ?><div class="invalid-feedback"><?= e($err) ?></div><?php endif; ?>
                    </div>
                    <div class="col-sm-6 mb-3">
                        <label class="form-label small" for="f_locality_id">Location</label>
                        <select class="form-select<?= invalid('locality_id') ?>" id="f_locality_id" name="locality_id" data-locality-select>
                            <option value="">Any location</option>
                            <?php foreach ($cities as $c): if (empty($byCity[(int) $c['id']])) { continue; } ?>
                                <optgroup label="<?= e($c['name']) ?>" data-city="<?= (int) $c['id'] ?>">
                                    <?php foreach ($byCity[(int) $c['id']] as $l): ?><option value="<?= (int) $l['id'] ?>"<?= $sel('locality_id', (string) $l['id']) ?>><?= e($l['name']) ?></option><?php endforeach; ?>
                                </optgroup>
                            <?php endforeach; ?>
                        </select>
                        <?php if ($err = field_error('locality_id')): ?><div class="invalid-feedback"><?= e($err) ?></div><?php endif; ?>
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label" for="f_message">Remarks (optional)</label>
                    <textarea class="form-control<?= invalid('message') ?>" id="f_message" name="message" rows="3" maxlength="1000" placeholder="For example: 2 BHK near a school, from December"><?= e(old('message')) ?></textarea>
                    <?php if ($err = field_error('message')): ?><div class="invalid-feedback"><?= e($err) ?></div><?php endif; ?>
                </div>
                <button class="btn btn-primary" type="submit">Send requirement</button>
            </form>
        </div>
    </div>
</div>
