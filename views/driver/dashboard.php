<?php
$title = 'Driver dashboard';
$active = array_filter($bookings, fn($b) => in_array($b['status'], ['paid', 'picked_up', 'at_point', 'delivered', 'disputed', 'awaiting_payment', 'offered'], true));
$history = array_filter($bookings, fn($b) => in_array($b['status'], ['completed', 'refunded'], true));
$vs = $user['verification_status'];
?>
<div class="page-head row spread">
  <h1>Welcome, <?= e($user['first_name']) ?></h1>
  <?php if ($vs === 'verified'): ?><a class="btn honey" href="/driver/routes/new">+ Publish a route</a><?php endif ?>
</div>

<?php if ($vs !== 'verified'): ?>
  <div class="notice" style="margin-bottom:16px">
    <?= match ($vs) {
        'pending' => '⏳ Your verification is being reviewed. You can publish routes once it is approved.',
        'rejected' => '⚠️ Your verification was not approved. Check your profile and submit again.',
        default => '👋 Add your vehicle and submit your identity verification to start earning.',
    } ?> <a href="/driver/profile">Go to profile →</a>
  </div>
<?php endif ?>

<div class="grid g4">
  <div class="stat"><b><?= count(array_filter($active, fn($b) => in_array($b['status'], ['paid', 'picked_up'], true))) ?></b><span>To deliver</span></div>
  <div class="stat"><b><?= $stats['deliveries'] ?></b><span>Completed</span></div>
  <div class="stat"><b><?= $stats['reliability'] !== null ? $stats['reliability'] . '%' : '—' ?></b><span>Reliability</span></div>
  <div class="stat"><b><?= $stats['rating'] !== null ? stars($stats['rating']) : '—' ?></b><span>Rating</span></div>
</div>

<div class="grid g2" style="margin-top:16px;align-items:start">
  <div>
    <div class="card"><h2>Active deliveries</h2>
      <?php if (!$active): ?><p class="muted">No active deliveries.</p><?php endif ?>
      <?php foreach ($active as $b): ?>
        <div style="padding:10px 0;border-bottom:1px solid var(--line)">
          <div class="row spread"><b><?= e($b['ref']) ?></b><?= status_badge($b['status']) ?></div>
          <div class="small muted"><?= e($b['pickup_city']) ?> → <?= e($b['delivery_city']) ?> · <?= e($b['description']) ?> · <?= e($b['weight_kg']) ?> kg · <?= e(date('j M', strtotime($b['pickup_date']))) ?> <?= e($b['window_start']) ?>–<?= e($b['window_end']) ?></div>
          <div class="row spread" style="margin-top:6px"><span>You earn <b><?= money((int)$b['driver_cents']) ?></b></span><a class="btn ghost sm" href="/bookings/<?= $b['id'] ?>">Open</a></div>
        </div>
      <?php endforeach ?>
    </div>
    <div class="card"><h2>My routes</h2>
      <?php if (!$routes): ?><p class="muted">No upcoming routes. Publish the trips you're already making.</p><?php endif ?>
      <?php foreach ($routes as $r): ?>
        <div class="row spread" style="padding:8px 0;border-bottom:1px solid var(--line)">
          <div><b><?= e($r['origin']) ?> → <?= e($r['destination']) ?></b><div class="small muted"><?= e(date('D j M', strtotime($r['travel_date']))) ?> · <?= e($r['window_start']) ?> · <?= money((int)$r['price_cents']) ?> · <?= $r['capacity_left'] ?> slots left</div></div>
          <a class="btn <?= $r['match_count'] ? 'honey' : 'ghost' ?> sm" href="/driver/routes/<?= $r['id'] ?>"><?= $r['match_count'] ?> matching</a>
        </div>
      <?php endforeach ?>
    </div>
  </div>
  <div>
    <div class="card"><h2>Earnings</h2>
      <dl class="kv"><dt>Pending payout</dt><dd><?= money($earnings['pending']) ?></dd>
        <dt>This month</dt><dd><?= money($earnings['month']) ?></dd>
        <dt>All time</dt><dd><?= money($earnings['paid']) ?></dd></dl>
      <p class="small muted">Paid out after delivery plus the <?= (int)config('dispute_window_hours') ?>-hour dispute window<?= $user['stripe_account_id'] ? '' : '. <a href="/driver/profile">Set up payouts →</a>' ?></p>
    </div>
    <?php if ($vehicle): ?><div class="card"><h2>Vehicle</h2>
      <dl class="kv"><dt>Make/model</dt><dd><?= e($vehicle['make_model']) ?></dd><dt>Fuel</dt><dd><?= e(ucfirst($vehicle['fuel'])) ?></dd>
        <dt>Max package</dt><dd><?= e(size_label($vehicle['max_size'])) ?>, <?= e($vehicle['max_weight_kg']) ?> kg</dd><dt>Plate</dt><dd><?= e($vehicle['plate_masked'] ?? '—') ?></dd></dl></div><?php endif ?>
    <?php if ($history): ?><div class="card"><h2>History</h2><div class="table-wrap"><table>
      <tr><th>Booking</th><th>Route</th><th>Earned</th><th></th></tr>
      <?php foreach ($history as $b): ?><tr><td><a href="/bookings/<?= $b['id'] ?>"><?= e($b['ref']) ?></a></td><td><?= e($b['pickup_city']) ?> → <?= e($b['delivery_city']) ?></td>
        <td><?= $b['status'] === 'completed' ? money((int)$b['driver_cents']) : '—' ?></td>
        <td><?= !$b['reviewed'] && $b['status'] === 'completed' ? '<a href="/bookings/' . $b['id'] . '/review">Review sender</a>' : status_badge($b['status']) ?></td></tr><?php endforeach ?>
    </table></div></div><?php endif ?>
  </div>
</div>
