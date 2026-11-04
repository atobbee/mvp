<?php
use AtoBbee\Domain\BookingService;
$title = 'Publish a route';
$o = $_SESSION['old'] ?? [];
$v = fn($k, $d = '') => e($o[$k] ?? $d);
$cats = $o['categories'] ?? ['food', 'artisan', 'documents', 'clothing'];
?>
<div class="page-head"><h1>Publish a route</h1><p class="muted">Tell us where you're going and we'll find packages that fit.</p></div>
<?php if (!$vehicle): ?><div class="notice">Add your vehicle on your <a href="/driver/profile">profile</a> first.</div><?php endif ?>
<form method="post" action="/driver/routes"><?= csrf_field() ?>
  <div class="card"><h2>Route</h2>
    <div class="grid g2">
      <div class="field"><label>Departure</label><select name="origin_city_id" id="from">
        <?php foreach ($cities as $c): ?><option value="<?= $c['id'] ?>" <?= ($o['origin_city_id'] ?? '') == $c['id'] || (!$o && $c['name'] === 'Breda') ? 'selected' : '' ?>><?= e($c['name']) ?></option><?php endforeach ?></select></div>
      <div class="field"><label>Destination</label><select name="dest_city_id" id="to">
        <?php foreach ($cities as $c): ?><option value="<?= $c['id'] ?>" <?= ($o['dest_city_id'] ?? '') == $c['id'] || (!$o && $c['name'] === 'Rotterdam') ? 'selected' : '' ?>><?= e($c['name']) ?></option><?php endforeach ?></select></div>
    </div>
    <div class="grid g4">
      <div class="field"><label>Date</label><input type="date" name="travel_date" value="<?= $v('travel_date', date('Y-m-d')) ?>" min="<?= date('Y-m-d') ?>" required></div>
      <div class="field"><label>Leaving from</label><input type="time" name="window_start" value="<?= $v('window_start', '15:00') ?>" required></div>
      <div class="field"><label>Leaving until</label><input type="time" name="window_end" value="<?= $v('window_end', '17:00') ?>" required></div>
      <div class="field"><label>Max detour (km)</label><input type="number" name="max_detour_km" min="0" max="100" value="<?= $v('max_detour_km', '10') ?>"></div>
    </div>
  </div>
  <div class="card"><h2>Capacity &amp; preferences</h2>
    <div class="grid g3">
      <div class="field"><label>Max package size</label><select name="max_size">
        <?php foreach (['envelope' => 'Envelope', 'small' => 'Small parcel', 'medium' => 'Medium parcel', 'large' => 'Large parcel'] as $k => $l): ?>
          <option value="<?= $k ?>" <?= ($o['max_size'] ?? ($vehicle['max_size'] ?? 'medium')) === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach ?></select></div>
      <div class="field"><label>Max weight per package (kg)</label><input type="number" name="max_weight_kg" step="0.5" min="0.5" value="<?= $v('max_weight_kg', $vehicle['max_weight_kg'] ?? 20) ?>"></div>
      <div class="field"><label>How many packages</label><input type="number" name="capacity" min="1" max="20" value="<?= $v('capacity', '3') ?>"></div>
    </div>
    <label>Accepted categories</label>
    <div class="pills"><?php foreach (BookingService::CATEGORIES as $k => $l): ?>
      <label><input type="checkbox" name="categories[]" value="<?= $k ?>" <?= in_array($k, $cats, true) ? 'checked' : '' ?>><span><?= e($l) ?></span></label><?php endforeach ?></div>
  </div>
  <div class="card"><h2>Price</h2>
    <p class="muted small" id="km">Suggestions are estimates based on distance.</p>
    <div class="grid g3" id="suggest">
      <button type="button" class="stat" data-k="cost" style="text-align:left;cursor:pointer"><span>Cost recovery</span><b>—</b><span>Covers direct costs</span></button>
      <button type="button" class="stat" data-k="fair" style="text-align:left;cursor:pointer"><span>Fair price</span><b>—</b><span>Costs + your time</span></button>
      <button type="button" class="stat" data-k="priority" style="text-align:left;cursor:pointer"><span>Priority price</span><b>—</b><span>Higher earnings</span></button>
    </div>
    <div class="grid g3" style="margin-top:14px">
      <div class="field"><label>Your price (€)</label><input type="number" name="price" id="price" step="0.50" min="<?= (int)config('min_price_cents') / 100 ?>" value="<?= $v('price', '22') ?>" required></div>
      <div><label>Platform fee (8%, min. €1.50)</label><p id="fee">—</p></div>
      <div><label>You receive</label><p id="net" style="font-weight:700">—</p></div>
    </div>
  </div>
  <div style="margin:20px 0"><button class="btn honey" <?= $vehicle ? '' : 'disabled' ?>>Publish route</button></div>
</form>
<script>
(() => {
  const rate = <?= json_encode((float)config('commission_rate')) ?>, min = <?= json_encode((int)config('commission_min_cents')) ?>;
  const eur = c => '€' + (c / 100).toFixed(2).replace('.', ',');
  const price = document.getElementById('price'), fee = document.getElementById('fee'), net = document.getElementById('net');
  const calc = () => { const p = Math.round(parseFloat(price.value || 0) * 100); const f = Math.max(Math.round(p * rate), min);
    fee.textContent = eur(f); net.textContent = eur(Math.max(0, p - f)); };
  price.addEventListener('input', calc); calc();
  const load = async () => {
    const r = await fetch(`/api/price-suggestions?from=${from.value}&to=${to.value}`).then(r => r.json()).catch(() => null);
    if (!r || r.error) return;
    document.getElementById('km').textContent = `≈ ${r.km} km by road. Suggestions are estimates; senders always see your final price.`;
    document.querySelectorAll('#suggest [data-k]').forEach(b => { b.querySelector('b').textContent = eur(r[b.dataset.k]);
      b.onclick = () => { price.value = (r[b.dataset.k] / 100).toFixed(2); calc(); }; });
  };
  const from = document.getElementById('from'), to = document.getElementById('to');
  from.onchange = to.onchange = load; load();
})();
</script>
