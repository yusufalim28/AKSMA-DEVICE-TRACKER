# Dokumentasi Sistem AKSMA Device Tracker

| | |
|---|---|
| **Nama sistem** | AKSMA Device Tracker (bagian dari AKSMA DIGITAL HUB) |
| **Konteks** | Program PLP Manajemen Pendidikan Islam, SMA IT Akmala Sabila |
| **Jenis dokumen** | Dokumentasi sistem: kebutuhan, perancangan, dan pengoperasian |
| **Versi dokumen** | 1.0 (Oktober 2026) |
| **Pembaca** | Pengelola sekolah dan petugas (bagian 1 sampai 3), pengembang (bagian 4 sampai 11) |

> Diagram di dokumen ini ditulis dengan [Mermaid](https://mermaid.js.org/) sehingga tampil otomatis di GitHub. Jika diagram tidak tampil di aplikasi lain, salin kodenya ke <https://mermaid.live>.

## Daftar isi
1. [Gambaran umum (untuk semua pembaca)](#1-gambaran-umum-untuk-semua-pembaca)
2. [Aktor dan hak akses](#2-aktor-dan-hak-akses)
3. [Panduan singkat penggunaan](#3-panduan-singkat-penggunaan)
4. [Kebutuhan sistem](#4-kebutuhan-sistem)
5. [Use case diagram](#5-use-case-diagram)
6. [Activity diagram](#6-activity-diagram)
7. [Status perangkat (state diagram)](#7-status-perangkat-state-diagram)
8. [Sequence diagram](#8-sequence-diagram)
9. [Arsitektur dan deployment](#9-arsitektur-dan-deployment)
10. [Perancangan data (ERD dan kamus data)](#10-perancangan-data-erd-dan-kamus-data)
11. [Aturan bisnis, API, dan keamanan](#11-aturan-bisnis-api-dan-keamanan)
12. [Skenario pengujian](#12-skenario-pengujian)
13. [Batasan dan pengembangan lanjutan](#13-batasan-dan-pengembangan-lanjutan)
14. [Glosarium](#14-glosarium)

---

## 1. Gambaran umum (untuk semua pembaca)

### 1.1 Masalah yang diselesaikan
Sekolah memiliki laptop dan tablet yang dipinjam siswa. Bila pencatatannya manual, sering terjadi: tidak tahu siapa yang sedang meminjam, perangkat terlambat kembali tanpa ada yang menyadari, kondisi perangkat tidak tercatat, dan rekap yang memakan waktu.

### 1.2 Solusi singkat
Setiap perangkat ditempeli **QR Code**. Siswa memindai QR dengan kamera HP, mengisi identitas, lalu sistem mencatat peminjaman. Saat mengembalikan, siswa memindai QR yang sama dan memasukkan **kode 4 angka** yang didapat saat meminjam. Petugas memantau semuanya lewat **dashboard**, menerima **email** otomatis, dan seluruh data tersalin ke **Google Sheets**.

### 1.3 Gambaran dalam satu diagram

```mermaid
flowchart LR
  A["Siswa memindai QR di perangkat"] --> B["Sistem mencatat pinjam<br/>dan memberi kode 4 angka"]
  B --> C["Siswa memakai perangkat"]
  C --> D["Siswa memindai QR lagi<br/>dan memasukkan kode"]
  D --> E["Sistem mencatat kembali<br/>dan kondisi perangkat"]
  E --> F["Data masuk Google Sheets<br/>dan email ke petugas"]
```

### 1.4 Tujuan dan indikator keberhasilan
| Tujuan | Indikator |
|---|---|
| Peminjaman tertib dan terdokumentasi | Seluruh transaksi tercatat |
| Akuntabilitas aset | Berkurangnya perangkat yang tidak diketahui peminjamnya |
| Efisiensi administrasi | Waktu pencatatan dan rekap lebih singkat |
| Pemantauan kondisi | Tersedia riwayat kondisi saat pinjam dan kembali |

### 1.5 Ruang lingkup
Termasuk: pencatatan peminjaman dan pengembalian, dashboard petugas, label QR, sinkron Google Sheets, notifikasi email, pengarsipan data.
Tidak termasuk: pelacakan lokasi perangkat, pemblokiran perangkat dari jarak jauh, pengelolaan aset selain laptop dan tablet, dan sistem tap tanpa kamera (butuh NFC).

---

## 2. Aktor dan hak akses

| Aktor | Peran | Cara masuk | Hak |
|---|---|---|---|
| **Siswa / peminjam** | Meminjam dan mengembalikan perangkat | Tanpa akun; memindai QR | Melihat status perangkat, meminjam, mengembalikan dengan kode |
| **Petugas sarpras** | Mengelola perangkat dan memantau | PIN petugas | Seluruh fitur dashboard |
| **Cron / sistem terjadwal** | Memicu pengecekan berkala | Kunci pada alamat `sync.php` | Sinkron, cek keterlambatan, kirim antrean email |
| **Google (Sheets dan Gmail)** | Sistem eksternal | Webhook dengan kata rahasia | Menerima data dan mengirim email |

---

## 3. Panduan singkat penggunaan

### 3.1 Untuk siswa
1. Pindai QR di perangkat dengan kamera HP. Halaman terbuka otomatis.
2. Isi nama dan kelas (atau NIS bila diwajibkan), pilih lama pinjam, tekan **Pinjam**.
3. **Catat kode 4 angka** yang muncul. Kode ini dibutuhkan saat mengembalikan.
4. Saat selesai, pindai QR yang sama, masukkan kode, pilih kondisi perangkat, tekan **Kembalikan**.
5. Kode hilang? Datangi petugas.

### 3.2 Untuk petugas
1. Buka halaman petugas, masuk dengan PIN.
2. **Tambah** perangkat, lalu **Label QR** untuk mencetak dan menempel.
3. Pantau di **Ringkasan**, **Perangkat**, dan **Riwayat**.
4. Perangkat berstatus **Perlu diperiksa**: periksa fisiknya, pilih kondisi terbaru, tandai sudah diperiksa.

### 3.3 Pertanyaan yang sering muncul
| Pertanyaan | Jawaban |
|---|---|
| Mengapa perlu kode 4 angka? | QR menempel di perangkat dan bisa dipindai siapa saja. Kode memastikan hanya peminjam yang bisa mengembalikan lewat QR. |
| Kode hilang? | Petugas mengembalikan dari dashboard (butuh PIN petugas). |
| Salah memasukkan kode? | Setelah 5 kali salah, kode terkunci dan harus lewat petugas. |
| Apa arti "Perlu diperiksa"? | Perangkat dikembalikan dalam kondisi tidak baik, atau baru didaftarkan dengan kondisi tidak baik. Tidak bisa dipinjam sampai petugas memeriksanya. |
| Jika Google Sheets sedang gangguan? | Pencatatan tetap berjalan; data menyusul pada sinkron berikutnya. |
| Jika Google Sheet dihapus atau diganti? | Data utama ada di database server, jadi tidak hilang. Sheet baru terisi penuh setelah **Kirim sekarang**. |

---

## 4. Kebutuhan sistem

### 4.1 Kebutuhan fungsional
| Kode | Kebutuhan |
|---|---|
| FR-01 | Sistem mengidentifikasi perangkat dari QR (parameter `id`) dan menampilkan statusnya. |
| FR-02 | Siswa dapat meminjam perangkat berstatus Tersedia pada jam layanan dengan lama pinjam per jam. |
| FR-03 | Sistem menerbitkan kode pengembalian 4 angka saat peminjaman berhasil. |
| FR-04 | Siswa dapat mengembalikan perangkat dengan kode dan melaporkan kondisinya. |
| FR-05 | Petugas dapat mengembalikan perangkat atas nama peminjam tanpa kode. |
| FR-06 | Petugas dapat mendaftarkan, menghapus, dan mengarsipkan perangkat. |
| FR-07 | Petugas dapat memeriksa perangkat berstatus Perlu diperiksa dan menetapkan kondisi barunya. |
| FR-08 | Dashboard menampilkan ringkasan status, indikator 30 hari, daftar perangkat, dan riwayat yang dapat dicari. |
| FR-09 | Petugas dapat mencetak label QR. |
| FR-10 | Petugas dapat menghapus riwayat (satuan atau berdasarkan umur) dengan arsip ke Google Sheets. |
| FR-11 | Data disalin ke Google Sheets setiap ada perubahan. |
| FR-12 | Petugas menerima email setiap pengembalian, dengan isi menyesuaikan kondisi, serta email keterlambatan. |
| FR-13 | Verifikasi NIS terhadap daftar siswa (opsional). |

### 4.2 Kebutuhan non-fungsional
| Kode | Kebutuhan | Cara dipenuhi |
|---|---|---|
| NFR-01 | Tidak boleh ada dua peminjam aktif pada satu perangkat | Indeks unik database dan transaksi `BEGIN IMMEDIATE` |
| NFR-02 | Pencatatan tidak bergantung pada Google | Database lokal sebagai sumber data; Sheets hanya salinan |
| NFR-03 | Mudah dipasang di hosting sekolah | PHP + SQLite, tanpa Composer dan tanpa server khusus |
| NFR-04 | Mudah dipakai di HP | Antarmuka responsif, tanpa instalasi aplikasi |
| NFR-05 | Data sensitif terlindungi | PIN, pembatasan percobaan, folder internal dikunci, HTTPS |
| NFR-06 | Tahan terhadap kegagalan sementara | Antrean email dan penanda sinkron dengan percobaan ulang |
| NFR-07 | Berkelanjutan setelah PLP | Akun dan hosting milik sekolah, dokumentasi, dan cadangan data |

---

## 5. Use case diagram

```mermaid
flowchart LR
  siswa(["Siswa / Peminjam"])
  petugas(["Petugas Sarpras"])
  cron(["Cron / Terjadwal"])
  google(["Google Sheets dan Gmail"])

  subgraph SISTEM["AKSMA Device Tracker"]
    UC1(["UC-01 Memindai QR perangkat"])
    UC2(["UC-02 Meminjam perangkat"])
    UC3(["UC-03 Mengembalikan perangkat dengan kode"])
    UC4(["UC-04 Masuk dashboard dengan PIN"])
    UC5(["UC-05 Memantau ringkasan dan riwayat"])
    UC6(["UC-06 Mengelola perangkat"])
    UC7(["UC-07 Mencetak label QR"])
    UC8(["UC-08 Mengembalikan atas nama peminjam"])
    UC9(["UC-09 Memeriksa perangkat"])
    UC10(["UC-10 Menghapus riwayat"])
    UC11(["UC-11 Menguji sinkron dan email"])
    UC12(["UC-12 Sinkron ke Google Sheets"])
    UC13(["UC-13 Mengirim notifikasi email"])
    UC14(["UC-14 Mengecek keterlambatan"])
  end

  siswa --- UC1
  siswa --- UC2
  siswa --- UC3
  petugas --- UC4
  petugas --- UC5
  petugas --- UC6
  petugas --- UC7
  petugas --- UC8
  petugas --- UC9
  petugas --- UC10
  petugas --- UC11
  cron --- UC12
  cron --- UC14
  google --- UC12
  google --- UC13

  UC2 -.->|include| UC1
  UC3 -.->|include| UC1
  UC5 -.->|include| UC4
  UC6 -.->|include| UC4
  UC7 -.->|include| UC4
  UC8 -.->|include| UC4
  UC9 -.->|include| UC4
  UC10 -.->|include| UC4
  UC11 -.->|include| UC4
  UC14 -.->|include| UC13
  UC3 -.->|include| UC13
  UC8 -.->|include| UC13
```

### 5.1 Spesifikasi use case utama

**UC-02 Meminjam perangkat**
| Elemen | Isi |
|---|---|
| Aktor | Siswa |
| Prasyarat | QR perangkat terpindai; perangkat berstatus Tersedia; saat ini dalam jam layanan |
| Alur utama | 1. Sistem menampilkan data perangkat dan pilihan lama pinjam. 2. Siswa mengisi identitas dan memilih lama pinjam. 3. Sistem memvalidasi data. 4. Sistem mencatat transaksi dan menghasilkan kode 4 angka. 5. Sistem menampilkan kode dan batas waktu kembali. |
| Alur alternatif | Di luar jam layanan: ditolak dengan pesan jam layanan. Perangkat tidak tersedia: formulir tidak muncul. Siswa masih meminjam perangkat lain: ditolak. NIS tidak terdaftar atau nama tidak cocok: ditolak. Perangkat baru saja dipinjam orang lain: ditolak. |
| Pascakondisi | Transaksi aktif tercatat; status perangkat Dipinjam; data tersinkron ke Google Sheets |

**UC-03 Mengembalikan perangkat dengan kode**
| Elemen | Isi |
|---|---|
| Aktor | Siswa; Google (penerima email) |
| Prasyarat | Perangkat berstatus Dipinjam atau Terlambat; siswa memiliki kode 4 angka |
| Alur utama | 1. Siswa memindai QR; sistem menampilkan formulir pengembalian. 2. Siswa memasukkan kode, memilih kondisi, dan mengisi catatan (opsional). 3. Sistem mencocokkan kode. 4. Sistem menutup transaksi dan memperbarui kondisi perangkat. 5. Sistem mengantrekan email untuk petugas. |
| Alur alternatif | Kode salah: hitungan percobaan bertambah dan sisa percobaan ditampilkan. Salah 5 kali: kode terkunci, harus lewat petugas. Kondisi bukan Baik: status perangkat menjadi Perlu diperiksa. |
| Pascakondisi | Transaksi ditutup; status Tersedia (kondisi Baik) atau Perlu diperiksa; email terkirim |

**UC-10 Menghapus riwayat**
| Elemen | Isi |
|---|---|
| Aktor | Petugas |
| Prasyarat | Sudah masuk dashboard; catatan sudah dikembalikan |
| Alur utama | 1. Petugas memilih Hapus pada catatan, atau Hapus riwayat lama dengan batas umur. 2. Sistem meminta konfirmasi. 3. Sistem menyalin catatan ke tab arsip Google Sheets. 4. Setelah sukses, sistem menghapus catatan dari database. |
| Alur alternatif | Penyalinan arsip gagal: tidak ada yang dihapus. Arsip dimatikan: catatan dihapus permanen setelah peringatan. |
| Pascakondisi | Catatan hilang dari dashboard dan tab transaksi; salinan ada di tab arsip |

---

## 6. Activity diagram

### 6.1 Peminjaman perangkat

```mermaid
flowchart TD
  subgraph P["Peminjam"]
    P1(["Mulai"]) --> P2["Pindai QR perangkat"]
    P6["Isi identitas dan pilih lama pinjam"]
    P9["Catat kode 4 angka"] --> P10(["Selesai"])
  end
  subgraph S["Sistem"]
    S1{"Perangkat ditemukan?"}
    S2{"Status Tersedia?"}
    S3{"Dalam jam layanan?"}
    S4{"Data valid dan<br/>belum melebihi batas pinjam?"}
    S5["Mulai transaksi dengan kunci tulis"]
    S6{"Perangkat masih bebas?"}
    S7["Simpan transaksi<br/>dan buat kode 4 angka"]
    S8["Antre sinkron Google Sheets"]
    X1["Tampilkan perangkat tidak ditemukan"]
    X2["Tampilkan status dan info peminjam aktif"]
    X3["Tampilkan jam layanan"]
    X4["Tampilkan pesan kesalahan"]
    X5["Tolak: baru dipinjam orang lain"]
  end
  P2 --> S1
  S1 -->|"Tidak"| X1
  S1 -->|"Ya"| S2
  S2 -->|"Tidak"| X2
  S2 -->|"Ya"| S3
  S3 -->|"Tidak"| X3
  S3 -->|"Ya"| P6
  P6 --> S4
  S4 -->|"Tidak"| X4
  S4 -->|"Ya"| S5
  S5 --> S6
  S6 -->|"Tidak"| X5
  S6 -->|"Ya"| S7
  S7 --> S8
  S8 --> P9
```

### 6.2 Pengembalian oleh peminjam

```mermaid
flowchart TD
  subgraph P["Peminjam"]
    A1(["Mulai"]) --> A2["Pindai QR perangkat"]
    A4["Isi kode, pilih kondisi, isi catatan"]
    A9(["Selesai"])
  end
  subgraph S["Sistem"]
    B1{"Perangkat sedang dipinjam?"}
    B2{"Kode terkunci?"}
    B3{"Kode cocok?"}
    B4["Tambah hitungan salah"]
    B5{"Salah sudah 5 kali?"}
    B6["Tutup transaksi dan catat kondisi"]
    B7{"Kondisi Baik?"}
    B8["Status Tersedia"]
    B9["Status Perlu diperiksa"]
    B10["Antre email dan sinkron"]
    X1["Tampilkan formulir peminjaman"]
    X2["Minta bantuan petugas"]
    X3["Tampilkan sisa percobaan"]
  end
  A2 --> B1
  B1 -->|"Tidak"| X1
  B1 -->|"Ya"| A4
  A4 --> B2
  B2 -->|"Ya"| X2
  B2 -->|"Tidak"| B3
  B3 -->|"Tidak"| B4
  B4 --> B5
  B5 -->|"Ya"| X2
  B5 -->|"Tidak"| X3
  B3 -->|"Ya"| B6
  B6 --> B7
  B7 -->|"Ya"| B8
  B7 -->|"Tidak"| B9
  B8 --> B10
  B9 --> B10
  B10 --> A9
```

### 6.3 Pemeriksaan perangkat oleh petugas

```mermaid
flowchart TD
  A(["Email atau dashboard menunjukkan Perlu diperiksa"]) --> B["Petugas memeriksa fisik perangkat"]
  B --> C["Petugas memilih kondisi terbaru"]
  C --> D{"Kondisi Rusak berat?"}
  D -->|"Ya"| E["Sistem menolak membuka perangkat"]
  E --> F{"Tindak lanjut"}
  F -->|"Diperbaiki"| G["Biarkan Perlu diperiksa<br/>sampai selesai, lalu pilih kondisi baru"]
  F -->|"Tidak dipakai lagi"| H["Hapus atau arsipkan perangkat"]
  G --> B
  D -->|"Tidak"| I["Sistem menyimpan kondisi baru<br/>dan menandai sudah diperiksa"]
  I --> J(["Perangkat Tersedia dan bisa dipinjam"])
  H --> K(["Selesai"])
```

### 6.4 Penghapusan riwayat dengan arsip

```mermaid
flowchart TD
  A(["Petugas memilih hapus riwayat"]) --> B["Sistem meminta konfirmasi"]
  B --> C{"Petugas setuju?"}
  C -->|"Tidak"| Z(["Batal"])
  C -->|"Ya"| D["Ambil catatan yang sudah dikembalikan"]
  D --> E{"Arsip Google Sheets aktif?"}
  E -->|"Ya"| F["Salin catatan ke tab arsip"]
  F --> G{"Penyalinan berhasil?"}
  G -->|"Tidak"| H["Batalkan: tidak ada yang dihapus<br/>dan tampilkan pesan galat"]
  G -->|"Ya"| I["Hapus catatan dari database"]
  E -->|"Tidak"| I
  I --> J["Catat di log dan tandai perlu sinkron"]
  J --> K(["Selesai"])
```

### 6.5 Proses latar: sinkron dan notifikasi
Dijalankan setelah jawaban dikirim ke pengguna, sehingga pengguna tidak menunggu Google.

```mermaid
flowchart TD
  A(["Ada perubahan data atau cron berjalan"]) --> B{"Webhook Google<br/>diatur?"}
  B -->|"Tidak"| Z(["Selesai"])
  B -->|"Ya"| C{"Proses lain sedang<br/>menyinkron?"}
  C -->|"Ya"| D["Tandai perlu sinkron ulang"] --> Z
  C -->|"Tidak"| E["Bersihkan penanda dan baca snapshot database"]
  E --> F["Tulis ulang tab perangkat dan transaksi"]
  F --> G{"Berhasil?"}
  G -->|"Tidak"| H["Simpan pesan galat dan tandai perlu sinkron<br/>untuk dicoba cron berikutnya"]
  G -->|"Ya"| I{"Ada perubahan baru<br/>selama proses?"}
  I -->|"Ya, maks 3 putaran"| E
  I -->|"Tidak"| J["Catat waktu sinkron terakhir"]
  H --> K
  J --> K["Kirim antrean email"]
  K --> L{"Email terkirim?"}
  L -->|"Tidak"| M["Tambah hitungan percobaan, maks 8"]
  L -->|"Ya"| N["Tandai terkirim"]
  M --> Z
  N --> Z
```

### 6.6 Pengecekan keterlambatan

```mermaid
flowchart TD
  A(["Cron tiap 5 menit atau ada aktivitas"]) --> B{"Terakhir dicek<br/>lebih dari 5 menit?"}
  B -->|"Tidak, bukan cron"| Z(["Selesai"])
  B -->|"Ya atau cron"| C["Cari transaksi aktif yang melewati batas<br/>lebih dari toleransi dan belum diberitahukan"]
  C --> D{"Ada?"}
  D -->|"Tidak"| Z
  D -->|"Ya"| E["Susun satu email ringkasan"]
  E --> F["Tandai transaksi tersebut sudah diberitahukan"]
  F --> G["Masukkan ke antrean email"]
  G --> Z
```

---

## 7. Status perangkat (state diagram)

```mermaid
stateDiagram-v2
  [*] --> Tersedia : didaftarkan kondisi Baik
  [*] --> PerluDiperiksa : didaftarkan kondisi tidak baik
  Tersedia --> Dipinjam : pinjam
  Dipinjam --> Terlambat : lewat batas waktu
  Dipinjam --> Tersedia : kembali kondisi Baik
  Terlambat --> Tersedia : kembali kondisi Baik
  Dipinjam --> PerluDiperiksa : kembali kondisi tidak baik
  Terlambat --> PerluDiperiksa : kembali kondisi tidak baik
  PerluDiperiksa --> Tersedia : petugas menandai sudah diperiksa
  Tersedia --> Diarsipkan : hapus, punya riwayat
  PerluDiperiksa --> Diarsipkan : hapus, punya riwayat
  Diarsipkan --> Tersedia : didaftarkan ulang dengan kode sama
  Tersedia --> [*] : hapus, belum pernah dipinjam
```

Catatan:
- **Terlambat tidak disimpan**, melainkan dihitung dari waktu sekarang terhadap batas kembali. Karena itu selalu akurat.
- Perangkat berkondisi **Rusak berat** tidak bisa dipindahkan ke Tersedia sebelum kondisinya diubah.
- Perangkat yang sedang Dipinjam atau Terlambat tidak dapat dihapus.

---

## 8. Sequence diagram

### 8.1 Peminjaman

```mermaid
sequenceDiagram
  autonumber
  actor S as Siswa
  participant B as Browser
  participant A as api.php
  participant D as Database SQLite
  participant G as Google Apps Script

  S->>B: Pindai QR, halaman terbuka dengan kode aset
  B->>A: getDevice(kode aset)
  A->>D: baca perangkat dan transaksi aktif
  A-->>B: status, jam layanan, pilihan lama pinjam
  S->>B: isi identitas dan pilih lama pinjam
  B->>A: pinjam(data)
  A->>A: validasi jam layanan, identitas, batas pinjam
  A->>D: BEGIN IMMEDIATE lalu cek ulang
  A->>D: INSERT transaksi dengan hash kode
  D-->>A: berhasil
  A-->>B: kode 4 angka dan batas kembali
  Note over A,G: Setelah jawaban dikirim
  A->>G: sinkron snapshot ke Google Sheets
  G-->>A: OK
```

### 8.2 Pengembalian dan notifikasi

```mermaid
sequenceDiagram
  autonumber
  actor S as Siswa
  participant B as Browser
  participant A as api.php
  participant D as Database SQLite
  participant G as Google Apps Script
  actor P as Petugas

  S->>B: Pindai QR dan isi kode serta kondisi
  B->>A: kembali(data)
  A->>D: BEGIN IMMEDIATE, ambil transaksi aktif
  A->>A: cocokkan HMAC kode
  alt kode salah
    A->>D: tambah hitungan salah
    A-->>B: pesan salah dan sisa percobaan
  else kode benar
    A->>D: tutup transaksi, perbarui kondisi dan status perangkat
    A->>D: masukkan email ke antrean
    A-->>B: pengembalian berhasil
    Note over A,G: Setelah jawaban dikirim
    A->>G: sinkron ke Google Sheets
    A->>G: kirim email sesuai kondisi
    G-->>P: Email pengembalian
  end
```

---

## 9. Arsitektur dan deployment

### 9.1 Arsitektur logis

```mermaid
flowchart TB
  subgraph Klien["Klien"]
    HP["HP siswa: pindai QR"]
    PC["Browser petugas: dashboard"]
  end
  subgraph Hosting["Hosting sekolah: Apache dan PHP"]
    UI["device-tracker/index.html"]
    API["device-tracker/api.php"]
    LIB["src/lib.php: logika bisnis"]
    SYN["src/sync.php: sinkron"]
    CRN["device-tracker/sync.php: endpoint cron"]
    CORE["core/: konfigurasi, database, klien Google"]
    DB[("storage/aksma.sqlite")]
  end
  subgraph Google["Google"]
    GAS["Apps Script penerima"]
    GSH["Google Sheets"]
    GML["Gmail"]
  end
  CR["Cron cPanel"]

  HP --> UI
  PC --> UI
  UI -->|"POST JSON"| API
  API --> LIB
  LIB --> CORE
  CORE --> DB
  LIB --> SYN
  SYN -->|"webhook"| GAS
  LIB -->|"webhook email"| GAS
  GAS --> GSH
  GAS --> GML
  CR -->|"tiap 5 menit"| CRN
  CRN --> LIB
```

### 9.2 Prinsip rancangan
| Prinsip | Penjelasan |
|---|---|
| Database sebagai sumber kebenaran | Google Sheets hanyalah salinan; sinkron selalu menulis ulang snapshot penuh, sehingga idempoten. |
| Logika di server | Semua validasi ada di PHP; frontend hanya menampilkan. |
| Kerja berat setelah respons | Sinkron dan email dijalankan setelah jawaban dikirim. |
| Modular | Folder `core/` dipakai bersama; tiap modul punya prefix tabel sendiri. |

### 9.3 Struktur folder
```
index.php                  Portal AKSMA DIGITAL HUB
core/                      Konfigurasi, database, klien Google (dipakai semua modul)
storage/                   Database, kunci, log (tidak di-commit)
google-sheets/penerima.gs  Kode Apps Script penerima
device-tracker/
  index.html               Antarmuka
  api.php                  Pintu masuk API
  sync.php                 Endpoint cron
  module.json              Metadata modul
  src/lib.php              Logika bisnis
  src/sync.php             Sinkron ke Google Sheets
  tools/import_siswa.php   Impor daftar siswa
docs/DOKUMENTASI.md        Dokumen ini
```

---

## 10. Perancangan data (ERD dan kamus data)

### 10.1 ERD

```mermaid
erDiagram
  dt_device ||--o{ dt_trx : "dipinjam dalam"
  dt_siswa ||..o{ dt_trx : "diverifikasi lewat NIS"

  dt_device {
    TEXT id PK "kode aset"
    TEXT jenis "Laptop, Tablet, Lainnya"
    TEXT merek
    TEXT model
    TEXT inv "nomor inventaris"
    TEXT kondisi
    INTEGER perlu_periksa "0 atau 1"
    INTEGER aktif "1 aktif, 0 diarsipkan"
    INTEGER dibuat "waktu unix"
  }
  dt_trx {
    INTEGER id PK
    TEXT device_id FK
    TEXT nis
    TEXT nama
    TEXT kelas
    TEXT kunci "identitas peminjam ternormalisasi"
    INTEGER pinjam_at
    INTEGER batas_at
    INTEGER kembali_at "kosong berarti masih dipinjam"
    TEXT kode_hash "HMAC kode 4 angka"
    INTEGER salah "percobaan kode salah"
    TEXT kondisi_awal
    TEXT kondisi_kembali
    TEXT catatan
    TEXT dikembalikan_oleh "peminjam atau petugas"
    INTEGER notif_telat "sudah diberitahu terlambat"
  }
  dt_siswa {
    TEXT nis PK
    TEXT nama
    TEXT kelas
  }
  dt_log {
    INTEGER id PK
    INTEGER waktu
    TEXT aktor
    TEXT aksi
    TEXT detail
  }
  dt_notif {
    INTEGER id PK
    TEXT jenis
    TEXT subjek
    TEXT isi
    INTEGER dibuat
    INTEGER terkirim_at
    INTEGER percobaan
    TEXT galat
  }
  dt_gagal {
    TEXT ip
    INTEGER waktu
  }
  meta {
    TEXT k PK
    TEXT v
  }
```

Hubungan `dt_siswa` ke `dt_trx` bersifat logis (lewat NIS saat verifikasi diaktifkan), bukan kunci asing.

### 10.2 Kamus data (kolom penting)
| Tabel.kolom | Keterangan |
|---|---|
| `dt_device.id` | Kode aset unik. Huruf besar, angka, `-` atau `_`, panjang 2 sampai 40. |
| `dt_device.perlu_periksa` | 1 bila menunggu pemeriksaan petugas; perangkat tidak bisa dipinjam. |
| `dt_device.aktif` | 0 = diarsipkan (hilang dari daftar, riwayat tetap). |
| `dt_trx.kunci` | `nis:<NIS>` bila verifikasi NIS aktif, atau `nm:<nama>\|<kelas>` yang dinormalkan. Dipakai untuk membatasi pinjam per orang. |
| `dt_trx.kembali_at` | `NULL` = transaksi aktif. Indeks unik parsial menjamin hanya satu transaksi aktif per perangkat. |
| `dt_trx.kode_hash` | HMAC-SHA256 dari kode, kode aset, dan waktu pinjam. Kode asli tidak disimpan. |
| `dt_notif.percobaan` | Jumlah upaya kirim; berhenti di 8. |
| `meta` | Penanda internal: `dt.dirty` (perlu sinkron), `dt.sync_ok`, `dt.sync_err`, `dt.cek_at`. |

### 10.3 Tab Google Sheets
| Tab | Perilaku | Isi |
|---|---|---|
| `DT_Perangkat` | Ditulis ulang tiap sinkron | Kode aset, jenis, merek, model, inventaris, kondisi, status, peminjam, kelas, waktu |
| `DT_Transaksi` | Ditulis ulang tiap sinkron | Seluruh transaksi, terbaru di atas |
| `DT_Arsip` | Hanya bertambah | Catatan riwayat yang dihapus |

Mengedit manual `DT_Perangkat` dan `DT_Transaksi` tidak disarankan karena akan tertimpa sinkron berikutnya.

---

## 11. Aturan bisnis, API, dan keamanan

### 11.1 Aturan bisnis
| Kode | Aturan |
|---|---|
| BR-01 | Peminjaman hanya pada jam layanan per hari (default Senin sampai Jumat 07.00 sampai 16.00). |
| BR-02 | Lama pinjam dipilih per jam (default 1 sampai 4 jam) dan batas kembali tidak boleh melewati jam tutup. |
| BR-03 | Bila sisa waktu kurang dari 1 jam dan minimal 30 menit, tersedia pilihan "sampai jam tutup". |
| BR-04 | Satu perangkat hanya boleh memiliki satu transaksi aktif. |
| BR-05 | Satu peminjam maksimal meminjam sejumlah perangkat yang ditetapkan (default 1) pada saat yang sama. |
| BR-06 | Nama minimal 3 karakter; kelas wajib bila verifikasi NIS tidak aktif. |
| BR-07 | Bila verifikasi NIS aktif, NIS harus terdaftar dan nama harus sesuai; kelas diambil dari data siswa. |
| BR-08 | Peminjaman dapat diwajibkan didampingi petugas (PIN) lewat pengaturan. |
| BR-09 | Kode pengembalian terdiri dari 4 angka acak; setelah 5 kali salah, terkunci. |
| BR-10 | Pengembalian dengan kondisi bukan Baik menjadikan perangkat Perlu diperiksa. |
| BR-11 | Perangkat Perlu diperiksa tidak dapat dipinjam. Perangkat berkondisi Rusak berat tidak dapat dibuka sebelum kondisinya diubah. |
| BR-12 | Status Terlambat dihitung dari waktu sekarang dan batas kembali. |
| BR-13 | Setiap pengembalian dikabari lewat email; isi menyesuaikan kondisi (Baik, tidak baik, rusak berat) dan ketepatan waktu. |
| BR-14 | Keterlambatan dikabari sekali per transaksi, setelah toleransi (default 10 menit), dalam satu email ringkasan. |
| BR-15 | Hanya catatan yang sudah dikembalikan yang dapat dihapus. Bila arsip aktif, penyalinan harus sukses sebelum penghapusan. |
| BR-16 | Perangkat yang belum pernah dipinjam dihapus permanen; yang punya riwayat diarsipkan. Perangkat yang sedang dipinjam tidak dapat dihapus. |
| BR-17 | PIN petugas salah 5 kali dari IP yang sama dalam 10 menit memblokir percobaan berikutnya selama itu. |

### 11.2 API internal
`POST device-tracker/api.php` dengan body `{"fn": "<nama>", "args": [...]}` dan header `X-Requested-With: aksma`. Jawaban `{"ok": true, "data": ...}` atau `{"ok": false, "error": "..."}`.

| Fungsi | Akses | Fungsi |
|---|---|---|
| `getDevice` | Publik | Info perangkat, jam layanan, pilihan lama pinjam |
| `pinjam` | Publik | Membuat transaksi dan kode |
| `kembali` | Publik (kode) | Menutup transaksi |
| `adminData` | PIN | Data dashboard |
| `adminAddDevice` | PIN | Mendaftarkan atau mengaktifkan ulang perangkat |
| `adminTandaiDiperiksa` | PIN | Menetapkan kondisi dan membuka perangkat |
| `adminKembalikan` | PIN | Pengembalian oleh petugas |
| `adminHapusPerangkat` | PIN | Hapus atau arsipkan perangkat |
| `adminHapusRiwayat`, `adminHapusRiwayatLama` | PIN | Hapus riwayat (maks 300 per permintaan atau 2.000 per penghapusan lama) |
| `adminSyncSekarang`, `adminTesNotif` | PIN | Uji sinkron dan email |

### 11.3 Keamanan
| Ancaman | Penanganan |
|---|---|
| Orang iseng menandai perangkat "kembali" | Wajib kode 4 angka (disimpan sebagai HMAC) dengan batas 5 percobaan |
| Menebak PIN petugas | Pembatasan percobaan per IP; PIN dapat disimpan sebagai hash bcrypt |
| Permintaan lintas situs | API hanya menerima POST berheader kustom |
| Dua pengguna bersamaan | Indeks unik database dan transaksi dengan kunci tulis |
| Berkas internal terbuka dari web | `.htaccess` pada `core/`, `storage/`, `src/`, `tools/` |
| Penyalahgunaan webhook Google | Kata rahasia pada setiap permintaan |
| Kebocoran rahasia di repositori | `config.php` dan `storage/` masuk `.gitignore`; hanya `config.example.php` yang di-commit |
| Data pribadi siswa | Akses Google Sheet dibatasi; gunakan akun Google sekolah |

---

## 12. Skenario pengujian
Skenario uji manual. Kolom "Hasil" diisi saat pengujian di lingkungan sekolah.

| ID | Skenario | Langkah ringkas | Hasil yang diharapkan | Aturan | Hasil |
|---|---|---|---|---|---|
| TC-01 | Pinjam normal | Pindai QR, isi data, pilih 2 jam | Kode 4 angka tampil; status Dipinjam; baris masuk Sheet | FR-02, FR-03 | ☐ |
| TC-02 | Di luar jam layanan | Pinjam setelah jam tutup | Ditolak, jam layanan ditampilkan | BR-01 | ☐ |
| TC-03 | Sisa waktu kurang dari 1 jam | Pinjam pukul 15.20 | Hanya pilihan "sampai jam tutup" | BR-03 | ☐ |
| TC-04 | Dua orang pindai bersamaan | Dua HP kirim pinjam bersamaan | Satu berhasil, satu ditolak | BR-04 | ☐ |
| TC-05 | Pinjam kedua oleh orang sama | Pinjam perangkat lain saat belum kembali | Ditolak | BR-05 | ☐ |
| TC-06 | Verifikasi NIS | NIS tidak terdaftar atau nama tidak cocok | Ditolak | BR-07 | ☐ |
| TC-07 | Kode salah | Masukkan kode salah satu kali | Pesan salah dan sisa percobaan | BR-09 | ☐ |
| TC-08 | Kode salah 5 kali | Salah lima kali berturut-turut | Kode terkunci | BR-09 | ☐ |
| TC-09 | Kembali kondisi Baik | Kode benar, kondisi Baik | Status Tersedia; email "dikembalikan (kondisi baik)" | BR-13 | ☐ |
| TC-10 | Kembali kondisi lecet | Kode benar, kondisi Ada lecet | Status Perlu diperiksa; email peringatan | BR-10, BR-13 | ☐ |
| TC-11 | Kembali rusak berat | Kondisi Rusak berat | Email bertanda PERLU TINDAKAN | BR-13 | ☐ |
| TC-12 | Pinjam saat Perlu diperiksa | Pindai QR perangkat tersebut | Tidak bisa dipinjam | BR-11 | ☐ |
| TC-13 | Tandai rusak berat sebagai diperiksa | Pilih Rusak berat lalu tandai | Ditolak | BR-11 | ☐ |
| TC-14 | Keterlambatan | Biarkan lewat batas plus toleransi | Satu email ringkasan, tidak berulang | BR-14 | ☐ |
| TC-15 | Pengembalian oleh petugas | Kembalikan dari dashboard tanpa kode | Berhasil; tercatat "petugas" | FR-05 | ☐ |
| TC-16 | PIN salah 5 kali | Salah PIN lima kali | Diblokir sementara | BR-17 | ☐ |
| TC-17 | Sinkron Sheets | Tekan Kirim sekarang | Tab terisi sesuai database | FR-11 | ☐ |
| TC-18 | Google gagal | Matikan akses sementara, lakukan pinjam | Pinjam tetap berhasil; sinkron menyusul | NFR-02 | ☐ |
| TC-19 | Hapus riwayat | Hapus catatan | Hilang dari dashboard; salinan di DT_Arsip | BR-15 | ☐ |
| TC-20 | Hapus saat arsip gagal | Arsip tidak dapat dijangkau | Tidak ada yang terhapus; pesan galat | BR-15 | ☐ |
| TC-21 | Hapus perangkat dipinjam | Hapus perangkat berstatus Dipinjam | Ditolak | BR-16 | ☐ |
| TC-22 | Hapus perangkat berriwayat | Hapus perangkat yang pernah dipinjam | Diarsipkan, riwayat tetap | BR-16 | ☐ |
| TC-23 | Permintaan tanpa header | Kirim ke API tanpa header kustom | Ditolak (403) | NFR-05 | ☐ |
| TC-24 | Kode aset tidak dikenal | Buka `?id=XXXX` yang tidak ada | Pesan perangkat tidak ditemukan | FR-01 | ☐ |

---

## 13. Batasan dan pengembangan lanjutan

### 13.1 Batasan saat ini
- Sistem memastikan pengembali memiliki kode yang benar, **bukan** bahwa perangkat sudah fisik di tangan petugas.
- Satu PIN bersama untuk semua petugas; belum ada akun per petugas.
- Gambar QR dibuat lewat layanan eksternal sehingga butuh internet saat mencetak label.
- Belum ada pengujian otomatis; pengujian dilakukan manual (bagian 12).
- Kuota email Gmail sekitar 100 per hari.
- Satu server dan satu berkas database; cadangan dilakukan dengan mengunduh `aksma.sqlite`.

### 13.2 Ide pengembangan
| Ide | Manfaat |
|---|---|
| Konfirmasi pengembalian oleh petugas | Memastikan perangkat fisik diperiksa sebelum dinyatakan kembali |
| Notifikasi Telegram | Cepat terlihat di HP, tanpa kuota email ketat |
| Ringkasan harian | Mengurangi jumlah email |
| Pembuatan QR lokal | Tidak bergantung internet saat mencetak |
| Akun petugas terpisah | Jejak audit per orang |
| Ekspor laporan bulanan | Bahan evaluasi sekolah |
| Integrasi dengan GREEN INFO dan GREEN HUB | Satu portal AKSMA DIGITAL HUB |

---

## 14. Glosarium
| Istilah | Arti |
|---|---|
| **QR Code** | Kode gambar yang dibaca kamera HP dan membuka alamat web tertentu |
| **Dashboard** | Halaman pemantauan untuk petugas |
| **Aset / kode aset** | Perangkat sekolah dan kode uniknya, misalnya `LAP-001` |
| **Transaksi** | Satu catatan peminjaman, dari pinjam sampai kembali |
| **Snapshot** | Salinan penuh data pada satu waktu |
| **Idempoten** | Dijalankan berkali-kali tetap menghasilkan hasil yang sama |
| **Webhook** | Alamat web yang menerima kiriman data dari sistem lain |
| **Apps Script** | Layanan Google untuk menjalankan kode kecil terhubung ke Sheets dan Gmail |
| **Cron** | Penjadwal tugas otomatis pada hosting |
| **HMAC** | Cara membuat sidik jari data dengan kunci rahasia, sehingga kode tidak disimpan apa adanya |
| **SQLite** | Database berupa satu berkas, tanpa server terpisah |
| **ERD** | Diagram hubungan antartabel data |
| **Use case** | Daftar hal yang bisa dilakukan pengguna terhadap sistem |
| **Activity diagram** | Diagram alur langkah suatu proses |
