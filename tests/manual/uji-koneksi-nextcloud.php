<?php
/*
 * Uji NextcloudAdminClient dengan jawaban Nextcloud palsu (Http::fake).
 *
 * Yang diuji: kontrak tombol Uji Vault (kunci `success`, bukan `ok`) dan
 * pesan yang dibaca admin untuk tiap kegagalan yang sering terjadi.
 *
 *   php packages/nawasara-workspace/tests/manual/uji-koneksi-nextcloud.php
 */

$root = dirname(__DIR__, 4);
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Nawasara\Workspace\Services\NextcloudAdminClient;

$gagal = 0;
$cek = function (string $label, bool $ok, string $detail = '') use (&$gagal) {
    if (! $ok) { $gagal++; }
    echo ($ok ? '  OK   ' : '  GAGAL')." {$label}".($detail ? "\n         {$detail}" : '')."\n";
};

$client = new NextcloudAdminClient('http://10.1.1.37:8080/nc', 'kisara-admin', 'app-pass-uji');
$caps = fn (bool $oo) => ['ocs' => ['data' => [
    'version' => ['string' => '31.0.14'],
    'capabilities' => $oo ? ['onlyoffice' => ['formats' => []]] : [],
]]];

echo "=== terhubung, konektor aktif ===\n";
Http::swap(new Factory); // stub baru tiap skenario; fake() menumpuk
Http::fake(['*' => Http::response($caps(true))]);
$r = $client->testConnection();
$cek('kunci `success` true', ($r['success'] ?? null) === true);
$cek('tidak memakai kunci `ok`', ! array_key_exists('ok', $r));
$cek('pesan menyebut versi', str_contains($r['message'], '31.0.14'), $r['message']);
Http::assertSent(fn (Request $q) => $q->url() === 'http://10.1.1.37:8080/nc/ocs/v2.php/cloud/capabilities?format=json'
    && $q->hasHeader('OCS-APIRequest', 'true')
    && str_starts_with($q->header('Authorization')[0] ?? '', 'Basic '));
$cek('memanggil capabilities dengan Basic auth + OCS-APIRequest', true);

echo "\n=== terhubung, konektor TIDAK aktif ===\n";
Http::swap(new Factory); // stub baru tiap skenario; fake() menumpuk
Http::fake(['*' => Http::response($caps(false))]);
$r = $client->testConnection();
$cek('tetap success, tetapi pesan memperingatkan', $r['success'] === true && str_contains($r['message'], 'BELUM aktif'), $r['message']);

echo "\n=== app password salah (401) ===\n";
Http::swap(new Factory); // stub baru tiap skenario; fake() menumpuk
Http::fake(['*' => Http::response(['ocs' => []], 401)]);
$r = $client->testConnection();
$cek('success false', $r['success'] === false);
$cek('pesan mengarah ke app password', str_contains($r['message'], 'app password'), $r['message']);

echo "\n=== Nextcloud tidak terjangkau ===\n";
Http::swap(new Factory); // stub baru tiap skenario; fake() menumpuk
Http::fake(['*' => fn () => throw new ConnectionException('cURL error 28: timed out')]);
$r = $client->testConnection();
$cek('success false + pesan tidak dapat dihubungi', $r['success'] === false && str_contains($r['message'], 'tidak dapat dihubungi'), $r['message']);

echo "\n=== Vault belum diisi ===\n";
$kosong = new NextcloudAdminClient('', '', '');
Http::swap(new Factory); // stub baru tiap skenario; fake() menumpuk
Http::fake();
$r = $kosong->testConnection();
$cek('success false, tidak memanggil apa pun', $r['success'] === false && str_contains($r['message'], 'Isi base_url'));
Http::assertNothingSent();

echo "\n".($gagal === 0 ? 'SEMUA LULUS' : "ADA {$gagal} YANG GAGAL")."\n";
exit($gagal === 0 ? 0 : 1);
