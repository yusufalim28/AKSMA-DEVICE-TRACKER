<?php
declare(strict_types=1);
/* Sinkron paksa + penyegar status "Terlambat". Jadwalkan via cron tiap 5 menit:
   */5 * * * *  php /path/ke/device-tracker/sync.php        (atau curl "https://domain/device-tracker/sync.php?key=CRON_KEY") */
require __DIR__ . '/../core/bootstrap.php';
require __DIR__ . '/../core/sheets.php';
require __DIR__ . '/src/lib.php';
require __DIR__ . '/src/sync.php';
if (PHP_SAPI !== 'cli' && !hash_equals(cron_key(), (string)($_GET['key'] ?? ''))) { http_response_code(403); exit('Forbidden'); }
dt_migrate(); dt_sync_run(); dt_jalankan_berkala(true); notif_kirim();
echo "OK sinkron: " . meta_get('dt.sync_ok') . ' ' . meta_get('dt.sync_err') . "\n";
