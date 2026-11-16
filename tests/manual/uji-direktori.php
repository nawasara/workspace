<?php
/*
 * Uji direktori Workspace (T-25, FR-09 AC-5, FR-10 AC-1, NFR-01):
 * GET /api/v1/workspace/directory lewat permintaan HTTP sungguhan (api.auth +
 * scope), dari cache Keycloak. SQLite di memori, lewat remote-test.
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
$app['config']->set('nawasara-workspace.directory.per_actor_per_minute', 3);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Nawasara\Api\Services\TokenManager;
use Nawasara\Workspace\Services\DirectorySearch;

$gagal = 0;
$cek = function (string $label, bool $ok, string $detail = '') use (&$gagal) {
    if (! $ok) { $gagal++; }
    echo ($ok ? '  OK   ' : '  GAGAL')." {$label}".($detail ? "\n         {$detail}" : '')."\n";
};

Schema::create('users', function ($t) {
    $t->id(); $t->string('name'); $t->string('email')->nullable(); $t->string('username')->nullable();
    $t->string('keycloak_id')->nullable(); $t->string('password')->nullable(); $t->timestamps();
});
Schema::create('nawasara_registry_opd', function ($t) {
    $t->id(); $t->string('code'); $t->string('name'); $t->string('simas_name')->nullable();
    $t->string('phone')->nullable(); $t->string('email')->nullable(); $t->timestamps();
});
Schema::create('activity_log', function ($t) {
    $t->id(); $t->string('log_name')->nullable(); $t->text('description');
    $t->nullableMorphs('subject'); $t->nullableMorphs('causer'); $t->json('properties')->nullable();
    $t->uuid('batch_uuid')->nullable(); $t->string('event')->nullable(); $t->timestamps();
});
(require $root.'/packages/nawasara-keycloak/database/migrations/2026_04_25_130001_create_nawasara_keycloak_users_table.php')->up();
foreach (glob($root.'/packages/nawasara-workspace/database/migrations/*.php') as $f) { (require $f)->up(); }
foreach (glob($root.'/packages/nawasara-api/database/migrations/*.php') as $f) { (require $f)->up(); }

DB::table('nawasara_registry_opd')->insert([
    ['id' => 7, 'code' => 'KOMINFO', 'name' => 'Dinas Komunikasi dan Informatika', 'simas_name' => 'DINAS KOMUNIKASI DAN INFORMATIKA', 'phone' => '0352-111', 'email' => 'kominfo@ponorogo.go.id'],
    ['id' => 8, 'code' => 'DISDIK', 'name' => 'Dinas Pendidikan', 'simas_name' => 'DINAS PENDIDIKAN', 'phone' => null, 'email' => null],
]);

$kc = function (string $username, string $first, string $last, bool $enabled, array $attrs = [], ?string $email = null) {
    DB::table('nawasara_keycloak_users')->insert([
        'user_id' => 'kc-'.$username, 'username' => $username, 'email' => $email,
        'first_name' => $first, 'last_name' => $last, 'enabled' => $enabled,
        'attributes' => $attrs ? json_encode(array_map(fn ($v) => [$v], $attrs)) : null,
        'created_at' => now(), 'updated_at' => now(),
    ]);
};
$pringgo = '199506142020121004';
$budi = '198001012005011002';
$nonaktif = '197001012000011001';
// Pernah masuk: atribut SIMAS terisi, plus data pribadi yang TIDAK boleh keluar.
$kc($pringgo, 'Pringgo', 'Saputro', true, [
    'simas_skpd' => 'DINAS KOMUNIKASI DAN INFORMATIKA', 'simas_unit' => 'BIDANG APLIKASI INFORMATIKA',
    'simas_jabatan' => 'PRANATA KOMPUTER AHLI PERTAMA', 'whatsapp_number' => '081234567890', 'nip' => $pringgo,
], 'odyinggo.pribadi@gmail.com');
// Belum pernah masuk aplikasi Kisara mana pun: tanpa atribut.
$kc($budi, 'Budi', 'Santoso', true);
$kc($nonaktif, 'Budi', 'Nonaktif', false);
$kc('svc-budi', 'Budi', 'Bot', true);
// Isi realistis untuk ukur waktu: ~11 000 ASN.
$rows = [];
for ($i = 0; $i < 11000; $i++) {
    $rows[] = ['user_id' => 'kc-p'.$i, 'username' => sprintf('19%016d', 100000 + $i), 'first_name' => 'Pegawai', 'last_name' => 'Nomor '.$i,
        'enabled' => $i % 50 !== 0, 'created_at' => now(), 'updated_at' => now()];
    if (count($rows) === 500) { DB::table('nawasara_keycloak_users')->insert($rows); $rows = []; }
}
if ($rows) { DB::table('nawasara_keycloak_users')->insert($rows); }

// OPD 7 sudah punya akun Workspace (grup OPD-7 ada); OPD 8 belum.
DB::table('nawasara_workspace_accounts')->insert(['nip' => $pringgo, 'display_name' => 'Pringgo Saputro', 'quota' => '3 GB',
    'opd_id' => 7, 'state_hash' => 'x', 'created_at' => now(), 'updated_at' => now()]);

$tokens = $app->make(TokenManager::class);
$tokenOk = $tokens->create('uji direktori', ['workspace.directory.read'])['plaintext'];
$tokenLain = $tokens->create('uji lain', ['workspace.access.read'])['plaintext'];

$http = $app->make(Illuminate\Contracts\Http\Kernel::class);
$get = function (array $query, ?string $token) use ($http) {
    $server = ['REMOTE_ADDR' => '10.9.9.'.random_int(1, 250), 'HTTP_ACCEPT' => 'application/json'];
    if ($token) { $server['HTTP_AUTHORIZATION'] = 'Bearer '.$token; }

    return $http->handle(Request::create('/api/v1/workspace/directory?'.http_build_query($query), 'GET', [], [], [], $server));
};
$body = fn ($res) => json_decode($res->getContent(), true);
$nips = fn ($res) => array_column($body($res)['data']['people'] ?? [], 'nip');

echo "=== rute dan scope ===\n";
$route = Route::getRoutes()->getByName('nawasara-api.workspace.directory.index');
$cek('rute di /api/v1/workspace/directory', $route !== null && $route->uri() === 'api/v1/workspace/directory', $route?->uri() ?? 'tidak ada');
$cek('tanpa token: 401', $get(['q' => 'budi'], null)->getStatusCode() === 401);
$cek('token tanpa scope workspace.directory.read: 403', $get(['q' => 'budi'], $tokenLain)->getStatusCode() === 403);
$cek('scope terdaftar di ScopeRegistry', array_key_exists('workspace.directory.read', $app->make(Nawasara\Api\Support\ScopeRegistry::class)->all()));
$cek('q < 3 karakter: 422 query_too_short', ($r = $get(['q' => 'bu'], $tokenOk))->getStatusCode() === 422 && $body($r)['error']['code'] === 'query_too_short');
$cek('q hanya wildcard (%%%): 422, tidak jadi "cari semua"', $get(['q' => '%%%'], $tokenOk)->getStatusCode() === 422);
$cek('actor bukan NIP: 422', $get(['q' => 'budi', 'actor' => 'admin'], $tokenOk)->getStatusCode() === 422);

echo "\n=== ditemukan (FR-10 AC-1) ===\n";
$r = $get(['q' => $budi], $tokenOk);
$cek('NIP lengkap ASN yang belum pernah masuk: ketemu', $r->getStatusCode() === 200 && $nips($r) === [$budi], $r->getContent());
$cek('awalan NIP (10 digit): ketemu', in_array($budi, $nips($get(['q' => '1980010120'], $tokenOk)), true));
$r = $get(['q' => 'budi'], $tokenOk);
$cek('nama "budi": Budi Santoso saja (nonaktif & akun layanan tidak)', $nips($r) === [$budi], implode(',', $nips($r)));
$cek('akun nonaktif tidak ketemu lewat NIP', $nips($get(['q' => $nonaktif], $tokenOk)) === []);
$cek('nama dua kata, huruf kecil: "pringgo saputro"', $nips($get(['q' => 'pringgo saputro'], $tokenOk)) === [$pringgo]);
$cek('kata kedua harus cocok juga: "pringgo santoso" kosong', $nips($get(['q' => 'pringgo santoso'], $tokenOk)) === []);

echo "\n=== tanpa data pribadi (FR-09 AC-5) ===\n";
$r = $get(['q' => 'pringgo'], $tokenOk);
$data = $body($r);
$p = $data['data']['people'][0] ?? [];
$cek('kunci atas persis: data, meta', array_keys($data) === ['data', 'meta']);
$cek('kunci data persis: people, opds', array_keys($data['data']) === ['people', 'opds']);
$cek('kunci orang persis: nip, name, jabatan, unit, opd', array_keys($p) === ['nip', 'name', 'jabatan', 'unit', 'opd'], implode(',', array_keys($p)));
$cek('isi: nama, jabatan, unit, OPD dari simas_*', $p === ['nip' => $pringgo, 'name' => 'Pringgo Saputro', 'jabatan' => 'PRANATA KOMPUTER AHLI PERTAMA',
    'unit' => 'BIDANG APLIKASI INFORMATIKA', 'opd' => ['id' => 7, 'name' => 'Dinas Komunikasi dan Informatika']], json_encode($p));
$raw = $r->getContent();
$cek('email, nomor WhatsApp, telepon/email OPD tidak ada di jawaban', ! str_contains($raw, 'gmail') && ! str_contains($raw, '081234567890')
    && ! str_contains($raw, '0352-111') && ! str_contains($raw, 'kominfo@') && ! str_contains($raw, 'whatsapp') && ! str_contains($raw, 'email'), $raw);
$b = $body($get(['q' => $budi], $tokenOk))['data']['people'][0];
$cek('belum pernah masuk: jabatan/unit/opd null, nama ada', $b === ['nip' => $budi, 'name' => 'Budi Santoso', 'jabatan' => null, 'unit' => null, 'opd' => null], json_encode($b));
$cek('tidak bisa mencari lewat email (bukan oracle)', $nips($get(['q' => 'odyinggo'], $tokenOk)) === []);
$cek('tidak bisa mencari lewat nomor HP', $nips($get(['q' => '0812345'], $tokenOk)) === []);
$cek('tidak bisa mencari lewat jabatan di atribut', $nips($get(['q' => 'pranata'], $tokenOk)) === []);

echo "\n=== OPD ===\n";
$r = $get(['q' => 'komunikasi'], $tokenOk);
$opds = $body($r)['data']['opds'];
$cek('OPD cocok nama: id, nama, grup OPD-7 (kunci persis)', $opds === [['id' => 7, 'name' => 'Dinas Komunikasi dan Informatika', 'group' => 'OPD-7']], json_encode($opds));
$cek('OPD tanpa akun Workspace (belum ada grup) tidak muncul', $body($get(['q' => 'pendidikan'], $tokenOk))['data']['opds'] === []);
$cek('cari NIP tidak mengembalikan OPD', $body($get(['q' => '1980010120'], $tokenOk))['data']['opds'] === []);

echo "\n=== batas jumlah ===\n";
$r = $get(['q' => 'pegawai'], $tokenOk);
$cek('bawaan 10 hasil', count($nips($r)) === 10 && $body($r)['meta']['limit'] === 10);
$r = $get(['q' => 'pegawai', 'limit' => 100], $tokenOk);
$cek('limit=100 dipotong jadi 20', count($nips($r)) === 20 && $body($r)['meta']['limit'] === 20, (string) count($nips($r)));
$cek('limit=0 jadi 1', count($nips($get(['q' => 'pegawai', 'limit' => 0], $tokenOk))) === 1);

echo "\n=== batas laju per pegawai (NFR-01) ===\n";
$aktor = '199901012020011001';
$kode = [];
for ($i = 0; $i < 4; $i++) { $kode[] = $get(['q' => 'budi', 'actor' => $aktor], $tokenOk)->getStatusCode(); }
$cek('aktor yang sama: 3 lolos, ke-4 429', $kode === [200, 200, 200, 429], implode(',', $kode));
$r = $get(['q' => 'budi', 'actor' => $aktor], $tokenOk);
$cek('429 ber-Retry-After dan kode rate_limited', $r->getStatusCode() === 429 && (int) $r->headers->get('Retry-After') > 0 && $body($r)['error']['code'] === 'rate_limited');
$cek('aktor lain tidak ikut terhambat', $get(['q' => 'budi', 'actor' => '199901012020011002'], $tokenOk)->getStatusCode() === 200);
$app['config']->set('nawasara-workspace.directory.per_token_per_minute', 1);
$cek('batas per token juga berlaku', $get(['q' => 'budi'], $tokenOk)->getStatusCode() === 429);
$app['config']->set('nawasara-workspace.directory.per_token_per_minute', 600);

echo "\n=== waktu (11 000 baris, SQLite, indikatif) ===\n";
$search = $app->make(DirectorySearch::class);
$t = [];
$qs = ['pegawai nomor 7', 'budi', 'santoso', '1900000000001', $pringgo, 'nomor 1099', 'xyzabc', 'pringgo saputro'];
for ($i = 0; $i < 80; $i++) {
    $q = $qs[$i % count($qs)];
    $s = hrtime(true);
    $search->people($q, 20);
    $search->opds($q, 10);
    $t[] = (hrtime(true) - $s) / 1e6;
}
sort($t);
$p95 = $t[(int) floor(0.95 * count($t)) - 1];
$cek('p95 cari < 500 ms', $p95 < 500, sprintf('p95 %.1f ms, maks %.1f ms', $p95, end($t)));

echo "\n".($gagal === 0 ? 'SEMUA LULUS' : "ADA {$gagal} YANG GAGAL")."\n";
exit($gagal === 0 ? 0 : 1);
