<?php
/* =========================================================================
   MAXIN CHICKEN — Cœur de l'API
   Connexion base de données, installation automatique, utilitaires,
   sécurité de l'espace administrateur.
   Configuration : copiez config.sample.php vers config.php
   ========================================================================= */

error_reporting(E_ALL);
ini_set('display_errors', '0');

date_default_timezone_set('Africa/Casablanca');

define('API_ROOT', __DIR__);
$configFile = API_ROOT . '/config.php';

function sendJson($httpCode, $data) {
    http_response_code($httpCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function ok($data = ['ok' => true]) {
    sendJson(200, $data);
}

function fail($httpCode, $message) {
    sendJson($httpCode, ['error' => $message]);
}

if (!is_file($configFile)) {
    fail(500, "Configuration manquante : copiez api/config.sample.php vers api/config.php et renseignez les accès à votre base de données (voir DEPLOIEMENT.md).");
}
$config = require $configFile;

/* ---------- Connexion à la base (MySQL ou SQLite) ---------- */
try {
    if (isset($config['driver']) && $config['driver'] === 'sqlite') {
        $sqlitePath = !empty($config['sqlite']['path']) ? $config['sqlite']['path'] : API_ROOT . '/data/maxin.sqlite';
        $dataDir = dirname($sqlitePath);
        if (!is_dir($dataDir)) { @mkdir($dataDir, 0775, true); }
        $db = new PDO('sqlite:' . $sqlitePath);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec('PRAGMA journal_mode=WAL');
        $db->exec('PRAGMA busy_timeout=5000');
        $DRIVER = 'sqlite';
    } else {
        $m = $config['mysql'];
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $m['host'], !empty($m['port']) ? (int)$m['port'] : 3306, $m['database'],
            !empty($m['charset']) ? $m['charset'] : 'utf8mb4');
        $db = new PDO($dsn, $m['user'], $m['password']);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $DRIVER = 'mysql';
    }
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    fail(500, 'Connexion à la base de données impossible. Vérifiez api/config.php.');
}

/* Installation automatique (tables + données initiales) au premier appel */
installDatabase($db, $DRIVER);

/* ---------- Démarrage de session (administration) ---------- */
$secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
session_name('MAXINADM');
if (function_exists('session_set_cookie_params') && PHP_VERSION_ID >= 70300) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
} else {
    session_set_cookie_params(0, '/; samesite=Lax', '', $secure, true);
}
session_start();

/* ---------- Utilitaires ---------- */

function bodyJson() {
    $raw = file_get_contents('php://input');
    if ($raw === '' || $raw === false) return is_array($_POST) ? $_POST : [];
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function strField($arr, $key, $default = '') {
    return (isset($arr[$key]) && is_scalar($arr[$key])) ? trim((string)$arr[$key]) : $default;
}

function numField($arr, $key, $default = 0) {
    return (isset($arr[$key]) && is_numeric($arr[$key])) ? (float)$arr[$key] : $default;
}

function clientIp() {
    return isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';
}

function randomRef($prefix) {
    return $prefix . '-' . random_int(100000, 999999);
}

function uniqueRef($db, $prefix) {
    $ref = randomRef($prefix);
    $stmt = $db->prepare('SELECT ref FROM contact_requests WHERE ref = ?');
    $stmt->execute([$ref]);
    $tries = 0;
    while ($stmt->fetch(PDO::FETCH_ASSOC)) {
        $ref = randomRef($prefix);
        $stmt->execute([$ref]);
        if (++$tries > 10) break;
    }
    return $ref;
}

/* Journal d'activités : toute action notable est conservée en base. */
function logActivity($db, $actor, $detail, $type, $description, $data = null) {
    $stmt = $db->prepare('INSERT INTO activity_log (created_at, actor, actor_detail, type, description, data, ip) VALUES (?,?,?,?,?,?,?)');
    $stmt->execute([
        date('Y-m-d H:i:s'),
        $actor,
        $detail,
        $type,
        $description,
        $data === null ? null : json_encode($data, JSON_UNESCAPED_UNICODE),
        clientIp()
    ]);
}

/* =========================================================================
   CRÉATION DES TABLES + DONNÉES INITIALES
   ========================================================================= */

function tablesExist($db) {
    try {
        $db->query('SELECT 1 FROM settings LIMIT 1');
        return true;
    } catch (PDOException $e) {
        return false;
    }
}

function installDatabase($db, $driver) {
    if (tablesExist($db)) return;

    if ($driver === 'mysql') {
        $db->exec("CREATE TABLE IF NOT EXISTS settings (
            skey VARCHAR(64) NOT NULL PRIMARY KEY,
            svalue TEXT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $db->exec("CREATE TABLE IF NOT EXISTS menu_items (
            id BIGINT NOT NULL PRIMARY KEY,
            name VARCHAR(255) NOT NULL,
            category VARCHAR(32) NOT NULL DEFAULT 'indian',
            price DECIMAL(10,2) NOT NULL DEFAULT 0,
            is_promo TINYINT(1) NOT NULL DEFAULT 0,
            promo_price DECIMAL(10,2) NULL,
            is_popular TINYINT(1) NOT NULL DEFAULT 0,
            sold_out TINYINT(1) NOT NULL DEFAULT 0,
            img MEDIUMTEXT NULL,
            descr MEDIUMTEXT NULL,
            position INT NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $db->exec("CREATE TABLE IF NOT EXISTS sides (
            idx INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            pkey VARCHAR(32) NOT NULL,
            sid VARCHAR(32) NOT NULL,
            name VARCHAR(255) NOT NULL,
            price DECIMAL(10,2) NOT NULL DEFAULT 0,
            sold_out TINYINT(1) NOT NULL DEFAULT 0,
            position INT NOT NULL DEFAULT 0,
            UNIQUE KEY uq_sides (pkey, sid)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $db->exec("CREATE TABLE IF NOT EXISTS boxes (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(255) NOT NULL,
            img MEDIUMTEXT NULL,
            descr MEDIUMTEXT NULL,
            old_price DECIMAL(10,2) NOT NULL DEFAULT 0,
            promo_price DECIMAL(10,2) NULL,
            promo_text VARCHAR(255) NULL,
            start_date VARCHAR(10) NULL,
            end_date VARCHAR(10) NULL,
            position INT NOT NULL DEFAULT 0,
            enabled TINYINT(1) NOT NULL DEFAULT 1
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $db->exec("CREATE TABLE IF NOT EXISTS vip_members (
            card_number VARCHAR(64) NOT NULL PRIMARY KEY,
            name VARCHAR(255) NOT NULL,
            phone VARCHAR(32) NOT NULL,
            email VARCHAR(255) NULL,
            date_inscription VARCHAR(10) NULL,
            niveau VARCHAR(16) NOT NULL DEFAULT 'Bronze',
            statut VARCHAR(16) NOT NULL DEFAULT 'actif',
            notes TEXT NULL,
            orders INT NOT NULL DEFAULT 0,
            points INT NOT NULL DEFAULT 0,
            created_at VARCHAR(19) NULL,
            updated_at VARCHAR(19) NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $db->exec("CREATE TABLE IF NOT EXISTS orders (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            ref VARCHAR(32) NOT NULL,
            name VARCHAR(255) NOT NULL,
            phone VARCHAR(32) NOT NULL,
            vip_card VARCHAR(64) NULL,
            order_type VARCHAR(64) NULL,
            address TEXT NULL,
            notes TEXT NULL,
            subtotal DECIMAL(10,2) NOT NULL DEFAULT 0,
            discount_percent DECIMAL(5,2) NOT NULL DEFAULT 0,
            discount_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
            fee DECIMAL(10,2) NOT NULL DEFAULT 0,
            total DECIMAL(10,2) NOT NULL DEFAULT 0,
            items_json MEDIUMTEXT NULL,
            statut VARCHAR(32) NOT NULL DEFAULT 'Nouvelle',
            created_at VARCHAR(19) NULL,
            INDEX idx_orders_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $db->exec("CREATE TABLE IF NOT EXISTS contact_requests (
            ref VARCHAR(32) NOT NULL PRIMARY KEY,
            created_at VARCHAR(19) NULL,
            name VARCHAR(255) NOT NULL,
            company VARCHAR(255) NULL,
            phone VARCHAR(32) NOT NULL,
            email VARCHAR(255) NULL,
            subject VARCHAR(255) NOT NULL,
            category VARCHAR(64) NULL,
            descr MEDIUMTEXT NULL,
            budget VARCHAR(64) NULL,
            date_souhaitee VARCHAR(32) NULL,
            attachment MEDIUMTEXT NULL,
            consent TINYINT(1) NOT NULL DEFAULT 0,
            statut VARCHAR(32) NOT NULL DEFAULT 'Nouvelle',
            notes TEXT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $db->exec("CREATE TABLE IF NOT EXISTS newsletter_subscribers (
            email VARCHAR(255) NOT NULL PRIMARY KEY,
            prenom VARCHAR(255) NULL,
            created_at VARCHAR(10) NOT NULL,
            consent TINYINT(1) NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $db->exec("CREATE TABLE IF NOT EXISTS newsletter_campaigns (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            created_at VARCHAR(10) NOT NULL,
            titre VARCHAR(255) NOT NULL,
            img MEDIUMTEXT NULL,
            link VARCHAR(500) NULL,
            text MEDIUMTEXT NULL,
            envois INT NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $db->exec("CREATE TABLE IF NOT EXISTS partners (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(255) NOT NULL,
            logo MEDIUMTEXT NULL,
            website VARCHAR(500) NULL,
            email VARCHAR(255) NULL,
            category VARCHAR(64) NULL,
            sort INT NOT NULL DEFAULT 0,
            published TINYINT(1) NOT NULL DEFAULT 1,
            descr TEXT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $db->exec("CREATE TABLE IF NOT EXISTS activity_log (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            created_at VARCHAR(19) NOT NULL,
            actor VARCHAR(16) NOT NULL,
            actor_detail VARCHAR(255) NULL,
            type VARCHAR(48) NOT NULL,
            description TEXT NULL,
            data MEDIUMTEXT NULL,
            ip VARCHAR(64) NULL,
            INDEX idx_activity_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $db->exec("CREATE TABLE IF NOT EXISTS login_attempts (
            ip VARCHAR(64) NOT NULL,
            created_at VARCHAR(19) NOT NULL,
            ok TINYINT(1) NOT NULL DEFAULT 0,
            INDEX idx_login_attempts (ip, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } else {
        $db->exec('CREATE TABLE IF NOT EXISTS settings (skey TEXT PRIMARY KEY, svalue TEXT)');
        $db->exec('CREATE TABLE IF NOT EXISTS menu_items (id INTEGER PRIMARY KEY, name TEXT, category TEXT, price REAL, is_promo INTEGER, promo_price REAL, is_popular INTEGER, sold_out INTEGER, img TEXT, descr TEXT, position INTEGER)');
        $db->exec('CREATE TABLE IF NOT EXISTS sides (idx INTEGER PRIMARY KEY AUTOINCREMENT, pkey TEXT, sid TEXT, name TEXT, price REAL, sold_out INTEGER, position INTEGER, UNIQUE(pkey, sid))');
        $db->exec('CREATE TABLE IF NOT EXISTS boxes (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, img TEXT, descr TEXT, old_price REAL, promo_price REAL, promo_text TEXT, start_date TEXT, end_date TEXT, position INTEGER, enabled INTEGER)');
        $db->exec('CREATE TABLE IF NOT EXISTS vip_members (card_number TEXT PRIMARY KEY, name TEXT, phone TEXT, email TEXT, date_inscription TEXT, niveau TEXT, statut TEXT, notes TEXT, orders INTEGER, points INTEGER, created_at TEXT, updated_at TEXT)');
        $db->exec('CREATE TABLE IF NOT EXISTS orders (id INTEGER PRIMARY KEY AUTOINCREMENT, ref TEXT, name TEXT, phone TEXT, vip_card TEXT, order_type TEXT, address TEXT, notes TEXT, subtotal REAL, discount_percent REAL, discount_amount REAL, fee REAL, total REAL, items_json TEXT, statut TEXT, created_at TEXT)');
        $db->exec('CREATE TABLE IF NOT EXISTS contact_requests (ref TEXT PRIMARY KEY, created_at TEXT, name TEXT, company TEXT, phone TEXT, email TEXT, subject TEXT, category TEXT, descr TEXT, budget TEXT, date_souhaitee TEXT, attachment TEXT, consent INTEGER, statut TEXT, notes TEXT)');
        $db->exec('CREATE TABLE IF NOT EXISTS newsletter_subscribers (email TEXT PRIMARY KEY, prenom TEXT, created_at TEXT, consent INTEGER)');
        $db->exec('CREATE TABLE IF NOT EXISTS newsletter_campaigns (id INTEGER PRIMARY KEY AUTOINCREMENT, created_at TEXT, titre TEXT, img TEXT, link TEXT, text TEXT, envois INTEGER)');
        $db->exec('CREATE TABLE IF NOT EXISTS partners (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, logo TEXT, website TEXT, email TEXT, category TEXT, sort INTEGER, published INTEGER, descr TEXT)');
        $db->exec('CREATE TABLE IF NOT EXISTS activity_log (id INTEGER PRIMARY KEY AUTOINCREMENT, created_at TEXT, actor TEXT, actor_detail TEXT, type TEXT, description TEXT, data TEXT, ip TEXT)');
        $db->exec('CREATE TABLE IF NOT EXISTS login_attempts (ip TEXT, created_at TEXT, ok INTEGER)');
    }

    /* Données initiales : uniquement si le menu est vide */
    $count = (int)$db->query('SELECT COUNT(*) FROM menu_items')->fetchColumn();
    if ($count === 0) {
        $seed = require API_ROOT . '/seed-data.php';
        seedDatabase($db, $seed);
        logActivity($db, 'system', 'site', 'install', 'Initialisation automatique de la base de données');
    }
}

function seedDatabase($db, $seed) {
    $menuStmt = $db->prepare('INSERT INTO menu_items (id, name, category, price, is_promo, promo_price, is_popular, sold_out, img, descr, position) VALUES (?,?,?,?,?,?,?,?,?,?,?)');
    $pos = 0;
    foreach ($seed['menu'] as $item) {
        $pos++;
        $menuStmt->execute([
            (int)$item['id'],
            $item['name'],
            $item['category'],
            (float)$item['price'],
            !empty($item['isPromo']) ? 1 : 0,
            (isset($item['promoPrice']) && $item['promoPrice'] !== null) ? (float)$item['promoPrice'] : null,
            !empty($item['isPopular']) ? 1 : 0,
            !empty($item['soldOut']) ? 1 : 0,
            (isset($item['img']) && $item['img'] !== '') ? $item['img'] : '',
            $item['desc'],
            $pos
        ]);
    }
    $db->exec('DELETE FROM sides');
    $sideStmt = $db->prepare('INSERT INTO sides (pkey, sid, name, price, sold_out, position) VALUES (?,?,?,?,?,?)');
    $pos = 0;
    foreach ($seed['sides'] as $pkey => $items) {
        foreach ($items as $it) {
            $pos++;
            $sideStmt->execute([
                $pkey, $it['id'], $it['name'],
                (float)(isset($it['price']) ? $it['price'] : 0),
                !empty($it['soldOut']) ? 1 : 0,
                $pos
            ]);
        }
    }
    $db->exec('DELETE FROM boxes');
    $boxStmt = $db->prepare('INSERT INTO boxes (name, img, descr, old_price, promo_price, promo_text, start_date, end_date, position, enabled) VALUES (?,?,?,?,?,?,?,?,?,?)');
    $pos = 0;
    foreach ($seed['boxes'] as $b) {
        $pos++;
        $boxStmt->execute([
            $b['name'], $b['img'], $b['desc'], (float)$b['oldPrice'],
            (isset($b['promoPrice']) && $b['promoPrice'] !== null) ? (float)$b['promoPrice'] : null,
            isset($b['promoText']) ? $b['promoText'] : '', '', '', $pos, 1
        ]);
    }
}

/* =========================================================================
   ACCÈS AUX DONNÉES
   ========================================================================= */

function getMenu($db) {
    $rows = $db->query('SELECT * FROM menu_items ORDER BY position, id')->fetchAll(PDO::FETCH_ASSOC);
    $out = [];
    foreach ($rows as $r) {
        $out[] = [
            'id' => (int)$r['id'],
            'name' => $r['name'],
            'category' => $r['category'],
            'price' => (float)$r['price'],
            'isPromo' => (bool)$r['is_promo'],
            'promoPrice' => $r['promo_price'] === null ? null : (float)$r['promo_price'],
            'isPopular' => (bool)$r['is_popular'],
            'soldOut' => (bool)$r['sold_out'],
            'img' => $r['img'],
            'desc' => $r['descr']
        ];
    }
    return $out;
}

function getSides($db) {
    $rows = $db->query('SELECT * FROM sides ORDER BY position, idx')->fetchAll(PDO::FETCH_ASSOC);
    $out = ['sauces' => [], 'accompaniments' => [], 'drinks' => []];
    foreach ($rows as $r) {
        $key = array_key_exists($r['pkey'], $out) ? $r['pkey'] : 'sauces';
        $out[$key][] = [
            'id' => $r['sid'],
            'name' => $r['name'],
            'price' => (float)$r['price'],
            'soldOut' => (bool)$r['sold_out']
        ];
    }
    return $out;
}

function getBoxes($db) {
    $rows = $db->query('SELECT * FROM boxes ORDER BY position, id')->fetchAll(PDO::FETCH_ASSOC);
    $out = [];
    foreach ($rows as $r) {
        $out[] = [
            'id' => (int)$r['id'],
            'name' => $r['name'],
            'img' => $r['img'],
            'desc' => $r['descr'],
            'oldPrice' => (float)$r['old_price'],
            'promoPrice' => $r['promo_price'] === null ? null : (float)$r['promo_price'],
            'promoText' => $r['promo_text'],
            'startDate' => $r['start_date'],
            'endDate' => $r['end_date'],
            'position' => (int)$r['position'],
            'enabled' => (bool)$r['enabled']
        ];
    }
    return $out;
}

function getPartners($db) {
    $rows = $db->query('SELECT * FROM partners ORDER BY sort, id')->fetchAll(PDO::FETCH_ASSOC);
    $out = [];
    foreach ($rows as $r) {
        $out[] = [
            'id' => (int)$r['id'],
            'name' => $r['name'],
            'logo' => $r['logo'],
            'website' => $r['website'],
            'email' => $r['email'],
            'category' => $r['category'],
            'order' => (int)$r['sort'],
            'published' => (bool)$r['published'],
            'desc' => $r['descr']
        ];
    }
    return $out;
}

function getVipList($db) {
    $rows = $db->query('SELECT * FROM vip_members ORDER BY created_at DESC, card_number')->fetchAll(PDO::FETCH_ASSOC);
    $out = [];
    foreach ($rows as $r) {
        $out[] = [
            'cardNumber' => $r['card_number'], 'name' => $r['name'], 'phone' => $r['phone'],
            'email' => $r['email'], 'dateInscription' => $r['date_inscription'],
            'niveau' => $r['niveau'], 'statut' => $r['statut'], 'notes' => $r['notes'],
            'orders' => (int)$r['orders'], 'points' => (int)$r['points']
        ];
    }
    return $out;
}

function findVip($db, $card) {
    foreach (getVipList($db) as $vm) {
        if ($vm['cardNumber'] === $card) return $vm;
    }
    return null;
}

function getSettings($db) {
    $rows = $db->query('SELECT skey, svalue FROM settings')->fetchAll(PDO::FETCH_ASSOC);
    $map = [];
    foreach ($rows as $r) { $map[$r['skey']] = $r['svalue']; }

    $defaults = [
        'delivery_fee'         => '0',
        'whatsapp_number'      => '212707612080',
        'announcement_text'    => '',
        'promo_codes'          => '{"OUVERTURE15":15}',
        'vip_welcome_points'   => '30',
        'vip_points_per_dh'    => '10',
        'vip_reward_threshold' => '3',
        'admin_pin_hash'       => ''
    ];
    foreach ($defaults as $k => $v) {
        if (!array_key_exists($k, $map)) {
            $ins = $db->prepare('INSERT INTO settings (skey, svalue) VALUES (?,?)');
            $ins->execute([$k, $v]);
            $map[$k] = $v;
        }
    }
    return $map;
}

function setSetting($db, $key, $value) {
    $stmt = $db->prepare('UPDATE settings SET svalue = ? WHERE skey = ?');
    $stmt->execute([$value, $key]);
    if ($stmt->rowCount() === 0) {
        $ins = $db->prepare('INSERT INTO settings (skey, svalue) VALUES (?,?)');
        $ins->execute([$key, $value]);
    }
}

function getPromoCodes($db) {
    $codes = json_decode(getSettings($db)['promo_codes'], true);
    $out = [];
    if (is_array($codes)) {
        foreach ($codes as $code => $percent) {
            if (is_string($code) && is_numeric($percent)) {
                $out[strtoupper(trim($code))] = min(100, max(0, (float)$percent));
            }
        }
    }
    return $out;
}

function getSettingsPublic($db) {
    $s = getSettings($db);
    return [
        'deliveryFee' => (float)$s['delivery_fee'],
        'whatsapp' => $s['whatsapp_number'],
        'announcement' => $s['announcement_text'],
        'promoCodes' => getPromoCodes($db),
        'vip' => [
            'welcomePoints' => (int)$s['vip_welcome_points'],
            'pointsPerDh' => (float)$s['vip_points_per_dh'],
            'rewardThreshold' => (int)$s['vip_reward_threshold']
        ]
    ];
}

function getOrders($db) {
    $rows = $db->query('SELECT * FROM orders ORDER BY id DESC')->fetchAll(PDO::FETCH_ASSOC);
    $out = [];
    foreach ($rows as $r) {
        $r['id'] = (int)$r['id'];
        $r['subtotal'] = (float)$r['subtotal'];
        $r['discount_percent'] = (float)$r['discount_percent'];
        $r['discount_amount'] = (float)$r['discount_amount'];
        $r['fee'] = (float)$r['fee'];
        $r['total'] = (float)$r['total'];
        $r['items'] = json_decode($r['items_json'], true);
        unset($r['items_json']);
        $out[] = $r;
    }
    return $out;
}

function getRequests($db) {
    return $db->query('SELECT * FROM contact_requests ORDER BY created_at DESC, ref')->fetchAll(PDO::FETCH_ASSOC);
}

function getSubscribers($db) {
    return $db->query('SELECT email, prenom, created_at, consent FROM newsletter_subscribers ORDER BY created_at DESC, email')->fetchAll(PDO::FETCH_ASSOC);
}

function getCampaigns($db) {
    return $db->query('SELECT id, created_at, titre, img, link, text, envois FROM newsletter_campaigns ORDER BY id DESC')->fetchAll(PDO::FETCH_ASSOC);
}

function getActivity($db, $limit = 400) {
    $limit = max(1, min(2000, (int)$limit));
    $stmt = $db->prepare('SELECT * FROM activity_log ORDER BY id DESC LIMIT ' . $limit);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) {
        $r['id'] = (int)$r['id'];
        $r['data'] = !empty($r['data']) ? json_decode($r['data'], true) : null;
    }
    return $rows;
}

/* =========================================================================
   SÉCURITÉ ADMIN (session + limite de tentatives)
   ========================================================================= */

function isAdmin() {
    return !empty($_SESSION['maxin_admin']) && $_SESSION['maxin_admin'] === true;
}

function requireAdmin() {
    if (!isAdmin()) fail(401, 'Session administrateur expirée. Reconnectez-vous.');
}

function checkLoginRate($db) {
    $stmt = $db->prepare('SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND ok = 0 AND created_at > ?');
    $stmt->execute([clientIp(), date('Y-m-d H:i:s', time() - 900)]);
    return (int)$stmt->fetchColumn();
}

function recordLoginAttempt($db, $ok) {
    $stmt = $db->prepare('DELETE FROM login_attempts WHERE ip = ? AND created_at < ?');
    $stmt->execute([clientIp(), date('Y-m-d H:i:s', time() - 3600)]);
    $ins = $db->prepare('INSERT INTO login_attempts (ip, created_at, ok) VALUES (?,?,?)');
    $ins->execute([clientIp(), date('Y-m-d H:i:s'), $ok ? 1 : 0]);
}

function getAdminPinHash($db) {
    $s = getSettings($db);
    if (!empty($s['admin_pin_hash'])) return $s['admin_pin_hash'];
    return password_hash('1234', PASSWORD_DEFAULT);
}
