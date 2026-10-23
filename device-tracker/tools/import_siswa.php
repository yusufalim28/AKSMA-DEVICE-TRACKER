<?php
/* Impor daftar siswa untuk verifikasi NIS.  Pakai:  php tools/import_siswa.php siswa.csv
   Format CSV (tanpa judul kolom):  nis,nama,kelas */
require __DIR__ . '/../../core/bootstrap.php';
require __DIR__ . '/../src/lib.php';
if (PHP_SAPI !== 'cli') exit('CLI only');
dt_migrate(); $f = fopen($argv[1] ?? '', 'r') or exit("File tidak bisa dibuka\n"); $n = 0;
$st = db()->prepare('INSERT INTO dt_siswa(nis,nama,kelas) VALUES(?,?,?) ON CONFLICT(nis) DO UPDATE SET nama=excluded.nama, kelas=excluded.kelas');
while (($r = fgetcsv($f)) !== false) { if (count($r) >= 3 && preg_match('/^\d+$/', trim($r[0]))) { $st->execute([trim($r[0]), trim($r[1]), trim($r[2])]); $n++; } }
echo "$n siswa diimpor.\n";
