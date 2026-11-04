<?php
use AtoBbee\Domain\BookingService;
$title = 'Send a package';
$o = $_SESSION['old'] ?? [];
$v = fn($k, $d = '') => e($o[$k] ?? $d);
$sel = fn($k, $val, $d = null) => (string)($o[$k] ?? $d) === (string)$val ? 'selected' : '';
$oldReq = $o['requirements'] ?? ['pin', 'fragile'];
?>
<div class="page-head"><h1>Send a package</h1><p class="muted">Fill in the details and we'll show you drivers already going your way.</p></div>
<form method="post" action="/send">
  <?= csrf_field() ?>
  <div class="card">
    <h2>📍 Pickup &amp; delivery</h2>
    <div class="grid g2">
      <div>
        <div class="field"><label>Pickup city</label><select name="pickup_city_id" required>
          <?php foreach ($cities as $c): ?><option value="<?= $c['id'] ?>" <?= $sel('pickup_city_id', $c['id'], $prefill['from']) ?>><?= e($c['name']) ?></option><?php endforeach ?></select></div>
        <div class="field"><label>Pickup address</label><input type="text" name="pickup_address" value="<?= $v('pickup_address') ?>" placeholder="Street and number" required></div>
      </div>
      <div>
        <div class="field"><label>Delivery city</label><select name="delivery_city_id" required>
          <?php foreach ($cities as $c): ?><option value="<?= $c['id'] ?>" <?= $sel('delivery_city_id', $c['id'], $prefill['to']) ?>><?= e($c['name']) ?></option><?php endforeach ?></select></div>
        <div class="field"><label>Delivery address</label><input type="text" name="delivery_address" value="<?= $v('delivery_address') ?>" placeholder="Street and number"></div>
      </div>
    </div>
    <?php if ($points): ?>
      <div class="field"><label>…or deliver to a pickup point (recipient collects there)</label>
        <select name="pickup_point_id"><option value="">No, deliver to the address</option>
          <?php foreach ($points as $p): ?><option value="<?= $p['id'] ?>" <?= $sel('pickup_point_id', $p['id']) ?>><?= e("{$p['name']}, {$p['address']}, {$p['city']}") ?> · <?= e($p['opening_hours']) ?></option><?php endforeach ?>
        </select></div>
    <?php endif ?>
    <div class="grid g2">
      <div class="field"><label>Recipient name</label><input type="text" name="recipient_name" value="<?= $v('recipient_name') ?>" required></div>
      <div class="field"><label>Recipient email (optional, for their PIN)</label><input type="email" name="recipient_email" value="<?= $v('recipient_email') ?>"></div>
    </div>
  </div>

  <div class="card">
    <h2>📅 Pickup timing</h2>
    <div class="grid g3">
      <div class="field"><label>Pickup date</label><input type="date" name="pickup_date" value="<?= $v('pickup_date', $prefill['date'] ?: date('Y-m-d')) ?>" min="<?= date('Y-m-d') ?>" required></div>
      <div class="field"><label>Window from</label><input type="time" name="window_start" value="<?= $v('window_start', '15:00') ?>" required></div>
      <div class="field"><label>Window until</label><input type="time" name="window_end" value="<?= $v('window_end', '17:00') ?>" required></div>
    </div>
  </div>

  <div class="card">
    <h2>📦 Package details</h2>
    <div class="field"><label>Size</label><div class="pills">
      <?php foreach (['envelope' => 'Envelope', 'small' => 'Small parcel', 'medium' => 'Medium parcel', 'large' => 'Large parcel'] as $k => $l): ?>
        <label><input type="radio" name="size" value="<?= $k ?>" <?= ($o['size'] ?? ($prefill['size'] ?: 'small')) === $k ? 'checked' : '' ?>><span><?= $l ?></span></label>
      <?php endforeach ?></div></div>
    <div class="grid g3">
      <div class="field"><label>Weight (kg)</label><input type="number" name="weight_kg" step="0.1" min="0.1" max="300" value="<?= $v('weight_kg', '1') ?>" required></div>
      <div class="field"><label>Category</label><select name="category">
        <?php foreach (BookingService::CATEGORIES as $k => $l): ?><option value="<?= $k ?>" <?= $sel('category', $k, 'artisan') ?>><?= e($l) ?></option><?php endforeach ?></select></div>
      <div class="field"><label>Declared value (optional, €)</label><input type="number" name="declared_value" step="0.01" min="0" value="<?= $v('declared_value') ?>"></div>
    </div>
    <div class="field"><label>Description</label><input type="text" name="description" value="<?= $v('description') ?>" placeholder="e.g. Small cheese parcel" required></div>
    <p class="notice">⚠️ Senders are responsible for appropriate packaging. See the <a href="/trust">prohibited items</a>.</p>
  </div>

  <div class="card">
    <h2>🔐 Delivery requirements</h2>
    <div class="grid g2">
      <?php foreach (BookingService::REQUIREMENTS as $k => $l): ?>
        <label class="check"><input type="checkbox" name="requirements[]" value="<?= $k ?>" <?= in_array($k, $oldReq, true) || $k === 'pin' ? 'checked' : '' ?> <?= $k === 'pin' ? 'disabled' : '' ?>> <?= e($l) ?><?= $k === 'pin' ? ' (always included)' : '' ?></label>
      <?php endforeach ?>
    </div>
    <div class="field"><label>Note to driver (optional)</label><textarea name="note_to_driver" maxlength="500"><?= $v('note_to_driver') ?></textarea></div>
    <label class="check"><input type="checkbox" name="prohibited_ack" value="1" required> This package contains no prohibited items and is properly packed.</label>
  </div>
  <div style="margin:20px 0"><button class="btn honey">Find drivers →</button></div>
</form>
