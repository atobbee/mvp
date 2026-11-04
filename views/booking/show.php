<?php
use AtoBbee\Domain\{BookingService, BookingStatus};
$title = $b['ref'];
$p = $b['package']; $r = $b['route']; $s = $b['status'];
$req = $p['requirements'];
$needs = fn($k) => in_array($k, $req, true);
$isAdmin = $user['role'] === 'admin';
?>
<div class="page-head row spread">
  <div>
    <p class="small muted" style="margin:0"><?= e($b['ref']) ?></p>
    <h1><?= e($p['pickup_city']) ?> → <?= e($p['delivery_city']) ?></h1>
    <p class="muted"><?= e($p['description']) ?> · <?= e(size_label($p['size'])) ?> · <?= e($p['weight_kg']) ?> kg · <?= e(date('D j M', strtotime($p['pickup_date']))) ?></p>
  </div>
  <div><?= status_badge($s) ?></div>
</div>

<div class="grid g2" style="align-items:start">
<div>

<?php /* ── Sender actions ── */ if ($isSender): ?>
  <?php if ($s === BookingStatus::OFFERED): ?>
    <div class="card"><h2>Offer from <?= e($b['driver_first']) ?></h2>
      <form method="post" action="/bookings/<?= $b['id'] ?>/accept"><?= csrf_field() ?><button class="btn honey">Accept and continue to payment</button></form></div>
  <?php elseif ($s === BookingStatus::AWAITING_PAYMENT): ?>
    <form class="card" method="post" action="/bookings/<?= $b['id'] ?>/pay"><?= csrf_field() ?>
      <h2>Confirm &amp; pay</h2>
      <dl class="kv">
        <dt>Delivery</dt><dd><?= money((int)$b['price_cents']) ?></dd>
        <dt>Platform fee (8%)</dt><dd>included · <?= money((int)$b['commission_cents']) ?></dd>
        <dt>Driver receives</dt><dd><?= money((int)$b['driver_cents']) ?> after delivery</dd>
        <dt><b>Total</b></dt><dd><b><?= money((int)$b['price_cents']) ?></b></dd>
      </dl>
      <p class="small muted">Your payment is held until the delivery is confirmed and the <?= (int)config('dispute_window_hours') ?>-hour dispute window has passed. Cancel before pickup for a full refund.</p>
      <label class="check"><input type="checkbox" name="terms" value="1" required> I agree to the Terms of Service and confirm the package complies with the prohibited items policy.</label>
      <div class="row"><button class="btn honey">Pay <?= money((int)$b['price_cents']) ?></button>
        <?php if (config('payment_gateway') === 'fake'): ?><span class="badge warn">Demo payment · no real charge</span><?php endif ?></div>
    </form>
  <?php endif ?>

  <?php if (in_array($s, [BookingStatus::PAID, BookingStatus::PICKED_UP, BookingStatus::AT_POINT], true)): ?>
    <div class="card">
      <?php if ($s === BookingStatus::PAID): ?>
        <h3>Pickup PIN</h3><div class="pin"><?= e($b['pickup_pin']) ?></div>
        <p class="small muted">Give this to <?= e($b['driver_first']) ?> at pickup only, once you hand over the package.</p>
      <?php endif ?>
      <h3 style="margin-top:12px">Recipient PIN</h3><div class="pin"><?= e($b['delivery_pin']) ?></div>
      <p class="small muted">Share this with <?= e($p['recipient_name']) ?>. They show it to <?= $point ? 'the pickup point' : 'the driver' ?> to receive the package.</p>
    </div>
  <?php endif ?>

  <?php if (in_array($s, [BookingStatus::PICKED_UP, BookingStatus::AT_POINT], true) || ($s === BookingStatus::DELIVERED && $b['payout_due_at'] > now_utc())): ?>
    <details class="card"><summary><b>Problem? Report an issue</b></summary>
      <form method="post" action="/bookings/<?= $b['id'] ?>/dispute" style="margin-top:12px"><?= csrf_field() ?>
        <div class="field"><label>What happened?</label><select name="reason">
          <option value="not_delivered">Not delivered</option><option value="damaged">Package damaged</option>
          <option value="late">Very late</option><option value="wrong_recipient">Wrong recipient</option><option value="other">Other</option></select></div>
        <div class="field"><label>Details</label><textarea name="details" required minlength="10"></textarea></div>
        <button class="btn danger">Report problem &amp; hold payout</button>
        <?php if ($s === BookingStatus::DELIVERED): ?><p class="small muted">You can report until <?= local_time($b['payout_due_at']) ?>.</p><?php endif ?>
      </form>
    </details>
  <?php endif ?>
<?php endif ?>

<?php /* ── Driver actions ── */ if ($isDriver): ?>
  <?php if ($s === BookingStatus::OFFERED): ?>
    <div class="card"><p>Waiting for the sender to accept your offer.</p></div>
  <?php elseif ($s === BookingStatus::AWAITING_PAYMENT): ?>
    <div class="card"><p>Waiting for the sender to pay. Don't pick up until it shows "Awaiting pickup".</p></div>
  <?php elseif ($s === BookingStatus::PAID): ?>
    <form class="card" method="post" action="/bookings/<?= $b['id'] ?>/pickup"><?= csrf_field() ?>
      <h2>Pickup</h2>
      <dl class="kv"><dt>Address</dt><dd><?= e($p['pickup_address']) ?>, <?= e($p['pickup_city']) ?></dd>
        <dt>Window</dt><dd><?= e($p['window_start']) ?>–<?= e($p['window_end']) ?></dd>
        <dt>Sender</dt><dd><?= e($b['sender_first'] . ' ' . mb_substr($b['sender_last'], 0, 1)) ?>.</dd>
        <?php if ($p['note_to_driver']): ?><dt>Note</dt><dd><?= e($p['note_to_driver']) ?></dd><?php endif ?></dl>
      <?php if ($needs('fragile') || $needs('upright')): ?><p class="notice"><?= $needs('fragile') ? '🥚 Fragile. ' : '' ?><?= $needs('upright') ? '⬆️ Keep upright.' : '' ?></p><?php endif ?>
      <div class="field"><label>Pickup PIN from the sender</label><input class="pin-input" name="pin" inputmode="numeric" maxlength="4" pattern="\d{4}" required autocomplete="off"></div>
      <button class="btn honey">Confirm pickup</button>
    </form>
  <?php elseif ($s === BookingStatus::PICKED_UP && $point): ?>
    <div class="card"><h2>Hand over at <?= e($point['name']) ?></h2>
      <p><?= e($point['address']) ?>, <?= e($point['city']) ?> · <?= e($point['opening_hours']) ?></p>
      <p class="muted small">Show this code. The pickup point scans it or types the reference.</p>
      <div id="qr" style="margin:12px 0"></div><div class="pin" style="font-size:20px;letter-spacing:2px"><?= e($b['ref']) ?></div>
    </div>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
    <script>new QRCode(document.getElementById('qr'), {text: <?= json_encode($b['ref']) ?>, width: 160, height: 160});</script>
  <?php elseif ($s === BookingStatus::PICKED_UP): ?>
    <form class="card" method="post" action="/bookings/<?= $b['id'] ?>/deliver" enctype="multipart/form-data" id="deliver-form"><?= csrf_field() ?>
      <h2>Delivery</h2>
      <dl class="kv"><dt>Address</dt><dd><?= e($p['delivery_address']) ?>, <?= e($p['delivery_city']) ?></dd><dt>Recipient</dt><dd><?= e($p['recipient_name']) ?></dd></dl>
      <?php foreach (BookingService::DELIVERY_CONFIRMATIONS as $k): if ($needs($k)): ?>
        <label class="check"><input type="checkbox" name="confirm[]" value="<?= $k ?>" required> <?= e(BookingService::REQUIREMENTS[$k]) ?> — done</label>
      <?php endif; endforeach ?>
      <?php if ($needs('photo')): ?>
        <div class="field"><label>Photo of the delivered package</label><input type="file" name="photo" accept="image/*" capture="environment" required></div>
      <?php endif ?>
      <?php if ($needs('signature')): ?>
        <div class="field"><label>Recipient signature</label><canvas class="sig-pad" id="sig"></canvas>
          <button type="button" class="btn ghost sm" id="sig-clear" style="margin-top:6px">Clear</button>
          <input type="hidden" name="signature" id="sig-data"></div>
      <?php endif ?>
      <div class="field"><label>Recipient PIN</label><input class="pin-input" name="pin" inputmode="numeric" maxlength="4" pattern="\d{4}" required autocomplete="off"></div>
      <button class="btn honey">Confirm delivery</button>
    </form>
    <?php if ($needs('signature')): ?><script>
      (() => { const c = document.getElementById('sig'), x = c.getContext('2d'); let drawing = false, used = false;
        const fit = () => { c.width = c.offsetWidth; c.height = c.offsetHeight; x.lineWidth = 2; x.lineCap = 'round'; }; fit();
        const pos = e => { const r = c.getBoundingClientRect(); return [e.clientX - r.left, e.clientY - r.top]; };
        c.addEventListener('pointerdown', e => { drawing = used = true; x.beginPath(); x.moveTo(...pos(e)); });
        c.addEventListener('pointermove', e => { if (drawing) { x.lineTo(...pos(e)); x.stroke(); } });
        addEventListener('pointerup', () => drawing = false);
        document.getElementById('sig-clear').onclick = () => { x.clearRect(0, 0, c.width, c.height); used = false; };
        document.getElementById('deliver-form').addEventListener('submit', e => {
          if (!used) { e.preventDefault(); alert('Ask the recipient to sign first.'); return; }
          document.getElementById('sig-data').value = c.toDataURL('image/png'); });
      })();
    </script><?php endif ?>
  <?php elseif ($s === BookingStatus::DELIVERED): ?>
    <div class="card"><h2>Delivered 🎉</h2><p>You'll receive <b><?= money((int)$b['driver_cents']) ?></b> after <?= local_time($b['payout_due_at']) ?> if no problem is reported.</p></div>
  <?php elseif ($s === BookingStatus::DISPUTED): ?>
    <div class="card"><h2>Under review</h2><p>The sender reported a problem. Your payout is on hold while our team reviews. We'll contact you if we need more information.</p></div>
  <?php endif ?>
<?php endif ?>

<?php if (($isSender || $isDriver) && BookingStatus::canTransition($s, BookingStatus::CANCELLED)): ?>
  <form method="post" action="/bookings/<?= $b['id'] ?>/cancel" style="margin-top:12px" onsubmit="return confirm('Cancel this booking?<?= $s === 'paid' ? ' The sender is refunded in full.' : '' ?>')"><?= csrf_field() ?>
    <button class="btn ghost sm"><?= $s === 'offered' && $isSender ? 'Decline offer' : ($s === 'offered' ? 'Withdraw offer' : 'Cancel booking') ?></button></form>
<?php endif ?>

<?php if (($isSender || $isDriver) && in_array($s, [BookingStatus::DELIVERED, BookingStatus::COMPLETED], true) && !$myReview): ?>
  <div class="card" style="margin-top:16px"><div class="row spread"><span>How did it go?</span><a class="btn honey sm" href="/bookings/<?= $b['id'] ?>/review">Leave a review</a></div></div>
<?php endif ?>

<?php if ($b['dispute']): ?>
  <div class="card" style="margin-top:16px"><h3>Problem report</h3>
    <p><b><?= e($b['dispute']['reason']) ?>:</b> <?= e($b['dispute']['details']) ?></p>
    <?php if ($b['dispute']['resolution']): ?><p class="muted">Resolution: <?= e($b['dispute']['resolution']) ?></p><?php endif ?></div>
<?php endif ?>
</div>

<div>
  <div class="card">
    <div class="row"><div class="avatar"><?= initials($b['driver_first'], $b['driver_last']) ?></div>
      <div><b><?= e($b['driver_first'] . ' ' . $b['driver_last']) ?></b>
        <div class="small muted"><?= e($r['make_model'] ?? '') ?> · <?= $stats['rating'] !== null ? stars($stats['rating']) . ' · ' : '' ?><?= $stats['deliveries'] ?> deliveries</div></div></div>
    <?php if ($b['driver_bio']): ?><p class="small" style="margin-top:12px">“<?= e($b['driver_bio']) ?>”</p><?php endif ?>
    <dl class="kv" style="margin-top:12px">
      <dt>Pickup</dt><dd><?= e($p['window_start']) ?>–<?= e($p['window_end']) ?></dd>
      <dt>Deliver to</dt><dd><?= e($point ? "{$point['name']} (pickup point)" : $p['delivery_city']) ?></dd>
      <dt>Requirements</dt><dd><?= e(implode(', ', array_map(fn($k) => BookingService::REQUIREMENTS[$k] ?? $k, $req))) ?></dd>
      <dt>Price</dt><dd><?= money((int)$b['price_cents']) ?></dd>
    </dl>
  </div>
  <div class="card">
    <h3>Timeline</h3>
    <ul class="timeline">
      <?php foreach ($b['events'] as $ev): if ($ev['type'] === 'pin_failed' && !$isAdmin) continue; ?>
        <li><b><?= e(ucfirst(str_replace('_', ' ', $ev['type']))) ?></b><span class="small muted"><?= local_time($ev['created_at']) ?> · <?= e($ev['note']) ?></span></li>
      <?php endforeach ?>
      <?php if (!in_array($s, ['completed', 'refunded', 'cancelled'], true)): ?><li class="todo"><b>Payment released to driver</b><span class="small muted">After the <?= (int)config('dispute_window_hours') ?>-hour dispute window</span></li><?php endif ?>
    </ul>
    <?php if ($b['proof_photo']): ?><p><a href="/bookings/<?= $b['id'] ?>/proof/photo" target="_blank">📸 Delivery photo</a></p><?php endif ?>
    <?php if ($b['signature_file']): ?><p><a href="/bookings/<?= $b['id'] ?>/proof/signature" target="_blank">✍️ Signature</a></p><?php endif ?>
    <p class="small muted">🗺️ Live GPS tracking is on the roadmap.</p>
  </div>
</div>
</div>
<?php if ($isSender && in_array($s, ['paid', 'picked_up', 'at_point'], true)): ?>
<script>setInterval(() => { if (!document.activeElement || !['INPUT','TEXTAREA','SELECT'].includes(document.activeElement.tagName)) location.reload(); }, 30000)</script>
<?php endif ?>
