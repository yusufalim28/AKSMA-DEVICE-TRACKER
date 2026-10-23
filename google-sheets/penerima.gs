const KATA_RAHASIA = 'GANTI-KATA-RAHASIA';  // samakan persis dengan $KATA_RAHASIA di core/config.php

function doPost(e) {
  if (!e || !e.postData) return teks('Skrip ini hanya menerima kiriman dari sistem. Jangan tekan Jalankan; lakukan Deploy.');
  const d = JSON.parse(e.postData.contents);
  if (d.rahasia !== KATA_RAHASIA) return teks('SALAH_RAHASIA');

  if (d.aksi === 'email') {
    MailApp.sendEmail({ to: d.kepada, subject: d.subjek, body: d.isi, name: 'AKSMA Device Tracker' });
    return teks('OK');
  }

  const kunci = LockService.getScriptLock();
  kunci.waitLock(30000);
  try {
    const ss = SpreadsheetApp.getActive();
    const sh = ss.getSheetByName(d.tab) || ss.insertSheet(d.tab);
    if (d.aksi === 'tambah') {                       // arsip: tambah di bawah data yang ada
      if (sh.getLastRow() === 0 && d.header) tulis(sh, 1, [d.header], true);
      if (d.rows.length) tulis(sh, sh.getLastRow() + 1, d.rows, false);
    } else {                                         // sinkron: tulis ulang seluruh tab
      sh.clearContents();
      if (d.rows.length) tulis(sh, 1, d.rows, true);
    }
  } finally { kunci.releaseLock(); }
  return teks('OK');
}

function tulis(sh, baris, rows, tebal) {
  const akhir = baris + rows.length - 1;
  if (sh.getMaxRows() < akhir) sh.insertRowsAfter(sh.getMaxRows(), akhir - sh.getMaxRows());
  const area = sh.getRange(baris, 1, rows.length, rows[0].length);
  area.setNumberFormat('@');   // simpan sebagai teks agar NIS/tanggal tidak berubah format
  area.setValues(rows);
  if (tebal) { sh.getRange(baris, 1, 1, rows[0].length).setFontWeight('bold'); if (baris === 1) sh.setFrozenRows(1); }
}

function teks(s) { return ContentService.createTextOutput(s); }

// Jalankan fungsi INI SEKALI (pilih di menu fungsi, tekan Jalankan) agar Google meminta izin kirim email.
function izinkanEmail() { Logger.log('Sisa kuota email hari ini: ' + MailApp.getRemainingDailyQuota()); }
