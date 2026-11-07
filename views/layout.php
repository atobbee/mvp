<?php use Atobbee\Auth; $me = Auth::user(); ?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e(($title ?? null) ? "$title · Atobbee" : 'Atobbee · Community-powered delivery') ?></title>
<link rel="stylesheet" href="/assets/app.css">
</head>
<body>
<nav class="nav">
  <div class="wrap">
    <a class="logo" href="/"><span>🐝</span>Atobbee</a>
    <div class="links">
      <?php if (!$me || $me['role'] === 'sender'): ?><a href="/send">Send a package</a><?php endif ?>
      <?php if (!$me): ?><a href="/register?role=driver">Drive &amp; earn</a><a href="/register?role=business">Pickup points</a><?php endif ?>
      <?php if ($me && $me['role'] === 'driver'): ?><a href="/driver">Dashboard</a><a href="/driver/routes/new">Publish a route</a><a href="/driver/profile">Profile</a><?php endif ?>
      <?php if ($me && $me['role'] === 'sender'): ?><a href="/dashboard">My deliveries</a><?php endif ?>
      <?php if ($me && $me['role'] === 'business'): ?><a href="/point">Pickup point</a><?php endif ?>
      <?php if ($me && $me['role'] === 'admin'): ?><a href="/admin">Admin</a><?php endif ?>
      <a href="/trust">Trust &amp; Safety</a>
    </div>
    <?php if ($me): ?>
      <span class="small muted"><?= e($me['first_name']) ?></span>
      <form method="post" action="/logout"><?= csrf_field() ?><button class="btn ghost sm">Log out</button></form>
    <?php else: ?>
      <a class="btn ghost sm" href="/login">Log in</a>
      <a class="btn honey sm" href="/register">Get started</a>
    <?php endif ?>
  </div>
</nav>
<main class="wrap">
  <?php foreach (take_flashes() as $f): ?>
    <div class="flash <?= e($f['type']) ?>"><?= e($f['message']) ?></div>
  <?php endforeach ?>
  <?= $content ?>
</main>
<footer>
  <div class="wrap row spread">
    <div><b>Atobbee</b> · A to B. Every time. · Initial market: Breda–Rotterdam corridor</div>
    <div>No passenger transport · <a href="/trust">Trust &amp; Safety</a> · Payments by Stripe</div>
  </div>
  <div class="wrap small" style="margin-top:8px">Atobbee operates a marketplace connecting senders with independent transport providers. Legal text is provisional and requires professional review before launch.</div>
</footer>
</body>
</html>
