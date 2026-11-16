<?php
/*
 * Uji NextcloudAdminClient (T-09): jalur + muatan sama persis dengan yang
 * diverifikasi di Nextcloud 31.0.14 / groupfolders 19.1.20 (LXC 145), dan
 * penanganan jawaban yang memang diharapkan (404 user, OCS 102 grup ada).
 */

$root = dirname(__DIR__, 4);
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Nawasara\Workspace\Services\NextcloudAdminClient;
use Nawasara\Workspace\Services\NextcloudException;

$gagal = 0;
$cek = function (string $label, bool $ok, string $detail = '') use (&$gagal) {
    if (! $ok) { $gagal++; }
    echo ($ok ? '  OK   ' : '  GAGAL')." {$label}".($detail ? "\n         {$detail}" : '')."\n";
};

$c = new NextcloudAdminClient('http://10.1.1.37:8080/nc', 'kisara-admin', 'pw');
$ok = fn ($data = [], $code = 200) => Http::response(['ocs' => ['meta' => ['status' => 'ok', 'statuscode' => $code, 'message' => 'OK'], 'data' => $data]]);
$fail = fn ($http, $code, $msg = 'x') => Http::response(['ocs' => ['meta' => ['status' => 'failure', 'statuscode' => $code, 'message' => $msg], 'data' => []]], $http);
$sent = [];
$fake = function ($responder) use (&$sent) {
    Http::swap(new Factory);
    $sent = [];
    Http::fake(function (Request $r) use ($responder, &$sent) { $sent[] = $r; return $responder($r); });
};

echo "=== akun ===\n";
$fake(fn () => Http::response(['ocs' => ['meta' => ['status' => 'failure', 'statuscode' => 404], 'data' => []]], 404));
$cek('user tidak ada: null, bukan galat', $c->getUser('199900001111222233') === null);

$fake(fn () => $ok(['id' => '199506142020121004']));
$c->createUser('199506142020121004', 'Pringgo', 'p@ponorogo.go.id', '3 GB', ['OPD-7']);
$r = $sent[0];
$cek('POST /nc/ocs/v2.php/cloud/users', $r->method() === 'POST' && $r->url() === 'http://10.1.1.37:8080/nc/ocs/v2.php/cloud/users', $r->url());
$body = $r->data();
$cek('muatan JSON: userid, displayName, quota, groups, email', $body['userid'] === '199506142020121004' && $body['displayName'] === 'Pringgo' && $body['quota'] === '3 GB' && $body['groups'] === ['OPD-7'] && $body['email'] === 'p@ponorogo.go.id');
$cek('sandi acak panjang (tak pernah dipakai)', strlen($body['password']) >= 32);
$cek('header OCS-APIRequest + Basic auth', $r->hasHeader('OCS-APIRequest', 'true') && str_starts_with($r->header('Authorization')[0] ?? '', 'Basic '));

$fake(fn () => $ok());
$c->updateUser('199506142020121004', 'quota', '5 GB');
$cek('PUT key/value', $sent[0]->method() === 'PUT' && $sent[0]->data() === ['key' => 'quota', 'value' => '5 GB']);
$c->disableUser('199506142020121004');
$cek('disable = PUT .../disable', str_ends_with($sent[1]->url(), '/cloud/users/199506142020121004/disable'));

echo "\n=== grup ===\n";
$fake(fn () => $fail(400, 102, 'group exists'));
$lempar = false;
try { $c->ensureGroup('OPD-7'); } catch (NextcloudException) { $lempar = true; }
$cek('grup sudah ada (OCS 102): tidak dianggap galat', ! $lempar);
$fake(fn () => $fail(400, 103, 'boom'));
$lempar = false;
try { $c->ensureGroup('OPD-7'); } catch (NextcloudException $e) { $lempar = $e->ocsCode === 103; }
$cek('galat lain tetap dilempar', $lempar);
$fake(fn () => $ok());
$c->removeUserFromGroup('199506142020121004', 'OPD-7');
$cek('keluarkan dari grup = DELETE + groupid', $sent[0]->method() === 'DELETE' && $sent[0]->data() === ['groupid' => 'OPD-7']);

echo "\n=== Team Folders ===\n";
$fake(fn () => $ok(['id' => 12, 'mount_point' => 'Diskominfo'], 100));
$id = $c->createTeamFolder('Diskominfo');
$cek('POST /nc/index.php/apps/groupfolders/folders?format=json (bukan /ocs/v2.php)', $sent[0]->url() === 'http://10.1.1.37:8080/nc/index.php/apps/groupfolders/folders?format=json', $sent[0]->url());
$cek('statuscode 100 diterima sebagai sukses, id dibaca', $id === 12);

$fake(fn () => $ok(['success' => true], 100));
$c->assignTeamFolderGroup(12, 'OPD-7');
$c->setTeamFolderQuota(12, 53687091200);
$c->enableTeamFolderAcl(12, 'OPD-7');
$urls = array_map(fn ($r) => preg_replace('#^.*/folders#', '', $r->url()), $sent);
$cek('urutan panggilan folder', $urls === ['/12/groups?format=json', '/12/quota?format=json', '/12/acl?format=json', '/12/manageACL?format=json'], json_encode($urls));
$cek('pengelola ACL = grup OPD', $sent[3]->data() === ['mappingType' => 'group', 'mappingId' => 'OPD-7', 'manageAcl' => true]);

$fake(fn () => $ok(['1' => ['id' => 1, 'mount_point' => 'Diskominfo', 'groups' => ['OPD-7' => 31], 'quota' => 53687091200, 'acl' => true]], 100));
$list = $c->teamFolders();
$cek('daftar folder dibaca per id', isset($list[1]) && $list[1]['groups'] === ['OPD-7' => 31] && $list[1]['acl'] === true);

echo "\n=== galat ===\n";
$fake(fn () => Http::response(['ocs' => ['meta' => ['status' => 'failure', 'statuscode' => 998, 'message' => 'Invalid query']]], 200));
$lempar = false;
try { $c->createTeamFolder('X'); } catch (NextcloudException $e) { $lempar = str_contains($e->getMessage(), 'OCS 998'); }
$cek('status failure di dalam HTTP 200 tetap galat', $lempar);
$fake(fn () => Http::response([], 401));
$lempar = false;
try { $c->getUser('x'); } catch (NextcloudException $e) { $lempar = $e->httpStatus === 401 && str_contains($e->getMessage(), 'app password'); }
$cek('401 → pesan app password', $lempar);

echo "\n".($gagal === 0 ? 'SEMUA LULUS' : "ADA {$gagal} YANG GAGAL")."\n";
exit($gagal === 0 ? 0 : 1);
