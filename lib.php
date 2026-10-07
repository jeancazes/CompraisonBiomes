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
        // Migration douce : milieu du livret associé à chaque biome (colonne ajoutée automatiquement)
        try {
            if (!$p->query("SHOW COLUMNS FROM biomes LIKE 'habitat'")->fetch()) {
                $p->exec("ALTER TABLE biomes ADD COLUMN habitat VARCHAR(20) NULL");
            }
        } catch (PDOException $e) { /* tables pas encore créées : install.php s'en charge */ }
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
/**
 * Extrait « NOM Prénom » d'un texte collé : liste simple (1 nom par ligne) ou CSV/tableur
 * (séparateur ; , ou tabulation) avec colonnes Nom / Prénom repérées par l'en-tête.
 * Les autres colonnes (classe, date, n°…) sont ignorées. @return string[]
 */
function parse_student_list(string $text): array
{
    $text = preg_replace('/^\xEF\xBB\xBF/', '', $text) ?? $text;
    $lines = array_values(array_filter(array_map('trim', preg_split('/\R/u', $text) ?: []), fn($l) => $l !== ''));
    if (!$lines) return [];
    $sep = null; $best = 0;
    foreach ([';', "\t", ','] as $c) {
        $n = substr_count(implode("\n", array_slice($lines, 0, 5)), $c);
        if ($n > $best) { $best = $n; $sep = $c; }
    }
    $isWord = fn(string $v) => (bool)preg_match('/^[\p{L}][\p{L}\s\'’.\-]*$/u', $v);
    $fmt = function (string $nom, string $pre): string {
        $nom = mb_strtoupper(clean_name($nom), 'UTF-8');
        $pre = clean_name($pre);
        return clean_name($nom . ' ' . mb_strtoupper(mb_substr($pre, 0, 1, 'UTF-8'), 'UTF-8') . mb_substr($pre, 1, null, 'UTF-8'));
    };
    if ($sep === null) {                                   // liste simple : une ligne = un nom
        return array_map('clean_name', $lines);
    }
    $rows = array_map(fn($l) => array_map('clean_name', str_getcsv($l, $sep, '"', '')), $lines);
    $norm = fn(string $v) => preg_replace('/[^a-z]/', '', strtolower(@iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $v) ?: $v));
    $iN = $iP = $iF = null; $start = 0;
    foreach (array_slice($rows, 0, 5, true) as $ri => $r) {   // recherche de l'en-tête
        foreach ($r as $ci => $cell) {
            $k = $norm($cell);
            if (in_array($k, ['nom', 'nomdefamille', 'nomdusage', 'nompatronymique', 'nomeleve'], true)) $iN ??= $ci;
            elseif (str_starts_with($k, 'prenom')) $iP ??= $ci;
            elseif (in_array($k, ['eleve', 'nomprenom', 'prenomnom', 'identite', 'etudiant'], true)) $iF ??= $ci;
        }
        if ($iN !== null || $iF !== null) { $start = $ri + 1; break; }
    }
    $out = [];
    foreach (array_slice($rows, $start) as $r) {
        if ($iN !== null && $iP !== null) {
            $nom = $r[$iN] ?? ''; $pre = $r[$iP] ?? '';
            if ($nom !== '' && $pre !== '') $out[] = $fmt($nom, $pre);
        } elseif ($iN !== null || $iF !== null) {          // une seule colonne d'identité
            $v = $r[$iN ?? $iF] ?? '';
            if ($v !== '') $out[] = $iN !== null ? clean_name($v) : $v;
        } else {                                           // pas d'en-tête : 2 premières colonnes « texte »
            $a = $r[0] ?? '';
            if ($a === '' || !$isWord($a)) continue;           // ligne de titre, chiffres, vide…
            if (preg_match('/\s/u', $a)) { $out[] = $a; continue; }   // « NOM Prénom » déjà dans une seule cellule
            $b = $r[1] ?? '';
            if ($b !== '' && $isWord($b)) $out[] = $fmt($a, $b);   // colonnes Nom ; Prénom sans en-tête
        }
    }
    return $out;
}

/** Minuscules sans accents, ponctuation → espace (pour comparer « Chêne vert » et « chene-vert »). */
function fold(string $s): string
{
    $s = mb_strtolower($s, 'UTF-8');
    $s = strtr($s, ['œ' => 'oe', 'æ' => 'ae', '’' => "'", 'ç' => 'c', 'ñ' => 'n']);
    $s = strtr($s, array_combine(
        mb_str_split('àâäáãåéèêëíìîïóòôöõúùûüýÿ'),
        mb_str_split('aaaaaaeeeeiiiiooooouuuuyy')
    ));
    return trim(preg_replace('/[^a-z0-9]+/', ' ', $s) ?? '');
}

/** Référentiel d'espèces (livret « Qui vit ici ? ») : data/taxons.json = [{n: nom français, l: nom latin, g: milieu}]. */
function taxons(): array
{
    static $t = null;
    if ($t === null) {
        $raw = @file_get_contents(__DIR__ . '/data/taxons.json');
        $t = $raw ? (json_decode($raw, true) ?: []) : [];
    }
    return $t;
}

/** Si le texte saisi est exactement un nom français ou latin du référentiel, renvoie le nom français officiel (fusion des synonymes). */
function canonical_name(string $name): string
{
    static $idx = null;
    if ($idx === null) {
        $idx = [];
        foreach (taxons() as $x) { $idx[fold($x['n'])] = $x['n']; $idx[fold($x['l'])] = $x['n']; }
    }
    return $idx[fold($name)] ?? $name;
}

const HABITATS = ['foret' => 'Forêt de l’Ancyse', 'ripisylve' => 'Ripisylve', 'riviere' => 'Rivière (la Cèze)'];

/** Milieu du livret où l'espèce (nom français ou latin) est valide, ou null si elle n'est pas dans le référentiel. */
function taxon_habitat(string $name): ?string
{
    static $idx = null;
    if ($idx === null) {
        $idx = [];
        foreach (taxons() as $x) { $idx[fold($x['n'])] = $x['g']; $idx[fold($x['l'])] = $x['g']; }
    }
    return $idx[fold($name)] ?? null;
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
