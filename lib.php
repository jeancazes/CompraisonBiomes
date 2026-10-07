<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');
header('Cache-Control: no-store');

session_name('biodiv');
session_set_cookie_params([
    'lifetime' => 0, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax',
    'secure' => !empty($_SERVER['HTTPS']),
]);
session_start();

set_exception_handler(function (Throwable $e) {
    error_log(get_class($e) . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    http_response_code(500);
    if (str_contains($_SERVER['SCRIPT_NAME'] ?? '', 'api.php')) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'message' => 'Erreur serveur.']);
    } elseif ($e instanceof PDOException) {
        // Cause la plus fréquente : identifiants MySQL de config.php, ou tables non créées (install.php)
        echo 'Erreur de base de données. Vérifie les identifiants MySQL dans config.php '
           . '(DB_HOST, DB_NAME, DB_USER, DB_PASS) et que install.php a bien été exécuté.';
    } else {
        echo 'Erreur serveur. Vérifie config.php et le journal d\'erreurs PHP.';
    }
    exit;
});

function pdo(): PDO
{
    static $p = null;
    if (!$p) {
        $p = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }
    return $p;
}

function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

function csrf(): string
{
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
    return $_SESSION['csrf'];
}
function csrf_field(): string { return '<input type="hidden" name="csrf" value="' . h(csrf()) . '">'; }
function csrf_check(): void
{
    if (!hash_equals($_SESSION['csrf'] ?? '', (string)($_POST['csrf'] ?? ''))) {
        http_response_code(400);
        exit('Session expirée : recharge la page et réessaie.');
    }
}

/** Empreinte (HMAC) de l'IP du visiteur. IPv6 : seul le préfixe /64 compte (les adresses « privacy » changent). */
function client_ip_hash(): string
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    if (TRUST_PROXY_HEADER && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $ip = trim(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0]);
    }
    $bin = @inet_pton($ip);
    if ($bin !== false && strlen($bin) === 16) {
        if (substr($bin, 0, 10) === str_repeat("\0", 10) && substr($bin, 10, 2) === "\xff\xff") {
            $bin = substr($bin, 12);            // IPv4 mappée en IPv6
        } else {
            $bin = substr($bin, 0, 8);          // préfixe /64
        }
    }
    return hash_hmac('sha256', $bin !== false ? $bin : $ip, IP_SALT);
}

/** Mémorise (hachée) l'IP utilisée par l'élève : simple information pour l'enseignant, ne bloque jamais. */
function log_student_ip(int $studentId): void
{
    pdo()->prepare('INSERT INTO student_ips (student_id, ip_hash, first_seen, last_seen) VALUES (?,?,NOW(),NOW())
                    ON DUPLICATE KEY UPDATE last_seen = NOW()')->execute([$studentId, client_ip_hash()]);
}

function setting(string $k): ?string
{
    $st = pdo()->prepare('SELECT v FROM settings WHERE k = ?');
    $st->execute([$k]);
    $v = $st->fetchColumn();
    return $v === false ? null : (string)$v;
}
function set_setting(string $k, string $v): void
{
    pdo()->prepare('INSERT INTO settings (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)')->execute([$k, $v]);
}

function is_admin(): bool { return !empty($_SESSION['admin']); }
function require_admin(): void
{
    if (!is_admin()) { header('Location: admin.php'); exit; }
}

function current_student(): ?array
{
    if (empty($_SESSION['student_id'])) return null;
    $st = pdo()->prepare('SELECT s.id, s.name, s.class_id, c.name AS class_name
                          FROM students s JOIN classes c ON c.id = s.class_id WHERE s.id = ?');
    $st->execute([$_SESSION['student_id']]);
    $r = $st->fetch();
    if (!$r) { unset($_SESSION['student_id']); return null; }
    return $r;
}

function clean_name(string $s): string
{
    $s = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $s) ?? '';
    $s = preg_replace('/\s+/u', ' ', $s) ?? '';
    return trim($s);
}
function name_key(string $s): string { return mb_strtolower(clean_name($s), 'UTF-8'); }

function slugify(string $s): string
{
    $s = mb_strtolower($s, 'UTF-8');
    $t = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
    if ($t !== false) $s = $t;
    $s = trim(preg_replace('/[^a-z0-9]+/', '-', $s) ?? '', '-');
    return $s !== '' ? $s : 'classe';
}

function base_url(): string
{
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    return $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . $dir;
}

function layout_head(string $title, string $bodyClass = ''): void
{
    echo '<!doctype html><html lang="fr"><head><meta charset="utf-8">',
         '<meta name="viewport" content="width=device-width,initial-scale=1">',
         '<title>', h($title), ' – ', h(SITE_TITLE), '</title>',
         '<link rel="stylesheet" href="assets/style.css"></head><body class="', h($bodyClass), '">';
}
