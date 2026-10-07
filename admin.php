<?php
declare(strict_types=1);
require_once __DIR__ . '/lib.php';

$db = pdo();
$hash = setting('admin_hash');
if ($hash === null) { exit("Installation non terminée : ouvre d'abord install.php."); }

// ---------- Connexion admin ----------
if (!is_admin()) {
    $err = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        $_SESSION['tries'] = ($_SESSION['tries'] ?? 0) + 1;
        if ($_SESSION['tries'] > 5) { sleep(3); }
        if (password_verify((string)($_POST['pw'] ?? ''), $hash)) {
            session_regenerate_id(true);
            $_SESSION['admin'] = true;
            $_SESSION['tries'] = 0;
            header('Location: admin.php'); exit;
        }
        sleep(1);
        $err = 'Mot de passe incorrect.';
    }
    layout_head('Administration');
    echo '<main class="card narrow"><h1>Espace enseignant</h1>';
    if ($err) echo '<p class="err">', h($err), '</p>';
    echo '<form method="post">', csrf_field(),
         '<label>Mot de passe<input type="password" name="pw" required autofocus></label>',
         '<button class="btn" type="submit">Se connecter</button></form>',
         '<p class="foot"><a href="index.php">← Retour élèves</a></p></main></body></html>';
    exit;
}

// ---------- Actions ----------
function back(string $msg, string $anchor = ''): never
{
    $_SESSION['flash'] = $msg;
    header('Location: admin.php' . ($anchor ? '#' . $anchor : ''));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $a = (string)($_POST['action'] ?? '');
    $id = (int)($_POST['id'] ?? 0);

    switch ($a) {
        case 'logout':
            unset($_SESSION['admin']);
            header('Location: admin.php'); exit;

        case 'biome_add':
            $name = mb_substr(clean_name((string)($_POST['name'] ?? '')), 0, 80);
            if ($name === '') back('Nom de biome vide.', 'biomes');
            $pos = (int)$db->query('SELECT COALESCE(MAX(position),0)+1 FROM biomes')->fetchColumn();
            $db->prepare('INSERT INTO biomes (name, emoji, description, position, habitat) VALUES (?,?,?,?,?)')->execute([
                $name,
                mb_substr(clean_name((string)($_POST['emoji'] ?? '')), 0, 8),
                mb_substr(clean_name((string)($_POST['description'] ?? '')), 0, 250),
                $pos,
                isset(HABITATS[$_POST['habitat'] ?? '']) ? $_POST['habitat'] : null,
            ]);
            back('Biome ajouté.', 'biomes');

        case 'biome_update':
            $name = mb_substr(clean_name((string)($_POST['name'] ?? '')), 0, 80);
            if ($name === '') back('Nom de biome vide.', 'biomes');
            $db->prepare('UPDATE biomes SET name=?, emoji=?, description=?, position=?, habitat=? WHERE id=?')->execute([
                $name,
                mb_substr(clean_name((string)($_POST['emoji'] ?? '')), 0, 8),
                mb_substr(clean_name((string)($_POST['description'] ?? '')), 0, 250),
                (int)($_POST['position'] ?? 0),
                isset(HABITATS[$_POST['habitat'] ?? '']) ? $_POST['habitat'] : null,
                $id,
            ]);
            back('Biome mis à jour.', 'biomes');

        case 'biome_delete':
            $db->prepare('DELETE FROM biomes WHERE id=?')->execute([$id]);
            back('Biome supprimé (ses observations aussi).', 'biomes');

        case 'class_add':
            $name = mb_substr(clean_name((string)($_POST['name'] ?? '')), 0, 80);
            if ($name === '') back('Nom de classe vide.', 'classes');
            $base = slugify($name); $slug = $base; $i = 2;
            $ex = $db->prepare('SELECT 1 FROM classes WHERE slug=?');
            while (true) { $ex->execute([$slug]); if (!$ex->fetchColumn()) break; $slug = $base . '-' . $i++; }
            $db->prepare('INSERT INTO classes (name, slug) VALUES (?,?)')->execute([$name, $slug]);
            back('Classe créée.', 'classes');

        case 'class_delete':
            $db->prepare('DELETE FROM classes WHERE id=?')->execute([$id]);
            back('Classe supprimée (élèves et observations compris).', 'classes');

        case 'class_clear_obs':
            $db->prepare('DELETE o FROM observations o JOIN students s ON s.id=o.student_id WHERE s.class_id=?')->execute([$id]);
            back("Observations de la classe effacées.", 'classes');

        case 'students_add':
            $names = parse_student_list((string)($_POST['names'] ?? ''));
            $ins = $db->prepare('INSERT IGNORE INTO students (class_id, name) VALUES (?,?)');
            $n = 0; $skip = 0;
            foreach ($names as $nm) {
                $nm = mb_substr(clean_name($nm), 0, 80);
                if ($nm === '') continue;
                $ins->execute([$id, $nm]);
                if ($ins->rowCount()) $n++; else $skip++;
            }
            back($n . ' élève(s) ajouté(s)' . ($skip ? ', ' . $skip . ' doublon(s) ignoré(s)' : '') . '.', 'class-' . $id);

        case 'student_delete':
            $cid = (int)($_POST['class_id'] ?? 0);
            $db->prepare('DELETE FROM students WHERE id=?')->execute([$id]);
            back('Élève supprimé.', 'class-' . $cid);

        case 'student_reset_ip':
            $cid = (int)($_POST['class_id'] ?? 0);
            $db->prepare('UPDATE students SET device_hash=NULL, bound_at=NULL WHERE id=?')->execute([$id]);
            back('Nom débloqué : le prochain appareil qui se connecte avec ce nom sera mémorisé.', 'class-' . $cid);

        case 'class_reset_ip':
            $db->prepare('UPDATE students SET device_hash=NULL, bound_at=NULL WHERE class_id=?')->execute([$id]);
            back('Tous les noms de la classe sont débloqués.', 'class-' . $id);

        case 'class_publish':
            $db->prepare('UPDATE classes SET final_open = 1 - final_open WHERE id=?')->execute([$id]);
            back('Bilan final : visibilité modifiée pour cette classe.', 'class-' . $id);

        case 'student_reopen':
            $cid = (int)($_POST['class_id'] ?? 0);
            $db->prepare('UPDATE students SET validated_at=NULL WHERE id=?')->execute([$id]);
            back('Inventaire rouvert pour cet élève.', 'class-' . $cid);

        case 'review':
            $key = (string)($_POST['key'] ?? '');
            $status = (string)($_POST['status'] ?? '');
            $nm = mb_substr(clean_name((string)($_POST['name'] ?? '')), 0, 120);
            if ($status === 'reset') {
                $db->prepare('DELETE FROM taxa_review WHERE name_key=?')->execute([$key]);
                back('Décision annulée.', 'revue');
            }
            if (!in_array($status, ['ok', 'no'], true) || $key === '') back('Action inconnue.', 'revue');
            $merge = $status === 'ok' ? mb_substr(clean_name((string)($_POST['merge'] ?? '')), 0, 120) : '';
            $db->prepare('INSERT INTO taxa_review (name_key, name, status, merge_into) VALUES (?,?,?,?)
                          ON DUPLICATE KEY UPDATE status=VALUES(status), merge_into=VALUES(merge_into)')
               ->execute([$key, $nm, $status, $merge !== '' ? $merge : null]);
            back($status === 'ok' ? 'Nom validé.' : 'Nom rejeté (exclu des scores corrigés).', 'revue');

        case 'password':
            $pw = (string)($_POST['pw'] ?? '');
            if (!password_verify((string)($_POST['old'] ?? ''), $hash)) back('Ancien mot de passe incorrect.', 'securite');
            if (mb_strlen($pw) < 8) back('Nouveau mot de passe trop court.', 'securite');
            set_setting('admin_hash', password_hash($pw, PASSWORD_DEFAULT));
            back('Mot de passe modifié.', 'securite');
    }
    back('Action inconnue.');
}

// ---------- Données ----------
$biomes = $db->query('SELECT * FROM biomes ORDER BY position, id')->fetchAll();
$classes = $db->query('SELECT * FROM classes ORDER BY name')->fetchAll();
$stuByClass = [];
foreach ($db->query('SELECT s.*, (SELECT COUNT(*) FROM observations o WHERE o.student_id=s.id) AS nobs,
                            (SELECT COUNT(*) FROM student_ips i WHERE i.student_id=s.id) AS nips
                     FROM students s ORDER BY s.name')->fetchAll() as $s) {
    $stuByClass[(int)$s['class_id']][] = $s;
}
$reviewRows = [];
$revMap = [];
foreach ($db->query('SELECT * FROM taxa_review')->fetchAll() as $r) $revMap[$r['name_key']] = $r;
foreach ($db->query('SELECT name_key, MIN(name) AS name, COUNT(*) AS cnt, COUNT(DISTINCT biome_id) AS nb FROM observations GROUP BY name_key ORDER BY name')->fetchAll() as $r) {
    if (taxon_habitat($r['name']) === null) $reviewRows[] = $r + ['rev' => $revMap[$r['name_key']] ?? null];
}
$nPending = count(array_filter($reviewRows, fn($r) => !$r['rev']));
$flash = $_SESSION['flash'] ?? ''; unset($_SESSION['flash']);
$base = base_url();

function confirm_form(string $action, int $id, string $label, string $confirm, string $cls = 'link danger', array $extra = []): string
{
    $x = '';
    foreach ($extra as $k => $v) $x .= '<input type="hidden" name="' . h($k) . '" value="' . h($v) . '">';
    return '<form method="post" class="inline" onsubmit="return confirm(' . h(json_encode($confirm, JSON_UNESCAPED_UNICODE)) . ')">'
         . csrf_field() . '<input type="hidden" name="action" value="' . h($action) . '"><input type="hidden" name="id" value="' . $id . '">'
         . $x . '<button class="' . h($cls) . '" type="submit">' . h($label) . '</button></form>';
}

layout_head('Administration', 'admin');
?>
<header class="top">
  <div><strong>Administration</strong></div>
  <nav class="who">
    <a href="#biomes">Biomes</a> · <a href="#classes">Classes</a> · <a href="#revue">Noms hors livret<?= $nPending ? " (" . $nPending . ")" : "" ?></a> · <a href="#securite">Sécurité</a> ·
    <a class="btn small" href="bilan.php">Bilan</a>
    <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="logout"><button class="link" type="submit">Déconnexion</button></form>
  </nav>
</header>
<main class="wrap">
<?php if ($flash): ?><p class="flash"><?= h($flash) ?></p><?php endif; ?>

<section class="card" id="biomes">
  <h2>Biomes</h2>
  <?php if (!$biomes): ?><p class="muted">Aucun biome. Ajoute-en un ci-dessous (forêt, prairie, mare, haie…).</p><?php endif; ?>
  <?php foreach ($biomes as $b): ?>
    <form method="post" class="row">
      <?= csrf_field() ?><input type="hidden" name="action" value="biome_update"><input type="hidden" name="id" value="<?= (int)$b['id'] ?>">
      <input class="w-emoji" name="emoji" value="<?= h($b['emoji']) ?>" placeholder="🌳" aria-label="Emoji">
      <input name="name" value="<?= h($b['name']) ?>" required aria-label="Nom">
      <input class="grow" name="description" value="<?= h($b['description']) ?>" placeholder="Description (facultatif)" aria-label="Description">
      <select name="habitat" aria-label="Milieu du livret"><option value="">Milieu : libre</option><?php foreach (HABITATS as $hk => $hl): ?><option value="<?= h($hk) ?>"<?= ($b['habitat'] ?? '') === $hk ? ' selected' : '' ?>><?= h($hl) ?></option><?php endforeach; ?></select>
      <input class="w-pos" type="number" name="position" value="<?= (int)$b['position'] ?>" aria-label="Ordre">
      <button class="btn small" type="submit">Enregistrer</button>
    </form>
    <div class="right"><?= confirm_form('biome_delete', (int)$b['id'], 'Supprimer « ' . $b['name'] . ' »', 'Supprimer ce biome et toutes ses observations ?') ?></div>
  <?php endforeach; ?>
  <h3>Ajouter un biome</h3>
  <form method="post" class="row">
    <?= csrf_field() ?><input type="hidden" name="action" value="biome_add">
    <input class="w-emoji" name="emoji" placeholder="🌳" aria-label="Emoji">
    <input name="name" placeholder="Nom du biome" required>
    <input class="grow" name="description" placeholder="Description (facultatif)">
    <select name="habitat" aria-label="Milieu du livret"><option value="">Milieu : libre</option><?php foreach (HABITATS as $hk => $hl): ?><option value="<?= h($hk) ?>"<?= ('') === $hk ? ' selected' : '' ?>><?= h($hl) ?></option><?php endforeach; ?></select>
      <button class="btn small" type="submit">Ajouter</button>
  </form>
</section>

<section class="card" id="classes">
  <h2>Classes, élèves et adresses</h2>
  <form method="post" class="row">
    <?= csrf_field() ?><input type="hidden" name="action" value="class_add">
    <input class="grow" name="name" placeholder="Nouvelle classe (ex. 6e B)" required>
    <button class="btn small" type="submit">Créer la classe</button>
  </form>

  <?php foreach ($classes as $c):
      $cid = (int)$c['id']; $list = $stuByClass[$cid] ?? [];
      $url = $base . '/index.php?classe=' . rawurlencode($c['slug']); ?>
    <details class="class" id="class-<?= $cid ?>" <?= (($_GET['open'] ?? '') == $cid) ? 'open' : '' ?>>
      <summary><strong><?= h($c['name']) ?></strong> <span class="muted">· <?= count($list) ?> élève(s) · <?= count(array_filter($list, fn($x) => $x['validated_at'])) ?> inventaire(s) validé(s)<?= $c['final_open'] ? ' · bilan final publié' : '' ?></span></summary>
      <p>🔗 Adresse à donner à la classe :
        <input class="url" readonly value="<?= h($url) ?>" onclick="this.select()">
      </p>
      <table>
        <thead><tr><th>Nom</th><th>Obs.</th><th>Inventaire</th><th>Appareil lié</th><th>IP vues</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($list as $s): ?>
          <tr>
            <td><?= h($s['name']) ?></td>
            <td><?= (int)$s['nobs'] ?></td>
            <td><?= $s['validated_at'] ? '✅ ' . h(substr((string)$s['validated_at'], 5, 11)) : ($s['draft_saved_at'] ? '📝 brouillon' : '—') ?></td>
            <td><?= $s['device_hash'] ? '🔒 ' . h(substr((string)$s['bound_at'], 0, 16)) : '—' ?></td>
            <td><?= (int)$s['nips'] ?><?= (int)$s['nips'] >= 4 ? ' ⚠' : '' ?></td>
            <td class="acts">
              <?php if ($s['validated_at']) echo confirm_form('student_reopen', (int)$s['id'], 'Rouvrir', "Rouvrir l'inventaire de cet élève ?", 'link', ['class_id' => $cid]); ?>
              <?php if ($s['device_hash']) echo confirm_form('student_reset_ip', (int)$s['id'], 'Débloquer', "Autoriser ce nom à se relier à un nouvel appareil ?", 'link', ['class_id' => $cid]); ?>
              <?= confirm_form('student_delete', (int)$s['id'], 'Supprimer', 'Supprimer cet élève et ses observations ?', 'link danger', ['class_id' => $cid]) ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <form method="post">
        <?= csrf_field() ?><input type="hidden" name="action" value="students_add"><input type="hidden" name="id" value="<?= $cid ?>">
        <label>Ajouter des élèves — colle ta liste (un nom par ligne) ou un CSV/tableur avec colonnes « Nom » et « Prénom » : les autres colonnes sont ignorées
          <textarea name="names" rows="5" placeholder="Nom;Prénom;Classe;Date&#10;MARTIN;Léa;6A;2014"></textarea></label>
        <button class="btn small" type="submit">Ajouter</button>
      </form>
      <div class="right">
        <?= confirm_form('class_publish', $cid, $c['final_open'] ? '🔒 Masquer le bilan final aux élèves' : '📣 Publier le bilan final aux élèves', $c['final_open'] ? 'Masquer le bilan final ?' : 'Publier le bilan final pour cette classe ?', 'link') ?>
        <?= confirm_form('class_reset_ip', $cid, 'Débloquer toute la classe', 'Débloquer tous les noms de la classe ?', 'link') ?>
        <?= confirm_form('class_clear_obs', $cid, 'Effacer les observations', 'Effacer toutes les observations de cette classe ?') ?>
        <?= confirm_form('class_delete', $cid, 'Supprimer la classe', 'Supprimer la classe, ses élèves et leurs observations ?') ?>
      </div>
    </details>
  <?php endforeach; ?>
</section>

<section class="card" id="revue">
  <h2>Noms hors livret à vérifier</h2>
  <p class="muted">Noms saisis par les élèves qui ne figurent pas dans le livret. <strong>Valider</strong> = compté dans les scores corrigés (tu peux les fusionner avec un autre nom). <strong>Rejeter</strong> = exclu des scores corrigés. Sans décision, le nom est exclu du corrigé.</p>
  <?php if (!$reviewRows): ?><p class="muted">Rien à vérifier.</p><?php else: ?>
  <table>
    <thead><tr><th>Nom saisi</th><th>Élèves</th><th>Statut</th><th>Décision</th></tr></thead>
    <tbody>
    <?php foreach ($reviewRows as $r): $rv = $r['rev']; ?>
      <tr>
        <td><?= h($r['name']) ?></td><td><?= (int)$r['cnt'] ?></td>
        <td><?= !$rv ? '⏳ en attente' : ($rv['status'] === 'ok' ? '✅ validé' . ($rv['merge_into'] ? ' → ' . h($rv['merge_into']) : '') : '✖ rejeté') ?></td>
        <td>
          <form method="post" class="inline">
            <?= csrf_field() ?><input type="hidden" name="action" value="review"><input type="hidden" name="key" value="<?= h($r['name_key']) ?>"><input type="hidden" name="name" value="<?= h($r['name']) ?>">
            <input name="merge" list="livret" placeholder="Fusionner avec… (facultatif)" value="<?= h($rv['merge_into'] ?? '') ?>" aria-label="Fusionner avec">
            <button class="link" name="status" value="ok" type="submit">Valider</button>
            <button class="link danger" name="status" value="no" type="submit">Rejeter</button>
            <?php if ($rv): ?><button class="link" name="status" value="reset" type="submit">Annuler</button><?php endif; ?>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <datalist id="livret"><?php foreach (taxons() as $t): ?><option value="<?= h($t['n']) ?>"><?php endforeach; ?></datalist>
  <?php endif; ?>
</section>

<section class="card" id="securite">
  <h2>Sécurité</h2>
  <p class="muted">Règle appliquée : un nom d'élève est lié au <strong>premier appareil (navigateur)</strong> qui l'utilise, via un cookie. Un autre appareil est refusé jusqu'à ce que tu cliques sur « Débloquer ». L'adresse IP ne bloque rien : elle est seulement comptée (colonne « IP vues », ⚠ si 4 IP différentes ou plus). Limite : sur une tablette partagée, un camarade peut se connecter sous le nom d'un élève qui s'y est déjà connecté ; surveille cela en classe.</p>
  <form method="post" class="row">
    <?= csrf_field() ?><input type="hidden" name="action" value="password">
    <input type="password" name="old" placeholder="Ancien mot de passe" required>
    <input type="password" name="pw" placeholder="Nouveau (8 car. min.)" minlength="8" required>
    <button class="btn small" type="submit">Changer</button>
  </form>
</section>
</main>
<script>
(function(){var h=location.hash;if(h.indexOf('#class-')===0){var d=document.querySelector(h);if(d&&d.tagName==='DETAILS'){d.open=true;d.scrollIntoView();}}})();
</script>
</body></html>
