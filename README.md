# permatafix

Perbaikan file `tf.php` — Inquiry Transfer Bank via Permata SNAP (SIS/Assist Switching Middleware).

## File

| File | Keterangan |
|------|------------|
| `tf.php` | Script PHP single-file untuk testing inquiry transfer bank melalui jalur Permata SNAP |

## Perubahan tf.php (sesi ini)

| # | Bug | Fix |
|---|-----|-----|
| 1 | **DE013 format salah** — `date('dm')` menghasilkan `"1406"` (hari-bulan) | Ganti ke `date('md')` → `"0614"` (bulan-hari), sesuai source `mbanking.controller.php` |
| 2 | **biayaAdmin parsing** — `substr($de004Raw, 0, -2)` tanpa guard panjang string | Tambah `strlen($de004Raw) >= 3` sebelum `substr` di 2 lokasi |
| 3 | **daftarBank BJB duplikat** — `'036'` dan `'110'` keduanya `'BJB'` tanpa keterangan | Dibedakan: `'110'` = kode BPD resmi, `'036'` = kode lama/RTGS |
| 4 | **mapKodeBankDE048 kurang '110'** — auto-fill DE048 tidak bekerja jika pilih kode BPD BJB | Tambah `'110' => 'BJBKIDJA'` ke mapping |

## Perubahan sebelumnya (sesi sebelumnya)

- `buildDE048()` — parameter `$kodeBank` dinamis, tidak lagi hardcoded `BLTRFAG`
- `buildISO8583Request()` — membaca `kode_bank_de048` untuk DE048
- `parseISO8583Response()` — normalisasi `DE039→RC`, `DE048→MSG`
- Form HTML — tambah field `kode_bank_de048` + JavaScript auto-fill dari dropdown
- `$daftarBank` — tambah bank 494/BLTRFAG dan bank lain
- `$mapKodeBankDE048` — mapping baru kode numerik BI → kode Assist/SWIFT

## Referensi

- `mbanking.controller.php` → `ProsesInquiryPayment()`, `GetInquiryHistoryCBS()`
- `PermataSNAP.mod.php` → `GetTFINQ()`, `SetResponseISO()`
- Format ISO 8583: MTI=010 (inquiry), DE039 (RC), DE048 (tagihan/pesan), DE103 (kode bank numerik BI)
