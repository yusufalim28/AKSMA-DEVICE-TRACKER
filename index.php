<?php
/* Portal AKSMA DIGITAL HUB. Modul ditemukan otomatis: setiap folder yang berisi module.json muncul sebagai kartu menu. */
require __DIR__ . '/core/bootstrap.php';
$mods = [];
foreach (glob(__DIR__ . '/*/module.json') ?: [] as $f) {
  $m = json_decode((string)file_get_contents($f), true);
  if (is_array($m) && !empty($m['slug'])) $mods[] = $m;
}
usort($mods, fn($a, $b) => ($a['urutan'] ?? 99) <=> ($b['urutan'] ?? 99));
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
?><!DOCTYPE html>
<html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>AKSMA DIGITAL HUB</title>
<style>
:root{color-scheme:light dark;--k:#F1F3EE;--p:#fff;--t:#17262B;--r:#566A6F;--g:#D5DBD3;--a:#0F5C5C;--l:#F2B632}
@media(prefers-color-scheme:dark){:root{--k:#0E1719;--p:#16252A;--t:#E7EFEC;--r:#9DB0AB;--g:#2B3E43;--a:#6CCFC4}}
*{box-sizing:border-box}body{margin:0;background:var(--k);color:var(--t);font:16px/1.5 system-ui,sans-serif}
main{max-width:760px;margin:0 auto;padding:32px 16px 48px}h1{margin:0 0 4px;font-size:28px}p{color:var(--r);margin:4px 0}
.grid{display:grid;gap:12px;margin-top:24px}
a.kartu{display:block;background:var(--p);border:1px solid var(--g);border-left:6px solid var(--l);border-radius:14px;padding:16px 18px;color:inherit;text-decoration:none}
a.kartu:hover,a.kartu:focus-visible{border-color:var(--a);outline:none}a.kartu b{display:block;font-size:18px}
small a{color:var(--a)}
</style></head><body><main>
<h1>AKSMA DIGITAL HUB</h1><p><?= $h(cfg('nama_sekolah', '')) ?></p>
<div class="grid">
<?php foreach ($mods as $m): ?>
  <a class="kartu" href="<?= $h($m['url'] ?? $m['slug'] . '/') ?>"><b><?= $h($m['menu'] ?? $m['nama'] ?? $m['slug']) ?></b>
  <span style="color:var(--r)"><?= $h($m['deskripsi'] ?? '') ?></span></a>
<?php endforeach; if (!$mods) echo '<p>Belum ada modul terpasang.</p>'; ?>
</div>
<p style="margin-top:28px"><small>Petugas: <?php foreach ($mods as $m) if (!empty($m['admin_url'])) echo '<a href="' . $h($m['admin_url']) . '">' . $h($m['menu'] ?? $m['slug']) . '</a> &nbsp; '; ?></small></p>
</main></body></html>
