<?php
declare(strict_types=1);
require_once __DIR__ . '/lib.php';

$db = pdo();
$admin = is_admin() && isset($_GET['class_id']);          // aperçu enseignant
if ($admin) {
    $cid = (int)$_GET['class_id'];
    $cn = $db->prepare('SELECT name FROM classes WHERE id = ?'); $cn->execute([$cid]);
    $cname = $cn->fetchColumn();
    if ($cname === false) { header('Location: admin.php'); exit; }
    $stu = ['id' => 0, 'name' => 'Enseignant', 'class_name' => $cname, 'class_id' => $cid];
} else {
    $stu = current_student();
    if (!$stu) { header('Location: index.php'); exit; }
    $cid = (int)$stu['class_id'];
    $v = $db->prepare('SELECT validated_at FROM students WHERE id = ?');
    $v->execute([$stu['id']]);
    if (!$v->fetchColumn()) { header('Location: biomes.php'); exit; }   // le bilan s'ouvre après validation
}

$fo = $db->prepare('SELECT final_open FROM classes WHERE id = ?');
$fo->execute([$cid]);
$finalOpen = (bool)$fo->fetchColumn();

$sum = class_summary([$cid]);
$stats = biodiversity([$cid], false);               // provisoire : tout ce qui est saisi, inventaires validés
$biomes = $db->query('SELECT id, name, emoji FROM biomes ORDER BY position, id')->fetchAll();
$pct = $sum['students'] ? round(100 * $sum['validated'] / $sum['students']) : 0;

layout_head('Bilan de la classe', 'student');
echo '<meta http-equiv="refresh" content="30">';
?>
<header class="top">
  <div><strong><?= h(SITE_TITLE) ?></strong></div>
  <div class="who"><?php if ($admin): ?><a class="btn small" href="admin.php">← Administration</a> <a class="btn small ghost" href="bilan_final.php?class_id=<?= $cid ?>">Bilan final</a><?php else: ?><?= h($stu['name']) ?> · <?= h($stu['class_name']) ?> · <a href="biomes.php">Mon inventaire</a> · <a href="logout.php">Quitter</a><?php endif; ?></div>
</header>
<main class="wrap">
  <section class="card">
    <h2>Bilan provisoire de la classe <?= h($stu['class_name']) ?></h2>
    <div class="kpis">
      <div class="kpi"><b><?= $sum['validated'] ?> / <?= $sum['students'] ?></b>inventaires validés (<?= $pct ?> %)</div>
      <div class="kpi"><b><?= $sum['valid_species'] ?></b>espèces valides inventoriées par la classe</div>
    </div>
    <div class="progress" role="progressbar" aria-valuenow="<?= $pct ?>" aria-valuemin="0" aria-valuemax="100"><span style="width:<?= $pct ?>%"></span></div>
    <p class="muted">Cette page se met à jour toute seule. Les scores sont <strong>provisoires</strong> : ils tiennent compte des seuls inventaires validés et de tous les noms saisis, avant la vérification de l'enseignant.</p>
  </section>

  <section class="card">
    <h2>Biodiversité par biome (provisoire)</h2>
    <div class="stats-wrap"><table class="stats">
      <thead><tr><th>Biome</th><th>Espèces S</th><th>Pielou J</th><th>Élèves</th></tr></thead>
      <tbody>
      <?php foreach ($biomes as $b): $x = $stats[(int)$b['id']]; ?>
        <tr><td><?= h(($b['emoji'] ? $b['emoji'] . ' ' : '') . $b['name']) ?></td><td><?= $x['S'] ?></td><td><?= fmt_j($x['J']) ?></td><td><?= $x['P'] ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <p class="muted small">S = nombre d'espèces différentes. J = équitabilité de Pielou (0 : une espèce domine, 1 : répartition équilibrée ; « — » si moins de 2 espèces).</p>
  </section>

  <section class="card">
    <?php if ($finalOpen || $admin): ?>
      <p><?= $finalOpen ? '🎉 Le bilan final est disponible.' : 'Bilan final non publié aux élèves (aperçu enseignant).' ?> <a class="btn" href="bilan_final.php<?= $admin ? '?class_id=' . $cid : '' ?>">Voir le bilan final</a></p>
    <?php else: ?>
      <p class="muted">Le bilan final (scores corrigés, comparaison entre classes) apparaîtra quand ton enseignant l'aura publié.</p>
    <?php endif; ?>
  </section>
</main>
</body></html>
