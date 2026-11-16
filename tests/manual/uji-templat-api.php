<?php
/*
 * Uji T-31: tabel templat (UUID) + API templat (FR-16 AC-1/AC-3/AC-4/AC-5),
 * lewat permintaan HTTP sungguhan (api.auth + scope). Nextcloud tiruan di
 * tingkat klien (berkas di memori), SQLite di memori, lewat remote-test.
 *
 * Yang diuji:
 *  - pita pegawai: Aktif + (semua OPD ∪ OPD si pegawai), urut app/urutan/nama
 *  - Draf & Nonaktif tidak tampil bagi pegawai; Draf tampil bagi admin Kisara
 *  - daftar kelola (semua status) hanya untuk admin Kisara
 *  - bukan admin tidak dapat menulis (403 not_admin) walau token ber-scope
 *  - unggah: Draf awal, isi diperiksa, ekstensi = app, nama berkas unik
 *  - ganti berkas, ubah metadata/status/cakupan, hapus (bawaan ditolak)
 *  - hapus tidak menyentuh berkas yang sudah dibuat pegawai
 *  - UUID v7, scope wajib, kunci jawaban persis
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
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Nawasara\Api\Services\TokenManager;
use Nawasara\Workspace\Models\PilotMember;
use Nawasara\Workspace\Models\Template;
use Nawasara\Workspace\Services\NextcloudAdminClient;
use Nawasara\Workspace\Services\NextcloudException;
use Nawasara\Workspace\Services\WorkspaceAdmins;
use Spatie\Permission\Models\Permission;

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
(require $root.'/vendor/spatie/laravel-permission/database/migrations/create_permission_tables.php.stub')->up();
(require $root.'/packages/nawasara-keycloak/database/migrations/2026_04_25_130001_create_nawasara_keycloak_users_table.php')->up();
$migrasi = glob($root.'/packages/nawasara-workspace/database/migrations/*.php');
foreach ($migrasi as $f) { (require $f)->up(); }
foreach (glob($root.'/packages/nawasara-api/database/migrations/*.php') as $f) { (require $f)->up(); }

/** Nextcloud tiruan: berkas per jalur di pohon admin + Drive pegawai, id berkas tetap saat ditimpa. */
class NcPalsu extends NextcloudAdminClient
{
    public array $files = [];   // path => ['id' => int, 'body' => string]
    public array $deleted = [];
    public bool $down = false;
    private int $nextId = 500;

    public function __construct() { parent::__construct('http://x/nc', 'kisara-admin', 'p'); }
    public function isConfigured(): bool { return true; }
    // Folder "Templat Kisara" sudah dalam keadaan benar (diuji di uji-templat-bawaan).
    public function teamFolders(): array { return [1 => ['id' => 1, 'mount_point' => 'Templat Kisara', 'groups' => ['admin' => 31, 'workspace-pilot' => 1], 'quota' => -3, 'acl' => false]]; }
    public function userGroups(string $uid): array { return ['admin']; }
    public function uploadFile(string $path, string $contents, string $mime = 'application/octet-stream'): void
    {
        if ($this->down) { throw new NextcloudException('PUT gagal: HTTP 503', 503); }
        $this->files[$path] = ['id' => $this->files[$path]['id'] ?? $this->nextId++, 'body' => $contents];
    }
    public function downloadFile(string $path): ?string { return $this->files[$path]['body'] ?? null; }
    public function fileId(string $path): ?int { return $this->files[$path]['id'] ?? null; }
    public function deleteFile(string $path): bool
    {
        if ($this->down) { throw new NextcloudException('DELETE gagal: HTTP 503', 503); }
        $this->deleted[] = $path;
        $had = isset($this->files[$path]);
        unset($this->files[$path]);
        return $had;
    }
}
$nc = new NcPalsu();
$app->instance(NextcloudAdminClient::class, $nc);

DB::table('nawasara_registry_opd')->insert([
    ['id' => 7, 'code' => 'KOMINFO', 'name' => 'Dinas Komunikasi dan Informatika', 'simas_name' => null],
    ['id' => 8, 'code' => 'DINDIK', 'name' => 'Dinas Pendidikan', 'simas_name' => null],
]);

$admin = '199506142020121004';    // admin Kisara (akun lokal + izin)
$bukan = '198604082020121003';    // akun lokal, tanpa izin
$kominfo = '199001012015011001';  // pegawai OPD 7 lewat override peserta pilot
$dindik = '199201012016012004';   // pegawai OPD 8 lewat atribut simas_skpd Keycloak
$tanpaOpd = '199301012017012005'; // pegawai tanpa OPD

Permission::firstOrCreate(['name' => 'workspace.template.manage', 'guard_name' => 'web']);
$userModel = config('auth.providers.users.model');
$uAdmin = $userModel::forceCreate(['name' => 'Admin Kisara', 'username' => $admin, 'email' => 'a@x.id']);
$uAdmin->givePermissionTo('workspace.template.manage');
$userModel::forceCreate(['name' => 'Bukan Admin', 'username' => $bukan, 'email' => 'b@x.id']);

PilotMember::create(['nip' => $kominfo, 'name' => 'Kominfo', 'stage' => 1, 'opd_id' => 7]);
DB::table('nawasara_keycloak_users')->insert(['user_id' => 'kc-d', 'username' => $dindik, 'enabled' => true,
    'attributes' => json_encode(['simas_skpd' => ['DINAS PENDIDIKAN']]), 'created_at' => now(), 'updated_at' => now()]);

$tokens = $app->make(TokenManager::class);
$tRead = $tokens->create('uji baca', ['workspace.template.read'])['plaintext'];
$tManage = $tokens->create('uji kelola', ['workspace.template.manage'])['plaintext'];
$tLain = $tokens->create('uji lain', ['workspace.access.read'])['plaintext'];

$http = $app->make(Illuminate\Contracts\Http\Kernel::class);
$call = function (string $method, string $uri, array $params = [], ?string $token = null, array $files = [], ?array $json = null) use ($http) {
    $server = ['REMOTE_ADDR' => '10.9.9.'.random_int(1, 250), 'HTTP_ACCEPT' => 'application/json'];
    if ($token !== '' && $token !== null) { $server['HTTP_AUTHORIZATION'] = 'Bearer '.$token; }
    $content = null;
    if ($json !== null) { $server['CONTENT_TYPE'] = 'application/json'; $content = json_encode($json); }
    $res = $http->handle(Request::create('/api/v1/workspace'.$uri, $method, $params, [], $files, $server, $content));
    app('auth')->forgetGuards();
    return $res;
};
$body = fn ($res) => json_decode($res->getContent(), true);
$ribbon = fn (string $nip, array $q = []) => $call('GET', '/templates', ['nip' => $nip] + $q, $tRead);
$names = fn ($res) => array_column($body($res)['data'] ?? [], 'name');

$src = $root.'/packages/nawasara-workspace/resources/templates/';
$tmpDir = sys_get_temp_dir().'/uji-templat-api-'.getmypid();
@mkdir($tmpDir);
$upload = function (string $source, string $as) use ($tmpDir) {
    $p = $tmpDir.'/'.Str::random(8);
    copy($source, $p);
    return new UploadedFile($p, $as, null, null, true);
};
$fake = function (string $as, string $body) use ($tmpDir) {
    $p = $tmpDir.'/'.Str::random(8);
    file_put_contents($p, $body);
    return new UploadedFile($p, $as, null, null, true);
};

$keys = ['id', 'name', 'description', 'app', 'extension', 'scope', 'opd', 'status', 'sort_order', 'builtin', 'path', 'file_id', 'size_bytes', 'updated_at'];

echo "=== tabel & rute ===\n";
$m = require collect($migrasi)->first(fn ($f) => str_contains($f, 'templates_table'));
$cek('migrasi templat ada dan dapat dibalik', Schema::hasTable('nawasara_workspace_templates') && (function () use ($m) {
    $m->down(); $gone = ! Schema::hasTable('nawasara_workspace_templates'); $m->up(); return $gone && Schema::hasTable('nawasara_workspace_templates');
})());
$cols = Schema::getColumnListing('nawasara_workspace_templates');
$cek('kolom sesuai FSD', array_diff(['id', 'name', 'description', 'app', 'scope', 'opd_id', 'status', 'sort_order', 'file_name', 'file_id', 'size_bytes', 'builtin_key', 'created_by', 'updated_by'], $cols) === [], implode(',', $cols));
$reg = $app->make(Nawasara\Api\Support\ScopeRegistry::class)->all();
$cek('scope workspace.template.read dan .manage terdaftar', isset($reg['workspace.template.read'], $reg['workspace.template.manage']));
foreach (['index' => 'templates', 'manage' => 'templates/manage', 'store' => 'templates', 'update' => 'templates/{id}', 'file' => 'templates/{id}/file', 'destroy' => 'templates/{id}'] as $n => $uri) {
    $r = Route::getRoutes()->getByName('nawasara-api.workspace.templates.'.$n);
    $cek("rute templates.{$n} di /api/v1/workspace/{$uri}", $r && $r->uri() === 'api/v1/workspace/'.$uri && in_array('api.auth', $r->gatherMiddleware(), true), $r?->uri() ?? 'tidak ada');
}
$cek('admin dikenali lewat izin, bukan sekadar akun lokal', app(WorkspaceAdmins::class)->isAdmin($admin) && ! app(WorkspaceAdmins::class)->isAdmin($bukan) && ! app(WorkspaceAdmins::class)->isAdmin($kominfo));

echo "\n=== templat bawaan: 14 baris ===\n";
$seed = (new Nawasara\Workspace\Database\Seeders\BuiltinTemplateSeeder($nc))->seed();
$cek('seeder: 14 berkas + 14 baris', $seed['created'] === 14 && $seed['rows_created'] === 14, json_encode($seed));
$nota = Template::where('builtin_key', 'nota-dinas')->first();
$cek('PK UUID v7 (HasUuids)', $nota && Str::isUuid($nota->id) && $nota->id[14] === '7', $nota?->id ?? '-');

echo "\n=== scope wajib ===\n";
$cek('tanpa token: 401', $call('GET', '/templates', ['nip' => $kominfo], '')->getStatusCode() === 401);
$cek('token tanpa workspace.template.read: 403', $call('GET', '/templates', ['nip' => $kominfo], $tLain)->getStatusCode() === 403);
$cek('token baca tidak bisa mengelola: 403 insufficient_scope', ($r = $call('GET', '/templates/manage', ['actor' => $admin], $tRead))->getStatusCode() === 403 && $body($r)['error']['code'] === 'insufficient_scope');
$cek('token kelola tidak bisa membaca pita: 403', $call('GET', '/templates', ['nip' => $kominfo], $tManage)->getStatusCode() === 403);
$cek('nip bukan 18 digit: 422 invalid_nip', ($r = $ribbon('123'))->getStatusCode() === 422 && $body($r)['error']['code'] === 'invalid_nip');
$cek('app tak dikenal: 422 invalid_app', ($r = $ribbon($kominfo, ['app' => 'pdf']))->getStatusCode() === 422 && $body($r)['error']['code'] === 'invalid_app');

echo "\n=== menulis: hanya admin Kisara ===\n";
$docx = fn ($as = 'Nota Dinas Bupati.docx') => $upload($src.'Nota Dinas.docx', $as);
$baru = fn (string $actor, array $p, $file = null) => $call('POST', '/templates', ['actor' => $actor] + $p, $tManage, ['file' => $file ?? $docx()]);
$cek('actor kosong: 422 invalid_actor', ($r = $call('POST', '/templates', ['name' => 'x', 'scope' => 'all'], $tManage, ['file' => $docx()]))->getStatusCode() === 422 && $body($r)['error']['code'] === 'invalid_actor');
$r = $baru($bukan, ['name' => 'Coba', 'scope' => 'all']);
$cek('bukan admin (akun lokal tanpa izin): 403 not_admin', $r->getStatusCode() === 403 && $body($r)['error']['code'] === 'not_admin');
$cek('pegawai biasa (tanpa akun lokal): 403', $baru($kominfo, ['name' => 'Coba', 'scope' => 'all'])->getStatusCode() === 403);
$cek('tidak ada baris maupun berkas tertulis', Template::count() === 14 && count($nc->files) === 14);
$cek('bukan admin tidak bisa melihat daftar kelola: 403', $call('GET', '/templates/manage', ['actor' => $bukan], $tManage)->getStatusCode() === 403);
$cek('bukan admin tidak bisa mengubah: 403', $call('PATCH', '/templates/'.$nota->id, [], $tManage, [], ['actor' => $bukan, 'status' => 'inactive'])->getStatusCode() === 403);
$cek('bukan admin tidak bisa menghapus: 403', $call('DELETE', '/templates/'.$nota->id, ['actor' => $bukan], $tManage)->getStatusCode() === 403);
$cek('bukan admin tidak bisa mengganti berkas: 403', $call('POST', '/templates/'.$nota->id.'/file', ['actor' => $bukan], $tManage, ['file' => $docx()])->getStatusCode() === 403);
$cek('nota dinas tetap Aktif', Template::find($nota->id)->status === 'active');

echo "\n=== unggah (admin) ===\n";
$r = $baru($admin, ['name' => 'Nota Dinas Bupati', 'description' => 'Format khusus', 'scope' => 'all']);
$d = $body($r)['data'] ?? [];
$cek('201, kunci persis', $r->getStatusCode() === 201 && array_keys($d) === $keys, $r->getContent());
$cek('mulai sebagai Draf (FR-16 AC-2)', ($d['status'] ?? null) === 'draft');
$cek('app dari ekstensi, urutan setelah bawaan', $d['app'] === 'docs' && $d['extension'] === 'docx' && $d['sort_order'] === 60, json_encode([$d['app'], $d['sort_order']]));
$cek('berkas di "Templat Kisara", path + file_id untuk Workspace Web', $d['path'] === 'Templat Kisara/Nota Dinas Bupati.docx' && $d['file_id'] === $nc->fileId($d['path']) && $d['builtin'] === false);
$cek('UUID v7 di jawaban', Str::isUuid($d['id']) && $d['id'][14] === '7');
$cek('created_by/updated_by = akun admin, tidak ikut jawaban', Template::find($d['id'])->created_by === $uAdmin->id && ! array_key_exists('created_by', $d));
$cek('jejak aktivitas dengan pelaku admin', DB::table('activity_log')->where('subject_id', $d['id'])->where('causer_id', $uAdmin->id)->exists());
$drafId = $d['id'];

$r = $baru($admin, ['name' => 'Nota Dinas Bupati', 'scope' => 'all']);
$cek('nama sama: berkas kedua "(2)", tidak menimpa', $r->getStatusCode() === 201 && $body($r)['data']['path'] === 'Templat Kisara/Nota Dinas Bupati (2).docx', $body($r)['data']['path'] ?? $r->getContent());
$r = $baru($admin, ['name' => '../a/b:c*', 'scope' => 'all']);
$cek('nama berbahaya dibersihkan: tidak keluar folder', $r->getStatusCode() === 201 && $body($r)['data']['path'] === 'Templat Kisara/a b c.docx', $body($r)['data']['path'] ?? $r->getContent());
$sampah = $body($r)['data']['id'];

$r = $baru($admin, ['name' => 'Surat Dindik', 'scope' => 'opd', 'opd_id' => 8, 'sort_order' => 5], $upload($src.'Surat Undangan.docx', 'surat.docx'));
$opd8 = $body($r)['data']['id'] ?? null;
$cek('cakupan satu OPD', $r->getStatusCode() === 201 && $body($r)['data']['scope'] === 'opd' && $body($r)['data']['opd'] === ['id' => 8, 'name' => 'Dinas Pendidikan'], $r->getContent());
$r = $baru($admin, ['name' => 'Anggaran Kominfo', 'scope' => 'opd', 'opd_id' => 7], $upload($src.'Anggaran Kegiatan.xlsx', 'anggaran.xlsx'));
$opd7 = $body($r)['data']['id'] ?? null;
$cek('xlsx -> sheets', $body($r)['data']['app'] === 'sheets');

$v = $baru($admin, ['name' => 'X', 'scope' => 'opd']);
$cek('cakupan opd tanpa opd_id: 422 validation_failed + fields.opd_id', $v->getStatusCode() === 422 && $body($v)['error']['code'] === 'validation_failed' && isset($body($v)['error']['fields']['opd_id']), $v->getContent());
$cek('opd_id tidak ada: 422', $baru($admin, ['name' => 'X', 'scope' => 'opd', 'opd_id' => 99])->getStatusCode() === 422);
$cek('tanpa nama: 422', $baru($admin, ['scope' => 'all'])->getStatusCode() === 422);
$cek('PDF berganti nama .docx: 422 (isi diperiksa)', ($v = $baru($admin, ['name' => 'X', 'scope' => 'all'], $fake('a.docx', '%PDF-1.4 bukan zip')))->getStatusCode() === 422 && isset($body($v)['error']['fields']['file']));
$cek('xlsx berganti nama .docx: 422', $baru($admin, ['name' => 'X', 'scope' => 'all'], $upload($src.'Daftar Hadir.xlsx', 'a.docx'))->getStatusCode() === 422);
$cek('ekstensi .pdf: 422', $baru($admin, ['name' => 'X', 'scope' => 'all'], $fake('a.pdf', 'x'))->getStatusCode() === 422);
$app['config']->set('nawasara-workspace.templates.max_upload_mb', 0);
$cek('melebihi batas ukuran: 422', $baru($admin, ['name' => 'X', 'scope' => 'all'])->getStatusCode() === 422);
$app['config']->set('nawasara-workspace.templates.max_upload_mb', 20);
$nc->down = true;
$r = $baru($admin, ['name' => 'Saat Nextcloud mati', 'scope' => 'all']);
$cek('Nextcloud gagal: 502 nextcloud_unavailable, tanpa baris', $r->getStatusCode() === 502 && $body($r)['error']['code'] === 'nextcloud_unavailable' && Template::where('name', 'Saat Nextcloud mati')->doesntExist());
$nc->down = false;

echo "\n=== pita pegawai (FR-16 AC-4, AC-3) ===\n";
$r = $ribbon($kominfo);
$d = $body($r);
$cek('kunci atas data + meta; meta persis', array_keys($d) === ['data', 'meta'] && array_keys($d['meta']) === ['nip', 'app', 'opd_id', 'drafts_included'], json_encode($d['meta']));
$cek('kunci tiap templat persis', array_keys($d['data'][0]) === $keys);
$cek('OPD 7 (override pilot): hanya 14 bawaan selama templat baru masih Draf', count($d['data']) === 14 && ! in_array('Anggaran Kominfo', $names($r), true) && $d['meta']['opd_id'] === 7, implode(', ', $names($r)));
// Templat baru masih Draf: aktifkan dulu.
foreach ([$opd7, $opd8] as $tid) {
    $call('PATCH', '/templates/'.$tid, [], $tManage, [], ['actor' => $admin, 'status' => 'active']);
}
$k = $names($ribbon($kominfo));
$cek('Draf tidak tampil bagi pegawai', ! in_array('Nota Dinas Bupati', $k, true));
$cek('OPD 7: bawaan + Anggaran Kominfo, tanpa Surat Dindik', in_array('Anggaran Kominfo', $k, true) && ! in_array('Surat Dindik', $k, true) && count($k) === 15, implode(', ', $k));
$dd = $names($ribbon($dindik));
$cek('OPD 8 (simas_skpd): bawaan + Surat Dindik, tanpa Anggaran Kominfo', in_array('Surat Dindik', $dd, true) && ! in_array('Anggaran Kominfo', $dd, true) && count($dd) === 15, implode(', ', $dd));
$t = $names($ribbon($tanpaOpd));
$cek('tanpa OPD: hanya cakupan semua OPD', count($t) === 14 && ! in_array('Surat Dindik', $t, true));
$cek('urut: docs, sheets, slides; dalam app sort_order lalu nama', array_slice($dd, 0, 6) === ['Surat Dindik', 'Nota Dinas', 'Surat Undangan', 'Notulen Rapat', 'Laporan Kegiatan', 'Telaahan Staf']
    && array_slice($dd, 6, 5) === ['Anggaran Kegiatan', 'Daftar Hadir', 'Rekap Absensi', 'Inventaris Aset', 'Jadwal Piket']
    && array_slice($dd, 11) === ['Paparan Pemkab', 'Laporan Bulanan', 'Sosialisasi', 'Rapat Pimpinan'], implode(', ', $dd));
$s = $names($ribbon($dindik, ['app' => 'slides']));
$cek('?app=slides: hanya 4 Slides', $s === ['Paparan Pemkab', 'Laporan Bulanan', 'Sosialisasi', 'Rapat Pimpinan']);
$call('PATCH', '/templates/'.$nota->id, [], $tManage, [], ['actor' => $admin, 'sort_order' => 999]);
$cek('urutan diubah admin: Nota Dinas ke akhir Docs', ($x = $names($ribbon($tanpaOpd, ['app' => 'docs']))) && end($x) === 'Nota Dinas', implode(', ', $x));
$call('PATCH', '/templates/'.$nota->id, [], $tManage, [], ['actor' => $admin, 'status' => 'inactive']);
$cek('Nonaktif hilang dari pita', ! in_array('Nota Dinas', $names($ribbon($kominfo)), true));
$a = $ribbon($admin);
$cek('admin: Draf ikut tampil untuk dicoba (AC-3), meta drafts_included', in_array('Nota Dinas Bupati', $names($a), true) && $body($a)['meta']['drafts_included'] === true);
$cek('admin: Nonaktif tetap tidak tampil di pita', ! in_array('Nota Dinas', $names($a), true));
$cek('pegawai: meta drafts_included false', $body($ribbon($kominfo))['meta']['drafts_included'] === false);

echo "\n=== daftar kelola ===\n";
$r = $call('GET', '/templates/manage', ['actor' => $admin], $tManage);
$all = $names($r);
$cek('semua status termasuk Draf & Nonaktif', in_array('Nota Dinas Bupati', $all, true) && in_array('Nota Dinas', $all, true) && count($all) === Template::count(), (string) count($all));
$cek('meta: total + max_upload_mb', $body($r)['meta'] === ['total' => Template::count(), 'max_upload_mb' => 20]);
$cek('?status=draft hanya Draf', collect($body($call('GET', '/templates/manage', ['actor' => $admin, 'status' => 'draft'], $tManage))['data'])->every(fn ($x) => $x['status'] === 'draft'));

echo "\n=== ubah metadata ===\n";
$r = $call('PATCH', '/templates/'.$opd8, [], $tManage, [], ['actor' => $admin, 'scope' => 'all', 'opd_id' => 8, 'name' => '  Surat Umum  ', 'description' => '']);
$cek('cakupan ke semua OPD: opd dikosongkan, nama dirapikan', $r->getStatusCode() === 200 && $body($r)['data']['scope'] === 'all' && $body($r)['data']['opd'] === null && $body($r)['data']['name'] === 'Surat Umum' && $body($r)['data']['description'] === null, $r->getContent());
$cek('cakupan opd tanpa opd_id: 422', $call('PATCH', '/templates/'.$opd8, [], $tManage, [], ['actor' => $admin, 'scope' => 'opd'])->getStatusCode() === 422);
$cek('status tak dikenal: 422', $call('PATCH', '/templates/'.$opd8, [], $tManage, [], ['actor' => $admin, 'status' => 'published'])->getStatusCode() === 422);
$cek('id bukan UUID: 404', $call('PATCH', '/templates/123', [], $tManage, [], ['actor' => $admin, 'name' => 'x'])->getStatusCode() === 404);
$cek('UUID tak ada: 404 not_found', ($r = $call('PATCH', '/templates/'.Str::uuid(), [], $tManage, [], ['actor' => $admin, 'name' => 'x']))->getStatusCode() === 404 && $body($r)['error']['code'] === 'not_found');
$r = $call('PATCH', '/templates/'.$nota->id, [], $tManage, [], ['actor' => $admin, 'name' => 'Nota Dinas (lama)', 'status' => 'active', 'sort_order' => 10]);
$cek('bawaan: metadata boleh diubah', $r->getStatusCode() === 200 && $body($r)['data']['name'] === 'Nota Dinas (lama)');
$again = (new Nawasara\Workspace\Database\Seeders\BuiltinTemplateSeeder($nc))->seed();
$cek('seeder diulang tidak membatalkan pilihan admin', Template::find($nota->id)->name === 'Nota Dinas (lama)' && $again['rows_created'] === 0 && Template::whereNotNull('builtin_key')->count() === 14, json_encode($again));

echo "\n=== ganti berkas ===\n";
$tpl = Template::find($drafId);
$idLama = $tpl->file_id;
$r = $call('POST', '/templates/'.$drafId.'/file', ['actor' => $admin], $tManage, ['file' => $upload($src.'Notulen Rapat.docx', 'versi2.docx')]);
$cek('200, isi diganti di jalur yang sama, file_id tetap', $r->getStatusCode() === 200 && $nc->downloadFile('Templat Kisara/Nota Dinas Bupati.docx') === file_get_contents($src.'Notulen Rapat.docx') && $body($r)['data']['file_id'] === $idLama, $r->getContent());
$cek('ekstensi lain: 422', $call('POST', '/templates/'.$drafId.'/file', ['actor' => $admin], $tManage, ['file' => $upload($src.'Daftar Hadir.xlsx', 'x.xlsx')])->getStatusCode() === 422);
$cek('berkas bawaan tidak bisa diganti: 409 builtin_template', ($r = $call('POST', '/templates/'.$nota->id.'/file', ['actor' => $admin], $tManage, ['file' => $docx()]))->getStatusCode() === 409 && $body($r)['error']['code'] === 'builtin_template');

echo "\n=== hapus (FR-16 AC-5) ===\n";
// Pegawai sudah membuat berkas dari templat ini: salinan di Drive-nya.
$nc->files[$kominfo.'/Tanpa judul.docx'] = ['id' => 9001, 'body' => $nc->downloadFile('Templat Kisara/Nota Dinas Bupati.docx')];
$nc->deleted = [];
$r = $call('DELETE', '/templates/'.$drafId, ['actor' => $admin], $tManage);
$cek('200 {id, deleted}', $r->getStatusCode() === 200 && $body($r)['data'] === ['id' => $drafId, 'deleted' => true], $r->getContent());
$cek('baris hilang, berkas templat hilang', Template::find($drafId) === null && $nc->downloadFile('Templat Kisara/Nota Dinas Bupati.docx') === null);
$cek('hanya berkas templat itu yang dihapus', $nc->deleted === ['Templat Kisara/Nota Dinas Bupati.docx'], json_encode($nc->deleted));
$cek('berkas buatan pegawai tidak tersentuh', isset($nc->files[$kominfo.'/Tanpa judul.docx']) && $nc->files[$kominfo.'/Tanpa judul.docx']['body'] === file_get_contents($src.'Notulen Rapat.docx'));
$cek('templat lain tetap', Template::find($opd7) !== null && $nc->fileId('Templat Kisara/Nota Dinas Bupati (2).docx') !== null);
$cek('bawaan tidak bisa dihapus: 409', $call('DELETE', '/templates/'.$nota->id, ['actor' => $admin], $tManage)->getStatusCode() === 409 && Template::find($nota->id) !== null);
$nc->down = true;
$cek('Nextcloud gagal: 502 dan baris tetap (bisa diulang)', $call('DELETE', '/templates/'.$opd7, ['actor' => $admin], $tManage)->getStatusCode() === 502 && Template::find($opd7) !== null);
$nc->down = false;
unset($nc->files['Templat Kisara/'.Template::find($sampah)->file_name]);
$cek('berkas sudah hilang di Nextcloud: hapus tetap berhasil', $call('DELETE', '/templates/'.$sampah, ['actor' => $admin], $tManage)->getStatusCode() === 200 && Template::find($sampah) === null);
$cek('hapus kedua kali: 404', $call('DELETE', '/templates/'.$drafId, ['actor' => $admin], $tManage)->getStatusCode() === 404);

echo "\n=== access API: is_admin dari aturan yang sama ===\n";
PilotMember::create(['nip' => $admin, 'name' => 'Admin', 'stage' => 0]);
$tAkses = $tokens->create('uji akses', ['workspace.access.read'])['plaintext'];
$cek('is_admin true untuk admin Kisara', $body($call('GET', '/access/'.$admin, [], $tAkses))['data']['is_admin'] === true);
PilotMember::create(['nip' => $bukan, 'name' => 'Bukan', 'stage' => 0]);
$cek('is_admin false untuk akun lokal tanpa izin', $body($call('GET', '/access/'.$bukan, [], $tAkses))['data']['is_admin'] === false);

array_map('unlink', glob($tmpDir.'/*') ?: []);
@rmdir($tmpDir);

echo "\n".($gagal === 0 ? 'SEMUA LULUS' : "ADA {$gagal} YANG GAGAL")."\n";
exit($gagal === 0 ? 0 : 1);
