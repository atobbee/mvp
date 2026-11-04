<?php $title = 'Admin'; ?>
<div class="page-head"><h1>Platform overview</h1></div>
<div class="grid g4">
  <div class="stat"><b><?= $stats['users'] ?></b><span>Users</span></div>
  <div class="stat"><b><?= $stats['open'] ?></b><span>Open requests</span></div>
  <div class="stat"><b><?= $stats['transit'] ?></b><span>In transit</span></div>
  <div class="stat"><b><?= $stats['completed'] ?></b><span>Completed</span></div>
  <div class="stat"><b><?= $stats['problems'] ?></b><span>Open problems</span></div>
  <div class="stat"><b><?= money($stats['gmv']) ?></b><span>Gross bookings</span></div>
  <div class="stat"><b><?= money($stats['commission']) ?></b><span>Commission earned</span></div>
  <div class="stat"><b><?= e(config('payment_gateway')) ?></b><span>Payment gateway</span></div>
</div>

<div class="grid g2" style="margin-top:16px;align-items:start">
  <div class="card"><h2>Verification queue (<?= count($queue) ?>)</h2>
    <?php if (!$queue): ?><p class="muted">All caught up.</p><?php endif ?>
    <?php foreach ($queue as $u): ?>
      <div style="padding:10px 0;border-bottom:1px solid var(--line)">
        <b><?= e($u['point_name'] ?: $u['first_name'] . ' ' . $u['last_name']) ?></b> <span class="badge"><?= e($u['role']) ?></span>
        <div class="small muted"><?= e($u['email']) ?> · <?= e($u['verification_note']) ?> · joined <?= local_time($u['created_at'], 'j M') ?></div>
        <form method="post" action="/admin/users/<?= $u['id'] ?>/verify" class="row" style="margin-top:6px"><?= csrf_field() ?>
          <input type="text" name="note" placeholder="Note (optional)" style="flex:1">
          <button class="btn sm" name="decision" value="approve">Approve</button>
          <button class="btn ghost sm" name="decision" value="reject">Reject</button></form>
      </div>
    <?php endforeach ?>
  </div>

  <div class="card"><h2>Reported problems (<?= count($disputes) ?>)</h2>
    <?php if (!$disputes): ?><p class="muted">No open problems.</p><?php endif ?>
    <?php foreach ($disputes as $d): ?>
      <div style="padding:10px 0;border-bottom:1px solid var(--line)">
        <b><a href="/bookings/<?= $d['booking_id'] ?>"><?= e($d['ref']) ?></a> — <?= e($d['reason']) ?></b>
        <div class="small muted">Sender <?= e($d['sender_first'] . ' ' . $d['sender_last']) ?> · Driver <?= e($d['driver_first'] . ' ' . $d['driver_last']) ?> · <?= money((int)$d['price_cents']) ?> · <?= local_time($d['created_at']) ?></div>
        <p style="margin:6px 0"><?= e($d['details']) ?></p>
        <?php if ($d['proof_photo']): ?><p class="small"><a href="/bookings/<?= $d['booking_id'] ?>/proof/photo" target="_blank">📸 Delivery photo</a></p><?php endif ?>
        <form method="post" action="/admin/disputes/<?= $d['id'] ?>/resolve"><?= csrf_field() ?>
          <input type="text" name="note" placeholder="Decision note (shared with both)" required>
          <div class="row" style="margin-top:6px"><button class="btn sm" name="outcome" value="driver">Release payout to driver</button>
            <button class="btn danger sm" name="outcome" value="refund">Refund sender</button></div></form>
      </div>
    <?php endforeach ?>
  </div>
</div>

<?php if ($flagged): ?>
<div class="card"><h2>Reported reviews</h2>
  <?php foreach ($flagged as $rv): ?>
    <div class="row spread" style="padding:8px 0;border-bottom:1px solid var(--line)">
      <div><b><?= str_repeat('★', (int)$rv['overall']) ?></b> <?= e($rv['reviewer']) ?> → <?= e($rv['reviewee']) ?> (<?= e($rv['ref']) ?>)<div class="small"><?= e($rv['comment']) ?></div></div>
      <form method="post" action="/admin/reviews/<?= $rv['id'] ?>/moderate" class="row"><?= csrf_field() ?>
        <button class="btn danger sm" name="decision" value="hide">Hide</button><button class="btn ghost sm" name="decision" value="keep">Keep</button></form>
    </div>
  <?php endforeach ?>
</div>
<?php endif ?>

<div class="card"><h2>Recent activity</h2><div class="table-wrap"><table>
  <tr><th>Booking</th><th>Route</th><th>Driver</th><th>Status</th><th>Amount</th><th>Commission</th></tr>
  <?php foreach ($activity as $b): ?><tr><td><a href="/bookings/<?= $b['id'] ?>"><?= e($b['ref']) ?></a></td><td><?= e($b['pickup_city']) ?> → <?= e($b['delivery_city']) ?></td>
    <td><?= e($b['driver_first'] . ' ' . mb_substr($b['driver_last'], 0, 1)) ?>.</td><td><?= status_badge($b['status']) ?></td><td><?= money((int)$b['price_cents']) ?></td><td><?= money((int)$b['commission_cents']) ?></td></tr><?php endforeach ?>
</table></div></div>
