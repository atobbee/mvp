<?php
// Your private page: see who signed up, download as CSV, remove someone.
// Open it at: https://yourdomain.nl/admin.php

session_start();
require __DIR__ . '/db.php';
$config = require (file_exists(__DIR__ . '/config.php') ? __DIR__ . '/config.php' : __DIR__ . '/config.example.php');

if (!file_exists(__DIR__ . '/config.php') || str_starts_with($config['admin_password'], 'CHANGE-ME')) {
    http_response_code(503);
    exit('<!doctype html><meta charset="utf-8"><title>Atobbee admin</title><body style="font-family:system-ui;background:#0f1b2e;color:#ece7dc;max-width:480px;margin:15vh auto;padding:0 16px"><h1>Admin is locked</h1><p>Copy <b>config.example.php</b> to <b>config.php</b> on the server and set your own admin password in it. Then reload this page.</p></body>');
}

if (isset($_POST['password'])) {
    if (hash_equals($config['admin_password'], (string)$_POST['password'])) {
        session_regenerate_id(true);
        $_SESSION['atobbee_admin'] = true;
    } else {
        sleep(2);
        $error = 'Wrong password.';
    }
}
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: admin.php');
    exit;
}

$style = '<style>
:root{--navy:#0f1b2e;--navy-2:#16233a;--gold:#d9c58c;--paper:#ece7dc;--mist:#9aa3b5;--line:rgba(236,231,220,.12)}
body{font-family:Inter,"Helvetica Neue",Arial,sans-serif;background:var(--navy);color:var(--paper);margin:40px auto;padding:0 16px}
.logo{font-weight:800;letter-spacing:.22em;text-transform:uppercase;display:flex;align-items:center;gap:10px}
input,button,a.btn{font:inherit;padding:10px 16px;border-radius:999px;box-sizing:border-box}
input{width:100%;border:1px solid var(--line);background:var(--navy-2);color:var(--paper);border-radius:12px;margin:6px 0 12px}
button,a.btn{background:var(--gold);color:var(--navy);border:0;font-weight:600;cursor:pointer;text-decoration:none;display:inline-block}
a{color:var(--mist)}
table{width:100%;border-collapse:collapse;margin-top:16px;background:var(--navy-2)}
td,th{text-align:left;padding:10px;border-bottom:1px solid var(--line);font-size:14px}
th{color:var(--gold);font-weight:600}
button.del{background:none;color:#f2a99a;padding:0;font-weight:500}
</style>';

if (empty($_SESSION['atobbee_admin'])) { ?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex"><title>Atobbee admin</title><?= $style ?></head>
<body style="max-width:360px;margin-top:15vh"><div class="logo"><img src="assets/logo-mark.svg" alt="" width="30">Atobbee</div><h1>Waitlist</h1>
<?php if (!empty($error)) echo '<p style="color:#c0392b">' . $error . '</p>'; ?>
<form method="post"><label>Password<input type="password" name="password" autofocus required></label><button>Log in</button></form></body></html>
<?php exit; }

$db = atobbee_db();

if (isset($_POST['delete'])) {
    $db->prepare('DELETE FROM waitlist WHERE id = ?')->execute([(int)$_POST['delete']]);
    header('Location: admin.php');
    exit;
}

$rows = $db->query('SELECT * FROM waitlist ORDER BY created_at DESC')->fetchAll(PDO::FETCH_ASSOC);

if (isset($_GET['csv'])) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="atobbee-waitlist-' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['email', 'first_name', 'last_name', 'postcode', 'city', 'country', 'interested_in', 'shop', 'signed_up_at_utc', 'consent_text']);
    foreach ($rows as $r) fputcsv($out, [$r['email'], $r['first_name'] ?? '', $r['last_name'] ?? '', $r['postcode'] ?? '', $r['city'] ?? '', $r['country'] ?? '', $r['role'], $r['company'] ?? '', $r['created_at'], $r['consent_text']]);
    exit;
}

$counts = ['sending' => 0, 'driving' => 0, 'pickup' => 0];
foreach ($rows as $r) foreach (explode(',', $r['role']) as $role) if (isset($counts[$role])) $counts[$role]++;
$cities = [];
foreach ($rows as $r) { $c = trim((string)($r['city'] ?? '')); if ($c === '') continue; $k = mb_convert_case($c, MB_CASE_TITLE); $cities[$k] = ($cities[$k] ?? 0) + 1; }
arsort($cities);
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex"><title>Atobbee admin</title><?= $style ?></head>
<body style="max-width:900px">
<div class="logo"><img src="assets/logo-mark.svg" alt="" width="30">Atobbee</div>
<h1>Waitlist: <?= count($rows) ?></h1>
<p>Sending: <?= $counts['sending'] ?> · Driving: <?= $counts['driving'] ?> · Pickup points: <?= $counts['pickup'] ?></p>
<?php if ($cities): ?><p><b>Top cities:</b> <?php $i = 0; foreach ($cities as $c => $n) { if ($i++ >= 8) break; echo htmlspecialchars($c) . ' (' . $n . ')' . ($i < min(8, count($cities)) ? ' · ' : ''); } ?></p><?php endif; ?>
<p><a class="btn" href="?csv=1">Download as CSV</a> &nbsp; <a href="?logout=1">Log out</a></p>
<table><tr><th>Email</th><th>Name</th><th>Location</th><th>Interested in</th><th>Shop</th><th>Signed up (UTC)</th><th></th></tr>
<?php foreach ($rows as $r): ?>
<tr><td><?= htmlspecialchars($r['email']) ?></td><td><?= htmlspecialchars(trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? ''))) ?></td><td><?= htmlspecialchars(trim(($r['postcode'] ?? '') . ' ' . ($r['city'] ?? '') . (($r['country'] ?? '') && $r['country'] !== 'Netherlands' ? ', ' . $r['country'] : ''))) ?></td><td><?= htmlspecialchars(str_replace(',', ', ', $r['role'])) ?></td><td><?= htmlspecialchars($r['company'] ?? '') ?></td><td><?= htmlspecialchars(substr($r['created_at'], 0, 16)) ?></td>
<td><form method="post" onsubmit="return confirm('Remove this person?')"><button class="del" name="delete" value="<?= (int)$r['id'] ?>">remove</button></form></td></tr>
<?php endforeach; ?>
</table></body></html>
