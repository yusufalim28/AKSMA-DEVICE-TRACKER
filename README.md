# AKSMA Device Tracker

Sistem pencatatan peminjaman dan pengembalian **laptop dan tablet sekolah** memakai QR Code. Data tersimpan di database server dan otomatis disalin ke **Google Sheets**. Petugas bisa memantau lewat dashboard dan mendapat **notifikasi email**.

Modul ini adalah bagian dari **AKSMA DIGITAL HUB** (SMA IT Akmala Sabila, program PLP Manajemen Pendidikan Islam). Bisa berjalan sendiri atau digabung dengan modul lain di bawah satu portal.

> **Dokumentasi lengkap** (use case, activity diagram, state diagram, sequence diagram, ERD, aturan bisnis, skenario uji): [docs/DOKUMENTASI.md](docs/DOKUMENTASI.md)

## Daftar isi
- [Fitur](#fitur)
- [Cara kerja](#cara-kerja)
- [Teknologi & kebutuhan hosting](#teknologi--kebutuhan-hosting)
- [Struktur folder](#struktur-folder)
- [Instalasi](#instalasi)
- [Hubungkan Google Sheets](#hubungkan-google-sheets)
- [Notifikasi email](#notifikasi-email)
- [Cron (opsional, disarankan)](#cron-opsional-disarankan)
- [Konfigurasi](#konfigurasi)
- [Cara memakai](#cara-memakai)
- [Aturan logika sistem](#aturan-logika-sistem)
- [Database](#database)
- [API internal](#api-internal)
- [Keamanan & privasi](#keamanan--privasi)
- [Memperbarui versi](#memperbarui-versi)
- [Pemecahan masalah](#pemecahan-masalah)
- [Batasan & rencana pengembangan](#batasan--rencana-pengembangan)
- [Menggabungkan dengan modul lain](#menggabungkan-dengan-modul-lain)

## Fitur
- **QR per perangkat.** Peminjam memindai QR dengan kamera HP. Formulir yang muncul menyesuaikan status: pinjam atau kembali.
- **Pilihan lama pinjam per jam** (1 sampai 4 jam), dibatasi jam layanan. Bila sisa waktu kurang dari 1 jam, muncul pilihan "sampai jam tutup".
- **Kode pengembalian 4 angka** yang diberikan saat meminjam, supaya hanya peminjam yang bisa mengembalikan lewat QR.
- **Dashboard petugas**: ringkasan status, indikator 30 hari, daftar perangkat, riwayat (dengan pencarian), tambah perangkat, cetak label QR.
- **Status otomatis**: Tersedia, Dipinjam, Terlambat, Perlu diperiksa.
- **Sinkron Google Sheets** (tab perangkat, transaksi, dan arsip) tanpa Apps Script di sisi alur utama; Apps Script hanya sebagai penerima kecil.
- **Notifikasi email** setiap perangkat dikembalikan (isi pesan menyesuaikan kondisinya) dan saat perangkat terlambat.
- **Hapus riwayat dan perangkat** dengan arsip ke Google Sheets.
- **Verifikasi NIS** (opsional) terhadap daftar siswa.

## Cara kerja
```
HP siswa ──scan QR──► index.html ──fetch──► api.php ──► SQLite (sumber data utama)
                                                │
                                                ├─► Google Apps Script (penerima) ──► Google Sheets
                                                └─► Google Apps Script (penerima) ──► Email petugas
```
- Database di server adalah **sumber kebenaran**. Google Sheets adalah **salinan**: setiap sinkron menulis ulang seluruh isi tab, sehingga tidak ada baris ganda atau selisih.
- Jika Google sedang gangguan, pencatatan tetap berjalan. Data menyusul pada sinkron berikutnya.
- Frontend adalah satu berkas HTML statis; semua logika dan validasi ada di PHP.

## Teknologi & kebutuhan hosting
| Bagian | Teknologi |
|---|---|
| Backend | PHP 8.0+ (tanpa framework, tanpa Composer) |
| Database | SQLite via PDO |
| Frontend | HTML, CSS, JavaScript murni |
| Google Sheets & email | Google Apps Script sebagai penerima webhook |
| QR | gambar dibuat lewat layanan `api.qrserver.com` saat label dicetak |

Ekstensi PHP yang dibutuhkan: `pdo_sqlite`, `curl`, `mbstring`. HTTPS wajib aktif. Hosting cPanel biasa sudah cukup.

## Struktur folder
```
.
├── index.php                  Portal AKSMA DIGITAL HUB (membaca */module.json)
├── core/                      Bagian bersama semua modul
│   ├── bootstrap.php          Konfigurasi, koneksi database, utilitas
│   ├── sheets.php             Klien Google Sheets/email (webhook)
│   ├── config.example.php     Contoh konfigurasi (salin jadi config.php)
│   └── config.php             Konfigurasi asli (TIDAK di-commit)
├── storage/                   Database, kunci, log (TIDAK di-commit, dikunci dari web)
├── google-sheets/
│   └── penerima.gs            Kode yang ditempel di Google Apps Script
├── docs/
│   └── DOKUMENTASI.md         Dokumentasi sistem dan diagram
└── device-tracker/
    ├── index.html             Antarmuka (siswa dan petugas)
    ├── api.php                Pintu masuk API (POST JSON)
    ├── sync.php               Endpoint cron: sinkron + cek keterlambatan
    ├── module.json            Metadata modul untuk portal
    ├── src/lib.php            Seluruh logika bisnis
    ├── src/sync.php           Sinkron database ke Google Sheets
    └── tools/import_siswa.php Impor daftar siswa (CLI)
```

## Instalasi
1. Unggah seluruh isi repositori ke `public_html` (atau subfolder/subdomain).
2. Salin `core/config.example.php` menjadi `core/config.php`, lalu isi `$PIN_PETUGAS` (dan `cron_key`).
3. Pastikan folder `storage/` bisa ditulis oleh PHP (izin 755).
4. Buka `https://DOMAIN-ANDA/PATH/device-tracker/?page=admin` dan masuk dengan PIN.
5. Menu **Tambah** untuk mendaftarkan perangkat, lalu menu **Label QR** untuk mencetak.

Uji lokal: pasang XAMPP, letakkan folder di `htdocs`, buka `http://localhost/NAMA-FOLDER/device-tracker/?page=admin`. QR yang dicetak dari localhost hanya berfungsi di komputer itu.

## Hubungkan Google Sheets
1. Buat Google Sheet kosong (sebaiknya dari **akun Google milik sekolah**, bukan akun pribadi).
2. Menu **Ekstensi, Apps Script**. Hapus isi bawaan, tempel seluruh isi `google-sheets/penerima.gs`.
3. Ganti `KATA_RAHASIA` di baris pertama dengan kata acak yang panjang, lalu simpan.
4. **Deploy, Deployment baru, jenis Aplikasi web**. Jalankan sebagai **Saya**, akses **Siapa saja**. Izinkan akses bila diminta.
5. Salin **URL aplikasi web** (berakhiran `/exec`).
6. Di `core/config.php`: isi `$WEBHOOK_URL` dengan URL itu dan `$KATA_RAHASIA` dengan kata yang **sama persis** dengan langkah 3.
7. Di halaman petugas, menu **Ringkasan**, tekan **Kirim sekarang**. Tab `DT_Perangkat` dan `DT_Transaksi` akan dibuat otomatis.

Catatan: jangan menekan **Jalankan** pada fungsi `doPost` di editor Apps Script (akan muncul error `postData`, itu normal). Fungsi itu hanya dipanggil lewat web. Setiap kode Apps Script diubah, buat versi baru lewat **Deploy, Kelola deployment**.

> Kata rahasia melindungi webhook agar tidak bisa dipakai orang lain. Jangan menaruh URL webhook dan kata rahasia di repositori publik.

## Notifikasi email
Email dikirim dari akun Google pemilik Google Sheet (kuota Gmail biasa sekitar 100 email per hari).
1. Isi `'notifikasi' => ['email' => 'alamat@contoh.sch.id']` di `config.php` (beberapa alamat dipisah koma).
2. Pastikan kode Apps Script memakai versi terbaru (`google-sheets/penerima.gs`).
3. Di editor Apps Script, pilih fungsi **izinkanEmail**, tekan **Jalankan** sekali, dan izinkan akses.
4. Buat versi baru di **Deploy, Kelola deployment**.
5. Di dashboard, tekan **Kirim email uji**.

Jenis notifikasi:
- **Setiap perangkat dikembalikan**, apa pun kondisinya. Judul dan isi pesan menyesuaikan:
  - *Baik*: pemberitahuan biasa, perangkat tersedia kembali.
  - *Ada lecet / Rusak ringan*: peringatan, perangkat berstatus Perlu diperiksa.
  - *Rusak berat*: bertanda **PERLU TINDAKAN**, perangkat tidak bisa dipinjam.
  Setiap pesan memuat perangkat, peminjam, waktu pinjam dan kembali, lama pinjam, ketepatan waktu (tepat waktu atau terlambat berapa lama), siapa yang mengonfirmasi (peminjam atau petugas), dan catatan.
- **Perangkat melewati batas waktu**: dirangkum dalam satu email; tiap peminjaman hanya diberitahukan sekali (setelah masa toleransi).

Kuota Gmail sekitar 100 email per hari. Jika pengembalian per hari sangat banyak, setel `notifikasi.kembali` ke `false`; hanya kondisi tidak baik dan keterlambatan yang tetap dikabari.

Email masuk ke antrean database dan dicoba ulang otomatis (maksimal 8 kali) bila gagal.

## Cron (opsional, disarankan)
Tanpa cron, pengecekan keterlambatan hanya berjalan saat ada yang memakai sistem (maksimal sekali per 5 menit). Dengan cron, pengecekan berjalan tepat waktu.

Di cPanel, **Cron Jobs**, pilih **Once Per Five Minutes**, lalu isi perintah:
```
curl -s "https://DOMAIN-ANDA/PATH/device-tracker/sync.php?key=KUNCI_CRON" > /dev/null 2>&1
```
`KUNCI_CRON` adalah nilai `cron_key` di `core/config.php`. Jika hosting tidak mengizinkan `curl`, pakai `wget -q -O /dev/null "ALAMAT"`. Cron juga mengulang sinkron yang sempat gagal dan memperbarui status "Terlambat" di Google Sheets.

## Konfigurasi
Semua pengaturan ada di `core/config.php` (lihat `config.example.php`).

| Kunci | Fungsi |
|---|---|
| `cron_key` | Kunci pada alamat `sync.php`. Wajib diganti. |
| `google.webhook_url` / `webhook_secret` | URL dan kata rahasia penerima Google Apps Script. Kosong = tanpa Google Sheets. |
| `device_tracker.admin_pin` | PIN petugas. Boleh berupa hash bcrypt (`$2y$...`). |
| `base_url` | Alamat dasar untuk QR. Kosong = dibuat otomatis dari alamat yang dipakai. |
| `pinjam_perlu_pin` | `true` = peminjaman harus didampingi petugas (butuh PIN). |
| `verifikasi_nis` | `true` = peminjam harus terdaftar di tabel siswa. |
| `maks_pinjam_per_orang` | Batas perangkat yang dipinjam bersamaan per orang (default 1). |
| `opsi_jam` | Pilihan lama pinjam dalam jam, mis. `[1, 2, 3, 4]`. |
| `min_sisa_menit` | Sisa waktu minimum agar pilihan "sampai jam tutup" muncul. |
| `jam_layanan` | Jam buka per hari (1=Senin ... 7=Minggu). Hari yang tidak ditulis = tutup. |
| `kondisi` | Daftar pilihan kondisi perangkat. `Baik` wajib ada. |
| `tab_perangkat`, `tab_transaksi`, `tab_arsip` | Nama tab di Google Sheets. |
| `arsip_riwayat` | `true` = riwayat yang dihapus disalin dulu ke tab arsip. |
| `notifikasi.email` | Alamat penerima notifikasi. Kosong = nonaktif. |
| `notifikasi.kembali` | `true` = kabari setiap pengembalian (default). |
| `notifikasi.perlu_periksa` | Bila `kembali` = `false`, tetap kabari kondisi tidak baik. |
| `notifikasi.terlambat`, `toleransi_menit` | Notifikasi keterlambatan dan toleransinya. |

### Verifikasi NIS (opsional)
Setel `verifikasi_nis => true`, siapkan CSV tanpa judul kolom (`nis,nama,kelas`), lalu jalankan:
```
php device-tracker/tools/import_siswa.php siswa.csv
```
Data siswa mengisi nama dan kelas otomatis dari NIS. Jangan menaruh data siswa asli di repositori.

## Cara memakai
**Petugas**
1. Daftarkan perangkat (kode aset unik, jenis, merek, model, kondisi).
2. Cetak dan tempel label QR pada perangkat.
3. Pantau di dashboard. Perangkat berstatus "Perlu diperiksa" diperiksa dulu, pilih kondisi terbaru, lalu tandai sudah diperiksa.

**Peminjam**
1. Pindai QR di perangkat dengan kamera HP.
2. Isi nama (dan kelas atau NIS), pilih lama pinjam, lalu kirim. **Catat kode 4 angka** yang muncul.
3. Saat mengembalikan, pindai QR yang sama, isi kode, pilih kondisi perangkat, kirim.

Jika kode hilang, petugas dapat mengembalikan perangkat dari dashboard (menu Perangkat) dengan PIN.

## Aturan logika sistem
- **Satu perangkat, satu peminjam aktif.** Dijamin oleh indeks unik di database dan transaksi `BEGIN IMMEDIATE`, sehingga dua pemindaian bersamaan diproses berurutan.
- **Batas pinjam per orang** diperiksa memakai kunci identitas (NIS, atau nama dan kelas yang dinormalkan).
- **Jam layanan**: peminjaman ditolak di luar jam layanan dan batas waktu tidak boleh melewati jam tutup.
- **Kode pengembalian** disimpan sebagai HMAC, bukan teks asli. Salah 5 kali, kode terkunci dan harus lewat petugas.
- **Status Terlambat dihitung dari waktu saat ini**, tidak disimpan.
- **Kondisi tidak baik saat dikembalikan** membuat perangkat berstatus Perlu diperiksa dan tidak bisa dipinjam sampai petugas memeriksanya. Kondisi "Rusak berat" tidak bisa dibuka kembali.
- **Percobaan PIN salah** dibatasi: 5 kali per IP dalam 10 menit.
- **Hapus riwayat**: hanya catatan yang sudah dikembalikan. Bila arsip aktif, salinan masuk Google Sheets dulu; jika gagal, tidak ada yang dihapus.
- **Hapus perangkat**: yang belum pernah dipinjam dihapus permanen; yang punya riwayat hanya diarsipkan. Perangkat yang sedang dipinjam tidak bisa dihapus.

### Status perangkat
| Status | Arti |
|---|---|
| Tersedia | Bisa dipinjam |
| Dipinjam | Sedang dipinjam dan belum melewati batas |
| Terlambat | Belum dikembalikan setelah batas waktu |
| Perlu diperiksa | Menunggu pemeriksaan petugas; tidak bisa dipinjam |

## Database
SQLite di `storage/aksma.sqlite`. Tabel milik modul ini berawalan `dt_`; skema dibuat dan dimigrasi otomatis saat API diakses.

| Tabel | Isi |
|---|---|
| `dt_device` | Data perangkat (kode aset, jenis, merek, model, kondisi, status periksa, aktif) |
| `dt_trx` | Transaksi peminjaman dan pengembalian |
| `dt_siswa` | Daftar siswa untuk verifikasi NIS (opsional) |
| `dt_log` | Jejak aksi (pinjam, kembali, tambah, hapus, dan sebagainya) |
| `dt_notif` | Antrean notifikasi email |
| `dt_gagal` | Catatan percobaan PIN salah |
| `meta` | Penanda internal (status sinkron dan lainnya) |

Tab Google Sheets: `DT_Perangkat`, `DT_Transaksi` (ditulis ulang tiap sinkron), dan `DT_Arsip` (hanya bertambah).

## API internal
`POST device-tracker/api.php` dengan body JSON `{"fn": "...", "args": [...]}` dan header `X-Requested-With: aksma`. Jawaban: `{"ok": true, "data": ...}` atau `{"ok": false, "error": "..."}`.

| Fungsi | Akses | Kegunaan |
|---|---|---|
| `getDevice` | publik | Info perangkat dan pilihan pinjam |
| `pinjam`, `kembali` | publik | Peminjaman dan pengembalian (kode 4 angka) |
| `adminData` | PIN | Data dashboard |
| `adminAddDevice`, `adminTandaiDiperiksa`, `adminKembalikan` | PIN | Kelola perangkat dan pengembalian oleh petugas |
| `adminHapusPerangkat`, `adminHapusRiwayat`, `adminHapusRiwayatLama` | PIN | Penghapusan dan pengarsipan |
| `adminSyncSekarang`, `adminTesNotif` | PIN | Uji sinkron dan email |

API hanya menerima permintaan dari frontend sendiri (header kustom wajib), bukan dari formulir lintas situs.

## Keamanan & privasi
- **Jangan commit** `core/config.php`, folder `storage/`, URL webhook, kata rahasia, PIN, atau kunci cron. Semuanya sudah diabaikan di `.gitignore`.
- Folder `core/`, `storage/`, `device-tracker/src/`, dan `tools/` dikunci dari akses web lewat `.htaccess` (Apache). Pada Nginx, tambahkan aturan setara.
- Gunakan **HTTPS**; PIN petugas dikirim pada setiap permintaan petugas.
- Data peminjam (nama, kelas, NIS) ikut tertulis di Google Sheets. **Batasi akses Sheet** hanya untuk petugas berwenang.
- Gunakan akun Google yang dikelola sekolah agar sistem tidak putus saat pengelola berganti.
- Ada satu PIN bersama untuk semua petugas (belum ada akun per petugas).

## Memperbarui versi
1. Unggah berkas baru **tanpa menimpa** `core/config.php` dan folder `storage/`.
2. Jika `penerima.gs` berubah, tempel versi baru di Apps Script lalu buat versi baru di **Kelola deployment** (URL tetap sama).
3. Buka halaman sistem sekali. Skema database menyesuaikan diri otomatis.
4. Cadangkan `storage/aksma.sqlite` sebelum dan secara berkala setelah memperbarui.

## Pemecahan masalah
| Gejala | Penyebab dan solusi |
|---|---|
| `TypeError ... postData` di Apps Script | Normal bila menekan Jalankan di editor. Cukup Deploy. |
| "Kata rahasia ... tidak sama" | `KATA_RAHASIA` di Apps Script berbeda dari `config.php`. |
| "Google tidak menjawab OK" | Deployment belum versi terbaru, atau akses bukan "Siapa saja". |
| Sinkron gagal untuk data lebih dari 1.000 baris | Pakai `penerima.gs` versi terbaru (menambah baris otomatis). |
| Email uji gagal | Jalankan `izinkanEmail` sekali, lalu buat versi baru deployment. |
| "unable to open database" / halaman kosong | Izin folder `storage/` (755) dan versi PHP minimal 8.0. |
| Status "Terlambat" tidak cepat berubah di Sheet | Pasang cron 5 menit. |
| QR tidak terbuka dari HP saat uji lokal | `localhost` hanya dikenali komputer itu. Uji di hosting. |

Log galat server ada di `storage/galat.log`.

## Batasan & rencana pengembangan
- Belum ada tes otomatis.
- Gambar QR dibuat lewat layanan eksternal, sehingga butuh internet saat mencetak label.
- Sistem memastikan pengembali memiliki kode yang benar, **bukan** bahwa perangkat sudah fisik di tangan petugas.
- Ide lanjutan: notifikasi Telegram, konfirmasi pengembalian oleh petugas, pembuatan QR lokal, akun petugas terpisah, ekspor laporan.

## Menggabungkan dengan modul lain
`index.php` di root adalah portal yang otomatis menampilkan setiap folder berisi `module.json`. Agar modul-modul tidak bentrok:
- Satu folder per modul, dengan `module.json` (contoh: `device-tracker/module.json`).
- Gunakan `core/` yang sama: `require __DIR__.'/../core/bootstrap.php'`.
- Prefix tabel database per modul (`dt_`, `gi_`, `gh_`) dan prefix tab Sheets (`DT_`, `GI_`, `GH_`).
- Satu blok konfigurasi per modul di `core/config.php`.

## Lisensi
Belum ditentukan. Tambahkan berkas `LICENSE` sebelum repositori dipublikasikan.
