<?php $title = 'Create account'; $sel = $_SESSION['old']['role'] ?? $role; ?>
<div class="section" style="max-width:520px;margin:0 auto">
  <form class="card" method="post" action="/register">
    <?= csrf_field() ?>
    <h1>Join Atobbee</h1>
    <div class="field"><label>I want to…</label>
      <div class="pills">
        <label><input type="radio" name="role" value="sender" <?= $sel === 'sender' ? 'checked' : '' ?>><span>📦 Send packages</span></label>
        <label><input type="radio" name="role" value="driver" <?= $sel === 'driver' ? 'checked' : '' ?>><span>🚗 Earn as driver</span></label>
        <label><input type="radio" name="role" value="business" <?= $sel === 'business' ? 'checked' : '' ?>><span>🏪 Become pickup point</span></label>
      </div>
    </div>
    <div class="grid g2">
      <div class="field"><label>First name</label><input type="text" name="first_name" value="<?= old('first_name') ?>" required autocomplete="given-name"></div>
      <div class="field"><label>Last name</label><input type="text" name="last_name" value="<?= old('last_name') ?>" required autocomplete="family-name"></div>
    </div>
    <div class="field"><label>Email</label><input type="email" name="email" value="<?= old('email') ?>" required autocomplete="email"></div>
    <div class="field"><label>Password (min. 10 characters)</label><input type="password" name="password" minlength="10" required autocomplete="new-password"></div>
    <label class="check"><input type="checkbox" name="terms" value="1" required> I agree to the Terms of Service and Privacy Policy</label>
    <button class="btn honey" style="width:100%;justify-content:center;margin-top:8px">Create account</button>
    <p class="small muted" style="margin-top:14px">Already have an account? <a href="/login">Log in</a></p>
  </form>
</div>
