<?php
declare(strict_types=1);
require_once __DIR__ . '/lib.php';
require_admin();

$db = pdo();
$cid = (int)($_GET['class_id'] ?? 0);
$where = $cid ? ' WHERE s.class_id = ?' : '';
$args = $cid ? [$cid] : [];

$st = $db->prepare('SELECT o.biome_id, o.name_key, MIN(o.name) AS name, COUNT(*) AS cnt
    FROM observations o JOIN students s ON s.id=o.student_id' . $where . ' GROUP BY o.biome_id, o.name_key');
$st->execute($args);
$sp = [];
foreach ($st->fetchAll() as $r) $sp[(int)$r['biome_id']][] = ['name' => $r['name'], 'cnt' => (int)$r['cnt']];

function csv_safe(string $s): string { return preg_match('/^[=+\-@\t\r]/', $s) ? "'" . $s : $s; }

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="bilan-biodiversite-' . date('Ymd') . '.csv"');
$o = fopen('php://output', 'w');
fwrite($o, "\xEF\xBB\xBF");           // BOM pour Excel
fputcsv($o, ['Biome', 'Richesse S', 'Signalements N', 'Pielou J', 'Espèce', 'Nb élèves'], ';');
foreach ($db->query('SELECT id, name FROM biomes ORDER BY position, id')->fetchAll() as $b) {
    $list = $sp[(int)$b['id']] ?? [];
    $S = count($list); $N = array_sum(array_column($list, 'cnt')); $H = 0.0;
    foreach ($list as $x) { $p = $x['cnt'] / $N; $H -= $p * log($p); }
    $J = $S > 1 ? number_format($H / log($S), 3, ',', '') : '';
    usort($list, fn($a, $c) => $c['cnt'] <=> $a['cnt'] ?: strcmp($a['name'], $c['name']));
    if (!$list) { fputcsv($o, [csv_safe($b['name']), 0, 0, '', '', ''], ';'); continue; }
    foreach ($list as $x) fputcsv($o, [csv_safe($b['name']), $S, $N, $J, csv_safe($x['name']), $x['cnt']], ';');
}
