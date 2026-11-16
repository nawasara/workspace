<?php
/*
 * Uji T-21: BuiltinTemplateSeeder terhadap Nextcloud tiruan di tingkat HTTP
 * (Http::fake), jadi NextcloudAdminClient yang asli ikut teruji: jalur
 * groupfolders, OCS grup, dan WebDAV (GET/PUT/PROPFIND/DELETE) beserta hak
 * tulis per grup. Sejak T-31 juga baris metadata (14 templat bawaan) di
 * SQLite di memori.
 */

$root = dirname(__DIR__, 4);
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$app['config']->set('database.default', 'uji');
$app['config']->set('database.connections.uji', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
$app['db']->setDefaultConnection('uji');
$app['config']->set('nawasara-workspace.sync_on_change', false);

use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Nawasara\Workspace\Models\Template;
use Nawasara\Workspace\Database\Seeders\BuiltinTemplateSeeder;
use Nawasara\Workspace\Services\NextcloudAdminClient;
use Nawasara\Workspace\Services\NextcloudException;

$gagal = 0;
$cek = function (string $label, bool $ok, string $detail = '') use (&$gagal) {
    if (! $ok) { $gagal++; }
    echo ($ok ? '  OK   ' : '  GAGAL')." {$label}".($detail ? "\n         {$detail}" : '')."\n";
};

const BASE = 'http://10.1.1.37:8080/nc';

Schema::create('users', function ($t) { $t->id(); $t->string('name'); $t->string('username')->nullable(); $t->timestamps(); });
Schema::create('nawasara_registry_opd', function ($t) { $t->id(); $t->string('code'); $t->string('name'); $t->timestamps(); });
Schema::create('activity_log', function ($t) {
    $t->id(); $t->string('log_name')->nullable(); $t->text('description');
    $t->nullableMorphs('subject'); $t->nullableMorphs('causer'); $t->json('properties')->nullable();
    $t->uuid('batch_uuid')->nullable(); $t->string('event')->nullable(); $t->timestamps();
});
foreach (glob($root.'/packages/nawasara-workspace/database/migrations/*.php') as $f) { (require $f)->up(); }

/** Nextcloud tiruan: grup, keanggotaan, Team Folders, dan berkas per Team Folder. */
class NcHttpPalsu
{
    public array $userGroups = ['kisara-admin' => ['admin'], 'pegawai-uji' => ['OPD-7', 'workspace-pilot']];
    public array $groups = ['admin' => true, 'OPD-7' => true, 'OPD-8' => true];
    public array $folders = [
        1 => ['mount_point' => 'Diskominfo', 'groups' => ['OPD-7' => 31], 'quota' => -3, 'acl' => true, 'files' => []],
        2 => ['mount_point' => 'Dinas Pendidikan', 'groups' => ['OPD-8' => 31], 'quota' => -3, 'acl' => true, 'files' => []],
    ];
    public array $writes = [];
    public array $failPut = [];
    public array $fileIds = []; // "mount/file" => Nextcloud file id, kept on overwrite
    private int $nextId = 3;
    private int $nextFileId = 1000;

    public function handle(Request $r)
    {
        $m = $r->method();
        $path = substr(parse_url($r->url(), PHP_URL_PATH), strlen('/nc'));
        $user = explode(':', base64_decode(substr($r->header('Authorization')[0] ?? '', 6)))[0];

        if (str_starts_with($path, '/remote.php/dav/files/')) {
            return $this->dav($m, $path, $user, $r);
        }
        if ($m !== 'GET') {
            $this->writes[] = "{$m} {$path} ".json_encode($r->data());
        }
        if ($path === '/index.php/apps/groupfolders/folders') {
            if ($m === 'GET') {
                $out = [];
                foreach ($this->folders as $id => $f) {
                    $out[$id] = ['id' => $id, 'mount_point' => $f['mount_point'], 'groups' => $f['groups'], 'quota' => $f['quota'], 'acl' => $f['acl']];
                }
                return $this->ok($out, 100);
            }
            $id = $this->nextId++;
            $this->folders[$id] = ['mount_point' => $r->data()['mountpoint'], 'groups' => [], 'quota' => -3, 'acl' => false, 'files' => []];
            return $this->ok(['id' => $id], 100);
        }
        if (preg_match('#^/index.php/apps/groupfolders/folders/(\d+)/groups$#', $path, $x)) {
            $this->folders[(int) $x[1]]['groups'][$r->data()['group']] = 31;
            return $this->ok(['success' => true], 100);
        }
        if (preg_match('#^/index.php/apps/groupfolders/folders/(\d+)/groups/([^/]+)$#', $path, $x)) {
            $this->folders[(int) $x[1]]['groups'][rawurldecode($x[2])] = (int) $r->data()['permissions'];
            return $this->ok(['success' => true], 100);
        }
        if ($path === '/ocs/v2.php/cloud/groups') {
            $gid = $r->data()['groupid'];
            if (isset($this->groups[$gid])) {
                return Http::response(['ocs' => ['meta' => ['status' => 'failure', 'statuscode' => 102, 'message' => 'group exists'], 'data' => []]], 400);
            }
            $this->groups[$gid] = true;
            return $this->ok([]);
        }
        if (preg_match('#^/ocs/v2.php/cloud/users/([^/]+)/groups$#', $path, $x)) {
            $uid = rawurldecode($x[1]);
            if ($m === 'GET') {
                return $this->ok(['groups' => $this->userGroups[$uid] ?? []]);
            }
            $this->userGroups[$uid][] = $r->data()['groupid'];
            return $this->ok([]);
        }

        return Http::response('tak dikenal: '.$m.' '.$path, 500);
    }

    /** Hak user atas sebuah Team Folder = maksimum hak grup-grupnya (seperti groupfolders). */
    public function perm(string $user, int $id): int
    {
        $p = 0;
        foreach ($this->userGroups[$user] ?? [] as $g) {
            $p |= $this->folders[$id]['groups'][$g] ?? 0;
        }
        return $p;
    }

    private function dav(string $m, string $path, string $user, Request $r)
    {
        $parts = array_map('rawurldecode', explode('/', substr($path, strlen('/remote.php/dav/files/'))));
        [$owner, $mount, $file] = $parts + [null, null, null];
        if ($owner !== $user) {
            return Http::response('', 403);
        }
        $id = null;
        foreach ($this->folders as $fid => $f) {
            if ($f['mount_point'] === $mount && $this->perm($user, $fid) > 0) { $id = $fid; }
        }
        if ($id === null) {
            return Http::response('', 404); // folder not mounted for this user
        }
        if ($m === 'GET') {
            return isset($this->folders[$id]['files'][$file]) ? Http::response($this->folders[$id]['files'][$file]) : Http::response('', 404);
        }
        if ($m === 'PROPFIND') {
            if (! isset($this->folders[$id]['files'][$file])) {
                return Http::response('', 404);
            }
            $fid = $this->fileIds["{$mount}/{$file}"];
            return Http::response('<?xml version="1.0"?><d:multistatus xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns"><d:response>'
                ."<d:href>/x</d:href><d:propstat><d:prop><oc:fileid>{$fid}</oc:fileid></d:prop><d:status>HTTP/1.1 200 OK</d:status></d:propstat></d:response></d:multistatus>", 207);
        }
        if ($m === 'DELETE') {
            if (! isset($this->folders[$id]['files'][$file])) {
                return Http::response('', 404);
            }
            if (($this->perm($user, $id) & 8) === 0) {
                return Http::response('', 403);
            }
            $this->writes[] = "DELETE {$mount}/{$file}";
            unset($this->folders[$id]['files'][$file]);
            return Http::response('', 204);
        }
        if ($m === 'PUT') {
            if (in_array($file, $this->failPut, true)) {
                return Http::response('', 507);
            }
            if (($this->perm($user, $id) & 2) === 0) {
                return Http::response('', 403); // no write permission
            }
            $this->writes[] = "PUT {$mount}/{$file}";
            $existed = isset($this->folders[$id]['files'][$file]);
            $this->folders[$id]['files'][$file] = $r->body();
            $this->fileIds["{$mount}/{$file}"] ??= $this->nextFileId++;
            return Http::response('', $existed ? 204 : 201);
        }
        return Http::response('', 405);
    }

    private function ok($data, int $code = 200)
    {
        return Http::response(['ocs' => ['meta' => ['status' => 'ok', 'statuscode' => $code, 'message' => 'OK'], 'data' => $data]]);
    }

    public function templat(): ?array
    {
        foreach ($this->folders as $id => $f) {
            if ($f['mount_point'] === 'Templat Kisara') { return ['id' => $id] + $f; }
        }
        return null;
    }
}

$nc = new NcHttpPalsu;
$sent = [];
Http::swap(new Factory);
Http::fake(function (Request $r) use ($nc, &$sent) { $sent[] = $r; return $nc->handle($r); });
Log::spy();

$admin = new NextcloudAdminClient(BASE, 'kisara-admin', 'pw');
$seeder = fn ($client = null) => new BuiltinTemplateSeeder($client ?? $admin);

echo "=== berkas sumber ===\n";
$src = $seeder()->sourceFiles();
$names = array_map('basename', $src);
$cek('14 berkas templat di resources/templates', count($src) === 14, implode(', ', $names));
$wanted = ['Nota Dinas.docx', 'Surat Undangan.docx', 'Notulen Rapat.docx', 'Laporan Kegiatan.docx', 'Telaahan Staf.docx',
    'Anggaran Kegiatan.xlsx', 'Daftar Hadir.xlsx', 'Rekap Absensi.xlsx', 'Inventaris Aset.xlsx', 'Jadwal Piket.xlsx',
    'Paparan Pemkab.pptx', 'Laporan Bulanan.pptx', 'Sosialisasi.pptx', 'Rapat Pimpinan.pptx'];
$cek('nama sesuai FR-15 AC-1 (5 Docs, 5 Sheets, 4 Slides)', array_diff($wanted, $names) === [] && array_diff($names, $wanted) === []);
$tandai = true;
foreach ($src as $f) {
    $z = new ZipArchive;
    if ($z->open($f) !== true || ! str_contains((string) $z->getFromName('docProps/core.xml'), 'Kisara (bawaan)')) { $tandai = false; }
    $z->close();
}
$cek('tiap berkas zip OOXML sah dan bertanda "Kisara (bawaan)"', $tandai);

echo "\n=== putaran pertama ===\n";
$s = $seeder()->seed();
$t = $nc->templat();
$jumlahTemplat = count(array_filter($nc->folders, fn ($f) => $f['mount_point'] === 'Templat Kisara'));
$cek('folder "Templat Kisara" dibuat sekali', $s['folder_created'] === true && $jumlahTemplat === 1, json_encode($s));
$cek('14 unggahan, 14 berkas di folder', $s['created'] === 14 && count($t['files']) === 14 && $s['failed'] === 0);
$cek('isi tersimpan sama persis dengan sumber', $t['files']['Nota Dinas.docx'] === file_get_contents($src[array_search('Nota Dinas.docx', $names)]));
$cek('grup: admin 31, pilot + semua OPD-* hanya baca (1)',
    $t['groups'] === ['admin' => 31, 'workspace-pilot' => 1, 'OPD-7' => 1, 'OPD-8' => 1], json_encode($t['groups']));
$cek('grup workspace-pilot dipastikan ada', isset($nc->groups['workspace-pilot']));
$put = array_values(array_filter($sent, fn ($r) => $r->method() === 'PUT'));
$cek('WebDAV PUT ke berkas admin, jalur di-encode', ($put[0] ?? null) && str_starts_with($put[0]->url(), BASE.'/remote.php/dav/files/kisara-admin/Templat%20Kisara/') && str_contains($put[0]->url(), '%20'), $put[0]?->url() ?? '-');
$rows = Template::orderBy('app')->orderBy('sort_order')->get();
$cek('14 baris metadata dibuat (T-31)', $s['rows_created'] === 14 && $rows->count() === 14, json_encode($s));
$cek('semua Aktif, untuk semua OPD, bertanda bawaan', $rows->every(fn ($t) => $t->status === 'active' && $t->scope === 'all' && $t->opd_id === null && $t->isBuiltin()));
$nd = Template::where('builtin_key', 'nota-dinas')->first();
$cek('kunci stabil = slug nama berkas; UUID', $nd && $nd->name === 'Nota Dinas' && $nd->app === 'docs' && Illuminate\Support\Str::isUuid($nd->id));
$cek('file_id dari PROPFIND, ukuran sesuai', $nd->file_id === $nc->fileIds['Templat Kisara/Nota Dinas.docx'] && $nd->size_bytes === strlen($t['files']['Nota Dinas.docx']));
$cek('urutan FR-15 per app (10, 20, ...)',
    Template::where('app', 'docs')->orderBy('sort_order')->pluck('name')->all() === ['Nota Dinas', 'Surat Undangan', 'Notulen Rapat', 'Laporan Kegiatan', 'Telaahan Staf']
    && Template::where('app', 'slides')->orderBy('sort_order')->pluck('sort_order')->all() === [110, 120, 130, 140]
    && Template::where('app', 'sheets')->count() === 5);
$cek('Content-Type OOXML yang benar', ($put[0] ?? null) && str_starts_with($put[0]->header('Content-Type')[0] ?? '', 'application/vnd.openxmlformats-officedocument.'));

echo "\n=== putaran kedua: idempoten ===\n";
$nc->writes = [];
Template::where('builtin_key', 'nota-dinas')->update(['name' => 'Nota Dinas (diubah admin)', 'status' => 'inactive', 'sort_order' => 99]);
$sent = [];
$s = $seeder()->seed();
$cek('tanpa tulisan apa pun ke Nextcloud', $nc->writes === [] && $s['unchanged'] === 14 && $s['folder_created'] === false && $s['groups_fixed'] === 0, json_encode($nc->writes));
$cek('tanpa PROPFIND dan tanpa baris baru/diubah', ! collect($sent)->contains(fn ($r) => $r->method() === 'PROPFIND') && $s['rows_created'] === 0 && $s['rows_updated'] === 0 && Template::count() === 14, json_encode($s));
$nd = Template::where('builtin_key', 'nota-dinas')->first();
$cek('pilihan admin (nama, status, urutan) tidak dibatalkan', $nd->name === 'Nota Dinas (diubah admin)' && $nd->status === 'inactive' && $nd->sort_order === 99);

echo "\n=== berkas sumber berubah ===\n";
$tmp = sys_get_temp_dir().'/uji-templat-'.getmypid();
@mkdir($tmp);
foreach ($src as $f) { copy($f, $tmp.'/'.basename($f)); }
file_put_contents($tmp.'/Nota Dinas.docx', file_get_contents($tmp.'/Nota Dinas.docx').'x'); // isi beda = hash beda
$ubah = new class($admin, $tmp) extends BuiltinTemplateSeeder {
    public function __construct(NextcloudAdminClient $nc, private string $dir) { parent::__construct($nc); }
    public function sourceFiles(): array { $f = glob($this->dir.'/*'); sort($f); return $f; }
};
$nc->writes = [];
$s = $ubah->seed();
$cek('hanya berkas yang berubah diunggah ulang', $s['updated'] === 1 && $s['unchanged'] === 13 && $nc->writes === ['PUT Templat Kisara/Nota Dinas.docx'], json_encode($nc->writes));
$cek('isi di Nextcloud kini versi baru', str_ends_with($nc->templat()['files']['Nota Dinas.docx'], 'x'));
$nd2 = Template::where('builtin_key', 'nota-dinas')->first();
$cek('baris: ukuran diperbarui, file_id tetap, pilihan admin tetap', $s['rows_updated'] === 1 && $nd2->size_bytes === $nd->size_bytes + 1 && $nd2->file_id === $nd->file_id && $nd2->name === 'Nota Dinas (diubah admin)', json_encode($s));
array_map('unlink', glob($tmp.'/*'));
@rmdir($tmp);

echo "\n=== hak akses ===\n";
$id = $nc->templat()['id'];
$nc->folders[$id]['groups']['OPD-7'] = 31;            // seseorang memberi hak tulis
$nc->folders[$id]['groups']['grup-lain'] = 15;          // grup tambahan manual
$nc->writes = [];
$s = $seeder()->seed();
$g = $nc->templat()['groups'];
$cek('hak tulis OPD dikembalikan ke baca saja', $g['OPD-7'] === 1 && $s['groups_fixed'] === 2, json_encode($s));
$cek('grup lain yang ada tetap, tapi baca saja', $g['grup-lain'] === 1);
$cek('Nota Dinas versi sumber asli dikembalikan', $s['updated'] === 1);

$pegawai = new NextcloudAdminClient(BASE, 'pegawai-uji', 'pw');
$cek('pegawai (anggota OPD) dapat membaca templat', $pegawai->downloadFile('Templat Kisara/Nota Dinas.docx') !== null);
$tolak = null;
try { $pegawai->uploadFile('Templat Kisara/Nota Dinas.docx', 'rusak'); } catch (NextcloudException $e) { $tolak = $e->httpStatus; }
$cek('pegawai TIDAK dapat menulis (403)', $tolak === 403);
$cek('akun tanpa grup tidak melihat folder (404)', (new NextcloudAdminClient(BASE, 'orang-luar', 'pw'))->downloadFile('Templat Kisara/Nota Dinas.docx') === null);

echo "\n=== klien: fileId dan deleteFile (T-31) ===\n";
$cek('fileId berkas ada = id Nextcloud', $admin->fileId('Templat Kisara/Daftar Hadir.xlsx') === $nc->fileIds['Templat Kisara/Daftar Hadir.xlsx']);
$cek('fileId berkas tidak ada = null', $admin->fileId('Templat Kisara/tidak-ada.docx') === null);
$propfind = collect($sent)->last(fn ($r) => $r->method() === 'PROPFIND');
$cek('PROPFIND Depth 0 meminta oc:fileid', $propfind && ($propfind->header('Depth')[0] ?? null) === '0' && str_contains($propfind->body(), 'oc:fileid'));
$admin->uploadFile('Templat Kisara/Hapus Saya.docx', 'x');
$cek('deleteFile menghapus (true), kedua kali tidak ada (false)', $admin->deleteFile('Templat Kisara/Hapus Saya.docx') === true && $admin->deleteFile('Templat Kisara/Hapus Saya.docx') === false && ! isset($nc->templat()['files']['Hapus Saya.docx']));
$tolak = null;
try { $pegawai->deleteFile('Templat Kisara/Nota Dinas.docx'); } catch (NextcloudException $e) { $tolak = $e->httpStatus; }
$cek('pegawai TIDAK dapat menghapus templat (403)', $tolak === 403 && isset($nc->templat()['files']['Nota Dinas.docx']));

echo "\n=== admin di luar grup admin ===\n";
$nc->userGroups['kisara-admin'] = [];
$nc->writes = [];
$s = $seeder()->seed();
$cek('admin dimasukkan ke grup admin agar folder terlihat', in_array('admin', $nc->userGroups['kisara-admin'], true) && $s['unchanged'] === 14, json_encode($s));

echo "\n=== galat & belum dikonfigurasi ===\n";
$nc->folders[$id]['files']['Daftar Hadir.xlsx'] = 'lama';
$nc->failPut = ['Daftar Hadir.xlsx'];
$s = $seeder()->seed();
$cek('satu unggahan gagal: dihitung, sisanya tetap jalan', $s['failed'] === 1 && $s['unchanged'] === 13, json_encode($s));
$nc->failPut = [];
$sent = [];
$s = $seeder(new NextcloudAdminClient('', '', ''))->seed();
$cek('Vault kosong: dilewati tanpa permintaan HTTP', $s['configured'] === false && $sent === []);

$lempar = false;
Http::swap(new Factory);
Http::fake(fn () => Http::response(['ocs' => ['meta' => ['status' => 'failure', 'statuscode' => 997], 'data' => []]], 401));
try { $seeder()->seed(); } catch (NextcloudException) { $lempar = true; }
$cek('folder gagal (401): galat dilempar, bukan "berhasil" diam-diam', $lempar);

echo "\n=== log ===\n";
$adaLog = function (string $level, string $event) {
    try { Log::shouldHaveReceived($level)->with($event, Mockery::any()); return true; } catch (Throwable) { return false; }
};
$cek('seed_started / seed_finished / folder_created / uploaded tercatat',
    $adaLog('info', 'workspace.templates.seed_started') && $adaLog('info', 'workspace.templates.seed_finished')
    && $adaLog('info', 'workspace.templates.folder_created') && $adaLog('info', 'workspace.templates.uploaded'));
$cek('upload_failed (warning), seed_skipped (warning), seed_failed (error) tercatat',
    $adaLog('warning', 'workspace.templates.upload_failed') && $adaLog('warning', 'workspace.templates.seed_skipped')
    && $adaLog('error', 'workspace.templates.seed_failed'));

echo "\n".($gagal === 0 ? 'SEMUA LULUS' : "ADA {$gagal} YANG GAGAL")."\n";
exit($gagal === 0 ? 0 : 1);
