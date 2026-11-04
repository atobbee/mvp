<?php
$title = 'My deliveries';
$active = array_filter($bookings, fn($b) => in_array($b['status'], ['awaiting_payment', 'paid', 'picked_up', 'at_point', 'delivered', 'disputed'], true));
$done = array_filter($bookings, fn($b) => in_array($b['status'], ['completed', 'refunded'], true));
$hour = (int)date('G');
?>
<div class="page-head row spread">
  <h1>Good <?= $hour < 12 ? 'morning' : ($hour < 18 ? 'afternoon' : 'evening') ?>, <?= e($user['first_name']) ?></h1>
  <a class="btn honey" href="/send">+ Send a package</a>
</div>
<div class="grid g3">
  <div class="stat"><b><?= count($active) ?></b><span>Active</span></div>
  <div class="stat"><b><?= count(array_filter($done, fn($b) => $b['status'] === 'completed')) ?></b><span>Completed</span></div>
  <div class="stat"><b><?= count($open) ?></b><span>Waiting for a driver</span></div>
</div>

<?php if ($open): ?>
<div class="card" style="margin-top:16px"><h2>Waiting for a driver</h2>
  <?php foreach ($open as $p): ?>
    <div class="row spread" style="padding:8px 0;border-bottom:1px solid var(--line)">
      <div><b><?= e($p['pickup_city']) ?> → <?= e($p['delivery_city']) ?></b> <span class="small muted"><?= e(date('j M', strtotime($p['pickup_date']))) ?> · <?= e($p['description']) ?></span>
        <?php if ($p['offers']): ?><span class="badge warn"><?= (int)$p['offers'] ?> offer<?= $p['offers'] > 1 ? 's' : '' ?></span><?php endif ?></div>
      <a class="btn ghost sm" href="/packages/<?= $p['id'] ?>">See drivers</a>
    </div>
  <?php endforeach ?>
</div>
<?php endif ?>

<div class="card"><h2>Active deliveries</h2>
  <?php if (!$active): ?><p class="muted">Nothing on the road right now.</p><?php endif ?>
  <?php foreach ($active as $b): ?>
    <div class="row spread" style="padding:8px 0;border-bottom:1px solid var(--line)">
      <div><b><?= e($b['ref']) ?></b> <?= status_badge($b['status']) ?><div class="small muted"><?= e($b['pickup_city']) ?> → <?= e($b['delivery_city']) ?> · Driver: <?= e($b['driver_first'] . ' ' . $b['driver_last']) ?></div></div>
      <a class="btn ghost sm" href="/bookings/<?= $b['id'] ?>">Track</a>
    </div>
  <?php endforeach ?>
</div>

<div class="card"><h2>Completed</h2>
  <?php if (!$done): ?><p class="muted">Your finished deliveries will show here.</p><?php else: ?>
  <div class="table-wrap"><table>
    <tr><th>Booking</th><th>Route</th><th>Date</th><th>Status</th><th>Amount</th><th>Review</th></tr>
    <?php foreach ($done as $b): ?>
      <tr><td><a href="/bookings/<?= $b['id'] ?>"><?= e($b['ref']) ?></a></td><td><?= e($b['pickup_city']) ?> → <?= e($b['delivery_city']) ?></td>
        <td><?= local_time($b['delivered_at'], 'j M') ?></td><td><?= status_badge($b['status']) ?></td><td><?= money((int)$b['price_cents']) ?></td>
        <td><?= $b['reviewed'] ? 'Reviewed ✓' : ($b['status'] === 'completed' ? '<a href="/bookings/' . $b['id'] . '/review">Review</a>' : '—') ?></td></tr>
    <?php endforeach ?>
  </table></div>
  <p class="small muted">Final invoicing and VAT treatment depend on the status of the sender and transport provider.</p>
  <?php endif ?>
</div>
