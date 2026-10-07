<?php
// ===== À MODIFIER avant de déposer sur le serveur =====
const DB_HOST = 'localhost';
const DB_NAME = 'nom_de_la_base';
const DB_USER = 'utilisateur_mysql';
const DB_PASS = 'mot_de_passe_mysql';

const SITE_TITLE = 'Biodiversité en sortie';

// Chaîne aléatoire longue (sert à anonymiser les adresses IP). Ne plus la changer ensuite :
// sinon toutes les liaisons nom/IP seraient perdues.
const IP_SALT = 'REMPLACE-MOI-PAR-UNE-LONGUE-CHAINE-ALEATOIRE';

// Clé demandée par install.php (protège l'installation). Choisis ce que tu veux.
const INSTALL_KEY = 'REMPLACE-MOI';

// Si le site est derrière un proxy/CDN qui transmet la vraie IP dans X-Forwarded-For,
// passe à true. Sinon laisse false (plus sûr : l'en-tête peut être falsifié).
const TRUST_PROXY_HEADER = false;
