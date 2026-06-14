# permatafix

Perbaikan file `tf.php` dan `mbanking.controller.php` — Inquiry & Payment Transfer Bank via Permata SNAP (SIS/Assist Switching Middleware).

## File

| File | Keterangan |
|------|------------|
| `tf.php` | Script PHP single-file untuk testing inquiry & payment transfer bank — **v1.6.45** (zero-pad kode bank numerik fix) |
| `mbanking.controller.php` | Controller mBanking — fix PHP Notice "Undefined index: KT" (line 111) |
| `mbanking_del.php` | Script PHP single-file deaktivasi user mBanking — MTI=009/KT=51, A-000300 |
| `hapusaktivasimbanking.php` | Script PHP single-file hapus aktivasi user mBanking — MTI=009/KT=51, warning banner + confirm 2 field |
| `aktivasimbanking.php` | Script PHP single-file aktivasi user mBanking (v1 lama) — MTI=009/KT=50, kirim PIN via SMS/Email |
| `aktivasimbanking2.php` | Script PHP single-file aktivasi user mBanking (v2 aktif) — MTI=009/KT=80, Kode Fasilitas + Email/SMS/WA |
| `qrvpn.php` | QR Code Scanner + Generator + VPN Login — 3 tab, jsQR kamera, qrcode.js generate, cURL login portal |
| `qrvpnlogin.php` | QR Scan VPN Login assist-pro.net — 4 tab: Scan QR (QRSCAN KT=02104), Generate QR Username VPN, VPN List (GETVPNLIST KT=02100), Polling Status (CEKVPN KT=04001) |

---

## qrvpnlogin.php — QR Scan VPN Login assist-pro.net (sesi 6)

Script PHP **single-file** integrasi langsung dengan `index_mobile.php` assist-pro.net.
Mengimplementasikan seluruh alur QR scan → VPN login sesuai source code `index_mobile.assistteam.php` dan `index_mobile.vpn.php`.

### Konfigurasi

```php
define('API_BASE_URL',  'https://app.assist-pro.net');  // URL assist-pro.net
define('API_ENDPOINT',  'index_mobile.php');             // relatif
define('CURL_TIMEOUT',  15);
```

### 4 Tab Fungsional

| Tab | Fungsi | Endpoint |
|-----|--------|----------|
| 📷 **Scan QR** | Kamera browser + upload gambar → decode QR → kirim QRSCAN | `MTI=02, KT=02104` |
| ⚡ **Generate QR** | Build QR berisi Username VPN (plain/JSON/custom) → download PNG | client-side |
| 🖥️ **VPN List** | Ambil daftar VPN by email | `MTI=02, KT=02100` |
| 🔄 **Polling Status** | Polling CEKVPN, konfirmasi connect, disconnect | `MTI=04, KT=04001/04003` |

### Alur QR VPN Login

```
1. Generate QR   → Tab Generate → isi Username VPN → QR tampil
2. Scan QR       → Tab Scan → kamera/upload → QRSCAN (KT=02104) → Status=3
3. Polling PC    → Tab Polling → Mulai Polling → CEKVPN (KT=04001)
4. Can Connect   → dapat DataVPN + URLSertifikat + URLRasphone
5. Konfirmasi    → EDITVPNSTATUS (KT=04003) → Status=1
6. Disconnect    → DISCONECTVPN (KT=02105) → Status=0
```

### Field Request/Response Utama

| KT | Nama | Request Fields | Response |
|----|------|---------------|----------|
| `02104` | QRSCAN | `Username, MacAddress, ClientID, APPVersion` | `Status:"1"/"0"`, `MSG:"ok"\|"Gagal,..."` |
| `04001` | CEKVPN | `MacAddress, ClientID` | `Data.MSG:"can connect"\|"sudah connect"\|"not found"`, `Data.DataVPN.URLSertifikat` |
| `02100` | GETVPNLIST | `EMAIL` | `Data:[{Username,Customer,Status,Block,...}]` |
| `04003` | EDITVPNSTATUS | `Username, MacAddress, ClientID, VPNVersion` | `Status:"1"`, `MSG:"ok"` |
| `02105` | DISCONECTVPN | `Username` | `Status:"1"`, `MSG:"ok"` |

### vpn_verify_list Status Flow

```
0 = Idle (belum ada request connect)
3 = Mobile sudah scan QR → menunggu PC
4 = PC sedang connecting (setelah CEKVPN terima Status=3)
1 = Fully connected (setelah EDITVPNSTATUS)
```

### CDN Libraries

- `jsQR@1.4.0` — QR decode kamera/gambar (client-side)
- `qrcode` — QR generate canvas (client-side)
- Tailwind CSS + Font Awesome

---

## qrvpn.php — QR Code Scanner & Generator VPN (sesi 5C)

Script PHP **single-file** dengan 3 tab fungsional untuk login VPN via QR Code.
Tidak memerlukan dependency server-side — semua QR processing dilakukan di browser.

### 3 Tab Utama

| Tab | Fungsi |
|-----|--------|
| 📷 **Scan QR** | Kamera real-time + upload gambar → decode → auto-detect tipe → aksi |
| ⚡ **Generate QR** | Form credential → build payload → render QR → download PNG |
| 🔑 **Login VPN** | Form manual + PHP cURL ke VPN portal → tampilkan response/token/cookie |

### Tab 1 — Scan QR

```
Browser getUserMedia → video stream → jsQR decode setiap 150ms
  ↓
Auto-deteksi tipe QR:
  • URL (https://)    → tombol "Buka URL" + "Pakai sbg VPN URL"
  • JSON Credential   → parse + tampilkan field + tombol "Auto Login"
  • token=...         → ekstrak token + URL → tombol "Login dengan Token"
  • vpn://...         → VPN scheme custom → tombol "Login VPN"
  • Plain text        → copy + coba sebagai URL
  ↓
Riwayat scan → localStorage (10 entri, klik untuk re-display)
Fallback: upload gambar PNG/JPG → jsQR decode client-side (tidak ke server)
```

**Format JSON credential yang dikenali:**
```json
{ "vpn": "https://vpn.company.com", "u": "username", "p": "password", "t": "token" }
```

### Tab 2 — Generate QR

| Mode | Payload yang di-encode |
|------|------------------------|
| 🔗 URL | URL langsung: `https://vpn.company.com/login` |
| 👤 Credential | JSON: `{"vpn":"...","u":"...","p":"...","t":"..."}` |
| 🎫 Token | JSON: `{"vpn":"...","token":"eyJ..."}` |
| ✏️ Custom | Teks/data bebas apapun |

- Ukuran QR: 200 / 300 / 400 / 512 px
- Error correction: L (7%) / M (15%) / Q (25%) / H (30%)
- Download PNG langsung dari browser
- Tombol **→ Login**: kirim credential ke Tab Login tanpa scan ulang

### Tab 3 — Login VPN (PHP backend)

```
PHP cURL → POST ke VPN portal URL
  ↓
3 metode auth:
  • Form POST   → username + password + token sebagai form data
  • Bearer Token → Authorization: Bearer {token} header
  • Basic Auth   → CURLOPT_USERPWD username:password
  ↓
Parse response:
  • HTTP 301/302 → deteksi redirect URL (dashboard)
  • JSON body    → ekstrak token/access_token dari response
  • Set-Cookie   → tampilkan session cookie
  • Body preview → 800 char pertama
```

**Deteksi sukses otomatis** (heuristic):
- HTTP 302/301 redirect
- Body mengandung kata: `dashboard`, `welcome`, `logout`, `success`

### Library Client-side

| Library | CDN | Fungsi |
|---------|-----|--------|
| `jsQR 1.4.0` | `cdn.jsdelivr.net/npm/jsqr@1.4.0` | Decode QR dari ImageData (kamera/gambar) |
| `qrcode` | `cdn.jsdelivr.net/npm/qrcode` | Generate QR ke Canvas/PNG |
| `Tailwind CSS` | `cdn.tailwindcss.com` | Styling |

### Konfigurasi (atas file)

```php
define('VPN_PORTAL_URL',     'https://vpn.company.com/login');  // URL default
define('VPN_TOKEN_ENDPOINT', 'https://vpn.company.com/api/auth'); // endpoint token
define('VPN_DEFAULT_USER',   'admin');   // pre-fill username
define('APP_TITLE',          'QR VPN'); // judul halaman
```

### Penggunaan

1. Upload `qrvpn.php` ke web server (HTTPS **wajib** untuk akses kamera)
2. Buka `https://server/qrvpn.php`
3. **Scan**: klik Mulai Scan → arahkan kamera ke QR Code → hasil muncul otomatis
4. **Generate**: pilih mode Credential → isi URL + user + pass → QR muncul real-time → Download
5. **Login**: isi form atau auto-fill dari scan → klik Login → lihat response

> **Catatan HTTPS**: `getUserMedia` (kamera) hanya bekerja di HTTPS atau `localhost`.
> Di HTTP, tab Scan tidak bisa mengakses kamera — gunakan fallback Upload Gambar.

---

## aktivasimbanking.php — Aktivasi User mBanking KT=50 (sesi 5C)

Script PHP **single-file** untuk mengaktivasi user mBanking via CBS — jalur lama KT=50.
CBS akan generate PIN 6 digit dan mengirimkan ke user via SMS atau Email.

### Perbedaan aktivasimbanking vs aktivasimbanking2

| Aspek | `aktivasimbanking.php` (KT=50) | `aktivasimbanking2.php` (KT=80) |
|-------|-------------------------------|----------------------------------|
| CBS function | `RegisterDigital()` | `RegisterDigital_v2()` |
| KT | 50 (TRX_MB_AKTIVASI_FROM_CORE) — **Kode Lama** | 80 (TRX_MB_AKTIVASI_FROM_CORE_V2) — **Aktif** |
| Output kode | PIN 6 digit plaintext di response | Kode Fasilitas 6 digit (terenkripsi, disimpan ke DB) |
| Kanal kirim | SMS / Email | Email (#E) / SMS Masking (#S) / SMS GW (#SL) / WA Blast (#W) |
| Field tambahan | `EMAIL` opsional | `EMAIL`, `EMAILCBS`, `CIF`, `WABLAST`, `OTP` |
| Cek duplikasi | HP + Agen | (HP **atau** CIF) + Agen |
| Warna UI | Green (`#16a34a`) | Blue (`#2563eb`) |
| Cache token | `aktivasi_mbanking_token.cache` | `aktivasi_mbanking2_token.cache` |

### Flow Teknis — aktivasimbanking.php (KT=50)

```
aktivasimbanking.php
  Step 1 → GET OAuth token (http://myassist.sis1.net/assist-auth_api/...)
            Cache ke storage/cds/cache/aktivasi_mbanking_token.cache
  Step 2 → Bangun ISO 8583 request:
            MTI  = 009           (DIGITAL_BANK_REGISTER)
            KT   = 50            (TRX_MB_AKTIVASI_FROM_CORE)
            AGEN = A-000300
            MSG  = CS#{faktur}#mBankingREG#{noHP}#{kodeAgen}
            + EMAIL (opsional)
  Step 3 → POST ke http://switching.mcoll.sis1.net/.../mobile-digital
  Step 4 → Parse response CBS:
            AR#{faktur}#SUKSES#Tunggu SMS pemberitahuan selanjutnya#{encPIN}#{pin}  ✅
            AR#{faktur}#SUKSES#Tunggu Email pemberitahuan selanjutnya#{encPIN}#{pin} ✅
            AR#{faktur}#GAGAL#Nomor sudah diaktivasi sebelumnya                     ❌

CBS handler (mbanking.controller.php):
  MTI=009 + KT=50 → RegisterDigital()
    → Cek agen_aktifasi WHERE HP + Agen + mBankingToken != ''
    → Jika belum ada → generate PIN rand(100000, 999999)
    → Jika EMAIL diisi → INSERT notifemail_sent (kirim via Email)
    → Jika tidak → INSERT sms_gateway_outbox (kirim PIN via SMS)
    → UPDATE agen_aktifasi SET HP, Agen, DateTime, Email
    → Response: AR#...#SUKSES#...#{encryptedPIN}#{pinPlaintext}
```

### Flow Teknis — aktivasimbanking2.php (KT=80)

```
aktivasimbanking2.php
  Step 1 → GET OAuth token (cache ke aktivasi_mbanking2_token.cache)
  Step 2 → Bangun ISO 8583 request:
            MTI  = 009           (DIGITAL_BANK_REGISTER)
            KT   = 80            (TRX_MB_AKTIVASI_FROM_CORE_V2)
            AGEN = A-000300
            MSG  = CS#{faktur}#mBankingREG#{noHP}#{kodeAgen}
            + EMAIL, EMAILCBS, CIF, WABLAST=1, OTP (opsional)
  Step 3 → POST ke http://switching.mcoll.sis1.net/.../mobile-digital
  Step 4 → Parse response CBS:
            AR#{faktur}#SUKSES#E   → Kode Fasilitas dikirim via Email   ✅
            AR#{faktur}#SUKSES#S   → Kode Fasilitas dikirim via SMS Masking ✅
            AR#{faktur}#SUKSES#SL  → Kode Fasilitas dikirim via SMS GW fallback ✅
            AR#{faktur}#SUKSES#W   → Kode Fasilitas dikirim via WA Blast ✅
            AR#{faktur}#GAGAL# Nomor atau CIF sudah diaktivasi sebelumnya ❌

CBS handler (mbanking.controller.php):
  MTI=009 + KT=80 → RegisterDigital_v2()
    → Cek agen_aktifasi WHERE (HP OR KodeCIF) + Agen
    → Jika belum ada → generate KodeFasilitas rand(100000,999999) → encryptIt()
    → Kirim via Email / WA Blast / SMS Masking / SMS GW (fallback)
    → UPDATE agen_aktifasi SET HP, Agen, DateTime, Email, mBankingKodeFasilitas, KodeCIF, DateTimeOTP
```

### Konfigurasi A-000300

| Konstanta | Nilai | Sumber |
|-----------|-------|--------|
| `OAUTH_CLIENT_ID` | `000087` | `assist-bpr.net/env/` (auth_client_id) |
| `OAUTH_CLIENT_SECRET` | `274FrdhikpazQXdLtv5kNoRucN7SlQPq` | `assist-bpr.net/env/` |
| `OAUTH_CORPORATE_ID` | `553231` | `assist-bpr.net/env/` |
| `OAUTH_SERTIFIKAT` | `d9ebe47971b415daadc3440ee4070aea` | config.sql (msAuth_SERTIFIKAT_API) |
| `OAUTH_KODE_APLIKASI` | `BPRPAS` | config.sql |
| `OAUTH_USERNAME` | `A-000300` | config.sql (msKodeH2H) |
| `DE061_SIM_SERIAL` | `babba586c65c1a8119cfe6a6dae9972f` | config.sql (msCDSID) |
| `KODE_AGEN` | `A-000300` | — |

### Penggunaan

**aktivasimbanking.php (KT=50):**
1. Upload ke web server, buka `http://server/aktivasimbanking.php`
2. Isi Nomor HP + Kode Agen + Email (opsional)
3. Klik **Aktivasi mBanking** → konfirmasi JS
4. Step 4: PIN awal user ditampilkan jika ada di response (bagian ke-6 dari AR#)

**aktivasimbanking2.php (KT=80):**
1. Upload ke web server, buka `http://server/aktivasimbanking2.php`
2. Isi Nomor HP + Kode Agen
3. Pilih kanal: Email / SMS (default) / WA Blast (centang + isi OTP)
4. Isi CIF nasabah jika diperlukan cek duplikasi by CIF
5. Klik **Aktivasi mBanking v2** → konfirmasi JS
6. Step 4: kanal kirim ditampilkan (E/S/SL/W)
7. User menyampaikan Kode Fasilitas ke CS untuk validasi KT=81

### Struktur File

```php
aktivasimbanking.php
  ├── KONFIGURASI (define A-000300)
  ├── generateFaktur()         — faktur unik
  ├── getAccessToken()         — OAuth + cache (identik mbanking_del.php v42d36d3)
  ├── aktivasiMBanking()      — bangun ISO KT=50 + POST + parse AR#
  ├── HANDLE POST REQUEST      — validasi + call aktivasiMBanking()
  └── HTML OUTPUT              — form + 4-step + PIN display (Tailwind, Green)

aktivasimbanking2.php
  ├── KONFIGURASI (define A-000300) + KANAL_MAP
  ├── generateFaktur()
  ├── getAccessToken()         — identik
  ├── aktivasiMBanking2()     — bangun ISO KT=80 + field tambahan + POST + parse
  ├── HANDLE POST REQUEST      — validasi + call aktivasiMBanking2()
  └── HTML OUTPUT              — form (dengan WA toggle) + 4-step + kanal display (Tailwind, Blue)
```

---

## hapusaktivasimbanking.php — Hapus Aktivasi User mBanking (sesi 5C)

Script PHP **single-file** untuk menghapus aktivasi user mBanking via CBS.
Menggunakan jalur ISO 8583 MTI=009/KT=51 — identik dengan `mbanking_del.php` namun
dengan UI berbeda: warning banner permanen + konfirmasi 2-field (HP + Kode Agen).

### Perbedaan dengan mbanking_del.php

| Aspek | `mbanking_del.php` | `hapusaktivasimbanking.php` |
|-------|--------------------|-----------------------------|
| Fungsi utama | `sendMBankingDEL()` | `hapusAktivasiMBanking()` |
| Cache token | `mbanking_del_token.cache` | `hapus_aktifasi_token.cache` |
| Warna UI | Indigo (`#6366f1`) | Red (`#ef4444`) |
| Confirm dialog | HP saja | HP + Kode Agen |
| Warning banner | Tidak ada | ⚠️ Amber — peringatan aksi permanen |

### Flow Teknis

```
hapusaktivasimbanking.php
  Step 1 → GET OAuth token (http://myassist.sis1.net/assist-auth_api/...)
            Cache ke storage/cds/cache/hapus_aktifasi_token.cache
  Step 2 → Bangun ISO 8583 request:
            MTI  = 009           (DIGITAL_BANK_REGISTER)
            KT   = 51            (TRX_MB_DEAKTIVASI_FROM_CORE)
            AGEN = A-000300
            MSG  = CS#{faktur}#mBankingDEL#{noHP}#{kodeAgen}
  Step 3 → POST ke http://switching.mcoll.sis1.net/.../mobile-digital
            Authorization: Bearer {access_token}
            cCode = JSON ISO request
  Step 4 → Parse response CBS:
            AR#{faktur}#SUKSES#User telah dinonaktifkan  ✅
            AR#{faktur}#GAGAL#{pesan error}              ❌

CBS handler (mbanking.controller.php):
  MTI=009 + KT=51 → Unregister()
    → SELECT agen_aktifasi WHERE HP + Agen
    → DELETE agen_smsbanking WHERE Nomor=HP AND Agen
    → DELETE agen_aktifasi   WHERE HP=KodeNegara(HP) AND Agen
```

### Konfigurasi A-000300

| Konstanta | Nilai | Sumber |
|-----------|-------|--------|
| `OAUTH_CLIENT_ID` | `000087` | `assist-bpr.net/env/` (auth_client_id) |
| `OAUTH_CLIENT_SECRET` | `274FrdhikpazQXdLtv5kNoRucN7SlQPq` | `assist-bpr.net/env/` |
| `OAUTH_CORPORATE_ID` | `553231` | `assist-bpr.net/env/` |
| `OAUTH_SERTIFIKAT` | `d9ebe47971b415daadc3440ee4070aea` | config.sql (msAuth_SERTIFIKAT_API) |
| `OAUTH_KODE_APLIKASI` | `BPRPAS` | config.sql |
| `OAUTH_USERNAME` | `A-000300` | config.sql (msKodeH2H) |
| `DE061_SIM_SERIAL` | `babba586c65c1a8119cfe6a6dae9972f` | config.sql (msCDSID) |
| `KODE_AGEN` | `A-000300` | — |
| `TOKEN_CACHE_FILE` | `storage/cds/cache/hapus_aktifasi_token.cache` | Terpisah dari mbanking_del.php |

> **Catatan `OAUTH_PASSWORD`**: Masih kosong string — isi dari tabel `agen` kolom password H2H jika OAuth server memerlukannya.

### Penggunaan

1. Upload `hapusaktivasimbanking.php` ke web server (direktori yang sama dengan `mbanking_del.php`)
2. Buka di browser: `http://server/hapusaktivasimbanking.php`
3. Baca **warning banner merah** — aksi ini **permanen** (tidak bisa di-undo)
4. Isi **Nomor HP** (format `08xxxxxxxxxx`) + **Kode Agen** (default A-000300)
5. Klik **Hapus Aktivasi mBanking** → konfirmasi JS tampilkan HP + Kode Agen → kirim ke CBS
6. Lihat hasil 4-step debug:
   - Step 1: status token (cache / baru)
   - Step 2: ISO request (MTI/KT/MSG)
   - Step 3: HTTP POST ke CBS (kode + elapsed ms)
   - Step 4: response CBS (SUKSES/GAGAL + raw JSON)

### Struktur File

```php
hapusaktivasimbanking.php
  ├── KONFIGURASI (define A-000300)
  ├── generateFaktur()            — nomor faktur unik timestamp+random
  ├── getAccessToken()            — OAuth + file cache (robust: BOM strip, PascalCase, error diagnostik)
  ├── hapusAktivasiMBanking()    — bangun ISO + POST + parse response
  ├── HANDLE POST REQUEST         — validasi form + call hapusAktivasiMBanking()
  └── HTML OUTPUT                 — warning banner + form + 4-step result display (Tailwind CSS)
```

### getAccessToken() — Token Extraction Chain (identik mbanking_del.php v42d36d3)

```php
// 6 path — support semua format OAuth server:
$token = $resp['data']['access_token']   // Format A (lowercase standar)
      ?? $resp['access_token']            // Format A (flat)
      ?? $resp['Data']['AccessToken']     // Format B (PascalCase — myassist.sis1.net)
      ?? $resp['Data']['Token']           // Format B (alt key)
      ?? $resp['data']['Token']           // Format C (mixed)
      ?? $resp['Token'];                  // Format C (flat)

// LifeTime dalam menit (myassist) vs detik (standar):
// Jika expiresRaw ≤ 1440 → dianggap menit → × 60
```

---

## mbanking_del.php — Deaktivasi User mBanking (sesi 5B)

Script PHP **single-file** untuk menonaktifkan user mBanking via CBS.
Menggunakan jalur ISO 8583 MTI=009/KT=51 ke `assist-switching_v3_pro`.

### Flow Teknis

```
mbanking_del.php
  Step 1 → GET OAuth token (http://myassist.sis1.net/assist-auth_api/...)
            Token di-cache ke storage/cds/cache/mbanking_del_token.cache
  Step 2 → Bangun ISO 8583 request:
            MTI  = 009           (DIGITAL_BANK_REGISTER)
            KT   = 51            (TRX_MB_DEAKTIVASI_FROM_CORE)
            AGEN = A-000300
            MSG  = CS#{faktur}#mBankingDEL#{noHP}#{kodeAgen}
  Step 3 → POST ke http://switching.mcoll.sis1.net/.../mobile-digital
            Authorization: Bearer {access_token}
            cCode = JSON ISO request
  Step 4 → Parse response CBS:
            AR#{faktur}#SUKSES#User telah dinonaktifkan  ✅
            AR#{faktur}#GAGAL#{pesan error}              ❌

CBS handler (mbanking.controller.php):
  MTI=009 + KT=51 → Unregister()
    → DELETE agen_smsbanking WHERE HP = {noHP} AND KodeAgen = {agen}
    → DELETE agen_aktifasi   WHERE HP = {noHP} AND KodeAgen = {agen}
```

### Konfigurasi A-000300

| Konstanta | Nilai | Sumber |
|-----------|-------|--------|
| `OAUTH_CLIENT_ID` | `000087` | `assist-bpr.net/env/` (auth_client_id) |
| `OAUTH_CLIENT_SECRET` | `274FrdhikpazQXdLtv5kNoRucN7SlQPq` | `assist-bpr.net/env/` |
| `OAUTH_CORPORATE_ID` | `553231` | `assist-bpr.net/env/` |
| `OAUTH_SERTIFIKAT` | `d9ebe47971b415daadc3440ee4070aea` | `config.sql` (msAuth_SERTIFIKAT_API) |
| `OAUTH_KODE_APLIKASI` | `BPRPAS` | config.sql |
| `OAUTH_USERNAME` | `A-000300` | config.sql (msKodeH2H) |
| `DE061_SIM_SERIAL` | `babba586c65c1a8119cfe6a6dae9972f` | config.sql (msCDSID) |
| `KODE_AGEN` | `A-000300` | — |

> **Catatan `OAUTH_PASSWORD`**: Masih kosong string — perlu diisi dari tabel `agen` kolom password H2H jika OAuth server memerlukannya.

### Penggunaan

1. Upload `mbanking_del.php` ke web server (direktori yang sama dengan `tf.php`)
2. Buka di browser: `http://server/mbanking_del.php`
3. Isi **Nomor HP** (format `08xxxxxxxxxx`) + **Kode Agen** (default sudah A-000300)
4. Klik **Nonaktifkan User mBanking** → konfirmasi JS → kirim ke CBS
5. Lihat hasil 4-step debug:
   - Step 1: status token (cache / baru)
   - Step 2: ISO request (MTI/KT/MSG)
   - Step 3: HTTP POST ke CBS (kode + elapsed ms)
   - Step 4: response CBS (SUKSES/GAGAL + raw JSON)

### Struktur File

```php
mbanking_del.php
  ├── KONFIGURASI (define A-000300)
  ├── getAccessToken()     — OAuth + file cache
  ├── sendMBankingDEL()   — bangun ISO + POST + parse
  ├── HANDLE POST REQUEST  — validasi form + call sendMBankingDEL()
  └── HTML OUTPUT          — form + 4-step result display (Tailwind CSS)
```

---

## Perubahan mbanking.controller.php (sesi 5B)

**Bug**: PHP Notice `Undefined index: KT` di line 108 — terjadi pada setiap request ISO 8583 yang tidak menyertakan key `KT` di root level request.

| # | Bug | Root Cause | Fix |
|---|-----|------------|-----|
| 1 | **PHP Notice "Undefined index: KT"** di line 108 | `$cKT = $vaRequest['KT']` tanpa `isset()` guard — jika key `KT` tidak ada di array, PHP throw Notice | Ganti ke: `$cKT = isset($vaRequest['KT']) ? $vaRequest['KT'] : '';` |

```php
// Sebelum (production — menyebabkan Notice di log):
$cKT = $vaRequest['KT'] ;

// Sesudah (fix — line 111):
$cKT = isset($vaRequest['KT']) ? $vaRequest['KT'] : '';  // fix: isset() guard, cegah PHP Notice Undefined index
```

> **Catatan**: Fix ini hanya menghilangkan PHP Notice dari log. Tidak mempengaruhi alur transaksi karena `$lWeird` di-force `false` di line 121.

---

## Perubahan tf.php — v1.6.45 (sesi 5B — kode bank numerik zero-pad fix)

**Root cause dari debug live test**: DE103=`"01"` dan DE048=`"0602*1001*INQBIFAST~~01~~500329"` — kode numerik BI 2-digit tidak ditemukan di `$mapKodeBankBIFAST` (map hanya berisi key 3-digit: `"011"`, `"014"`, dll.).

| # | Bug | Root Cause | Fix |
|---|-----|------------|-----|
| 1 | **DE048 BIFAST berisi `"~~01~~"`** — kode tidak ter-map ke BIC | `"01"` (2 digit) tidak ada di `$mapKodeBankBIFAST`; map hanya punya `"011"` (Danamon), `"014"` (BCA) | Tambah **zero-pad** sebelum lookup: `str_pad(kode, 3, '0', STR_PAD_LEFT)` di 4 titik |
| 2 | **DE103 = `"01"`** dikirim ke CBS | `kode_bank` dari POST tidak di-normalize sebelum diteruskan ke ISO | Normalize `kode_bank` ke 3 digit di handler inquiry + payment |

**Zero-pad diterapkan di 4 titik:**
```
1. Handler POST inquiry   — kode_bank normalize sebelum validasi
2. Handler POST payment   — kode_bank normalize sebelum validasi  
3. buildISO8583Request()  — guard numerik auto-map BIFAST
4. buildISO8583PaymentRequest() — guard numerik auto-map BIFAST
```

**Contoh efek fix:**
```
User input kode bank: "01"  → normalize → "011" (Danamon) → map → "BDINIDJA"
User input kode bank: "14"  → normalize → "014" (BCA)     → map → "CENAIDJA"
User input kode bank: "8"   → normalize → "008" (Mandiri)  → map → "BMRIIDJA"
User input kode bank: "014" → sudah 3 digit, map langsung  → "CENAIDJA"
```

**Temuan lain dari debug yang TIDAK difix di tf.php (masalah di server):**
- `Notice: Undefined index: KT` di `mbanking.controller.php` line 108 — fix sudah ada di repo (`092c278`) tapi **belum di-deploy ke production**
- `Notice: Undefined index: URL` di `mbanking.controller.php` line 782 — bug tambahan di server, belum dianalisis

---

## Perubahan tf.php — v1.6.44 (sesi 5B — Danamon SNAP fallback)

### Latar Belakang

Setelah v1.6.43, DE048 sudah benar (`PAYBIFAST~~CENAIDJA*0` ✅), namun CBS masih mengembalikan **DE039=03** karena `agen_fitur.PermataSNAPTF` untuk A-000268 kemungkinan belum bernilai `'1'`.

**Temuan kritis dari analisis source code CBS:**
- ISO 8583 request untuk PAYBIFAST **identik** antara jalur Permata dan Danamon
- Routing provider (Permata vs Danamon) ditentukan sepenuhnya oleh `agen_fitur.PermataSNAPTF / DanamonSNAPTF` di **CBS layer** — bukan oleh isi ISO payload
- `SNAPDanamon.mod.php` hanya memiliki `inquiry()` + `receiver()` — **tidak ada payment endpoint**
- `DanamonDispatcher.mod.php` line 22: `return ['status'=>'fallback']` (circuit breaker placeholder, selalu fallback ke jalur API lama)

**Implikasi arsitektur**: tf.php tidak bisa mem-"force" routing ke Danamon secara langsung. Yang bisa dilakukan adalah:
1. Tampilkan pilihan provider di UI (Permata/Danamon)
2. Teruskan nilai `snap_provider` ke debug output + tips
3. Berikan SQL guidance yang relevan berdasarkan provider yang dipilih pengguna

### Perubahan v1.6.44

| # | Perubahan | File/Fungsi |
|---|-----------|-------------|
| 1 | **Docblock header** — tambah keterangan BIFAST dual-provider (Permata default, Danamon fallback) | `tf.php` header |
| 2 | **Inquiry POST handler** — tambah field `snap_provider` ke `$formData` | `// HANDLE POST REQUEST` |
| 3 | **Payment POST handler** — tambah field `snap_provider` ke `$paymentFormData` | Payment handler |
| 4 | **`paymentTransferBank()`** — tambah `$snapProvider` variable + key `snap_provider` di return array | `paymentTransferBank()` |
| 5 | **`buildISO8583PaymentRequest()`** — tambah `_snap_provider` di return (info debug saja, tidak masuk ISO) | `buildISO8583PaymentRequest()` |
| 6 | **UI Form** — radio buttons SNAP Provider (Permata/Danamon) + warning Danamon kondisional | HTML form, `#fieldSNAPProviderWrap` |
| 7 | **Confirm box** — baris "SNAP Provider" + warning Danamon jika dipilih | Konfirmasi sebelum bayar |
| 8 | **Hidden field payment form** — `<input type="hidden" name="snap_provider">` | Payment submit form |
| 9 | **Payment result success** — baris "SNAP Provider" di detail hasil transaksi | Result display |
| 10 | **RC=03 tip context-aware** — tip berbeda berdasarkan `snap_provider` + `jenis_transfer` | `rcDescription()` / RC tip section |
| 11 | **JS `handleJenisTransferChange()`** — toggle `#fieldSNAPProviderWrap` saat jenis TF berubah | JavaScript |
| 12 | **JS IIFE `updateDanamonWarning()`** — toggle warning + border Danamon saat radio berubah | JavaScript |

### Cara Kerja UI Dual-Provider

```
Form BIFAST:
  ┌─────────────────────────────────────────────────────┐
  │ ⚡ SNAP Provider (Jalur Payment)                     │
  │  ○ 🏦 Permata SNAP (default)                        │
  │  ○ 🏦 Danamon SNAP (fallback jika Permata gagal)    │
  │                                                     │
  │ [Jika Danamon dipilih, muncul warning:]             │
  │ ⚠️ Danamon SNAP dipilih. Pastikan                   │
  │    agen_fitur.DanamonSNAPTF = '1' di CBS            │
  │    SQL: UPDATE agen_fitur SET DanamonSNAPTF='1'     │
  │         WHERE KodeAgen='A-000268';                  │
  └─────────────────────────────────────────────────────┘
```

**Alur teknis saat memilih Danamon:**
```
User pilih "Danamon SNAP" di UI
  → snap_provider = 'danamon' di-POST ke payment handler
  → paymentTransferBank() menerima $snapProvider = 'danamon'
  → buildISO8583PaymentRequest() menyertakan _snap_provider = 'danamon' (debug saja)
  → ISO 8583 dikirim ke CBS (payload IDENTIK dengan Permata)
  → CBS membaca agen_fitur.DanamonSNAPTF untuk routing
  → Jika DanamonSNAPTF='1': CBS route ke Danamon SNAP API ✅
  → Jika DanamonSNAPTF≠'1': CBS kembalikan DE039=03 + tip SQL DanamonSNAPTF
```

### RC=03 Context-Aware Tips (v1.6.44)

| Kondisi | Tip yang Ditampilkan |
|---------|---------------------|
| BIFAST + Permata SNAP + RC=03 | Cek `PermataSNAPTF='1'` di `agen_fitur` + SQL fix + saran beralih ke Danamon |
| BIFAST + Danamon SNAP + RC=03 | Cek `DanamonSNAPTF='1'` di `agen_fitur` + SQL fix |
| Non-BIFAST + RC=03 | Tip generik "Invalid Merchant/Expired" |

### SQL untuk Danamon SNAP

```sql
-- Aktifkan Danamon SNAP untuk agen:
UPDATE agen_fitur SET DanamonSNAPTF = '1' WHERE KodeAgen = 'A-000268';

-- Cek status kedua provider sekaligus:
SELECT KodeAgen, PermataSNAPTF, DanamonSNAPTF
FROM agen_fitur
WHERE KodeAgen = 'A-000268';
```

---

## Perubahan tf.php — v1.6.43 (sesi 5B — PHP execution order fix)

**Root cause**: `$mapKodeBankBIFAST` dideklarasikan di line ~1739 (seksi DATA REFERENSI), sedangkan payment handler di line ~1620 sudah menggunakannya. PHP procedural: variabel belum ada saat auto-map dijalankan → auto-map gagal diam-diam → `"014"` tetap lolos ke DE048.

| # | Bug | Root Cause | Fix |
|---|-----|------------|-----|
| 1 | **Auto-map kode bank tetap gagal** meski guard numerik sudah ada | `$mapKodeBankBIFAST` dideklarasikan setelah handler POST yang menggunakannya (PHP execution order) | Pindah deklarasi `$mapKodeBankBIFAST` ke line ~1547, **sebelum** blok `// HANDLE POST REQUEST` |

**Urutan eksekusi sebelum fix (salah):**
```
line ~1546: // HANDLE POST REQUEST
line ~1620: payment handler → if (empty($bifastVal) || preg_match('/^\d+$/', $bifastVal))
              → $paymentFormData['kode_bank_bifast'] = $mapKodeBankBIFAST[$numericKey]
              → ❌ $mapKodeBankBIFAST belum ada → PHP undefined variable → auto-map gagal
line ~1739: $mapKodeBankBIFAST = ['008' => 'BMRIIDJA', '014' => 'CENAIDJA', ...] ← terlambat!
```

**Urutan eksekusi sesudah fix (benar):**
```
line ~1547: $mapKodeBankBIFAST = ['008' => 'BMRIIDJA', '014' => 'CENAIDJA', ...] ← dipindah ke sini
line ~1584: // HANDLE POST REQUEST
line ~1620: payment handler → auto-map berhasil ✅
```

**Konfirmasi live test setelah v1.6.43 di-deploy:**
```
DE048: "0602*1001*PAYBIFAST~~CENAIDJA*0"  ✅ BENAR
DE039: "03"  ← dari CBS/Permata SNAP (bukan tf.php)
```

---

## Perubahan tf.php — v1.6.42 (commit 4c51f3c — BIC8 fix)

**BCA KodeBIFAST dikonfirmasi dari production DB**: nilai di kolom `KodeBIFAST` adalah `CENAIDJA` (BIC8), **bukan** `CENAIDJAXXX` (BIC11).

- Update `$mapKodeBankBIFAST['014']`: `'CENAIDJAXXX'` → `'CENAIDJA'`
- Update 8 referensi di docblock, komentar, dan placeholder

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

---

## Diagnosis DE039=03 (sesi 5B — masih aktif)

**Status**: DE048 tf.php sudah benar (`PAYBIFAST~~CENAIDJA*0` ✅). DE039=03 berasal dari CBS/Permata SNAP, bukan dari tf.php.

**Trace flow MTI=002 DE003=211041 (PAYBIFAST):**
```
tf.php → ISO 8583 (MTI=002, DE048=PAYBIFAST~~CENAIDJA*0)
  → mbanking.controller.php: ReadRequest() → mBanking()
  → mBanking() appends margin: DE048 jadi PAYBIFAST~~CENAIDJA*0*0
  → SendHTTPPost($cURL) → CBS agen URL
  → CBS processes PAYBIFAST
  → CBS → Permata SNAP API
  → Permata SNAP returns DE039=03 (Invalid Merchant)
```

**3 Kemungkinan root cause:**

| # | Penyebab | Probabilitas | Diagnosis SQL |
|---|----------|-------------|---------------|
| 1 | **A-000268 tidak ada di `agen_fitur` atau `PermataSNAPTF ≠ '1'`** | 90% | `SELECT KodeAgen, PermataSNAPTF FROM agen_fitur WHERE KodeAgen = 'A-000268'` |
| 2 | `bank_code.KodeBIFAST` untuk Kode=014 kosong/salah | 7% | `SELECT Kode, KodeBIFAST FROM bank_code WHERE Kode = '014'` |
| 3 | Rekening 5465389271 restricted untuk BI-FAST di BCA | 3% | Test dengan rekening BCA lain |

**SQL diagnosis (jalankan di production DB):**
```sql
-- Query 1: Cek konfigurasi agen
SELECT KodeAgen, PermataSNAPTF, DanamonSNAPTF, KodeBankTFOB
FROM agen_fitur
WHERE KodeAgen = 'A-000268';

-- Query 2: Cek KodeBIFAST BCA
SELECT Kode, Nama, KodeBIFAST, BankPermataID, TransferOnline
FROM bank_code
WHERE Kode = '014';
```

**Fix berdasarkan hasil Query 1:**
```sql
-- Jika row tidak ada:
INSERT INTO agen_fitur (KodeAgen, PermataSNAPTF, DanamonSNAPTF, KodeBankTFOB)
VALUES ('A-000268', '1', '0', '');

-- Jika row ada tapi PermataSNAPTF ≠ '1':
UPDATE agen_fitur SET PermataSNAPTF = '1' WHERE KodeAgen = 'A-000268';
```

---

## Referensi

- `mbanking.controller.php` → `ProsesInquiryPayment()`, `GetInquiryHistoryCBS()`, `mBanking()`
- `PermataSNAP.mod.php` → `GetTFINQ()`, baris 264: `$cBeneficiaryBankCode = $vaDE48Tilde[1]` — nilai DE048 pos[1] langsung ke Permata SNAP
- `prosespermata.mod.php` → baris 70 comment: `INQBIFAST~~BMRIIDJA` — konfirmasi format BIC diperlukan
- `MBankingFunc.mod.php` → `GetHargaPPOB()` baris 69: TRX_PASCA_2 tidak parse DE048 → `$cKodeProduk` = full string → margin=0
- Format ISO 8583: MTI=010 (inquiry), MTI=002 (payment), DE039 (RC), DE048 (tagihan/pesan), DE103 (kode bank numerik BI)
- `agen_fitur.PermataSNAPTF = "1"` → wajib ada agar routing BIFAST ke Permata SNAP aktif

