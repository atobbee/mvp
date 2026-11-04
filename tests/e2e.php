<?php
// End-to-end HTTP test. Run: php bin/install.php --fresh --demo, start the server (see README), then php tests/e2e.php
const BASE = 'http://127.0.0.1:8000';
$ok = 0; $bad = 0;
function t(string $name, bool $cond, string $extra = '') { global $ok, $bad; $cond ? $ok++ : $bad++; echo ($cond ? '  ✓ ' : '  ✗ ') . $name . ($cond ? '' : " — $extra") . "\n"; }

final class Client {
    public string $jar; public string $body = ''; public int $code = 0; public string $url = '';
    function __construct(string $who) { $this->jar = sys_get_temp_dir() . "/jar-$who-" . uniqid(); }
    function req(string $method, string $path, array $data = [], bool $multipart = false): self {
        $ch = curl_init(BASE . $path);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_COOKIEJAR => $this->jar, CURLOPT_COOKIEFILE => $this->jar,
            CURLOPT_HTTPHEADER => ['Referer: ' . BASE . ($this->url ? parse_url($this->url, PHP_URL_PATH) : '/')]]);
        if ($method === 'POST') { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, $multipart ? $data : http_build_query($data)); }
        $this->body = (string)curl_exec($ch); $this->code = curl_getinfo($ch, CURLINFO_HTTP_CODE); $this->url = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        return $this;
    }
    function get(string $p): self { return $this->req('GET', $p); }
    function csrf(): string { preg_match('/name="_csrf" value="([a-f0-9]+)"/', $this->body, $m); return $m[1] ?? ''; }
    function post(string $p, array $d = [], bool $mp = false): self { if (!$this->csrf()) $this->get('/'); return $this->req('POST', $p, $d + ['_csrf' => $this->csrf()], $mp); }
    function login(string $email): self { $this->get('/login'); return $this->post('/login', ['email' => $email, 'password' => 'demo1234']); }
    function has(string $s): bool { return str_contains($this->body, $s); }
    function flashErr(): string { preg_match('/class="flash error">([^<]+)/', $this->body, $m); return $m[1] ?? ''; }
}
$db = new PDO('sqlite:' . dirname(__DIR__) . '/storage/atobbee.sqlite');
$id = fn(string $name) => $db->query("SELECT id FROM cities WHERE name = " . $db->quote($name))->fetchColumn();
$today = date('Y-m-d');
$pkg = fn(array $o = []) => $o + ['pickup_city_id' => $id('Breda'), 'pickup_address' => 'Hoeve Overa 1', 'delivery_city_id' => $id('Rotterdam'),
    'delivery_address' => 'Coolsingel 40', 'recipient_name' => 'Anna Jansen', 'pickup_date' => $today, 'window_start' => '15:00', 'window_end' => '16:00',
    'size' => 'small', 'weight_kg' => '3.2', 'category' => 'artisan', 'description' => 'Small cheese parcel', 'requirements' => ['photo', 'fragile'], 'prohibited_ack' => '1'];

echo "Public pages\n";
$anon = new Client('anon');
t('home renders with live routes', $anon->get('/')->code === 200 && $anon->has('Sophie') && $anon->has('€18,40'));
t('trust page', $anon->get('/trust')->code === 200 && $anon->has('Prohibited items'));
t('send requires login', str_contains($anon->get('/send')->url, '/login'));
t('POST without CSRF rejected', $anon->req('POST', '/login', ['email' => 'x'])->code === 419);
t('price suggestions API', ($j = json_decode($anon->get("/api/price-suggestions?from={$id('Breda')}&to={$id('Rotterdam')}")->body, true)) && $j['fair'] > 0, $anon->body);

echo "Sender books Sophie directly\n";
$pieter = (new Client('pieter'))->login('pieter@atobbee.test');
t('sender lands on dashboard', str_contains($pieter->url, '/dashboard') && $pieter->has('Pieter'));
$pieter->get('/send')->post('/send', $pkg());
t('package created, matches shown', preg_match('#/packages/(\d+)$#', $pieter->url, $m) === 1 && $pieter->has('Sophie van den Berg'), $pieter->url . ' ' . $pieter->flashErr());
$pkgId = $m[1] ?? 0;
t('Marco also matches', $pieter->has('Marco Thijssen'));
t('Lena correctly excluded (no fragile, detour too big)', !$pieter->has('Lena Kowalski'));
preg_match('/name="route_id" value="(\d+)"><button class="btn honey">Choose Sophie/', $pieter->body, $rm);
$pieter->post("/packages/$pkgId/book", ['route_id' => $rm[1] ?? 0]);
t('booking awaiting payment', preg_match('#/bookings/(\d+)$#', $pieter->url, $bm) === 1 && $pieter->has('Confirm &amp; pay'), $pieter->flashErr());
$bid = $bm[1];
$pieter->post("/bookings/$bid/pay", ['terms' => '1']);
t('paid, PINs visible to sender', $pieter->has('Pickup PIN') && $pieter->has('Recipient PIN'), $pieter->flashErr());
preg_match_all('/<div class="pin">(\d{4})<\/div>/', $pieter->body, $pins);
[$pickupPin, $deliveryPin] = $pins[1] + [null, null];
t('package withdrawn from other drivers', $db->query("SELECT status FROM packages WHERE id = $pkgId")->fetchColumn() === 'booked');

echo "Access control\n";
$lena = (new Client('lena'))->login('lena@atobbee.test');
t('other driver cannot see booking', $lena->get("/bookings/$bid")->code === 404);
t('sender cannot open driver dashboard', $pieter->get('/driver')->code === 403);

echo "Sophie picks up and delivers\n";
$sophie = (new Client('sophie'))->login('sophie@atobbee.test');
t('driver dashboard lists booking', $sophie->has('Small cheese parcel') && $sophie->has('€18,40'));
$sophie->get("/bookings/$bid");
t('driver does not see the PINs', !$sophie->has('<div class="pin">'));
$sophie->post("/bookings/$bid/pickup", ['pin' => $pickupPin === '0000' ? '1111' : '0000']);
t('wrong PIN refused with attempts left', str_contains($sophie->flashErr(), 'attempts left'), $sophie->flashErr());
$sophie->post("/bookings/$bid/pickup", ['pin' => $pickupPin]);
t('pickup confirmed → in transit', $sophie->has('In transit'), $sophie->flashErr());
$sophie->post("/bookings/$bid/deliver", ['pin' => $deliveryPin]);
t('photo required', str_contains($sophie->flashErr(), 'photo'), $sophie->flashErr());
$img = imagecreatetruecolor(20, 20); $png = sys_get_temp_dir() . '/proof.png'; imagepng($img, $png);
$sophie->post("/bookings/$bid/deliver", ['pin' => $deliveryPin, 'photo' => new CURLFile($png, 'image/png', 'proof.png')], true);
t('delivered with photo', $sophie->has('Delivered 🎉'), $sophie->flashErr());
t('sender can view proof photo', $pieter->get("/bookings/$bid/proof/photo")->code === 200);
t('other driver cannot view proof photo', $lena->get("/bookings/$bid/proof/photo")->code === 404);

echo "Review + payout\n";
$pieter->get("/bookings/$bid/review");
$pieter->post("/bookings/$bid/review", ['score' => ['communication' => 5, 'punctuality' => 5, 'care' => 5, 'accuracy' => 5, 'overall' => 5], 'comment' => 'Lovely, cheese intact.']);
t('review saved', $pieter->has('Thanks for your review'), $pieter->flashErr());
$db->exec("UPDATE bookings SET payout_due_at = '2000-01-01T00:00:00Z' WHERE id = $bid");
$cron = shell_exec('cd ' . escapeshellarg(dirname(__DIR__)) . ' && php bin/cron.php');
t('cron releases payout', str_contains((string)$cron, 'ATB-'), (string)$cron);
t('booking completed', $db->query("SELECT status FROM bookings WHERE id = $bid")->fetchColumn() === 'completed');
t('rating now on public profile', $anon->get('/drivers/' . $db->query("SELECT driver_id FROM bookings WHERE id = $bid")->fetchColumn())->has('Lovely, cheese intact.'));

echo "Pickup point + driver offer flow\n";
$point = $db->query("SELECT id FROM pickup_points LIMIT 1")->fetchColumn();
$pieter->get('/send')->post('/send', $pkg(['pickup_point_id' => $point, 'delivery_address' => '', 'requirements' => [], 'description' => 'Birthday gift']));
preg_match('#/packages/(\d+)$#', $pieter->url, $m2);
$pkg2 = $m2[1] ?? 0;
t('package to pickup point created', (bool)$pkg2, $pieter->flashErr());
$marco = (new Client('marco'))->login('marco@atobbee.test');
$marcoRoute = $db->query("SELECT r.id FROM routes r JOIN users u ON u.id = r.driver_id WHERE u.email = 'marco@atobbee.test' AND r.travel_date = '$today'")->fetchColumn();
$marco->get("/driver/routes/$marcoRoute");
t('Marco sees the request', $marco->has('Birthday gift'));
$marco->post("/driver/routes/$marcoRoute/offer", ['package_id' => $pkg2]);
t('offer sent', $marco->has('Offer sent'), $marco->flashErr());
$pieter->get("/packages/$pkg2");
t('sender sees offer', $pieter->has('Offers from drivers') && $pieter->has('Accept offer'));
preg_match('#/bookings/(\d+)/accept#', $pieter->body, $om);
$b2 = $om[1];
$pieter->post("/bookings/$b2/accept");
$pieter->post("/bookings/$b2/pay", ['terms' => '1']);
preg_match_all('/<div class="pin">(\d{4})<\/div>/', $pieter->body, $pins2);
t('offer accepted and paid', count($pins2[1]) === 2, $pieter->flashErr());
$marco->post("/bookings/$b2/pickup", ['pin' => $pins2[1][0]]);
t('driver gets handover QR for pickup point', $marco->has('Hand over at De Kaashoek'), $marco->flashErr());
$ref2 = $db->query("SELECT ref FROM bookings WHERE id = $b2")->fetchColumn();
$shop = (new Client('shop'))->login('kaashoek@atobbee.test');
t('point sees package on the way', $shop->has($ref2));
$shop->post('/point/receive', ['ref' => $ref2]);
t('point receives', $shop->has('received'), $shop->flashErr());
$shop->post('/point/collect', ['ref' => $ref2, 'pin' => $pins2[1][1]]);
t('recipient collects with PIN', $shop->has('handed to the recipient'), $shop->flashErr());
t('booking delivered', $db->query("SELECT status FROM bookings WHERE id = $b2")->fetchColumn() === 'delivered');

echo "Dispute\n";
$pieter->get("/bookings/$b2")->post("/bookings/$b2/dispute", ['reason' => 'damaged', 'details' => 'Box was crushed on one side.']);
t('dispute opened, payout on hold', $pieter->has('Under review'), $pieter->flashErr());
$admin = (new Client('admin'))->login('admin@atobbee.test');
t('admin sees dispute and verification queue', $admin->has('Box was crushed') && $admin->has('Karin'));
preg_match('#/admin/disputes/(\d+)/resolve#', $admin->body, $dm);
$admin->post("/admin/disputes/{$dm[1]}/resolve", ['outcome' => 'driver', 'note' => 'Minor dent, contents fine']);
t('dispute resolved → driver paid', $db->query("SELECT status FROM bookings WHERE id = $b2")->fetchColumn() === 'completed', $admin->flashErr());
$karin = $db->query("SELECT id FROM users WHERE email = 'karin@atobbee.test'")->fetchColumn();
$admin->post("/admin/users/$karin/verify", ['decision' => 'approve']);
t('admin verifies Karin', $db->query("SELECT verification_status FROM users WHERE id = $karin")->fetchColumn() === 'verified');

echo "Driver onboarding\n";
$new = new Client('newdriver');
$new->get('/register?role=driver')->post('/register', ['role' => 'driver', 'first_name' => 'Ravi', 'last_name' => 'Bakker', 'email' => 'ravi' . uniqid() . '@x.test', 'password' => 'longpassword1', 'terms' => '1']);
t('new driver goes to profile', str_contains($new->url, '/driver/profile'));
t('unverified driver cannot publish', str_contains($new->get('/driver/routes/new')->url, '/driver/profile'));
$new->post('/driver/profile', ['make_model' => 'Skoda Octavia', 'kind' => 'car', 'fuel' => 'petrol', 'max_size' => 'medium', 'max_weight_kg' => '20', 'plate' => 'AB-123-C', 'languages' => ['NL']]);
$new->post('/driver/verify', []);
t('verification submitted', $new->has('Submitted'), $new->flashErr());
t('masked plate stored', $db->query("SELECT plate_masked FROM vehicles ORDER BY id DESC LIMIT 1")->fetchColumn() === 'AB-**-C');

echo "\n$ok passed, $bad failed\n";
