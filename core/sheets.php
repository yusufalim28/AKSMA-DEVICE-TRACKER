<?php
declare(strict_types=1);
/* Klien Google Sheets API (service account). Tanpa Composer; butuh ekstensi curl + openssl. */
final class Sheets {
  private static function b64(string $s): string { return rtrim(strtr(base64_encode($s), '+/', '-_'), '='); }

  static function aktif(): bool { return (string)cfg('google.webhook_url', '') !== '' || (string)cfg('google.spreadsheet_id', '') !== ''; }

  /** Mode mudah: kirim perintah ke skrip kecil di Google Sheet (lihat google-sheets/penerima.gs). */
  private static function kirim(array $p): void {
    $p['rahasia'] = (string)cfg('google.webhook_secret', '');
    $ch = curl_init((string)cfg('google.webhook_url'));
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 60,
      CURLOPT_HTTPHEADER => ['Content-Type: text/plain;charset=utf-8'], CURLOPT_POSTFIELDS => json_encode($p, JSON_UNESCAPED_UNICODE)]);
    $body = curl_exec($ch);
    if ($body === false) { $e = curl_error($ch); curl_close($ch); throw new RuntimeException('Tidak bisa menghubungi Google: ' . $e); }
    curl_close($ch);
    $body = trim((string)$body);
    if ($body === 'SALAH_RAHASIA') throw new RuntimeException('Kata rahasia di config.php tidak sama dengan yang ada di Apps Script.');
    if ($body !== 'OK') throw new RuntimeException('Google tidak menjawab OK. Pastikan kode Apps Script sudah versi terbaru dan di-deploy ulang (akses: Siapa saja).');
  }
  /** Tambah baris di akhir tab (dipakai untuk arsip). */
  static function tambah(string $tab, array $rows, array $header): void { self::kirim(['aksi' => 'tambah', 'tab' => $tab, 'rows' => $rows, 'header' => $header]); }
  /** Kirim email lewat akun Google pemilik skrip. */
  static function email(string $kepada, string $subjek, string $isi): void { self::kirim(['aksi' => 'email', 'kepada' => $kepada, 'subjek' => $subjek, 'isi' => $isi]); }

  private static function http(string $method, string $url, ?array $json = null, array $headers = [], ?string $raw = null): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 25,
      CURLOPT_HTTPHEADER => $headers]);
    if ($json !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($json, JSON_UNESCAPED_UNICODE));
    if ($raw !== null)  curl_setopt($ch, CURLOPT_POSTFIELDS, $raw);
    $body = curl_exec($ch);
    if ($body === false) { $e = curl_error($ch); curl_close($ch); throw new RuntimeException('Koneksi ke Google gagal: ' . $e); }
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    return [$code, json_decode((string)$body, true) ?: []];
  }

  private static function token(): string {
    $cache = AKSMA_ROOT . '/storage/gtoken.json';
    if (is_file($cache)) { $c = json_decode((string)file_get_contents($cache), true); if (($c['exp'] ?? 0) > time() + 120) return $c['tok']; }
    $sa = json_decode((string)@file_get_contents((string)cfg('google.service_account')), true);
    if (!$sa || empty($sa['private_key']) || empty($sa['client_email'])) throw new RuntimeException('File service account Google tidak ditemukan atau tidak valid.');
    $now = time();
    $head = self::b64(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
    $claim = self::b64(json_encode(['iss' => $sa['client_email'], 'scope' => 'https://www.googleapis.com/auth/spreadsheets',
      'aud' => 'https://oauth2.googleapis.com/token', 'iat' => $now, 'exp' => $now + 3600]));
    if (!openssl_sign("$head.$claim", $sig, $sa['private_key'], OPENSSL_ALGO_SHA256)) throw new RuntimeException('Gagal menandatangani JWT.');
    [$code, $r] = self::http('POST', 'https://oauth2.googleapis.com/token', null, ['Content-Type: application/x-www-form-urlencoded'],
      http_build_query(['grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => "$head.$claim." . self::b64($sig)]));
    if ($code !== 200 || empty($r['access_token'])) throw new RuntimeException('Token Google ditolak: ' . ($r['error_description'] ?? $code));
    file_put_contents($cache, json_encode(['tok' => $r['access_token'], 'exp' => $now + (int)($r['expires_in'] ?? 3600)])); @chmod($cache, 0600);
    return $r['access_token'];
  }

  private static function api(string $method, string $path, ?array $json = null): array {
    $url = 'https://sheets.googleapis.com/v4/spreadsheets/' . cfg('google.spreadsheet_id') . $path;
    return self::http($method, $url, $method === 'GET' ? null : ($json ?? []), ['Authorization: Bearer ' . self::token(), 'Content-Type: application/json']);
  }

  /** Ganti seluruh isi tab dengan $rows (baris pertama = header). Tab dibuat otomatis bila belum ada. */
  static function replace(string $tab, array $rows): void {
    if ((string)cfg('google.webhook_url', '') !== '') { self::kirim(['aksi' => 'ganti', 'tab' => $tab, 'rows' => $rows]); return; }
    $enc = rawurlencode($tab);
    [$code, $r] = self::api('POST', "/values/$enc:clear");
    if ($code === 400) { // tab belum ada
      [$c2, $r2] = self::api('POST', ':batchUpdate', ['requests' => [['addSheet' => ['properties' => ['title' => $tab]]]]]);
      if ($c2 !== 200) throw new RuntimeException('Gagal membuat tab ' . $tab . ': ' . ($r2['error']['message'] ?? $c2));
    } elseif ($code !== 200) {
      throw new RuntimeException('Sheets clear gagal: ' . ($r['error']['message'] ?? $code));
    }
    [$c3, $r3] = self::api('PUT', "/values/$enc!A1?valueInputOption=RAW", ['values' => $rows]);
    if ($c3 !== 200) throw new RuntimeException('Sheets update gagal: ' . ($r3['error']['message'] ?? $c3));
  }
}
