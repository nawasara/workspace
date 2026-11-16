# Nawasara Workspace

Bagian Nawasara dari **Kisara Workspace** (Docs, Sheets, Slides, dan Drive
untuk ASN Pemkab Ponorogo). Paket ini adalah *control plane*: siapa yang boleh
masuk selama pilot, akun dan grup OPD di Nextcloud, Folder OPD, kuota, templat,
dan pegawai yang keluar.

Paket ini **tidak** menyimpan berkas. Berkas, berbagi, versi, dan sampah
milik Nextcloud; layar yang dilihat pegawai ada di Workspace Web
(`pringgojs/kisara-workspace-web`). Rencana lengkap ada di
`docs/plan/kisara-workspace/` repo nawasara-dev.

Bekerja bersama: `nawasara/vault` (kredensial), `nawasara/registry` (OPD),
`nawasara/keycloak` (direktori pegawai), `nawasara/ui`.

## Status v0.1.0

| Fitur | Status |
|---|---|
| Halaman Ringkasan: koneksi ke Nextcloud, versi, konektor OnlyOffice | ✅ |
| Grup Vault `workspace` + tombol Uji | ✅ |
| Peserta Pilot: daftar, tambah dari direktori Keycloak, cabut/hapus, kill switch (T-05) | ✅ |
| API akses `GET /api/v1/workspace/access/{nip}`, scope `workspace.access.read` (T-06) | ✅ |
| Direktori `GET /api/v1/workspace/directory`, scope `workspace.directory.read`: cari pegawai (nama/NIP) dan OPD untuk Bagikan dan @mention (T-25) | ✅ |
| Sinkron grup Keycloak `workspace-pilot`: setelah perubahan + tiap 15 menit (T-07) | ✅ |
| Klien admin Nextcloud: akun, grup, kuota, Team Folders + ACL (T-09) | ✅ |
| Akun Nextcloud untuk seluruh ASN dari cache Keycloak, UID = NIP, bertahap per putaran (T-10) | ✅ |
| OPD tiap pegawai dari `simas_skpd` (cocok utuh), override peserta pilot (T-11) | ✅ |
| Folder OPD: satu Team Folder per OPD, kuota 50 GB, ACL dikelola grup OPD sendiri (T-12) | ✅ |
| Penyimpangan Kuota: kuota sendiri per pegawai / per Folder OPD, dengan alasan dan pelaku (T-14) | ✅ |
| Layar atur akses subfolder di Workspace Web (T-13) | ⏳ |
| Templat, pegawai keluar, jejak akses | ⏳ |

## Setup

```bash
composer require nawasara/workspace
php artisan migrate
php artisan db:seed --class="Nawasara\Workspace\Database\Seeders\PermissionSeeder"
```

Tambahkan di `resources/css/app.css`, atau semua kelas Tailwind paket ini
tidak ter-compile:

```css
@source "../../vendor/nawasara/workspace";
```

Lalu isi grup Vault **workspace** dan tekan **Uji**:

| Field | Isi |
|---|---|
| `base_url` | Alamat internal Nextcloud **termasuk `/nc`**, mis. `http://10.1.1.37:8080/nc` |
| `admin_user` | Akun admin Nextcloud (mis. `kisara-admin`) |
| `app_password` | **App password** akun itu, bukan sandi login |

## Permissions

| Permission | Untuk |
|---|---|
| `workspace.view` | Ringkasan dan halaman baca |
| `workspace.pilot.manage` | Peserta pilot, kill switch |
| `workspace.quota.view` | Halaman Penyimpangan Kuota |
| `workspace.quota.manage` | Menambah, mengubah, menghapus penyimpangan kuota |
| `workspace.folder.manage` | Folder OPD; membatalkan pembatasan subfolder |
| `workspace.template.manage` | Kelola Templat |
| `workspace.offboarding.manage` | Antrean Drive pegawai keluar |
| `workspace.audit.view` | Jejak akses |

## Keputusan yang mudah dibatalkan tanpa tahu alasannya

- **Akun dibuat untuk SELURUH ASN, bukan hanya peserta pilot** (FR-10): supaya
  siapa pun bisa dicari dan dibagikan berkas sejak hari pertama. Mereka tetap
  tidak bisa masuk sebelum lolos gerbang pilot.
- **Tabel `nawasara_workspace_accounts` + `state_hash`**: tiap putaran hanya
  menulis akun yang baru/berubah. Tanpa itu, setiap 15 menit ~11 000 panggilan
  ke Nextcloud. Maks `provision_batch` (1000) tulisan per putaran; putaran
  pertama dicicil.
- **OPD dicocokkan UTUH**, bukan awalan, dan peta OPD dimuat sekali per
  putaran (UnitKerjaMatcher bawaan memuat semua OPD per panggilan).
- **Hanya grup `OPD-*` yang diatur sinkron akun.** Grup lain (`workspace-pilot`
  dari user_oidc) tidak pernah disentuh.

- **Kuota: bawaan dari config, penyimpangan dari tabel, diterapkan sinkron.**
  Bawaan `quota.drive_default` (3 GB) dan `quota.opd_folder_default` (50 GB)
  di `config/nawasara-workspace.php`; pengecualiannya baris di
  `nawasara_workspace_quota_overrides` (halaman Penyimpangan Kuota). Kuota
  diset lewat OCS oleh sinkron akun/folder, **bukan** lewat `mapping-quota`
  user_oidc: dua sumber akan saling menimpa setiap kali pegawai masuk.
- **Kuota pegawai ikut `state_hash`.** Mengubah atau menghapus penyimpangan
  mengubah hash satu akun itu, sehingga hanya akun itu yang ditulis ulang.
  Menyimpan penyimpangan langsung memicu sinkron akun (`sync_on_change`).
- **Kuota Folder OPD dibandingkan dengan yang dilaporkan Nextcloud**, bukan
  dengan baris lokal: kuota yang diubah tangan di Nextcloud juga dikembalikan.
- **Penyimpangan hanya dihapus, tidak dinonaktifkan.** Penyimpangan yang
  berhenti berlaku sama artinya dengan "bawaan"; baris nonaktif hanya cara
  kedua mengatakan hal yang sama. Riwayat (nilai, alasan, siapa) tetap di log
  aktivitas `workspace`. Hapus butuh sudo.
- **Alasan wajib.** Penyimpangan yang tidak bisa dijelaskan adalah yang
  pertama "dirapikan" orang lain.
- **Satuan biner** (1 GB = 1024^3 byte), sama dengan Nextcloud, supaya angka
  di Nawasara dan di halaman admin Nextcloud sama.

- **Team Folders di `/index.php/apps/groupfolders/folders?format=json`, bukan
  `/ocs/v2.php`.** Pada groupfolders 19.1.20 (Nextcloud 31) jalur OCS v2 menjawab
  404, dan sukses ber-`statuscode` 100. Diverifikasi langsung di LXC 145
  (17 Nov 2026), bukan dari dokumentasi.

- **Tidak ada peran "admin OPD"** (D-03). Anggota OPD sendiri yang membatasi
  subfolder Folder OPD-nya, dari Workspace Web dengan token mereka sendiri.
  Permission di atas semuanya untuk admin Kisara di Diskominfo.
- **Akun admin Nextcloud hanya dipakai Nawasara**, untuk pekerjaan admin
  (akun, grup, folder, kuota). Workspace Web tidak pernah memegangnya: ia
  bertindak sebagai pegawai dengan token pegawai itu (ADR 0003), sehingga
  Nextcloud yang memutuskan hak akses berkas.
- **App password, bukan sandi login admin**, di Vault: dapat dicabut sendiri
  tanpa mengganti sandi admin.
- **`base_url` alamat internal**, bukan `https://docs.ponorogo.go.id/nc`.
  Port 8080 di LXC Workspace hanya menerima 10.1.1.30 (LXC Nawasara), dan
  jalur internal tidak melewati Cloudflare.

## Roadmap

Lihat `docs/plan/kisara-workspace/04-tasks.md` (epic E-01, E-02, E-07..E-10).

## Author

Pringgo J. Saputro, Diskominfo Kabupaten Ponorogo

## License

MIT

## Endpoint

`GET /api/v1/workspace/access/{nip}` (scope `workspace.access.read`), selalu
200:

```json
{ "data": { "nip": "...", "allowed": true, "reason": null, "stage": 0,
            "opd": { "id": 7, "name": "Diskominfo" }, "is_admin": false } }
```

`reason` saat ditolak: `kill_switch`, `account_disabled`, `not_participant`,
`revoked`, `invalid_nip`. Urutan aturan di `Services/AccessDecider.php`: kill
switch mengalahkan semuanya; akun Kisara nonaktif selalu ditolak; `pilot_open`
(tahap 3) membuka untuk semua ASN; selain itu hanya peserta aktif.

`GET /api/v1/workspace/directory?q=&limit=&actor=` (scope
`workspace.directory.read`). `q` minimal 3 karakter: angka saja = awalan NIP,
selain itu nama (setiap kata harus cocok dengan nama depan/belakang). `limit`
bawaan 10, maks 20. `actor` = NIP pegawai yang mengetik (opsional, untuk batas
laju per pegawai).

```json
{ "data": {
    "people": [ { "nip": "199506142020121004", "name": "Pringgo Saputro",
                  "jabatan": "PRANATA KOMPUTER AHLI PERTAMA", "unit": "BIDANG APLIKASI INFORMATIKA",
                  "opd": { "id": 7, "name": "Dinas Komunikasi dan Informatika" } } ],
    "opds": [ { "id": 7, "name": "Dinas Komunikasi dan Informatika", "group": "OPD-7" } ] },
  "meta": { "q": "pringgo", "limit": 10 } }
```

Galat: `422 query_too_short`, `422 invalid_actor`, `429 rate_limited`
(dengan `Retry-After`), `401`/`403` dari middleware token/scope.

Keputusan direktori:

- **Sumber cache Keycloak, bukan Nextcloud**, supaya ASN yang belum pernah
  membuka Workspace ikut ketemu (FR-10 AC-1). Aturan "ASN" sama dengan sinkron
  akun (aktif + username NIP 18 digit), jadi yang ditemukan pasti punya akun
  Nextcloud UID = NIP untuk dibagikan.
- **Yang dicari hanya NIP dan nama depan/belakang.** Bukan email, bukan JSON
  `attributes`: pencarian di sana menjadi oracle ("adakah yang nomor WA-nya
  0812...") walau nilainya tidak pernah dikembalikan.
- **Jawaban daftar-izin** (`DirectoryPersonResource`, `DirectoryOpdResource`):
  nip, nama, jabatan, unit, OPD. NIP ikut karena itu UID Nextcloud untuk
  berbagi dan @mention.
- **Jabatan/unit/OPD dari atribut `simas_*`** yang diisi authenticator saat
  login. ASN yang belum pernah masuk aplikasi Kisara mana pun tetap ditemukan,
  dengan ketiganya `null`.
- **OPD hanya yang sudah punya akun Workspace**: grup `OPD-<id>` dibuat oleh
  sinkron akun; OPD tanpa akun belum punya grup untuk dibagikan.
- **Batas laju dua lapis** (`directory.*` di config): per token 600/menit,
  per `actor` 60/menit.
- **Indeks**: cari NIP memakai indeks `username` (awalan). Cari nama memindai
  `first_name`/`last_name` (~11 000 baris, tanpa indeks; diukur p95 ~7 ms di
  SQLite uji). Indeks biasa tidak menolong `LIKE '%kata%'`; bila lambat di
  produksi, tambahkan FULLTEXT atau kolom nama ternormalisasi di paket
  `nawasara/keycloak`, bukan di sini.

## Uji

Lewat remote-test (VM tes), dari root nawasara-dev:

```bash
bash <skills>/tooling/remote-test.sh -- 'composer install -n && php artisan package:discover \
  && for t in uji-koneksi-nextcloud uji-klien-admin uji-akses uji-peserta-pilot uji-grup-pilot uji-provisioning uji-kuota uji-direktori; do \
     php packages/nawasara-workspace/tests/manual/$t.php; done'
```
