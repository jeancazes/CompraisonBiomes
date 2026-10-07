<?php
declare(strict_types=1);
require_once __DIR__ . '/lib.php';

$db = pdo();
$schema = [
"CREATE TABLE IF NOT EXISTS settings (
  k VARCHAR(50) NOT NULL PRIMARY KEY,
  v TEXT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
"CREATE TABLE IF NOT EXISTS classes (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(80) NOT NULL,
  slug VARCHAR(60) NOT NULL UNIQUE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
"CREATE TABLE IF NOT EXISTS students (
  id INT AUTO_INCREMENT PRIMARY KEY,
  class_id INT NOT NULL,
  name VARCHAR(80) NOT NULL,
  device_hash CHAR(64) NULL,
  bound_at DATETIME NULL,
  UNIQUE KEY uq_class_name (class_id, name),
  CONSTRAINT fk_st_class FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
"CREATE TABLE IF NOT EXISTS student_ips (
  student_id INT NOT NULL,
  ip_hash CHAR(64) NOT NULL,
  first_seen DATETIME NOT NULL,
  last_seen DATETIME NOT NULL,
  PRIMARY KEY (student_id, ip_hash),
  CONSTRAINT fk_ip_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
"CREATE TABLE IF NOT EXISTS biomes (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(80) NOT NULL,
  emoji VARCHAR(16) NOT NULL DEFAULT '',
  description VARCHAR(255) NOT NULL DEFAULT '',
  position INT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
"CREATE TABLE IF NOT EXISTS observations (
  id INT AUTO_INCREMENT PRIMARY KEY,
  biome_id INT NOT NULL,
  student_id INT NOT NULL,
  name VARCHAR(120) NOT NULL,
  name_key VARCHAR(120) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_obs (biome_id, student_id, name_key),
  KEY idx_biome_key (biome_id, name_key),
  CONSTRAINT fk_obs_biome FOREIGN KEY (biome_id) REFERENCES biomes(id) ON DELETE CASCADE,
  CONSTRAINT fk_obs_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
];
foreach ($schema as $sql) $db->exec($sql);
migrate_schema($db);   // colonnes/tables ajoutées par les mises à jour (milieu, validation, revue des noms)

// Migration depuis l'ancienne version (liaison par IP) : on passe à la liaison par appareil
$cols = $db->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'students'")->fetchAll(PDO::FETCH_COLUMN);
if (in_array('ip_hash', $cols, true) && !in_array('device_hash', $cols, true)) {
    $db->exec('ALTER TABLE students DROP COLUMN ip_hash, ADD COLUMN device_hash CHAR(64) NULL AFTER name');
    $db->exec('UPDATE students SET bound_at = NULL');
}

$msg = '';
$done = setting('admin_hash') !== null;

if (!$done && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $key = (string)($_POST['key'] ?? '');
    $pw = (string)($_POST['pw'] ?? '');
    if (!hash_equals(INSTALL_KEY, $key)) {
        $msg = "Clé d'installation incorrecte.";
    } elseif (mb_strlen($pw) < 8) {
        $msg = 'Mot de passe trop court (8 caractères minimum).';
    } else {
        set_setting('admin_hash', password_hash($pw, PASSWORD_DEFAULT));
        $done = true;
        $msg = 'ok';
    }
}

layout_head('Installation');
echo '<main class="card narrow"><h1>Installation</h1>';
if ($done) {
    echo '<p class="ok">✅ Base prête et mot de passe administrateur défini.</p>',
         '<p><strong>Supprime maintenant <code>install.php</code> du serveur.</strong></p>',
         '<p><a class="btn" href="admin.php">Aller à l\'administration</a></p>';
} else {
    if ($msg) echo '<p class="err">', h($msg), '</p>';
    echo '<p>Tables créées. Choisis le mot de passe administrateur :</p>',
         '<form method="post">', csrf_field(),
         '<label>Clé d\'installation (définie dans config.php)<input type="password" name="key" required></label>',
         '<label>Mot de passe administrateur (8 car. min.)<input type="password" name="pw" minlength="8" required></label>',
         '<button class="btn" type="submit">Terminer</button></form>';
}
echo '</main></body></html>';
