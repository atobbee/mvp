<?php
use AtoBbee\Domain\BookingService;
$title = 'Available drivers';
$p = $package;
$reqLabels = array_map(fn($k) => BookingService::REQUIREMENTS[$k] ?? $k, $p['requirements']);
$driverCard = function (array $r, array $stats) { ?>
  <div class="row">
    <div class="avatar"><?= initials($r['first_name'], $r['last_name']) ?></div>
    <div>
      <b><a href="/drivers/<?= (int)$r['driver_id'] ?>"><?= e($r['first_name'] . ' ' . $r['last_name']) ?></a></b>
      <span class="badge ok">✓ Verified</span>
      <?php if ($r['is_professional']): ?><span class="badge honey">Pro transporter</span><?php endif ?>
      <?php if ($r['fuel'] === 'electric'): ?><span class="badge ok">⚡ Electric</span><?php endif ?>
      <div class="small muted"><?= e($r['make_model'] ?? '') ?> ·
        <?= $stats['rating'] !== null ? stars($stats['rating']) . ' (' . $stats['deliveries'] . ' deliveries)' : 'New driver' ?>
        <?= $r['languages'] ? ' · ' . e(str_replace(',', ' + ', $r['languages'])) : '' ?></div>
    </div>
  </div>
<?php }; ?>
<div class="page-head row spread">
  <div>
    <h1><?= e($p['pickup_city']) ?> → <?= e($p['pickup_point_name'] ? $p['pickup_point_name'] . ' (' . $p['delivery_city'] . ')' : $p['delivery_city']) ?></h1>
    <p class="muted"><?= e(date('D j M', strtotime($p['pickup_date']))) ?> · <?= e($p['window_start']) ?>–<?= e($p['window_end']) ?> · <?= e(size_label($p['size'])) ?> (<?= e($p['weight_kg']) ?> kg) · <?= e(implode(' · ', $reqLabels)) ?></p>
  </div>
  <?php if ($p['status'] === 'open'): ?>
    <form method="post" action="/packages/<?= $p['id'] ?>/cancel" onsubmit="return confirm('Withdraw this request?')"><?= csrf_field() ?><button class="btn ghost sm">Withdraw request</button></form>
  <?php endif ?>
</div>

<?php foreach ($pending as $b): ?>
  <div class="card"><div class="row spread"><div>You picked <b><?= e($b['driver_first']) ?></b> — payment not finished yet.</div><a class="btn honey" href="/bookings/<?= $b['id'] ?>">Continue to payment</a></div></div>
<?php endforeach ?>

<?php if ($offers): ?>
  <h2 style="margin-top:24px">Offers from drivers</h2>
  <?php foreach ($offers as $o): $r = $o['route']; ?>
    <div class="card match">
      <div><?php $driverCard($r, $o['stats']) ?><p class="small muted" style="margin:8px 0 0">Departs <?= e($r['window_start']) ?>–<?= e($r['window_end']) ?> · <?= (int)$o['compatibility'] ?>% route match</p></div>
      <div style="text-align:right"><div class="price"><?= money((int)$o['price_cents']) ?></div>
        <form method="post" action="/bookings/<?= $o['id'] ?>/accept"><?= csrf_field() ?><button class="btn honey">Accept offer</button></form></div>
    </div>
  <?php endforeach ?>
<?php endif ?>

<h2 style="margin-top:24px">Drivers on your route</h2>
<?php if (!$matches): ?>
  <div class="card">
    <p><b>No driver is travelling this way at that time yet.</b></p>
    <p class="muted">Your request stays open: drivers who publish a matching route will see it and can send you an offer. You'll find offers on your dashboard.</p>
    <a class="btn ghost" href="/dashboard">Go to my deliveries</a>
  </div>
<?php endif ?>
<?php foreach ($matches as $m): ?>
  <div class="card match">
    <div>
      <?php $driverCard($m, $m['stats']) ?>
      <div class="grid g3 small" style="margin-top:12px">
        <div><span class="muted">Pickup window</span><br><b><?= e($m['window_start']) ?>–<?= e($m['window_end']) ?></b></div>
        <div><span class="muted">Detour</span><br><b><?= e($m['match']['detour_km']) ?> km</b></div>
        <div><span class="muted">Route match</span><br><b><?= (int)$m['match']['score'] ?>%</b></div>
      </div>
    </div>
    <div style="text-align:right">
      <div class="price"><?= money($m['split']['price']) ?></div>
      <div class="small muted">Driver gets <?= money($m['split']['driver']) ?></div>
      <form method="post" action="/packages/<?= $p['id'] ?>/book" style="margin-top:8px"><?= csrf_field() ?>
        <input type="hidden" name="route_id" value="<?= (int)$m['id'] ?>"><button class="btn honey">Choose <?= e($m['first_name']) ?></button></form>
    </div>
  </div>
<?php endforeach ?>
<p class="small muted" style="margin-top:16px">ℹ️ Route match is calculated from distance and detour, time window overlap, capacity and package requirements. It is rule-based, not AI.</p>
