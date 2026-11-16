<?php
/*
 * Uji Peserta Pilot (T-05) terhadap SQLite di memori, lewat remote-test.
 *
 *   remote-test.sh -- 'composer install … && php packages/nawasara-workspace/tests/manual/uji-peserta-pilot.php'
 *
 * Yang diuji adalah ATURANNYA:
 *  - pegawai dipilih dari direktori Keycloak, akun tanpa NIP ditolak
 *  - NIP yang sama tidak bisa masuk dua kali
 *  - cabut menyimpan baris (bisa dipulihkan), hapus butuh sudo
 *  - kill switch: menyalakan butuh sudo, mematikan tidak
 */

$root = dirname(__DIR__, 4);
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$app['config']->set('database.default', 'uji');
$app['config']->set('database.connections.uji', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]);
$app['db']->setDefaultConnection('uji');
$app['config']->set('cache.default', 'array');

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Nawasara\Workspace\Livewire\Pilot\Section\Controls;
use Nawasara\Workspace\Livewire\Pilot\Section\Form;
use Nawasara\Workspace\Livewire\Pilot\Section\Table;
use Nawasara\Workspace\Models\PilotMember;
use Nawasara\Workspace\Models\Setting;

$gagal = 0;
$cek = function (string $label, bool $ok, string $detail = '') use (&$gagal) {
    if (! $ok) { $gagal++; }
    echo ($ok ? '  OK   ' : '  GAGAL')." {$label}".($detail ? "\n         {$detail}" : '')."\n";
};

// ---- skema: tabel induk seminimal mungkin, migrasi paket yang sebenarnya ----
Schema::create('users', function ($t) {
    $t->id(); $t->string('name'); $t->string('email')->nullable(); $t->string('username')->nullable();
    $t->string('keycloak_id')->nullable(); $t->string('password')->nullable(); $t->timestamps();
});
Schema::create('nawasara_registry_opd', function ($t) { $t->id(); $t->string('code'); $t->string('name'); $t->timestamps(); });
Schema::create('activity_log', function ($t) {
    $t->id(); $t->string('log_name')->nullable(); $t->text('description');
    $t->nullableMorphs('subject'); $t->nullableMorphs('causer'); $t->json('properties')->nullable();
    $t->uuid('batch_uuid')->nullable(); $t->string('event')->nullable(); $t->timestamps();
});
(require $root.'/packages/nawasara-keycloak/database/migrations/2026_04_25_130001_create_nawasara_keycloak_users_table.php')->up();
// Tabel Spatie: @can di blade memeriksa izin lewat tabel ini.
(require $root.'/vendor/spatie/laravel-permission/database/migrations/create_permission_tables.php.stub')->up();
foreach (glob($root.'/packages/nawasara-workspace/database/migrations/*.php') as $f) {
    (require $f)->up();
}
$cek('migrasi paket jalan', Schema::hasTable('nawasara_workspace_pilot_members') && Schema::hasTable('nawasara_workspace_settings'));

DB::table('users')->insert(['id' => 1, 'name' => 'Admin Kisara', 'username' => 'admin', 'email' => 'a@x.id']);
DB::table('nawasara_registry_opd')->insert(['id' => 7, 'code' => 'KOMINFO', 'name' => 'Diskominfo']);
$kc = fn ($id, $user, $first, $last) => DB::table('nawasara_keycloak_users')->insert([
    'user_id' => $id, 'username' => $user, 'first_name' => $first, 'last_name' => $last, 'enabled' => true,
    'created_at' => now(), 'updated_at' => now(),
]);
$kc('kc-1', '199506142020121004', 'Pringgo', 'Saputro');
$kc('kc-2', '198604082020121003', 'Warist', 'Amru');
$kc('kc-3', 'operator-lab', 'Operator', 'Lab');

$admin = (config('auth.providers.users.model'))::find(1);
auth()->setUser($admin); // setUser, bukan login(): login() memicu listener audit & registry
Gate::before(fn () => true); // izin dicek terpisah oleh route + PermissionSeeder

echo "=== tambah peserta dari direktori ===\n";
$f = Livewire::test(Form::class)->call('create')->set('userSearch', 'Pringgo');
$hasil = $f->instance()->userResults;
$cek('pencarian menemukan pegawai di direktori', count($hasil) === 1 && $hasil[0]['name'] !== '', json_encode(array_column($hasil, 'name')));
$f->call('pick', 0)->set('stage', 0)->set('opdId', 7)->set('note', 'pemilik')->call('save')->assertHasNoErrors();
$m = PilotMember::firstWhere('nip', '199506142020121004');
$cek('baris dibuat dengan NIP dari direktori', $m !== null && $m->keycloak_id === 'kc-1' && $m->stage === 0 && $m->opd_id === 7);
$cek('added_by = admin yang menambah', $m?->added_by === 1);
$cek('tercatat di log aktivitas', DB::table('activity_log')->where('description', 'Peserta pilot ditambahkan')->exists());

echo "\n=== akun tanpa NIP ditolak ===\n";
$f = Livewire::test(Form::class)->call('create')->set('userSearch', 'Operator')->call('pick', 0);
$cek('pegawai tidak terpilih', $f->get('picked') === null);
$f->call('save');
$cek('tidak ada baris baru', PilotMember::count() === 1);

echo "\n=== NIP yang sudah ada tidak muncul lagi ===\n";
$f = Livewire::test(Form::class)->call('create')->set('userSearch', 'Pringgo');
$cek('peserta lama disaring dari hasil cari', count($f->instance()->userResults) === 0);

echo "\n=== ubah tahap ===\n";
Livewire::test(Form::class)->call('edit', $m->id)->set('stage', 1)->set('opdId', null)->call('save')->assertHasNoErrors();
$m->refresh();
$cek('tahap diubah, OPD kembali ke SIMAS', $m->stage === 1 && $m->opd_id === null);

echo "\n=== cabut dan pulihkan ===\n";
Livewire::test(Table::class)->call('revoke', $m->id);
$m->refresh();
$cek('dicabut: removed_at terisi, baris tetap', $m->removed_at !== null && PilotMember::active()->count() === 0);
Livewire::test(Table::class)->call('restore', $m->id);
$cek('dipulihkan', $m->fresh()->removed_at === null);

echo "\n=== hapus butuh sudo ===\n";
Livewire::test(Table::class)->call('delete', $m->id);
$cek('tanpa sudo: baris TIDAK terhapus', PilotMember::whereKey($m->id)->exists());

echo "\n=== kill switch ===\n";
Livewire::test(Controls::class)->call('toggleKillSwitch');
$cek('menyalakan tanpa sudo: tetap mati', Setting::flag(Setting::KILL_SWITCH) === false);
Setting::setFlag(Setting::KILL_SWITCH, true, 1);
$cek('flag terbaca menyala (cache dibuang saat set)', Setting::flag(Setting::KILL_SWITCH) === true);
Livewire::test(Controls::class)->call('toggleKillSwitch');
$cek('mematikan kill switch tidak butuh sudo', Setting::flag(Setting::KILL_SWITCH) === false);

echo "\n=== halaman dirender ===\n";
$html = Livewire::test(Table::class)->html();
$cek('tabel memuat baris peserta', str_contains($html, '199506142020121004') && str_contains($html, 'Tahap 1: Diskominfo'));
$cek('baris ada di dalam x-slot:table (tbody)', preg_match('#<tbody[^>]*>.*199506142020121004#s', $html) === 1);

echo "\n".($gagal === 0 ? 'SEMUA LULUS' : "ADA {$gagal} YANG GAGAL")."\n";
exit($gagal === 0 ? 0 : 1);
