<?php
declare(strict_types=1);
require_once __DIR__ . '/lib.php';

if (current_student()) { header('Location: biomes.php'); exit; }

$db = pdo();
$error = '';

// Connexion élève : classe + nom. Le nom est lié au 1er appareil (cookie) qui l'utilise.
// L'IP ne bloque plus : elle est seulement mémorisée pour information.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $cid = (int)($_POST['class_id'] ?? 0);
    $sid = (int)($_POST['student_id'] ?? 0);
    $st = $db->prepare('SELECT id, device_hash FROM students WHERE id = ? AND class_id = ?');
    $st->execute([$sid, $cid]);
    $stu = $st->fetch();
    if (!$stu) {
        $error = 'Choisis ton nom dans la liste.';
    } else {
        $cookie = 'biodiv_d' . $sid;
        $tok = (string)($_COOKIE[$cookie] ?? '');
        $ok = false;

        if ($stu['device_hash'] === null) {
            // Premier appareil : on le lie à ce nom (atomique)
            $tok = bin2hex(random_bytes(24));
            $up = $db->prepare('UPDATE students SET device_hash = ?, bound_at = NOW() WHERE id = ? AND device_hash IS NULL');
            $up->execute([hash('sha256', $tok), $sid]);
            $ok = $up->rowCount() === 1;   // sinon un camarade vient de le prendre
        } elseif ($tok !== '' && hash_equals($stu['device_hash'], hash('sha256', $tok))) {
            $ok = true;
        }

        if ($ok) {
            setcookie($cookie, $tok, [
                'expires' => time() + 400 * 86400, 'path' => '/', 'httponly' => true,
                'samesite' => 'Lax', 'secure' => !empty($_SERVER['HTTPS']),
            ]);
            log_student_ip($sid);
            session_regenerate_id(true);
            $_SESSION['student_id'] = $sid;
            header('Location: biomes.php');
            exit;
        }
        $error = "Ce nom est déjà utilisé sur un autre appareil. "
               . "Si c'est bien toi (nouvel appareil, navigateur vidé), demande à ton enseignant de débloquer ton nom.";
    }
}

$classes = $db->query('SELECT id, name, slug FROM classes ORDER BY name')->fetchAll();
$selected = null;
$slug = (string)($_GET['classe'] ?? '');
$cid = (int)($_GET['class_id'] ?? ($_POST['class_id'] ?? 0));
foreach ($classes as $c) {
    if (($slug !== '' && $c['slug'] === $slug) || ($cid && (int)$c['id'] === $cid)) $selected = $c;
}
$students = [];
if ($selected) {
    $st = $db->prepare('SELECT id, name FROM students WHERE class_id = ? ORDER BY name');
    $st->execute([$selected['id']]);
    $students = $st->fetchAll();
}

layout_head('Connexion');
echo '<main class="card narrow"><h1>', h(SITE_TITLE), '</h1>';
if ($error) echo '<p class="err">', h($error), '</p>';

if (!$classes) {
    echo '<p>Le site n\'est pas encore configuré. Demande à ton enseignant.</p>';
} elseif (!$selected) {
    echo '<h2>1. Choisis ta classe</h2><div class="choices">';
    foreach ($classes as $c) {
        echo '<a class="btn big" href="?class_id=', (int)$c['id'], '">', h($c['name']), '</a>';
    }
    echo '</div>';
} else {
    echo '<p class="muted">Classe : <strong>', h($selected['name']), '</strong> · <a href="index.php">changer</a></p>';
    echo '<h2>2. Choisis ton nom</h2>';
    if (!$students) {
        echo '<p>Aucun élève dans cette classe. Demande à ton enseignant.</p>';
    } else {
        echo '<form method="post">', csrf_field(),
             '<input type="hidden" name="class_id" value="', (int)$selected['id'], '">',
             '<label>Ton nom<select name="student_id" required><option value="">— choisir —</option>';
        foreach ($students as $s) echo '<option value="', (int)$s['id'], '">', h($s['name']), '</option>';
        echo '</select></label><button class="btn big" type="submit">Entrer</button></form>';
    }
}
echo '<p class="foot"><a href="admin.php">Espace enseignant</a></p></main></body></html>';
