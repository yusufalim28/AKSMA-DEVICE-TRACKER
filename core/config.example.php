<?php
$PIN_PETUGAS    = 'GANTI-PIN-PETUGAS';   // PIN masuk halaman petugas.
$WEBHOOK_URL    = '';                     // Tautan dari Google Sheet (lihat README, bagian Hubungkan Google Sheets). Kosong = tanpa Google Sheets dulu
$KATA_RAHASIA   = 'GANTI-KATA-RAHASIA';   // Harus SAMA dengan yang di Apps Script. 

return [
  'timezone' => 'Asia/Jakarta',
  'cron_key' => 'GANTI-DENGAN-KATA-ACAK-PANJANG',   // kunci alamat sync.php untuk cron. WAJIB diganti
  'nama_sekolah' => 'SMA IT Akmala Sabila',
  'google' => ['webhook_url' => $WEBHOOK_URL, 'webhook_secret' => $KATA_RAHASIA],

  'device_tracker' => [
    'admin_pin' => $PIN_PETUGAS,
    'base_url' => '',                 // biarkan kosong; alamat QR dibuat otomatis
    'pinjam_perlu_pin' => false,      // true = peminjaman harus didampingi petugas
    'verifikasi_nis' => false,        // true = peminjam harus terdaftar (butuh impor data siswa)
    'maks_pinjam_per_orang' => 1,
    'opsi_jam' => [1, 2, 3, 4],       // pilihan lama pinjam, dalam JAM
    'min_sisa_menit' => 30,           // jika sisa waktu < 1 jam, muncul pilihan "sampai jam tutup" (asal sisa >= angka ini)
    // 1=Senin ... 7=Minggu. Hari yang tidak ditulis = layanan tutup.
    'jam_layanan' => [1=>['07:00','16:00'], 2=>['07:00','16:00'], 3=>['07:00','16:00'], 4=>['07:00','16:00'], 5=>['07:00','16:00']],
    'kondisi' => ['Baik', 'Ada lecet', 'Rusak ringan', 'Rusak berat'],
    'tab_perangkat' => 'DT_Perangkat',
    'tab_transaksi' => 'DT_Transaksi',
    'tab_arsip' => 'DT_Arsip',
    'arsip_riwayat' => true,          // true = riwayat yang dihapus disalin dulu ke tab DT_Arsip di Google Sheet

    'notifikasi' => [
      'email' => '',                  // isi alamat email petugas; beberapa alamat pisahkan koma. Kosong = tanpa notifikasi
      'terlambat' => true,            // kabari bila ada perangkat melewati batas waktu
      'toleransi_menit' => 10,        // tunggu sekian menit setelah batas sebelum mengabari
      'kembali' => true,              // kabari SETIAP perangkat dikembalikan (apa pun kondisinya); pesan menyesuaikan kondisi
      'perlu_periksa' => true,        // jika 'kembali' = false, tetap kabari bila kondisi tidak baik
    ],
  ],
];
