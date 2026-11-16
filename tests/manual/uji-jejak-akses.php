<?php
/*
 * Uji T-36 Jejak Akses (FR-20). SQLite di memori, lewat remote-test.
 *
 * Fixture: tests/manual/fixtures/admin-audit-nc31.log, baris JSON admin_audit
 * dengan format pesan Nextcloud 31 / groupfolders 19 (lihat AuditLogParser).
 *
 * Yang diuji:
 *  - tiap pesan dipetakan ke aksinya; yang bukan jejak akses diabaikan
 *  - "created" + "written to" dalam satu permintaan = satu baris
 *  - sebelum/sesudah untuk cabut bagian dan ACL dari kejadian sebelumnya
 *  - waktu ber-offset disimpan UTC; IP dan NIP tercatat (AC-1)
 *  - dijalankan ulang: tidak ada baris ganda (hash), kursor melanjutkan
 *  - baris terakhir yang belum lengkap ditunggu, lalu dilanjutkan
 *  - log dirotasi / dipotong: baca dari awal tanpa baris ganda
 *  - sumber HTTP dengan Range (Http::fake)
 *  - pangkas > 1 tahun (AC-3)
 *  - cari per berkas dan per pegawai, saring aksi dan tanggal (AC-2)
 *  - halaman: baris di dalam x-slot:table, dua empty state
 *  - job: siklus hidup tercatat di nawasara/sync; tanpa sumber tidak gagal
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
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Nawasara\Sync\Models\SyncJob;
use Nawasara\Workspace\Jobs\IngestAuditLogJob;
use Nawasara\Workspace\Jobs\PruneAuditEventsJob;
use Nawasara\Workspace\Livewire\Audit\Section\Table;
use Nawasara\Workspace\Models\Account;
use Nawasara\Workspace\Models\AuditCursor;
use Nawasara\Workspace\Models\AuditEvent;
use Nawasara\Workspace\Services\Audit\AuditIngestor;
use Nawasara\Workspace\Services\Audit\AuditLogSource;
use Nawasara\Workspace\Services\Audit\FileAuditLogSource;
use Nawasara\Workspace\Services\Audit\HttpAuditLogSource;

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
(require $root.'/packages/nawasara-sync/database/migrations/2026_04_25_000001_create_nawasara_sync_jobs_table.php')->up();
(require $root.'/packages/nawasara-alerting/database/migrations/2026_06_09_000001_create_nawasara_alert_states_table.php')->up();
(require $root.'/vendor/spatie/laravel-permission/database/migrations/create_permission_tables.php.stub')->up();
foreach (glob($root.'/packages/nawasara-workspace/database/migrations/*.php') as $f) { (require $f)->up(); }
$cek('migrasi jejak akses jalan', Schema::hasTable('nawasara_workspace_audit_events') && Schema::hasTable('nawasara_workspace_audit_cursors'));

DB::table('users')->insert(['id' => 1, 'name' => 'Admin Kisara', 'username' => 'admin', 'email' => 'a@x.id']);
auth()->setUser((config('auth.providers.users.model'))::find(1)); // setUser, bukan login()
Gate::before(fn () => true);

$A = '199506142020121004'; $B = '198604082020121003';
Account::create(['nip' => $A, 'display_name' => 'Pringgo Saputro', 'quota' => '3 GB', 'state_hash' => 'x']);
Account::create(['nip' => $B, 'display_name' => 'Warist Hidayat', 'quota' => '3 GB', 'state_hash' => 'x']);

$fixture = file_get_contents(__DIR__.'/fixtures/admin-audit-nc31.log');
$lines = explode("\n", rtrim($fixture, "\n"));
$dir = sys_get_temp_dir().'/uji-jejak-'.getmypid();
@mkdir($dir);
$log = $dir.'/audit.log';
$ingestor = app(AuditIngestor::class);

echo "\n=== pemetaan aksi ===\n";
file_put_contents($log, $fixture);
$src = new FileAuditLogSource($log);
$s = $ingestor->run($src, 32 * 1024 * 1024, 4096);
$cek('29 baris dibaca, 19 tersimpan, 8 diabaikan, 2 digabung', $s['lines'] === 29 && $s['inserted'] === 19 && $s['ignored'] === 8 && $s['merged'] === 2, json_encode($s));
$per = AuditEvent::selectRaw('action, count(*) n')->groupBy('action')->pluck('n', 'action')->map(fn ($n) => (int) $n)->all();
ksort($per);
$harap = ['acl_change' => 4, 'create' => 2, 'delete' => 2, 'login' => 1, 'open' => 2, 'restore' => 2, 'share' => 3, 'unshare' => 1, 'update' => 2];
$cek('jumlah per aksi sesuai', $per === $harap, json_encode($per));
$cek('kesembilan aksi FR-20 terwakili', array_keys($per) === array_keys($harap) && count($per) === count(AuditEvent::ACTIONS));

$login = AuditEvent::where('action', 'login')->first();
$cek('masuk: NIP dari pesan, IP, user agent, waktu UTC', $login->nip === $A && $login->ip === '103.109.206.21'
    && str_contains((string) $login->user_agent, 'Firefox') && $login->occurred_at->eq(Carbon::parse('2026-11-17 01:00:01', 'UTC')));
$buat = AuditEvent::where('action', 'create')->where('file_id', 501)->get();
$cek('unggahan baru: satu baris Membuat, tanpa Mengubah di permintaan yang sama', $buat->count() === 1 && AuditEvent::where('req_id', 'r02')->count() === 1);
$ubah = AuditEvent::where('action', 'update')->where('req_id', 'r04')->get();
$cek('dua kali tulis dalam satu permintaan: satu baris Mengubah', $ubah->count() === 1);
$cek('waktu +07:00 disimpan sebagai UTC', $ubah->first()?->occurred_at->eq(Carbon::parse('2026-11-16 18:10:00', 'UTC')), (string) $ubah->first()?->occurred_at);
$buka = AuditEvent::where('action', 'open')->where('file_id', 501)->first();
$cek('membuka: berkas, jalur tampil tanpa /<uid>/files', $buka->file_name === 'Nota Dinas.docx' && $buka->displayPath() === '/Dokumen/Nota Dinas.docx');
$ganti = AuditEvent::where('req_id', 'r05')->first();
$cek('ganti nama = Mengubah, sebelum/sesudah = jalur lama/baru', $ganti->action === 'update' && str_ends_with($ganti->before, 'Nota Dinas.docx') && str_ends_with($ganti->after, 'Nota Dinas Final.docx'));
$salin = AuditEvent::where('req_id', 'r06')->first();
$cek('salin = Membuat untuk id baru', $salin->action === 'create' && $salin->file_id === 502 && str_contains($salin->detail, 'Salinan dari'));
$bagi = AuditEvent::where('req_id', 'r08')->first();
$cek('bagikan ke pengguna: penerima dan izin', $bagi->action === 'share' && $bagi->target === 'Pengguna '.$B && $bagi->after === 'baca, ubah, bagikan' && $bagi->before === null);
$bagiG = AuditEvent::where('req_id', 'r09')->first();
$cek('bagikan ke grup', $bagiG->target === 'Grup OPD-7' && $bagiG->after === 'baca, ubah, buat, hapus, bagikan');
$izin = AuditEvent::where('req_id', 'r10')->first();
$cek('izin bagian diubah = Membagikan, sesudah = baca', $izin->action === 'share' && $izin->after === 'baca' && $izin->detail === 'Izin bagian diubah');
$cabut = AuditEvent::where('req_id', 'r11')->first();
$cek('cabut bagian: sebelum = izin saat dibagikan, sesudah kosong', $cabut->action === 'unshare' && $cabut->before === 'baca, ubah, bagikan' && $cabut->after === null && $cabut->target === 'Pengguna '.$B);
$acl = AuditEvent::where('action', 'acl_change')->orderBy('occurred_at')->get()->values();
$cek('ACL baru untuk grup: izinkan baca, tolak sisanya', $acl[0]->after === 'Izinkan: baca; Tolak: ubah, buat, hapus, bagikan' && $acl[0]->before === null && $acl[0]->target === 'Grup OPD-7' && $acl[0]->nip === $B);
$cek('ACL diubah: sebelum = aturan sebelumnya', $acl[1]->before === $acl[0]->after && $acl[1]->after === 'Tolak: baca, ubah, buat, hapus, bagikan');
$cek('ACL dihapus untuk pengguna: sebelum = aturannya, sesudah ikut induk', $acl[3]->before === 'Izinkan: baca, ubah, buat, hapus, bagikan' && $acl[3]->after === 'Ikut folder induk' && $acl[3]->target === 'Pengguna Warist ('.$B.')');
$cek('ACL tercatat dengan IP pegawai', $acl->every(fn ($e) => $e->ip === '10.10.5.20' && $e->file_id === 600));
$hapus = AuditEvent::where('action', 'delete')->orderBy('occurred_at')->get();
$cek('hapus ke Sampah dan hapus permanen dibedakan', $hapus[0]->detail === 'Dipindah ke Sampah' && $hapus[1]->detail === 'Dihapus permanen dari Sampah');
$pulih = AuditEvent::where('action', 'restore')->orderBy('occurred_at')->get();
$cek('pulihkan dari Sampah dan pulihkan versi', $pulih[0]->detail === 'Dipulihkan dari Sampah' && str_starts_with($pulih[1]->detail, 'Versi ') && str_contains($pulih[1]->detail, 'WIB'));
$cek('tidak ada baris tanpa IP atau tanpa NIP dari fixture', AuditEvent::whereNull('ip')->count() === 0 && AuditEvent::whereNull('nip')->count() === 0);

echo "\n=== dijalankan ulang ===\n";
$s = $ingestor->run($src, 32 * 1024 * 1024, 4096);
$cek('kursor di akhir: tidak ada yang dibaca lagi', $s['read_bytes'] === 0 && $s['inserted'] === 0 && AuditEvent::count() === 19, json_encode($s));
AuditCursor::where('source', 'file')->update(['offset' => 0]);
$s = $ingestor->run($src, 32 * 1024 * 1024, 4096);
// Baris yang diabaikan / digabung tidak disimpan, jadi dibaca ulang dan diabaikan / digabung lagi.
$cek('kursor dikembalikan ke 0: semua dikenali lewat hash, tidak ada ganda', $s['inserted'] === 0 && $s['duplicates'] === 19 && AuditEvent::count() === 19, json_encode($s));

echo "\n=== melanjutkan dari kursor ===\n";
AuditEvent::query()->delete();
AuditCursor::query()->delete();
$separuh = implode("\n", array_slice($lines, 0, 12))."\n";
$potong = substr($lines[12], 0, 40); // baris ke-13 belum selesai ditulis
file_put_contents($log, $separuh.$potong);
$s = $ingestor->run($src, 32 * 1024 * 1024, 4096);
$cur = AuditCursor::where('source', 'file')->first();
$cek('baris belum lengkap ditunggu: kursor di akhir baris lengkap terakhir', $cur->offset === strlen($separuh) && $s['lines'] === 12, json_encode($s));
$n1 = AuditEvent::count();
file_put_contents($log, $fixture);
$s = $ingestor->run($src, 32 * 1024 * 1024, 4096);
$cek('dilanjutkan: sisanya terbaca, total sama dengan sekali jalan', AuditEvent::count() === 19 && $s['lines'] === 17 && $n1 > 0, "pertama {$n1}, ".json_encode($s));
AuditEvent::query()->delete();
AuditCursor::query()->delete();
$s1 = $ingestor->run($src, 1, 4096); // batas per putaran: satu potongan
$c1 = AuditCursor::where('source', 'file')->value('offset');
$s2 = $ingestor->run($src, 32 * 1024 * 1024, 4096);
$cek('batas per putaran: putaran berikut melanjutkan, bukan mengulang', $c1 > 0 && $c1 < strlen($fixture) && $s2['duplicates'] === 0 && AuditEvent::count() === 19, "offset1 {$c1}, ".json_encode([$s1['lines'], $s2['lines']]));

echo "\n=== rotasi ===\n";
$baru = $lines[16]."\n"; // satu baris ACL, berbeda dari baris pertama lama
$baru = str_replace('"r13"', '"r99"', $baru);
file_put_contents($log, $baru);
$s = $ingestor->run($src, 32 * 1024 * 1024, 4096);
$cek('log lebih pendek dari kursor: baca dari awal, baris baru masuk', $s['reset'] === true && $s['inserted'] === 1 && AuditEvent::count() === 20, json_encode($s));
file_put_contents($log, $fixture);
$s = $ingestor->run($src, 32 * 1024 * 1024, 4096);
$cek('baris pertama berubah (log diganti): baca dari awal, tanpa ganda', $s['reset'] === true && $s['inserted'] === 0 && $s['duplicates'] === 19 && AuditEvent::count() === 20, json_encode($s));

echo "\n=== sumber HTTP (Range) ===\n";
$hits = [];
Http::fake(function (Request $r) use ($fixture, &$hits) {
    $hits[] = $r;
    if (($r->header('Authorization')[0] ?? '') !== 'Bearer rahasia-uji') {
        return Http::response('', 401);
    }
    preg_match('/bytes=(\d+)-(\d+)/', $r->header('Range')[0] ?? '', $m);
    $total = strlen($fixture);
    if ((int) $m[1] >= $total) {
        return Http::response('', 416, ['Content-Range' => "bytes */{$total}"]);
    }
    $end = min((int) $m[2], $total - 1);

    return Http::response(substr($fixture, (int) $m[1], $end - (int) $m[1] + 1), 206, ['Content-Range' => "bytes {$m[1]}-{$end}/{$total}"]);
});
$http = new HttpAuditLogSource('http://10.1.1.37:8080/_audit/audit.log', 'rahasia-uji');
$p = $http->probe();
$cek('probe: ukuran dari Content-Range, sidik jari sama dengan berkas', $p['size'] === strlen($fixture) && $p['fingerprint'] === $src->probe()['fingerprint']);
$s = $ingestor->run($http, 32 * 1024 * 1024, 4096);
$cek('sumber HTTP: 29 baris terbaca berpotongan, semua dikenali (sumber berbeda, hash sama)', $s['lines'] === 29 && $s['inserted'] === 0 && $s['duplicates'] === 19, json_encode($s));
$cek('Range dikirim per potongan', collect($hits)->filter(fn ($r) => ! str_starts_with($r->header('Range')[0] ?? 'bytes=0-', 'bytes=0-'))->count() >= 2);
$s = $ingestor->run($http, 32 * 1024 * 1024, 4096);
$cek('HTTP dijalankan ulang: tidak ada yang dibaca', $s['read_bytes'] === 0);
$salah = new HttpAuditLogSource('http://10.1.1.37:8080/_audit/audit.log', 'salah');
$pesan = '';
try { $salah->probe(); } catch (\RuntimeException $e) { $pesan = $e->getMessage(); }
$cek('token salah: galat jelas tanpa isi respons', str_contains($pesan, 'HTTP 401'), $pesan);

echo "\n=== job ===\n";
$app->instance(AuditLogSource::class, $src);
(new IngestAuditLogJob(triggerSource: 'manual'))->handle();
$sj = SyncJob::where('action', 'ingest_audit_log')->latest('id')->first();
$cek('SyncJob sukses dengan hitungan', $sj && $sj->status === SyncJob::STATUS_SUCCESS && array_key_exists('inserted', (array) $sj->result), json_encode($sj?->toArray()));
unset($app[AuditLogSource::class]);
config(['nawasara-workspace.audit.source' => 'none']);
(new IngestAuditLogJob(triggerSource: 'manual'))->handle();
$sj = SyncJob::where('action', 'ingest_audit_log')->latest('id')->first();
$cek('tanpa sumber: tidak gagal, tercatat belum dikonfigurasi', $sj->status === SyncJob::STATUS_SUCCESS && ($sj->result['configured'] ?? null) === false, json_encode($sj->result));

echo "\n=== pencarian (AC-2) ===\n";
$ids = fn ($c) => $c->rows->getCollection()->pluck('id')->all();
$t = Livewire::test(Table::class)->set('scope', 'pegawai')->set('search', $B);
$cek('per pegawai (NIP): hanya jejak Warist', collect($t->instance()->rows->items())->every(fn ($e) => $e->nip === $B) && $t->instance()->rows->total() === 6, (string) $t->instance()->rows->total());
$t = Livewire::test(Table::class)->set('search', 'Pringgo');
$cek('per pegawai (nama dari akun)', $t->instance()->rows->total() === 14 && collect($t->instance()->rows->items())->every(fn ($e) => $e->nip === $A), (string) $t->instance()->rows->total());
$t = Livewire::test(Table::class)->set('scope', 'berkas')->set('search', '501');
$cek('per berkas (id): semua kejadian berkas 501', $t->instance()->rows->total() === 7 && collect($t->instance()->rows->items())->every(fn ($e) => $e->file_id === 501), (string) $t->instance()->rows->total());
$t = Livewire::test(Table::class)->set('scope', 'berkas')->set('search', 'Anggaran 2027');
$cek('per berkas (nama)', $t->instance()->rows->total() === 1);
$t = Livewire::test(Table::class)->set('scope', 'berkas')->set('search', $B);
$cek('cakupan berkas tidak mencocokkan NIP pegawai', collect($t->instance()->rows->items())->every(fn ($e) => str_contains((string) $e->file_path, $B) || str_contains((string) $e->target, $B)));
$t = Livewire::test(Table::class)->set('actionFilter', 'acl_change');
$cek('saring aksi', $t->instance()->rows->total() === 5);
$t = Livewire::test(Table::class)->set('dateFrom', '2026-11-17')->set('dateTo', '2026-11-17');
$cek('saring tanggal (WIB): 01:10 WIB kemarin malam UTC ikut 17 Nov WIB', $t->instance()->rows->total() === 20, (string) $t->instance()->rows->total());
$t = Livewire::test(Table::class)->set('dateFrom', '2026-11-18');
$cek('saring tanggal sesudahnya: kosong', $t->instance()->rows->total() === 0);
$satu = AuditEvent::where('req_id', 'r12')->first();
$t = Livewire::test(Table::class)->call('traceFile', $satu->id);
$cek('menu baris: Jejak berkas ini', $t->get('scope') === 'berkas' && $t->get('search') === '601' && $t->instance()->rows->total() === 1);
$t = Livewire::test(Table::class)->call('traceEmployee', $satu->id);
$cek('menu baris: Jejak pegawai ini', $t->get('scope') === 'pegawai' && $t->get('search') === $B);

echo "\n=== halaman ===\n";
$html = Livewire::test(Table::class)->html();
$cek('baris ada di dalam x-slot:table (tbody)', preg_match('#<tbody[^>]*>.*'.$A.'.*</tbody>#s', $html) === 1);
$cek('nama, aksi, IP, sebelum/sesudah tampil', str_contains($html, 'Pringgo Saputro') && str_contains($html, 'Ubah akses folder') && str_contains($html, '10.10.5.20') && str_contains($html, 'Sebelum:'));
$cek('tidak ada aksi hapus/ubah baris', ! str_contains($html, 'lucide-trash'));
$html = Livewire::test(Table::class)->set('search', 'tidak-ada-siapa-pun')->html();
$cek('empty state pencarian', str_contains($html, 'Tidak ada yang cocok'));
AuditEvent::query()->delete();
$html = Livewire::test(Table::class)->html();
$cek('empty state tanpa data', str_contains($html, 'Belum ada jejak akses'));
$html = Livewire::test(\Nawasara\Workspace\Livewire\Audit\Index::class)->html();
$cek('halaman induk memuat tabel', str_contains($html, 'Jejak Akses'));

echo "\n=== pangkas 1 tahun (AC-3) ===\n";
$tambah = fn (string $h, $at) => AuditEvent::create(['occurred_at' => $at, 'action' => 'open', 'nip' => $A, 'raw_hash' => hash('sha256', $h)]);
$tambah('lama', now()->subDays(366));
$tambah('hampir', now()->subDays(364));
$tambah('baru', now());
(new PruneAuditEventsJob(triggerSource: 'manual'))->handle();
$sj = SyncJob::where('action', 'prune_audit_events')->latest('id')->first();
$cek('lebih dari 365 hari dihapus, sisanya tetap', AuditEvent::count() === 2 && ! AuditEvent::where('raw_hash', hash('sha256', 'lama'))->exists() && ($sj->result['deleted'] ?? null) === 1, json_encode($sj?->result));
for ($i = 0; $i < 7; $i++) { $tambah("massal{$i}", now()->subDays(400)); }
$cek('pangkas berkelompok menghapus semua', $ingestor->prune(365, 3) === 7 && AuditEvent::count() === 2);

echo "\n=== jadwal ===\n";
$events = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())->map(fn ($e) => $e->description)->filter()->values()->all();
$cek('pangkas harian terjadwal', in_array('nawasara-workspace:prune-audit-events', $events, true), json_encode($events));
$cek('baca log tidak dijadwalkan tanpa sumber', ! in_array('nawasara-workspace:ingest-audit-log', $events, true));

echo "\n=== migrasi dapat dibalik ===\n";
(require glob($root.'/packages/nawasara-workspace/database/migrations/*_create_nawasara_workspace_audit_events_table.php')[0])->down();
(require glob($root.'/packages/nawasara-workspace/database/migrations/*_create_nawasara_workspace_audit_cursors_table.php')[0])->down();
$cek('down() membuang tabel', ! Schema::hasTable('nawasara_workspace_audit_events') && ! Schema::hasTable('nawasara_workspace_audit_cursors'));

@unlink($log); @rmdir($dir);
echo "\n".($gagal === 0 ? 'SEMUA LULUS' : "ADA {$gagal} YANG GAGAL")."\n";
exit($gagal === 0 ? 0 : 1);
