<?php
/*
 * Uji API akses Workspace (T-06): aturan AccessDecider + bentuk jawaban.
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
$app['config']->set('nawasara-workspace.sync_on_change', false); // job sinkron grup diuji terpisah (uji-grup-pilot)

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Nawasara\Workspace\Http\Api\AccessController;
use Nawasara\Workspace\Models\PilotMember;
use Nawasara\Workspace\Models\Setting;
use Nawasara\Workspace\Services\AccessDecider;

$gagal = 0;
$cek = function (string $label, bool $ok, string $detail = '') use (&$gagal) {
    if (! $ok) { $gagal++; }
    echo ($ok ? '  OK   ' : '  GAGAL')." {$label}".($detail ? "\n         {$detail}" : '')."\n";
};

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
foreach (glob($root.'/packages/nawasara-workspace/database/migrations/*.php') as $f) { (require $f)->up(); }

DB::table('nawasara_registry_opd')->insert(['id' => 7, 'code' => 'KOMINFO', 'name' => 'Diskominfo']);
$kc = fn ($user, $enabled) => DB::table('nawasara_keycloak_users')->insert(['user_id' => 'kc-'.$user, 'username' => $user, 'enabled' => $enabled, 'created_at' => now(), 'updated_at' => now()]);
$kc('199506142020121004', true);
$kc('198604082020121003', true);
$kc('197001012000011001', false);

PilotMember::create(['nip' => '199506142020121004', 'name' => 'Pringgo', 'stage' => 0, 'opd_id' => 7]);
PilotMember::create(['nip' => '198604082020121003', 'name' => 'Warist', 'stage' => 1, 'removed_at' => now()]);
PilotMember::create(['nip' => '197001012000011001', 'name' => 'Nonaktif', 'stage' => 1]);

$d = new AccessDecider;
$r = fn (string $nip) => $d->decide($nip);

echo "=== aturan ===\n";
$a = $r('199506142020121004');
$cek('peserta aktif: boleh, tahap & OPD ikut', $a['allowed'] && $a['stage'] === 0 && $a['opd_id'] === 7, json_encode($a));
$cek('peserta dicabut: revoked', $r('198604082020121003')['reason'] === AccessDecider::REVOKED);
$cek('bukan peserta: not_participant', $r('199001012015011001')['reason'] === AccessDecider::NOT_PARTICIPANT);
$cek('akun Kisara nonaktif kalah walau peserta: account_disabled', $r('197001012000011001')['reason'] === AccessDecider::ACCOUNT_DISABLED);
$cek('bukan NIP: invalid_nip', $r('admin')['reason'] === AccessDecider::INVALID_NIP && $r('1995')['reason'] === AccessDecider::INVALID_NIP);

echo "\n=== pilot_open (tahap 3) ===\n";
Setting::setFlag(Setting::PILOT_OPEN, true);
$cek('bukan peserta pun boleh', $r('199001012015011001')['allowed'] && $r('199001012015011001')['stage'] === 3);
$cek('akun nonaktif tetap ditolak', $r('197001012000011001')['reason'] === AccessDecider::ACCOUNT_DISABLED);

echo "\n=== kill switch mengalahkan semuanya ===\n";
Setting::setFlag(Setting::KILL_SWITCH, true);
$cek('peserta aktif ditolak: kill_switch', $r('199506142020121004')['reason'] === AccessDecider::KILL_SWITCH);
$cek('tahap 3 pun ditolak', $r('199001012015011001')['reason'] === AccessDecider::KILL_SWITCH);
Setting::setFlag(Setting::KILL_SWITCH, false);
Setting::setFlag(Setting::PILOT_OPEN, false);

echo "\n=== bentuk jawaban (daftar-izin) ===\n";
$res = (new AccessController)->show('199506142020121004', $d);
$body = $res->getData(true)['data'];
$cek('kunci persis yang dijanjikan', array_keys($body) === ['nip', 'allowed', 'reason', 'stage', 'opd', 'is_admin'], implode(',', array_keys($body)));
$cek('opd berisi id + nama saja', $body['opd'] === ['id' => 7, 'name' => 'Diskominfo']);
$cek('is_admin false (tidak ada akun lokal)', $body['is_admin'] === false);
$cek('selalu 200, penolakan pun', (new AccessController)->show('199001012015011001', $d)->status() === 200);

echo "\n=== rute ===\n";
$route = Route::getRoutes()->getByName('nawasara-api.workspace.access.show');
$cek('rute terdaftar di /api/v1/workspace/access/{nip}', $route !== null && str_ends_with($route->uri(), 'workspace/access/{nip}'), $route?->uri() ?? 'tidak ada');
$cek('dijaga scope workspace.access.read + api.auth', $route && in_array('scope:workspace.access.read', $route->gatherMiddleware(), true) && in_array('api.auth', $route->gatherMiddleware(), true), implode(',', $route?->gatherMiddleware() ?? []));

echo "\n".($gagal === 0 ? 'SEMUA LULUS' : "ADA {$gagal} YANG GAGAL")."\n";
exit($gagal === 0 ? 0 : 1);
