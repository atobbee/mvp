<?php $title = 'Trust & Safety'; ?>
<div class="page-head"><h1>Trust &amp; Safety</h1><p class="muted">How AtoBbee keeps you and your packages safe.</p></div>
<div class="grid g2">
  <div class="card"><h3>🔐 Verification</h3>
    <p>Drivers and transporters are identity-verified before they can accept deliveries. Professional transporters add their KvK registration. Pickup points are verified before they appear to senders.</p></div>
  <div class="card"><h3>💳 Secure payment</h3>
    <p>The sender pays when booking. The money is held until delivery is confirmed and the <?= (int)config('dispute_window_hours') ?>-hour dispute window has passed; then the driver is paid minus the 8% platform fee.</p></div>
  <div class="card"><h3>📦 Proof of pickup &amp; delivery</h3>
    <p>Every booking has a pickup PIN (sender → driver) and a delivery PIN (recipient → driver or pickup point). Senders can also require a photo, a signature, an ID or 18+ check, or recipient-only delivery.</p></div>
  <div class="card"><h3>⭐ Ratings &amp; disputes</h3>
    <p>Ratings only come from completed deliveries. Problems can be reported up to <?= (int)config('dispute_window_hours') ?> hours after delivery and pause the payout until our team reviews. Unfair reviews can be reported for moderation.</p></div>
</div>
<div class="card" style="margin-top:16px"><h3>🚫 Prohibited items</h3>
  <p>Never send: illegal goods or controlled substances; weapons, firearms or ammunition; explosives or fireworks; unpackaged hazardous substances; cash or bearer instruments; live animals; items larger than declared; stolen goods; radioactive materials; biological materials or human remains.</p>
  <p class="muted small">Senders are responsible for appropriate packaging and an accurate description of the contents.</p></div>
<div class="card"><h3>⚖️ Liability</h3>
  <p class="muted">AtoBbee is a marketplace connecting senders with independent transport providers. Responsibilities, insurance, liability limits and claims procedures are governed by the platform terms and applicable law. All legal text is provisional pending professional review.</p></div>
