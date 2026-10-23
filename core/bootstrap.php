<?php
declare(strict_types=1);
/* Inti AKSMA DIGITAL HUB: konfigurasi, database, utilitas. Dipakai bersama oleh semua modul. */
define('AKSMA_ROOT', dirname(__DIR__));
if (!is_file(__DIR__ . '/config.php')) { http_response_code(500); exit('File core/config.php tidak ditemukan.'); }
$GLOBALS['AKSMA_CFG'] = require __DIR__ . '/config.php';
date_default_timezone_set((string)cfg('timezone', 'Asia/Jakarta'));
mb_internal_encoding('UTF-8');

function cfg(string $path, $def = null) {
  $v = $GLOBALS['AKSMA_CFG'];
  foreach (explode('.', $path) as $k) { if (!is_array($v) || !array_key_exists($k, $v)) return $def; $v = $v[$k]; }
  return $v;
}
function db(): PDO {
  static $pdo = null;
  if ($pdo) return $pdo;
  $dir = AKSMA_ROOT . '/storage';
  if (!is_dir($dir)) mkdir($dir, 0750, true);
  $pdo = new PDO('sqlite:' . $dir . '/aksma.sqlite');
  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
  $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
  $pdo->exec('PRAGMA busy_timeout=8000; PRAGMA foreign_keys=ON; PRAGMA journal_mode=WAL;');
  $pdo->exec('CREATE TABLE IF NOT EXISTS meta(k TEXT PRIMARY KEY, v TEXT)');
  return $pdo;
}
function meta_get(string $k, string $def = ''): string {
  $q = db()->prepare('SELECT v FROM meta WHERE k=?'); $q->execute([$k]);
  $v = $q->fetchColumn(); return $v === false ? $def : (string)$v;
}
function meta_set(string $k, string $v): void {
  db()->prepare('INSERT INTO meta(k,v) VALUES(?,?) ON CONFLICT(k) DO UPDATE SET v=excluded.v')->execute([$k, $v]);
}
/* Kunci rahasia untuk HMAC; dibuat otomatis sekali dan disimpan di storage/. */
function app_secret(): string {
  $f = AKSMA_ROOT . '/storage/secret.key';
  if (!is_file($f)) { file_put_contents($f, bin2hex(random_bytes(32))); @chmod($f, 0600); }
  return trim((string)file_get_contents($f));
}
function log_galat(string $m): void {
  @file_put_contents(AKSMA_ROOT . '/storage/galat.log', date('c') . ' ' . $m . "\n", FILE_APPEND | LOCK_EX);
}
