<?php
declare(strict_types=1);
require_once __DIR__ . '/lib.php';
header('Content-Type: application/json; charset=utf-8');

function out(array $a, int $code = 200): never
{
    http_response_code($code);
    echo json_encode($a, JSON_UNESCAPED_UNICODE);
    exit;
}

$stu = current_student();
if (!$stu) out(['ok' => false, 'message' => 'Session expirée, reconnecte-toi.'], 401);

$db = pdo();
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET' && isset($_GET['taxons'])) {
    header('Cache-Control: private, max-age=3600');
    out(['ok' => true, 'taxons' => array_map(fn($x) => [$x['n'], $x['l'], $x['g']], taxons())]);
}

if ($method === 'GET') {
    $biomes = $db->query('SELECT id, name, emoji, description, habitat FROM biomes ORDER BY position, id')->fetchAll();
    $st = $db->prepare(
        'SELECT o.biome_id, o.name_key, MIN(o.name) AS name, COUNT(*) AS cnt,
                MAX(o.student_id = ?) AS mine
         FROM observations o JOIN students s ON s.id = o.student_id
         WHERE s.class_id = ?
         GROUP BY o.biome_id, o.name_key
         ORDER BY name'
    );
    $st->execute([$stu['id'], $stu['class_id']]);
    $byBiome = [];
    foreach ($st->fetchAll() as $r) {
        $byBiome[(int)$r['biome_id']][] = [
            'key' => $r['name_key'], 'name' => $r['name'],
            'count' => (int)$r['cnt'], 'mine' => (bool)$r['mine'],
        ];
    }
    foreach ($biomes as &$b) {
        $b['id'] = (int)$b['id'];
        $b['species'] = $byBiome[$b['id']] ?? [];
    }
    out(['ok' => true, 'biomes' => $biomes]);
}

if ($method !== 'POST') out(['ok' => false, 'message' => 'Méthode non autorisée.'], 405);
if (!hash_equals($_SESSION['csrf'] ?? '', (string)($_POST['csrf'] ?? ''))) {
    out(['ok' => false, 'message' => 'Session expirée, recharge la page.'], 400);
}

$action = (string)($_POST['action'] ?? '');
$biomeId = (int)($_POST['biome_id'] ?? 0);
$chk = $db->prepare('SELECT habitat FROM biomes WHERE id = ?');
$chk->execute([$biomeId]);
$row = $chk->fetch();
$biomeHabitat = $row['habitat'] ?? null;
if (!$row) out(['ok' => false, 'message' => 'Biome inconnu.'], 404);

if ($action === 'add') {
    $name = clean_name((string)($_POST['name'] ?? ''));
    $name = canonical_name($name);   // « Hedera helix » → « Lierre » : les synonymes comptent pour une seule espèce
    $hab = taxon_habitat($name);
    if ($biomeHabitat && $hab && $hab !== $biomeHabitat) {   // espèce du livret valide seulement dans un autre milieu
        out(['ok' => false, 'message' => '✖ ' . $name . ' n’est pas attendu dans ce biome (milieu : ' . (HABITATS[$hab] ?? $hab) . '). Vérifie ton biome ou ton identification.']);
    }
    $len = mb_strlen($name, 'UTF-8');
    if ($len < 2 || $len > 80 || !preg_match('/\p{L}/u', $name)) {
        out(['ok' => false, 'message' => 'Écris un nom entre 2 et 80 caractères.']);
    }
    $key = name_key($name);

    $tot = $db->prepare('SELECT COUNT(*) FROM observations WHERE student_id = ?');
    $tot->execute([$stu['id']]);
    if ((int)$tot->fetchColumn() >= 500) out(['ok' => false, 'message' => "Limite d'observations atteinte."]);

    // Combien de camarades de la classe l'ont déjà noté dans ce biome ?
    $others = $db->prepare(
        'SELECT COUNT(*) FROM observations o JOIN students s ON s.id = o.student_id
         WHERE o.biome_id = ? AND o.name_key = ? AND s.class_id = ? AND o.student_id <> ?'
    );
    $others->execute([$biomeId, $key, $stu['class_id'], $stu['id']]);
    $nOthers = (int)$others->fetchColumn();

    $ins = $db->prepare('INSERT IGNORE INTO observations (biome_id, student_id, name, name_key) VALUES (?, ?, ?, ?)');
    $ins->execute([$biomeId, $stu['id'], $name, $key]);
    if ($ins->rowCount() === 0) out(['ok' => true, 'status' => 'already', 'message' => 'Tu as déjà noté cet être vivant.']);

    $msg = $nOthers === 0
        ? '🎉 Nouvelle espèce pour la classe : ' . $name
        : '✔ Ajouté (déjà vu par ' . $nOthers . ' camarade' . ($nOthers > 1 ? 's' : '') . ')';
    out(['ok' => true, 'status' => 'added', 'message' => $msg]);
}

if ($action === 'remove') {
    $key = name_key((string)($_POST['key'] ?? ''));
    $del = $db->prepare('DELETE FROM observations WHERE biome_id = ? AND student_id = ? AND name_key = ?');
    $del->execute([$biomeId, $stu['id'], $key]);
    out(['ok' => true, 'status' => 'removed', 'message' => 'Retiré de ta liste.']);
}

out(['ok' => false, 'message' => 'Action inconnue.'], 400);
