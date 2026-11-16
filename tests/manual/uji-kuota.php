<?php
/*
 * Uji T-14 Penyimpangan Kuota dengan Nextcloud tiruan di memori. SQLite di
 * memori, lewat remote-test.
 *
 * Yang diuji:
 *  - kuota bawaan 3 GB per pegawai, 50 GB per Folder OPD
 *  - penyimpangan pegawai diterapkan, perubahan diterapkan ulang, dihapus
 *    kembali ke bawaan; pegawai lain tidak ikut ditulis
 *  - penyimpangan Folder OPD diterapkan dan dicabut
 *  - halaman: alasan wajib, pelaku tercatat, satu sasaran satu baris,
 *    hapus butuh sudo, baris tabel di dalam x-slot:table
 */

$root = dirname(__DIR__, 4);
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$app['config']->set('database.default', 'uji');
$app['config']->set('database.connections.uji', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]);
$app['db']->setDefaultConnection('uji');
$app['config']->set('cache.default', 'array');
$app['config']->set('nawasara-workspace.sync_on_change', false); // sinkron dipanggil langsung di bawah

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Nawasara\Workspace\Livewire\Quota\Section\Form;
use Nawasara\Workspace\Livewire\Quota\Section\Table;
use Nawasara\Workspace\Models\OpdFolder;
use Nawasara\Workspace\Models\QuotaOverride;
use Nawasara\Workspace\Services\AccountProvisioner;
use Nawasara\Workspace\Services\FolderProvisioner;
use Nawasara\Workspace\Services\NextcloudAdminClient;
use Nawasara\Workspace\Services\OpdResolver;
use Nawasara\Workspace\Support\QuotaSize;

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
(require $root.'/vendor/spatie/laravel-permission/database/migrations/create_permission_tables.php.stub')->up();
$migrasi = glob($root.'/packages/nawasara-workspace/database/migrations/*.php');
foreach ($migrasi as $f) { (require $f)->up(); }
$cek('migrasi penyimpangan kuota jalan', Schema::hasTable('nawasara_workspace_quota_overrides'));

/** Nextcloud tiruan: akun dan Team Folders di memori; catat setiap tulis. */
class NcPalsu extends NextcloudAdminClient
{
    public array $users = [];
    public array $folders = [];
    public array $writes = [];
    private int $nextId = 1;

    public function __construct() { parent::__construct('http://x/nc', 'a', 'p'); }
    public function isConfigured(): bool { return true; }
    public function getUser(string $uid): ?array
    {
        return isset($this->users[$uid]) ? ['displayname' => $this->users[$uid]['displayname'], 'email' => $this->users[$uid]['email']] : null;
    }
    public function createUser(string $uid, string $displayName, ?string $email, string $quota, array $groups = []): void
    {
        $this->writes[] = "create {$uid}";
        $this->users[$uid] = ['displayname' => $displayName, 'email' => $email, 'quota' => $quota, 'groups' => $groups];
    }
    public function updateUser(string $uid, string $key, string $value): void { $this->writes[] = "update {$uid} {$key}"; $this->users[$uid][$key] = $value; }
    public function ensureGroup(string $gid): void {}
    public function userGroups(string $uid): array { return $this->users[$uid]['groups'] ?? []; }
    public function addUserToGroup(string $uid, string $gid): void { $this->users[$uid]['groups'][] = $gid; }
    public function removeUserFromGroup(string $uid, string $gid): void { $this->users[$uid]['groups'] = array_values(array_diff($this->users[$uid]['groups'], [$gid])); }
    public function teamFolders(): array
    {
        $out = [];
        foreach ($this->folders as $id => $f) { $out[$id] = ['id' => $id] + $f; }
        return $out;
    }
    public function createTeamFolder(string $name): int
    {
        $id = $this->nextId++;
        $this->folders[$id] = ['mount_point' => $name, 'groups' => [], 'quota' => -3, 'acl' => false, 'manager' => null];
        return $id;
    }
    public function renameTeamFolder(int $id, string $name): void { $this->folders[$id]['mount_point'] = $name; }
    public function assignTeamFolderGroup(int $id, string $gid, int $permissions = 31): void { $this->folders[$id]['groups'][$gid] = $permissions; }
    public function setTeamFolderQuota(int $id, int $bytes): void { $this->writes[] = "folder quota {$id} {$bytes}"; $this->folders[$id]['quota'] = $bytes; }
    public function enableTeamFolderAcl(int $id, string $managerGroup): void { $this->folders[$id]['acl'] = true; $this->folders[$id]['manager'] = $managerGroup; }
}

DB::table('users')->insert(['id' => 1, 'name' => 'Admin Kisara', 'username' => 'admin', 'email' => 'a@x.id']);
DB::table('nawasara_registry_opd')->insert(['id' => 7, 'code' => 'KOMINFO', 'name' => 'Dinas Komunikasi, Informatika dan Statistik']);
$kc = fn ($id, $user, $first) => DB::table('nawasara_keycloak_users')->insert([
    'user_id' => $id, 'username' => $user, 'first_name' => $first, 'enabled' => true,
    'email' => strtolower($first).'@ponorogo.go.id',
    'attributes' => json_encode(['simas_skpd' => ['Dinas Komunikasi, Informatika dan Statistik']]),
    'created_at' => now(), 'updated_at' => now(),
]);
$kc('kc-a', '199506142020121004', 'Pringgo');
$kc('kc-b', '198604082020121003', 'Warist');
$kc('kc-c', 'operator-lab', 'Operator');
$A = '199506142020121004';
$B = '198604082020121003';

auth()->setUser((config('auth.providers.users.model'))::find(1)); // setUser, bukan login()
Gate::before(fn () => true); // izin dicek terpisah oleh route + PermissionSeeder

$nc = new NcPalsu;
$akun = fn () => (new AccountProvisioner($nc, new OpdResolver))->run();
$folder = fn () => (new FolderProvisioner($nc))->run();
$GB = 1024 ** 3;

echo "=== ukuran ===\n";
$cek('"50 GB" = 50 x 1024^3 byte', QuotaSize::bytes('50 GB') === 50 * $GB);
$cek('format ke satuan bulat terbesar', QuotaSize::format(10 * $GB) === '10 GB' && QuotaSize::format(512 * 1024 ** 2) === '512 MB' && QuotaSize::format(1536 * 1024 ** 2) === '1536 MB' && QuotaSize::format(2 * 1024 ** 4) === '2 TB');

echo "\n=== bawaan ===\n";
$akun();
$folder();
$f7 = OpdFolder::firstWhere('opd_id', 7);
$cek('pegawai: 3 GB', ($nc->users[$A]['quota'] ?? null) === '3 GB' && ($nc->users[$B]['quota'] ?? null) === '3 GB');
$cek('Folder OPD: 50 GB', ($nc->folders[$f7->nc_folder_id]['quota'] ?? null) === 50 * $GB);

echo "\n=== halaman: tambah penyimpangan pegawai ===\n";
$f = Livewire::test(Form::class)->call('create')->set('userSearch', 'Pringgo')->call('pick', 0)
    ->set('size', 10)->set('unit', 'GB')->set('reason', '')->call('save');
$cek('alasan wajib', $f->instance()->getErrorBag()->has('reason') && QuotaOverride::count() === 0);
$f->set('reason', 'Operator arsip foto kegiatan')->call('save')->assertHasNoErrors();
$o = QuotaOverride::firstWhere('nip', $A);
$cek('baris dibuat: 10 GB, alasan, oleh admin', $o !== null && $o->quota_bytes === 10 * $GB && $o->reason === 'Operator arsip foto kegiatan' && $o->created_by === 1 && $o->updated_by === 1 && $o->name === 'Pringgo');
$cek('tercatat di log aktivitas', DB::table('activity_log')->where('description', 'Penyimpangan kuota ditambahkan')->where('causer_id', 1)->exists());
$f = Livewire::test(Form::class)->call('create')->set('userSearch', 'Pringgo');
$cek('pegawai yang sudah punya penyimpangan disaring dari pencarian', count($f->instance()->userResults) === 0);
$f = Livewire::test(Form::class)->call('create')->set('userSearch', 'Operator')->call('pick', 0);
$cek('akun tanpa NIP tidak bisa dipilih', $f->get('picked') === null);

echo "\n=== penyimpangan pegawai diterapkan ===\n";
$nc->writes = [];
$s = $akun();
$cek('A menjadi 10 GB', $nc->users[$A]['quota'] === '10 GB', json_encode($s));
$cek('hanya A yang ditulis, B tidak disentuh', $s['updated'] === 1 && $s['unchanged'] === 1 && ! preg_grep("/{$B}/", $nc->writes), json_encode($nc->writes));
$nc->writes = [];
$s = $akun();
$cek('putaran berikutnya tanpa tulisan (idempoten)', $nc->writes === [] && $s['unchanged'] === 2);

echo "\n=== penyimpangan diubah → diterapkan ulang ===\n";
$f = Livewire::test(Form::class)->call('edit', $o->id);
$cek('form ubah memuat 10 GB', $f->get('size') === 10 && $f->get('unit') === 'GB' && $f->get('picked')['nip'] === $A);
$f->set('size', 512)->set('unit', 'MB')->set('reason', 'Cukup 512 MB, arsip dipindah ke Folder OPD')->call('save')->assertHasNoErrors();
$cek('baris diperbarui', $o->fresh()->quota_bytes === 512 * 1024 ** 2);
$cek('perubahan tercatat', DB::table('activity_log')->where('description', 'Penyimpangan kuota diubah')->exists());
$s = $akun();
$cek('A menjadi 512 MB pada putaran berikutnya', $nc->users[$A]['quota'] === '512 MB' && $s['updated'] === 1, json_encode($s));

echo "\n=== penyimpangan dihapus → kembali ke bawaan ===\n";
Livewire::test(Table::class)->call('delete', $o->id);
$cek('hapus tanpa sudo: baris TIDAK terhapus', QuotaOverride::whereKey($o->id)->exists());
$o->delete(); // setelah sudo (diuji terpisah di auth-primitives)
$cek('penghapusan tercatat', DB::table('activity_log')->where('description', 'Penyimpangan kuota dihapus')->exists());
$s = $akun();
$cek('A kembali 3 GB', $nc->users[$A]['quota'] === '3 GB' && $s['updated'] === 1, json_encode($s));

echo "\n=== penyimpangan Folder OPD ===\n";
Livewire::test(Form::class)->call('create')->set('targetType', 'opd')->set('opdId', 7)
    ->set('size', 100)->set('unit', 'GB')->set('reason', 'Arsip foto dan video kegiatan dinas')->call('save')->assertHasNoErrors();
$op = QuotaOverride::firstWhere('opd_id', 7);
$cek('baris OPD dibuat, nip kosong', $op !== null && $op->target_type === 'opd' && $op->nip === null && $op->quota_bytes === 100 * $GB);
$f = Livewire::test(Form::class)->call('create')->set('targetType', 'opd');
$cek('OPD yang sudah punya penyimpangan tidak ditawarkan lagi', ! array_key_exists(7, $f->instance()->opdOptions));
$f->set('opdId', 7)->set('size', 1)->set('reason', 'ganda ganda')->call('save');
$cek('OPD ganda ditolak', QuotaOverride::where('opd_id', 7)->count() === 1);
$nc->writes = [];
$s = $folder();
$cek('Folder OPD menjadi 100 GB', $nc->folders[$f7->nc_folder_id]['quota'] === 100 * $GB && $f7->fresh()->quota_bytes === 100 * $GB, json_encode($s));
$cek('akun pegawai tidak terpengaruh penyimpangan OPD', $nc->users[$A]['quota'] === '3 GB');
$nc->writes = [];
$folder();
$cek('putaran berikutnya tanpa tulisan', $nc->writes === []);
$op->delete();
$folder();
$cek('dihapus: Folder OPD kembali 50 GB', $nc->folders[$f7->nc_folder_id]['quota'] === 50 * $GB);

echo "\n=== halaman dirender ===\n";
QuotaOverride::create(['target_type' => 'pegawai', 'nip' => $B, 'name' => 'Warist', 'quota_bytes' => 20 * $GB, 'reason' => 'Uji tampilan', 'created_by' => 1, 'updated_by' => 1]);
$html = Livewire::test(Table::class)->html();
$cek('baris ada di dalam x-slot:table (tbody)', preg_match('#<tbody[^>]*>.*'.$B.'.*20 GB#s', $html) === 1);
$cek('kuota bawaan ditampilkan', str_contains($html, '3 GB') && str_contains($html, '50 GB'));
$html = Livewire::test(Table::class)->set('search', 'tidak-ada-siapa-pun')->html();
$cek('empty state pencarian', str_contains($html, 'Tidak ada yang cocok'));

echo "\n=== migrasi dapat dibalik ===\n";
(require $root.'/packages/nawasara-workspace/database/migrations/2026_11_17_100005_create_nawasara_workspace_quota_overrides_table.php')->down();
$cek('down() menghapus tabel', ! Schema::hasTable('nawasara_workspace_quota_overrides'));

echo "\n".($gagal === 0 ? 'SEMUA LULUS' : "ADA {$gagal} YANG GAGAL")."\n";
exit($gagal === 0 ? 0 : 1);
