<?php
declare(strict_types=1);
require __DIR__ . '/../core/bootstrap.php';
require __DIR__ . '/../core/sheets.php';
require __DIR__ . '/src/lib.php';
require __DIR__ . '/src/sync.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function keluar(array $a): void { echo json_encode($a, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE); }

// Hanya POST dari frontend sendiri (header kustom tidak bisa dikirim form lintas-situs).
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'aksma') {
  http_response_code(403); keluar(['ok' => false, 'error' => 'Permintaan ditolak.']); exit;
}
$in = json_decode((string)file_get_contents('php://input'), true);
$fn = is_array($in) ? (string)($in['fn'] ?? '') : '';
$args = is_array($in) && is_array($in['args'] ?? null) ? array_values($in['args']) : [];
$izin = ['getDevice', 'pinjam', 'kembali', 'adminData', 'adminTandaiDiperiksa', 'adminAddDevice', 'adminKembalikan', 'adminSyncSekarang', 'adminHapusPerangkat', 'adminHapusRiwayat', 'adminHapusRiwayatLama', 'adminTesNotif'];

try {
  if (!in_array($fn, $izin, true)) throw new UserError('Perintah tidak dikenal.');
  dt_migrate();
  $hasil = $fn(...$args);
  keluar(['ok' => true, 'data' => $hasil]);
} catch (UserError $e) {
  keluar(['ok' => false, 'error' => $e->getMessage()]);
} catch (ArgumentCountError $e) {
  keluar(['ok' => false, 'error' => 'Permintaan tidak lengkap.']);
} catch (Throwable $e) {
  log_galat($fn . ': ' . $e->getMessage()); http_response_code(500);
  keluar(['ok' => false, 'error' => 'Terjadi kesalahan pada server.']);
}

// Kirim jawaban ke pengguna dulu, baru urus Google Sheets & email (pengguna tidak menunggu Google).
$tulis = !in_array($fn, ['getDevice', 'adminData', 'adminSyncSekarang', 'adminTesNotif'], true);
$sync = $tulis && Sheets::aktif();
if ($sync || notif_aktif()) {
  if (function_exists('fastcgi_finish_request')) fastcgi_finish_request(); else { @ob_end_flush(); @flush(); }
  ignore_user_abort(true); set_time_limit(90);
  try {
    if ($sync) dt_sync_run();
    dt_jalankan_berkala();
    notif_kirim();
  } catch (Throwable $e) { log_galat('latar: ' . $e->getMessage()); }
}
