<?php
declare(strict_types=1);
require_once __DIR__ . '/lib.php';

$stu = current_student();
if (!$stu) { header('Location: index.php'); exit; }
$db = pdo();
$cid = (int)$stu['class_id'];

$fo = $db->prepare('SELECT final_open FROM classes WHERE id = ?');
$fo->execute([$cid]);
if (!$fo->fetchColumn()) { header('Location: classe.php'); exit; }

$biomes = $db->query('SELECT id, name, emoji FROM biomes ORDER BY position, id')->fetchAll();
$allClasses = $db->query('SELECT id FROM classes ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
$vue = (string)($_GET['vue'] ?? 'classe');
if (!in_array($vue, ['classe', 'toutes', 'comparer'], true)) $vue = 'classe';
$lab = fn(array $b) => ($b['emoji'] ? $b['emoji'] . ' ' : '') . $b['name'];

layout_head('Bilan final', 'student');
?>
<header class="top">
  <div><strong><?= h(SITE_TITLE) ?></strong></div>
  <div class="who"><?= h($stu['name']) ?> · <?= h($stu['class_name']) ?> · <a href="classe.php">Bilan provisoire</a> · <a href="logout.php">Quitter</a></div>
</header>
<main class="wrap">
  <section class="card">
    <h2>Bilan final</h2>
    <nav class="switch" aria-label="Vue">
      <a href="?vue=classe" class="<?= $vue === 'classe' ? 'on' : '' ?>">Ma classe</a>
      <a href="?vue=toutes" class="<?= $vue === 'toutes' ? 'on' : '' ?>">Toutes les classes</a>
      <a href="?vue=comparer" class="<?= $vue === 'comparer' ? 'on' : '' ?>">Comparer les classes</a>
    </nav>
    <p class="muted">« Non corrigé » : tous les noms saisis. « Corrigé » : seulement les espèces valides (du livret, dans le bon milieu, ou noms vérifiés par l'enseignant ; les synonymes sont regroupés).</p>
  </section>

<?php if ($vue !== 'comparer'):
    $ids = $vue === 'classe' ? [$cid] : null;
    $raw = biodiversity($ids, false);
    $cor = biodiversity($ids, true);
    $sum = class_summary($ids);
    $maxS = max(1, ...array_map(fn($x) => $x['S'], $raw)); ?>
  <section class="card">
    <h3><?= $vue === 'classe' ? 'Ma classe : ' . h($stu['class_name']) : 'Toutes les classes réunies' ?></h3>
    <div class="kpis">
      <div class="kpi"><b><?= $sum['valid_species'] ?></b>espèces valides inventoriées</div>
      <div class="kpi"><b><?= $sum['validated'] ?> / <?= $sum['students'] ?></b>inventaires validés</div>
    </div>
    <table class="stats">
      <thead><tr><th>Biome</th><th>S non corrigé</th><th>S corrigé</th><th>J non corrigé</th><th>J corrigé</th></tr></thead>
      <tbody>
      <?php foreach ($biomes as $b): $i = (int)$b['id']; ?>
        <tr><td><?= h($lab($b)) ?></td><td><?= $raw[$i]['S'] ?></td><td><strong><?= $cor[$i]['S'] ?></strong></td>
            <td><?= fmt_j($raw[$i]['J']) ?></td><td><strong><?= fmt_j($cor[$i]['J']) ?></strong></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <h3>Richesse spécifique (nombre d'espèces)</h3>
    <?php foreach ($biomes as $b): $i = (int)$b['id']; ?>
      <div class="bar-row"><span class="bar-label"><?= h($lab($b)) ?> · corrigé</span>
        <span class="bar"><span style="width:<?= round(100 * $cor[$i]['S'] / $maxS, 1) ?>%"></span></span><span class="bar-val"><?= $cor[$i]['S'] ?></span></div>
      <div class="bar-row"><span class="bar-label muted">non corrigé</span>
        <span class="bar alt"><span style="width:<?= round(100 * $raw[$i]['S'] / $maxS, 1) ?>%"></span></span><span class="bar-val"><?= $raw[$i]['S'] ?></span></div>
    <?php endforeach; ?>
  </section>

<?php else: ?>
  <?php
  $per = [];
  foreach ($allClasses as $k => $classId) {
      $classId = (int)$classId;
      if (class_summary([$classId])['validated'] === 0 && $classId !== $cid) continue;
      $per[$classId] = ['label' => $classId === $cid ? 'Ma classe' : 'Classe ' . chr(65 + ($k % 26)),
                        'raw' => biodiversity([$classId], false), 'cor' => biodiversity([$classId], true)];
  }
  foreach ($biomes as $b): $i = (int)$b['id']; ?>
  <section class="card">
    <h3><?= h($lab($b)) ?></h3>
    <table class="stats">
      <thead><tr><th>Classe</th><th>S non corr.</th><th>S corrigé</th><th>J non corr.</th><th>J corrigé</th></tr></thead>
      <tbody>
      <?php foreach ($per as $classId => $d): ?>
        <tr class="<?= $classId === $cid ? 'me' : '' ?>"><td><?= h($d['label']) ?></td>
          <td><?= $d['raw'][$i]['S'] ?></td><td><?= $d['cor'][$i]['S'] ?></td><td><?= fmt_j($d['raw'][$i]['J']) ?></td><td><?= fmt_j($d['cor'][$i]['J']) ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </section>
  <?php endforeach; ?>
  <p class="muted">Les autres classes sont anonymes. Attention : la richesse dépend aussi du nombre d'élèves et du temps passé sur le terrain.</p>
<?php endif; ?>
</main>
</body></html>
