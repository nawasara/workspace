<?php
/*
 * Uji T-29: GET /api/v1/workspace/quota/{nip} (FR-14, FR-13 AC-3/AC-4) lewat
 * permintaan HTTP sungguhan (api.auth + scope). Nextcloud tiruan, cache WHM
 * dari migrasi nawasara/whm. SQLite di memori, lewat remote-test.
 *
 * Yang diuji:
 *  - dua sumber ok; kunci persis; satuan byte; ambang 80 % dan penuh
 *  - Nextcloud gagal: Drive unavailable, Mail tetap; Drive ditembolok
 *  - WHM tidak ada / gagal: Mail unavailable, Drive tetap
 *  - bukan peserta: hanya Mail (drive null); pilot_open: semua peserta
 *  - scope wajib; NIP tidak valid 422
 *  - klien admin: bentuk jawaban OCS asli (Http::fake), termasuk akun yang
 *    belum pernah masuk dan kuota tak terbatas
 */

$root = dirname(__DIR__, 4);
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$app['config']->set('database.default', 'uji');
$app['config']->set('database.connections.uji', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
$app['db']->setDefaultConnection('uji');
$app['config']->set('cache.default', 'array');
$app['config']->set('nawasara-workspace.sync_on_change', false);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Nawasara\Api\Services\TokenManager;
use Nawasara\Workspace\Models\Setting;
use Nawasara\Workspace\Services\NextcloudAdminClient;
use Nawasara\Workspace\Services\NextcloudException;

$gagal = 0;
$cek = function (string $label, bool $ok, string $detail = '') use (&$gagal) {
    if (! $ok) { $gagal++; }
    echo ($ok ? '  OK   ' : '  GAGAL')." {$label}".($detail ? "\n         {$detail}" : '')."\n";
};

Schema::create('users', function ($t) {
    $t->id(); $t->string('name'); $t->string('email')->nullable(); $t->string('username')->nullable();
    $t->string('keycloak_id')->nullable(); $t->string('password')->nullable(); $t->timestamps();
});
Schema::create('nawasara_registry_opd', function ($t) { $t->id(); $t->string('code'); $t->string('name'); $t->string('simas_name')->nullable(); $t->timestamps(); });
Schema::create('activity_log', function ($t) {
    $t->id(); $t->string('log_name')->nullable(); $t->text('description');
    $t->nullableMorphs('subject'); $t->nullableMorphs('causer'); $t->json('properties')->nullable();
    $t->uuid('batch_uuid')->nullable(); $t->string('event')->nullable(); $t->timestamps();
});
(require $root.'/packages/nawasara-keycloak/database/migrations/2026_04_25_130001_create_nawasara_keycloak_users_table.php')->up();
foreach (glob($root.'/packages/nawasara-workspace/database/migrations/*.php') as $f) { (require $f)->up(); }
foreach (glob($root.'/packages/nawasara-api/database/migrations/*.php') as $f) { (require $f)->up(); }
$whmMigrasi = fn () => [
    (require $root.'/packages/nawasara-whm/database/migrations/2026_04_25_100001_create_nawasara_whm_email_accounts_table.php'),
    (require $root.'/packages/nawasara-whm/database/migrations/2026_10_30_120001_create_nawasara_whm_mailbox_links_table.php'),
];
foreach ($whmMigrasi() as $m) { $m->up(); }

/** Nextcloud tiruan: pemakaian per UID; hitung panggilan; bisa dibuat gagal. */
class NcPalsu extends NextcloudAdminClient
{
    public array $quota = [];
    public int $calls = 0;
    public bool $down = false;

    public function __construct() { parent::__construct('http://x/nc', 'a', 'p'); }
    public function isConfigured(): bool { return true; }
    public function userQuota(string $uid): ?array
    {
        $this->calls++;
        if ($this->down) { throw new NextcloudException('Nextcloud tidak dapat dihubungi: timeout'); }
        return $this->quota[$uid] ?? null;
    }
}
$nc = new NcPalsu();
$app->instance(NextcloudAdminClient::class, $nc);

$GB = 1024 ** 3;
$MB = 1024 ** 2;
$pringgo = '199506142020121004';   // peserta, mailbox pribadi
$budi = '198001012005011002';      // bukan peserta, mailbox pribadi
$sari = '199001012015012003';      // peserta, hanya mailbox bersama
$dewi = '199201012016012004';      // peserta, tanpa akun Nextcloud
$lain = '199301012017012005';      // pemegang kedua mailbox bersama

$member = fn ($nip, $removed = null) => DB::table('nawasara_workspace_pilot_members')->insert([
    'nip' => $nip, 'name' => 'x', 'stage' => 1, 'removed_at' => $removed, 'created_at' => now(), 'updated_at' => now()]);
$member($pringgo); $member($sari); $member($dewi);
$member('197001012000011001', now()); // dicabut

$nc->quota[$pringgo] = ['used' => (int) (2.5 * $GB), 'quota' => 3 * $GB];
$nc->quota[$sari] = ['used' => 0, 'quota' => 3 * $GB];
$nc->quota[$budi] = ['used' => 1 * $GB, 'quota' => 3 * $GB];

$link = fn ($mailbox, $subject) => DB::table('nawasara_whm_mailbox_links')->insert([
    'instance' => 'WHM-Ryder', 'mailbox' => $mailbox, 'provider_id' => 'kisara', 'subject' => $subject, 'created_at' => now(), 'updated_at' => now()]);
$mbox = fn ($email, $quotaMb, $usedMb, $humanized = true) => DB::table('nawasara_whm_email_accounts')->insert([
    'instance' => 'WHM-Ryder', 'cpanel_user' => 'ponorogo', 'email' => $email, 'local_part' => explode('@', $email)[0], 'domain' => 'ponorogo.go.id',
    'quota_mb' => $quotaMb, 'disk_used_mb' => $usedMb, 'humanized' => $humanized ? json_encode(['diskquota' => (string) $quotaMb, 'diskused' => (string) $usedMb]) : null,
    'created_at' => now(), 'updated_at' => now()]);
$link('pringgo@ponorogo.go.id', $pringgo);
$link('kominfo@ponorogo.go.id', $pringgo);   // bersama: pringgo + lain
$link('kominfo@ponorogo.go.id', $lain);
$link('budi@ponorogo.go.id', $budi);
$link('kominfo@ponorogo.go.id', $sari);
$mbox('Pringgo@ponorogo.go.id', 1024, 200.5);
$mbox('kominfo@ponorogo.go.id', 5120, 5000);
$mbox('budi@ponorogo.go.id', 1000, 1000);     // penuh

$tokens = $app->make(TokenManager::class);
$tokenOk = $tokens->create('uji kuota', ['workspace.quota.read'])['plaintext'];
$tokenLain = $tokens->create('uji lain', ['workspace.access.read'])['plaintext'];

$http = $app->make(Illuminate\Contracts\Http\Kernel::class);
$get = function (string $nip, ?string $token = null) use ($http, &$tokenOk) {
    $server = ['REMOTE_ADDR' => '10.9.9.'.random_int(1, 250), 'HTTP_ACCEPT' => 'application/json'];
    $token ??= $tokenOk;
    if ($token !== '') { $server['HTTP_AUTHORIZATION'] = 'Bearer '.$token; }

    return $http->handle(Request::create('/api/v1/workspace/quota/'.$nip, 'GET', [], [], [], $server));
};
$body = fn ($res) => json_decode($res->getContent(), true);
$secKeys = ['status', 'used_bytes', 'quota_bytes', 'used_percent', 'level'];

echo "=== rute dan scope ===\n";
$route = Route::getRoutes()->getByName('nawasara-api.workspace.quota.show');
$cek('rute di /api/v1/workspace/quota/{nip}', $route !== null && $route->uri() === 'api/v1/workspace/quota/{nip}', $route?->uri() ?? 'tidak ada');
$cek('scope terdaftar di ScopeRegistry', array_key_exists('workspace.quota.read', $app->make(Nawasara\Api\Support\ScopeRegistry::class)->all()));
$cek('tanpa token: 401', $get($pringgo, '')->getStatusCode() === 401);
$cek('token tanpa scope workspace.quota.read: 403', $get($pringgo, $tokenLain)->getStatusCode() === 403);
$cek('NIP 17 digit: 422 invalid_nip', ($r = $get('19950614202012100'))->getStatusCode() === 422 && $body($r)['error']['code'] === 'invalid_nip');
$cek('NIP berhuruf: 422', $get('abc')->getStatusCode() === 422);
$cek('NIP tak valid tidak memanggil Nextcloud', $nc->calls === 0);

echo "\n=== dua sumber ok ===\n";
$r = $get($pringgo);
$d = $body($r);
$cek('200', $r->getStatusCode() === 200, $r->getContent());
$cek('kunci atas persis: data, meta', array_keys($d) === ['data', 'meta']);
$cek('kunci data persis: nip, drive, mail', array_keys($d['data']) === ['nip', 'drive', 'mail']);
$cek('meta: warning_percent 80', $d['meta'] === ['warning_percent' => 80]);
$cek('kunci drive persis', array_keys($d['data']['drive']) === $secKeys, implode(',', array_keys($d['data']['drive'])));
$cek('kunci mail persis: address + bagian', array_keys($d['data']['mail']) === array_merge(['address'], $secKeys));
$cek('drive: 2,5 GB dari 3 GB, 83 %, warning', $d['data']['drive'] === ['status' => 'ok', 'used_bytes' => (int) (2.5 * $GB), 'quota_bytes' => 3 * $GB,
    'used_percent' => 83, 'level' => 'warning'], json_encode($d['data']['drive']));
$cek('mail: mailbox pribadi saja, huruf kecil, byte', $d['data']['mail'] === ['address' => 'pringgo@ponorogo.go.id', 'status' => 'ok',
    'used_bytes' => (int) round(200.5 * $MB), 'quota_bytes' => 1024 * $MB, 'used_percent' => 19, 'level' => 'ok'], json_encode($d['data']['mail']));
$cek('mailbox bersama (kominfo@) tidak dipakai', ! str_contains($r->getContent(), 'kominfo'));

echo "\n=== cache Drive ===\n";
$sebelum = $nc->calls;
$get($pringgo);
$cek('muat ulang dalam 60 detik tidak memanggil Nextcloud lagi', $nc->calls === $sebelum, "panggilan {$nc->calls}");

echo "\n=== Nextcloud gagal (FR-14 AC-3) ===\n";
Cache::flush();
$nc->down = true;
$d = $body($r = $get($pringgo));
$cek('tetap 200', $r->getStatusCode() === 200);
$cek('drive unavailable, angka null, kunci sama', $d['data']['drive'] === ['status' => 'unavailable', 'used_bytes' => null, 'quota_bytes' => null, 'used_percent' => null, 'level' => null], json_encode($d['data']['drive']));
$cek('mail tetap ok', ($d['data']['mail']['status'] ?? null) === 'ok' && $d['data']['mail']['used_bytes'] === (int) round(200.5 * $MB));
$cek('pesan galat Nextcloud tidak bocor ke jawaban', ! str_contains($r->getContent(), 'timeout'));
$sebelum = $nc->calls;
$nc->down = false;
$cek('kegagalan ditembolok sebentar: tidak langsung memanggil lagi', ($body($get($pringgo))['data']['drive']['status'] === 'unavailable') && $nc->calls === $sebelum);
Cache::flush();
$cek('setelah tembolok habis: ok lagi', $body($get($pringgo))['data']['drive']['status'] === 'ok');

echo "\n=== bukan peserta (FR-14 AC-4) ===\n";
Cache::flush();
$sebelum = $nc->calls;
$d = $body($get($budi));
$cek('drive null', $d['data']['drive'] === null, json_encode($d['data']));
$cek('Nextcloud tidak ditanya untuk bukan peserta', $nc->calls === $sebelum);
$cek('mail ada: penuh 100 %, level full', $d['data']['mail'] === ['address' => 'budi@ponorogo.go.id', 'status' => 'ok', 'used_bytes' => 1000 * $MB,
    'quota_bytes' => 1000 * $MB, 'used_percent' => 100, 'level' => 'full'], json_encode($d['data']['mail']));
$cek('peserta dicabut: drive null', $body($get('197001012000011001'))['data']['drive'] === null);
Setting::setFlag(Setting::PILOT_OPEN, true);
$cek('pilot_open: bukan peserta pun dapat drive', ($body($get($budi))['data']['drive']['status'] ?? null) === 'ok');
Setting::setFlag(Setting::PILOT_OPEN, false);
Setting::setFlag(Setting::KILL_SWITCH, true);
Cache::flush();
$cek('kill switch tidak menyembunyikan drive peserta', ($body($get($pringgo))['data']['drive']['status'] ?? null) === 'ok');
Setting::setFlag(Setting::KILL_SWITCH, false);

echo "\n=== mail kosong / tanpa akun ===\n";
Cache::flush();
$d = $body($get($sari));
$cek('hanya mailbox bersama: mail null, drive ok 0 %', $d['data']['mail'] === null && $d['data']['drive']['used_percent'] === 0 && $d['data']['drive']['level'] === 'ok', json_encode($d['data']));
$d = $body($get($dewi));
$cek('peserta tanpa akun Nextcloud: drive unavailable, mail null', $d['data']['drive']['status'] === 'unavailable' && $d['data']['mail'] === null, json_encode($d['data']));
$nc->quota[$dewi] = ['used' => 5 * $MB, 'quota' => null];
Cache::flush();
$d = $body($get($dewi));
$cek('kuota tak terbatas: quota_bytes null, persen null, level ok', $d['data']['drive'] === ['status' => 'ok', 'used_bytes' => 5 * $MB, 'quota_bytes' => null, 'used_percent' => null, 'level' => 'ok'], json_encode($d['data']['drive']));
// ambang tepat
$nc->quota[$dewi] = ['used' => 80 * $MB - 1, 'quota' => 100 * $MB];
Cache::flush();
$cek('79,99 %: 79, ok', ($x = $body($get($dewi))['data']['drive'])['used_percent'] === 79 && $x['level'] === 'ok', json_encode($x));
$nc->quota[$dewi] = ['used' => 80 * $MB, 'quota' => 100 * $MB];
Cache::flush();
$cek('tepat 80 %: warning', ($x = $body($get($dewi))['data']['drive'])['level'] === 'warning', json_encode($x));
// mailbox tertaut tapi belum ada di cache / belum sinkron disk
$link('dewi@ponorogo.go.id', $dewi);
$cek('tertaut tapi tidak ada di cache WHM: mail unavailable, alamat tetap', ($x = $body($get($dewi))['data']['mail'])['status'] === 'unavailable' && $x['address'] === 'dewi@ponorogo.go.id', json_encode($x));
$mbox('dewi@ponorogo.go.id', null, null, false);
$cek('baru sinkron ringan (tanpa disk): unavailable, bukan "tak terbatas"', $body($get($dewi))['data']['mail']['status'] === 'unavailable');
DB::table('nawasara_whm_email_accounts')->where('email', 'dewi@ponorogo.go.id')->update(['disk_used_mb' => 10, 'humanized' => json_encode(['diskquota' => '0', 'diskused' => '10'])]);
$cek('kuota mail 0 di WHM = tak terbatas: quota_bytes null', ($x = $body($get($dewi))['data']['mail'])['status'] === 'ok' && $x['quota_bytes'] === null && $x['used_bytes'] === 10 * $MB, json_encode($x));

echo "\n=== WHM gagal / tidak ada ===\n";
Cache::flush();
// Kolom email hilang: kueri cache WHM melempar galat (SQLite menganggap "kolom"
// berkutip yang tak ada sebagai teks, jadi pakai kolom tanpa kutip di whereRaw).
Schema::table('nawasara_whm_email_accounts', fn ($t) => $t->renameColumn('email', 'surel'));
$d = $body($r = $get($pringgo));
$cek('kueri WHM galat: mail unavailable, address null', $d['data']['mail'] === ['address' => null, 'status' => 'unavailable', 'used_bytes' => null, 'quota_bytes' => null, 'used_percent' => null, 'level' => null], json_encode($d['data']['mail']));
$cek('drive tetap ok', $d['data']['drive']['status'] === 'ok');
$cek('pesan galat SQL tidak bocor', ! str_contains($r->getContent(), 'surel') && ! str_contains($r->getContent(), 'SQL'));
Schema::drop('nawasara_whm_mailbox_links');
Schema::drop('nawasara_whm_email_accounts');
$d = $body($get($pringgo));
$cek('tabel WHM tidak ada: mail unavailable, drive ok', $d['data']['mail']['status'] === 'unavailable' && $d['data']['drive']['status'] === 'ok');
$cek('bukan peserta + WHM tidak ada: drive null, mail unavailable', ($x = $body($get($budi))['data'])['drive'] === null && $x['mail']['status'] === 'unavailable');
$nc->down = true;
Cache::flush();
$d = $body($r = $get($pringgo));
$cek('keduanya gagal: tetap 200, dua-duanya unavailable', $r->getStatusCode() === 200 && $d['data']['drive']['status'] === 'unavailable' && $d['data']['mail']['status'] === 'unavailable');
$nc->down = false;

echo "\n=== klien admin: bentuk OCS asli (Http::fake) ===\n";
$ocs = fn ($data) => Http::response(['ocs' => ['meta' => ['status' => 'ok', 'statuscode' => 200], 'data' => $data]], 200);
Http::fake([
    'nc.test/nc/ocs/v2.php/cloud/users/111*' => $ocs(['id' => '111', 'quota' => ['free' => 100, 'used' => 2048, 'total' => 2148, 'relative' => 0.95, 'quota' => 3221225472]]),
    'nc.test/nc/ocs/v2.php/cloud/users/222*' => $ocs(['id' => '222', 'quota' => ['quota' => 3221225472, 'used' => 0]]),
    'nc.test/nc/ocs/v2.php/cloud/users/333*' => $ocs(['id' => '333', 'quota' => ['free' => 1, 'used' => 77, 'total' => 78, 'relative' => 0, 'quota' => -3]]),
    'nc.test/nc/ocs/v2.php/cloud/users/444*' => $ocs(['id' => '444', 'quota' => ['quota' => 'none', 'used' => 0]]),
    'nc.test/nc/ocs/v2.php/cloud/users/404*' => Http::response(['ocs' => ['meta' => ['status' => 'failure', 'statuscode' => 404, 'message' => 'User does not exist'], 'data' => []]], 404),
    'nc.test/nc/ocs/v2.php/cloud/users/555*' => Http::response('bad gateway', 502),
]);
$real = new NextcloudAdminClient('http://nc.test/nc', 'admin', 'secret');
$cek('akun aktif: used + quota byte', $real->userQuota('111') === ['used' => 2048, 'quota' => 3221225472]);
$cek('belum pernah masuk (tanpa free/total): used 0, quota ada', $real->userQuota('222') === ['used' => 0, 'quota' => 3221225472]);
$cek('quota -3 = tak terbatas (null)', $real->userQuota('333') === ['used' => 77, 'quota' => null]);
$cek('quota "none" = tak terbatas (null)', $real->userQuota('444') === ['used' => 0, 'quota' => null]);
$cek('akun tidak ada: null', $real->userQuota('404') === null);
$lempar = false;
try { $real->userQuota('555'); } catch (NextcloudException) { $lempar = true; }
$cek('502: NextcloudException', $lempar);
$cek('withTimeout membuat salinan, aslinya tetap', $real->withTimeout(3) !== $real);

echo "\n".($gagal === 0 ? 'SEMUA LULUS' : "ADA {$gagal} YANG GAGAL")."\n";
exit($gagal === 0 ? 0 : 1);
