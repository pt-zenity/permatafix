<?php
/**
 * Inquiry Transfer Bank via Permata (SIS/Assist Switching Middleware)
 * =====================================================================
 * Script single-file PHP untuk melakukan inquiry transfer bank melalui
 * jalur SIS/Assist switching middleware (assist-switching_v3_pro).
 *
 * Mendukung jenis transfer:
 *   - TFDANA  (Transfer Dana Online / SKN Real-Time)
 *   - LLG     (Lalu Lintas Giro / SKN Batch)
 *   - RTGS    (Real-Time Gross Settlement)
 *
 * AUTO-DETECTION dari source code assist-switching_v3_pro & assist-bpr.net:
 *   ✅ URL_GET_TOKEN, URL_DIGITAL, MFTFI → dari config/local_config.php
 *   ✅ KODE_AGEN, MFTFI TF  → dari nama file storage/cds/cache/*snap_tf_bank_permata.cache
 *   ✅ DE061_SIM_SERIAL     → dari storage/cds/cache/ (nama file cache terakhir)
 *   ✅ OAuth credentials (auto-detect, urutan prioritas):
 *      1. File .assist.env (format KEY=VALUE):
 *           OAUTH_CLIENT_ID=xxxx
 *           OAUTH_CLIENT_SECRET=xxxx
 *           OAUTH_USERNAME=xxxx       ← UserH2H di tabel agen
 *           OAUTH_PASSWORD=xxxx
 *           DE061_SIM_SERIAL=xxxx     ← opsional, override auto-detect
 *      2. assist-bpr.net/env/{kode}_.env (format key = value, spasi sekitar =):
 *           auth_client_id = xxxx     → OAUTH_CLIENT_ID
 *           auth_client_secret = xxxx → OAUTH_CLIENT_SECRET
 *           username = Assist         → OAUTH_USERNAME (default H2H)
 *           password = Irac           → OAUTH_PASSWORD (default H2H)
 *           auth_server_uri = https://one.myassist.id
 *
 * Alur transaksi (sesuai source code mbanking.controller.php):
 *   1. Ambil OAuth access token dari myassist.sis1.net
 *   2. Bangun pesan ISO 8583-like JSON (MTI="010", MSG dengan DE fields)
 *   3. Kirim POST ke switching.mcoll.sis1.net/.../mobile-digital (proxy v3)
 *      dengan header: Authorization: Bearer {accessToken}
 *   4. Response di-parse dari ISO 8583 array
 *
 * Referensi: assist-switching_v3_pro v1.6.42 "Assist Pro Net"
 *   - config/local_config.php
 *   - mvc/mbanking/mbanking.controller.php → ProsesInquiryPayment()
 *   - storage/cds/cache/*snap_tf_bank_permata.cache → KODE_AGEN + MFTFI
 *   - include/func.oauth.mod.php
 *
 * PHP 8.1+
 */

date_default_timezone_set('Asia/Jakarta');

// ============================================================
// AUTO-DETECTION ENGINE
// ============================================================

/**
 * Cari direktori root assist-switching_v3_pro secara otomatis.
 *
 * Urutan pencarian:
 *   1. Known absolute paths (produksi, dicek berurutan):
 *        /var/www/prg/app/assist/aa-pro/app/assist-switching_v3_pro
 *   2. Traversal dari __DIR__ ke atas (maks 6 level):
 *      Cocok jika >= 2 dari 3 marker ditemukan:
 *        config/local_config.php | storage/cds/cache | sisproject/project.json
 */
function detectAssistRoot(): string {
    $markers = ['config/local_config.php', 'storage/cds/cache', 'sisproject/project.json'];

    // ── Helper validasi root ──────────────────────────────────
    $isValidRoot = function (string $candidate) use ($markers): bool {
        $found = 0;
        foreach ($markers as $m) {
            if (file_exists($candidate . '/' . $m)) $found++;
        }
        return $found >= 2;
    };

    // ── Langkah 1: known absolute paths (produksi) ────────────
    $knownPaths = [
        '/var/www/prg/app/assist/aa-pro/app/assist-switching_v3_pro',
    ];
    foreach ($knownPaths as $known) {
        if ($isValidRoot($known)) return $known;
    }

    // ── Langkah 2: traversal dari __DIR__ ke atas ─────────────
    $dir = __DIR__;
    for ($i = 0; $i < 6; $i++) {
        if ($isValidRoot($dir)) return $dir;
        $parent = dirname($dir);
        if ($parent === $dir) break;
        $dir = $parent;
    }
    return '';
}

/**
 * Parse local_config.php dari assist-switching_v3_pro.
 * Membaca nilai $config['key'] menggunakan regex (tanpa require/eval).
 * Return array key => value dari $config[].
 */
function parseLocalConfig(string $assistRoot): array {
    $cfgFile = $assistRoot . '/config/local_config.php';
    if (!file_exists($cfgFile)) return [];

    $src  = file_get_contents($cfgFile);
    $vals = [];

    // Pattern: $config['KEY'] = "VALUE"; atau $config["KEY"] = 'VALUE';
    // Tangani juga concatenation sederhana seperti $config['X'] . "/path"
    preg_match_all(
        '/\$config\[[\'"]([\w_]+)[\'"]\]\s*=\s*["\']([^"\']*)["\']/',
        $src,
        $matches,
        PREG_SET_ORDER
    );
    foreach ($matches as $m) {
        $vals[$m[1]] = $m[2];
    }

    // Resolve concatenation: $config['X'] . "/suffix"
    // misal: $config['_URL_GET_TOKEN_'] = $config['_URL_MY_ASSIST_'] . "/oauth/getaccesstoken";
    preg_match_all(
        '/\$config\[[\'"]([\w_]+)[\'"]\]\s*=\s*\$config\[[\'"]([\w_]+)[\'"]\]\s*\.\s*["\']([^"\']*)["\']/',
        $src,
        $concatMatches,
        PREG_SET_ORDER
    );
    foreach ($concatMatches as $m) {
        $base = $vals[$m[2]] ?? '';
        $vals[$m[1]] = $base . $m[3];
    }

    return $vals;
}

/**
 * Deteksi KODE_AGEN dan MFTFI dari nama file cache snap_tf_bank_permata.
 * Pattern nama: cds-auth-a-{KodeAgen}_{mftfi}-snap_tf_bank_permata.cache
 * Contoh: cds-auth-a-000268_0017-snap_tf_bank_permata.cache
 *   → KodeAgen = "A-000268", mftfi = "0017"
 */
function detectFromCacheTF(string $assistRoot): array {
    $cacheDir = $assistRoot . '/storage/cds/cache';
    if (!is_dir($cacheDir)) return ['kode_agen' => '', 'mftfi' => ''];

    // ── Langkah 1: cari snap_tf_bank_permata (spesifik untuk TF Permata) ──
    $files = glob($cacheDir . '/cds-auth-a-*-snap_tf_bank_permata.cache');

    // ── Langkah 2: fallback — cari semua snap_* (snap_ft_bdi, dll.) ────────
    //    Diperlukan untuk agen yang pakai snap_ft_bdi tapi belum punya
    //    snap_tf_bank_permata.cache (mis. A-000300)
    if (empty($files)) {
        $files = glob($cacheDir . '/cds-auth-a-*-snap_*.cache');
    }

    if (empty($files)) return ['kode_agen' => '', 'mftfi' => ''];

    // Ambil file terbaru (paling baru dimodifikasi)
    usort($files, fn($a, $b) => filemtime($b) - filemtime($a));
    $fname = basename($files[0]);

    // Parse: cds-auth-a-{numerik}_{mftfi}-snap_*.cache
    if (preg_match('/^cds-auth-a-(\d+)_(\w+)-snap_/', $fname, $m)) {
        return [
            'kode_agen'  => 'A-' . $m[1],   // ex: "A-000268" / "A-000300"
            'mftfi'      => $m[2],            // ex: "0017" / "0024"
            'raw_kode'   => $m[1],            // ex: "000268" / "000300"
            'cache_file' => $fname,           // nama file asli untuk info
        ];
    }

    return ['kode_agen' => '', 'mftfi' => ''];
}

/**
 * Baca OAuth credentials dari file .assist.env.
 * Cari di: __DIR__, satu level atas, dua level atas.
 * Format: KEY=VALUE (satu per baris, # untuk komentar)
 */
function loadAssistEnv(): array {
    $candidates = [
        __DIR__ . '/.assist.env',
        dirname(__DIR__) . '/.assist.env',
        dirname(dirname(__DIR__)) . '/.assist.env',
        __DIR__ . '/assist.env',
        dirname(__DIR__) . '/assist.env',
    ];

    foreach ($candidates as $path) {
        if (!file_exists($path)) continue;
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $env   = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') continue;
            if (strpos($line, '=') === false) continue;
            [$key, $val] = explode('=', $line, 2);
            $env[trim($key)] = trim($val, " \t\"'");
        }
        if (!empty($env)) return array_merge($env, ['_env_file' => $path]);
    }
    return [];
}

/**
 * Cari direktori root assist-bpr.net secara otomatis.
 * Markers: direktori env/ berisi file *.env + bin/connect.php
 *
 * Urutan pencarian:
 *   1. Known absolute paths (produksi, dicek berurutan):
 *        /var/www/prg/app/assist/bpr-myassist/assist-bpr.net
 *        /var/www/prg/app/mvc/assist-bpr.net
 *   2. Traversal dari __DIR__ ke atas (maks 8 level):
 *      a. Direktori itu sendiri memiliki env/ + bin/connect.php
 *      b. Subdirektori bernama assist-bpr.net / assist-bpr / assistbpr
 *      c. Subdirektori pola app/mvc/assist-bpr.net
 *      d. Subdirektori pola app/assist/bpr-myassist/assist-bpr.net
 */
function detectAssistBprRoot(): string {
    // ── Helper validasi root ──────────────────────────────────
    $isValidRoot = function (string $candidate): bool {
        if (!is_dir($candidate . '/env')) return false;
        if (!file_exists($candidate . '/bin/connect.php')) return false;
        $envFiles = glob($candidate . '/env/*.env') ?: [];
        return !empty($envFiles);
    };

    // ── Langkah 1: known absolute paths (produksi) ────────────
    // Urutan: path paling spesifik/baru dulu
    $knownPaths = [
        '/var/www/prg/app/assist/bpr-myassist/assist-bpr.net',
        '/var/www/prg/app/mvc/assist-bpr.net',
    ];
    foreach ($knownPaths as $known) {
        if ($isValidRoot($known)) return $known;
    }

    // ── Langkah 2: traversal dari __DIR__ ke atas ─────────────
    $subNames = ['assist-bpr.net', 'assist-bpr', 'assistbpr'];
    $dir = __DIR__;
    for ($i = 0; $i < 8; $i++) {
        // 2a. Direktori saat ini sendiri adalah root assist-bpr.net
        if ($isValidRoot($dir)) return $dir;

        // 2b. Subdirektori langsung bernama assist-bpr.net / assist-bpr / assistbpr
        foreach ($subNames as $sub) {
            $candidate = $dir . '/' . $sub;
            if ($isValidRoot($candidate)) return $candidate;
        }

        // 2c. Subdirektori app/mvc/assist-bpr.net (struktur /var/www/prg/app/mvc/)
        foreach ($subNames as $sub) {
            $candidate = $dir . '/app/mvc/' . $sub;
            if ($isValidRoot($candidate)) return $candidate;
        }

        // 2d. Subdirektori app/assist/bpr-myassist/assist-bpr.net
        foreach ($subNames as $sub) {
            $candidate = $dir . '/app/assist/bpr-myassist/' . $sub;
            if ($isValidRoot($candidate)) return $candidate;
        }

        $parent = dirname($dir);
        if ($parent === $dir) break;
        $dir = $parent;
    }
    return '';
}

/**
 * Baca OAuth credentials dari assist-bpr.net/env/{kode}_.env
 *
 * Format file: key = value  (dengan spasi di sekitar tanda =)
 * Contoh:
 *   auth_client_id = 000143
 *   auth_client_secret = 53f2cb55aa3a3ea5f7f523a2a22cc36...
 *   username = Assist
 *   password = Irac
 *   auth_server_uri = https://one.myassist.id/
 *   base_url_auth = https://auth.myassist.id/
 *
 * Mapping ke konstanta standar:
 *   auth_client_id     → OAUTH_CLIENT_ID
 *   auth_client_secret → OAUTH_CLIENT_SECRET
 *   username           → OAUTH_USERNAME
 *   password           → OAUTH_PASSWORD
 *   auth_server_uri / base_url_auth → OAUTH_SERVER_URI (opsional)
 *
 * @param string $assistBprRoot  Path root assist-bpr.net (dari detectAssistBprRoot())
 * @param string $rawKode        Kode numerik agen, mis. "000268" (dari cache filename)
 *
 * Urutan pencarian kandidat:
 *   1. {envDir}/{rawKode}_.env          — kode spesifik dari cache filename
 *   2. Semua file env numerik [0-9]*.env  diurutkan mtime desc, dicocokkan log_name dulu
 *   3. File env hostname-pattern (bpr.*.env, *.net.env, dll) — mencakup bpr.myassist.id.env
 *
 * Matching log_name:
 *   Setiap file env numerik dapat berisi "log_name = {hostname}".
 *   Jika server hostname cocok dengan log_name, file itu diprioritaskan.
 *
 * Field yang dikembalikan:
 *   OAUTH_CLIENT_ID, OAUTH_CLIENT_SECRET, OAUTH_USERNAME, OAUTH_PASSWORD,
 *   OAUTH_SERVER_URI, OAUTH_CORPORATE_ID, OAUTH_TOKEN_PRIVATE (RSA key jika ada),
 *   _env_file, _source
 */
function detectFromAssistBprEnv(string $assistBprRoot, string $rawKode = ''): array {
    $envDir = $assistBprRoot . '/env';
    if (!is_dir($envDir)) return [];

    // ── Helper: parse satu env file ke array raw ──────────────
    $parseEnvFile = function (string $path): array {
        if (!file_exists($path)) return [];
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) return [];
        $raw = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') continue;
            if (strpos($line, '=') === false) continue;
            // Format assist-bpr: "key = value" (dengan spasi di sekitar =)
            [$k, $v] = explode('=', $line, 2);
            $raw[trim($k)] = trim($v);
        }
        return $raw;
    };

    // ── Helper: buat result array dari $raw ────────────────────
    $buildResult = function (array $raw, string $path) use ($parseEnvFile, $envDir): array {
        // Resolusi URL OAuth: auth_server_uri > base_url_auth
        $oauthServerUri = rtrim(
            $raw['auth_server_uri'] ?? $raw['base_url_auth'] ?? '',
            '/'
        );
        // RSA private key — hanya ada di bpr.myassist.id.env (OAUTH_TOKEN_PRIVATE / oauth_token_private)
        // Format dalam file: backslash-n literal → ganti ke newline sesungguhnya
        $tokenPrivate = $raw['oauth_token_private'] ?? $raw['OAUTH_TOKEN_PRIVATE'] ?? '';
        if (!empty($tokenPrivate)) {
            $tokenPrivate = str_replace('\n', "\n", $tokenPrivate);
        }
        return [
            'OAUTH_CLIENT_ID'      => $raw['auth_client_id']     ?? $raw['OAUTH_CLIENT_ID']     ?? '',
            'OAUTH_CLIENT_SECRET'  => $raw['auth_client_secret'] ?? $raw['OAUTH_CLIENT_SECRET'] ?? '',
            'OAUTH_USERNAME'       => $raw['username']           ?? '',
            'OAUTH_PASSWORD'       => $raw['password']           ?? '',
            'OAUTH_SERVER_URI'     => $oauthServerUri,
            'OAUTH_CORPORATE_ID'   => $raw['auth_corporate_id']  ?? $raw['OAUTH_CORPORATE_ID']  ?? '',
            'OAUTH_TOKEN_PRIVATE'  => $tokenPrivate,
            // KodeSertifikat — kode sertifikat terdaftar di tabel `sertifikat` auth2_access.
            // Berbeda dari auth_client_id (OAuth2 SSO).
            // Di env file: auth_sertifikat atau OAUTH_SERTIFIKAT
            'OAUTH_SERTIFIKAT'     => $raw['auth_sertifikat']    ?? $raw['OAUTH_SERTIFIKAT']    ?? '',
            '_env_file'            => $path,
            '_source'              => 'assist-bpr.net/env',
        ];
    };

    // ── Deteksi hostname server saat ini ──────────────────────
    // Digunakan untuk mencocokkan log_name di env files
    $serverHost = '';
    if (!empty($_SERVER['HTTP_HOST'])) {
        // Hilangkan port jika ada (mis. "bpr.ams.sis1.net:80" → "bpr.ams.sis1.net")
        $serverHost = strtolower(explode(':', $_SERVER['HTTP_HOST'])[0]);
    } elseif (!empty($_SERVER['SERVER_NAME'])) {
        $serverHost = strtolower($_SERVER['SERVER_NAME']);
    } else {
        // CLI: gethostname() → hostname OS
        $hn = @gethostname();
        if (!empty($hn)) $serverHost = strtolower($hn);
    }

    // ── Langkah 1: kode spesifik dari cache filename ──────────
    if (!empty($rawKode)) {
        $specificPath = $envDir . '/' . $rawKode . '_.env';
        $raw = $parseEnvFile($specificPath);
        if (!empty($raw['auth_client_id']) || !empty($raw['OAUTH_CLIENT_ID'])) {
            return $buildResult($raw, $specificPath);
        }
        // Coba tanpa leading zeros (mis. "000087" → "87")
        $noLeading = ltrim($rawKode, '0');
        if ($noLeading !== $rawKode && $noLeading !== '') {
            $altPath = $envDir . '/' . $noLeading . '_.env';
            $raw = $parseEnvFile($altPath);
            if (!empty($raw['auth_client_id']) || !empty($raw['OAUTH_CLIENT_ID'])) {
                return $buildResult($raw, $altPath);
            }
        }
    }

    // ── Langkah 2: hostname matching via log_name ─────────────
    // Scan semua file numerik; jika log_name cocok dengan server hostname → prioritas tinggi
    if (!empty($serverHost)) {
        $allNumericEnv = glob($envDir . '/[0-9]*.env') ?: [];
        foreach ($allNumericEnv as $path) {
            $raw = $parseEnvFile($path);
            if (empty($raw)) continue;
            $logName = strtolower(trim($raw['log_name'] ?? ''));
            if ($logName === '' || $logName !== $serverHost) continue;
            // Cocok! File ini milik server yang sedang berjalan
            if (!empty($raw['auth_client_id']) || !empty($raw['OAUTH_CLIENT_ID'])) {
                return $buildResult($raw, $path);
            }
        }
    }

    // ── Langkah 3: fallback semua file numerik, mtime desc ────
    $allNumericEnv = glob($envDir . '/[0-9]*.env') ?: [];
    usort($allNumericEnv, fn($a, $b) => filemtime($b) - filemtime($a));
    foreach ($allNumericEnv as $path) {
        $raw = $parseEnvFile($path);
        if (empty($raw['auth_client_id']) && empty($raw['OAUTH_CLIENT_ID'])) continue;
        return $buildResult($raw, $path);
    }

    // ── Langkah 4: file env hostname-pattern (non-numerik) ────
    // Contoh: bpr.myassist.id.env, sis1.cloud.env, dll.
    // Glob semua *.env, exclude file numerik yang sudah di-scan
    $allEnvFiles  = glob($envDir . '/*.env') ?: [];
    $numericPaths = array_fill_keys($allNumericEnv, true);
    foreach ($allEnvFiles as $path) {
        if (isset($numericPaths[$path])) continue;   // sudah di-scan di langkah 3
        $basename = basename($path);
        // Skip file yang diketahui kosong (berisi IP dengan port, atau nama sangat pendek)
        if (strpos($basename, ':') !== false) continue;
        $raw = $parseEnvFile($path);
        if (empty($raw['auth_client_id']) && empty($raw['OAUTH_CLIENT_ID'])) continue;
        return $buildResult($raw, $path);
    }

    return [];
}

// ────────────────────────────────────────────────────────────
// Jalankan auto-detection
// ────────────────────────────────────────────────────────────
$_ASSIST_ROOT   = detectAssistRoot();
$_LOCAL_CONFIG  = !empty($_ASSIST_ROOT) ? parseLocalConfig($_ASSIST_ROOT) : [];
$_CACHE_TF      = !empty($_ASSIST_ROOT) ? detectFromCacheTF($_ASSIST_ROOT) : [];
$_ASSIST_ENV    = loadAssistEnv();

// Auto-detect assist-bpr.net (sumber OAuth tambahan)
$_ASSIST_BPR_ROOT = detectAssistBprRoot();
$_RAW_KODE_TF     = $_CACHE_TF['raw_kode'] ?? '';
$_BPR_ENV         = !empty($_ASSIST_BPR_ROOT)
    ? detectFromAssistBprEnv($_ASSIST_BPR_ROOT, $_RAW_KODE_TF)
    : [];

// Deteksi sumber tiap nilai
$_DETECTION_LOG = [];

// ── URLs (dari local_config.php) ─────────────────────────────
$_myAssistBase  = $_LOCAL_CONFIG['_URL_MY_ASSIST_'] ?? 'http://myassist.sis1.net/assist-auth_api/public';
$_urlGetToken   = $_LOCAL_CONFIG['_URL_GET_TOKEN_'] ?? ($_myAssistBase . '/oauth/getaccesstoken');
$_urlCekToken   = $_LOCAL_CONFIG['_URL_CEK_TOKEN_'] ?? ($_myAssistBase . '/oauth/cekaccesstoken');
$_urlRfzToken   = $_LOCAL_CONFIG['_URL_RFZ_TOKEN_'] ?? ($_myAssistBase . '/oauth/getaccesstokenfromrefresh');
$_swProBase     = $_LOCAL_CONFIG['_URL_SW_PRO_']    ?? 'http://switching.mcoll.sis1.net/assist-switching_v3_pro/public';
// URL_DIGITAL selalu dibangun dari _URL_SW_PRO_ (proxy v3)
// Key 's'/'ss' di local_config adalah URL INTERNAL switching→digital, TIDAK dipakai langsung
$_urlDigital    = $_swProBase . '/mobile-digital';
$_urlDigitalSS  = $_swProBase . '/mobile-digital';
$_mftfi         = $_CACHE_TF['mftfi']               ?? ($_LOCAL_CONFIG['mftfi'] ?? '002');

$_DETECTION_LOG['assist_root']    = $_ASSIST_ROOT ?: '— tidak ditemukan (fallback ke default)';
$_DETECTION_LOG['local_config']   = !empty($_LOCAL_CONFIG) ? '✅ Terbaca (' . count($_LOCAL_CONFIG) . ' keys)' : '⚠️ Tidak ditemukan';
$_DETECTION_LOG['url_get_token']  = empty($_LOCAL_CONFIG['_URL_GET_TOKEN_']) ? '⚠️ fallback default' : '✅ dari local_config.php';
$_DETECTION_LOG['url_digital']    = !empty($_LOCAL_CONFIG['_URL_SW_PRO_']) ? '✅ dari local_config.php (_URL_SW_PRO_)' : '⚠️ fallback default (switching.mcoll.sis1.net)';

// ── KODE_AGEN + MFTFI (dari cache file) ──────────────────────
$_kodeAgen     = $_CACHE_TF['kode_agen'] ?? '';
$_mftfiFromCache = !empty($_CACHE_TF['mftfi']);

$_DETECTION_LOG['kode_agen']      = !empty($_kodeAgen)   ? '✅ auto dari cache: ' . $_kodeAgen  : '⚠️ tidak ditemukan di cache';
$_DETECTION_LOG['mftfi']          = $_mftfiFromCache      ? '✅ auto dari cache: ' . $_mftfi     : '⚠️ fallback dari local_config/default';

// ── OAuth Credentials (prioritas: .assist.env > assist-bpr.net/env/) ──────
// Sumber 1: .assist.env (format KEY=VALUE)
$_oauthClientId     = $_ASSIST_ENV['OAUTH_CLIENT_ID']     ?? '';
$_oauthClientSecret = $_ASSIST_ENV['OAUTH_CLIENT_SECRET'] ?? '';
$_oauthUsername     = $_ASSIST_ENV['OAUTH_USERNAME']      ?? '';
$_oauthPassword     = $_ASSIST_ENV['OAUTH_PASSWORD']      ?? '';
$_de061              = $_ASSIST_ENV['DE061_SIM_SERIAL']     ?? '';
$_oauthSertifikat   = $_ASSIST_ENV['OAUTH_SERTIFIKAT']    ?? '';  // KodeSertifikat di tabel sertifikat
$_oauthKodeAplikasi = $_ASSIST_ENV['OAUTH_KODE_APLIKASI'] ?? '';
$_oauthCorporateId  = '';           // auth_corporate_id dari env BPR
$_oauthTokenPrivate = '';           // RSA private key dari bpr.myassist.id.env
$_oauthSource       = !empty($_oauthClientId) ? '.assist.env' : '';

// Sumber 2: assist-bpr.net/env/{kode}_.env — fallback jika .assist.env kosong
if (empty($_oauthClientId) && !empty($_BPR_ENV['OAUTH_CLIENT_ID'])) {
    // Full override dari BPR env
    $_oauthClientId     = $_BPR_ENV['OAUTH_CLIENT_ID'];
    $_oauthClientSecret = $_BPR_ENV['OAUTH_CLIENT_SECRET'] ?? '';
    $_oauthUsername     = $_BPR_ENV['OAUTH_USERNAME']      ?? '';
    $_oauthPassword     = $_BPR_ENV['OAUTH_PASSWORD']      ?? '';
    $_oauthCorporateId  = $_BPR_ENV['OAUTH_CORPORATE_ID']  ?? '';
    $_oauthTokenPrivate = $_BPR_ENV['OAUTH_TOKEN_PRIVATE'] ?? '';
    // DE061 tetap dari .assist.env jika ada; assist-bpr tidak menyimpan DE061
    $_oauthSource       = 'assist-bpr.net/env';
} elseif (empty($_oauthClientSecret) && !empty($_BPR_ENV['OAUTH_CLIENT_SECRET'])) {
    // Partial fill: client_id ada di .assist.env tapi secret kosong → ambil dari bpr
    $_oauthClientSecret = $_BPR_ENV['OAUTH_CLIENT_SECRET'];
    if (empty($_oauthUsername)) $_oauthUsername = $_BPR_ENV['OAUTH_USERNAME'] ?? '';
    if (empty($_oauthPassword)) $_oauthPassword = $_BPR_ENV['OAUTH_PASSWORD'] ?? '';
    $_oauthSource .= '+assist-bpr.net/env';
}
// Corporate ID + RSA key selalu diisi dari BPR env jika belum ada (field tambahan)
if (empty($_oauthCorporateId)  && !empty($_BPR_ENV['OAUTH_CORPORATE_ID']))  $_oauthCorporateId  = $_BPR_ENV['OAUTH_CORPORATE_ID'];
if (empty($_oauthTokenPrivate) && !empty($_BPR_ENV['OAUTH_TOKEN_PRIVATE'])) $_oauthTokenPrivate = $_BPR_ENV['OAUTH_TOKEN_PRIVATE'];
// OAUTH_SERTIFIKAT — KodeSertifikat untuk assist-switching token system
// .assist.env lebih prioritas; fallback ke assist-bpr.net/env jika ada field auth_sertifikat
if (empty($_oauthSertifikat)    && !empty($_BPR_ENV['OAUTH_SERTIFIKAT']))    $_oauthSertifikat   = $_BPR_ENV['OAUTH_SERTIFIKAT'];
if (empty($_oauthKodeAplikasi) && !empty($_BPR_ENV['OAUTH_KODE_APLIKASI'])) $_oauthKodeAplikasi = $_BPR_ENV['OAUTH_KODE_APLIKASI'];
if (empty($_de061)             && !empty($_BPR_ENV['DE061_SIM_SERIAL']))     $_de061             = $_BPR_ENV['DE061_SIM_SERIAL'];
// Sumber 3: hardcoded defaults dari tabel accesstoken row MRA000291/android
// Digunakan jika .assist.env tidak ada DAN assist-bpr.net/env tidak mengandung nilai ini
if (empty($_oauthSertifikat))   $_oauthSertifikat   = 'd9ebe47971b415daadc3440ee4070aea';
if (empty($_oauthKodeAplikasi)) $_oauthKodeAplikasi = 'MRA000291';
if (empty($_de061))             $_de061             = '0103aab935bfa953c260';

$_envFile = !empty($_oauthSource) && str_contains($_oauthSource, 'bpr')
    ? ($_BPR_ENV['_env_file'] ?? ($_ASSIST_ENV['_env_file'] ?? ''))
    : ($_ASSIST_ENV['_env_file'] ?? '');

$_DETECTION_LOG['assist_bpr_root']      = !empty($_ASSIST_BPR_ROOT)     ? '✅ ' . $_ASSIST_BPR_ROOT      : '— tidak ditemukan';
$_DETECTION_LOG['oauth_env_file']       = !empty($_envFile)             ? '✅ ' . $_envFile               : '⚠️ env tidak ditemukan';
$_DETECTION_LOG['oauth_source']         = !empty($_oauthSource)         ? '✅ ' . $_oauthSource           : '⚠️ tidak ada .assist.env (pakai hardcoded MRA000291)';
$_DETECTION_LOG['oauth_client_id']      = !empty($_oauthClientId)       ? '✅ terisi (' . $_oauthSource . ')' : '❌ belum diisi';
$_DETECTION_LOG['oauth_sertifikat']     = !empty($_oauthSertifikat)
    ? '✅ terisi — AKAN DIPAKAI sebagai KodeSertifikat'
    : '❌ KOSONG — fallback ke OAUTH_CLIENT_ID (kemungkinan salah!)';
$_DETECTION_LOG['oauth_username']       = !empty($_oauthUsername)       ? '✅ terisi (' . $_oauthSource . ')' : '❌ belum diisi';
$_DETECTION_LOG['oauth_corporate_id']   = !empty($_oauthCorporateId)    ? '✅ ' . $_oauthCorporateId      : '⚠️ kosong (opsional)';
$_DETECTION_LOG['oauth_token_private']  = !empty($_oauthTokenPrivate)   ? '✅ RSA key terdeteksi'         : '⚠️ tidak ada (opsional)';
$_DETECTION_LOG['de061_sim_serial']     = !empty($_de061)               ? '✅ ' . $_de061                 : '⚠️ kosong';

// ============================================================
// DEFINISIKAN KONSTANTA (dari hasil auto-detection)
// ============================================================

define('URL_GET_TOKEN',  $_urlGetToken);
define('URL_CEK_TOKEN',  $_urlCekToken);
define('URL_RFZ_TOKEN',  $_urlRfzToken);
define('URL_DIGITAL',    $_urlDigital);
define('URL_DIGITAL_SS', $_urlDigitalSS);
define('MFTFI',          $_mftfi);
define('KODE_AGEN',      $_kodeAgen);
define('OAUTH_CLIENT_ID',      $_oauthClientId);
define('OAUTH_CLIENT_SECRET',  $_oauthClientSecret);
define('OAUTH_USERNAME',       $_oauthUsername);
define('OAUTH_PASSWORD',       $_oauthPassword);
define('OAUTH_CORPORATE_ID',   $_oauthCorporateId);
define('OAUTH_TOKEN_PRIVATE',  $_oauthTokenPrivate);
define('DE061_SIM_SERIAL',     $_de061);
// OAUTH_SERTIFIKAT = KodeSertifikat terdaftar di tabel `sertifikat` di DB auth2_access.
// BERBEDA dari OAUTH_CLIENT_ID (yang adalah auth_client_id = kode BPR untuk OAuth2 SSO).
// Diperoleh dari admin SIS, disimpan di .assist.env sebagai OAUTH_SERTIFIKAT=xxxx
// Jika kosong, getAccessToken() fallback ke OAUTH_CLIENT_ID (backward compat).
define('OAUTH_SERTIFIKAT',     $_oauthSertifikat);
// OAUTH_KODE_APLIKASI = KodeAplikasi di tabel accesstoken (mis: MRA000291)
define('OAUTH_KODE_APLIKASI',  $_oauthKodeAplikasi);

// ── Token Cache (CDS pattern) ────────────────────────────────
// Cache dir: ikuti struktur asli jika assist root ditemukan, fallback ke __DIR__
$_cacheDir = !empty($_ASSIST_ROOT)
    ? $_ASSIST_ROOT . '/storage/cds/cache'
    : __DIR__ . '/storage/cds/cache';

$_rawKode = $_CACHE_TF['raw_kode'] ?? str_replace('A-', '', KODE_AGEN);

define('CACHE_DIR',  $_cacheDir);
define('CACHE_FILE_TF', CACHE_DIR . '/cds-auth-a-' . $_rawKode . '_' . MFTFI . '-snap_tf_bank_permata.cache');

// ── Opsi ────────────────────────────────────────────────────
define('CURL_TIMEOUT', 30);
define('DEBUG_MODE',   true);

// Bersihkan variabel sementara
// Bersihkan variabel sementara yang sudah tidak dipakai
// CATATAN: $_ASSIST_ROOT, $_LOCAL_CONFIG, $_CACHE_TF, $_ASSIST_ENV,
//          $_ASSIST_BPR_ROOT, dan $_BPR_ENV TIDAK di-unset —
//          masih dipakai oleh panel Status Auto-Detection di HTML bawah.
unset($_myAssistBase, $_urlGetToken, $_urlCekToken, $_urlRfzToken,
      $_urlDigital, $_urlDigitalSS, $_mftfi, $_mftfiFromCache,
      $_kodeAgen, $_rawKode, $_cacheDir, $_envFile, $_oauthSource,
      $_oauthClientId, $_oauthClientSecret, $_oauthUsername, $_oauthPassword,
      $_oauthCorporateId, $_oauthTokenPrivate, $_oauthSertifikat, $_oauthKodeAplikasi, $_de061, $_RAW_KODE_TF);

// ============================================================
// FUNGSI HELPER
// ============================================================

/**
 * SNow() — timestamp fungsi sesuai source code Assist
 * Format: Y-m-d H:i:s (WIB)
 */
function SNow(): string {
    return date('Y-m-d H:i:s');
}

/**
 * Buat direktori cache jika belum ada
 */
function ensureCacheDir(): void {
    if (!is_dir(CACHE_DIR)) {
        @mkdir(CACHE_DIR, 0755, true);
    }
}

/**
 * Simpan token ke cache file (CDS pattern)
 * Format cache: JSON {access_token, expires_in, cached_at, refresh_token}
 */
function saveTokenCache(array $tokenData): void {
    ensureCacheDir();
    $cache = [
        'access_token'  => $tokenData['access_token']  ?? '',
        'refresh_token' => $tokenData['refresh_token'] ?? '',
        'expires_in'    => $tokenData['expires_in']    ?? 3600,
        'cached_at'     => time(),
        'token_type'    => $tokenData['token_type']    ?? 'Bearer',
    ];
    @file_put_contents(CACHE_FILE_TF, json_encode($cache));
}

/**
 * Baca token dari cache file
 * Return null jika cache tidak ada / expired
 */
function readTokenCache(): ?string {
    if (!file_exists(CACHE_FILE_TF)) return null;
    $raw   = @file_get_contents(CACHE_FILE_TF);
    if (!$raw) return null;
    $cache = json_decode($raw, true);
    if (empty($cache['access_token'])) return null;
    // Cek expiry: expires_in dikurangi 60 detik buffer
    $expiresAt = ($cache['cached_at'] ?? 0) + ($cache['expires_in'] ?? 3600) - 60;
    if (time() > $expiresAt) return null;
    return $cache['access_token'];
}

/**
 * HTTP POST menggunakan cURL
 * Mendukung form-urlencoded (default) dan JSON
 */
function sendHttpPost(string $url, string $body, array $headers, bool $isJson = false): array {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => CURL_TIMEOUT,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_FOLLOWLOCATION => true,
    ]);
    $t0   = microtime(true);
    $raw  = curl_exec($ch);
    $ms   = round((microtime(true) - $t0) * 1000);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    return [
        'http_code'  => $code,
        'raw'        => $raw ?? '',
        'data'       => json_decode($raw ?? '', true) ?? [],
        'curl_error' => $err,
        'elapsed_ms' => $ms,
        'url'        => $url,
        'body_sent'  => $body,
        'headers'    => $headers,
    ];
}

/**
 * Ambil OAuth Access Token dari myassist.sis1.net (assist-auth_api)
 *
 * ARSITEKTUR (hasil analisis source code assist-auth_api):
 *
 *  Kita  →  assist-auth_api (/oauth/getaccesstoken)
 *              → routes ke access::go()
 *              → ApiController::GetAccessToken()
 *                  → baca header 'Authorization: Bearer' dari request kita
 *                  → POST $_POST + header Authorization ke:
 *                     http://db.auth.access.sis1.net/auth2_access/public/getaccesstoken
 *
 *  auth2_access mensyaratkan:
 *    Header : Authorization: Bearer {KodeSertifikat}   ← OAUTH_SERTIFIKAT
 *    POST   : PLATFORM=xxx&DEVICEID=xxx&VERSIAPLIKASI=xxx
 *    (BUKAN format OAuth2 standard grant_type/client_id/etc)
 *
 *  KodeSertifikat = nilai dari konstanta OAUTH_SERTIFIKAT (dari .assist.env).
 *  BUKAN OAUTH_CLIENT_ID (auth_client_id) yang merupakan kode BPR untuk OAuth2 SSO.
 *
 *  Response sukses (auth2_access):
 *    {"Status":1, "Data":{"AccessToken":"...","RefreshToken":"...","LifeTime":"10", ...}}
 */
function getAccessToken(): array {
    // 1. Coba ambil dari cache
    $cached = readTokenCache();
    if ($cached) {
        return ['success' => true, 'token' => $cached, 'from_cache' => true];
    }

    // 2. Bangun POST body sesuai format Assist (bukan OAuth2 standard)
    //    DEVICEID  = DE061_SIM_SERIAL (IP server) jika ada, fallback SERVER_ADDR, fallback KODE_AGEN, fallback md5 hostname
    //    Dari tabel accesstoken MRA000291: DeviceID='0103aab935bfa953c260', Platform='android', VersiAplikasi='1.2.6'
    $deviceId = DE061_SIM_SERIAL
        ?: ($_SERVER['SERVER_ADDR'] ?? '')
        ?: (KODE_AGEN ?: md5(gethostname()));
    $postBody = [
        'PLATFORM'      => 'android',
        'DEVICEID'      => $deviceId,
        'VERSIAPLIKASI' => '1.2.6',
        'USERNAME'      => OAUTH_USERNAME,
    ];
    // Sertakan KODEAPLIKASI jika tersedia (dari tabel accesstoken: KodeAplikasi='MRA000291')
    if (OAUTH_KODE_APLIKASI !== '') {
        $postBody['KODEAPLIKASI'] = OAUTH_KODE_APLIKASI;
    }
    $body = http_build_query($postBody);

    // 3. Header: Authorization: Bearer {KodeSertifikat}
    //    assist-auth_api membaca header ini dan meneruskannya ke auth2_access.
    //
    //    PRIORITAS pengambilan KodeSertifikat:
    //      1. OAUTH_SERTIFIKAT  ← isian baru di .assist.env (auth_sertifikat dari admin SIS)
    //      2. OAUTH_CLIENT_ID   ← fallback lama (biasanya salah, tapi dijaga backward compat)
    //
    //    OAUTH_SERTIFIKAT ≠ OAUTH_CLIENT_ID:
    //      OAUTH_CLIENT_ID  = auth_client_id BPR = kode untuk OAuth2 SSO di myassist.id
    //      OAUTH_SERTIFIKAT = KodeSertifikat di tabel `sertifikat` auth2_access = untuk assist-switching
    $kodeSertifikat = OAUTH_SERTIFIKAT ?: OAUTH_CLIENT_ID;
    $sertifikatSource = OAUTH_SERTIFIKAT ? 'OAUTH_SERTIFIKAT' : 'OAUTH_CLIENT_ID (fallback)';

    $headers = [
        'Content-Type: application/x-www-form-urlencoded',
        'Accept: application/json',
        'Authorization: Bearer ' . $kodeSertifikat,
    ];

    $result = sendHttpPost(URL_GET_TOKEN, $body, $headers);
    // Sertakan info sumber KodeSertifikat di result untuk debug
    $result['kode_sertifikat']        = $kodeSertifikat;
    $result['kode_sertifikat_source'] = $sertifikatSource;

    if (!empty($result['curl_error'])) {
        return ['success' => false, 'error' => 'cURL: ' . $result['curl_error'], 'raw' => $result];
    }

    $data = $result['data'];

    // Response sukses: {"Status":1, "Data":{"AccessToken":"...","RefreshToken":"...","LifeTime":"10"}}
    // AccessToken (kapital A) — bukan access_token (OAuth2 standard)
    $accessToken = $data['Data']['AccessToken']
                ?? $data['data']['AccessToken']
                ?? $data['access_token']
                ?? '';

    if (!empty($accessToken)) {
        // Simpan KodeSertifikat di cache untuk referensi
        // Normalisasi ke format cache: simpan sebagai access_token
        $cacheData = [
            'access_token' => $accessToken,
            'expires_in'   => (int)(($data['Data']['LifeTime'] ?? 10) * 60),
        ];
        saveTokenCache($cacheData);
        return [
            'success'          => true,
            'token'            => $accessToken,
            'from_cache'       => false,
            'raw'              => $result,
            'sertifikat_used'  => $kodeSertifikat,
            'sertifikat_source'=> $sertifikatSource,
        ];
    }

    // Susun pesan error informatif
    // Prioritas: MSG > message > error_description > body mentah > "HTTP {code}"
    $errMsg = $data['MSG']
           ?? $data['message']
           ?? $data['error_description']
           ?? $data['error']
           ?? (trim($result['raw']) ?: null)
           ?? ('HTTP ' . $result['http_code']);
    return [
        'success'          => false,
        'error'            => $errMsg,
        'raw'              => $result,
        'sertifikat_used'  => $kodeSertifikat,
        'sertifikat_source'=> $sertifikatSource,
    ];
}

/**
 * Bangun DE048 sesuai format ISO 8583 Assist
 *
 * Dari source (mbanking.controller.php):
 *   $vaDE048 = split("*", $cRequest['DE048']);  → 3 bagian dipisah *
 *   $vaTrx   = split("~~", $vaDE048[2]);         → bagian ke-2 dipisah ~~
 *   Format: {part1}*{part2}*{TrxCode}~~{KodeBank}
 *
 * Contoh DE048 (KodeBank diambil dari input user, bukan hardcoded):
 *   INQTFDANA : "0601*1001*INQTFDANA~~{kodeBank}"
 *   INQLLG    : "0201*1001*INQLLG~~{kodeBank}"
 *   INQRTGS   : "0801*1001*INQRTGS~~{kodeBank}"
 *   INQBIFAST : "0602*1001*INQBIFAST~~{kodeBIFAST}~~{nominal}"
 *               kodeBIFAST = kolom KodeBIFAST di bank_code (mis. "CENAIDJA")
 *               nominal    = nominal transfer (diperlukan saat inquiry BI-FAST)
 *
 * Part1 = kode kategori, Part2 = kode produk internal, TrxCode = kode transaksi Assist
 * KodeBank  = kode bank tujuan:
 *   TFDANA/LLG/RTGS = kolom Kode di bank_code (SWIFT/alias Assist), mis. "CENAIDJA"
 *   BIFAST          = kolom KodeBIFAST di bank_code, mis. "CENAIDJA" (BIC8/BIC11)
 *
 * CATATAN: kode bank di DE048 adalah BUKAN kode numerik BI (DE103).
 *          Contoh TFDANA: DE103="014" → DE048 kodeBank="CENAIDJA"
 *          Contoh BIFAST: DE103="014" → DE048 kodeBIFAST="CENAIDJA"
 */
function buildDE048(string $jenisTF, string $kodeBank = 'BLTRFAG', int $nominal = 0): string {
    $kodeBank = strtoupper(trim($kodeBank));
    if (empty($kodeBank)) $kodeBank = 'BLTRFAG';  // fallback
    $prefix = [
        'TFDANA' => '0601*1001*INQTFDANA~~',
        'LLG'    => '0201*1001*INQLLG~~',
        'RTGS'   => '0801*1001*INQRTGS~~',
        'BIFAST' => '0602*1001*INQBIFAST~~',
    ];
    $p = $prefix[strtoupper($jenisTF)] ?? $prefix['TFDANA'];
    // BIFAST: tambahkan ~~{nominal} di belakang kodeBank
    if (strtoupper($jenisTF) === 'BIFAST') {
        return $p . $kodeBank . '~~' . (string)$nominal;
    }
    return $p . $kodeBank;
}

/**
 * JSON2ISO — bangun string ISO 8583 serialized sesuai MBankingFunc::JSON2ISO
 *
 * Signature dari source:
 *   MBankingFunc::JSON2ISO(false, DE003, DE004, DE012, DE013, DE037, RC, DE044, DE048, DE052, DE061, DE102, DE103)
 *
 * Format output: JSON array dengan key MTI, RC, MSG (DE fields)
 * Digunakan untuk membangun request ke digital server.
 *
 * Untuk INQUIRY (MTI=010), field yang dikirim:
 *   DE003  = processing code (ex: "231041" untuk payment inquiry)
 *   DE004  = amount (12 digit, ex: "000000000000")
 *   DE012  = local time (HHmm, ex: "1430")
 *   DE013  = local date (ddMM, ex: "1206")
 *   DE037  = retrieval reference number (12 digit)
 *   DE044  = additional response data (biasanya "0")
 *   DE048  = additional data (kode transaksi, ex: "0601*1001*INQTFDANA~~BLTRFAG")
 *   DE052  = PIN data (64 zero untuk inquiry)
 *   DE061  = SIMSerial / device identifier
 *   DE102  = account identification 1 (nomor rekening tujuan)
 *   DE103  = account identification 2 (kode bank tujuan)
 */
function buildISO8583Request(array $params, string $accessToken): array {
    $jenisTF       = strtoupper($params['jenis_transfer'] ?? 'TFDANA');
    $nomorRekening = preg_replace('/\D/', '', $params['nomor_rekening'] ?? '');
    $kodeBank      = $params['kode_bank'] ?? '';
    $nominal       = (int)preg_replace('/\D/', '', $params['nominal'] ?? '0');

    // DE004: nominal dalam format 12 digit + "00" (2 digit desimal)
    $de004 = str_pad($nominal, 10, '0', STR_PAD_LEFT) . '00';

    // DE012: HHmm (jam:menit sekarang)
    $de012 = date('Hi');

    // DE013: MMDD (bulan:tanggal) — sesuai source controller: date("md")
    // CATATAN: response dari server menggunakan DDMM (mis. "1406" = hari14 bulan06),
    // tetapi REQUEST ke switching tetap pakai MMDD (mis. "0614") sesuai source controller.
    // Inkonsistensi ini ada di sisi server; kita mengikuti format pengiriman controller.
    $de013 = date('md');  // MMDD: bulan dulu, lalu hari

    // DE037: retrieval reference number 12 digit (nomor unik transaksi)
    $de037 = date('His') . str_pad((string)mt_rand(1, 999999), 6, '0', STR_PAD_LEFT);

    // DE048: kode transaksi sesuai jenis transfer + kode bank tujuan (Kode di tabel bank_code)
    // kode_bank_de048 = kolom Kode di bank_code (alias Assist/SWIFT), mis. "BLTRFAG", "CENAIDJA"
    //   untuk TFDANA/LLG/RTGS: gunakan kolom Kode (BankPermataID/alias Assist)
    //   untuk BIFAST          : gunakan kolom KodeBIFAST (BIC8/BIC11, mis. "CENAIDJA")
    // kode_bank (DE103) = kode numerik BI, mis. "014" (BCA) — bisa berbeda dari DE048
    if ($jenisTF === 'BIFAST') {
        // BIFAST pakai kode_bank_bifast (KodeBIFAST), fallback ke kode_bank_de048
        $kodeBankDE048 = strtoupper(trim(
            $params['kode_bank_bifast'] ?? $params['kode_bank_de048'] ?? $params['kode_bank'] ?? ''
        ));
        // Guard: jika nilai masih berupa kode numerik BI (mis. "014"), auto-map ke KodeBIFAST BIC
        // Ini terjadi saat kode_bank_bifast tidak diisi / JS auto-fill tidak berjalan
        if (preg_match('/^\d+$/', $kodeBankDE048)) {
            global $mapKodeBankBIFAST;
            $mapped = $mapKodeBankBIFAST[$kodeBankDE048] ?? '';
            if ($mapped !== '') {
                $kodeBankDE048 = $mapped;
            }
            // Jika tidak ada di map → biarkan apa adanya (switching akan kembalikan error)
        }
    } else {
        $kodeBankDE048 = strtoupper(trim($params['kode_bank_de048'] ?? $params['kode_bank'] ?? ''));
    }
    // BIFAST: nominal diteruskan ke buildDE048 karena diperlukan dalam format DE048
    $de048 = buildDE048($jenisTF, $kodeBankDE048, $nominal);

    // DE052: PIN block (64 zero untuk inquiry)
    $de052 = str_repeat('0', 64);

    // DE061: SIM Serial / device identifier agen
    $de061 = DE061_SIM_SERIAL;

    // DE102: nomor rekening tujuan
    $de102 = $nomorRekening;

    // DE103: kode bank tujuan
    $de103 = $kodeBank;

    // Bangun struktur ISO 8583-like JSON sesuai MTI=010 (DIGITAL_BANK_INQUIRY)
    $msg = [
        'DE003' => '231041',    // processing code inquiry payment
        'DE004' => $de004,
        'DE012' => $de012,
        'DE013' => $de013,
        'DE037' => $de037,
        'DE044' => '0',
        'DE048' => $de048,
        'DE052' => $de052,
        'DE061' => $de061,
        'DE102' => $de102,
        'DE103' => $de103,
    ];

    $isoRequest = [
        'MTI' => '010',         // DIGITAL_BANK_INQUIRY
        'MSG' => $msg,
    ];

    return $isoRequest;
}

/**
 * Kirim request inquiry ke assist-switching_v3_pro (/mobile-digital)
 *
 * Sesuai source code (mbanking.controller.php):
 *   SendHTTPPost($cURL, $_POST, "", false, $vaHeaders, true)
 *   → proxy forward $_POST as-is ke digital.sis1.net
 *   → proxy memvalidasi DEVICEID sebelum forward (401 jika kosong)
 *
 * POST body wajib mengandung:
 *   cCode      = JSON ISO 8583 request
 *   DEVICEID   = DE061_SIM_SERIAL (divalidasi proxy sebelum forward)
 *   PLATFORM   = android
 *   VERSIAPLIKASI = sesuai row accesstoken
 */
function sendInquiryRequest(array $isoRequest, string $accessToken): array {
    $cB      = json_encode($isoRequest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $deviceId = DE061_SIM_SERIAL
        ?: ($_SERVER['SERVER_ADDR'] ?? '')
        ?: (KODE_AGEN ?: md5(gethostname()));

    $postFields = http_build_query([
        'cCode'         => $cB,
        'DEVICEID'      => $deviceId,
        'PLATFORM'      => 'android',
        'VERSIAPLIKASI' => '1.2.6',
    ]);
    $cU = URL_DIGITAL;

    $headers = [
        'Authorization: Bearer ' . $accessToken,
        'Content-Type: application/x-www-form-urlencoded',
    ];

    $result = sendHttpPost($cU, $postFields, $headers);
    $result['iso_request'] = $isoRequest;
    $result['body_signed'] = $postFields;

    return $result;
}

/**
 * ISO2Array — parse response ISO 8583 dari digital server
 *
 * Response dari switching (mbanking.controller.php) dikembalikan sebagai:
 *   {"status":true,"response_code":200,"data":"...ISO_ARRAY_JSON...","message":"",...}
 *
 * Field "data" berisi JSON string hasil MBankingFunc::ISO2Array(), contoh:
 *   {
 *     "ISO_MD5":"...", "ISO":"0210...", "MTI":"0210",
 *     "DE001":"...", "DE003":"231041", "DE004":"000000750000",
 *     "DE039":"11",  "DE048":"Transaksi tidak dapat diproses...",
 *     "DE102":"...", "DE103":"0", ...
 *   }
 *
 * Normalisasi yang dilakukan:
 *   - RC   ← DE039  (response code ISO 8583; "00"=sukses, "11"=error, dll.)
 *   - MSG  ← DE048  (pesan/tagihan dari bank atau error message)
 *   - Pertahankan semua field DE* asli untuk debug
 *
 * Sesuai source:
 *   $vaResponse = json_decode($cResponse, 1);
 *   $vaResponse = json_decode($vaResponse["data"], 1);  // unwrap outer
 *   ISO2Array sudah diparsing di sisi switching; kita terima hasilnya langsung
 */
function parseISO8583Response(array $httpResult): array {
    $raw  = $httpResult['raw'] ?? '';
    $data = $httpResult['data'] ?? [];

    // ── Langkah 0: strip PHP Notice/Warning HTML sebelum JSON ────
    // Server prod kadang masih menghasilkan PHP Notice di output (mis. "Undefined index: KT")
    // yang muncul sebagai "<br />\n<b>Notice</b>:..." SEBELUM JSON string.
    // json_decode() PHP akan return null jika ada konten non-JSON di awal string.
    // Fix: cari posisi '{' pertama, potong semua konten sebelumnya.
    if (empty($data) && !empty($raw)) {
        $jsonStart = strpos($raw, '{');
        if ($jsonStart !== false) {
            $cleanRaw = substr($raw, $jsonStart);
            $decoded  = json_decode($cleanRaw, true);
            if (is_array($decoded)) {
                $data = $decoded;
            }
        }
    }

    // ── Langkah 1: unwrap outer JSON wrapper dari switching ──────
    // Switching mengembalikan: {"status":true,"response_code":200,"data":"...", ...}
    // Field "data" berisi JSON string ISO array — perlu di-decode sekali lagi
    if (!empty($data['data']) && is_string($data['data'])) {
        $inner = json_decode($data['data'], true);
        if (is_array($inner)) {
            $data = $inner;
        }
    }

    // ── Langkah 2: normalisasi RC dan MSG dari field DE* ──────────
    // Switching mengembalikan field ISO 8583 flat (DE039, DE048, DE004, dll.)
    // bukan nested {"RC":"00","MSG":{...}} — kita normalisasi agar
    // kode downstream bisa menggunakan $data['RC'] dan $data['MSG'] secara konsisten
    if (isset($data['DE039']) && !isset($data['RC'])) {
        // DE039 = response code ISO 8583
        $data['RC']  = $data['DE039'];
    }
    if (isset($data['DE048']) && !isset($data['MSG'])) {
        // DE048 = pesan/tagihan (nama rekening jika sukses, pesan error jika gagal)
        $data['MSG'] = $data['DE048'];
    }
    // DE004 = biaya admin (12 digit: 10 digit nominal + 2 digit desimal "00")
    // Contoh: "000000750000" → biayaadmin = 7500 (Rp7.500)
    // Sudah ada di $data['DE004'], tidak perlu renaming

    // ── Langkah 3: kembalikan data (sudah dinormalisasi) ──────────
    return $data;
}

/**
 * Fungsi utama: Inquiry Transfer Bank
 */
function inquiryTransferBank(array $params): array {
    $debug = [];

    // ── Step 1: Ambil Token ────────────────────────────────────
    $tokenResult = getAccessToken();
    $debug['step1_get_token'] = $tokenResult;

    if (!$tokenResult['success']) {
        return [
            'success' => false,
            'step'    => 'get_token',
            'rc'      => 'XT',
            'message' => 'Gagal mendapatkan token: ' . ($tokenResult['error'] ?? 'Unknown error'),
            'debug'   => $debug,
        ];
    }

    $accessToken = $tokenResult['token'];

    // ── Step 2: Bangun ISO Request ─────────────────────────────
    $isoRequest = buildISO8583Request($params, $accessToken);
    $debug['step2_iso_request'] = $isoRequest;

    // ── Step 3: Kirim ke Digital Server ───────────────────────
    $httpResult = sendInquiryRequest($isoRequest, $accessToken);
    $debug['step3_http_result'] = $httpResult;

    // ── Step 4: Parse Response ─────────────────────────────────
    $isoResponse = parseISO8583Response($httpResult);
    $debug['step4_iso_response'] = $isoResponse;

    // Ambil RC dari response
    // Fallback: 'XT' jika RC tidak ada di response (bukan otomatis '00' meski HTTP 200)
    $rc      = $isoResponse['RC']  ?? $isoResponse['rc'] ?? 'XT';
    $msgData = $isoResponse['MSG'] ?? $isoResponse;

    // Parsing field-field dari ISO response (hasil parseISO8583Response)
    // Setelah normalisasi: $isoResponse['RC']  = DE039 (response code)
    //                      $isoResponse['MSG'] = DE048 (tagihan/pesan)
    //                      $isoResponse['DE004'] = biaya admin
    //                      $isoResponse['DE102'] = nomor rekening konfirmasi
    $tagihan      = '';
    $namaRekening = '';
    $biayaAdmin   = 0;

    // MSG sudah dinormalisasi dari DE048 oleh parseISO8583Response()
    if (is_string($msgData)) {
        // MSG berupa string tagihan/pesan langsung (DE048)
        $tagihan = $msgData;
    } elseif (is_array($msgData)) {
        // Seharusnya tidak terjadi setelah normalisasi, tapi jaga-jaga
        $tagihan      = $msgData['DE048'] ?? $msgData['tagihan'] ?? $msgData['message'] ?? '';
        $namaRekening = $msgData['DE048'] ?? $msgData['NamaRekening'] ?? '';
        $de004Raw     = $msgData['DE004'] ?? '000000000000';
        $biayaAdmin   = strlen($de004Raw) >= 3 ? (int)substr($de004Raw, 0, -2) : 0;
        $nomorKonfirm = $msgData['DE102'] ?? $params['nomor_rekening'] ?? '';
    }

    // Biaya admin dari top-level DE004 (diisi oleh parseISO8583Response dari ISO array)
    if ($biayaAdmin === 0 && isset($isoResponse['DE004'])) {
        $de004Raw   = $isoResponse['DE004'];
        $biayaAdmin = strlen($de004Raw) >= 3 ? (int)substr($de004Raw, 0, -2) : 0; // hapus 2 digit desimal ("00")
    }

    $success = in_array($rc, ['00', '19'], true);

    // Parse nama pemilik rekening dari tagihan
    // Format DE048 response TFDANA/LLG/RTGS (dari Permata SNAP via switching):
    //   "NoRek|NamaPemilik|KodeBankBI|NamaBank"
    //   parts[0] = nomor rekening konfirmasi
    //   parts[1] = nama pemilik rekening (BENEFICIARY NAME)
    //   parts[2] = kode bank BI (mis. "014" = BCA)
    //   parts[3] = nama bank singkat (mis. "BCA")
    // Contoh: "5465389271|FLIPTECH LENTERA IP PT|014|BCA"
    $beneficiaryName = '-';
    $beneficiaryNo   = $params['nomor_rekening'] ?? '';
    $beneficiaryBankNameFromDE048 = '';
    $beneficiaryBankCodeFromDE048 = '';
    if (!empty($tagihan)) {
        $parts = explode('|', $tagihan);
        if (count($parts) >= 4) {
            // Format lengkap: NoRek|NamaPemilik|KodeBI|NamaBank
            $beneficiaryNo               = $parts[0] !== '' ? $parts[0] : $beneficiaryNo;
            $beneficiaryName             = $parts[1] !== '' ? $parts[1] : '-';
            $beneficiaryBankCodeFromDE048 = $parts[2] ?? '';  // kode BI, mis. "014"
            $beneficiaryBankNameFromDE048 = $parts[3] ?? '';  // nama bank, mis. "BCA"
        } elseif (count($parts) >= 2) {
            // Format pendek: NoRek|NamaPemilik
            $beneficiaryNo   = $parts[0] !== '' ? $parts[0] : $beneficiaryNo;
            $beneficiaryName = $parts[1] !== '' ? $parts[1] : '-';
        } elseif (count($parts) === 1 && strlen($tagihan) > 5) {
            // String nama langsung (tanpa pipe)
            $beneficiaryName = $tagihan;
        }
    }

    // Gunakan bank name/code dari DE048 response jika lebih akurat dari input form
    $bankCode = $beneficiaryBankCodeFromDE048 ?: ($params['kode_bank'] ?? '');
    $bankName = $beneficiaryBankNameFromDE048 ?: ($params['nama_bank'] ?? '');

    return [
        'success'         => $success,
        'step'            => 'inquiry',
        'rc'              => $rc,
        'message'         => $tagihan ?: ($success ? 'Inquiry berhasil' : 'Inquiry gagal'),
        'beneficiary'     => [
            'account_no'   => $beneficiaryNo,
            'account_name' => $beneficiaryName,
            'bank_code'    => $bankCode,
            'bank_name'    => $bankName,
        ],
        'biaya_admin'     => $biayaAdmin,
        'jenis_transfer'  => strtoupper($params['jenis_transfer'] ?? 'TFDANA'),
        'de048'           => buildDE048(
            $params['jenis_transfer'] ?? 'TFDANA',
            $params['kode_bank_de048'] ?? $params['kode_bank'] ?? ''
        ),
        'token_from_cache'=> $tokenResult['from_cache'] ?? false,
        'debug'           => $debug,
    ];
}

// ============================================================
// HELPER — RC / DE039 DESCRIPTION
// ============================================================

/**
 * rcDescription — terjemahkan kode RC (DE039) ISO 8583 ke deskripsi manusiawi
 *
 * Kode standar ISO 8583 + kode internal Assist Switching.
 *
 * @param  string $rc   Kode RC 2-karakter (misal "00", "03", "11", "XT")
 * @return string       Deskripsi singkat
 */
function rcDescription(string $rc): string {
    $map = [
        // ── Sukses ────────────────────────────────────────
        '00' => 'Approved / Sukses',
        // ── Tolak dari bank tujuan ─────────────────────────
        '01' => 'Refer to Card Issuer',
        '02' => 'Refer to Card Issuer (Special Condition)',
        '03' => 'Invalid Merchant / Transaksi tidak diizinkan oleh bank tujuan',
        '04' => 'Pick Up Card',
        '05' => 'Do Not Honor — transaksi ditolak bank tujuan',
        '06' => 'Error pada sistem bank tujuan',
        '07' => 'Pick Up Card (Special Condition)',
        '08' => 'Honor With Identification',
        '09' => 'Request In Progress',
        '10' => 'Approved For Partial Amount',
        '11' => 'Approved VIP — Inquiry gagal / tidak ada data (RC=11 dari switching)',
        '12' => 'Invalid Transaction',
        '13' => 'Invalid Amount',
        '14' => 'Invalid Card Number / Nomor rekening tidak valid',
        '15' => 'No Such Issuer',
        '19' => 'Re-enter Transaction',
        '20' => 'Invalid Response',
        '21' => 'No Action Taken',
        '22' => 'Suspected Malfunction',
        '25' => 'Unable to Locate Record On File',
        '30' => 'Format Error',
        '31' => 'Bank Not Supported by Switch',
        '33' => 'Expired Card',
        '34' => 'Suspected Fraud',
        '36' => 'Restricted Card',
        '38' => 'PIN Tries Exceeded',
        '39' => 'No Credit Account',
        '40' => 'Requested Function Not Supported',
        '41' => 'Lost Card',
        '43' => 'Stolen Card, Pick Up',
        '51' => 'Insufficient Funds / Saldo tidak cukup',
        '52' => 'No Checking Account',
        '53' => 'No Savings Account',
        '54' => 'Expired Card',
        '55' => 'Incorrect PIN',
        '56' => 'No Card Record',
        '57' => 'Transaction Not Permitted to Cardholder',
        '58' => 'Transaction Not Permitted to Terminal',
        '59' => 'Suspected Fraud',
        '61' => 'Exceeds Withdrawal Amount Limit',
        '62' => 'Restricted Card',
        '63' => 'Security Violation',
        '65' => 'Activity Limit Exceeded',
        '68' => 'Response Received Too Late',
        '75' => 'PIN Tries Exceeded',
        '76' => 'Unable To Locate Previous Message',
        '77' => 'Previous Message Located For A Repeat Or Reversal',
        '78' => 'Blocked — First Use',
        '80' => 'Network Not Found',
        '82' => 'No Security Module',
        '83' => 'Unable to Verify PIN',
        '84' => 'Invalid Authorization Life Cycle',
        '85' => 'Not Declined (V only)',
        '86' => 'PIN Validation Not Possible',
        '88' => 'Cryptographic Failure',
        '89' => 'Authentication Failure',
        '91' => 'Issuer or Switch is Inoperative / Bank tujuan tidak dapat dihubungi',
        '92' => 'Financial Institution Or Intermediate Network Facility Cannot Be Found',
        '93' => 'Transaction Cannot Be Completed — Violation of Law',
        '94' => 'Duplicate Transmission',
        '95' => 'Reconcile Error',
        '96' => 'System Malfunction',
        '97' => 'Reconciliation Totals Reset',
        '98' => 'MAC Error',
        '99' => 'Reserved for National Use',
        // ── Kode internal Assist Switching ─────────────────
        'XV' => 'Validasi gagal (input tidak lengkap)',
        'XT' => 'Token OAuth gagal didapat',
        'XP' => 'Gagal parsing response switching',
        'XH' => 'HTTP error saat koneksi ke switching',
        // ── Kode BI-FAST (format Uxxx dari BIFast.mod.php) ─
        'U000' => 'BI-FAST: Sukses / Transaction Accepted',
        'U100' => 'BI-FAST: Product Not Found',
        'U110' => 'BI-FAST: Payment Not Accepted',
        'U111' => 'BI-FAST: Minimum Amount Check Failed',
        'U112' => 'BI-FAST: Maximum Amount Check Failed — melebihi Rp 250 juta',
        'U115' => 'BI-FAST: Date Sent Tolerance Check Failed',
        'U121' => 'BI-FAST: Inbound Bank Not Found',
        'U124' => 'BI-FAST: Bank Code Not Found',
        'U129' => 'BI-FAST: Payee Bank Unavailable',
        'U149' => 'BI-FAST: Duplicate Transaction',
        'U155' => 'BI-FAST: Fraud Check Failed',
        'U156' => 'BI-FAST: Sanction Check Failed',
        'U181' => 'BI-FAST: Stand-In Limit Exceeded',
        'U184' => 'BI-FAST: Stand-In Insufficient Funds',
    ];
    return $map[$rc] ?? "RC={$rc} (kode tidak dikenal)";
}

// ============================================================
// PAYMENT (PAYTFDANA / PAYLLG / PAYRTGS) — FUNGSI EKSEKUSI
// ============================================================

/**
 * buildDE048Payment — bangun DE048 untuk request PAYMENT (MTI=002)
 *
 * Format:
 *   "{prefix}*1001*PAY{JENIS}~~{kodeBank}*0"
 * Contoh:
 *   TFDANA : "0601*1001*PAYTFDANA~~CENAIDJA*0"
 *   LLG    : "0201*1001*PAYLLG~~CENAIDJA*0"
 *   RTGS   : "0801*1001*PAYRTGS~~CENAIDJA*0"
 *   BIFAST : "0602*1001*PAYBIFAST~~CENAIDJA*0"
 *            kodeBank = kolom KodeBIFAST di bank_code (BIC8/BIC11, mis. "CENAIDJA")
 *
 * Part1   = kode kategori (prefix nominal — tetap diisi nominal saat PAY)
 * Part2   = kode produk internal (1001)
 * TrxCode = PAY{JENIS}~~{kodeBank}  (PAY bukan INQ)
 * *0      = margin (0 = tanpa margin tambahan)
 */
function buildDE048Payment(string $jenisTF, string $kodeBank, int $nominal = 0): string {
    $kodeBank = strtoupper(trim($kodeBank));
    if (empty($kodeBank)) $kodeBank = 'BLTRFAG';

    // Prefix nominal: sama dengan inquiry
    $prefixMap = [
        'TFDANA' => '0601',
        'LLG'    => '0201',
        'RTGS'   => '0801',
        'BIFAST' => '0602',
    ];
    $pfx = $prefixMap[strtoupper($jenisTF)] ?? '0601';

    return "{$pfx}*1001*PAY{$jenisTF}~~{$kodeBank}*0";
}

/**
 * buildISO8583PaymentRequest — bangun ISO 8583 request untuk PAYMENT (MTI=002 = DIGITAL_BANK)
 *
 * Sesuai source mbanking.controller.php → mBanking() → SendHTTPPost ke CBS.
 * CBS yang kemudian memanggil PermataSNAP::ReceiverHome (MTI=022) untuk eksekusi.
 *
 * Perbedaan utama dari Inquiry:
 *   MTI    = "002"    (bukan "010" — DIGITAL_BANK bukan DIGITAL_BANK_INQUIRY)
 *   DE003  = "211041" (bukan "231041") — DE003_TRX_PASCA_2
 *   DE004  = nominal transfer aktual (12 digit)
 *   DE039  = "00"     (sinyal konfirmasi dari nasabah)
 *   DE048  = "{pfx}*1001*PAY{JENIS}~~{kodeBank}*0"
 *
 * @param array  $params          Field-field dari form (sama dengan inquiry)
 * @param string $accessToken     Token OAuth
 * @param int    $biayaAdmin      Biaya admin dari response inquiry (untuk DE102/feeAssist)
 */
function buildISO8583PaymentRequest(array $params, string $accessToken, int $biayaAdmin = 0): array {
    $jenisTF       = strtoupper($params['jenis_transfer'] ?? 'TFDANA');
    $nomorRekening = preg_replace('/\D/', '', $params['nomor_rekening'] ?? '');
    $kodeBank      = $params['kode_bank'] ?? '';
    $nominal       = (int)preg_replace('/\D/', '', $params['nominal'] ?? '0');

    // DE004: nominal transfer dalam format 12 digit (10 digit + 2 desimal "00")
    $de004 = str_pad($nominal, 10, '0', STR_PAD_LEFT) . '00';

    // DE012: HHmm sekarang
    $de012 = date('Hi');

    // DE013: MMDD (sama dengan inquiry — sesuai source controller)
    $de013 = date('md');

    // DE037: RRN baru (harus berbeda dari inquiry — transaksi berbeda)
    $de037 = date('His') . str_pad((string)mt_rand(1, 999999), 6, '0', STR_PAD_LEFT);

    // DE039: "00" → sinyal konfirmasi nasabah (nasabah menyetujui)
    $de039 = '00';

    // DE048: PAY (bukan INQ) + kode bank Assist/SWIFT
    // BIFAST pakai kolom KodeBIFAST (BIC 11-digit); TFDANA/LLG/RTGS pakai kolom Kode
    if ($jenisTF === 'BIFAST') {
        $kodeBankDE048 = strtoupper(trim(
            $params['kode_bank_bifast'] ?? $params['kode_bank_de048'] ?? $params['kode_bank'] ?? ''
        ));
        // Guard: jika nilai masih berupa kode numerik BI (mis. "014"), auto-map ke KodeBIFAST BIC
        // Ini mencegah DE048 berisi "014" (numerik) yang ditolak Permata SNAP API
        if (preg_match('/^\d+$/', $kodeBankDE048)) {
            global $mapKodeBankBIFAST;
            $mapped = $mapKodeBankBIFAST[$kodeBankDE048] ?? '';
            if ($mapped !== '') {
                $kodeBankDE048 = $mapped;
            }
            // Jika tidak ada di map → biarkan apa adanya (switching akan kembalikan error deskriptif)
        }
    } else {
        $kodeBankDE048 = strtoupper(trim($params['kode_bank_de048'] ?? $params['kode_bank'] ?? ''));
    }
    $de048 = buildDE048Payment($jenisTF, $kodeBankDE048, $nominal);

    // DE052: PIN block (64 zero — untuk non-PIN transaction via middleware)
    $de052 = str_repeat('0', 64);

    // DE061: SIM Serial / device identifier agen
    $de061 = DE061_SIM_SERIAL;

    // DE102: nomor rekening tujuan (konfirmasi sama dengan inquiry)
    $de102 = $nomorRekening;

    // DE103: kode bank BI tujuan
    $de103 = $kodeBank;

    $msg = [
        'DE003' => '211041',   // processing code payment (DE003_TRX_PASCA_2)
        'DE004' => $de004,     // nominal transfer
        'DE012' => $de012,
        'DE013' => $de013,
        'DE037' => $de037,
        'DE039' => $de039,     // "00" = konfirmasi
        'DE044' => '0',
        'DE048' => $de048,     // PAYTFDANA / PAYLLG / PAYRTGS
        'DE052' => $de052,
        'DE061' => $de061,
        'DE102' => $de102,
        'DE103' => $de103,
    ];

    return [
        'MTI' => '002',        // DIGITAL_BANK — ReadRequest() hanya mengenal "002" untuk payment
        'MSG' => $msg,         // MTI lama "200" tidak ada handler-nya → jatuh ke else → "Request salah!!"
    ];
}

/**
 * sendPaymentRequest — kirim request payment ke switching (/mobile-digital)
 *
 * Sama persis dengan sendInquiryRequest, hanya isoRequest berbeda (MTI=002).
 * Switching meneruskan ke CBS; CBS yang eksekusi transfer via Permata SNAP.
 */
function sendPaymentRequest(array $isoRequest, string $accessToken): array {
    $cB      = json_encode($isoRequest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $deviceId = DE061_SIM_SERIAL
        ?: ($_SERVER['SERVER_ADDR'] ?? '')
        ?: (KODE_AGEN ?: md5(gethostname()));

    $postFields = http_build_query([
        'cCode'         => $cB,
        'DEVICEID'      => $deviceId,
        'PLATFORM'      => 'android',
        'VERSIAPLIKASI' => '1.2.6',
    ]);

    $headers = [
        'Authorization: Bearer ' . $accessToken,
        'Content-Type: application/x-www-form-urlencoded',
    ];

    $result = sendHttpPost(URL_DIGITAL, $postFields, $headers);
    $result['iso_request'] = $isoRequest;
    $result['body_signed'] = $postFields;
    return $result;
}

/**
 * parsePaymentResponse — parse response ISO 8583 untuk transaksi payment
 *
 * Sama dengan parseISO8583Response untuk inquiry:
 *   - Strip PHP Notice HTML sebelum JSON
 *   - Unwrap outer {"status":true,"data":"..."} wrapper
 *   - Normalisasi DE039 → RC, DE048 → MSG
 *
 * Response sukses payment biasanya mengandung:
 *   DE039 = "00"
 *   DE048 = nomor referensi / SN transaksi
 */
function parsePaymentResponse(array $httpResult): array {
    // Gunakan kembali fungsi yang sama — logika identik
    return parseISO8583Response($httpResult);
}

/**
 * paymentTransferBank — fungsi utama eksekusi transfer setelah inquiry sukses
 *
 * Flow:
 *   Step 1: Ambil token (reuse cache dari inquiry jika masih valid)
 *   Step 2: Bangun ISO 8583 MTI=002 (DIGITAL_BANK) dengan DE003=211041 (PAYTFDANA/etc.)
 *   Step 3: Kirim POST ke /mobile-digital
 *   Step 4: Parse response — RC=00 berarti transfer berhasil
 *
 * @param array $params  Harus mengandung semua field inquiry + 'nominal' wajib diisi
 * @param int   $biayaAdmin  Biaya admin dari inquiry (Rp, bukan DE004 raw)
 */
function paymentTransferBank(array $params, int $biayaAdmin = 0): array {
    $debug = [];

    // ── Validasi: nominal wajib diisi untuk payment ──────────
    $nominal = (int)preg_replace('/\D/', '', $params['nominal'] ?? '0');
    if ($nominal <= 0) {
        return [
            'success' => false,
            'step'    => 'validate',
            'rc'      => 'XV',
            'message' => 'Nominal transfer wajib diisi dan harus lebih dari 0 untuk melakukan payment.',
            'debug'   => $debug,
        ];
    }

    // ── Step 1: Ambil Token (reuse cache) ─────────────────────
    $tokenResult = getAccessToken();
    $debug['step1_get_token'] = $tokenResult;

    if (!$tokenResult['success']) {
        return [
            'success' => false,
            'step'    => 'get_token',
            'rc'      => 'XT',
            'message' => 'Gagal mendapatkan token: ' . ($tokenResult['error'] ?? 'Unknown'),
            'debug'   => $debug,
        ];
    }
    $accessToken = $tokenResult['token'];

    // ── Step 2: Bangun ISO 8583 MTI=002 (DIGITAL_BANK) ──────────
    $isoRequest = buildISO8583PaymentRequest($params, $accessToken, $biayaAdmin);
    $debug['step2_iso_request'] = $isoRequest;

    // ── Step 3: Kirim ke Digital Server ───────────────────────
    $httpResult = sendPaymentRequest($isoRequest, $accessToken);
    $debug['step3_http_result'] = $httpResult;

    // ── Step 4: Parse Response ─────────────────────────────────
    $isoResponse = parsePaymentResponse($httpResult);
    $debug['step4_iso_response'] = $isoResponse;

    $rc      = $isoResponse['RC']  ?? $isoResponse['DE039'] ?? 'XT';
    $msgRaw  = $isoResponse['MSG'] ?? $isoResponse['DE048'] ?? '';
    $success = ($rc === '00');

    // Nomor referensi / SN dari response (DE048 saat payment sukses)
    $nomorRef = is_string($msgRaw) ? $msgRaw : '';

    // DE004 response = total yang didebet (nominal + biaya)
    $de004Resp = $isoResponse['DE004'] ?? '000000000000';
    $totalDebet = strlen($de004Resp) >= 3 ? (int)substr($de004Resp, 0, -2) : 0;

    return [
        'success'         => $success,
        'step'            => 'payment',
        'rc'              => $rc,
        'message'         => $nomorRef ?: ($success ? 'Transfer berhasil diproses' : 'Transfer gagal'),
        'nomor_referensi' => $nomorRef,
        'nominal'         => $nominal,
        'biaya_admin'     => $biayaAdmin,
        'total_debet'     => $totalDebet ?: ($nominal + $biayaAdmin),
        'jenis_transfer'  => strtoupper($params['jenis_transfer'] ?? 'TFDANA'),
        'beneficiary'     => [
            'account_no'   => preg_replace('/\D/', '', $params['nomor_rekening'] ?? ''),
            'account_name' => $params['beneficiary_name']  ?? '-',
            'bank_code'    => $params['beneficiary_bank_code'] ?? ($params['kode_bank'] ?? ''),
            'bank_name'    => $params['beneficiary_bank_name'] ?? ($params['nama_bank']  ?? ''),
        ],
        'token_from_cache'=> $tokenResult['from_cache'] ?? false,
        'debug'           => $debug,
    ];
}

// ============================================================
// HANDLE POST REQUEST
// ============================================================
$result       = null;
$errorMessage = '';
$formData     = [];

// ── Handler: Inquiry ──────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'inquiry') {

    $formData = [
        'nomor_rekening'   => trim($_POST['nomor_rekening']    ?? ''),
        'kode_bank'         => trim($_POST['kode_bank']         ?? ''),  // kode numerik BI → DE103
        'kode_bank_manual'  => trim($_POST['kode_bank_manual']  ?? ''),  // override kode numerik BI
        'kode_bank_de048'   => strtoupper(trim($_POST['kode_bank_de048'] ?? '')),  // kode Assist/SWIFT → DE048 (TFDANA/LLG/RTGS)
        'kode_bank_bifast'  => strtoupper(trim($_POST['kode_bank_bifast'] ?? '')), // KodeBIFAST → DE048 BIFAST
        'nama_bank'         => trim($_POST['nama_bank']         ?? ''),
        'nominal'           => trim($_POST['nominal']           ?? '0'),
        'jenis_transfer'    => strtoupper(trim($_POST['jenis_transfer'] ?? 'TFDANA')),
    ];

    // Jika input kode bank manual diisi, gunakan sebagai kode numerik BI (DE103)
    if (!empty($formData['kode_bank_manual'])) {
        $formData['kode_bank'] = $formData['kode_bank_manual'];
    }
    // Jika kode_bank_de048 kosong, gunakan kode_bank sebagai fallback
    if (empty($formData['kode_bank_de048'])) {
        $formData['kode_bank_de048'] = $formData['kode_bank'];
    }
    // Jika kode_bank_bifast kosong, coba auto-map dari kode numerik BI
    if (empty($formData['kode_bank_bifast']) && $formData['jenis_transfer'] === 'BIFAST') {
        global $mapKodeBankBIFAST;
        $formData['kode_bank_bifast'] = $mapKodeBankBIFAST[$formData['kode_bank']] ?? $formData['kode_bank_de048'];
    }

    // Validasi input
    if (empty($formData['nomor_rekening'])) {
        $errorMessage = 'Nomor rekening tujuan wajib diisi!';
    } elseif (empty($formData['kode_bank'])) {
        $errorMessage = 'Kode bank tujuan wajib diisi!';
    } elseif (KODE_AGEN === '') {
        $errorMessage = '⚠️ KODE_AGEN tidak terdeteksi! Pastikan ada file cache snap_tf_bank_permata.cache di storage/cds/cache/ dalam direktori assist-switching_v3_pro.';
    } elseif (OAUTH_CLIENT_ID === '' && OAUTH_USERNAME === '') {
        $errorMessage = '⚠️ Kredensial OAuth belum dikonfigurasi! Buat file .assist.env dengan isi: OAUTH_CLIENT_ID, OAUTH_CLIENT_SECRET, OAUTH_USERNAME, OAUTH_PASSWORD.';
    } else {
        $result = inquiryTransferBank($formData);
    }
}

// ── Handler: Payment (eksekusi transfer setelah konfirmasi) ────
$paymentResult    = null;
$paymentError     = '';
$paymentFormData  = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'payment') {

    // Ambil semua field yang diteruskan dari form konfirmasi
    $paymentFormData = [
        'nomor_rekening'      => trim($_POST['nomor_rekening']      ?? ''),
        'kode_bank'           => trim($_POST['kode_bank']           ?? ''),
        'kode_bank_de048'     => strtoupper(trim($_POST['kode_bank_de048']  ?? '')),
        'kode_bank_bifast'    => strtoupper(trim($_POST['kode_bank_bifast'] ?? '')), // KodeBIFAST
        'nama_bank'           => trim($_POST['nama_bank']           ?? ''),
        'nominal'             => trim($_POST['nominal']             ?? '0'),
        'jenis_transfer'      => strtoupper(trim($_POST['jenis_transfer']  ?? 'TFDANA')),
        // Data beneficiary dari inquiry — diteruskan sebagai hidden field
        'beneficiary_name'    => trim($_POST['beneficiary_name']    ?? ''),
        'beneficiary_bank_code' => trim($_POST['beneficiary_bank_code'] ?? ''),
        'beneficiary_bank_name' => trim($_POST['beneficiary_bank_name'] ?? ''),
        'biaya_admin'         => (int)preg_replace('/\D/', '', $_POST['biaya_admin'] ?? '0'),
    ];

    // Validasi wajib
    $nominal = (int)preg_replace('/\D/', '', $paymentFormData['nominal']);
    // BIFAST: pastikan kode_bank_bifast berisi KodeBIFAST BIC, bukan kode numerik
    if ($paymentFormData['jenis_transfer'] === 'BIFAST') {
        $bifastVal = $paymentFormData['kode_bank_bifast'];
        // Kosong atau masih numerik → auto-map via $mapKodeBankBIFAST
        if (empty($bifastVal) || preg_match('/^\d+$/', $bifastVal)) {
            $numericKey = !empty($bifastVal) ? $bifastVal : $paymentFormData['kode_bank'];
            $paymentFormData['kode_bank_bifast'] = $mapKodeBankBIFAST[$numericKey]
                ?? $paymentFormData['kode_bank_de048']
                ?? $bifastVal;
        }
    }
    if (empty($paymentFormData['nomor_rekening'])) {
        $paymentError = 'Nomor rekening tujuan tidak boleh kosong.';
    } elseif (empty($paymentFormData['kode_bank'])) {
        $paymentError = 'Kode bank tujuan tidak boleh kosong.';
    } elseif ($nominal <= 0) {
        $paymentError = 'Nominal transfer harus lebih dari 0.';
    } elseif (KODE_AGEN === '') {
        $paymentError = '⚠️ KODE_AGEN tidak terdeteksi!';
    } elseif (OAUTH_CLIENT_ID === '' && OAUTH_USERNAME === '') {
        $paymentError = '⚠️ Kredensial OAuth belum dikonfigurasi!';
    } else {
        $paymentResult = paymentTransferBank($paymentFormData, $paymentFormData['biaya_admin']);
    }
}

// ============================================================
// DATA REFERENSI
// ============================================================
// Daftar bank: kode numerik BI (→ DE103) => nama bank
// CATATAN: kode di sini adalah kode numerik BI (sandi bank),
//          BUKAN kode Assist/SWIFT yang masuk ke DE048.
//          Untuk DE048 gunakan field kode_bank_de048 (diisi terpisah).
$daftarBank = [
    ''       => '-- Pilih Bank --',
    // ── Bank Umum Nasional (BUKU 4) ───────────────────────────────
    '008'    => 'Bank Mandiri',
    '009'    => 'BNI',
    '002'    => 'BRI',
    '014'    => 'BCA',              // kode BI 014 = BCA (bukan BPR Agro)
    '013'    => 'Bank Permata',
    '022'    => 'CIMB Niaga',
    '016'    => 'Maybank',
    '011'    => 'Danamon',
    '028'    => 'OCBC NISP',
    '200'    => 'BTN',
    '019'    => 'Panin Bank',
    '023'    => 'UOB',
    '441'    => 'Bank Mega',
    '153'    => 'Bank Muamalat',
    '147'    => 'Bank Bukopin',
    // ── BPD (Bank Pembangunan Daerah) ────────────────────────────
    // BJB: '110' = kode BPD resmi, '036' = kode lama/RTGS
    '110'    => 'BJB (Bank Jabar Banten)',
    '036'    => 'BJB (kode lama/RTGS)',

    '111'    => 'Bank DKI',
    '112'    => 'BPD Jateng',
    '113'    => 'BPD DIY',
    '114'    => 'BPD Jatim',
    '116'    => 'BPD Sumut',
    '118'    => 'BPD Sulsel',
    '119'    => 'BPD NTB',
    '120'    => 'BPD Kalbar',
    '121'    => 'BPD Kalteng',
    '122'    => 'BPD Kalsel',
    '123'    => 'BPD Kaltim',
    '131'    => 'BPD Sulut',
    '132'    => 'BPD Sulteng',
    '133'    => 'BPD Sultra',
    '076'    => 'BPD Bali',
    // ── Bank Syariah ─────────────────────────────────────────────
    '422'    => 'BSI (Bank Syariah Indonesia)',
    '426'    => 'Bank Mega Syariah',
    // ── Bank Digital & Fintech ───────────────────────────────────
    '335'    => 'BNC (Neo Commerce)',
    '503'    => 'Bank Jago',
    '506'    => 'SeaBank',
    '513'    => 'Bank Ina',
    '553'    => 'Bank DBS',
    '688'    => 'HSBC Indonesia',
    '542'    => 'Bank Saqu (Bank Jago)',
    '547'    => 'Motion Banking (MNC Bank)',
    // ── BPR / Bank Khusus ────────────────────────────────────────
    '494'    => 'Bank Rakyat Agro (BLTRFAG)',  // kode BI 494, kode Assist: BLTRFAG
    '490'    => 'BSB (Bank Syariah Bukopin)',
];

// Mapping kode numerik BI → kode Assist/SWIFT (untuk DE048 TFDANA/LLG/RTGS)
// Digunakan untuk auto-fill field kode_bank_de048 via JavaScript
$mapKodeBankDE048 = [
    '008'    => 'BMRIIDJA',   // Bank Mandiri
    '009'    => 'BNINIDJA',   // BNI
    '002'    => 'BRINIDJA',   // BRI
    '014'    => 'CENAIDJA',   // BCA
    '013'    => 'BBBAIDJA',   // Bank Permata
    '022'    => 'BIARINDJA',  // CIMB Niaga
    '016'    => 'MBBEIDJA',   // Maybank
    '011'    => 'BDINIDJA',   // Danamon
    '028'    => 'NISPIDJA',   // OCBC NISP
    '200'    => 'BTANIDJA',   // BTN
    '019'    => 'PINBIDJA',   // Panin Bank
    '023'    => 'UOVBIDJA',   // UOB
    '153'    => 'MUABIDJA',   // Muamalat
    '147'    => 'BBUKIDJA',   // Bukopin
    '422'    => 'BSYIIDJA',   // BSI
    '494'    => 'BLTRFAG',    // Bank Rakyat Agro
    '503'    => 'ARTO',       // Bank Jago
    '506'    => 'SEAMIDJA',   // SeaBank
    '335'    => 'BNCOIDJA',   // BNC
    '110'    => 'BJBKIDJA',   // BJB (kode BPD resmi)
    '036'    => 'BJBKIDJA',   // BJB (kode lama/RTGS — SWIFT sama)
];

// Mapping kode numerik BI → KodeBIFAST (BIC 11-digit) untuk DE048 BIFAST
// Kolom KodeBIFAST di tabel bank_code — format BIC11: {BANK}{CC}{LOC}{BRANCH}
// mis. CENAIDJA = CEN(BCA) A(Asia) IDJ(Indonesia) A(primary) — BIC8 tanpa branch suffix
// CATATAN: KodeBIFAST bisa BIC8 (8 karakter) ATAU BIC11 (11 karakter, suffix XXX = HO)
// Nilai di bawah dikonfirmasi dari kolom KodeBIFAST tabel bank_code production.
$mapKodeBankBIFAST = [
    '008'    => 'BMRIIDJA',    // Bank Mandiri
    '009'    => 'BNINIDJA',    // BNI
    '002'    => 'BRINIDJA',    // BRI
    '014'    => 'CENAIDJA',    // BCA — dikonfirmasi dari production DB (BIC8, bukan CENAIDJAXXX)
    '013'    => 'BBBAIDJA',    // Bank Permata
    '022'    => 'BIARINDJA',   // CIMB Niaga
    '016'    => 'MBBEIDJA',    // Maybank
    '011'    => 'BDINIDJA',    // Danamon
    '028'    => 'NISPIDJA',    // OCBC NISP
    '200'    => 'BTANIDJA',    // BTN
    '019'    => 'PINBIDJA',    // Panin Bank
    '023'    => 'UOVBIDJA',    // UOB
    '153'    => 'MUABIDJA',    // Muamalat
    '147'    => 'BBUKIDJA',    // Bukopin
    '422'    => 'BSYIIDJA',    // BSI
    '503'    => 'ARTOIDJA',    // Bank Jago
    '506'    => 'SEAMIDJA',    // SeaBank
    '335'    => 'BNCOIDJA',    // BNC
    '110'    => 'BJBKIDJA',    // BJB
    '036'    => 'BJBKIDJA',    // BJB (kode lama)
    '441'    => 'MEGAIDJA',    // Bank Mega
    '111'    => 'BDKIIDJA',    // Bank DKI
    '114'    => 'BJTMIDJA',    // BPD Jatim
    '112'    => 'BCENIDJA',    // BPD Jateng
];
// CATATAN: KodeBIFAST di atas adalah nilai umum/default.
// Nilai aktual yang dipakai harus dari kolom KodeBIFAST di tabel bank_code production.
// Jika inquiry BI-FAST gagal, cek kolom KodeBIFAST via:
//   SELECT Kode, Nama, KodeBIFAST FROM bank_code WHERE Kode='...'

// Cek apakah konfigurasi lengkap
$isConfigured = (KODE_AGEN !== '' && OAUTH_CLIENT_ID !== '' && OAUTH_USERNAME !== '');

// Siapkan daftar bank untuk JavaScript (tanpa entry kosong)
$daftarBankJs  = array_filter($daftarBank,  fn($v) => $v !== '-- Pilih Bank --');
$mapDE048Js    = $mapKodeBankDE048;
$mapBIFASTJs   = $mapKodeBankBIFAST;
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inquiry Transfer Bank — SIS/Assist Middleware</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #003d82 0%, #006eff 100%);
            min-height: 100vh;
            padding: 20px;
        }
        .container { max-width: 960px; margin: 0 auto; }

        /* ── Header ── */
        .page-header { text-align: center; color: #fff; margin-bottom: 24px; }
        .header-badge {
            display: inline-flex; align-items: center; gap: 10px;
            background: rgba(255,255,255,.13);
            backdrop-filter: blur(6px);
            border: 1px solid rgba(255,255,255,.2);
            border-radius: 50px;
            padding: 9px 22px;
            margin-bottom: 12px;
        }
        .badge-permata {
            background: #003d82; color: #fff;
            border-radius: 8px; padding: 3px 9px;
            font-size: .72rem; font-weight: 800; letter-spacing: 1px;
        }
        .badge-assist {
            background: #f59e0b; color: #1c1917;
            border-radius: 8px; padding: 3px 9px;
            font-size: .72rem; font-weight: 800; letter-spacing: 1px;
        }
        .badge-sep { color: rgba(255,255,255,.5); }
        .page-header h1 { font-size: 1.85rem; font-weight: 800; }
        .page-header p  { font-size: .93rem; opacity: .82; margin-top: 5px; }

        /* ── Cards ── */
        .card {
            background: #fff;
            border-radius: 16px;
            box-shadow: 0 8px 36px rgba(0,0,0,.18);
            overflow: hidden;
            margin-bottom: 20px;
        }
        .card-header {
            background: linear-gradient(90deg, #003d82, #0057c2);
            color: #fff;
            padding: 15px 22px;
            display: flex; align-items: center; gap: 10px;
            font-size: .97rem; font-weight: 700;
        }
        .card-header .ch-icon {
            width: 30px; height: 30px;
            background: rgba(255,255,255,.2);
            border-radius: 7px;
            display: flex; align-items: center; justify-content: center;
            font-size: 17px;
        }
        .card-header .ch-badge {
            margin-left: auto;
            background: rgba(255,255,255,.18);
            border-radius: 6px;
            padding: 2px 9px;
            font-size: .72rem;
            letter-spacing: .4px;
        }
        .card-header .ch-badge.status-ready { background: #16a34a; color: #fff; font-weight: 800; }
        .card-header .ch-badge.status-warn  { background: #d97706; color: #fff; font-weight: 800; }
        .card-header .ch-badge.status-error { background: #dc2626; color: #fff; font-weight: 800; }
        .card-body { padding: 24px; }

        /* ── Config Info ── */
        .config-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 10px;
            margin-bottom: 18px;
        }
        @media (max-width: 640px) { .config-grid { grid-template-columns: 1fr 1fr; } }
        .cfg-item {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            padding: 10px 12px;
        }
        .cfg-item .cfg-label { font-size: .72rem; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: .5px; }
        .cfg-item .cfg-value { font-size: .88rem; font-weight: 600; color: #1e293b; margin-top: 3px; font-family: monospace; }
        .cfg-item .cfg-value.ok   { color: #059669; }
        .cfg-item .cfg-value.warn { color: #d97706; }
        .cfg-item .cfg-value.err  { color: #dc2626; }

        /* ── Auto-Detection Panel ── */
        .det-panel {
            border-radius: 14px;
            overflow: hidden;
            margin-bottom: 18px;
            border: 1.5px solid #e2e8f0;
        }
        .det-panel-header {
            display: flex; align-items: center; gap: 10px;
            padding: 11px 16px;
            font-weight: 800; font-size: .82rem;
            letter-spacing: .3px;
        }
        .det-panel-header.ready  { background: #dcfce7; color: #14532d; border-bottom: 1.5px solid #bbf7d0; }
        .det-panel-header.warn   { background: #fef9c3; color: #713f12; border-bottom: 1.5px solid #fde68a; }
        .det-panel-header.error  { background: #fee2e2; color: #7f1d1d; border-bottom: 1.5px solid #fca5a5; }
        .det-badge {
            margin-left: auto;
            border-radius: 20px; padding: 2px 11px;
            font-size: .7rem; font-weight: 800; letter-spacing: .5px;
        }
        .det-badge.ready { background: #16a34a; color: #fff; }
        .det-badge.warn  { background: #d97706; color: #fff; }
        .det-badge.error { background: #dc2626; color: #fff; }
        .det-rows { background: #fff; }
        .det-row {
            display: grid;
            grid-template-columns: 22px 160px 1fr auto;
            align-items: center;
            gap: 8px;
            padding: 8px 16px;
            border-bottom: 1px solid #f1f5f9;
            font-size: .8rem;
        }
        .det-row:last-child { border-bottom: none; }
        .det-row:hover { background: #f8fafc; }
        .det-icon { font-size: 14px; text-align: center; }
        .det-key  { color: #64748b; font-family: monospace; font-size: .76rem; }
        .det-val  { color: #1e293b; font-weight: 600; word-break: break-all; }
        .det-val.ok   { color: #059669; }
        .det-val.warn { color: #d97706; }
        .det-val.err  { color: #dc2626; }
        .det-pill {
            font-size: .65rem; font-weight: 700; border-radius: 10px;
            padding: 1px 8px; white-space: nowrap;
        }
        .det-pill.ok   { background: #dcfce7; color: #14532d; }
        .det-pill.warn { background: #fef9c3; color: #713f12; }
        .det-pill.err  { background: #fee2e2; color: #991b1b; }
        .det-pill.info { background: #dbeafe; color: #1e3a8a; }
        .det-section-label {
            padding: 5px 16px 3px;
            font-size: .67rem; font-weight: 800; color: #94a3b8;
            text-transform: uppercase; letter-spacing: .8px;
            background: #f8fafc; border-bottom: 1px solid #f1f5f9;
        }

        /* ── Alert ── */
        .alert {
            border-radius: 10px; padding: 13px 17px;
            margin-bottom: 16px; font-size: .9rem;
            display: flex; align-items: flex-start; gap: 10px;
        }
        .alert-error   { background: #fef2f2; border: 1px solid #fca5a5; color: #7f1d1d; }
        .alert-warning { background: #fffbeb; border: 1px solid #fcd34d; color: #78350f; }
        .alert-info    { background: #eff6ff; border: 1px solid #93c5fd; color: #1e3a8a; }
        .alert .al-ic  { font-size: 17px; flex-shrink: 0; }

        /* ── Form ── */
        .section-title {
            font-size: .75rem; font-weight: 700; color: #64748b;
            text-transform: uppercase; letter-spacing: 1px;
            display: flex; align-items: center; gap: 8px;
            margin: 18px 0 12px;
        }
        .section-title::after { content: ''; flex: 1; height: 1px; background: #e2e8f0; }
        .form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
        @media (max-width: 580px) { .form-grid { grid-template-columns: 1fr; } }
        .form-group { display: flex; flex-direction: column; gap: 5px; }
        .form-group.full { grid-column: 1 / -1; }
        .form-group label {
            font-size: .82rem; font-weight: 700; color: #374151;
            text-transform: uppercase; letter-spacing: .4px;
        }
        .form-group label .req { color: #ef4444; }
        .form-group label .opt { color: #9ca3af; font-weight: 400; font-size: .77rem; text-transform: none; }
        .form-group input,
        .form-group select {
            border: 1.5px solid #d1d5db;
            border-radius: 8px;
            padding: 9px 13px;
            font-size: .92rem;
            color: #111827;
            outline: none;
            transition: border-color .2s, box-shadow .2s;
        }
        .form-group input:focus,
        .form-group select:focus {
            border-color: #3b82f6;
            box-shadow: 0 0 0 3px rgba(59,130,246,.13);
        }
        .field-hint { font-size: .75rem; color: #6b7280; }

        /* ── Transfer Type Tabs ── */
        .type-tabs { display: flex; gap: 10px; flex-wrap: wrap; }
        .type-tab {
            flex: 1; min-width: 90px;
            padding: 10px 8px;
            border: 2px solid #e5e7eb;
            border-radius: 10px;
            text-align: center;
            cursor: pointer;
            background: #f9fafb;
            transition: all .2s;
        }
        .type-tab input[type=radio] { display: none; }
        .type-tab .ti  { font-size: 20px; display: block; }
        .type-tab .tl  { font-size: .78rem; font-weight: 700; color: #374151; display: block; }
        .type-tab .td  { font-size: .69rem; color: #6b7280; display: block; }
        .type-tab .tde048 { font-size: .65rem; color: #9ca3af; display: block; font-family: monospace; }
        .type-tab:has(input:checked), .type-tab.active {
            border-color: #2563eb; background: #eff6ff;
        }
        .type-tab:has(input:checked) .tl, .type-tab.active .tl { color: #1d4ed8; }

        /* ── Submit Button ── */
        .btn-submit {
            width: 100%; padding: 13px;
            background: linear-gradient(135deg, #1d4ed8, #2563eb);
            color: #fff; border: none; border-radius: 10px;
            font-size: .97rem; font-weight: 700; cursor: pointer;
            transition: box-shadow .2s; margin-top: 6px;
            display: flex; align-items: center; justify-content: center; gap: 8px;
        }
        .btn-submit:hover { box-shadow: 0 4px 20px rgba(29,78,216,.4); }
        .btn-submit:disabled { opacity: .65; cursor: not-allowed; }

        /* ── Result ── */
        .result-wrap { margin-top: 20px; }
        .result-header {
            border-radius: 12px 12px 0 0; padding: 14px 20px;
            display: flex; align-items: center; gap: 10px;
            font-weight: 800; color: #fff;
        }
        .result-header.ok   { background: linear-gradient(90deg, #047857, #10b981); }
        .result-header.fail { background: linear-gradient(90deg, #b91c1c, #dc2626); }
        .result-body {
            background: #fff; border: 1px solid #e5e7eb;
            border-top: none; border-radius: 0 0 12px 12px; padding: 22px;
        }
        .result-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 16px; }
        @media (max-width: 600px) { .result-grid { grid-template-columns: 1fr 1fr; } }
        .ri label {
            font-size: .71rem; font-weight: 700; color: #6b7280;
            text-transform: uppercase; letter-spacing: .5px; display: block;
        }
        .ri .val { font-size: .95rem; font-weight: 600; color: #111827; margin-top: 3px; word-break: break-all; }
        .ri .val.hl { color: #059669; font-size: 1.08rem; }
        .ri .val.code { font-family: monospace; font-size: .82rem; }
        .section-sep { grid-column: 1 / -1; border: none; border-top: 1px dashed #e5e7eb; }

        /* ── Debug ── */
        .debug-wrap { margin-top: 14px; }
        .debug-btn {
            width: 100%; background: #f1f5f9; border: 1px solid #cbd5e1;
            border-radius: 8px; padding: 9px 14px;
            font-size: .83rem; font-weight: 600; color: #475569;
            cursor: pointer; text-align: left;
            display: flex; justify-content: space-between;
        }
        .debug-content {
            display: none; background: #0f172a; color: #94a3b8;
            padding: 16px; border-radius: 0 0 8px 8px;
            font-family: monospace; font-size: .76rem;
            white-space: pre-wrap; word-break: break-all;
            max-height: 520px; overflow-y: auto;
        }
        .debug-content.show { display: block; }
        .dc { color: #38bdf8; }
        .de { color: #fbbf24; }   /* debug warning/error = amber */

        /* ── Spinner ── */
        .spinner {
            display: none; width: 18px; height: 18px;
            border: 3px solid rgba(255,255,255,.3);
            border-top-color: #fff; border-radius: 50%;
            animation: spin .7s linear infinite;
        }
        @keyframes spin { to { transform: rotate(360deg); } }

        /* ── Konfirmasi Payment ── */
        .confirm-box {
            background: #fff;
            border-radius: 16px;
            box-shadow: 0 8px 36px rgba(0,0,0,.18);
            overflow: hidden;
            margin-bottom: 20px;
        }
        .confirm-header {
            background: linear-gradient(90deg, #b45309, #f59e0b);
            color: #fff;
            padding: 15px 22px;
            display: flex; align-items: center; gap: 10px;
            font-size: 1rem; font-weight: 800;
        }
        .confirm-header .ch-icon {
            width: 32px; height: 32px;
            background: rgba(255,255,255,.22);
            border-radius: 8px;
            display: flex; align-items: center; justify-content: center;
            font-size: 18px;
        }
        .confirm-body { padding: 24px; }
        .confirm-summary {
            background: #fffbeb;
            border: 1.5px solid #fcd34d;
            border-radius: 12px;
            padding: 18px 20px;
            margin-bottom: 18px;
        }
        .confirm-summary .cs-row {
            display: flex; justify-content: space-between;
            align-items: center;
            padding: 6px 0;
            border-bottom: 1px solid #fef3c7;
            font-size: .9rem;
        }
        .confirm-summary .cs-row:last-child { border-bottom: none; }
        .confirm-summary .cs-label { color: #78350f; font-weight: 600; }
        .confirm-summary .cs-val   { color: #1c1917; font-weight: 700; font-family: monospace; }
        .confirm-summary .cs-val.big {
            font-size: 1.15rem; color: #b45309;
        }
        .confirm-warning {
            background: #fef2f2;
            border: 1.5px solid #fca5a5;
            border-radius: 10px;
            padding: 12px 16px;
            margin-bottom: 18px;
            font-size: .85rem;
            color: #991b1b;
            display: flex; gap: 10px; align-items: flex-start;
        }
        .confirm-warning .cw-ic { font-size: 1.2rem; flex-shrink: 0; margin-top: 1px; }
        .confirm-btn-row {
            display: flex; gap: 14px;
        }
        @media (max-width: 480px) { .confirm-btn-row { flex-direction: column; } }
        .btn-cancel {
            flex: 1;
            padding: 13px;
            background: #f1f5f9;
            color: #374151;
            border: 2px solid #e2e8f0;
            border-radius: 10px;
            font-size: .95rem;
            font-weight: 700;
            cursor: pointer;
            transition: background .15s;
        }
        .btn-cancel:hover { background: #e2e8f0; }
        .btn-pay {
            flex: 2;
            padding: 13px;
            background: linear-gradient(135deg, #b45309, #f59e0b);
            color: #fff;
            border: none;
            border-radius: 10px;
            font-size: .95rem;
            font-weight: 800;
            cursor: pointer;
            display: flex; align-items: center; justify-content: center; gap: 8px;
            transition: opacity .15s;
        }
        .btn-pay:hover   { opacity: .9; }
        .btn-pay:disabled { opacity: .55; cursor: not-allowed; }

        /* ── Payment Result ── */
        .pay-result-wrap { margin-top: 20px; }
        .pay-result-header {
            padding: 16px 22px;
            font-size: 1.05rem; font-weight: 800;
            border-radius: 16px 16px 0 0;
            display: flex; align-items: center;
        }
        .pay-result-header.ok   { background: linear-gradient(90deg, #047857, #10b981); color: #fff; }
        .pay-result-header.fail { background: linear-gradient(90deg, #b91c1c, #dc2626); color: #fff; }
        .pay-result-body {
            background: #fff;
            border: 1.5px solid #e2e8f0;
            border-top: none;
            border-radius: 0 0 16px 16px;
            padding: 24px;
            box-shadow: 0 8px 36px rgba(0,0,0,.12);
        }
        .pay-receipt {
            background: #f0fdf4;
            border: 1.5px solid #86efac;
            border-radius: 12px;
            padding: 20px 22px;
            margin-bottom: 16px;
        }
        .pay-receipt .pr-row {
            display: flex; justify-content: space-between;
            padding: 7px 0;
            border-bottom: 1px dashed #bbf7d0;
            font-size: .9rem;
        }
        .pay-receipt .pr-row:last-child { border-bottom: none; }
        .pay-receipt .pr-label { color: #166534; font-weight: 600; }
        .pay-receipt .pr-val   { color: #14532d; font-weight: 700; font-family: monospace; }
        .pay-receipt .pr-val.ref {
            background: #dcfce7; padding: 2px 8px; border-radius: 5px;
            font-size: .85rem; letter-spacing: .3px;
        }
        .pay-receipt .pr-val.amount { font-size: 1.1rem; color: #047857; }

        /* ── Footer ── */
        .footer { text-align: center; color: rgba(255,255,255,.55); font-size: .8rem; padding: 18px 0 8px; }
    </style>
</head>
<body>
<div class="container">

    <!-- ── Header ── -->
    <div class="page-header">
        <div class="header-badge">
            <span class="badge-permata">PERMATA</span>
            <span class="badge-sep">via</span>
            <span class="badge-assist">ASSIST</span>
            <span style="color:rgba(255,255,255,.65); font-size:.82rem;">Switching v3 Pro</span>
        </div>
        <h1>🏦 Inquiry Transfer Bank</h1>
        <p>Cek rekening tujuan via SIS/Assist Digital Switching Middleware (MTI=010, INQTFDANA / INQLLG / INQRTGS)</p>
    </div>

    <!-- ── Config Status ── -->
    <div class="card">
        <div class="card-header">
            <div class="ch-icon">⚙️</div>
            Status Auto-Detection &amp; Konfigurasi
            <?php
                $totalOk  = 0; $totalErr = 0; $totalWarn = 0;
                // hitung status kritis
                if (KODE_AGEN !== '')       $totalOk++; else $totalErr++;
                if (OAUTH_CLIENT_ID !== '') $totalOk++; else $totalErr++;
                if (OAUTH_USERNAME !== '')  $totalOk++; else $totalErr++;
                if ($_ASSIST_ROOT !== '')   $totalOk++; else $totalWarn++;
                if ($_ASSIST_BPR_ROOT !== '') $totalOk++; else $totalWarn++;
                $overallClass = $totalErr > 0 ? 'error' : ($totalWarn > 0 ? 'warn' : 'ready');
                $overallLabel = $totalErr > 0 ? '❌ Belum Siap' : ($totalWarn > 0 ? '⚠️ Sebagian' : '✅ Siap');
            ?>
            <span class="ch-badge status-<?= $overallClass ?>"><?= $overallLabel ?></span>
        </div>
        <div class="card-body" style="padding:18px 24px;">

        <?php
        // ── Tentukan status tiap komponen ────────────────────────────
        $assistRootOk  = $_ASSIST_ROOT !== '';
        $bprRootOk     = $_ASSIST_BPR_ROOT !== '';
        $localCfgOk    = !empty($_LOCAL_CONFIG);
        $urlTokenOk    = !empty($_LOCAL_CONFIG['_URL_GET_TOKEN_']);
        $urlDigitalOk  = !empty($_LOCAL_CONFIG['_URL_SW_PRO_']);
        $kodeAgenOk    = KODE_AGEN !== '';
        $mftfiOk       = !empty($_CACHE_TF['mftfi'] ?? null);
        $cacheFileOk   = file_exists(CACHE_FILE_TF);
        $oauthIdOk     = OAUTH_CLIENT_ID !== '';
        $oauthSecOk    = OAUTH_CLIENT_SECRET !== '';
        $oauthUserOk   = OAUTH_USERNAME !== '';
        $oauthPassOk   = OAUTH_PASSWORD !== '';
        $corpIdOk      = OAUTH_CORPORATE_ID !== '';
        $rsaKeyOk      = OAUTH_TOKEN_PRIVATE !== '';
        $de061Ok       = DE061_SIM_SERIAL !== '';
        $envFileOk     = !empty($_BPR_ENV['_env_file'] ?? ($_ASSIST_ENV['_env_file'] ?? ''));
        $oauthSrc      = $_DETECTION_LOG['oauth_source'] ?? '';

        // ── helper render satu baris ─────────────────────────────────
        $row = function(string $icon, string $key, string $val, string $cls, string $pill, string $pillCls) {
            echo '<div class="det-row">';
            echo '<span class="det-icon">' . $icon . '</span>';
            echo '<span class="det-key">' . htmlspecialchars($key) . '</span>';
            echo '<span class="det-val ' . $cls . '">' . htmlspecialchars($val) . '</span>';
            echo '<span class="det-pill ' . $pillCls . '">' . $pill . '</span>';
            echo '</div>';
        };

        // ── Blok 1: Path Root ─────────────────────────────────────────
        $panelCls1 = ($assistRootOk && $bprRootOk) ? 'ready' : ($assistRootOk || $bprRootOk ? 'warn' : 'error');
        ?>

        <div class="det-panel">
            <div class="det-panel-header <?= $panelCls1 ?>">
                📂 Path Root
                <span class="det-badge <?= $panelCls1 ?>"><?= $panelCls1 === 'ready' ? 'READY' : ($panelCls1 === 'warn' ? 'SEBAGIAN' : 'TIDAK DITEMUKAN') ?></span>
            </div>
            <div class="det-rows">
                <div class="det-section-label">assist-switching_v3_pro</div>
                <?php $row(
                    $assistRootOk ? '✅' : '❌',
                    'assist_root',
                    $assistRootOk ? $_ASSIST_ROOT : 'Tidak ditemukan',
                    $assistRootOk ? 'ok' : 'err',
                    $assistRootOk ? 'FOUND' : 'MISSING',
                    $assistRootOk ? 'ok' : 'err'
                ); ?>
                <?php $row(
                    $localCfgOk ? '✅' : '⚠️',
                    'local_config.php',
                    $localCfgOk ? 'Terbaca (' . count($_LOCAL_CONFIG) . ' keys)' : 'Tidak ditemukan — URL pakai default',
                    $localCfgOk ? 'ok' : 'warn',
                    $localCfgOk ? 'OK' : 'DEFAULT',
                    $localCfgOk ? 'ok' : 'warn'
                ); ?>
                <?php $row(
                    $cacheFileOk ? '✅' : '❌',
                    'cache snap_tf_bank_permata',
                    $cacheFileOk ? basename(CACHE_FILE_TF) : 'Belum ada — KODE_AGEN tidak dapat dibaca',
                    $cacheFileOk ? 'ok' : 'err',
                    $cacheFileOk ? 'ADA' : 'MISSING',
                    $cacheFileOk ? 'ok' : 'err'
                ); ?>

                <div class="det-section-label">assist-bpr.net</div>
                <?php $row(
                    $bprRootOk ? '✅' : '⚠️',
                    'assist_bpr_root',
                    $bprRootOk ? $_ASSIST_BPR_ROOT : 'Tidak ditemukan — fallback ke .assist.env',
                    $bprRootOk ? 'ok' : 'warn',
                    $bprRootOk ? 'FOUND' : 'MISSING',
                    $bprRootOk ? 'ok' : 'warn'
                ); ?>
                <?php $row(
                    $envFileOk ? '✅' : '⚠️',
                    'env file aktif',
                    $envFileOk
                        ? basename($_BPR_ENV['_env_file'] ?? ($_ASSIST_ENV['_env_file'] ?? '-'))
                        : 'Tidak ada — isi manual via .assist.env',
                    $envFileOk ? 'ok' : 'warn',
                    $envFileOk ? 'LOADED' : 'NONE',
                    $envFileOk ? 'ok' : 'warn'
                ); ?>
            </div>
        </div>

        <?php
        // ── Blok 2: Nilai Agen ────────────────────────────────────────
        $panelCls2 = ($kodeAgenOk && $mftfiOk) ? 'ready' : ($kodeAgenOk || $mftfiOk ? 'warn' : 'error');
        ?>
        <div class="det-panel">
            <div class="det-panel-header <?= $panelCls2 ?>">
                🏦 Data Agen
                <span class="det-badge <?= $panelCls2 ?>"><?= $kodeAgenOk ? 'TERDETEKSI' : 'BELUM ADA' ?></span>
            </div>
            <div class="det-rows">
                <?php $row(
                    $kodeAgenOk ? '✅' : '❌',
                    'KODE_AGEN',
                    $kodeAgenOk ? KODE_AGEN : 'Tidak terdeteksi — butuh cache snap_tf_bank_permata.cache',
                    $kodeAgenOk ? 'ok' : 'err',
                    $kodeAgenOk ? 'AUTO' : 'MISSING',
                    $kodeAgenOk ? 'ok' : 'err'
                ); ?>
                <?php $row(
                    $mftfiOk ? '✅' : '⚠️',
                    'MFTFI',
                    MFTFI !== '' ? MFTFI . ($mftfiOk ? ' (dari cache)' : ' (default)') : '—',
                    $mftfiOk ? 'ok' : 'warn',
                    $mftfiOk ? 'AUTO' : 'DEFAULT',
                    $mftfiOk ? 'ok' : 'warn'
                ); ?>
                <?php $row(
                    $corpIdOk ? '✅' : '⚠️',
                    'OAUTH_CORPORATE_ID',
                    $corpIdOk ? OAUTH_CORPORATE_ID : 'Kosong (opsional)',
                    $corpIdOk ? 'ok' : 'warn',
                    $corpIdOk ? 'AUTO' : 'OPSIONAL',
                    $corpIdOk ? 'ok' : 'info'
                ); ?>
            </div>
        </div>

        <?php
        // ── Blok 3: OAuth Credentials ────────────────────────────────
        $sertOk    = OAUTH_SERTIFIKAT !== '';
        $panelCls3 = ($oauthIdOk && $oauthSecOk && $oauthUserOk && $sertOk) ? 'ready'
                   : ($oauthIdOk || $oauthUserOk ? 'warn' : 'error');
        $srcLabel  = '';
        if (!empty($_BPR_ENV['_env_file']))        $srcLabel = 'bpr-env: ' . basename($_BPR_ENV['_env_file']);
        elseif (!empty($_ASSIST_ENV['_env_file'])) $srcLabel = '.assist.env';
        ?>
        <div class="det-panel">
            <div class="det-panel-header <?= $panelCls3 ?>">
                🔑 OAuth Credentials
                <?php if ($srcLabel): ?>
                <span style="font-size:.72rem;opacity:.75;margin-left:4px;">← <?= htmlspecialchars($srcLabel) ?></span>
                <?php endif; ?>
                <span class="det-badge <?= $panelCls3 ?>"><?= $panelCls3 === 'ready' ? 'LENGKAP' : ($panelCls3 === 'warn' ? 'SEBAGIAN' : 'KOSONG') ?></span>
            </div>
            <div class="det-rows">
                <?php $row(
                    $oauthIdOk ? '✅' : '❌',
                    'OAUTH_CLIENT_ID (SSO)',
                    $oauthIdOk ? OAUTH_CLIENT_ID . ' (kode BPR — untuk OAuth2 SSO saja)' : 'Belum diisi',
                    $oauthIdOk ? 'ok' : 'err',
                    $oauthIdOk ? 'OK' : 'MISSING',
                    $oauthIdOk ? 'ok' : 'err'
                ); ?>
                <?php
                // ── OAUTH_SERTIFIKAT — field paling kritis untuk assist-switching ──
                $sertDisplay = OAUTH_SERTIFIKAT ?: '⚠️ KOSONG → fallback ke OAUTH_CLIENT_ID (kemungkinan SALAH!)';
                $row(
                    $sertOk ? '✅' : '⚠️',
                    'OAUTH_SERTIFIKAT ★',
                    $sertDisplay,
                    $sertOk ? 'ok' : 'warn',
                    $sertOk ? 'DIPAKAI' : 'BUTUH ISI',
                    $sertOk ? 'ok' : 'err'
                );
                if (!$sertOk): ?>
                <div style="grid-column:1/-1;background:#451a03;border:1px solid #f59e0b;border-radius:6px;padding:8px 12px;font-size:.78rem;color:#fde68a;line-height:1.6;">
                    ★ <strong>OAUTH_SERTIFIKAT belum diisi!</strong> Ini adalah <em>KodeSertifikat</em> yang
                    terdaftar di tabel <code>sertifikat</code> di DB <code>db.auth.access.sis1.net</code>.
                    Nilainya berbeda dari <code>auth_client_id</code>. Minta ke admin SIS, lalu tambahkan
                    ke file <code>.assist.env</code> (production):<br>
                    <code>OAUTH_SERTIFIKAT=nilai_dari_admin_SIS</code>
                </div>
                <?php endif; ?>
                <?php $row(
                    $oauthSecOk ? '✅' : '❌',
                    'OAUTH_CLIENT_SECRET',
                    $oauthSecOk ? substr(OAUTH_CLIENT_SECRET, 0, 8) . str_repeat('•', 12) : 'Belum diisi',
                    $oauthSecOk ? 'ok' : 'err',
                    $oauthSecOk ? 'OK' : 'MISSING',
                    $oauthSecOk ? 'ok' : 'err'
                ); ?>
                <?php $row(
                    $oauthUserOk ? '✅' : '❌',
                    'OAUTH_USERNAME',
                    $oauthUserOk ? OAUTH_USERNAME : 'Belum diisi',
                    $oauthUserOk ? 'ok' : 'err',
                    $oauthUserOk ? 'OK' : 'MISSING',
                    $oauthUserOk ? 'ok' : 'err'
                ); ?>
                <?php $row(
                    $oauthPassOk ? '✅' : '❌',
                    'OAUTH_PASSWORD',
                    $oauthPassOk ? str_repeat('•', 8) : 'Belum diisi',
                    $oauthPassOk ? 'ok' : 'err',
                    $oauthPassOk ? 'OK' : 'MISSING',
                    $oauthPassOk ? 'ok' : 'err'
                ); ?>
                <?php $row(
                    $de061Ok ? '✅' : '⚠️',
                    'DE061_SIM_SERIAL',
                    $de061Ok ? DE061_SIM_SERIAL : 'Kosong (opsional)',
                    $de061Ok ? 'ok' : 'warn',
                    $de061Ok ? 'OK' : 'OPSIONAL',
                    $de061Ok ? 'ok' : 'info'
                ); ?>
                <?php $row(
                    $rsaKeyOk ? '✅' : '⚠️',
                    'OAUTH_TOKEN_PRIVATE',
                    $rsaKeyOk ? 'RSA key tersedia (' . strlen(OAUTH_TOKEN_PRIVATE) . ' chars)' : 'Tidak ada (opsional)',
                    $rsaKeyOk ? 'ok' : 'warn',
                    $rsaKeyOk ? 'RSA' : 'OPSIONAL',
                    $rsaKeyOk ? 'ok' : 'info'
                ); ?>
            </div>
        </div>

        <?php
        // ── Blok 4: Endpoint URL ──────────────────────────────────────
        $panelCls4 = 'ready';
        ?>
        <div class="det-panel">
            <div class="det-panel-header <?= $panelCls4 ?>">
                🌐 Endpoint &amp; Identity
                <span class="det-badge <?= $panelCls4 ?>">READY</span>
            </div>
            <div class="det-rows">
                <?php $row(
                    '✅', 'URL_GET_TOKEN',
                    URL_GET_TOKEN,
                    'ok', $urlTokenOk ? 'CONFIG' : 'DEFAULT', $urlTokenOk ? 'ok' : 'info'
                ); ?>
                <?php $row(
                    '✅', 'URL_DIGITAL (TF)',
                    URL_DIGITAL,
                    'ok', $urlDigitalOk ? 'CONFIG' : 'DEFAULT', $urlDigitalOk ? 'ok' : 'info'
                ); ?>

            </div>
        </div>

        <?php if (!$isConfigured): ?>
        <div class="alert alert-warning" style="margin-bottom:0;">
            <span class="al-ic">⚠️</span>
            <div>
                <strong>Konfigurasi belum lengkap.</strong>
                Buat file <code style="background:#fef3c7;padding:1px 5px;border-radius:3px;">.assist.env</code>
                di direktori yang sama dengan script ini:
                <pre style="background:#fffbeb;border:1px solid #fcd34d;border-radius:6px;padding:10px;margin:8px 0;font-size:.8rem;overflow-x:auto;">OAUTH_CLIENT_ID=isi_client_id
OAUTH_CLIENT_SECRET=isi_client_secret
OAUTH_USERNAME=Assist
OAUTH_PASSWORD=Irac
DE061_SIM_SERIAL=</pre>
                <strong>KODE_AGEN</strong> &amp; <strong>MFTFI</strong> dibaca otomatis dari
                <code>storage/cds/cache/*snap_tf_bank_permata.cache</code>.
            </div>
        </div>
        <?php endif; ?>
        </div>
    </div>

    <!-- ── Form Card ── -->
    <div class="card">
        <div class="card-header">
            <div class="ch-icon">🔍</div>
            Form Inquiry Transfer Bank
            <span class="ch-badge">ISO 8583 MTI=010</span>
        </div>
        <div class="card-body">

            <?php if ($errorMessage): ?>
            <div class="alert alert-error">
                <span class="al-ic">❌</span>
                <div><?= htmlspecialchars($errorMessage) ?></div>
            </div>
            <?php endif; ?>

            <div class="alert alert-info" style="margin-bottom:16px;">
                <span class="al-ic">ℹ️</span>
                <div>
                    Request dikirim ke <strong>proxy assist-switching_v3_pro</strong> (<code>/mobile-digital</code>) menggunakan format ISO 8583 (MTI=010).
                    Header: <code>Authorization: Bearer {token}</code> — proxy yang meneruskan ke digital.sis1.net.
                    DE048 berisi kode transaksi Assist (<code>INQTFDANA</code> / <code>INQLLG</code> / <code>INQRTGS</code> / <code>INQBIFAST</code>).
                </div>
            </div>

            <form method="POST" id="inquiryForm" onsubmit="handleSubmit(event)">
                <input type="hidden" name="action" value="inquiry">

                <!-- Jenis Transfer -->
                <div class="section-title">Jenis Transfer</div>
                <div class="form-group full" style="margin-bottom:16px;">
                    <div class="type-tabs">
                        <label class="type-tab <?= ($formData['jenis_transfer'] ?? 'TFDANA') === 'TFDANA' ? 'active' : '' ?>">
                            <input type="radio" name="jenis_transfer" value="TFDANA"
                                <?= ($formData['jenis_transfer'] ?? 'TFDANA') === 'TFDANA' ? 'checked' : '' ?>>
                            <span class="ti">💸</span>
                            <span class="tl">Transfer Dana</span>
                            <span class="td">Online / SKN Real-Time</span>
                            <span class="tde048">DE048: INQTFDANA</span>
                        </label>
                        <label class="type-tab <?= ($formData['jenis_transfer'] ?? '') === 'LLG' ? 'active' : '' ?>">
                            <input type="radio" name="jenis_transfer" value="LLG"
                                <?= ($formData['jenis_transfer'] ?? '') === 'LLG' ? 'checked' : '' ?>>
                            <span class="ti">📋</span>
                            <span class="tl">LLG / SKN Batch</span>
                            <span class="td">Maks. Rp 500 juta</span>
                            <span class="tde048">DE048: INQLLG</span>
                        </label>
                        <label class="type-tab <?= ($formData['jenis_transfer'] ?? '') === 'RTGS' ? 'active' : '' ?>">
                            <input type="radio" name="jenis_transfer" value="RTGS"
                                <?= ($formData['jenis_transfer'] ?? '') === 'RTGS' ? 'checked' : '' ?>>
                            <span class="ti">🏛️</span>
                            <span class="tl">RTGS</span>
                            <span class="td">Di atas Rp 100 juta</span>
                            <span class="tde048">DE048: INQRTGS</span>
                        </label>
                        <label class="type-tab <?= ($formData['jenis_transfer'] ?? '') === 'BIFAST' ? 'active' : '' ?>" style="border-color:#2563eb;">
                            <input type="radio" name="jenis_transfer" value="BIFAST"
                                <?= ($formData['jenis_transfer'] ?? '') === 'BIFAST' ? 'checked' : '' ?>
                                onchange="handleJenisTransferChange()">
                            <span class="ti">⚡</span>
                            <span class="tl" style="color:#1d4ed8;">BI-FAST</span>
                            <span class="td">Real-time 24/7, maks. Rp 250 juta</span>
                            <span class="tde048" style="background:#dbeafe;color:#1e40af;">DE048: INQBIFAST</span>
                        </label>
                    </div>
                </div>

                <!-- Bank & Rekening Tujuan -->
                <div class="section-title">Rekening Tujuan</div>
                <div class="form-grid">
                    <div class="form-group">
                        <label>Bank Tujuan <span class="req">*</span></label>
                        <select name="kode_bank" id="kodeBank" onchange="setNamaBank(this)">
                            <?php foreach ($daftarBank as $kode => $nama): ?>
                            <option value="<?= htmlspecialchars($kode) ?>"
                                <?= ($formData['kode_bank'] ?? '') === $kode ? 'selected' : '' ?>>
                                <?= htmlspecialchars($nama) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="field-hint">Kode numerik BI → DE103 dalam request ISO</div>
                    </div>

                    <div class="form-group">
                        <label>Kode Bank Manual (DE103) <span class="opt">(jika tidak ada di list)</span></label>
                        <input type="text" name="kode_bank_manual" id="kodeBankManual"
                            placeholder="Kode numerik BI, mis. 494"
                            value="<?= htmlspecialchars($formData['kode_bank_manual'] ?? '') ?>">
                        <div class="field-hint">Override pilihan dropdown di atas</div>
                    </div>

                    <div class="form-group" id="fieldDE048Wrap">
                        <label>Kode Bank DE048 (Assist/SWIFT) <span class="req">*</span></label>
                        <input type="text" name="kode_bank_de048" id="kodeBankDE048"
                            placeholder="Mis. BLTRFAG, CENAIDJA, BNINIDJA"
                            value="<?= htmlspecialchars($formData['kode_bank_de048'] ?? '') ?>"
                            oninput="this.value=this.value.toUpperCase()">
                        <div class="field-hint">Kode bank di DE048 TFDANA/LLG/RTGS (kolom <code>Kode</code> tabel <code>bank_code</code>) &mdash; auto-fill dari dropdown</div>
                    </div>

                    <!-- Field KodeBIFAST — hanya muncul saat BIFAST dipilih -->
                    <div class="form-group" id="fieldBIFASTWrap" style="<?= ($formData['jenis_transfer'] ?? '') !== 'BIFAST' ? 'display:none;' : '' ?>">
                        <label style="color:#1d4ed8;">⚡ Kode Bank BI-FAST (KodeBIFAST) <span class="req">*</span></label>
                        <input type="text" name="kode_bank_bifast" id="kodeBankBIFAST"
                            placeholder="Mis. CENAIDJA, BNINIDJA, BMRIIDJA"
                            value="<?= htmlspecialchars($formData['kode_bank_bifast'] ?? '') ?>"
                            oninput="this.value=this.value.toUpperCase()">
                        <div class="field-hint" style="color:#1e40af;">
                            Kolom <code>KodeBIFAST</code> tabel <code>bank_code</code> — BIC 11-digit.
                            Cek: <code>SELECT KodeBIFAST FROM bank_code WHERE Kode='...'</code>
                        </div>
                    </div>

                    <input type="hidden" name="nama_bank" id="namaBank"
                        value="<?= htmlspecialchars($formData['nama_bank'] ?? '') ?>">

                    <div class="form-group">
                        <label>Nomor Rekening Tujuan <span class="req">*</span></label>
                        <input type="text" name="nomor_rekening" id="nomorRek"
                            placeholder="Contoh: 1234567890"
                            maxlength="28"
                            value="<?= htmlspecialchars($formData['nomor_rekening'] ?? '') ?>"
                            oninput="this.value=this.value.replace(/\D/g,'')">
                        <div class="field-hint">Diisi ke DE102 dalam request ISO</div>
                    </div>

                    <div class="form-group">
                        <label>Nominal Transfer (Rp) <span class="opt">(opsional)</span></label>
                        <input type="text" name="nominal" id="nominalInput"
                            placeholder="Contoh: 100000"
                            value="<?= htmlspecialchars($formData['nominal'] ?? '') ?>"
                            oninput="this.value=this.value.replace(/\D/g,'')">
                        <div class="field-hint">Diisi ke DE004 dalam request ISO</div>
                    </div>
                </div>

                <hr style="margin:20px 0;border:none;border-top:1px dashed #e5e7eb;">

                <button type="submit" class="btn-submit" id="submitBtn">
                    <span id="btnText">🔍 Kirim Inquiry Transfer</span>
                    <div class="spinner" id="spinner"></div>
                </button>
            </form>
        </div>
    </div>

    <!-- ── RESULT ── -->
    <?php if ($result !== null): ?>
    <div class="result-wrap">

        <div class="result-header <?= $result['success'] ? 'ok' : 'fail' ?>">
            <?= $result['success'] ? '✅ Inquiry Berhasil' : '❌ Inquiry Gagal' ?>
            <span style="margin-left:auto;background:rgba(255,255,255,.2);border-radius:6px;padding:2px 10px;font-size:.78rem;">
                RC: <?= htmlspecialchars($result['rc'] ?? '-') ?>
            </span>
        </div>
        <div class="result-body">

            <?php if ($result['success']): ?>
            <div class="result-grid">
                <div class="ri">
                    <label>Nama Pemilik Rekening</label>
                    <div class="val hl"><?= htmlspecialchars($result['beneficiary']['account_name'] ?? '-') ?></div>
                </div>
                <div class="ri">
                    <label>Nomor Rekening</label>
                    <div class="val"><?= htmlspecialchars($result['beneficiary']['account_no'] ?? '-') ?></div>
                </div>
                <div class="ri">
                    <label>Bank Tujuan</label>
                    <div class="val">
                        [<?= htmlspecialchars($result['beneficiary']['bank_code'] ?? '-') ?>]
                        <?= htmlspecialchars($result['beneficiary']['bank_name'] ?? '-') ?>
                    </div>
                </div>
                <hr class="section-sep">
                <div class="ri">
                    <label>Jenis Transfer</label>
                    <div class="val"><?= htmlspecialchars($result['jenis_transfer'] ?? '-') ?></div>
                </div>
                <div class="ri">
                    <label>Biaya Admin</label>
                    <div class="val">Rp <?= number_format($result['biaya_admin'] ?? 0, 0, ',', '.') ?></div>
                </div>
                <div class="ri">
                    <label>DE048</label>
                    <div class="val code"><?= htmlspecialchars($result['de048'] ?? '-') ?></div>
                </div>
                <hr class="section-sep">
                <div class="ri">
                    <label>Response Code</label>
                    <div class="val code"><?= htmlspecialchars($result['rc'] ?? '-') ?></div>
                </div>
                <div class="ri" style="grid-column:1/-1">
                    <label>Pesan / Tagihan</label>
                    <div class="val"><?= htmlspecialchars($result['message'] ?? '-') ?></div>
                </div>
            </div>
            <div style="margin-top:14px;padding:12px;background:#f0fdf4;border:1px solid #86efac;border-radius:8px;font-size:.84rem;color:#065f46;">
                ✅ Rekening valid! Transfer dapat dilanjutkan ke
                <strong><?= htmlspecialchars($result['beneficiary']['account_name'] ?? '-') ?></strong>
                (<?= htmlspecialchars($result['beneficiary']['account_no'] ?? '-') ?>)
                di <?= htmlspecialchars($result['beneficiary']['bank_name'] ?? '-') ?>.
                <?php if ($result['token_from_cache'] ?? false): ?>
                <em style="font-size:.78rem;">(Token dari cache)</em>
                <?php endif; ?>
            </div>

            <?php
            // ── Tampilkan form konfirmasi payment jika nominal > 0 ──
            $_nominal      = (int)preg_replace('/\D/', '', $formData['nominal'] ?? '0');
            $_biayaAdmin   = (int)($result['biaya_admin'] ?? 0);
            $_totalDebet   = $_nominal + $_biayaAdmin;
            $_beneNo       = $result['beneficiary']['account_no']   ?? '';
            $_beneName     = $result['beneficiary']['account_name'] ?? '-';
            $_beneBank     = $result['beneficiary']['bank_name']    ?? '-';
            $_beneBankCode = $result['beneficiary']['bank_code']    ?? '';
            $_jenisTF      = $result['jenis_transfer']              ?? 'TFDANA';
            $_de048        = $result['de048']                       ?? '';
            ?>

            <?php if ($_nominal > 0): ?>
            <!-- ── Konfirmasi Payment ── -->
            <div class="confirm-box" style="margin-top:20px;" id="confirmBox">
                <div class="confirm-header">
                    <div class="ch-icon">💸</div>
                    Konfirmasi Eksekusi Transfer
                    <span style="margin-left:auto;background:rgba(255,255,255,.2);border-radius:6px;padding:2px 10px;font-size:.72rem;letter-spacing:.3px;">
                        ISO 8583 MTI=002
                    </span>
                </div>
                <div class="confirm-body">

                    <div class="confirm-warning">
                        <span class="cw-ic">⚠️</span>
                        <div>
                            <strong>Peringatan:</strong> Tombol <em>Eksekusi Transfer</em> akan mengirimkan
                            request <strong>MTI=002 (DIGITAL_BANK) / DE003=211041 (PAY<?= htmlspecialchars($_jenisTF) ?>)</strong> ke switching.
                            Transaksi ini bersifat <strong>irreversible</strong> — pastikan rekening tujuan,
                            nominal, dan bank sudah benar sebelum melanjutkan.
                        </div>
                    </div>

                    <div class="confirm-summary">
                        <div class="cs-row">
                            <span class="cs-label">Nama Penerima</span>
                            <span class="cs-val"><?= htmlspecialchars($_beneName) ?></span>
                        </div>
                        <div class="cs-row">
                            <span class="cs-label">Nomor Rekening</span>
                            <span class="cs-val"><?= htmlspecialchars($_beneNo) ?></span>
                        </div>
                        <div class="cs-row">
                            <span class="cs-label">Bank Tujuan</span>
                            <span class="cs-val">[<?= htmlspecialchars($_beneBankCode) ?>] <?= htmlspecialchars($_beneBank) ?></span>
                        </div>
                        <div class="cs-row">
                            <span class="cs-label">Jenis Transfer</span>
                            <span class="cs-val"><?= htmlspecialchars($_jenisTF) ?></span>
                        </div>
                        <div class="cs-row">
                            <span class="cs-label">Nominal Transfer</span>
                            <span class="cs-val">Rp <?= number_format($_nominal, 0, ',', '.') ?></span>
                        </div>
                        <div class="cs-row">
                            <span class="cs-label">Biaya Admin</span>
                            <span class="cs-val">Rp <?= number_format($_biayaAdmin, 0, ',', '.') ?></span>
                        </div>
                        <div class="cs-row">
                            <span class="cs-label">Total Debet</span>
                            <span class="cs-val big">Rp <?= number_format($_totalDebet, 0, ',', '.') ?></span>
                        </div>
                        <div class="cs-row" style="font-size:.78rem;">
                            <span class="cs-label">DE048 (request)</span>
                            <span class="cs-val" style="font-size:.78rem;"><?= htmlspecialchars(buildDE048Payment($_jenisTF, ($_jenisTF === 'BIFAST' ? ($formData['kode_bank_bifast'] ?? $formData['kode_bank_de048'] ?? $formData['kode_bank'] ?? '') : ($formData['kode_bank_de048'] ?? $formData['kode_bank'] ?? '')), $_nominal)) ?></span>
                        </div>
                    </div>

                    <form method="POST" id="paymentForm" onsubmit="handlePaySubmit(event)">
                        <input type="hidden" name="action"               value="payment">
                        <input type="hidden" name="nomor_rekening"       value="<?= htmlspecialchars($_beneNo) ?>">
                        <input type="hidden" name="kode_bank"            value="<?= htmlspecialchars($formData['kode_bank'] ?? '') ?>">
                        <input type="hidden" name="kode_bank_de048"      value="<?= htmlspecialchars($formData['kode_bank_de048'] ?? '') ?>">
                        <input type="hidden" name="kode_bank_bifast"     value="<?= htmlspecialchars($formData['kode_bank_bifast'] ?? '') ?>">
                        <input type="hidden" name="nama_bank"            value="<?= htmlspecialchars($_beneBank) ?>">
                        <input type="hidden" name="nominal"              value="<?= htmlspecialchars((string)$_nominal) ?>">
                        <input type="hidden" name="jenis_transfer"       value="<?= htmlspecialchars($_jenisTF) ?>">
                        <input type="hidden" name="beneficiary_name"     value="<?= htmlspecialchars($_beneName) ?>">
                        <input type="hidden" name="beneficiary_bank_code" value="<?= htmlspecialchars($_beneBankCode) ?>">
                        <input type="hidden" name="beneficiary_bank_name" value="<?= htmlspecialchars($_beneBank) ?>">
                        <input type="hidden" name="biaya_admin"          value="<?= htmlspecialchars((string)$_biayaAdmin) ?>">

                        <div class="confirm-btn-row">
                            <button type="button" class="btn-cancel" onclick="document.getElementById('confirmBox').style.display='none'">
                                ✖ Batal
                            </button>
                            <button type="submit" class="btn-pay" id="payBtn">
                                <span id="payBtnText">💸 Eksekusi Transfer</span>
                                <div class="spinner" id="paySpinner"></div>
                            </button>
                        </div>
                    </form>
                </div>
            </div>
            <?php else: ?>
            <div style="margin-top:12px;padding:10px 14px;background:#fef9ec;border:1px dashed #fbbf24;border-radius:8px;font-size:.83rem;color:#92400e;">
                💡 <strong>Untuk melanjutkan ke eksekusi transfer</strong>, isi field
                <em>Nominal Transfer</em> di form atas lalu ulangi inquiry.
                Setelah inquiry sukses, form konfirmasi payment akan muncul di sini.
            </div>
            <?php endif; ?>

            <?php else: ?>
            <?php
                $_iRC   = $result['rc']     ?? 'XT';
                $_iStep = $result['step']   ?? '-';
                $_iMsg  = $result['message'] ?? 'Inquiry gagal';
                $_iDesc = rcDescription($_iRC);
                // Tip spesifik per RC
                $_iTip  = '';
                if ($_iRC === '11') $_iTip = '💡 RC=11: Switching tidak menemukan data rekening. Kemungkinan InquiryCode untuk bank ini belum diset di tabel bank_code (kolom InquiryCode), atau bank tidak support inquiry. Cek via SQL: SELECT InquiryCode FROM bank_code WHERE Kode=\'...\';';
                elseif ($_iRC === '14') $_iTip = '💡 RC=14: Nomor rekening tidak terdaftar di bank tujuan.';
                elseif ($_iRC === '91') $_iTip = '💡 RC=91: Bank tujuan tidak dapat dihubungi saat ini.';
                elseif ($_iRC === '03') $_iTip = '💡 RC=03: Bank tujuan menolak inquiry — rekening mungkin diblokir atau tidak mengizinkan transfer masuk.';
                elseif (substr($_iRC, 0, 1) === 'U') $_iTip = '💡 RC=U-xxx (BI-FAST): Kode error dari sistem BI-FAST. Cek detail di BIFast.mod.php fungsi RC(). Kemungkinan KodeBIFAST bank tidak sesuai kolom KodeBIFAST di tabel bank_code.';
            ?>
            <div class="alert alert-error" style="align-items:flex-start;">
                <span class="al-ic" style="font-size:1.5rem;margin-top:2px;">🚫</span>
                <div style="flex:1;">
                    <div style="font-weight:700;margin-bottom:6px;">Inquiry Gagal</div>
                    <table style="border-collapse:collapse;font-size:.87rem;width:100%;">
                        <tr>
                            <td style="padding:3px 10px 3px 0;font-weight:600;white-space:nowrap;width:90px;">RC</td>
                            <td style="padding:3px 0;">
                                <code style="background:#fee2e2;border:1px solid #fca5a5;border-radius:4px;padding:2px 8px;font-weight:700;color:#991b1b;">
                                    <?= htmlspecialchars($_iRC) ?>
                                </code>
                            </td>
                        </tr>
                        <tr>
                            <td style="padding:3px 10px 3px 0;font-weight:600;">Deskripsi</td>
                            <td style="padding:3px 0;"><?= htmlspecialchars($_iDesc) ?></td>
                        </tr>
                        <?php if (!empty($_iMsg) && $_iMsg !== 'Inquiry gagal'): ?>
                        <tr>
                            <td style="padding:3px 10px 3px 0;font-weight:600;">Pesan</td>
                            <td style="padding:3px 0;"><?= htmlspecialchars($_iMsg) ?></td>
                        </tr>
                        <?php endif; ?>
                        <tr>
                            <td style="padding:3px 10px 3px 0;font-weight:600;">Step</td>
                            <td style="padding:3px 0;font-size:.8rem;color:#666;"><?= htmlspecialchars($_iStep) ?></td>
                        </tr>
                    </table>
                    <?php if ($_iTip): ?>
                    <div style="margin-top:8px;padding:8px 10px;background:#fef3c7;border-left:3px solid #f59e0b;border-radius:4px;font-size:.82rem;color:#78350f;">
                        <?= htmlspecialchars($_iTip) ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- ── Debug Panel ── -->
            <?php if (DEBUG_MODE): ?>
            <div class="debug-wrap">
                <button class="debug-btn" onclick="toggleDebug('dbg1')">
                    🔧 Debug: Request / Response ISO 8583 (SHA256 Signing)
                    <span id="dbg1-icon">▼</span>
                </button>
                <div class="debug-content" id="dbg1">
<span class="dc">━━ STEP 1: GET TOKEN ━━</span>
Token URL  : <?= htmlspecialchars(URL_GET_TOKEN) ?>

<?php
// ── Tampilkan info KodeSertifikat yang dipakai ───────────────
$_sertUsed   = $result['debug']['step1_get_token']['sertifikat_used']   ?? (OAUTH_SERTIFIKAT ?: OAUTH_CLIENT_ID);
$_sertSrc    = $result['debug']['step1_get_token']['sertifikat_source'] ?? (OAUTH_SERTIFIKAT ? 'OAUTH_SERTIFIKAT' : 'OAUTH_CLIENT_ID (fallback)');
$_sertOk     = (OAUTH_SERTIFIKAT !== '');
?>
<span class="<?= $_sertOk ? 'dc' : 'de' ?>">KodeSert  : <?= htmlspecialchars($_sertUsed ?: '(kosong!)') ?> ← <?= htmlspecialchars($_sertSrc) ?></span>
<?php if (!$_sertOk): ?>
<span class="de">⚠️  OAUTH_SERTIFIKAT kosong! Fallback ke OAUTH_CLIENT_ID.</span>
<span class="de">   Jika "Aplikasi tidak valid" → isi OAUTH_SERTIFIKAT=xxxx di .assist.env</span>
<span class="de">   (tanyakan KodeSertifikat ke admin SIS — bukan sama dengan auth_client_id)</span>
<?php endif; ?>
Client ID  : <?= htmlspecialchars(OAUTH_CLIENT_ID ?: '(kosong)') ?> (untuk OAuth2 SSO — bukan KodeSertifikat)
Username   : <?= htmlspecialchars(OAUTH_USERNAME  ?: '(kosong)') ?>
Kode Agen  : <?= htmlspecialchars(KODE_AGEN       ?: '(kosong)') ?>
Device ID  : <?= htmlspecialchars(DE061_SIM_SERIAL ?: (KODE_AGEN ?: md5(gethostname()))) ?>

Body Sent  : <?= htmlspecialchars($result['debug']['step1_get_token']['raw']['body_sent'] ?? '-') ?>
Hdr Auth   : Authorization: Bearer <?= htmlspecialchars($_sertUsed ?: '(kosong)') ?>

Cache File : <?= htmlspecialchars(CACHE_FILE_TF) ?>
Dari Cache : <?= ($result['debug']['step1_get_token']['from_cache'] ?? false) ? 'YA' : 'TIDAK' ?>

Sukses     : <?= ($result['debug']['step1_get_token']['success'] ?? false) ? 'YA' : 'TIDAK' ?>
HTTP Code  : <?= htmlspecialchars((string)($result['debug']['step1_get_token']['raw']['http_code'] ?? '-')) ?>
Error Msg  : <?= htmlspecialchars($result['debug']['step1_get_token']['error'] ?? '-') ?>
OAuth Raw  : <?= htmlspecialchars($result['debug']['step1_get_token']['raw']['raw'] ?? '-') ?>


<span class="dc">━━ STEP 2: ISO 8583 REQUEST (MTI=010) ━━</span>
<?= htmlspecialchars(json_encode($result['debug']['step2_iso_request'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ?>


<span class="dc">━━ STEP 3: HTTP POST ke Digital Server ━━</span>
URL        : <?= htmlspecialchars($result['debug']['step3_http_result']['url'] ?? '') ?>

Body Sent  : <?= htmlspecialchars($result['debug']['step3_http_result']['body_signed'] ?? '') ?>

HTTP Code  : <?= htmlspecialchars((string)($result['debug']['step3_http_result']['http_code'] ?? '')) ?>  (<?= $result['debug']['step3_http_result']['elapsed_ms'] ?? 0 ?>ms)

RAW Response:
<?= htmlspecialchars($result['debug']['step3_http_result']['raw'] ?? '(kosong)') ?>


<span class="dc">━━ STEP 4: ISO 8583 RESPONSE PARSED ━━</span>
<?= htmlspecialchars(json_encode($result['debug']['step4_iso_response'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ?>

                </div>
            </div>
            <?php endif; ?>
        </div>

    </div>
    <?php endif; ?>

    <!-- ══════════════════════════════════════════════════════════
         PAYMENT RESULT CARD
         Ditampilkan setelah user submit form konfirmasi (action=payment)
    ══════════════════════════════════════════════════════════ -->
    <?php if ($paymentResult !== null || $paymentError !== ''): ?>
    <div class="pay-result-wrap">

        <?php if ($paymentError !== ''): ?>
        <!-- Error validasi sebelum kirim ke server -->
        <div class="pay-result-header fail">
            ❌ Payment Gagal — Validasi Input
        </div>
        <div class="pay-result-body">
            <div class="confirm-warning">
                <span class="cw-ic">🚫</span>
                <div><?= htmlspecialchars($paymentError) ?></div>
            </div>
        </div>

        <?php elseif ($paymentResult !== null): ?>
        <div class="pay-result-header <?= $paymentResult['success'] ? 'ok' : 'fail' ?>">
            <?= $paymentResult['success'] ? '✅ Transfer Berhasil Dieksekusi' : '❌ Transfer Gagal' ?>
            <span style="margin-left:auto;background:rgba(255,255,255,.2);border-radius:6px;padding:2px 10px;font-size:.78rem;">
                RC: <?= htmlspecialchars($paymentResult['rc'] ?? '-') ?>
            </span>
        </div>
        <div class="pay-result-body">

            <?php if ($paymentResult['success']): ?>
            <!-- Receipt sukses -->
            <div class="pay-receipt">
                <div class="pr-row">
                    <span class="pr-label">Status</span>
                    <span class="pr-val" style="color:#047857;font-size:.95rem;">✅ SUKSES</span>
                </div>
                <div class="pr-row">
                    <span class="pr-label">Response Code</span>
                    <span class="pr-val">RC = <?= htmlspecialchars($paymentResult['rc'] ?? '-') ?></span>
                </div>
                <?php if (!empty($paymentResult['nomor_referensi'])): ?>
                <div class="pr-row">
                    <span class="pr-label">No. Referensi / SN</span>
                    <span class="pr-val ref"><?= htmlspecialchars($paymentResult['nomor_referensi']) ?></span>
                </div>
                <?php endif; ?>
                <div class="pr-row">
                    <span class="pr-label">Nama Penerima</span>
                    <span class="pr-val"><?= htmlspecialchars($paymentResult['beneficiary']['account_name'] ?? '-') ?></span>
                </div>
                <div class="pr-row">
                    <span class="pr-label">Rekening Tujuan</span>
                    <span class="pr-val"><?= htmlspecialchars($paymentResult['beneficiary']['account_no'] ?? '-') ?></span>
                </div>
                <div class="pr-row">
                    <span class="pr-label">Bank Tujuan</span>
                    <span class="pr-val">
                        [<?= htmlspecialchars($paymentResult['beneficiary']['bank_code'] ?? '-') ?>]
                        <?= htmlspecialchars($paymentResult['beneficiary']['bank_name'] ?? '-') ?>
                    </span>
                </div>
                <div class="pr-row">
                    <span class="pr-label">Jenis Transfer</span>
                    <span class="pr-val"><?= htmlspecialchars($paymentResult['jenis_transfer'] ?? '-') ?></span>
                </div>
                <div class="pr-row">
                    <span class="pr-label">Nominal Transfer</span>
                    <span class="pr-val">Rp <?= number_format($paymentResult['nominal'] ?? 0, 0, ',', '.') ?></span>
                </div>
                <div class="pr-row">
                    <span class="pr-label">Biaya Admin</span>
                    <span class="pr-val">Rp <?= number_format($paymentResult['biaya_admin'] ?? 0, 0, ',', '.') ?></span>
                </div>
                <div class="pr-row">
                    <span class="pr-label">Total Didebet</span>
                    <span class="pr-val amount">Rp <?= number_format($paymentResult['total_debet'] ?? 0, 0, ',', '.') ?></span>
                </div>
                <?php if ($paymentResult['token_from_cache'] ?? false): ?>
                <div class="pr-row" style="font-size:.78rem;">
                    <span class="pr-label">Token</span>
                    <span class="pr-val">dari cache (reuse)</span>
                </div>
                <?php endif; ?>
            </div>

            <?php else: ?>
            <!-- Gagal — tampilkan detail RC + deskripsi ISO 8583 -->
            <?php
                $_fRC   = $paymentResult['rc']      ?? 'XT';
                $_fStep = $paymentResult['step']     ?? '-';
                $_fMsg  = $paymentResult['message']  ?? 'Transfer gagal';
                $_fDesc = rcDescription($_fRC);
            ?>
            <div class="confirm-warning" style="align-items:flex-start;">
                <span class="cw-ic" style="font-size:1.6rem;margin-top:2px;">🚫</span>
                <div style="flex:1;">
                    <div style="font-size:1rem;font-weight:700;margin-bottom:8px;">
                        Transfer Ditolak / Gagal
                    </div>
                    <table style="width:100%;border-collapse:collapse;font-size:.87rem;">
                        <tr>
                            <td style="padding:4px 8px 4px 0;color:#7c3aed;font-weight:600;white-space:nowrap;width:110px;">RC</td>
                            <td style="padding:4px 0;">
                                <code style="background:#fef3c7;border:1px solid #fcd34d;border-radius:4px;padding:2px 8px;font-size:.9rem;font-weight:700;color:#92400e;">
                                    <?= htmlspecialchars($_fRC) ?>
                                </code>
                            </td>
                        </tr>
                        <tr>
                            <td style="padding:4px 8px 4px 0;color:#7c3aed;font-weight:600;white-space:nowrap;">Deskripsi</td>
                            <td style="padding:4px 0;"><?= htmlspecialchars($_fDesc) ?></td>
                        </tr>
                        <?php if (!empty($_fMsg) && $_fMsg !== 'Transfer gagal'): ?>
                        <tr>
                            <td style="padding:4px 8px 4px 0;color:#7c3aed;font-weight:600;white-space:nowrap;">Pesan</td>
                            <td style="padding:4px 0;"><?= htmlspecialchars($_fMsg) ?></td>
                        </tr>
                        <?php endif; ?>
                        <tr>
                            <td style="padding:4px 8px 4px 0;color:#7c3aed;font-weight:600;white-space:nowrap;">Step</td>
                            <td style="padding:4px 0;font-size:.8rem;color:#666;"><?= htmlspecialchars($_fStep) ?></td>
                        </tr>
                        <tr>
                            <td style="padding:4px 8px 4px 0;color:#7c3aed;font-weight:600;white-space:nowrap;">Rekening</td>
                            <td style="padding:4px 0;">
                                <?= htmlspecialchars($paymentResult['beneficiary']['account_no'] ?? '-') ?>
                                — <?= htmlspecialchars($paymentResult['beneficiary']['bank_name'] ?? '-') ?>
                                (<?= htmlspecialchars($paymentResult['beneficiary']['bank_code'] ?? '-') ?>)
                            </td>
                        </tr>
                        <tr>
                            <td style="padding:4px 8px 4px 0;color:#7c3aed;font-weight:600;white-space:nowrap;">Nominal</td>
                            <td style="padding:4px 0;">
                                Rp <?= number_format($paymentResult['nominal'] ?? 0, 0, ',', '.') ?>
                                <?php if (($paymentResult['biaya_admin'] ?? 0) > 0): ?>
                                + biaya Rp <?= number_format($paymentResult['biaya_admin'], 0, ',', '.') ?>
                                <?php endif; ?>
                            </td>
                        </tr>
                    </table>
                    <?php
                    // Petunjuk spesifik untuk RC umum
                    $tip = '';
                    if ($_fRC === '03') $tip = '💡 RC=03: Bank tujuan menolak transaksi ini. Kemungkinan rekening tujuan tidak aktif, fitur transfer masuk tidak diizinkan, atau ada pembatasan dari bank tujuan. Hubungi bank tujuan.';
                    elseif ($_fRC === '51') $tip = '💡 RC=51: Saldo rekening sumber tidak mencukupi.';
                    elseif ($_fRC === '14') $tip = '💡 RC=14: Nomor rekening tujuan tidak valid atau tidak terdaftar di bank tujuan.';
                    elseif ($_fRC === '91') $tip = '💡 RC=91: Sistem bank tujuan sedang tidak dapat dihubungi. Coba beberapa menit lagi.';
                    elseif ($_fRC === '96') $tip = '💡 RC=96: System malfunction. Coba ulang transaksi.';
                    elseif ($_fRC === '11') $tip = '💡 RC=11: Inquiry tidak mengembalikan data. Pastikan InquiryCode bank di tabel bank_code sudah terisi dengan benar.';
                    elseif (substr($_fRC, 0, 1) === 'U') $tip = '💡 RC=U-xxx (BI-FAST): Kode error dari sistem BI-FAST. Periksa KodeBIFAST bank di kolom KodeBIFAST tabel bank_code. RC U112 = melebihi Rp 250 juta, U129 = bank tujuan unavailable.';
                    if ($tip): ?>
                    <div style="margin-top:10px;padding:8px 10px;background:#fffbeb;border-left:3px solid #f59e0b;border-radius:4px;font-size:.82rem;color:#78350f;">
                        <?= htmlspecialchars($tip) ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- ── Debug Panel Payment ── -->
            <?php if (DEBUG_MODE && isset($paymentResult['debug'])): ?>
            <div class="debug-wrap" style="margin-top:16px;">
                <button class="debug-btn" onclick="toggleDebug('dbgPay')">
                    🔧 Debug: Payment Request / Response ISO 8583 (MTI=002)
                    <span id="dbgPay-icon">▼</span>
                </button>
                <div class="debug-content" id="dbgPay">
<span class="dc">━━ STEP 1: GET TOKEN ━━</span>
Token URL  : <?= htmlspecialchars(URL_GET_TOKEN) ?>

Kode Agen  : <?= htmlspecialchars(KODE_AGEN ?: '(kosong)') ?>

Dari Cache : <?= ($paymentResult['debug']['step1_get_token']['from_cache'] ?? false) ? 'YA' : 'TIDAK' ?>

Sukses     : <?= ($paymentResult['debug']['step1_get_token']['success'] ?? false) ? 'YA' : 'TIDAK' ?>

HTTP Code  : <?= htmlspecialchars((string)($paymentResult['debug']['step1_get_token']['raw']['http_code'] ?? '-')) ?>


<span class="dc">━━ STEP 2: ISO 8583 REQUEST (MTI=002, DE003=211041) ━━</span>
<?= htmlspecialchars(json_encode($paymentResult['debug']['step2_iso_request'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ?>


<span class="dc">━━ STEP 3: HTTP POST ke Digital Server ━━</span>
URL        : <?= htmlspecialchars($paymentResult['debug']['step3_http_result']['url'] ?? '') ?>

Body Sent  : <?= htmlspecialchars($paymentResult['debug']['step3_http_result']['body_signed'] ?? '') ?>

HTTP Code  : <?= htmlspecialchars((string)($paymentResult['debug']['step3_http_result']['http_code'] ?? '')) ?>  (<?= $paymentResult['debug']['step3_http_result']['elapsed_ms'] ?? 0 ?>ms)

RAW Response:
<?= htmlspecialchars($paymentResult['debug']['step3_http_result']['raw'] ?? '(kosong)') ?>


<span class="dc">━━ STEP 4: ISO 8583 RESPONSE PARSED ━━</span>
<?= htmlspecialchars(json_encode($paymentResult['debug']['step4_iso_response'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ?>

                </div>
            </div>
            <?php endif; ?>

        </div>
        <?php endif; ?>

    </div>
    <?php endif; ?>

    <div class="footer">
        Inquiry &amp; Payment Transfer Bank — SIS/Assist Switching Middleware v1.6.42 &mdash;
        PHP <?= PHP_VERSION ?> &mdash; <?= SNow() ?> WIB
    </div>

</div>

<script>
const bankNames = <?= json_encode($daftarBankJs) ?>;
const mapDE048  = <?= json_encode($mapDE048Js) ?>;    // kode numerik BI → kode Assist/SWIFT (TFDANA/LLG/RTGS)
const mapBIFAST = <?= json_encode($mapBIFASTJs) ?>;   // kode numerik BI → KodeBIFAST (BI-FAST BIC11)

function setNamaBank(sel) {
    const kode = sel.value;
    document.getElementById('namaBank').value = bankNames[kode] || '';
    if (kode) {
        document.getElementById('kodeBankManual').value = '';
        // Auto-fill kode DE048 TFDANA/LLG/RTGS
        const de048 = mapDE048[kode] || '';
        document.getElementById('kodeBankDE048').value = de048;
        // Auto-fill KodeBIFAST
        const bifast = mapBIFAST[kode] || de048;
        document.getElementById('kodeBankBIFAST').value = bifast;
    }
}

document.getElementById('kodeBankManual').addEventListener('input', function() {
    if (this.value) {
        document.getElementById('kodeBank').value      = '';
        document.getElementById('namaBank').value      = '';
        // Saat manual diisi, kosongkan auto-fill DE048
        document.getElementById('kodeBankDE048').value = '';
        document.getElementById('kodeBankBIFAST').value = '';
    }
});

// Tampil/sembunyikan field KodeBIFAST sesuai jenis transfer
function handleJenisTransferChange() {
    const radios  = document.querySelectorAll('input[name="jenis_transfer"]');
    let jenis = 'TFDANA';
    radios.forEach(r => { if (r.checked) jenis = r.value; });
    const isBIFAST = (jenis === 'BIFAST');
    document.getElementById('fieldBIFASTWrap').style.display = isBIFAST ? '' : 'none';
    document.getElementById('fieldDE048Wrap').style.display  = isBIFAST ? 'none' : '';
}

// Validasi real-time KodeBIFAST — tampilkan warning jika isi masih berupa angka
(function() {
    const inp = document.getElementById('kodeBankBIFAST');
    if (!inp) return;
    const hint = inp.closest('.form-group')?.querySelector('.field-hint');
    inp.addEventListener('input', function() {
        const v = this.value.trim();
        if (v && /^\d+$/.test(v)) {
            this.style.borderColor = '#dc2626';
            this.style.background  = '#fef2f2';
            if (hint) {
                hint.innerHTML = '<span style="color:#dc2626;font-weight:600;">⚠️ Nilai <code>' + v + '</code> adalah kode numerik BI, bukan KodeBIFAST BIC!</span> '
                    + 'Jalankan: <code>SELECT KodeBIFAST FROM bank_code WHERE Kode=\'' + v + '\'</code> '
                    + 'untuk mendapat BIC yang benar (mis. <code>CENAIDJA</code> untuk BCA). '
                    + 'PHP akan auto-map dari <code>$mapKodeBankBIFAST</code> sebagai fallback.';
            }
            // Coba auto-map dari mapBIFAST
            const mapped = mapBIFAST[v];
            if (mapped) {
                this.value = mapped;
                this.style.borderColor = '#16a34a';
                this.style.background  = '#f0fdf4';
                if (hint) {
                    hint.innerHTML = '<span style="color:#16a34a;font-weight:600;">✅ Auto-mapped: <code>' + v + '</code> → <code>' + mapped + '</code></span> '
                        + 'Pastikan nilai ini sesuai kolom <code>KodeBIFAST</code> di tabel <code>bank_code</code> production.';
                }
            }
        } else if (v) {
            this.style.borderColor = '#2563eb';
            this.style.background  = '';
            if (hint) {
                hint.innerHTML = 'Kolom <code>KodeBIFAST</code> tabel <code>bank_code</code> — BIC 8 atau 11-digit. '
                    + 'Cek: <code>SELECT KodeBIFAST FROM bank_code WHERE Kode=\'...\'</code>';
            }
        } else {
            this.style.borderColor = '';
            this.style.background  = '';
        }
    });
})();

function handleSubmit(e) {
    const manual = document.getElementById('kodeBankManual').value.trim();
    if (manual) document.getElementById('kodeBank').value = manual;
    document.getElementById('btnText').textContent = 'Memproses...';
    document.getElementById('spinner').style.display = 'block';
    document.getElementById('submitBtn').disabled = true;
    return true;
}

function handlePaySubmit(e) {
    const btn  = document.getElementById('payBtn');
    const txt  = document.getElementById('payBtnText');
    const spin = document.getElementById('paySpinner');
    if (txt)  txt.textContent = 'Memproses...';
    if (spin) spin.style.display = 'block';
    if (btn)  btn.disabled = true;
    return true;
}

function toggleDebug(id) {
    const el   = document.getElementById(id);
    const icon = document.getElementById(id + '-icon');
    el.classList.toggle('show') ? icon.textContent = '▲' : icon.textContent = '▼';
}

document.querySelectorAll('.type-tab input[type=radio]').forEach(function(r) {
    r.addEventListener('change', function() {
        document.querySelectorAll('.type-tab').forEach(t => t.classList.remove('active'));
        r.closest('.type-tab').classList.add('active');
        handleJenisTransferChange();
    });
});

// Inisialisasi tampilan field saat halaman load
handleJenisTransferChange();
</script>
</body>
</html>