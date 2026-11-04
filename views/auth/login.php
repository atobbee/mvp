<?php $title = 'Log in'; ?>
<div class="section" style="max-width:420px;margin:0 auto">
  <form class="card" method="post" action="/login">
    <?= csrf_field() ?>
    <h1>Log in</h1>
    <div class="field"><label>Email</label><input type="email" name="email" value="<?= old('email') ?>" required autofocus autocomplete="email"></div>
    <div class="field"><label>Password</label><input type="password" name="password" required autocomplete="current-password"></div>
    <button class="btn honey" style="width:100%;justify-content:center">Log in</button>
    <p class="small muted" style="margin-top:14px">New here? <a href="/register">Create an account</a></p>
    <?php if (config('env') === 'dev'): ?>
      <p class="small notice">Dev demo accounts (password <code>demo1234</code>): pieter@ (sender), sophie@ / marco@ (drivers), kaashoek@ (pickup point), admin@ — all @atobbee.test</p>
    <?php endif ?>
  </form>
</div>
