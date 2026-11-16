<?php
/*
 * Uji T-10 (akun), T-11 (OPD), T-12 (Folder OPD) dengan Nextcloud tiruan di
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
use Illuminate\Support\Facades\Schema;
use Nawasara\Workspace\Models\Account;
use Nawasara\Workspace\Models\OpdFolder;
use Nawasara\Workspace\Models\PilotMember;
use Nawasara\Workspace\Services\AccountProvisioner;
use Nawasara\Workspace\Services\FolderProvisioner;
use Nawasara\Workspace\Services\NextcloudAdminClient;
use Nawasara\Workspace\Services\NextcloudException;
use Nawasara\Workspace\Services\OpdResolver;

$gagal = 0;
$cek = function (string $label, bool $ok, string $detail = '') use (&$gagal) {
    if (! $ok) { $gagal++; }
    echo ($ok ? '  OK   ' : '  GAGAL')." {$label}".($detail ? "\n         {$detail}" : '')."\n";
};

Schema::create('users', function ($t) { $t->id(); $t->string('name'); $t->string('email')->nullable(); $t->string('username')->nullable(); $t->timestamps(); });
Schema::create('nawasara_registry_opd', function ($t) { $t->id(); $t->string('code'); $t->string('name'); $t->string('simas_name')->nullable(); $t->timestamps(); });
Schema::create('activity_log', function ($t) {
    $t->id(); $t->string('log_name')->nullable(); $t->text('description');
    $t->nullableMorphs('subject'); $t->nullableMorphs('causer'); $t->json('properties')->nullable();
    $t->uuid('batch_uuid')->nullable(); $t->string('event')->nullable(); $t->timestamps();
});
(require $root.'/packages/nawasara-keycloak/database/migrations/2026_04_25_130001_create_nawasara_keycloak_users_table.php')->up();
foreach (glob($root.'/packages/nawasara-workspace/database/migrations/*.php') as $f) { (require $f)->up(); }

/** Nextcloud tiruan: akun, grup, Team Folders di memori; catat setiap tulis. */
class NcPalsu extends NextcloudAdminClient
{
    public array $users = [];      // uid => [displayname, email, quota, groups[]]
    public array $groups = [];
    public array $folders = [];    // id => [mount_point, groups, quota, acl, manager]
    public array $writes = [];
    public array $failCreate = [];
    private int $nextId = 1;

    public function __construct() { parent::__construct('http://x/nc', 'a', 'p'); }
    public function isConfigured(): bool { return true; }
    public function getUser(string $uid): ?array
    {
        return isset($this->users[$uid]) ? ['displayname' => $this->users[$uid]['displayname'], 'email' => $this->users[$uid]['email']] : null;
    }
    public function createUser(string $uid, string $displayName, ?string $email, string $quota, array $groups = []): void
    {
        if (in_array($uid, $this->failCreate, true)) { throw new NextcloudException('boom', 500); }
        $this->writes[] = "create {$uid}";
        $this->users[$uid] = ['displayname' => $displayName, 'email' => $email, 'quota' => $quota, 'groups' => $groups];
    }
    public function updateUser(string $uid, string $key, string $value): void { $this->writes[] = "update {$uid} {$key}"; $this->users[$uid][$key] = $value; }
    public function ensureGroup(string $gid): void { $this->groups[$gid] = true; }
    public function userGroups(string $uid): array { return $this->users[$uid]['groups'] ?? []; }
    public function addUserToGroup(string $uid, string $gid): void { $this->writes[] = "add {$uid} {$gid}"; $this->users[$uid]['groups'][] = $gid; }
    public function removeUserFromGroup(string $uid, string $gid): void
    {
        $this->writes[] = "remove {$uid} {$gid}";
        $this->users[$uid]['groups'] = array_values(array_diff($this->users[$uid]['groups'], [$gid]));
    }
    public function teamFolders(): array
    {
        $out = [];
        foreach ($this->folders as $id => $f) { $out[$id] = ['id' => $id] + $f; }
        return $out;
    }
    public function createTeamFolder(string $name): int
    {
        $id = $this->nextId++;
        $this->writes[] = "folder create {$name}";
        $this->folders[$id] = ['mount_point' => $name, 'groups' => [], 'quota' => -3, 'acl' => false, 'manager' => null];
        return $id;
    }
    public function renameTeamFolder(int $id, string $name): void { $this->writes[] = "folder rename {$name}"; $this->folders[$id]['mount_point'] = $name; }
    public function assignTeamFolderGroup(int $id, string $gid, int $permissions = 31): void { $this->folders[$id]['groups'][$gid] = $permissions; }
    public function setTeamFolderQuota(int $id, int $bytes): void { $this->folders[$id]['quota'] = $bytes; }
    public function enableTeamFolderAcl(int $id, string $managerGroup): void { $this->folders[$id]['acl'] = true; $this->folders[$id]['manager'] = $managerGroup; }
}

DB::table('nawasara_registry_opd')->insert([
    ['id' => 7, 'code' => 'KOMINFO', 'name' => 'DINAS KOMUNIKASI, INFORMATIKA DAN STATISTIK', 'simas_name' => null],
    ['id' => 8, 'code' => 'DINDIK', 'name' => 'Dinas Pendidikan', 'simas_name' => 'DINAS PENDIDIKAN'],
]);
$kc = function ($id, $user, $first, $enabled, $skpd) {
    DB::table('nawasara_keycloak_users')->insert([
        'user_id' => $id, 'username' => $user, 'first_name' => $first, 'enabled' => $enabled,
        'email' => strtolower($first).'@ponorogo.go.id',
        'attributes' => json_encode($skpd ? ['simas_skpd' => [$skpd]] : []), 'created_at' => now(), 'updated_at' => now(),
    ]);
};
$kc('kc-a', '199506142020121004', 'Pringgo', true, 'Dinas Komunikasi, Informatika dan Statistik');
$kc('kc-b', '198604082020121003', 'Warist', true, 'DINAS PENDIDIKAN');
$kc('kc-c', '199001012015011001', 'Citra', true, 'DINAS PENDIDIKAN DAN KEBUDAYAAN'); // hanya berawalan
$kc('kc-d', '197001012000011001', 'Dodi', false, 'DINAS PENDIDIKAN');                 // nonaktif
$kc('kc-e', 'operator-lab', 'Operator', true, null);                                   // bukan NIP

echo "=== T-11 OPD ===\n";
$r = new OpdResolver;
$cek('SKPD cocok utuh (huruf & tanda baca dinormalkan)', $r->resolve('1', 'dinas komunikasi informatika dan statistik') === 7);
$cek('simas_name dipakai bila ada', $r->resolve('1', 'DINAS PENDIDIKAN') === 8);
$cek('hanya berawalan nama OPD: TIDAK cocok', $r->resolve('1', 'DINAS PENDIDIKAN DAN KEBUDAYAAN') === null);
$cek('tanpa SKPD: tanpa OPD', $r->resolve('1', null) === null);
PilotMember::create(['nip' => '199001012015011001', 'name' => 'Citra', 'stage' => 1, 'opd_id' => 7]);
$cek('override peserta pilot menang', (new OpdResolver)->resolve('199001012015011001', 'DINAS PENDIDIKAN DAN KEBUDAYAAN') === 7);

echo "\n=== T-10 putaran pertama ===\n";
$nc = new NcPalsu;
$prov = fn () => new AccountProvisioner($nc, new OpdResolver);
$s = $prov()->run();
$cek('hanya ASN ber-NIP yang aktif (3 dari 5)', $s['created'] === 3 && $s['seen'] === 3, json_encode($s));
$cek('UID = NIP, nama, email, kuota bawaan 3 GB', ($nc->users['199506142020121004']['displayname'] ?? '') === 'Pringgo' && $nc->users['199506142020121004']['quota'] === '3 GB' && $nc->users['199506142020121004']['email'] === 'pringgo@ponorogo.go.id');
$cek('grup OPD saat dibuat', $nc->users['199506142020121004']['groups'] === ['OPD-7'] && $nc->users['198604082020121003']['groups'] === ['OPD-8']);
$cek('nonaktif & bukan NIP tidak dibuat', ! isset($nc->users['197001012000011001']) && ! isset($nc->users['operator-lab']));
$cek('status lokal tersimpan', Account::count() === 3 && Account::where('nip', '199506142020121004')->value('opd_id') === 7);

echo "\n=== T-10 putaran kedua: idempoten ===\n";
$nc->writes = [];
$s = $prov()->run();
$cek('tanpa tulisan ke Nextcloud', $nc->writes === [] && $s['unchanged'] === 3, json_encode($s));

echo "\n=== T-10/T-11 pindah OPD ===\n";
$nc->users['198604082020121003']['groups'][] = 'workspace-pilot';
DB::table('nawasara_keycloak_users')->where('user_id', 'kc-b')->update(['attributes' => json_encode(['simas_skpd' => ['DINAS KOMUNIKASI, INFORMATIKA DAN STATISTIK']])]);
$nc->writes = [];
$s = $prov()->run();
$g = $nc->users['198604082020121003']['groups'];
$cek('OPD lama dicabut, baru diberikan', in_array('OPD-7', $g, true) && ! in_array('OPD-8', $g, true), json_encode($g));
$cek('grup non-OPD (workspace-pilot) tidak disentuh', in_array('workspace-pilot', $g, true));
$cek('akun diperbarui, bukan dibuat ulang', $s['updated'] === 1 && $s['created'] === 0);

echo "\n=== T-10 akun yang sudah ada di Nextcloud ===\n";
$kc('kc-f', '198001012005011001', 'Fajar', true, null);
$nc->users['198001012005011001'] = ['displayname' => 'Fajar', 'email' => null, 'quota' => '1 GB', 'groups' => []];
$s = $prov()->run();
$cek('ditautkan & diperbarui, tidak dibuat ganda', $s['updated'] === 1 && $s['created'] === 0 && $nc->users['198001012005011001']['quota'] === '3 GB');

echo "\n=== T-10 batas & galat ===\n";
foreach (range(1, 4) as $i) { $kc("kc-n{$i}", '19900101201501100'.(1 + $i), "Baru{$i}", true, null); }
$nc->failCreate = ['199001012015011002'];
$s = $prov()->run(2);
$cek('maks 2 tulisan, sisanya ditunda', ($s['created'] + $s['updated'] + $s['failed']) === 2 && $s['deferred'] === 2, json_encode($s));
$cek('yang gagal tidak dianggap selesai', ! Account::where('nip', '199001012015011002')->whereNotNull('state_hash')->exists());
$nc->failCreate = [];
$s = $prov()->run();
$cek('putaran berikutnya melanjutkan + mengulang yang gagal', isset($nc->users['199001012015011002'], $nc->users['199001012015011005']), json_encode($s));

echo "\n=== T-12 Folder OPD ===\n";
$fp = new FolderProvisioner($nc);
$nc->writes = [];
$s = $fp->run();
$f7 = OpdFolder::firstWhere('opd_id', 7);
$folder = $nc->folders[$f7->nc_folder_id] ?? [];
$cek('satu folder per OPD yang punya anggota (hanya Kominfo kini)', $s['created'] === 1 && OpdFolder::count() === 1, json_encode($s));
$cek('nama rapi dari Registry', ($folder['mount_point'] ?? '') === 'Dinas Komunikasi, Informatika dan Statistik', $folder['mount_point'] ?? '-');
$cek('grup OPD-7, kuota 50 GB, ACL dikelola grup OPD sendiri', ($folder['groups'] ?? []) === ['OPD-7' => 31] && $folder['quota'] === 50 * 1024 ** 3 && $folder['acl'] === true && $folder['manager'] === 'OPD-7');
$nc->writes = [];
$s = $fp->run();
$cek('putaran kedua tanpa tulisan', $nc->writes === [] && $s['unchanged'] === 1, json_encode($s));
DB::table('nawasara_registry_opd')->where('id', 7)->update(['name' => 'Diskominfo Ponorogo']);
$s = $fp->run();
$cek('OPD ganti nama → folder ikut, grup tetap', $nc->folders[$f7->nc_folder_id]['mount_point'] === 'Diskominfo Ponorogo' && $s['renamed'] === 1);
unset($nc->folders[$f7->nc_folder_id]);
$s = $fp->run();
$cek('folder hilang di Nextcloud → dibuat ulang, baris diperbarui', $s['created'] === 1 && isset($nc->folders[OpdFolder::firstWhere('opd_id', 7)->nc_folder_id]));

echo "\n".($gagal === 0 ? 'SEMUA LULUS' : "ADA {$gagal} YANG GAGAL")."\n";
exit($gagal === 0 ? 0 : 1);
