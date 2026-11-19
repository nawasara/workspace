<?php
/*
 * Uji Workspace di atas Akses Layanan Kisara (nawasara/asn-portal):
 *  - tanpa tabel asn-portal: perilaku lama utuh (daftar pilot sendiri)
 *  - setelah migrasi asn-portal: AccessDecider, grup Keycloak, OPD override
 *    dan kartu Drive membaca Akses Layanan Kisara, daftar lama diabaikan
 *  - perubahan akses workspace memicu SyncPilotGroupJob, perubahan mail tidak
 *  - halaman Peserta Pilot lama mengalihkan dan menolak setiap tulisan
 * SQLite di memori, lewat remote-test.
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

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Nawasara\AsnPortal\Models\AppAccess;
use Nawasara\AsnPortal\Services\KisaraAccess;
use Nawasara\Keycloak\Services\KeycloakClient;
use Nawasara\Workspace\Http\Api\AccessController;
use Nawasara\Workspace\Jobs\SyncPilotGroupJob;
use Nawasara\Workspace\Livewire\Pilot\Index as PilotIndex;
use Nawasara\Workspace\Livewire\Pilot\Section\Table as PilotTable;
use Nawasara\Workspace\Models\PilotMember;
use Nawasara\Workspace\Models\Setting;
use Nawasara\Workspace\Services\AccessDecider;
use Nawasara\Workspace\Services\NextcloudAdminClient;
use Nawasara\Workspace\Services\OpdResolver;
use Nawasara\Workspace\Services\PilotGroupSync;
use Nawasara\Workspace\Services\QuotaReader;
use Nawasara\Workspace\Support\KisaraAccessBridge;

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

DB::table('users')->insert(['id' => 1, 'name' => 'Admin Kisara', 'username' => 'admin', 'email' => 'a@x.id']);
DB::table('nawasara_registry_opd')->insert([['id' => 7, 'code' => 'KOMINFO', 'name' => 'Diskominfo'], ['id' => 8, 'code' => 'BKPSDM', 'name' => 'BKPSDM']]);
$kc = fn ($id, $user) => DB::table('nawasara_keycloak_users')->insert(['user_id' => $id, 'username' => $user, 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
$a = '199506142020121004';
$b = '198604082020121003';
$c = '199001012015011001';
$kc('kc-a', $a);
$kc('kc-b', $b);
$kc('kc-c', $c);

PilotMember::create(['nip' => $a, 'name' => 'A', 'stage' => 0, 'opd_id' => 7, 'keycloak_id' => 'kc-a']);
PilotMember::create(['nip' => $b, 'name' => 'B', 'stage' => 1]);

$bridge = app(KisaraAccessBridge::class);

echo "=== sebelum migrasi asn-portal: perilaku lama ===\n";
$cek('bridge belum aktif (tabel belum ada)', $bridge->active() === false);
$d = new AccessDecider;
$cek('peserta lama boleh masuk dari daftar Workspace sendiri', $d->decide($a)['allowed'] && $d->decide($a)['opd_id'] === 7);
$cek('kill switch Workspace lama masih berlaku', (function () use ($d, $a) { Setting::setFlag(Setting::KILL_SWITCH, true); $r = $d->decide($a)['reason']; Setting::setFlag(Setting::KILL_SWITCH, false); return $r === 'kill_switch'; })());

echo "\n=== migrasi asn-portal: daftar disalin, sumber berpindah ===\n";
$migrasi = glob($root.'/packages/nawasara-asn-portal/database/migrations/*.php');
sort($migrasi);
foreach ($migrasi as $f) { (require $f)->up(); }
$cek('bridge aktif setelah tabel ada (jawaban "tidak" tidak diingat)', $bridge->active() === true);
$cek('dua peserta x dua layanan tersalin', AppAccess::count() === 4, (string) AppAccess::count());

$d = new AccessDecider;
$r = $d->decide($a);
$cek('peserta: boleh, tahap + OPD dari Akses Layanan Kisara', $r['allowed'] && $r['stage'] === 0 && $r['opd_id'] === 7, json_encode($r));
$cek('bentuk jawaban decide() tetap sama', array_keys($r) === ['nip', 'allowed', 'reason', 'stage', 'opd_id'], implode(',', array_keys($r)));

PilotMember::create(['nip' => $c, 'name' => 'C', 'stage' => 1]);
$cek('baris baru di tabel LAMA tidak memberi akses lagi', $d->decide($c)['reason'] === 'not_participant');
AppAccess::where('nip', $b)->where('app', 'workspace')->first()->update(['removed_at' => now()]);
$cek('dinonaktifkan di Akses Layanan Kisara: revoked', $d->decide($b)['reason'] === 'revoked');
Setting::setFlag(Setting::KILL_SWITCH, true);
$cek('kill switch Workspace LAMA diabaikan', $d->decide($a)['allowed'] === true);
Setting::setFlag(Setting::KILL_SWITCH, false);
app(KisaraAccess::class)->setFlag('workspace', KisaraAccess::FLAG_KILL_SWITCH, true);
$cek('kill switch Akses Layanan Kisara berlaku', $d->decide($a)['reason'] === 'kill_switch');
app(KisaraAccess::class)->setFlag('workspace', KisaraAccess::FLAG_KILL_SWITCH, false);
app(KisaraAccess::class)->setFlag('mail', KisaraAccess::FLAG_KILL_SWITCH, true);
$cek('kill switch Mail tidak menyentuh Workspace', $d->decide($a)['allowed'] === true);
app(KisaraAccess::class)->setFlag('mail', KisaraAccess::FLAG_KILL_SWITCH, false);

$body = (new AccessController)->show($a, $d)->getData(true)['data'];
$cek('API /workspace/access tetap: kunci sama, OPD terisi', array_keys($body) === ['nip', 'allowed', 'reason', 'stage', 'opd', 'is_admin'] && $body['opd'] === ['id' => 7, 'name' => 'Diskominfo'], json_encode($body));

echo "\n=== OPD override dan kartu Drive ===\n";
AppAccess::where('nip', $a)->where('app', 'workspace')->first()->update(['opd_id' => 8]);
$cek('OpdResolver memakai override dari Akses Layanan Kisara', (new OpdResolver)->resolve($a, 'DINAS LAIN') === 8);
$cek('override di tabel lama diabaikan', (new OpdResolver)->resolve($c, null) === null);
$q = new QuotaReader(new NextcloudAdminClient('http://x/nc', 'a', 'p'));
$cek('kartu Drive: peserta aktif', $q->isParticipant($a) === true);
$cek('kartu Drive: dinonaktifkan bukan peserta', $q->isParticipant($b) === false);

echo "\n=== grup Keycloak workspace-pilot ===\n";
class KeycloakPalsuBridge extends KeycloakClient
{
    public array $members = [];
    public function findGroupByName(string $name): ?array { return ['id' => 'g-1', 'name' => $name]; }
    public function getGroupMembers(string $groupId, int $pageSize = 200): array
    {
        return array_map(fn ($id) => ['id' => $id, 'username' => $this->members[$id]], array_keys($this->members));
    }
    public function addUserToGroup(string $userId, string $groupId): bool { $this->members[$userId] = $userId; return true; }
    public function removeUserFromGroup(string $userId, string $groupId): bool { unset($this->members[$userId]); return true; }
}
$fake = new KeycloakPalsuBridge;
$fake->members = ['kc-b' => $b, 'kc-c' => $c];
$r = (new PilotGroupSync($fake, $d))->run();
$cek('grup = baris aktif workspace: A masuk, B (nonaktif) dan C (hanya di tabel lama) keluar', array_keys($fake->members) === ['kc-a'], json_encode($r));

echo "\n=== perubahan memicu sinkron grup ===\n";
$app['config']->set('nawasara-workspace.sync_on_change', true);
Queue::fake();
AppAccess::where('nip', $a)->where('app', 'mail')->first()->update(['note' => 'mail saja']);
$cek('ubah akses MAIL: tidak memicu SyncPilotGroupJob', Queue::pushed(SyncPilotGroupJob::class)->count() === 0);
AppAccess::where('nip', $b)->where('app', 'workspace')->first()->update(['removed_at' => null]);
$cek('ubah akses WORKSPACE: memicu SyncPilotGroupJob', Queue::pushed(SyncPilotGroupJob::class)->count() === 1);
app(KisaraAccess::class)->setFlag('workspace', KisaraAccess::FLAG_OPEN, true);
$cek('ubah pengaturan workspace: memicu juga', Queue::pushed(SyncPilotGroupJob::class)->count() === 2);
app(KisaraAccess::class)->setFlag('workspace', KisaraAccess::FLAG_OPEN, false);
$app['config']->set('nawasara-workspace.sync_on_change', false);

echo "\n=== halaman Peserta Pilot lama ===\n";
auth()->setUser((config('auth.providers.users.model'))::find(1));
Gate::before(fn () => true);
try {
    Livewire::test(PilotIndex::class)->assertRedirectToRoute('nawasara-asn-portal.access.index', ['app' => 'workspace']);
    $alih = true;
    $alasan = '';
} catch (\Throwable $e) {
    $alih = false;
    $alasan = $e->getMessage();
}
$cek('halaman lama mengalihkan ke Akses Layanan Kisara (app=workspace)', $alih, $alasan);
$id = PilotMember::where('nip', $a)->value('id');
try {
    Livewire::test(PilotTable::class)->call('revoke', $id)->assertStatus(409);
    $tolak = true;
} catch (\Throwable $e) {
    $tolak = $e instanceof Symfony\Component\HttpKernel\Exception\HttpException && $e->getStatusCode() === 409;
}
$cek('cabut dari halaman lama ditolak 409', $tolak && PilotMember::find($id)->removed_at === null);

echo "\n".($gagal === 0 ? 'SEMUA LULUS' : "ADA {$gagal} YANG GAGAL")."\n";
exit($gagal === 0 ? 0 : 1);
