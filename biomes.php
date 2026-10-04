<?php
declare(strict_types=1);
require_once __DIR__ . '/lib.php';

$stu = current_student();
if (!$stu) { header('Location: index.php'); exit; }

layout_head('Mes observations', 'student');
?>
<header class="top">
  <div><strong>🌿 <?= h(SITE_TITLE) ?></strong></div>
  <div class="who"><?= h($stu['name']) ?> · <?= h($stu['class_name']) ?> · <a href="logout.php">Quitter</a></div>
</header>
<main class="wrap">
  <div id="tabs" class="tabs" role="tablist" aria-label="Biomes"></div>
  <section id="panel" class="card" aria-live="polite"><p class="muted">Chargement…</p></section>
</main>
<script>window.CSRF = <?= json_encode(csrf()) ?>;</script>
<script src="assets/app.js"></script>
</body></html>
