# permatafix

Perbaikan file `tf.php` — Inquiry Transfer Bank via Permata SNAP (SIS/Assist Switching Middleware).

## File

| File | Keterangan |
|------|------------|
| `tf.php` | Script PHP single-file untuk testing inquiry transfer bank melalui jalur Permata SNAP |

---

## Perubahan tf.php — Commit 2 (sesi ini, setelah uji coba live)

Ditemukan dari debug log transaksi BCA rekening 5465389271 (RC=00 sukses, tapi STEP 4=`[]`):

| # | Bug | Root Cause | Fix |
|---|-----|------------|-----|
| 1 | **STEP 4 = `[]`** — response tidak ter-parse | Server masih ada PHP Notice HTML (`<br/><b>Notice</b>:...`) sebelum JSON, menyebabkan `json_decode()` return null | `parseISO8583Response()`: tambah **Langkah 0** — `strpos($raw, '{')` untuk strip konten sebelum JSON |
| 2 | **Nama rekening tidak tampil di result** | Format DE048 response adalah `"NoRek\|NamaPemilik\|KodeBI\|NamaBank"` (4 bagian), parser lama hanya paham 2 bagian | Parsing `parts[0..3]`: NoRek, NamaPemilik, KodeBI, NamaBank — bank_code & bank_name diambil dari response, bukan dari form input |
| 3 | **Komentar DE013 menyesatkan** | Server mengembalikan DE013=`"1406"` (DDMM) tapi kita kirim `"0614"` (MMDD) | Tambah catatan: inkonsistensi ada di sisi server; request tetap MMDD sesuai source controller |

---

## Perubahan tf.php — Commit 1 (sesi sebelumnya)

| # | Bug | Fix |
|---|-----|-----|
| 1 | **DE013 format salah** — `date('dm')` → `"1406"` (hari-bulan) | Ganti ke `date('md')` → `"0614"` (bulan-hari), sesuai source `mbanking.controller.php` |
| 2 | **biayaAdmin parsing** — `substr($de004Raw, 0, -2)` tanpa guard | Tambah `strlen($de004Raw) >= 3` di 2 lokasi |
| 3 | **daftarBank BJB duplikat** — `'036'` dan `'110'` sama-sama `'BJB'` | Dibedakan: `'110'` = kode BPD resmi, `'036'` = kode lama/RTGS |
| 4 | **mapKodeBankDE048 kurang '110'** | Tambah `'110' => 'BJBKIDJA'` |

---

## Perubahan tf.php — Commit 0 (sesi pertama)

- `buildDE048()` — `$kodeBank` dinamis, tidak hardcoded `BLTRFAG`
- `buildISO8583Request()` — membaca `kode_bank_de048` untuk DE048
- `parseISO8583Response()` — normalisasi `DE039→RC`, `DE048→MSG`
- Form HTML — tambah field `kode_bank_de048` + JavaScript auto-fill
- `$daftarBank` — tambah bank 494/BLTRFAG dan bank lain
- `$mapKodeBankDE048` — mapping baru kode numerik BI → kode Assist/SWIFT

---

## Format DE048 Response (terkonfirmasi dari live test)

```
"5465389271|FLIPTECH LENTERA IP PT|014|BCA"
  [0] NoRekening       = 5465389271
  [1] NamaPemilik      = FLIPTECH LENTERA IP PT
  [2] KodeBankBI       = 014
  [3] NamaBankSingkat  = BCA
```

Biaya admin dari DE004: `"000000750000"` → Rp 7.500

## Referensi

- `mbanking.controller.php` → `ProsesInquiryPayment()`, `GetInquiryHistoryCBS()`
- `PermataSNAP.mod.php` → `GetTFINQ()`, `SetResponseISO()`
- Format ISO 8583: MTI=010 (inquiry), DE039 (RC), DE048 (tagihan/pesan), DE103 (kode bank numerik BI)
