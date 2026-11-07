<?php
$title = 'Pickup point';
$pt = $point ?? [];
$val = fn($k, $d = '') => e($pt[$k] ?? $d);
?>
<div class="page-head row spread">
  <div><h1><?= $point ? e($point['name']) : 'Set up your pickup point' ?></h1>
    <?php if ($point): ?><p class="muted"><?= e($point['address']) ?>, <?= e($point['city']) ?> ·
      <?= $point['paused'] ? '<span class="badge warn">Paused</span>' : '<span class="badge ok">● Open</span>' ?>
      <?= $user['verification_status'] === 'verified' ? '' : ' <span class="badge warn">Verification pending</span>' ?></p><?php endif ?></div>
</div>

<?php if ($point): ?>
  <div class="card row spread">
    <div><b>Atobbee Business</b> · <?= money((int)config('business_fee_cents')) ?>/month
      <?php if ($point['subscription_status'] === 'active'): ?><span class="badge ok">Active until <?= local_time($point['subscription_until'], 'j M Y') ?></span><?php else: ?><span class="badge warn">Not active — senders can't choose you yet</span><?php endif ?></div>
    <?php if ($point['subscription_status'] !== 'active'): ?><form method="post" action="/point/subscribe"><?= csrf_field() ?><button class="btn honey">Activate subscription</button></form><?php endif ?>
  </div>

  <div class="grid g4" style="margin-top:16px">
    <div class="stat"><b><?= $counts['expected'] ?></b><span>On the way</span></div>
    <div class="stat"><b><?= $counts['waiting'] ?></b><span>Awaiting collection</span></div>
    <div class="stat"><b><?= $counts['collected'] ?></b><span>Collected (24h)</span></div>
    <div class="stat"><b><?= (int)$point['daily_limit'] ?></b><span>Daily limit</span></div>
  </div>

  <div class="grid g2" style="margin-top:16px;align-items:start">
    <form class="card" method="post" action="/point/receive"><?= csrf_field() ?>
      <h2>📥 Receive from driver</h2>
      <p class="small muted">Scan the QR on the driver's phone, or type the booking reference.</p>
      <div class="row"><input type="text" name="ref" id="ref-receive" placeholder="ATB-2026-00001" required style="flex:1">
        <button type="button" class="btn ghost scan" data-target="ref-receive">📷 Scan</button></div>
      <button class="btn honey" style="margin-top:10px">Confirm received</button>
    </form>
    <form class="card" method="post" action="/point/collect"><?= csrf_field() ?>
      <h2>📤 Hand to recipient</h2>
      <p class="small muted">Ask for the booking reference and their 4-digit PIN.</p>
      <div class="field"><input type="text" name="ref" placeholder="ATB-2026-00001" required></div>
      <div class="field"><input class="pin-input" name="pin" inputmode="numeric" maxlength="4" pattern="\d{4}" placeholder="PIN" required autocomplete="off"></div>
      <button class="btn honey">Confirm collection</button>
    </form>
  </div>
  <video id="scanner" playsinline style="display:none;width:100%;max-width:420px;border-radius:12px;margin-top:12px"></video>

  <div class="card"><h2>Packages</h2>
    <?php if (!$packages): ?><p class="muted">Nothing expected right now.</p><?php else: ?>
    <div class="table-wrap"><table><tr><th>Booking</th><th>Contents</th><th>Recipient</th><th>Expected</th><th>Status</th></tr>
      <?php foreach ($packages as $p): ?><tr><td><?= e($p['ref']) ?></td><td><?= e(size_label($p['size'])) ?> · <?= e($p['weight_kg']) ?> kg</td>
        <td><?= e($p['recipient_name']) ?></td><td><?= e(date('j M', strtotime($p['pickup_date']))) ?>, before <?= e($p['eta']) ?></td><td><?= status_badge($p['status']) ?></td></tr><?php endforeach ?>
    </table></div><?php endif ?>
  </div>
<?php endif ?>

<form class="card" method="post" action="/point/settings"><?= csrf_field() ?>
  <h2><?= $point ? 'Settings' : 'Your business' ?></h2>
  <div class="grid g3">
    <div class="field"><label>Business name</label><input type="text" name="name" value="<?= $val('name') ?>" required></div>
    <div class="field"><label>Street address</label><input type="text" name="address" value="<?= $val('address') ?>" required></div>
    <div class="field"><label>City</label><select name="city_id"><?php foreach ($cities as $c): ?><option value="<?= $c['id'] ?>" <?= ($pt['city_id'] ?? null) == $c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option><?php endforeach ?></select></div>
    <div class="field"><label>Opening hours</label><input type="text" name="opening_hours" value="<?= $val('opening_hours', 'Mon–Sat 09:00–18:00') ?>"></div>
    <div class="field"><label>Daily package limit</label><input type="number" name="daily_limit" min="1" max="200" value="<?= $val('daily_limit', 10) ?>"></div>
    <div class="field"><label>Max package size</label><select name="max_size"><?php foreach (['envelope' => 'Envelope', 'small' => 'Small', 'medium' => 'Medium', 'large' => 'Large'] as $k => $l): ?><option value="<?= $k ?>" <?= ($pt['max_size'] ?? 'medium') === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach ?></select></div>
  </div>
  <?php if (!$point): ?><div class="field" style="max-width:240px"><label>KvK number</label><input type="text" name="kvk_number" inputmode="numeric" maxlength="8"></div><?php endif ?>
  <label class="check"><input type="checkbox" name="accepts_pickup" value="1" <?= ($pt['accepts_pickup'] ?? 1) ? 'checked' : '' ?>> Recipients can collect packages here</label>
  <label class="check"><input type="checkbox" name="accepts_dropoff" value="1" <?= ($pt['accepts_dropoff'] ?? 1) ? 'checked' : '' ?>> Senders can drop off packages here (coming soon)</label>
  <?php if ($point): ?><label class="check"><input type="checkbox" name="paused" value="1" <?= $point['paused'] ? 'checked' : '' ?>> Pause availability (holiday, busy day)</label><?php endif ?>
  <button class="btn"><?= $point ? 'Save settings' : 'Create pickup point' ?></button>
</form>

<script>
// Uses the browser's built-in BarcodeDetector where available (Chrome/Android). Typing the ref always works.
document.querySelectorAll('.scan').forEach(btn => btn.addEventListener('click', async () => {
  if (!('BarcodeDetector' in window)) { alert('Scanning is not supported in this browser. Type the reference instead.'); return; }
  const video = document.getElementById('scanner'), target = document.getElementById(btn.dataset.target);
  const stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' } });
  video.srcObject = stream; video.style.display = 'block'; await video.play();
  const detector = new BarcodeDetector({ formats: ['qr_code'] });
  const tick = async () => {
    const codes = await detector.detect(video).catch(() => []);
    if (codes.length) { target.value = codes[0].rawValue; stream.getTracks().forEach(t => t.stop()); video.style.display = 'none'; target.form.requestSubmit(); return; }
    requestAnimationFrame(tick);
  };
  tick();
}));
</script>
