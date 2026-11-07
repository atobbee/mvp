<?php
use Atobbee\Domain\BookingService;
$title = 'My route';
$r = $route;
?>
<div class="page-head row spread">
  <div><h1><?= e($r['origin']) ?> → <?= e($r['destination']) ?></h1>
    <p class="muted"><?= e(date('D j M', strtotime($r['travel_date']))) ?> · <?= e($r['window_start']) ?>–<?= e($r['window_end']) ?> · up to <?= e(size_label($r['max_size'])) ?>, <?= e($r['max_weight_kg']) ?> kg · <?= (int)$r['max_detour_km'] ?> km detour · <?= $r['capacity_left'] ?> of <?= (int)$r['capacity'] ?> slots free</p>
    <p>Price <?= money($split['price']) ?> · you receive <b><?= money($split['driver']) ?></b> per package</p></div>
  <?php if ($r['status'] === 'active'): ?>
    <form method="post" action="/driver/routes/<?= $r['id'] ?>/cancel" onsubmit="return confirm('Cancel this route?')"><?= csrf_field() ?><button class="btn ghost sm">Cancel route</button></form>
  <?php else: ?><span class="badge bad"><?= e(ucfirst($r['status'])) ?></span><?php endif ?>
</div>

<?php if ($bookings): ?>
<div class="card"><h2>Booked on this route</h2>
  <?php foreach ($bookings as $b): ?>
    <div class="row spread" style="padding:8px 0;border-bottom:1px solid var(--line)">
      <div><b><?= e($b['ref']) ?></b> <?= status_badge($b['status']) ?><div class="small muted"><?= e($b['pickup_city']) ?> → <?= e($b['delivery_city']) ?> · <?= e($b['description']) ?></div></div>
      <a class="btn ghost sm" href="/bookings/<?= $b['id'] ?>">Open</a></div>
  <?php endforeach ?>
</div>
<?php endif ?>

<div class="card"><h2>Compatible requests (<?= count($matches) ?>)</h2>
  <?php if (!$matches): ?><p class="muted">No open packages fit this route yet. Senders will also see your route when they post.</p><?php endif ?>
  <?php foreach ($matches as $m): ?>
    <div class="match" style="padding:12px 0;border-bottom:1px solid var(--line)">
      <div><b><?= e(size_label($m['size'])) ?> · <?= e($m['pickup_city']) ?> → <?= e($m['pickup_point_name'] ?: $m['delivery_city']) ?></b>
        <div class="small muted"><?= e($m['weight_kg']) ?> kg · <?= e(BookingService::CATEGORIES[$m['category']] ?? $m['category']) ?> · <?= e($m['description']) ?> · pickup <?= e($m['window_start']) ?>–<?= e($m['window_end']) ?></div>
        <div class="small"><?= e(implode(' · ', array_map(fn($k) => BookingService::REQUIREMENTS[$k] ?? $k, $m['requirements']))) ?></div></div>
      <div style="text-align:right"><div><b><?= (int)$m['match']['score'] ?>%</b> <span class="small muted">· <?= e($m['match']['detour_km']) ?> km detour</span></div>
        <?php if ($m['already_offered']): ?><span class="badge">Offer sent</span>
        <?php else: ?><form method="post" action="/driver/routes/<?= $r['id'] ?>/offer"><?= csrf_field() ?><input type="hidden" name="package_id" value="<?= $m['id'] ?>"><button class="btn honey sm">Offer for <?= money($split['price']) ?></button></form><?php endif ?></div>
    </div>
  <?php endforeach ?>
</div>
