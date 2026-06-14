# permatafix

Perbaikan file `tf.php` — Inquiry Transfer Bank via Permata SNAP (SIS/Assist Switching Middleware).

## File

| File | Keterangan |
|------|------------|
| `tf.php` | Script PHP single-file untuk testing inquiry transfer bank melalui jalur Permata SNAP |

---

## Perubahan tf.php — v1.6.42 (sesi ini)

**Root cause**: PAYBIFAST mengirim DE048=`"0602*1001*PAYBIFAST~~014*0"` — `"014"` adalah kode numerik BI (BCA), bukan KodeBIFAST BIC yang dibutuhkan Permata SNAP API. Server `PermataSNAP.mod.php` baris 264: `$cBeneficiaryBankCode = $vaDE48Tilde[1]` — menggunakan nilai apa adanya → Permata SNAP reject (DE039=03).

| # | Bug | Root Cause | Fix |
|---|-----|------------|-----|
| 1 | **DE048 PAYBIFAST berisi `"014"` (numerik) bukan BIC** | `buildISO8583PaymentRequest()` fallback chain: `kode_bank_bifast ?? kode_bank_de048 ?? kode_bank` — jika `kode_bank_bifast` kosong, jatuh ke `kode_bank` = `"014"` | Tambah guard: `if (preg_match('/^\d+$/', $kodeBankDE048))` → auto-map via `$mapKodeBankBIFAST` |
| 2 | **Bug yang sama di `buildISO8583Request()` (INQ)** | Fallback chain sama, bisa kirim numerik ke DE048 | Tambah guard numerik yang sama di INQ |
| 3 | **Payment handler tidak sanitasi `kode_bank_bifast`** | Hidden field `kode_bank_bifast` di confirm form bisa kosong/numerik | Tambah blok auto-map di payment handler sebelum validasi |
| 4 | **Preview DE048 di confirm box salah** | `buildDE048Payment()` pakai `kode_bank_de048` bahkan saat jenis=BIFAST | Ganti ke ternary: BIFAST→`kode_bank_bifast`, lainnya→`kode_bank_de048` |
| 5 | **Tidak ada UX warning saat user input numerik di KodeBIFAST field** | Field menerima angka tanpa feedback | Tambah event listener `input` → warning merah + auto-map JS dari `mapBIFAST` |

**`$mapKodeBankBIFAST` BCA entry** (konfirmasi default):
```
'014' => 'CENAIDJAXXX'   // BCA — nilai aktual dari kolom KodeBIFAST tabel bank_code
```
Jika production DB memiliki nilai berbeda, jalankan: `SELECT KodeBIFAST FROM bank_code WHERE Kode='014'` dan update map.

---

## Perubahan tf.php — v1.6.41 (sesi sebelumnya)

**Fitur BIFAST lengkap (INQBIFAST + PAYBIFAST):**

| # | Perubahan |
|---|-----------|
| 1 | `buildDE048()` — BIFAST prefix `0602`, format `INQBIFAST~~{KodeBIFAST}~~{nominal}` |
| 2 | `buildDE048Payment()` — BIFAST prefix `0602`, format `PAYBIFAST~~{KodeBIFAST}*0` |
| 3 | `buildISO8583Request()` + `buildISO8583PaymentRequest()` — routing ke `kode_bank_bifast` untuk BIFAST |
| 4 | `$mapKodeBankBIFAST` — 20+ bank mapping kode numerik BI → KodeBIFAST BIC |
| 5 | UI tab ke-4 "⚡ BI-FAST" (biru), field KodeBIFAST toggle, JS auto-fill |
| 6 | Payment confirm form: hidden field `kode_bank_bifast` |

---

## Perubahan tf.php — v1.6.40 (sesi sebelumnya)

**Production-ready cleanup + rcDescription():**

| # | Perubahan |
|---|-----------|
| 1 | Tambah `rcDescription()` — 60+ RC codes + 15 BI-FAST U-codes |
| 2 | UI error display inquiry+payment fail: RC badge, deskripsi, tip |
| 3 | Bersihkan semua label "MTI=200" → "MTI=002" (8 lokasi) |

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
- `PermataSNAP.mod.php` → `GetTFINQ()`, baris 264: `$cBeneficiaryBankCode = $vaDE48Tilde[1]` — nilai DE048 pos[1] langsung ke Permata SNAP
- `prosespermata.mod.php` → baris 70 comment: `INQBIFAST~~BMRIIDJA` — konfirmasi format BIC diperlukan
- Format ISO 8583: MTI=010 (inquiry), MTI=002 (payment), DE039 (RC), DE048 (tagihan/pesan), DE103 (kode bank numerik BI)

