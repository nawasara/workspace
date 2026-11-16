<?php
/*
 * Uji T-35 Pemantauan dan peringatan (NFR-06, NFR-07). SQLite di memori,
 * lewat remote-test. Kanal notifikasi diganti tiruan; tidak ada Telegram.
 *
 * Yang diuji:
 *  - provisioning: 1 gagal -> tidak ada alert; 2 gagal berturut -> 1 alert;
 *    gagal ke-3 menyalakan alert YANG SAMA; berhasil -> pulih; gagal-berhasil-
 *    gagal tidak dihitung berturut
 *  - OnlyOffice: mati 4 menit -> tidak ada; 6 menit -> alert; pulih -> resolve;
 *    URL bawaan dari Vault base_url (/nc -> /oo/healthcheck)
 *  - disk LXC 145: 79% -> tidak; 81% -> alert; turun -> pulih; data basi atau
 *    tidak ada -> tidak berubah
 *  - target id stabil (nama job, onlyoffice, lxc/145)
 *  - tanpa paket alerting: tidak ada galat, tidak ada alert
 *  - log siklus hidup dengan request id, tanpa isi hasil selain angka
 *  - jadwal terdaftar
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

use Illuminate\Log\Events\MessageLogged;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Nawasara\Alerting\Models\AlertState;
use Nawasara\Alerting\Services\AlerterImpl;
use Nawasara\Alerting\Services\NotifyDispatcher;
use Nawasara\Sync\Events\SyncJobFinalFailed;
use Nawasara\Sync\Events\SyncJobSucceeded;
use Nawasara\Sync\Models\SyncJob;
use Nawasara\Workspace\Alerting\DiskUsageMonitor;
use Nawasara\Workspace\Alerting\OnlyOfficeHealthMonitor;
use Nawasara\Workspace\Alerting\WorkspaceAlerts;

$gagal = 0;
$cek = function (string $label, bool $ok, string $detail = '') use (&$gagal) {
    if (! $ok) { $gagal++; }
    echo ($ok ? '  OK   ' : '  GAGAL')." {$label}".($detail ? "\n         {$detail}" : '')."\n";
};

Schema::create('users', function ($t) { $t->id(); $t->string('name'); $t->timestamps(); });
(require $root.'/packages/nawasara-sync/database/migrations/2026_04_25_000001_create_nawasara_sync_jobs_table.php')->up();
(require $root.'/packages/nawasara-alerting/database/migrations/2026_06_09_000001_create_nawasara_alert_states_table.php')->up();
(require $root.'/packages/nawasara-proxmox/database/migrations/2026_04_28_100002_create_nawasara_proxmox_vms_table.php')->up();
foreach (glob($root.'/packages/nawasara-workspace/database/migrations/*.php') as $f) { (require $f)->up(); }

/** Kanal notifikasi tiruan: mencatat, tidak mengirim. */
class DispatcherPalsu extends NotifyDispatcher
{
    public array $sent = [];
    public function __construct() {}
    public function dispatch(AlertState $state, \Nawasara\Alerting\Contracts\AlertRuleDefinition $rule, string $kind): void
    {
        $this->sent[] = "{$kind} {$state->rule_key} {$state->target_id}";
    }
}
$kanal = new DispatcherPalsu;
$app->instance(NotifyDispatcher::class, $kanal);
$app->forgetInstance(AlerterImpl::class);
Facade::clearResolvedInstance('alerter');

$logs = [];
Event::listen(MessageLogged::class, function (MessageLogged $e) use (&$logs) { $logs[] = [$e->level, $e->message, $e->context]; });

$terkirim = fn (string $rule) => array_values(array_filter($kanal->sent, fn ($s) => str_contains($s, " {$rule} ")));
$state = fn (string $rule) => AlertState::where('rule_key', $rule)->get();

echo "=== aturan terdaftar ===\n";
$cek('tiga aturan Workspace terdaftar', collect([WorkspaceAlerts::RULE_PROVISIONING, WorkspaceAlerts::RULE_ONLYOFFICE, WorkspaceAlerts::RULE_DISK])->every(fn ($k) => app(AlerterImpl::class)->hasRule($k)));
$jadwal = collect($app->make(\Illuminate\Console\Scheduling\Schedule::class)->events())->map(fn ($e) => $e->description)->all();
$cek('jadwal onlyoffice-health dan disk-usage terdaftar', in_array('nawasara-workspace:onlyoffice-health', $jadwal) && in_array('nawasara-workspace:disk-usage', $jadwal), json_encode($jadwal));
$oo = collect($app->make(\Illuminate\Console\Scheduling\Schedule::class)->events())->first(fn ($e) => $e->description === 'nawasara-workspace:onlyoffice-health');
$cek('onlyoffice-health tiap menit', $oo?->expression === '* * * * *', (string) $oo?->expression);

echo "\n=== provisioning gagal 2x berturut ===\n";
$run = function (string $action, string $status, string $error = 'Nextcloud 503') {
    $t = SyncJob::create(['service' => 'workspace', 'action' => $action, 'target_type' => 'NextcloudUser', 'status' => SyncJob::STATUS_RUNNING, 'attempts' => 3, 'trigger_source' => 'scheduled', 'started_at' => now()]);
    if ($status === 'failed') {
        $t->markFailed($error);
        SyncJobFinalFailed::dispatch($t, new RuntimeException($error));
    } else {
        $t->markSuccess(['created' => 2, 'updated' => 1, 'nip' => '199001012020011001']);
        SyncJobSucceeded::dispatch($t);
    }
    return $t;
};
$R = WorkspaceAlerts::RULE_PROVISIONING;

$run('sync_accounts', 'success');
$run('sync_accounts', 'failed');
$cek('satu kegagalan: tidak ada alert provisioning', $state($R)->isEmpty() && $terkirim($R) === []);
$run('sync_accounts', 'failed');
$cek('dua kegagalan berturut: satu alert', $state($R)->count() === 1 && $terkirim($R) === ["fired {$R} SyncWorkspaceAccountsJob"], json_encode($kanal->sent));
$s = $state($R)->first();
$cek('target = nama job, konteks berisi jumlah dan galat singkat', $s->target_type === 'WorkspaceJob' && $s->target_id === 'SyncWorkspaceAccountsJob' && $s->context['failures'] === 2 && str_contains($s->context['error_short'], '503'), json_encode($s->context));
$run('sync_accounts', 'failed');
$cek('gagal ke-3: alert yang sama, tidak ada baris baru (cooldown)', $state($R)->count() === 1 && count($terkirim($R)) === 1);
$run('sync_accounts', 'success');
$cek('berhasil: alert pulih', $state($R)->first()->status === 'ok' && end($kanal->sent) === "resolved {$R} SyncWorkspaceAccountsJob");
$run('sync_accounts', 'failed');
$cek('gagal sekali sesudah pulih: tidak menyala lagi', $state($R)->first()->status === 'ok');

$run('sync_opd_folders', 'failed');
$run('sync_opd_folders', 'success');
$run('sync_opd_folders', 'failed');
$cek('gagal-berhasil-gagal: tidak dihitung berturut', $state($R)->where('target_id', 'SyncWorkspaceFoldersJob')->isEmpty());

// Run yang mati karena timeout tidak memancarkan event, tapi tetap terhitung.
SyncJob::create(['service' => 'workspace', 'action' => 'sync_pilot_group', 'status' => 'failed', 'attempts' => 3, 'error' => 'timeout', 'trigger_source' => 'scheduled']);
SyncJob::create(['service' => 'workspace', 'action' => 'sync_pilot_group', 'status' => 'queued', 'trigger_source' => 'event']);
$run('sync_pilot_group', 'failed');
$cek('kegagalan tanpa event (timeout) ikut terhitung; baris queued diabaikan', $state($R)->where('target_id', 'SyncPilotGroupJob')->count() === 1);

$t = SyncJob::create(['service' => 'whm', 'action' => 'sync_accounts', 'status' => 'failed', 'trigger_source' => 'scheduled']);
SyncJobFinalFailed::dispatch($t, new RuntimeException('x'));
SyncJobFinalFailed::dispatch($t, new RuntimeException('x'));
$cek('layanan lain tidak disentuh', $state($R)->where('target_id', 'like', '%whm%')->isEmpty() && $state($R)->count() === 2);

echo "\n=== log siklus hidup (NFR-06) ===\n";
$logs = [];
$job = new Illuminate\Queue\Jobs\SyncJob($app, json_encode(['uuid' => 'req-123', 'displayName' => 'Nawasara\\Workspace\\Jobs\\SyncWorkspaceAccountsJob', 'job' => 'x', 'data' => []]), 'sync', 'default');
Event::dispatch(new JobProcessing('sync', $job));
$run('sync_accounts', 'success');
Event::dispatch(new Illuminate\Queue\Events\JobProcessed('sync', $job));
$mulai = collect($logs)->first(fn ($l) => $l[1] === 'workspace.job.started');
$selesai = collect($logs)->first(fn ($l) => $l[1] === 'workspace.job.finished');
$cek('started dan finished membawa request id yang sama', ($mulai[2]['request_id'] ?? null) === 'req-123' && ($selesai[2]['request_id'] ?? null) === 'req-123', json_encode([$mulai, $selesai]));
$cek('finished: jumlah dan durasi saja, NIP tidak ikut', ($selesai[2]['counts'] ?? null) === ['created' => 2, 'updated' => 1] && array_key_exists('duration_ms', $selesai[2]), json_encode($selesai[2] ?? null));
$logs = [];
Event::dispatch(new JobProcessing('sync', $job));
$run('sync_accounts', 'failed', 'Nextcloud 500');
$gagalLog = collect($logs)->first(fn ($l) => $l[1] === 'workspace.job.failed');
$cek('failed: request id, sync_job_id, galat', ($gagalLog[2]['request_id'] ?? null) === 'req-123' && isset($gagalLog[2]['sync_job_id']) && ($gagalLog[2]['error'] ?? null) === 'Nextcloud 500', json_encode($logs));
$logs = [];
$lain = new Illuminate\Queue\Jobs\SyncJob($app, json_encode(['uuid' => 'r2', 'displayName' => 'Nawasara\\Whm\\Jobs\\SyncWhmEmailsJob', 'job' => 'x', 'data' => []]), 'sync', 'default');
Event::dispatch(new JobProcessing('sync', $lain));
$cek('job paket lain tidak dicatat', collect($logs)->where(1, 'workspace.job.started')->isEmpty());

echo "\n=== OnlyOffice tidak menjawab > 5 menit ===\n";
$O = WorkspaceAlerts::RULE_ONLYOFFICE;
$app['config']->set('nawasara-workspace.monitoring.onlyoffice.healthcheck_url', 'http://10.1.1.37:8080/oo/healthcheck');
$ooHidup = true;
$ooBody = 'true';
Http::fake(function () use (&$ooHidup, &$ooBody) { return $ooHidup ? Http::response($ooBody, 200) : Http::response('Bad Gateway', 502); });
$mon = app(OnlyOfficeHealthMonitor::class);
$t0 = Carbon::parse('2026-11-17 08:00:00');
Carbon::setTestNow($t0);
$cek('sehat: up', $mon->check() === 'up');
$ooHidup = false;
$hasil = [];
for ($m = 1; $m <= 5; $m++) { Carbon::setTestNow($t0->copy()->addMinutes($m)); $hasil[] = $mon->check(); }
$cek('mati 4 menit: tidak ada alert', $state($O)->isEmpty() && $terkirim($O) === [] && ! in_array('alerted', $hasil), json_encode($hasil));
Carbon::setTestNow($t0->copy()->addMinutes(7));
$cek('mati 6 menit: alert', $mon->check() === 'alerted' && $terkirim($O) === ["fired {$O} onlyoffice"], json_encode($kanal->sent));
$s = $state($O)->first();
$cek('target stabil onlyoffice, severity critical, menit 6', $s->target_type === 'WorkspaceService' && $s->target_id === 'onlyoffice' && $s->severity === 'critical' && $s->context['minutes'] === 6 && $s->context['reason'] === 'http_502', json_encode($s->context));
Carbon::setTestNow($t0->copy()->addMinutes(8));
$mon->check();
$cek('menit berikutnya: tidak ada pesan tambahan', count($terkirim($O)) === 1 && $state($O)->count() === 1);
$logs = [];
$ooHidup = true;
Carbon::setTestNow($t0->copy()->addMinutes(9));
$cek('pulih: up dan alert resolve', $mon->check() === 'up' && $state($O)->first()->status === 'ok' && end($kanal->sent) === "resolved {$O} onlyoffice");
$pulih = collect($logs)->first(fn ($l) => $l[1] === 'workspace.onlyoffice.recovered');
$cek('log recovered dengan request id dan durasi', isset($pulih[2]['request_id']) && $pulih[2]['down_seconds'] === 480, json_encode($pulih));
$ooHidup = false;
Carbon::setTestNow($t0->copy()->addMinutes(10));
$mon->check();
Carbon::setTestNow($t0->copy()->addMinutes(13));
$cek('padam lagi: hitungan mulai dari awal (3 menit, belum alert)', $mon->check() === 'down' && $state($O)->first()->status === 'ok');
$ooHidup = true; $ooBody = 'false';
$cek('badan selain "true" = tidak sehat', $mon->probe('http://10.1.1.37:8080/oo/healthcheck') === 'unexpected_body');
$ooBody = 'true';
Carbon::setTestNow($t0->copy()->addMinutes(14)); $mon->check();

$app['config']->set('nawasara-workspace.monitoring.onlyoffice.healthcheck_url', '');
Cache::forget(OnlyOfficeHealthMonitor::URL_CACHE_KEY);
\Nawasara\Vault\Facades\Vault::shouldReceive('get')->once()->andReturn('http://10.1.1.37:8080/nc/');
$cek('URL bawaan: base_url Vault tanpa /nc + /oo/healthcheck', $mon->url() === 'http://10.1.1.37:8080/oo/healthcheck', $mon->url());
$cek('URL bawaan di-cache (Vault dibaca sekali)', $mon->url() === 'http://10.1.1.37:8080/oo/healthcheck');
Cache::put(OnlyOfficeHealthMonitor::URL_CACHE_KEY, '', 600);
$cek('Vault kosong: unconfigured, tanpa galat', $mon->check() === 'unconfigured');

echo "\n=== disk LXC 145 > 80% ===\n";
$D = WorkspaceAlerts::RULE_DISK;
Carbon::setTestNow($t0->copy()->addHour());
$GB = 1073741824;
DB::table('nawasara_proxmox_vms')->insert(['vmid' => 145, 'node_name' => 'pve1', 'vm_type' => 'lxc', 'name' => 'kisara-workspace', 'status' => 'running', 'disk_total' => 200 * $GB, 'disk_used' => 158 * $GB, 'last_synced_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
$disk = app(DiskUsageMonitor::class);
$cek('79%: tidak ada alert', $disk->check() === 'ok' && $state($D)->isEmpty());
DB::table('nawasara_proxmox_vms')->where('vmid', 145)->update(['disk_used' => 162 * $GB]);
$cek('81% (sisa 38 GB): alert', $disk->check() === 'high' && $terkirim($D) === ["fired {$D} lxc/145"]);
$s = $state($D)->first();
$cek('target stabil lxc/145, konteks persen dan sisa', $s->target_type === 'ProxmoxVm' && $s->target_id === 'lxc/145' && (float) $s->context['percent'] === 81.0 && (float) $s->context['free_gb'] === 38.0, json_encode($s->context));
DB::table('nawasara_proxmox_vms')->where('vmid', 145)->update(['last_synced_at' => now()->subHours(3), 'disk_used' => 100 * $GB]);
$cek('data basi: tidak berubah (tetap menyala)', $disk->check() === 'stale' && $state($D)->first()->status === 'firing');
DB::table('nawasara_proxmox_vms')->where('vmid', 145)->update(['last_synced_at' => now()]);
$cek('turun ke 50%: pulih', $disk->check() === 'ok' && $state($D)->first()->status === 'ok');
$app['config']->set('nawasara-workspace.monitoring.disk.vmid', 999);
$cek('vmid tidak ada: unknown, tanpa galat', $disk->check() === 'unknown');
$app['config']->set('nawasara-workspace.monitoring.disk.vmid', 145);

echo "\n=== tanpa paket alerting ===\n";
$sebelum = AlertState::count();
$kirimSebelum = count($kanal->sent);
WorkspaceAlerts::$alerter = 'Nawasara\\TidakAda\\Alerter';
$galat = null;
try {
    WorkspaceAlerts::registerRules();
    $run('sync_offboarding', 'failed');
    $run('sync_offboarding', 'failed');
    $run('sync_offboarding', 'success');
    $ooHidup = false;
    $app['config']->set('nawasara-workspace.monitoring.onlyoffice.healthcheck_url', 'http://10.1.1.37:8080/oo/healthcheck');
    Carbon::setTestNow($t0->copy()->addHours(2)); $mon->check();
    Carbon::setTestNow($t0->copy()->addHours(2)->addMinutes(7)); $hasilOo = $mon->check();
    DB::table('nawasara_proxmox_vms')->where('vmid', 145)->update(['disk_used' => 190 * $GB, 'last_synced_at' => now()]);
    $hasilDisk = $disk->check();
} catch (\Throwable $e) {
    $galat = $e;
}
$cek('tidak ada galat', $galat === null, (string) $galat?->getMessage());
$cek('pemantau tetap jalan (alerted/high) tetapi tidak ada alert', ($hasilOo ?? null) === 'alerted' && ($hasilDisk ?? null) === 'high' && AlertState::where('rule_key', 'like', 'workspace.%')->count() === $state($R)->count() + 2 && count(array_filter(array_slice($kanal->sent, $kirimSebelum), fn ($s) => str_contains($s, ' workspace.'))) === 0, json_encode(array_slice($kanal->sent, $kirimSebelum)));
WorkspaceAlerts::$alerter = \Nawasara\Alerting\Facades\Alerter::class;

echo "\n=== tanpa em-dash ===\n";
$berkas = array_merge(glob($root.'/packages/nawasara-workspace/src/Alerting/*.php'), [$root.'/packages/nawasara-workspace/config/nawasara-workspace.php']);
$cek('tidak ada em-dash di berkas T-35', collect($berkas)->every(fn ($f) => ! str_contains(file_get_contents($f), "\u{2014}")));

echo "\n".($gagal === 0 ? 'SEMUA LULUS' : "ADA {$gagal} YANG GAGAL")."\n";
exit($gagal === 0 ? 0 : 1);
