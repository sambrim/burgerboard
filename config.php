<?php
declare(strict_types=1);

// ─── Environment ─────────────────────────────────────────────────────────────
define('DB_HOST',     'localhost');
define('DB_NAME',     '');
define('DB_USER',     '');
define('DB_PASS',     "");
define('DB_CHARSET',  'utf8mb4');

// ─── SMS Notifications (46elks) ───────────────────────────────────────────────
define('SMS_ENABLED',   true);
define('SMS_USERNAME',  '');
define('SMS_PASSWORD',  '');
define('SMS_FROM',      '');       // max 11 chars, no spaces
define('SMS_TO',        '');      // your phone number with country code


// ─── Paths ────────────────────────────────────────────────────────────────────
define('UPLOAD_DIR', '/var/uploads/burgerboard/');
define('MAX_FILE_SIZE', 8 * 1024 * 1024);            // 8MB in bytes

// ─── Rate limiting ────────────────────────────────────────────────────────────
define('UPLOAD_LIMIT',        5);   // max uploads per IP
define('UPLOAD_WINDOW',      30);   // within this many minutes
define('LOGIN_LIMIT',         5);   // max login attempts
define('LOGIN_WINDOW',       15);   // lockout window in minutes

// ─── Salt for IP hashing (keep secret, never change after launch) ─────────────
define('IP_SALT', '');

// ─── Session config (before any session_start()) ───────────────────────────────
function get_db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = sprintf(
            'mysql:host=%s;dbname=%s;charset=%s',
            DB_HOST, DB_NAME, DB_CHARSET
        );
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    }
    return $pdo;
}

// ─── Helpers ──────────────────────────────────────────────────────────────────

/**
 * Get the real visitor IP, accounting for Cloudflare.
 * CF-Connecting-IP is set by Cloudflare and contains the real visitor IP.
 */
function get_visitor_ip(): string {
    return $_SERVER['HTTP_CF_CONNECTING_IP']
        ?? $_SERVER['REMOTE_ADDR']
        ?? '0.0.0.0';
}

/**
 * Hash an IP address with a secret salt.
 * We store only the hash — never the raw IP.
 */
function hash_ip(string $ip): string {
    return hash_hmac('sha256', $ip, IP_SALT);
}

/**
 * Generate a UUID v4.
 */
function generate_uuid(): string {
    $data = random_bytes(16);
    $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
    $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

/**
 * Check if an IP hash has exceeded a rate limit.
 * Returns true if the limit has been reached (i.e. block the action).
 */
function is_rate_limited(string $table, string $ip_hash, int $limit, int $window_minutes): bool {
    $db  = get_db();
    $sql = "SELECT COUNT(*) FROM {$table}
            WHERE ip_hash = ?
            AND attempted_at >= DATE_SUB(NOW(), INTERVAL ? MINUTE)";
    $stmt = $db->prepare($sql);
    $stmt->execute([$ip_hash, $window_minutes]);
    return (int)$stmt->fetchColumn() >= $limit;
}

/**
 * Record an attempt in a rate limit table.
 */
function record_attempt(string $table, string $ip_hash): void {
    $db = get_db();
    $db->prepare("INSERT INTO {$table} (ip_hash, attempted_at) VALUES (?, NOW())")
       ->execute([$ip_hash]);
}

/**
 * Sanitize a string for safe HTML output.
 */
function h(string $str): string {
    return htmlspecialchars($str, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Redirect to a URL and exit.
 */
function redirect(string $url): never {
    header('Location: ' . $url, true, 302);
    exit;
}

/**
 * Generate a CSRF token and store it in the session.
 */
function csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Validate a submitted CSRF token against the session.
 */
function csrf_verify(): bool {
    $submitted = $_POST['csrf_token'] ?? '';
    $stored    = $_SESSION['csrf_token'] ?? '';
    return $stored !== '' && hash_equals($stored, $submitted);
}


/**
 * Send an SMS notification via 46elks.
 */
function send_sms(string $message): bool {
    if (!SMS_ENABLED) return false;

    $ch = curl_init('https://api.46elks.com/a1/sms');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERPWD        => SMS_USERNAME . ':' . SMS_PASSWORD,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query([
            'from'    => SMS_FROM,
            'to'      => SMS_TO,
            'message' => $message,
        ]),
        CURLOPT_TIMEOUT        => 10,
    ]);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return $http_code === 200;
}

