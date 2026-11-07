<?php $title = null; $ex = $pricing->split(2000); ?>
<section class="hero">
  <div class="tag">🐝 Netherlands · Breda–Rotterdam corridor</div>
  <h1>Your package is going somewhere.<br>Someone already is too.</h1>
  <p class="lead">Atobbee connects packages with verified people and professional transporters already travelling in the right direction.</p>

  <form class="card quote" method="get" action="/send">
    <h3>Quick quote</h3>
    <div class="grid g4">
      <div><label>From</label><select name="from"><?php foreach ($cities as $c): ?><option value="<?= $c['id'] ?>" <?= $c['name'] === 'Breda' ? 'selected' : '' ?>><?= e($c['name']) ?></option><?php endforeach ?></select></div>
      <div><label>To</label><select name="to"><?php foreach ($cities as $c): ?><option value="<?= $c['id'] ?>" <?= $c['name'] === 'Rotterdam' ? 'selected' : '' ?>><?= e($c['name']) ?></option><?php endforeach ?></select></div>
      <div><label>Date</label><input type="date" name="date" value="<?= date('Y-m-d') ?>" min="<?= date('Y-m-d') ?>"></div>
      <div><label>Package size</label><select name="size"><option value="envelope">Envelope</option><option value="small" selected>Small parcel</option><option value="medium">Medium parcel</option><option value="large">Large parcel</option></select></div>
    </div>
    <div style="margin-top:14px"><button class="btn honey">Find drivers →</button></div>
  </form>
</section>

<section class="section steps">
  <h2>Three steps to a smarter delivery</h2>
  <div class="grid g3">
    <div class="card"><div class="emoji">📦</div><h3>1. Post your package</h3><p class="muted">Pickup and delivery details, requirements and a pickup time window. Under two minutes.</p></div>
    <div class="card"><div class="emoji">🔍</div><h3>2. Match with a driver</h3><p class="muted">Verified drivers already travelling your route. Compare price, timing and trust profile, then choose.</p></div>
    <div class="card"><div class="emoji">✅</div><h3>3. Track &amp; confirm</h3><p class="muted">PIN confirmation at pickup and delivery. Payment is released to the driver after safe completion.</p></div>
  </div>
</section>

<?php if ($routes): ?>
<section class="section">
  <h2>Drivers already on the road</h2>
  <div class="grid g3">
    <?php foreach ($routes as $r): ?>
      <div class="card">
        <div class="row"><div class="avatar"><?= initials($r['first_name'], $r['last_name']) ?></div>
          <div><b><?= e($r['first_name'] . ' ' . mb_substr($r['last_name'], 0, 1)) ?>.</b><div class="small muted"><?= e($r['make_model'] ?? 'Vehicle') ?></div></div></div>
        <p style="margin:12px 0 4px"><b><?= e($r['origin']) ?> → <?= e($r['destination']) ?></b></p>
        <div class="small muted"><?= $r['travel_date'] === date('Y-m-d') ? 'Today' : e(date('D j M', strtotime($r['travel_date']))) ?> · <?= e($r['window_start']) ?>–<?= e($r['window_end']) ?> · from <?= money((int)$r['price_cents']) ?></div>
      </div>
    <?php endforeach ?>
  </div>
</section>
<?php endif ?>

<section class="section grid g2">
  <div class="card">
    <h2>Transparent pricing</h2>
    <p class="muted">One simple commission: 8%, minimum <?= money((int)config('commission_min_cents')) ?>. No hidden fees.</p>
    <dl class="kv">
      <dt>Delivery price</dt><dd><?= money($ex['price']) ?></dd>
      <dt>Platform fee (8%)</dt><dd>− <?= money($ex['commission']) ?></dd>
      <dt>Driver earns</dt><dd><b><?= money($ex['driver']) ?></b></dd>
    </dl>
  </div>
  <div class="card">
    <h2>For drivers &amp; businesses</h2>
    <p><b>🚗 Drive &amp; earn.</b> Publish a journey you already make, accept packages that fit, get paid after delivery.</p>
    <p><b>🏪 Become a pickup point.</b> Bakeries, florists, corner shops: receive packages for customers for <?= money((int)config('business_fee_cents')) ?>/month.</p>
    <div class="row"><a class="btn" href="/register?role=driver">Start driving</a><a class="btn ghost" href="/register?role=business">Register a business</a></div>
  </div>
</section>
