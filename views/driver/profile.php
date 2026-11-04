<?php
$title = 'Profile';
$vs = $user['verification_status'];
$langs = array_filter(explode(',', (string)$user['languages']));
?>
<div class="page-head row spread"><h1>Your profile</h1>
  <?php if ($vs === 'verified'): ?><a href="/drivers/<?= $user['id'] ?>" class="btn ghost sm">See public profile</a><?php endif ?></div>

<form class="card" method="post" action="/driver/profile"><?= csrf_field() ?>
  <h2>About you</h2>
  <div class="field"><label>Short bio (shown to senders)</label><textarea name="bio" maxlength="500" placeholder="Quick handover or a chat? Anything senders should know?"><?= e($user['bio']) ?></textarea></div>
  <label>Languages</label>
  <div class="pills" style="margin-bottom:14px"><?php foreach (['NL', 'EN', 'DE', 'FR', 'PL', 'TR', 'AR', 'ES'] as $l): ?>
    <label><input type="checkbox" name="languages[]" value="<?= $l ?>" <?= in_array($l, $langs, true) ? 'checked' : '' ?>><span><?= $l ?></span></label><?php endforeach ?></div>
  <h2>Vehicle</h2>
  <div class="grid g3">
    <div class="field"><label>Make &amp; model</label><input type="text" name="make_model" value="<?= e($vehicle['make_model'] ?? '') ?>" required></div>
    <div class="field"><label>Year</label><input type="number" name="year" min="1980" max="<?= date('Y') + 1 ?>" value="<?= e($vehicle['year'] ?? '') ?>"></div>
    <div class="field"><label>Licence plate (we only show part of it)</label><input type="text" name="plate" placeholder="12-ABC-3" value="<?= e($vehicle['plate_masked'] ?? '') ?>"></div>
    <div class="field"><label>Type</label><select name="kind"><?php foreach (['car' => 'Passenger car', 'van' => 'Van', 'cargo_bike' => 'Cargo bike', 'bike' => 'Bike'] as $k => $l): ?><option value="<?= $k ?>" <?= ($vehicle['kind'] ?? 'car') === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach ?></select></div>
    <div class="field"><label>Fuel</label><select name="fuel"><?php foreach (['petrol', 'diesel', 'electric', 'hybrid', 'none'] as $f): ?><option value="<?= $f ?>" <?= ($vehicle['fuel'] ?? 'petrol') === $f ? 'selected' : '' ?>><?= ucfirst($f) ?></option><?php endforeach ?></select></div>
    <div class="field"><label>Biggest package</label><select name="max_size"><?php foreach (['envelope' => 'Envelope', 'small' => 'Small', 'medium' => 'Medium', 'large' => 'Large'] as $k => $l): ?><option value="<?= $k ?>" <?= ($vehicle['max_size'] ?? 'medium') === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach ?></select></div>
    <div class="field"><label>Max weight (kg)</label><input type="number" name="max_weight_kg" step="0.5" min="1" value="<?= e($vehicle['max_weight_kg'] ?? 20) ?>"></div>
  </div>
  <button class="btn">Save profile</button>
</form>

<div class="card"><h2>Verification</h2>
  <?php if ($vs === 'verified'): ?><p><span class="badge ok">✓ Identity verified</span><?= $user['is_professional'] ? ' <span class="badge honey">Professional transporter</span>' : '' ?></p>
  <?php elseif ($vs === 'pending'): ?><p><span class="badge warn">Under review</span> We usually verify within one working day.</p>
  <?php else: ?>
    <?php if ($vs === 'rejected'): ?><p class="notice">Your previous submission was not approved<?= $user['verification_note'] ? ': ' . e($user['verification_note']) : '' ?>.</p><?php endif ?>
    <form method="post" action="/driver/verify"><?= csrf_field() ?>
      <p class="muted">We check a government ID and your driving licence before you can carry packages. <i>(MVP: an admin checks documents manually. A regulated ID provider replaces this before launch.)</i></p>
      <label class="check"><input type="checkbox" name="is_professional" value="1" <?= $user['is_professional'] ? 'checked' : '' ?>> I'm a professional transporter (registered at KvK)</label>
      <div class="field" style="max-width:240px"><label>KvK number (professionals only)</label><input type="text" name="kvk_number" inputmode="numeric" maxlength="8" value="<?= e($user['kvk_number']) ?>"></div>
      <button class="btn honey" <?= $vehicle ? '' : 'disabled' ?>>Submit for verification</button>
      <?php if (!$vehicle): ?><span class="small muted">Save your vehicle first.</span><?php endif ?>
    </form>
  <?php endif ?>
</div>

<div class="card"><h2>Payouts</h2>
  <?php if ($user['stripe_account_id']): ?><p><span class="badge ok">Payout account connected</span></p>
    <?php if ($gateway === 'stripe'): ?><form method="post" action="/driver/payouts"><?= csrf_field() ?><button class="btn ghost sm">Update payout details</button></form><?php endif ?>
  <?php else: ?>
    <p class="muted">Payouts go through Stripe Connect. You'll add your bank details on Stripe's secure page.</p>
    <form method="post" action="/driver/payouts"><?= csrf_field() ?><button class="btn">Set up payouts</button></form>
  <?php endif ?>
</div>
