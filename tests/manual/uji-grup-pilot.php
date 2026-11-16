<?php
/*
 * Uji sinkron grup Keycloak workspace-pilot (T-07) dengan Keycloak tiruan di
 * memori. SQLite di memori, lewat remote-test.
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
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Nawasara\Keycloak\Services\KeycloakClient;
use Nawasara\Workspace\Jobs\SyncPilotGroupJob;
use Nawasara\Workspace\Models\PilotMember;
use Nawasara\Workspace\Models\Setting;
use Nawasara\Workspace\Services\AccessDecider;
use Nawasara\Workspace\Services\PilotGroupSync;

$gagal = 0;
$cek = function (string $label, bool $ok, string $detail = '') use (&$gagal) {
    if (! $ok) { $gagal++; }
    echo ($ok ? '  OK   ' : '  GAGAL')." {$label}".($detail ? "\n         {$detail}" : '')."\n";
};

Schema::create('users', function ($t) { $t->id(); $t->string('name'); $t->string('email')->nullable(); $t->string('username')->nullable(); $t->timestamps(); });
Schema::create('nawasara_registry_opd', function ($t) { $t->id(); $t->string('code'); $t->string('name'); $t->timestamps(); });
Schema::create('activity_log', function ($t) {
    $t->id(); $t->string('log_name')->nullable(); $t->text('description');
    $t->nullableMorphs('subject'); $t->nullableMorphs('causer'); $t->json('properties')->nullable();
    $t->uuid('batch_uuid')->nullable(); $t->string('event')->nullable(); $t->timestamps();
});
(require $root.'/packages/nawasara-keycloak/database/migrations/2026_04_25_130001_create_nawasara_keycloak_users_table.php')->up();
(require $root.'/packages/nawasara-sync/database/migrations/2026_04_25_000001_create_nawasara_sync_jobs_table.php')->up();
foreach (glob($root.'/packages/nawasara-workspace/database/migrations/*.php') as $f) { (require $f)->up(); }

/** Keycloak tiruan: satu grup, anggota di memori, catat setiap tulis. */
class KeycloakPalsu extends KeycloakClient
{
    public array $members = [];
    public array $writes = [];
    public bool $groupExists = true;
    public bool $membersFail = false;
    public array $failAdd = [];

    public function findGroupByName(string $name): ?array { return $this->groupExists ? ['id' => 'g-1', 'name' => $name] : null; }
    public function getGroupMembers(string $groupId, int $pageSize = 200): array
    {
        if ($this->membersFail) { throw new \RuntimeException('Gagal membaca anggota grup Keycloak: HTTP 500'); }
        return array_map(fn ($id) => ['id' => $id, 'username' => $this->members[$id]], array_keys($this->members));
    }
    public function addUserToGroup(string $userId, string $groupId): bool
    {
        $this->writes[] = "add {$userId}";
        if (in_array($userId, $this->failAdd, true)) { return false; }
        $this->members[$userId] = $userId;
        return true;
    }
    public function removeUserFromGroup(string $userId, string $groupId): bool
    {
        $this->writes[] = "remove {$userId}";
        unset($this->members[$userId]);
        return true;
    }
}

$kc = fn ($id, $user) => DB::table('nawasara_keycloak_users')->insert(['user_id' => $id, 'username' => $user, 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
$kc('kc-a', '199506142020121004');
$kc('kc-b', '198604082020121003');
$kc('kc-c', '199001012015011001');

PilotMember::create(['nip' => '199506142020121004', 'name' => 'A', 'stage' => 0, 'keycloak_id' => 'kc-a']);
PilotMember::create(['nip' => '198604082020121003', 'name' => 'B', 'stage' => 1]);                     // id dari snapshot
PilotMember::create(['nip' => '199001012015011001', 'name' => 'C', 'stage' => 1, 'removed_at' => now()]); // dicabut
PilotMember::create(['nip' => '197501012000011001', 'name' => 'D', 'stage' => 1]);                     // tak ada di snapshot

$fake = new KeycloakPalsu;
$fake->members = ['kc-c' => '199001012015011001', 'kc-x' => 'orang-lama'];
$sync = new PilotGroupSync($fake, new AccessDecider);

echo "=== rencana murni ===\n";
[$add, $rm] = PilotGroupSync::plan(['kc-a' => '1', 'kc-b' => '2'], ['kc-b' => '2', 'kc-z' => '9']);
$cek('tambah yang kurang, keluarkan yang lebih', $add === ['kc-a'] && $rm === ['kc-z']);

echo "\n=== putaran pertama ===\n";
$r = $sync->run();
$cek('A ditambah (id tersimpan), B ditambah (id dari snapshot)', isset($fake->members['kc-a'], $fake->members['kc-b']), json_encode($r));
$cek('C (dicabut) dan orang lama dikeluarkan', ! isset($fake->members['kc-c']) && ! isset($fake->members['kc-x']));
$cek('D tanpa id Keycloak dihitung unresolved, tidak ditebak', $r['unresolved'] === 1);
$cek('hitungan', $r['added'] === 2 && $r['removed'] === 2 && $r['failed'] === 0);

echo "\n=== putaran kedua: idempoten ===\n";
$fake->writes = [];
$r = $sync->run();
$cek('tidak ada tulisan sama sekali', $fake->writes === [] && $r['added'] === 0 && $r['removed'] === 0, json_encode($fake->writes));

echo "\n=== kill switch mengosongkan grup ===\n";
Setting::setFlag(Setting::KILL_SWITCH, true);
$r = $sync->run();
$cek('semua dikeluarkan', $fake->members === [] && $r['desired'] === 0, json_encode($r));
Setting::setFlag(Setting::KILL_SWITCH, false);
$sync->run();
$cek('dibuka lagi: anggota kembali', count($fake->members) === 2);

echo "\n=== kegagalan ===\n";
$fake->membersFail = true;
$fake->writes = [];
$lempar = false;
try { $sync->run(); } catch (\RuntimeException $e) { $lempar = true; }
$cek('gagal baca anggota: dilempar, TIDAK ada tulisan massal', $lempar && $fake->writes === []);
$fake->membersFail = false;

$fake->groupExists = false;
$lempar = false;
try { $sync->run(); } catch (\RuntimeException $e) { $lempar = str_contains($e->getMessage(), 'belum ada'); }
$cek('grup belum dibuat: pesan jelas', $lempar);
$fake->groupExists = true;

$fake->members = [];
$fake->failAdd = ['kc-a'];
$r = $sync->run();
$cek('satu tambah gagal: dihitung, sisanya tetap jalan', $r['failed'] === 1 && isset($fake->members['kc-b']), json_encode($r));

echo "\n=== perubahan peserta memicu job ===\n";
$app['config']->set('nawasara-workspace.sync_on_change', true);
Queue::fake();
PilotMember::where('nip', '199001012015011001')->first()->update(['removed_at' => null]);
Queue::assertPushed(SyncPilotGroupJob::class);
$cek('menyimpan peserta mendorong SyncPilotGroupJob', true);
$cek('tercatat di riwayat sync (tracker)', DB::table('nawasara_sync_jobs')->where('action', 'sync_pilot_group')->exists());

echo "\n".($gagal === 0 ? 'SEMUA LULUS' : "ADA {$gagal} YANG GAGAL")."\n";
exit($gagal === 0 ? 0 : 1);
