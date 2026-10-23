<?php
declare(strict_types=1);
/* Sinkronisasi database -> Google Sheets. Idempoten: selalu menulis ulang snapshot penuh, jadi tidak ada data ganda/selisih. */

function dt_sync_run(): void {
  if (!Sheets::aktif()) return;
  $lock = @fopen(AKSMA_ROOT . '/storage/dt-sync.lock', 'c');
  if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) { tandai_sinkron(); return; } // proses lain sedang jalan; ia akan mengulang
  try {
    for ($i = 0; $i < 3; $i++) {
      meta_set('dt.dirty', '0');
      $now = time(); $pdo = db();
      $aktif = [];
      foreach ($pdo->query('SELECT * FROM dt_trx WHERE kembali_at IS NULL') as $t) $aktif[$t['device_id']] = $t;

      $rows = [['Kode aset', 'Jenis', 'Merek', 'Model', 'No. inventaris', 'Kondisi', 'Status', 'Peminjam', 'Kelas', 'Dipinjam sejak', 'Batas kembali', 'Diperbarui']];
      foreach ($pdo->query('SELECT * FROM dt_device WHERE aktif=1 ORDER BY id') as $d) {
        $a = $aktif[$d['id']] ?? null;
        $rows[] = [$d['id'], $d['jenis'], $d['merek'], $d['model'], $d['inv'], $d['kondisi'], status_dev($d, $a, $now),
          $a['nama'] ?? '', $a['kelas'] ?? '', $a ? fmt((int)$a['pinjam_at']) : '', $a ? fmt((int)$a['batas_at']) : '', fmt($now)];
      }
      Sheets::replace((string)dtc('tab_perangkat', 'DT_Perangkat'), $rows);

      $rows = [['ID', 'Kode aset', 'Nama', 'Kelas', 'NIS', 'Dipinjam', 'Batas kembali', 'Dikembalikan', 'Kondisi awal', 'Kondisi kembali', 'Status', 'Dikembalikan oleh', 'Catatan']];
      foreach ($pdo->query('SELECT * FROM dt_trx ORDER BY id DESC') as $t) {
        $rows[] = [$t['id'], $t['device_id'], $t['nama'], $t['kelas'], $t['nis'], fmt((int)$t['pinjam_at']), fmt((int)$t['batas_at']),
          fmt($t['kembali_at'] ? (int)$t['kembali_at'] : null), $t['kondisi_awal'], (string)$t['kondisi_kembali'],
          $t['kembali_at'] ? 'Dikembalikan' : ($now > (int)$t['batas_at'] ? 'Terlambat' : 'Dipinjam'), (string)$t['dikembalikan_oleh'], $t['catatan']];
      }
      Sheets::replace((string)dtc('tab_transaksi', 'DT_Transaksi'), $rows);

      meta_set('dt.sync_ok', fmt($now)); meta_set('dt.sync_err', '');
      if (meta_get('dt.dirty') !== '1') break;   // tidak ada perubahan baru selama proses
    }
  } catch (Throwable $e) {
    meta_set('dt.dirty', '1'); meta_set('dt.sync_err', $e->getMessage()); log_galat('sync: ' . $e->getMessage());
  } finally { flock($lock, LOCK_UN); fclose($lock); }
}
