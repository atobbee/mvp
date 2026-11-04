<?php
$title = 'Leave a review';
$who = $isSender ? $b['driver_first'] : $b['sender_first'];
$cats = $isSender
    ? ['communication' => 'Communication', 'punctuality' => 'Punctuality', 'care' => 'Package care', 'accuracy' => 'As described', 'overall' => 'Overall experience']
    : ['communication' => 'Communication', 'punctuality' => 'Ready on time', 'care' => 'Packaging', 'accuracy' => 'Description accuracy', 'overall' => 'Overall experience'];
?>
<div class="section" style="max-width:560px;margin:0 auto">
  <form class="card" method="post" action="/bookings/<?= $b['id'] ?>/review"><?= csrf_field() ?>
    <p class="small muted"><?= e($b['ref']) ?> · <?= e($b['package']['pickup_city']) ?> → <?= e($b['package']['delivery_city']) ?></p>
    <h1>How was <?= e($who) ?>?</h1>
    <?php foreach ($cats as $k => $label): ?>
      <div class="field"><label><?= e($label) ?></label><div class="pills">
        <?php for ($i = 1; $i <= 5; $i++): ?><label><input type="radio" name="score[<?= $k ?>]" value="<?= $i ?>" required <?= $i === 5 ? 'checked' : '' ?>><span><?= str_repeat('★', $i) ?></span></label><?php endfor ?>
      </div></div>
    <?php endforeach ?>
    <div class="field"><label>Comment (optional)</label><textarea name="comment" maxlength="1000"></textarea></div>
    <div class="row"><button class="btn honey">Submit review</button><a class="btn ghost" href="/bookings/<?= $b['id'] ?>">Skip for now</a></div>
  </form>
</div>
