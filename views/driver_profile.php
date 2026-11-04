<?php $title = $driver['first_name']; ?>
<div class="section grid g2" style="align-items:start">
  <div class="card">
    <div class="row"><div class="avatar" style="width:64px;height:64px;font-size:22px"><?= initials($driver['first_name'], $driver['last_name']) ?></div>
      <div><h1 style="margin:0"><?= e($driver['first_name'] . ' ' . $driver['last_name']) ?></h1>
        <div class="muted"><?= $driver['is_professional'] ? 'Professional transporter' : 'Private driver' ?><?= $vehicle ? ' · ' . e($vehicle['make_model']) : '' ?></div></div></div>
    <p style="margin-top:12px"><span class="badge ok">✓ Identity verified</span>
      <?php if ($vehicle && $vehicle['fuel'] === 'electric'): ?><span class="badge ok">⚡ Electric</span><?php endif ?>
      <?php if ($stats['deliveries'] >= 10): ?><span class="badge honey">📦 10+ deliveries</span><?php endif ?>
      <?php if (($stats['reliability'] ?? 0) >= 95 && $stats['deliveries'] >= 10): ?><span class="badge honey">🏅 Reliable driver</span><?php endif ?></p>
    <div class="grid g3">
      <div class="stat"><b><?= $stats['rating'] !== null ? number_format($stats['rating'], 1) : '—' ?></b><span>Rating</span></div>
      <div class="stat"><b><?= $stats['deliveries'] ?></b><span>Deliveries</span></div>
      <div class="stat"><b><?= $stats['reliability'] !== null ? $stats['reliability'] . '%' : '—' ?></b><span>Reliability</span></div>
    </div>
    <?php if ($driver['bio']): ?><h3 style="margin-top:16px">About</h3><p>“<?= e($driver['bio']) ?>”</p><?php endif ?>
    <?php if ($driver['languages']): ?><p class="muted">Languages: <?= e(str_replace(',', ' · ', $driver['languages'])) ?></p><?php endif ?>
    <?php if ($vehicle): ?><h3>Vehicle</h3><dl class="kv"><dt>Make &amp; model</dt><dd><?= e($vehicle['make_model']) ?><?= $vehicle['year'] ? ' (' . e($vehicle['year']) . ')' : '' ?></dd>
      <dt>Cargo</dt><dd>Up to <?= e(size_label($vehicle['max_size'])) ?>, <?= e($vehicle['max_weight_kg']) ?> kg</dd><dt>Plate</dt><dd><?= e($vehicle['plate_masked'] ?? '—') ?></dd></dl><?php endif ?>
  </div>
  <div class="card"><h2>Reviews</h2>
    <?php if (!$reviews): ?><p class="muted">No reviews yet.</p><?php endif ?>
    <?php foreach ($reviews as $rv): ?>
      <div style="padding:10px 0;border-bottom:1px solid var(--line)"><b><?= str_repeat('★', (int)$rv['overall']) ?></b> <span class="small muted"><?= e($rv['first_name']) ?> · <?= local_time($rv['created_at'], 'j M Y') ?></span>
        <?php if ($rv['comment']): ?><p style="margin:4px 0 0"><?= e($rv['comment']) ?></p><?php endif ?></div>
    <?php endforeach ?>
    <p class="small muted">Ratings come only from completed deliveries.</p>
  </div>
</div>
