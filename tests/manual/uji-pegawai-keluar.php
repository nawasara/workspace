<?php
/*
 * Uji T-33 Pegawai Keluar (FR-18) dengan Nextcloud tiruan di memori. SQLite
 * di memori, lewat remote-test.
 *
 * Yang diuji:
 *  - akun Kisara nonaktif → akun Nextcloud dinonaktifkan, masuk antrean
 *    Ditahan 90 hari; putaran kedua tidak mengantre ganda (AC-1, AC-2)
 *  - sinkron akun tidak menulis / mengaktifkan akun yang sedang ditahan
 *  - aktif lagi sebelum tenggat → akun diaktifkan, antrean batal (AC-5)
 *  - pindahkan ke Folder OPD tercatat (siapa, kapan, ke mana) (AC-3)
 *  - peringatan H-7 sekali saja; tenggat lewat → akun dihapus, Folder OPD
 *    tidak tersentuh; tenggat dimajukan tetap memberi 7 hari (AC-4)
 *  - batal manual tidak diantre ulang; hapus sekarang butuh sudo
 *  - halaman: baris di dalam x-slot:table, dua empty state
 *  - job: siklus hidup tercatat di nawasara/sync
 *  - klien asli (HTTP tiruan): pemindahan sebagai pegawai, akun selalu
 *    dinonaktifkan lagi dan sandinya diganti, meski gagal
 */

$root = dirname(__DIR__, 4);
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$app['config']->set('database.default', 'uji');
$app['config']->set('database.connections.uji', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]);
$app['db']->setDefaultConnection('uji');
$app['config']->set('cache.default', 'array');
$app['config']->set('session.driver', 'array');
$app['config']->set('nawasara-workspace.sync_on_change', false);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Nawasara\AuthPrimitives\Auth\Sudo;
use Nawasara\Sync\Events\SyncJobSucceeded;
use Nawasara\Sync\Models\SyncJob;
use Nawasara\Workspace\Jobs\SyncWorkspaceOffboardingJob;
use Nawasara\Workspace\Livewire\Offboarding\Section\Table;
use Nawasara\Workspace\Models\Account;
use Nawasara\Workspace\Models\Offboarding;
use Nawasara\Workspace\Models\OpdFolder;
use Nawasara\Workspace\Services\AccountProvisioner;
use Nawasara\Workspace\Services\NextcloudAdminClient;
use Nawasara\Workspace\Services\NextcloudException;
use Nawasara\Workspace\Services\OffboardingManager;
use Nawasara\Workspace\Services\OffboardingNotifier;
use Nawasara\Workspace\Services\OpdResolver;

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
(require $root.'/packages/nawasara-sync/database/migrations/2026_04_25_000001_create_nawasara_sync_jobs_table.php')->up();
(require $root.'/vendor/spatie/laravel-permission/database/migrations/create_permission_tables.php.stub')->up();
foreach (glob($root.'/packages/nawasara-workspace/database/migrations/*.php') as $f) { (require $f)->up(); }
$cek('migrasi antrean pegawai keluar jalan', Schema::hasTable('nawasara_workspace_offboarding'));

/** Nextcloud tiruan: akun dan grup di memori; setiap panggilan Team Folder dicatat terpisah. */
class NcPalsu extends NextcloudAdminClient
{
    public array $users = [];      // uid => [displayname, email, quota, groups[], enabled]
    public array $writes = [];
    public array $folderCalls = [];
    public array $moves = [];

    public function __construct() { parent::__construct('http://x/nc', 'a', 'p'); }
    public function isConfigured(): bool { return true; }
    public function getUser(string $uid): ?array
    {
        return isset($this->users[$uid]) ? ['displayname' => $this->users[$uid]['displayname'], 'email' => $this->users[$uid]['email']] : null;
    }
    public function createUser(string $uid, string $displayName, ?string $email, string $quota, array $groups = []): void
    {
        $this->writes[] = "create {$uid}";
        $this->users[$uid] = ['displayname' => $displayName, 'email' => $email, 'quota' => $quota, 'groups' => $groups, 'enabled' => true];
    }
    public function updateUser(string $uid, string $key, string $value): void { $this->writes[] = "update {$uid} {$key}"; $this->users[$uid][$key] = $value; }
    public function disableUser(string $uid): void { $this->writes[] = "disable {$uid}"; $this->users[$uid]['enabled'] = false; }
    public function enableUser(string $uid): void { $this->writes[] = "enable {$uid}"; $this->users[$uid]['enabled'] = true; }
    public function deleteUser(string $uid): void { $this->writes[] = "delete {$uid}"; unset($this->users[$uid]); }
    public function ensureGroup(string $gid): void {}
    public function userGroups(string $uid): array { return $this->users[$uid]['groups'] ?? []; }
    public function addUserToGroup(string $uid, string $gid): void { $this->writes[] = "add {$uid} {$gid}"; $this->users[$uid]['groups'][] = $gid; }
    public function removeUserFromGroup(string $uid, string $gid): void
    {
        $this->writes[] = "remove {$uid} {$gid}";
        $this->users[$uid]['groups'] = array_values(array_diff($this->users[$uid]['groups'], [$gid]));
    }
    public function moveUserFilesToTeamFolder(string $uid, string $teamFolder, string $targetDir): array
    {
        $this->moves[] = [$uid, $teamFolder, $targetDir, $this->users[$uid]['groups']];
        return ['moved' => 3, 'failed' => []];
    }
    // Folder OPD: apa pun yang menyentuhnya dicatat; harus tetap kosong.
    public function teamFolders(): array { $this->folderCalls[] = 'list'; return []; }
    public function createTeamFolder(string $name): int { $this->folderCalls[] = "create {$name}"; return 1; }
    public function renameTeamFolder(int $id, string $name): void { $this->folderCalls[] = "rename {$id}"; }
    public function deleteTeamFolder(int $id): void { $this->folderCalls[] = "delete {$id}"; }
    public function removeTeamFolderGroup(int $id, string $gid): void { $this->folderCalls[] = "ungroup {$id}"; }
    public function setTeamFolderQuota(int $id, int $bytes): void { $this->folderCalls[] = "quota {$id}"; }
    public function deleteFile(string $path): bool { $this->folderCalls[] = "rm {$path}"; return true; }
}

class NotifierPalsu extends OffboardingNotifier
{
    public array $warned = [];
    public array $cleared = [];
    public function warn(Offboarding $row): void { $this->warned[] = $row->nip; }
    public function clear(Offboarding $row): void { $this->cleared[] = $row->nip; }
}

DB::table('users')->insert(['id' => 1, 'name' => 'Admin Kisara', 'username' => 'admin', 'email' => 'a@x.id']);
DB::table('nawasara_registry_opd')->insert(['id' => 7, 'code' => 'KOMINFO', 'name' => 'Dinas Komunikasi, Informatika dan Statistik']);
OpdFolder::create(['opd_id' => 7, 'nc_folder_id' => 1, 'name' => 'Dinas Komunikasi, Informatika dan Statistik', 'quota_bytes' => 50 * 1024 ** 3]);
$kc = fn ($id, $user, $first) => DB::table('nawasara_keycloak_users')->insert([
    'user_id' => $id, 'username' => $user, 'first_name' => $first, 'enabled' => true,
    'email' => strtolower($first).'@ponorogo.go.id',
    'attributes' => json_encode(['simas_skpd' => ['Dinas Komunikasi, Informatika dan Statistik']]),
    'created_at' => now(), 'updated_at' => now(),
]);
$A = '199506142020121004'; $B = '198604082020121003'; $C = '199001012015011001'; $D = '198001012005011001';
$kc('kc-a', $A, 'Pringgo'); $kc('kc-b', $B, 'Warist'); $kc('kc-c', $C, 'Citra'); $kc('kc-d', $D, 'Dodi');
$setKc = fn (string $nip, bool $on) => DB::table('nawasara_keycloak_users')->where('username', $nip)->update(['enabled' => $on]);

auth()->setUser((config('auth.providers.users.model'))::find(1)); // setUser, bukan login()
Gate::before(fn () => true);

$nc = new NcPalsu;
$notif = new NotifierPalsu;
app()->instance(NextcloudAdminClient::class, $nc);
app()->instance(OffboardingNotifier::class, $notif);
$akun = fn () => (new AccountProvisioner($nc, new OpdResolver))->run();
$keluar = fn () => app(OffboardingManager::class)->run();
$baris = fn (string $nip) => Offboarding::where('nip', $nip)->latest('id')->first();

$s = $akun();
$cek('4 akun dibuat sinkron akun', $s['created'] === 4, json_encode($s));

echo "\n=== AC-1/AC-2: akun Kisara nonaktif → ditahan ===\n";
$setKc($A, false);
$nc->writes = [];
$s = $keluar();
$r = $baris($A);
$cek('1 masuk antrean', $s['queued'] === 1 && Offboarding::count() === 1, json_encode($s));
$cek('akun Nextcloud dinonaktifkan, tidak dihapus', $nc->users[$A]['enabled'] === false && $nc->writes === ["disable {$A}"], json_encode($nc->writes));
$cek('status Ditahan, OPD dan nama ikut', $r->status === 'held' && $r->nc_disabled && $r->opd_id === 7 && $r->display_name === 'Pringgo');
$cek('tenggat 90 hari', abs($r->hold_until->diffInDays($r->disabled_at)) == 90, $r->hold_until.' vs '.$r->disabled_at);
$cek('tercatat di log aktivitas', DB::table('activity_log')->where('description', 'Pegawai keluar: akun Workspace ditahan')->exists());
$nc->writes = [];
$s = $keluar();
$cek('putaran kedua: tidak diantre ganda, tanpa tulisan', Offboarding::count() === 1 && $nc->writes === [] && $s['queued'] === 0, json_encode($s));

echo "\n=== sinkron akun tidak menyentuh akun yang ditahan ===\n";
$setKc($A, true); // aktif lagi, tetapi job pegawai keluar belum jalan
DB::table('nawasara_keycloak_users')->where('username', $A)->update(['first_name' => 'Pringgo Baru']);
$nc->writes = [];
$s = $akun();
$cek('dilewati (held=1), tanpa tulisan, tetap nonaktif', $s['held'] === 1 && $nc->writes === [] && $nc->users[$A]['enabled'] === false, json_encode($s));

echo "\n=== AC-5: aktif lagi sebelum tenggat → batal ===\n";
$s = $keluar();
$r = $baris($A);
$cek('akun Nextcloud diaktifkan', $nc->users[$A]['enabled'] === true && $s['reenabled'] === 1, json_encode($s));
$cek('antrean Dibatalkan (aktif lagi)', $r->status === 'cancelled' && ! $r->nc_disabled && $r->cancel_reason === 'reenabled' && $r->cancelled_at !== null);
$s = $akun();
$cek('sinkron akun kembali mengurus akun itu', $s['held'] === 0 && $nc->users[$A]['displayname'] === 'Pringgo Baru', json_encode($s));
$setKc($A, false);
$keluar();
$cek('nonaktif lagi kemudian → keberangkatan baru (baris kedua)', Offboarding::where('nip', $A)->count() === 2 && $baris($A)->status === 'held');

echo "\n=== AC-3: pindahkan ke Folder OPD ===\n";
$setKc($B, false);
$keluar();
$rb = $baris($B);
Livewire::test(Table::class)->call('moveToOpd', $rb->id);
$rb->refresh();
$cek('dipindah ke Folder OPD-nya, subfolder per pegawai', ($nc->moves[0] ?? null) && $nc->moves[0][1] === 'Dinas Komunikasi, Informatika dan Statistik' && $nc->moves[0][2] === "Arsip Pegawai Keluar/{$B} Warist", json_encode($nc->moves));
$cek('tercatat: siapa, kapan, ke mana, berapa', $rb->moved_by === 1 && $rb->moved_at !== null && $rb->moved_to === "Dinas Komunikasi, Informatika dan Statistik/Arsip Pegawai Keluar/{$B} Warist" && $rb->moved_items === 3);
$cek('status tetap Ditahan', $rb->status === 'held');
// C: sudah tidak di grup OPD-nya → ditambahkan untuk pemindahan, lalu dicabut lagi
$setKc($C, false);
$keluar();
$nc->users[$C]['groups'] = [];
Livewire::test(Table::class)->call('moveToOpd', $baris($C)->id);
$cek('grup OPD dipasang selama pindah, dicabut sesudahnya', ($nc->moves[1][3] ?? []) === ['OPD-7'] && $nc->users[$C]['groups'] === [], json_encode($nc->moves[1] ?? null));
$tanpaOpd = Offboarding::create(['nip' => '197001012000011001', 'display_name' => 'Tanpa OPD', 'status' => 'held', 'nc_disabled' => true, 'disabled_at' => now(), 'hold_until' => now()->addDays(90)]);
Livewire::test(Table::class)->call('moveToOpd', $tanpaOpd->id);
$cek('tanpa Folder OPD: ditolak, tidak tercatat dipindah', $tanpaOpd->fresh()->moved_at === null && count($nc->moves) === 2);
$tanpaOpd->update(['status' => 'cancelled', 'nc_disabled' => false]);

echo "\n=== AC-4: peringatan H-7 sekali, lalu hapus ===\n";
$rb->update(['hold_until' => now()->addDays(6)]);
$s = $keluar();
$cek('peringatan terkirim untuk B', $notif->warned === [$B] && $rb->fresh()->warned_at !== null, json_encode($notif->warned));
$keluar();
$cek('tidak diulang di putaran berikutnya', $notif->warned === [$B]);
$cek('belum dihapus sebelum tenggat', isset($nc->users[$B]) && $rb->fresh()->status === 'held');
Carbon::setTestNow(now()->addDays(7));
$nc->writes = []; $nc->folderCalls = [];
$s = $keluar();
$rb->refresh();
$cek('tenggat lewat: akun + Drive Saya dihapus', ! isset($nc->users[$B]) && in_array("delete {$B}", $nc->writes, true) && $s['deleted'] >= 1, json_encode($s));
$cek('Folder OPD tidak tersentuh (tanpa panggilan Team Folder)', $nc->folderCalls === [], json_encode($nc->folderCalls));
$cek('status Dihapus, terjadwal (deleted_by kosong)', $rb->status === 'deleted' && $rb->deleted_at_nc !== null && $rb->deleted_by === null && ! $rb->nc_disabled);
$cek('baris akun lokal dibuang, alert diselesaikan', ! Account::where('nip', $B)->exists() && in_array($B, $notif->cleared, true));
$s = $akun();
$cek('sinkron akun tidak membuat ulang (Kisara masih nonaktif)', ! isset($nc->users[$B]), json_encode($s));
$keluar();
$cek('tidak diantre lagi setelah dihapus', Offboarding::where('nip', $B)->count() === 1);
Carbon::setTestNow();

echo "\n=== tenggat dimajukan tanpa peringatan → tetap 7 hari ===\n";
$rc = $baris($C);
$rc->update(['hold_until' => now()->subDay()]);
$s = $keluar();
$cek('diperingatkan dulu, belum dihapus', isset($nc->users[$C]) && $rc->fresh()->warned_at !== null && $rc->fresh()->status === 'held', json_encode($s));
Carbon::setTestNow(now()->addDays(7));
$keluar();
$cek('7 hari setelah peringatan: dihapus', ! isset($nc->users[$C]) && $rc->fresh()->status === 'deleted');
Carbon::setTestNow();

echo "\n=== batal manual ===\n";
$setKc($D, false);
$keluar();
$rd = $baris($D);
Livewire::test(Table::class)->call('cancel', $rd->id);
$rd->refresh();
$cek('Dibatalkan oleh admin, akun tetap nonaktif', $rd->status === 'cancelled' && $rd->cancel_reason === 'manual' && $rd->cancelled_by === 1 && $nc->users[$D]['enabled'] === false && $rd->nc_disabled);
$keluar();
$cek('tidak diantre ulang selama Kisara nonaktif', Offboarding::where('nip', $D)->count() === 1);
$setKc($D, true);
$keluar();
$cek('Kisara aktif lagi → akun Nextcloud diaktifkan', $nc->users[$D]['enabled'] === true && ! $rd->fresh()->nc_disabled);

echo "\n=== hapus sekarang butuh sudo ===\n";
$ra = $baris($A);
Livewire::test(Table::class)->call('deleteNow', $ra->id);
$cek('tanpa sudo: tidak dihapus', $ra->fresh()->status === 'held' && isset($nc->users[$A]));
Sudo::confirm(1);
Livewire::test(Table::class)->call('deleteNow', $ra->id);
$ra->refresh();
$cek('dengan sudo: dihapus, pelaku tercatat', $ra->status === 'deleted' && $ra->deleted_by === 1 && ! isset($nc->users[$A]), $ra->status.' '.json_encode($ra->last_error));
Sudo::forget();

echo "\n=== halaman dirender ===\n";
$html = Livewire::test(Table::class)->html();
$cek('baris ada di dalam x-slot:table (tbody)', preg_match('#<tbody[^>]*>.*'.$A.'.*</tbody>#s', $html) === 1);
$cek('pemindahan ditampilkan', str_contains($html, 'Arsip Pegawai Keluar/'.$B));
$html = Livewire::test(Table::class)->set('search', 'tidak-ada-siapa-pun')->html();
$cek('empty state pencarian', str_contains($html, 'Tidak ada yang cocok'));
$html = Livewire::test(Table::class)->set('statusFilter', 'held')->html();
$cek('saring status Ditahan: kosong kini → empty state pencarian', str_contains($html, 'Tidak ada yang cocok'));
Offboarding::query()->delete();
$html = Livewire::test(Table::class)->html();
$cek('empty state tanpa data', str_contains($html, 'Belum ada pegawai keluar'));

echo "\n=== job: siklus hidup tercatat ===\n";
Event::fake([SyncJobSucceeded::class]);
$setKc($D, false);
(new SyncWorkspaceOffboardingJob(triggerSource: 'manual'))->handle();
$sj = SyncJob::where('action', 'sync_offboarding')->latest('id')->first();
$cek('SyncJob sukses dengan hitungan', $sj && $sj->status === SyncJob::STATUS_SUCCESS && ($sj->result['queued'] ?? null) === 1, json_encode($sj?->result));

echo "\n=== klien asli: pindah sebagai pegawai (HTTP tiruan) ===\n";
$xml = '<?xml version="1.0"?><d:multistatus xmlns:d="DAV:" xmlns:nc="http://nextcloud.org/ns">'
    .'<d:response><d:href>/nc/remote.php/dav/files/'.$C.'/</d:href><d:propstat><d:prop><nc:mount-type/></d:prop></d:propstat></d:response>'
    .'<d:response><d:href>/nc/remote.php/dav/files/'.$C.'/Laporan%20Akhir.docx</d:href><d:propstat><d:prop><nc:mount-type></nc:mount-type></d:prop></d:propstat></d:response>'
    .'<d:response><d:href>/nc/remote.php/dav/files/'.$C.'/Foto/</d:href><d:propstat><d:prop><nc:mount-type/></d:prop></d:propstat></d:response>'
    .'<d:response><d:href>/nc/remote.php/dav/files/'.$C.'/Dibagikan.xlsx</d:href><d:propstat><d:prop><nc:mount-type>shared</nc:mount-type></d:prop></d:propstat></d:response>'
    .'<d:response><d:href>/nc/remote.php/dav/files/'.$C.'/Diskominfo/</d:href><d:propstat><d:prop><nc:mount-type>group</nc:mount-type></d:prop></d:propstat></d:response>'
    .'</d:multistatus>';
$ocsOk = ['ocs' => ['meta' => ['status' => 'ok', 'statuscode' => 200], 'data' => []]];
Http::fake(function (Request $r) use ($xml, $ocsOk) {
    return match ($r->method()) {
        'PROPFIND' => Http::response($xml, 207),
        'MKCOL' => Http::response('', str_ends_with($r->url(), 'Arsip%20Pegawai%20Keluar') ? 405 : 201),
        'MOVE' => Http::response('', str_contains($r->url(), 'Foto') ? 412 : 201),
        default => Http::response($ocsOk, 200),
    };
});
$klien = new NextcloudAdminClient('http://nc/nc', 'kisara-admin', 'app-pass');
$hasil = $klien->moveUserFilesToTeamFolder($C, 'Diskominfo', "Arsip Pegawai Keluar/{$C} Citra");
$rec = collect(Http::recorded())->map(fn ($p) => $p[0]);
$auth = fn (Request $r) => base64_decode(substr($r->header('Authorization')[0] ?? '', 6));
$langkah = $rec->map(fn (Request $r) => $r->method().' '.preg_replace('#^http://nc/nc#', '', urldecode($r->url())))->all();
$cek('hasil: 1 dipindah, Foto gagal (nama sudah ada)', $hasil === ['moved' => 1, 'failed' => ['Foto']], json_encode($hasil));
$cek('urutan: sandi, aktifkan, PROPFIND, MKCOL x2, MOVE x2, nonaktifkan, sandi', $langkah === [
    "PUT /ocs/v2.php/cloud/users/{$C}",
    "PUT /ocs/v2.php/cloud/users/{$C}/enable",
    "PROPFIND /remote.php/dav/files/{$C}/",
    "MKCOL /remote.php/dav/files/{$C}/Diskominfo/Arsip Pegawai Keluar",
    "MKCOL /remote.php/dav/files/{$C}/Diskominfo/Arsip Pegawai Keluar/{$C} Citra",
    "MOVE /remote.php/dav/files/{$C}/Laporan Akhir.docx",
    "MOVE /remote.php/dav/files/{$C}/Foto",
    "PUT /ocs/v2.php/cloud/users/{$C}/disable",
    "PUT /ocs/v2.php/cloud/users/{$C}",
], json_encode($langkah, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
$sandi1 = $rec[0]->data()['value'] ?? null;
$sandi2 = $rec[8]->data()['value'] ?? null;
$cek('OCS sebagai admin; WebDAV sebagai pegawai dengan sandi sementara', str_starts_with($auth($rec[0]), 'kisara-admin:') && $auth($rec[2]) === "{$C}:{$sandi1}" && $auth($rec[5]) === "{$C}:{$sandi1}");
$cek('sandi sementara 40 karakter, diganti sandi lain di akhir', strlen((string) $sandi1) === 40 && $sandi2 !== $sandi1 && strlen((string) $sandi2) === 40);
$cek('Destination di Folder OPD, Overwrite: F', urldecode($rec[5]->header('Destination')[0] ?? '') === "http://nc/nc/remote.php/dav/files/{$C}/Diskominfo/Arsip Pegawai Keluar/{$C} Citra/Laporan Akhir.docx" && ($rec[5]->header('Overwrite')[0] ?? '') === 'F');
$cek('yang dibagikan ke dia dan Team Folder tidak dipindah', $rec->filter(fn ($r) => $r->method() === 'MOVE' && (str_contains(urldecode($r->url()), 'Dibagikan') || str_ends_with(urldecode($r->url()), '/Diskominfo')))->isEmpty());

Http::swap(new \Illuminate\Http\Client\Factory); // rekaman dan tiruan baru
Http::fake(fn (Request $r) => $r->method() === 'PROPFIND' ? Http::response('', 401) : Http::response($ocsOk, 200));
$pesan = '';
try { $klien->moveUserFilesToTeamFolder($C, 'Diskominfo', 'Arsip'); } catch (NextcloudException $e) { $pesan = $e->getMessage(); }
$langkah = collect(Http::recorded())->map(fn ($p) => $p[0]->method().' '.preg_replace('#^http://nc/nc#', '', $p[0]->url()))->all();
$cek('login sementara ditolak: galat jelas', str_contains($pesan, '2FA'), $pesan);
$cek('tetap dinonaktifkan lagi dan sandinya diganti', array_slice($langkah, -2) === ["PUT /ocs/v2.php/cloud/users/{$C}/disable", "PUT /ocs/v2.php/cloud/users/{$C}"], json_encode($langkah));

echo "\n=== migrasi dapat dibalik ===\n";
(require glob($root.'/packages/nawasara-workspace/database/migrations/*_create_nawasara_workspace_offboarding_table.php')[0])->down();
$cek('down() membuang tabel', ! Schema::hasTable('nawasara_workspace_offboarding'));

echo "\n".($gagal === 0 ? 'SEMUA LULUS' : "ADA {$gagal} YANG GAGAL")."\n";
exit($gagal === 0 ? 0 : 1);
