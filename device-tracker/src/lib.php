<?php
declare(strict_types=1);
/* Logika bisnis AKSMA Device Tracker. Semua tabel berawalan dt_ agar aman digabung dengan modul lain. */

final class UserError extends RuntimeException {}

function dtc(string $k, $def = null) { return cfg('device_tracker.' . $k, $def); }

function dt_migrate(): void {
  static $done = false; if ($done) return; $done = true;
  db()->exec("
    CREATE TABLE IF NOT EXISTS dt_device(
      id TEXT PRIMARY KEY, jenis TEXT NOT NULL, merek TEXT NOT NULL, model TEXT NOT NULL,
      inv TEXT NOT NULL DEFAULT '', kondisi TEXT NOT NULL DEFAULT 'Baik',
      perlu_periksa INTEGER NOT NULL DEFAULT 0, dibuat INTEGER NOT NULL);
    CREATE TABLE IF NOT EXISTS dt_trx(
      id INTEGER PRIMARY KEY AUTOINCREMENT, device_id TEXT NOT NULL REFERENCES dt_device(id),
      nis TEXT NOT NULL DEFAULT '', nama TEXT NOT NULL, kelas TEXT NOT NULL, kunci TEXT NOT NULL,
      pinjam_at INTEGER NOT NULL, batas_at INTEGER NOT NULL, kembali_at INTEGER,
      kode_hash TEXT NOT NULL, salah INTEGER NOT NULL DEFAULT 0,
      kondisi_awal TEXT NOT NULL, kondisi_kembali TEXT, catatan TEXT NOT NULL DEFAULT '', dikembalikan_oleh TEXT);
    CREATE UNIQUE INDEX IF NOT EXISTS dt_trx_satu_aktif ON dt_trx(device_id) WHERE kembali_at IS NULL;
    CREATE INDEX IF NOT EXISTS dt_trx_kunci ON dt_trx(kunci) WHERE kembali_at IS NULL;
    CREATE INDEX IF NOT EXISTS dt_trx_waktu ON dt_trx(pinjam_at);
    CREATE TABLE IF NOT EXISTS dt_siswa(nis TEXT PRIMARY KEY, nama TEXT NOT NULL, kelas TEXT NOT NULL);
    CREATE TABLE IF NOT EXISTS dt_log(id INTEGER PRIMARY KEY AUTOINCREMENT, waktu INTEGER, aktor TEXT, aksi TEXT, detail TEXT);
    CREATE TABLE IF NOT EXISTS dt_gagal(ip TEXT NOT NULL, waktu INTEGER NOT NULL);
    CREATE TABLE IF NOT EXISTS dt_notif(id INTEGER PRIMARY KEY AUTOINCREMENT, jenis TEXT, subjek TEXT, isi TEXT,
      dibuat INTEGER, terkirim_at INTEGER, percobaan INTEGER NOT NULL DEFAULT 0, galat TEXT);
  ");
  tambah_kolom('dt_device', 'aktif', 'INTEGER NOT NULL DEFAULT 1');
  tambah_kolom('dt_trx', 'notif_telat', 'INTEGER NOT NULL DEFAULT 0');
}

function tambah_kolom(string $t, string $k, string $def): void {
  foreach (db()->query("PRAGMA table_info($t)") as $c) if ($c['name'] === $k) return;
  db()->exec("ALTER TABLE $t ADD COLUMN $k $def");
}

/* ---------- utilitas ---------- */
function fmt(?int $t): string { return $t ? date('d/m/Y H:i', $t) : ''; }
function norm(string $s): string { return preg_replace('/[^a-z0-9]+/', '', mb_strtolower($s)) ?? ''; }
function bersih($s, int $max): string { return mb_substr(trim((string)preg_replace('/\s+/u', ' ', (string)$s)), 0, $max); }
function normId($s): string {
  $s = strtoupper(trim((string)$s));
  if (!preg_match('/^[A-Z0-9][A-Z0-9_-]{1,39}$/', $s)) throw new UserError('Kode aset tidak valid. Gunakan huruf, angka, atau tanda hubung.');
  return $s;
}
function kondisi_valid($k): string {
  $k = trim((string)$k);
  if (!in_array($k, dtc('kondisi', ['Baik']), true)) throw new UserError('Pilihan kondisi tidak valid.');
  return $k;
}
function catat(string $aktor, string $aksi, string $detail): void {
  db()->prepare('INSERT INTO dt_log(waktu,aktor,aksi,detail) VALUES(?,?,?,?)')->execute([time(), $aktor, $aksi, $detail]);
}
function tandai_sinkron(): void { meta_set('dt.dirty', '1'); }

/** Transaksi database dengan kunci tulis di awal: dua orang yang scan bersamaan diproses berurutan, bukan bersamaan. */
function dt_tx(callable $f) {
  $pdo = db(); $pdo->exec('BEGIN IMMEDIATE');
  try { $r = $f($pdo); $pdo->exec('COMMIT'); return $r; }
  catch (Throwable $e) { try { $pdo->exec('ROLLBACK'); } catch (Throwable $x) {} throw $e; }
}

/* ---------- autentikasi petugas (dibatasi percobaan) ---------- */
function cek_pin($pin): void {
  $pin = (string)$pin; $ip = $_SERVER['REMOTE_ADDR'] ?? 'cli'; $now = time(); $pdo = db();
  $ref = (string)dtc('admin_pin', '');
  if ($ref === '' || str_starts_with($ref, 'GANTI')) throw new UserError('PIN petugas belum diatur pada core/config.php.');
  $pdo->prepare('DELETE FROM dt_gagal WHERE waktu<?')->execute([$now - 600]);
  $q = $pdo->prepare('SELECT COUNT(*) FROM dt_gagal WHERE ip=?'); $q->execute([$ip]);
  if ((int)$q->fetchColumn() >= 5) throw new UserError('Terlalu banyak PIN salah. Coba lagi dalam 10 menit.');
  $ok = $pin !== '' && (str_starts_with($ref, '$2y$') ? password_verify($pin, $ref) : hash_equals($ref, $pin));
  if (!$ok) { $pdo->prepare('INSERT INTO dt_gagal(ip,waktu) VALUES(?,?)')->execute([$ip, $now]); throw new UserError('PIN salah.'); }
  $pdo->prepare('DELETE FROM dt_gagal WHERE ip=?')->execute([$ip]);
}

/* ---------- jam layanan & durasi ---------- */
function layanan(int $now): array {
  $j = (dtc('jam_layanan', []))[(int)date('N', $now)] ?? null;
  if (!$j) return [false, 0, 'Tidak ada layanan peminjaman hari ini.'];
  $tgl = date('Y-m-d', $now);
  $buka = strtotime("$tgl {$j[0]}"); $tutup = strtotime("$tgl {$j[1]}");
  return [$now >= $buka && $now < $tutup, $tutup, "Jam layanan hari ini {$j[0]}–{$j[1]}."];
}
/** Pilihan lama pinjam per JAM. Bila sisa waktu < 1 jam, tersedia pilihan "sampai jam tutup". jp=0 berarti sampai tutup. */
function opsi_jp(int $now, int $tutup): array {
  $o = [];
  foreach (dtc('opsi_jam', [1, 2, 3, 4]) as $j) {
    $j = (int)$j;
    if ($j > 0 && $now + $j * 3600 <= $tutup) $o[] = ['jp' => $j, 'label' => "$j jam (sampai " . date('H:i', $now + $j * 3600) . ')'];
  }
  if (!$o && intdiv($tutup - $now, 60) >= (int)dtc('min_sisa_menit', 30)) $o[] = ['jp' => 0, 'label' => 'Sampai jam tutup (' . date('H:i', $tutup) . ')'];
  return $o;
}

/* ---------- akses data ---------- */
function dev_row(string $id): ?array { $q = db()->prepare('SELECT * FROM dt_device WHERE id=? AND aktif=1'); $q->execute([$id]); return $q->fetch() ?: null; }
function trx_aktif(string $id): ?array { $q = db()->prepare('SELECT * FROM dt_trx WHERE device_id=? AND kembali_at IS NULL'); $q->execute([$id]); return $q->fetch() ?: null; }
function status_dev(array $d, ?array $a, int $now): string {
  if ($a) return $now > (int)$a['batas_at'] ? 'Terlambat' : 'Dipinjam';
  return (int)$d['perlu_periksa'] ? 'Perlu diperiksa' : 'Tersedia';
}
function hash_kode(string $kode, string $dev, int $pinjamAt): string { return hash_hmac('sha256', "$kode|$dev|$pinjamAt", app_secret()); }

/* ================= API publik (dipanggil frontend) ================= */

function getDevice($id): array {
  try { $id = normId($id); } catch (UserError $e) { return ['found' => false]; }
  $d = dev_row($id); if (!$d) return ['found' => false];
  $now = time(); $a = trx_aktif($id);
  [$ak, $tutup, $pesan] = layanan($now);
  $opsi = $ak ? opsi_jp($now, $tutup) : [];
  if ($ak && !$opsi) $pesan .= ' Sisa waktu terlalu singkat untuk meminjam.';
  return ['found' => true, 'id' => $d['id'], 'jenis' => $d['jenis'], 'merek' => $d['merek'], 'model' => $d['model'],
    'inv' => $d['inv'], 'kondisi' => $d['kondisi'], 'status' => status_dev($d, $a, $now),
    'sejak' => $a ? fmt((int)$a['pinjam_at']) : '', 'batas' => $a ? fmt((int)$a['batas_at']) : '',
    'layananAktif' => $ak && (bool)$opsi, 'layananPesan' => $pesan, 'opsiJp' => $opsi,
    'verifikasi' => (bool)dtc('verifikasi_nis', false), 'wajibPin' => (bool)dtc('pinjam_perlu_pin', false),
    'kondisiList' => dtc('kondisi', ['Baik'])];
}

function pinjam($d): array {
  $d = is_array($d) ? $d : [];
  $id = normId($d['id'] ?? ''); $now = time();
  [$ak, $tutup] = layanan($now);
  if (!$ak) throw new UserError('Di luar jam layanan peminjaman.');
  $jp = isset($d['jp']) ? (int)$d['jp'] : -1; $batas = 0;
  foreach (opsi_jp($now, $tutup) as $o) if ($o['jp'] === $jp) $batas = $jp === 0 ? $tutup : $now + $jp * 3600;
  if (!$batas) throw new UserError('Lama pinjam tidak valid atau melewati jam layanan.');
  if (dtc('pinjam_perlu_pin', false)) cek_pin($d['pin'] ?? '');

  $nama = bersih($d['nama'] ?? '', 60); $nis = ''; $kelas = bersih($d['kelas'] ?? '', 20);
  if (mb_strlen($nama) < 3) throw new UserError('Nama lengkap wajib diisi.');
  if (dtc('verifikasi_nis', false)) {
    $nis = preg_replace('/\D/', '', (string)($d['nis'] ?? ''));
    $q = db()->prepare('SELECT * FROM dt_siswa WHERE nis=?'); $q->execute([$nis]); $s = $q->fetch();
    if (!$s) throw new UserError('NIS tidak terdaftar. Hubungi petugas sarpras.');
    $a = norm($s['nama']); $b = norm($nama);
    if (strlen($b) < 3 || (!str_contains($a, $b) && !str_contains($b, $a))) throw new UserError('Nama tidak sesuai dengan NIS tersebut.');
    $nama = $s['nama']; $kelas = $s['kelas'];
    $kunci = 'nis:' . $nis;
  } else {
    if ($kelas === '') throw new UserError('Kelas wajib diisi.');
    $kunci = 'nm:' . norm($nama) . '|' . norm($kelas);
  }
  $kode = str_pad((string)random_int(0, 9999), 4, '0', STR_PAD_LEFT);

  dt_tx(function (PDO $pdo) use ($id, $nama, $kelas, $nis, $kunci, $now, $batas, $kode) {
    $dev = dev_row($id); if (!$dev) throw new UserError('Perangkat tidak ditemukan.');
    if ((int)$dev['perlu_periksa']) throw new UserError('Perangkat sedang diperiksa petugas.');
    $q = $pdo->prepare('SELECT device_id FROM dt_trx WHERE kunci=? AND kembali_at IS NULL'); $q->execute([$kunci]);
    $aktif = $q->fetchAll(PDO::FETCH_COLUMN);
    if (count($aktif) >= (int)dtc('maks_pinjam_per_orang', 1))
      throw new UserError('Kamu masih meminjam ' . implode(', ', $aktif) . '. Kembalikan dulu sebelum meminjam lagi.');
    try {
      $pdo->prepare('INSERT INTO dt_trx(device_id,nis,nama,kelas,kunci,pinjam_at,batas_at,kode_hash,kondisi_awal) VALUES(?,?,?,?,?,?,?,?,?)')
          ->execute([$id, $nis, $nama, $kelas, $kunci, $now, $batas, hash_kode($kode, $id, $now), $dev['kondisi']]);
    } catch (PDOException $e) { throw new UserError('Perangkat ini baru saja dipinjam orang lain.'); }
    catat($nama . ' (' . $kelas . ')', 'pinjam', $id);
  });
  tandai_sinkron();
  return ['nama' => $nama, 'batas' => fmt($batas), 'kode' => $kode];
}

/** Inti pengembalian. $kode = null berarti dikembalikan oleh petugas (sudah lolos cek PIN). */
function proses_kembali(string $id, ?string $kode, string $kondisi, string $catatan): array {
  $now = time(); $kondisi = kondisi_valid($kondisi); $catatan = bersih($catatan, 200);
  $hasil = dt_tx(function (PDO $pdo) use ($id, $kode, $kondisi, $catatan, $now) {
    $t = trx_aktif($id);
    if (!$t) throw new UserError('Perangkat ini tidak sedang dipinjam.');
    if ($kode !== null) {
      if ((int)$t['salah'] >= 5) return ['kunci' => true];
      if (!hash_equals($t['kode_hash'], hash_kode($kode, $id, (int)$t['pinjam_at']))) {
        $pdo->prepare('UPDATE dt_trx SET salah=salah+1 WHERE id=?')->execute([$t['id']]);
        return ['sisa' => 4 - (int)$t['salah']];   // dikomit agar hitungan salah tersimpan
      }
    }
    $pdo->prepare('UPDATE dt_trx SET kembali_at=?, kondisi_kembali=?, catatan=?, dikembalikan_oleh=? WHERE id=?')
        ->execute([$now, $kondisi, $catatan, $kode === null ? 'petugas' : 'peminjam', $t['id']]);
    $periksa = $kondisi !== 'Baik' ? 1 : 0;
    $pdo->prepare('UPDATE dt_device SET kondisi=?, perlu_periksa=? WHERE id=?')->execute([$kondisi, $periksa, $id]);
    catat($kode === null ? 'petugas' : $t['nama'], 'kembali', "$id ($kondisi)");
    notif_kembali($t, $id, $kondisi, $catatan, $now, $kode === null, (bool)$periksa);
    return ['periksa' => (bool)$periksa, 'waktu' => fmt($now)];
  });
  if (isset($hasil['kunci'])) throw new UserError('Kode terkunci karena terlalu sering salah. Minta bantuan petugas sarpras.');
  if (isset($hasil['sisa'])) throw new UserError($hasil['sisa'] > 0 ? 'Kode pengembalian salah. Sisa percobaan: ' . $hasil['sisa'] . '.' : 'Kode salah dan kini terkunci. Minta bantuan petugas sarpras.');
  tandai_sinkron();
  return $hasil;
}

function kembali($d): array {
  $d = is_array($d) ? $d : [];
  $kode = preg_replace('/\D/', '', (string)($d['kode'] ?? ''));
  if (strlen($kode) !== 4) throw new UserError('Kode pengembalian terdiri dari 4 angka.');
  return proses_kembali(normId($d['id'] ?? ''), $kode, (string)($d['kondisi'] ?? ''), (string)($d['catatan'] ?? ''));
}

/* ================= API petugas ================= */

function adminKembalikan($pin, $id, $kondisi, $catatan = ''): array {
  cek_pin($pin);
  return proses_kembali(normId($id), null, (string)$kondisi, (string)$catatan);
}

function adminTandaiDiperiksa($pin, $id, $kondisi = null): array {
  cek_pin($pin); $id = normId($id); $d = dev_row($id);
  if (!$d) throw new UserError('Perangkat tidak ditemukan.');
  $k = $kondisi === null ? $d['kondisi'] : kondisi_valid($kondisi);
  if ($k === 'Rusak berat') throw new UserError('Perangkat "Rusak berat" tidak bisa dibuka untuk dipinjam. Pilih kondisi lain, biarkan tetap "Perlu diperiksa" selama diperbaiki, atau hapus/arsipkan bila tidak dipakai lagi.');
  db()->prepare('UPDATE dt_device SET perlu_periksa=0, kondisi=? WHERE id=?')->execute([$k, $id]);
  catat('petugas', 'diperiksa', "$id ($k)"); tandai_sinkron();
  return ['id' => $id];
}

function adminAddDevice($pin, $d): array {
  cek_pin($pin); $d = is_array($d) ? $d : [];
  $id = normId($d['id'] ?? '');
  $jenis = in_array($d['jenis'] ?? '', ['Laptop', 'Tablet', 'Lainnya'], true) ? $d['jenis'] : 'Lainnya';
  $merek = bersih($d['merek'] ?? '', 40); $model = bersih($d['model'] ?? '', 60);
  if ($merek === '' || $model === '') throw new UserError('Merek dan model wajib diisi.');
  $kond = kondisi_valid($d['kondisi'] ?? 'Baik');
  $ada = db()->prepare('SELECT aktif FROM dt_device WHERE id=?'); $ada->execute([$id]); $ar = $ada->fetch();
  $inv = bersih($d['inv'] ?? '', 40); $pk = $kond === 'Baik' ? 0 : 1;
  if ($ar && (int)$ar['aktif'] === 1) throw new UserError('Kode aset ' . $id . ' sudah dipakai.');
  if ($ar) { // kode lama yang pernah diarsipkan: aktifkan kembali dengan data baru
    db()->prepare('UPDATE dt_device SET jenis=?,merek=?,model=?,inv=?,kondisi=?,perlu_periksa=?,aktif=1 WHERE id=?')->execute([$jenis, $merek, $model, $inv, $kond, $pk, $id]);
  } else {
    db()->prepare('INSERT INTO dt_device(id,jenis,merek,model,inv,kondisi,perlu_periksa,dibuat) VALUES(?,?,?,?,?,?,?,?)')->execute([$id, $jenis, $merek, $model, $inv, $kond, $pk, time()]);
  }
  catat('petugas', 'tambah', $id); tandai_sinkron();
  return ['id' => $id];
}

function adminData($pin): array {
  cek_pin($pin); $now = time(); $pdo = db();
  $aktif = [];
  foreach ($pdo->query('SELECT * FROM dt_trx WHERE kembali_at IS NULL') as $t) $aktif[$t['device_id']] = $t;
  $devs = []; $r = ['total' => 0, 'tersedia' => 0, 'dipinjam' => 0, 'terlambat' => 0, 'periksa' => 0];
  foreach ($pdo->query('SELECT * FROM dt_device WHERE aktif=1 ORDER BY id') as $d) {
    $a = $aktif[$d['id']] ?? null; $s = status_dev($d, $a, $now); $r['total']++;
    $r[['Tersedia' => 'tersedia', 'Dipinjam' => 'dipinjam', 'Terlambat' => 'terlambat', 'Perlu diperiksa' => 'periksa'][$s]]++;
    $devs[] = ['id' => $d['id'], 'jenis' => $d['jenis'], 'merek' => $d['merek'], 'model' => $d['model'], 'inv' => $d['inv'],
      'kondisi' => $d['kondisi'], 'status' => $s, 'peminjam' => $a ? $a['nama'] . ' (' . $a['kelas'] . ')' : '',
      'sejak' => $a ? fmt((int)$a['pinjam_at']) : '', 'batas' => $a ? fmt((int)$a['batas_at']) : ''];
  }
  $riw = [];
  foreach ($pdo->query('SELECT * FROM dt_trx ORDER BY pinjam_at DESC LIMIT 500') as $t) {
    $riw[] = ['id' => (int)$t['id'], 'pinjam' => fmt((int)$t['pinjam_at']), 'aset' => $t['device_id'], 'nama' => $t['nama'], 'kelas' => $t['kelas'],
      'kembali' => fmt($t['kembali_at'] ? (int)$t['kembali_at'] : null), 'kondisiKembali' => (string)$t['kondisi_kembali'],
      'status' => $t['kembali_at'] ? 'Dikembalikan' : ($now > (int)$t['batas_at'] ? 'Terlambat' : 'Dipinjam'), 'catatan' => $t['catatan']];
  }
  $s30 = $now - 30 * 86400;
  $q = $pdo->prepare("SELECT COUNT(*) c,
      COALESCE(ROUND(AVG(CASE WHEN kembali_at IS NOT NULL THEN (kembali_at-pinjam_at)/60.0 END)),0) rata,
      SUM(CASE WHEN (kembali_at IS NOT NULL AND kembali_at>batas_at) OR (kembali_at IS NULL AND ?>batas_at) THEN 1 ELSE 0 END) telat,
      SUM(CASE WHEN kondisi_kembali IS NOT NULL AND kondisi_kembali<>'Baik' THEN 1 ELSE 0 END) buruk
    FROM dt_trx WHERE pinjam_at>=?");
  $q->execute([$now, $s30]); $st = $q->fetch();
  return ['ringkas' => $r, 'devices' => $devs, 'riwayat' => $riw,
    'stat' => ['trx30' => (int)$st['c'], 'rataMenit' => (int)$st['rata'], 'telat' => (int)$st['telat'], 'tidakBaik' => (int)$st['buruk']],
    'kondisiList' => dtc('kondisi', ['Baik']), 'appUrl' => app_url(),
    'sync' => ['aktif' => Sheets::aktif(), 'ok' => meta_get('dt.sync_ok'), 'galat' => meta_get('dt.sync_err'), 'tertunda' => meta_get('dt.dirty') === '1'],
    'notif' => notif_status(), 'cronUrl' => app_url() . 'sync.php?key=' . cron_key(), 'arsipSheet' => arsip_aktif()];
}

function app_url(): string {
  $u = trim((string)dtc('base_url', ''));
  if ($u !== '') return rtrim($u, '/') . '/';
  $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
  return ($https ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? 'localhost') . rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/') . '/';
}

/** Tombol "Kirim ke Google Sheets sekarang": sekaligus menguji koneksi dan menampilkan pesan galat yang jelas. */
function adminSyncSekarang($pin): array {
  cek_pin($pin);
  if (!Sheets::aktif()) throw new UserError('Google Sheets belum diatur. Isi WEBHOOK_URL di core/config.php.');
  tandai_sinkron(); dt_sync_run();
  $err = meta_get('dt.sync_err');
  if ($err !== '') throw new UserError($err);
  return ['ok' => meta_get('dt.sync_ok')];
}


/* ================= Hapus perangkat & riwayat ================= */

function arsip_aktif(): bool { return (bool)dtc('arsip_riwayat', true) && (string)cfg('google.webhook_url', '') !== ''; }

/** Hapus perangkat: bila belum pernah dipinjam dihapus permanen; bila punya riwayat hanya diarsipkan (riwayat tetap utuh). */
function adminHapusPerangkat($pin, $id): array {
  cek_pin($pin); $id = normId($id);
  $mode = dt_tx(function (PDO $pdo) use ($id) {
    if (!dev_row($id)) throw new UserError('Perangkat tidak ditemukan.');
    if (trx_aktif($id)) throw new UserError('Perangkat sedang dipinjam. Kembalikan dulu sebelum dihapus.');
    $n = $pdo->prepare('SELECT COUNT(*) FROM dt_trx WHERE device_id=?'); $n->execute([$id]);
    if ((int)$n->fetchColumn() === 0) { $pdo->prepare('DELETE FROM dt_device WHERE id=?')->execute([$id]); return 'hapus'; }
    $pdo->prepare('UPDATE dt_device SET aktif=0, perlu_periksa=0 WHERE id=?')->execute([$id]); return 'arsip';
  });
  catat('petugas', 'hapus_perangkat', "$id ($mode)"); tandai_sinkron();
  return ['mode' => $mode];
}

/** Hapus catatan riwayat yang SUDAH dikembalikan. Bila arsip aktif, salinannya disimpan dulu ke tab arsip Google Sheet; jika gagal, tidak ada yang dihapus. */
function hapus_trx(array $rows): int {
  if (!$rows) return 0;
  if (arsip_aktif()) {
    $now = fmt(time()); $out = [];
    foreach ($rows as $t) $out[] = [$t['id'], $t['device_id'], $t['nama'], $t['kelas'], $t['nis'], fmt((int)$t['pinjam_at']), fmt((int)$t['batas_at']),
      fmt((int)$t['kembali_at']), $t['kondisi_awal'], (string)$t['kondisi_kembali'], (string)$t['dikembalikan_oleh'], $t['catatan'], $now];
    try {
      Sheets::tambah((string)dtc('tab_arsip', 'DT_Arsip'), $out, ['ID', 'Kode aset', 'Nama', 'Kelas', 'NIS', 'Dipinjam', 'Batas kembali', 'Dikembalikan',
        'Kondisi awal', 'Kondisi kembali', 'Dikembalikan oleh', 'Catatan', 'Diarsipkan pada']);
    } catch (Throwable $e) { throw new UserError('Gagal menyalin ke arsip Google Sheet, data TIDAK dihapus. ' . $e->getMessage()); }
  }
  dt_tx(function (PDO $pdo) use ($rows) {
    $st = $pdo->prepare('DELETE FROM dt_trx WHERE id=? AND kembali_at IS NOT NULL');
    foreach ($rows as $t) $st->execute([$t['id']]);
    catat('petugas', 'hapus_riwayat', count($rows) . ' catatan');
  });
  tandai_sinkron();
  return count($rows);
}
function adminHapusRiwayat($pin, $ids): array {
  cek_pin($pin);
  $ids = array_slice(array_values(array_filter(array_map('intval', is_array($ids) ? $ids : []))), 0, 300);
  if (!$ids) throw new UserError('Tidak ada catatan yang dipilih.');
  $q = db()->prepare('SELECT * FROM dt_trx WHERE kembali_at IS NOT NULL AND id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')');
  $q->execute($ids);
  return ['jumlah' => hapus_trx($q->fetchAll())];
}
function adminHapusRiwayatLama($pin, $bulan): array {
  cek_pin($pin); $b = max(1, min(60, (int)$bulan));
  $q = db()->prepare('SELECT * FROM dt_trx WHERE kembali_at IS NOT NULL AND pinjam_at<? ORDER BY id LIMIT 2000');
  $q->execute([strtotime("-$b months")]);
  return ['jumlah' => hapus_trx($q->fetchAll())];
}

/* ================= Notifikasi email ================= */

function cron_key(): string { $k = trim((string)cfg('cron_key', '')); return $k !== '' ? $k : substr(hash_hmac('sha256', 'cron', app_secret()), 0, 24); }
function notif_aktif(): bool { return trim((string)dtc('notifikasi.email', '')) !== '' && (string)cfg('google.webhook_url', '') !== ''; }
function notif_antri(string $jenis, string $subjek, string $isi): void {
  if (!notif_aktif()) return;
  db()->prepare('INSERT INTO dt_notif(jenis,subjek,isi,dibuat) VALUES(?,?,?,?)')->execute([$jenis, '[AKSMA Device Tracker] ' . $subjek, $isi, time()]);
}
/** Kirim antrean email. Yang gagal dicoba lagi pada panggilan berikutnya (maks 8x). */
function notif_kirim(): void {
  if (!notif_aktif()) return;
  $lock = @fopen(AKSMA_ROOT . '/storage/dt-notif.lock', 'c');
  if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) return;
  try {
    foreach (db()->query('SELECT * FROM dt_notif WHERE terkirim_at IS NULL AND percobaan<8 ORDER BY id LIMIT 10')->fetchAll() as $n) {
      try {
        Sheets::email((string)dtc('notifikasi.email'), $n['subjek'], $n['isi']);
        db()->prepare('UPDATE dt_notif SET terkirim_at=? WHERE id=?')->execute([time(), $n['id']]);
      } catch (Throwable $e) {
        db()->prepare('UPDATE dt_notif SET percobaan=percobaan+1, galat=? WHERE id=?')->execute([$e->getMessage(), $n['id']]);
        log_galat('notif: ' . $e->getMessage()); break;
      }
    }
  } finally { flock($lock, LOCK_UN); fclose($lock); }
}
function lama_teks(int $menit): string { $menit = max(0, $menit); return $menit >= 60 ? intdiv($menit, 60) . ' jam ' . ($menit % 60) . ' menit' : $menit . ' menit'; }

/** Email untuk SETIAP pengembalian; isi dan judul menyesuaikan kondisi perangkat dan ketepatan waktu. */
function notif_kembali(array $t, string $id, string $kondisi, string $catatan, int $now, bool $petugas, bool $periksa): void {
  if (!dtc('notifikasi.kembali', true) && !($periksa && dtc('notifikasi.perlu_periksa', true))) return;
  $dv = dev_row($id); $nm = $dv ? trim($dv['jenis'] . ' ' . $dv['merek'] . ' ' . $dv['model']) : '';
  $selisih = $now - (int)$t['batas_at'];
  $waktu = $selisih > 60 ? 'Terlambat ' . lama_teks(intdiv($selisih, 60)) : 'Tepat waktu';
  if ($kondisi === 'Baik') {
    $judul = "$id dikembalikan (kondisi baik)"; $ikon = '✅'; $kepala = 'Perangkat dikembalikan';
    $status = 'Status perangkat: Tersedia kembali dan bisa dipinjam.';
  } elseif ($kondisi === 'Rusak berat') {
    $judul = "PERLU TINDAKAN: $id dikembalikan dalam kondisi rusak berat"; $ikon = '🚨'; $kepala = 'Perangkat dikembalikan dalam kondisi RUSAK BERAT';
    $status = "Status perangkat: Perlu diperiksa. Tidak bisa dipinjam.\nSegera tindak lanjuti (perbaikan atau penggantian).";
  } else {
    $judul = "$id dikembalikan, perlu diperiksa ($kondisi)"; $ikon = '⚠️'; $kepala = "Perangkat dikembalikan dengan kondisi: $kondisi";
    $status = 'Status perangkat: Perlu diperiksa. Tidak bisa dipinjam sampai petugas memeriksanya.';
  }
  $isi = "$ikon $kepala\n\n$id" . ($nm !== '' ? " ($nm)" : '') . "\nKondisi: $kondisi\nPeminjam: {$t['nama']} ({$t['kelas']})\n" .
    'Dipinjam: ' . fmt((int)$t['pinjam_at']) . "\nDikembalikan: " . fmt($now) . ' (lama ' . lama_teks(intdiv($now - (int)$t['pinjam_at'], 60)) . ")\n" .
    "Ketepatan waktu: $waktu\nDikonfirmasi oleh: " . ($petugas ? 'petugas' : 'peminjam (kode pengembalian)') .
    "\nCatatan: " . ($catatan !== '' ? $catatan : '-') . "\n\n$status\n\nBuka halaman petugas: " . app_url() . '?page=admin';
  notif_antri($periksa ? 'perlu_periksa' : 'kembali', $judul, $isi);
}

/** Satu email ringkasan untuk semua perangkat yang baru melewati batas waktu (masing-masing hanya diberitahukan sekali). */
function notif_cek_telat(): void {
  if (!notif_aktif() || !dtc('notifikasi.terlambat', true)) return;
  $now = time(); $tol = (int)dtc('notifikasi.toleransi_menit', 10) * 60;
  dt_tx(function (PDO $pdo) use ($now, $tol) {
    $q = $pdo->prepare('SELECT t.*, d.jenis, d.merek, d.model FROM dt_trx t JOIN dt_device d ON d.id=t.device_id
                        WHERE t.kembali_at IS NULL AND t.notif_telat=0 AND t.batas_at<?');
    $q->execute([$now - $tol]); $rows = $q->fetchAll();
    if (!$rows) return;
    $baris = [];
    foreach ($rows as $t) {
      $m = intdiv($now - (int)$t['batas_at'], 60);
      $baris[] = "- {$t['device_id']} ({$t['jenis']} {$t['merek']} {$t['model']}), dipinjam {$t['nama']} ({$t['kelas']}), batas " . fmt((int)$t['batas_at']) .
        ', terlambat ' . ($m >= 60 ? intdiv($m, 60) . ' jam ' . ($m % 60) . ' menit' : "$m menit");
      $pdo->prepare('UPDATE dt_trx SET notif_telat=1 WHERE id=?')->execute([$t['id']]);
    }
    notif_antri('terlambat', count($rows) . ' perangkat belum dikembalikan',
      "Perangkat berikut sudah melewati batas waktu peminjaman:\n\n" . implode("\n", $baris) . "\n\nBuka halaman petugas: " . app_url() . '?page=admin');
  });
}
/** Dipanggil tiap ada aktivitas (maks sekali per 5 menit) dan oleh cron. */
function dt_jalankan_berkala(bool $paksa = false): void {
  if (!notif_aktif()) return;
  $now = time();
  if (!$paksa && $now - (int)meta_get('dt.cek_at', '0') < 300) return;
  meta_set('dt.cek_at', (string)$now);
  notif_cek_telat();
  db()->prepare('DELETE FROM dt_notif WHERE terkirim_at IS NOT NULL AND terkirim_at<?')->execute([$now - 30 * 86400]);
}
function notif_status(): array {
  $q = db()->query('SELECT COUNT(*) FROM dt_notif WHERE terkirim_at IS NULL AND percobaan<8'); $antri = (int)$q->fetchColumn();
  $g = db()->query('SELECT galat FROM dt_notif WHERE terkirim_at IS NULL AND galat IS NOT NULL ORDER BY id DESC LIMIT 1')->fetchColumn();
  return ['aktif' => notif_aktif(), 'email' => (string)dtc('notifikasi.email', ''), 'antri' => $antri, 'galat' => $g ? (string)$g : ''];
}
function adminTesNotif($pin): array {
  cek_pin($pin);
  if (!notif_aktif()) throw new UserError('Notifikasi belum aktif. Isi alamat email pada core/config.php dan pastikan WEBHOOK_URL terisi.');
  try { Sheets::email((string)dtc('notifikasi.email'), '[AKSMA Device Tracker] Uji notifikasi', "Jika email ini sampai, notifikasi berfungsi.\nWaktu: " . fmt(time())); }
  catch (Throwable $e) { throw new UserError($e->getMessage()); }
  return ['ok' => true];
}
