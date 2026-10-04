<?php
declare(strict_types=1);
require_once __DIR__ . '/lib.php';

if (current_student()) { header('Location: biomes.php'); exit; }

$db = pdo();
$error = '';

// Connexion élève : classe + nom, avec règle « un nom = une seule IP »
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $cid = (int)($_POST['class_id'] ?? 0);
    $sid = (int)($_POST['student_id'] ?? 0);
    $st = $db->prepare('SELECT id, ip_hash FROM students WHERE id = ? AND class_id = ?');
    $st->execute([$sid, $cid]);
    $stu = $st->fetch();
    if (!$stu) {
        $error = 'Choisis ton nom dans la liste.';
    } else {
        $me = client_ip_hash();
        if ($stu['ip_hash'] === null) {
            // Première connexion : on lie ce nom à cette IP (atomique)
            $up = $db->prepare('UPDATE students SET ip_hash = ?, bound_at = NOW() WHERE id = ? AND ip_hash IS NULL');
            $up->execute([$me, $sid]);
            if ($up->rowCount() === 0) { // quelqu'un d'autre vient de le lier : on revérifie
                $st->execute([$sid, $cid]);
                $stu = $st->fetch();
            } else {
                $stu['ip_hash'] = $me;
            }
        }
        if ($stu['ip_hash'] !== null && hash_equals($stu['ip_hash'], $me)) {
            session_regenerate_id(true);
            $_SESSION['student_id'] = $sid;
            header('Location: biomes.php');
            exit;
        }
        $error = "Ce nom est déjà utilisé depuis une autre connexion Internet. "
               . "Si c'est bien toi, demande à ton enseignant de réinitialiser ton accès.";
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
echo '<main class="card narrow"><h1>🌿 ', h(SITE_TITLE), '</h1>';
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
