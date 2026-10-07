<?php
declare(strict_types=1);
require_once __DIR__ . '/lib.php';
require_admin();

$db = pdo();
$classes = $db->query('SELECT id, name FROM classes ORDER BY name')->fetchAll();
$cid = (int)($_GET['class_id'] ?? 0);
$scopeName = 'Toutes les classes';
foreach ($classes as $c) if ((int)$c['id'] === $cid) $scopeName = $c['name'];
if ($scopeName === 'Toutes les classes') $cid = 0;

$biomes = $db->query('SELECT id, name, emoji FROM biomes ORDER BY position, id')->fetchAll();

// Fréquence de chaque espèce = nombre d'élèves l'ayant signalée dans le biome
$sql = 'SELECT o.biome_id, o.name_key, MIN(o.name) AS name, COUNT(*) AS cnt
        FROM observations o JOIN students s ON s.id = o.student_id'
     . ($cid ? ' WHERE s.class_id = ?' : '')
     . ' GROUP BY o.biome_id, o.name_key';
$st = $db->prepare($sql);
$st->execute($cid ? [$cid] : []);
$rows = $st->fetchAll();

$sp = [];                 // biome_id => [name_key => ['name'=>, 'cnt'=>]]
foreach ($rows as $r) $sp[(int)$r['biome_id']][$r['name_key']] = ['name' => $r['name'], 'cnt' => (int)$r['cnt']];

$pq = $db->prepare('SELECT o.biome_id, COUNT(DISTINCT o.student_id) FROM observations o JOIN students s ON s.id=o.student_id'
    . ($cid ? ' WHERE s.class_id = ?' : '') . ' GROUP BY o.biome_id');
$pq->execute($cid ? [$cid] : []);
$participants = $pq->fetchAll(PDO::FETCH_KEY_PAIR);

$stats = [];
foreach ($biomes as $b) {
    $bid = (int)$b['id'];
    $list = $sp[$bid] ?? [];
    $S = count($list);
    $N = array_sum(array_column($list, 'cnt'));
    $H = 0.0;
    foreach ($list as $x) { $p = $x['cnt'] / $N; $H -= $p * log($p); }
    $J = $S > 1 ? $H / log($S) : null;
    uasort($list, fn($a, $b2) => $b2['cnt'] <=> $a['cnt'] ?: strcmp($a['name'], $b2['name']));
    $stats[$bid] = ['b' => $b, 'S' => $S, 'N' => $N, 'J' => $J, 'P' => (int)($participants[$bid] ?? 0), 'top' => array_slice($list, 0, 5, true)];
}

$maxS = max(1, ...array_map(fn($x) => $x['S'], $stats ?: [['S' => 1]]));
$byS = $stats; uasort($byS, fn($a, $b2) => $b2['S'] <=> $a['S']);
$ids = array_keys($stats);

layout_head('Bilan', 'admin');
?>
<header class="top">
  <div><strong>Bilan de biodiversité</strong></div>
  <nav class="who"><a href="admin.php">← Administration</a></nav>
</header>
<main class="wrap">
  <form method="get" class="card row">
    <label>Données prises en compte
      <select name="class_id" onchange="this.form.submit()">
        <option value="0">Toutes les classes</option>
        <?php foreach ($classes as $c): ?>
          <option value="<?= (int)$c['id'] ?>" <?= (int)$c['id'] === $cid ? 'selected' : '' ?>><?= h($c['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <noscript><button class="btn small">Afficher</button></noscript>
    <a class="btn small" href="export.php?class_id=<?= $cid ?>">⬇ Export CSV</a>
    <button class="btn small" type="button" onclick="window.print()">Imprimer</button>
  </form>

  <?php if (!$biomes): ?><p class="card muted">Aucun biome défini.</p><?php else: ?>

  <section class="card">
    <h2>Biodiversité par biome — <?= h($scopeName) ?></h2>
    <div class="grid">
      <?php foreach ($stats as $x): $b = $x['b']; ?>
        <article class="biome-card">
          <h3><?= h(($b['emoji'] ? $b['emoji'] . ' ' : '') . $b['name']) ?></h3>
          <dl>
            <dt>Richesse spécifique</dt><dd class="big"><?= $x['S'] ?></dd>
            <dt>Équitabilité de Pielou (J)</dt><dd class="big"><?= $x['J'] === null ? '—' : number_format($x['J'], 2, ',', '') ?></dd>
            <dt>Élèves ayant participé</dt><dd><?= $x['P'] ?></dd>
            <dt>Signalements cumulés</dt><dd><?= $x['N'] ?></dd>
          </dl>
          <?php if ($x['top']): ?>
            <p class="muted small">Les plus signalés :
              <?= h(implode(', ', array_map(fn($t) => $t['name'] . ' (' . $t['cnt'] . ')', $x['top']))) ?></p>
          <?php endif; ?>
        </article>
      <?php endforeach; ?>
    </div>
  </section>

  <section class="card">
    <h2>Comparaison</h2>
    <h3>Richesse spécifique (nombre d'espèces)</h3>
    <?php foreach ($byS as $x): ?>
      <div class="bar-row">
        <span class="bar-label"><?= h(($x['b']['emoji'] ? $x['b']['emoji'] . ' ' : '') . $x['b']['name']) ?></span>
        <span class="bar"><span style="width:<?= round(100 * $x['S'] / $maxS, 1) ?>%"></span></span>
        <span class="bar-val"><?= $x['S'] ?></span>
      </div>
    <?php endforeach; ?>

    <h3>Équitabilité de Pielou (0 = une espèce domine, 1 = répartition équilibrée)</h3>
    <?php foreach ($stats as $x): ?>
      <div class="bar-row">
        <span class="bar-label"><?= h(($x['b']['emoji'] ? $x['b']['emoji'] . ' ' : '') . $x['b']['name']) ?></span>
        <span class="bar alt"><span style="width:<?= $x['J'] === null ? 0 : round(100 * $x['J'], 1) ?>%"></span></span>
        <span class="bar-val"><?= $x['J'] === null ? '—' : number_format($x['J'], 2, ',', '') ?></span>
      </div>
    <?php endforeach; ?>

    <h3>Classement</h3>
    <table>
      <thead><tr><th>#</th><th>Biome</th><th>Richesse S</th><th>Pielou J</th></tr></thead>
      <tbody>
      <?php $rank = 0; foreach ($byS as $x): $rank++; ?>
        <tr><td><?= $rank ?></td><td><?= h($x['b']['name']) ?></td><td><?= $x['S'] ?></td>
            <td><?= $x['J'] === null ? '—' : number_format($x['J'], 2, ',', '') ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>

    <?php if (count($ids) > 1): ?>
    <h3>Espèces communes entre biomes</h3>
    <div class="scroll"><table class="matrix">
      <thead><tr><th></th><?php foreach ($ids as $i): ?><th><?= h($stats[$i]['b']['name']) ?></th><?php endforeach; ?></tr></thead>
      <tbody>
      <?php foreach ($ids as $i): ?>
        <tr><th><?= h($stats[$i]['b']['name']) ?></th>
        <?php foreach ($ids as $j):
            $A = $sp[$i] ?? []; $B = $sp[$j] ?? [];
            $n = $i === $j ? count($A) : count(array_intersect_key($A, $B)); ?>
          <td class="<?= $i === $j ? 'diag' : '' ?>"><?= $n ?></td>
        <?php endforeach; ?></tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <?php endif; ?>
  </section>

  <section class="card note">
    <h3>Comment lire ces indices</h3>
    <p><strong>Richesse spécifique (S)</strong> : nombre d'espèces différentes notées dans le biome (noms identiques sans tenir compte des majuscules ni des accents).</p>
    <p><strong>Équitabilité de Pielou (J = H′ / ln S)</strong> : comme les élèves saisissent seulement des noms, l'« abondance » d'une espèce est approchée par le <em>nombre d'élèves qui l'ont signalée</em>. J proche de 1 : les espèces sont signalées à peu près aussi souvent ; proche de 0 : quelques espèces dominent. Non défini si S &lt; 2.</p>
    <p class="muted">Attention : la richesse dépend aussi de l'effort d'observation (nombre d'élèves, temps passé) — le nombre de participants est affiché pour nuancer la comparaison. Deux élèves qui écrivent le même être vivant de façon différente (« merle » / « merle noir ») comptent pour deux espèces.</p>
  </section>
  <?php endif; ?>
</main>
</body></html>
