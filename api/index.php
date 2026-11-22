<?php
/* =========================================================================
   MAXIN CHICKEN — Routeur de l'API
   Toutes les actions passent par : /api/?action=...
   Actions publiques : public, promo_validate, order_create, vip_register,
   vip_lookup, contact_create, reservation_create, nl_subscribe, nl_unsubscribe
   Actions admin (session) : voir la section ADMINISTRATEUR ci-dessous
   ========================================================================= */

require __DIR__ . '/bootstrap.php';

$action = isset($_GET['action']) ? (string)$_GET['action'] : '';

switch ($action) {

/* ========================= PUBLIC ========================= */

case 'public': {
    ok([
        'menu' => getMenu($db),
        'sides' => getSides($db),
        'boxes' => getBoxes($db),
        'partners' => getPartners($db),
        'settings' => getSettingsPublic($db)
    ]);
}

case 'promo_validate': {
    $body = bodyJson();
    $code = strtoupper(strField($body, 'code'));
    $codes = getPromoCodes($db);
    if ($code !== '' && isset($codes[$code]) && $codes[$code] > 0) {
        ok(['valid' => true, 'percent' => (float)$codes[$code]]);
    }
    ok(['valid' => false, 'percent' => 0]);
}

case 'order_create': {
    $body = bodyJson();
    $name = strField($body, 'name');
    $phone = strField($body, 'phone');
    $vipCardIn = strtoupper(strField($body, 'vipCard'));
    $orderType = strField($body, 'orderType');
    $address = strField($body, 'address');
    $notes = strField($body, 'notes');
    $promoCode = strtoupper(strField($body, 'promoCode'));
    $items = isset($body['items']) && is_array($body['items']) ? $body['items'] : [];

    if ($name === '') fail(400, 'Nom client manquant.');
    if ($phone === '') fail(400, 'Numéro de téléphone manquant.');
    if ($orderType === 'Livraison à Domicile' && $address === '') fail(400, 'Adresse de livraison manquante.');
    if (count($items) === 0) fail(400, 'Panier vide.');

    /* Fiabilité : le prix des plats du menu est relu depuis la base */
    $menuById = [];
    foreach (getMenu($db) as $mi) { $menuById[(int)$mi['id']] = $mi; }

    $cleanItems = [];
    foreach ($items as $it) {
        if (!is_array($it)) continue;
        $iname = strField($it, 'name', 'Article');
        $iqty = (int)numField($it, 'qty', 1);
        if ($iqty < 1) $iqty = 1;
        if ($iqty > 99) $iqty = 99;
        $iprice = (float)numField($it, 'price', 0);
        if ($iprice < 0) $iprice = 0;
        $miId = (isset($it['menuItemId']) && is_numeric($it['menuItemId'])) ? (int)$it['menuItemId'] : null;
        if ($miId !== null && isset($menuById[$miId])) {
            $mi = $menuById[$miId];
            if (!empty($mi['soldOut'])) fail(409, 'Article actuellement épuisé : ' . $mi['name']);
            $iprice = ($mi['isPromo'] && $mi['promoPrice'] !== null && $mi['promoPrice'] > 0 && $mi['promoPrice'] < $mi['price'])
                ? (float)$mi['promoPrice'] : (float)$mi['price'];
        }
        $cleanItems[] = [
            'name' => mb_substr($iname, 0, 120),
            'price' => round($iprice, 2),
            'qty' => $iqty,
            'menuItemId' => $miId
        ];
    }
    if (count($cleanItems) === 0) fail(400, 'Panier invalide.');

    $settings = getSettings($db);
    $subtotal = 0.0;
    foreach ($cleanItems as $ci) { $subtotal += $ci['price'] * $ci['qty']; }
    $subtotal = round($subtotal, 2);

    $codes = getPromoCodes($db);
    $discountPercent = 0.0;
    if ($promoCode !== '' && isset($codes[$promoCode]) && $codes[$promoCode] > 0) {
        $discountPercent = (float)$codes[$promoCode];
    }
    $discount = $discountPercent > 0 ? round($subtotal * $discountPercent / 100, 2) : 0.0;

    $fee = ($orderType === 'Livraison à Domicile') ? (float)$settings['delivery_fee'] : 0.0;
    $total = round($subtotal - $discount + $fee, 2);

    /* Carte VIP : fournie, sinon dérivée du numéro de téléphone (comportement d'origine) */
    $cleanPhone = preg_replace('/\D/', '', $phone);
    $userCard = ($vipCardIn !== '') ? $vipCardIn : ('VIP-MC-' . (mb_strlen($cleanPhone) >= 4 ? mb_substr($cleanPhone, -4) : '2026'));
    $pointsPerDh = (float)$settings['vip_points_per_dh'];
    $rewardThreshold = (int)$settings['vip_reward_threshold'];
    $pointsGain = $pointsPerDh > 0 ? (int)round($total / $pointsPerDh) : 0;

    /* Fidélité : créée / mise à jour entièrement côté serveur */
    $stmt = $db->prepare('SELECT card_number FROM vip_members WHERE card_number = ?');
    $stmt->execute([$userCard]);
    $existing = $stmt->fetchColumn();

    $now = date('Y-m-d H:i:s');
    if (!$existing) {
        $ins = $db->prepare('INSERT INTO vip_members (card_number, name, phone, email, date_inscription, niveau, statut, notes, orders, points, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)');
        $ins->execute([$userCard, $name, $phone, '', date('Y-m-d'), 'Bronze', 'actif', '', 1, $pointsGain, $now, $now]);
        logActivity($db, 'client', $phone, 'vip_created', 'Nouveau membre VIP ' . $userCard . ' (' . $name . ')', ['card' => $userCard]);
    } else {
        $upd = $db->prepare('UPDATE vip_members SET orders = orders + 1, points = points + ?, name = ?, updated_at = ? WHERE card_number = ?');
        $upd->execute([$pointsGain, $name, $now, $userCard]);
    }
    $vip = findVip($db, $userCard);
    $vipOrdersAfter = $vip ? (int)$vip['orders'] : 1;
    $vipPointsAfter = $vip ? (int)$vip['points'] : $pointsGain;

    /* Enregistrement de la commande */
    $ref = randomRef('MC');
    $stmt = $db->prepare('SELECT ref FROM orders WHERE ref = ?');
    $stmt->execute([$ref]);
    $tries = 0;
    while ($stmt->fetch(PDO::FETCH_ASSOC)) {
        $ref = randomRef('MC');
        $stmt->execute([$ref]);
        if (++$tries > 10) break;
    }

    $insOrder = $db->prepare('INSERT INTO orders (ref, name, phone, vip_card, order_type, address, notes, subtotal, discount_percent, discount_amount, fee, total, items_json, statut, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
    $insOrder->execute([
        $ref, $name, $phone, ($vipCardIn !== '') ? $vipCardIn : null, $orderType,
        $address, $notes, $subtotal, $discountPercent, $discount, $fee, $total,
        json_encode($cleanItems, JSON_UNESCAPED_UNICODE), 'Nouvelle', $now
    ]);

    logActivity($db, 'client', $phone, 'order', 'Commande ' . $ref . ' — ' . $name . ' — ' . $total . ' DH', [
        'ref' => $ref,
        'total' => $total,
        'orderType' => $orderType,
        'vipCard' => ($vipCardIn !== '') ? $vipCardIn : null,
        'items' => array_map(function ($c) { return $c['qty'] . 'x ' . $c['name']; }, $cleanItems)
    ]);

    ok([
        'ref' => $ref,
        'subtotal' => $subtotal,
        'discountPercent' => $discountPercent,
        'discount' => $discount,
        'fee' => $fee,
        'total' => $total,
        'vip' => ['card' => $userCard, 'orders' => $vipOrdersAfter, 'points' => $vipPointsAfter],
        'rewardUnlocked' => $vipOrdersAfter >= $rewardThreshold,
        'rewardThreshold' => $rewardThreshold
    ]);
}

case 'vip_register': {
    $body = bodyJson();
    $name = strField($body, 'name');
    $phone = strField($body, 'phone');
    if ($name === '') fail(400, 'Nom manquant.');
    if ($phone === '') fail(400, 'Numéro de téléphone manquant.');

    $cleanPhone = preg_replace('/\D/', '', $phone);
    $cardNum = 'VIP-MC-' . (mb_strlen($cleanPhone) >= 4 ? mb_substr($cleanPhone, -4) : '2026');

    $settings = getSettings($db);
    $welcomePoints = (int)$settings['vip_welcome_points'];

    $stmt = $db->prepare('SELECT card_number FROM vip_members WHERE card_number = ?');
    $stmt->execute([$cardNum]);
    $existing = $stmt->fetchColumn();

    $now = date('Y-m-d H:i:s');
    if (!$existing) {
        $ins = $db->prepare('INSERT INTO vip_members (card_number, name, phone, email, date_inscription, niveau, statut, notes, orders, points, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)');
        $ins->execute([$cardNum, $name, $phone, '', date('Y-m-d'), 'Bronze', 'actif', '', 1, $welcomePoints, $now, $now]);
        logActivity($db, 'client', $phone, 'vip_register', 'Inscription fidélité VIP : ' . $name . ' — carte ' . $cardNum, ['card' => $cardNum]);
        ok(['created' => true, 'member' => findVip($db, $cardNum), 'welcomePoints' => $welcomePoints]);
    }
    logActivity($db, 'client', $phone, 'vip_lookup', 'Consultation carte VIP existante ' . $cardNum, ['card' => $cardNum]);
    ok(['created' => false, 'member' => findVip($db, $cardNum), 'welcomePoints' => $welcomePoints]);
}

case 'vip_lookup': {
    $q = strField($_GET, 'q');
    if ($q === '') ok(['found' => false]);
    $stmt = $db->prepare('SELECT * FROM vip_members WHERE card_number = ? OR phone LIKE ? LIMIT 1');
    $stmt->execute([$q, '%' . $q . '%']);
    $r = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$r) ok(['found' => false]);
    logActivity($db, 'client', $q, 'vip_lookup', 'Consultation carte VIP ' . $r['card_number'], ['card' => $r['card_number']]);
    ok([
        'found' => true,
        'member' => [
            'cardNumber' => $r['card_number'],
            'name' => $r['name'],
            'points' => (int)$r['points'],
            'orders' => (int)$r['orders']
        ]
    ]);
}

case 'contact_create': {
    $body = bodyJson();
    $name = strField($body, 'name');
    $phone = strField($body, 'phone');
    $email = strField($body, 'email');
    $subject = strField($body, 'subject');
    $category = strField($body, 'category');
    $desc = strField($body, 'desc');
    if ($name === '') fail(400, 'Nom manquant.');
    if ($phone === '') fail(400, 'Téléphone manquant.');
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) fail(400, 'Adresse e-mail invalide.');
    if ($subject === '') fail(400, 'Objet manquant.');
    if ($category === '') fail(400, 'Catégorie manquante.');
    if ($desc === '') fail(400, 'Description manquante.');

    $attachment = null;
    if (isset($body['attachment']) && is_array($body['attachment'])) {
        $att = $body['attachment'];
        $dataUrl = (isset($att['data']) && is_string($att['data'])) ? $att['data'] : '';
        if (strlen($dataUrl) > 4 * 1024 * 1024) fail(413, 'Pièce jointe trop lourde.');
        if (strpos($dataUrl, 'data:image/') !== 0 && strpos($dataUrl, 'data:application/pdf') !== 0) fail(400, 'Type de pièce jointe non autorisé.');
        if (strlen($dataUrl) >= 20) {
            $attachment = [
                'name' => mb_substr(strField($att, 'name', 'fichier'), 0, 200),
                'type' => mb_substr(strField($att, 'type'), 0, 100),
                'size' => (int)numField($att, 'size'),
                'data' => $dataUrl
            ];
        }
    }

    $ref = uniqueRef($db, 'DEM');

    $ins = $db->prepare('INSERT INTO contact_requests (ref, created_at, name, company, phone, email, subject, category, descr, budget, date_souhaitee, attachment, consent, statut, notes) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
    $ins->execute([
        $ref, date('Y-m-d H:i:s'), $name, strField($body, 'company'), $phone, $email, $subject,
        $category, $desc, strField($body, 'budget'), strField($body, 'dateSouhaitee'),
        $attachment === null ? null : json_encode($attachment, JSON_UNESCAPED_UNICODE),
        1, 'Nouvelle', ''
    ]);

    logActivity($db, 'client', $email, 'contact', 'Nouvelle demande ' . $ref . ' : ' . $subject, ['ref' => $ref, 'category' => $category]);
    ok(['ref' => $ref]);
}

case 'reservation_create': {
    $body = bodyJson();
    $name = strField($body, 'name');
    $phone = strField($body, 'phone');
    $date = strField($body, 'date');
    $time = strField($body, 'time');
    $guests = (int)numField($body, 'guests', 2);
    if ($name === '') fail(400, 'Nom manquant.');
    if ($phone === '') fail(400, 'Téléphone manquant.');
    if ($date === '' || $time === '') fail(400, 'Date et heure requises.');
    if ($guests < 1 || $guests > 50) fail(400, 'Nombre de personnes invalide.');

    $ref = uniqueRef($db, 'RES');

    $ins = $db->prepare('INSERT INTO contact_requests (ref, created_at, name, company, phone, email, subject, category, descr, budget, date_souhaitee, attachment, consent, statut, notes) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
    $ins->execute([
        $ref, date('Y-m-d H:i:s'), $name, '', $phone, '',
        'Réservation de table', 'Réservation',
        'Réservation pour ' . $guests . ' personne(s) le ' . $date . ' à ' . $time,
        '', trim($date . ' ' . $time),
        null, 1, 'Nouvelle', ''
    ]);
    logActivity($db, 'client', $phone, 'reservation', 'Réservation ' . $ref . ' : ' . $name . ' — ' . $date . ' ' . $time . ' (' . $guests . ' pers.)', ['ref' => $ref]);
    ok(['ref' => $ref]);
}

case 'nl_subscribe': {
    $body = bodyJson();
    $email = strtolower(strField($body, 'email'));
    $prenom = strField($body, 'prenom');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) fail(400, 'Adresse e-mail invalide.');

    $stmt = $db->prepare('SELECT email FROM newsletter_subscribers WHERE email = ?');
    $stmt->execute([$email]);
    if ($stmt->fetch()) ok(['already' => true]);

    $ins = $db->prepare('INSERT INTO newsletter_subscribers (email, prenom, created_at, consent) VALUES (?,?,?,1)');
    $ins->execute([$email, $prenom, date('Y-m-d')]);
    logActivity($db, 'client', $email, 'newsletter_subscribe', 'Inscription newsletter : ' . $email);
    ok(['already' => false]);
}

case 'nl_unsubscribe': {
    $email = strtolower(strField($_GET, 'email'));
    $removed = false;
    if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $del = $db->prepare('DELETE FROM newsletter_subscribers WHERE email = ?');
        $del->execute([$email]);
        $removed = $del->rowCount() > 0;
        logActivity($db, 'client', $email, 'newsletter_unsubscribe', 'Désabonnement newsletter : ' . $email);
    }
    ok(['removed' => $removed]);
}

/* ========================= ADMINISTRATEUR ========================= */

case 'admin_login': {
    $body = bodyJson();
    $pin = strField($body, 'pin');
    if (checkLoginRate($db) >= 6) {
        logActivity($db, 'admin', '', 'login_blocked', 'Connexion bloquée : trop de tentatives depuis ' . clientIp());
        fail(429, 'Trop de tentatives. Réessayez dans 15 minutes.');
    }
    $okLogin = ($pin !== '') && password_verify($pin, getAdminPinHash($db));
    recordLoginAttempt($db, $okLogin);
    if (!$okLogin) {
        logActivity($db, 'admin', '', 'login_failed', 'Échec de connexion administrateur (PIN incorrect) depuis ' . clientIp());
        fail(401, 'Code PIN invalide.');
    }
    session_regenerate_id(true);
    $_SESSION['maxin_admin'] = true;
    logActivity($db, 'admin', '', 'login', 'Connexion au panneau d administration réussie');
    ok(['ok' => true]);
}

case 'admin_logout': {
    logActivity($db, 'admin', '', 'logout', 'Déconnexion du panneau d administration');
    unset($_SESSION['maxin_admin']);
    ok(['ok' => true]);
}

case 'admin_state': {
    requireAdmin();
    ok([
        'menu' => getMenu($db),
        'sides' => getSides($db),
        'boxes' => getBoxes($db),
        'partners' => getPartners($db),
        'settings' => getSettingsPublic($db),
        'vip' => getVipList($db),
        'requests' => getRequests($db),
        'orders' => getOrders($db),
        'subscribers' => getSubscribers($db),
        'campaigns' => getCampaigns($db),
        'activity' => getActivity($db, 400)
    ]);
}

case 'admin_change_pin': {
    requireAdmin();
    $body = bodyJson();
    $newPin = strField($body, 'newPin');
    if (!preg_match('/^[0-9]{4,6}$/', $newPin)) fail(400, 'Le PIN doit contenir entre 4 et 6 chiffres.');
    setSetting($db, 'admin_pin_hash', password_hash($newPin, PASSWORD_DEFAULT));
    logActivity($db, 'admin', '', 'pin_change', 'Code PIN administrateur modifié');
    ok(['ok' => true]);
}

case 'admin_save_settings': {
    requireAdmin();
    $body = bodyJson();

    if (isset($body['deliveryFee']) && is_numeric($body['deliveryFee'])) {
        $val = max(0, (float)$body['deliveryFee']);
        setSetting($db, 'delivery_fee', (string)$val);
        logActivity($db, 'admin', '', 'settings', 'Frais de livraison modifiés : ' . $val . ' DH');
    }
    if (isset($body['whatsapp'])) {
        $wa = preg_replace('/\D/', '', (string)$body['whatsapp']);
        if ($wa !== '') {
            setSetting($db, 'whatsapp_number', $wa);
            logActivity($db, 'admin', '', 'settings', 'Numéro WhatsApp modifié : ' . $wa);
        }
    }
    if (isset($body['announcement'])) {
        setSetting($db, 'announcement_text', mb_substr((string)$body['announcement'], 0, 500));
        logActivity($db, 'admin', '', 'settings', 'Bandeau annonce modifié');
    }
    if (isset($body['promoCodes']) && is_array($body['promoCodes'])) {
        $clean = [];
        foreach ($body['promoCodes'] as $code => $percent) {
            if (!is_string($code) || !is_numeric($percent)) continue;
            $code = strtoupper(trim($code));
            $pct = min(100, max(0, (float)$percent));
            if ($code !== '' && $pct > 0) $clean[$code] = $pct;
        }
        setSetting($db, 'promo_codes', json_encode($clean, JSON_UNESCAPED_UNICODE));
        logActivity($db, 'admin', '', 'settings', 'Codes promo mis à jour (' . count($clean) . ')', $clean);
    }
    if (isset($body['vip']) && is_array($body['vip'])) {
        $v = $body['vip'];
        if (isset($v['welcomePoints']) && is_numeric($v['welcomePoints'])) {
            setSetting($db, 'vip_welcome_points', (string)max(0, (int)$v['welcomePoints']));
        }
        if (isset($v['pointsPerDh']) && is_numeric($v['pointsPerDh']) && (float)$v['pointsPerDh'] > 0) {
            setSetting($db, 'vip_points_per_dh', (string)(float)$v['pointsPerDh']);
        }
        if (isset($v['rewardThreshold']) && is_numeric($v['rewardThreshold'])) {
            setSetting($db, 'vip_reward_threshold', (string)max(1, (int)$v['rewardThreshold']));
        }
        logActivity($db, 'admin', '', 'settings', 'Paramètres fidélité VIP mis à jour');
    }
    ok(['ok' => true, 'settings' => getSettingsPublic($db)]);
}

case 'admin_menu_update': {
    requireAdmin();
    $body = bodyJson();
    $id = (int)numField($body, 'id', 0);
    $patch = (isset($body['patch']) && is_array($body['patch'])) ? $body['patch'] : [];
    if ($id <= 0) fail(400, 'Identifiant manquant.');
    if (empty($patch)) ok(['ok' => true]);

    $sets = [];
    $params = [];
    $intCols = ['isPromo' => 'is_promo', 'isPopular' => 'is_popular', 'soldOut' => 'sold_out'];
    $floatCols = ['price' => 'price', 'promoPrice' => 'promo_price'];
    $textCols = ['name' => 'name', 'img' => 'img', 'desc' => 'descr'];

    foreach ($patch as $key => $val) {
        if (array_key_exists($key, $intCols)) {
            $sets[] = $intCols[$key] . ' = ?';
            $params[] = $val ? 1 : 0;
        } elseif (array_key_exists($key, $floatCols)) {
            if (!is_numeric($val)) fail(400, 'Valeur numérique invalide.');
            $numVal = (float)$val;
            if ($key === 'price' && $numVal <= 0) fail(400, 'Prix invalide.');
            $sets[] = $floatCols[$key] . ' = ?';
            $params[] = $numVal;
        } elseif (array_key_exists($key, $textCols)) {
            $sets[] = $textCols[$key] . ' = ?';
            $params[] = (string)$val;
        }
    }
    if (empty($sets)) ok(['ok' => true]);

    $params[] = $id;
    $stmt = $db->prepare('UPDATE menu_items SET ' . implode(', ', $sets) . ' WHERE id = ?');
    $stmt->execute($params);

    $stmt2 = $db->prepare('SELECT name FROM menu_items WHERE id = ?');
    $stmt2->execute([$id]);
    $nm = $stmt2->fetchColumn();
    logActivity($db, 'admin', '', 'menu_update', 'Menu modifié : ' . ($nm !== false ? $nm : '#' . $id) . ' — ' . implode(', ', array_keys($patch)), ['id' => $id]);
    ok(['ok' => true]);
}

case 'admin_menu_add': {
    requireAdmin();
    $body = bodyJson();
    $item = (isset($body['item']) && is_array($body['item'])) ? $body['item'] : $body;
    $name = strField($item, 'name');
    $category = strField($item, 'category', 'indian');
    $price = (float)numField($item, 'price', 0);
    $img = strField($item, 'img');
    $desc = strField($item, 'desc');
    $isPromo = !empty($item['isPromo']);
    $promoPrice = ($isPromo && isset($item['promoPrice']) && is_numeric($item['promoPrice'])) ? (float)$item['promoPrice'] : null;

    if ($name === '') fail(400, 'Nom du plat manquant.');
    if ($price <= 0) fail(400, 'Prix invalide.');
    if ($img === '') fail(400, 'Photo du plat manquante.');
    if ($desc === '') fail(400, 'Description manquante.');

    $newId = ((int)$db->query('SELECT COALESCE(MAX(id), 1000) FROM menu_items')->fetchColumn()) + 1;
    $pos = ((int)$db->query('SELECT COALESCE(MIN(position), 1) FROM menu_items')->fetchColumn()) - 1;

    $ins = $db->prepare('INSERT INTO menu_items (id, name, category, price, is_promo, promo_price, is_popular, sold_out, img, descr, position) VALUES (?,?,?,?,?,?,?,?,?,?,?)');
    $ins->execute([$newId, $name, $category, $price, $isPromo ? 1 : 0, $promoPrice, !empty($item['isPopular']) ? 1 : 0, 0, $img, $desc, $pos]);
    logActivity($db, 'admin', '', 'menu_add', 'Nouveau plat ajouté : ' . $name . ' (' . $price . ' DH)', ['id' => $newId]);
    ok(['ok' => true, 'id' => $newId]);
}

case 'admin_menu_delete': {
    requireAdmin();
    $body = bodyJson();
    $id = (int)numField($body, 'id', 0);
    if ($id <= 0) fail(400, 'Identifiant manquant.');
    $stmt = $db->prepare('SELECT name FROM menu_items WHERE id = ?');
    $stmt->execute([$id]);
    $nm = $stmt->fetchColumn();
    $del = $db->prepare('DELETE FROM menu_items WHERE id = ?');
    $del->execute([$id]);
    logActivity($db, 'admin', '', 'menu_delete', 'Plat supprimé du menu : ' . ($nm !== false ? $nm : '#' . $id), ['id' => $id]);
    ok(['ok' => true]);
}

case 'admin_menu_reset': {
    requireAdmin();
    $seed = require API_ROOT . '/seed-data.php';
    $db->beginTransaction();
    try {
        $db->exec('DELETE FROM menu_items');
        $db->exec('DELETE FROM sides');
        seedDatabase($db, $seed);
        $db->commit();
    } catch (Exception $e) {
        $db->rollBack();
        fail(500, 'Réinitialisation impossible : ' . $e->getMessage());
    }
    logActivity($db, 'admin', '', 'menu_reset', 'Menu et accompagnements réinitialisés aux valeurs d origine');
    ok(['ok' => true]);
}

case 'admin_sides_update': {
    requireAdmin();
    $body = bodyJson();
    $pkey = strField($body, 'pkey');
    $sid = strField($body, 'sid');
    $patch = (isset($body['patch']) && is_array($body['patch'])) ? $body['patch'] : [];
    $validKeys = ['sauces' => 1, 'accompaniments' => 1, 'drinks' => 1];
    if (!isset($validKeys[$pkey]) || $sid === '') fail(400, 'Paramètres invalides.');

    $updated = [];
    if (array_key_exists('price', $patch)) {
        if (!is_numeric($patch['price'])) fail(400, 'Prix invalide.');
        $stmt = $db->prepare('UPDATE sides SET price = ? WHERE pkey = ? AND sid = ?');
        $stmt->execute([max(0, (float)$patch['price']), $pkey, $sid]);
        $updated[] = 'prix';
    }
    if (array_key_exists('soldOut', $patch)) {
        $stmt = $db->prepare('UPDATE sides SET sold_out = ? WHERE pkey = ? AND sid = ?');
        $stmt->execute([$patch['soldOut'] ? 1 : 0, $pkey, $sid]);
        $updated[] = 'stock';
    }
    if (empty($updated)) fail(400, 'Rien à mettre à jour.');

    $stmt = $db->prepare('SELECT name FROM sides WHERE pkey = ? AND sid = ?');
    $stmt->execute([$pkey, $sid]);
    $nm = $stmt->fetchColumn();
    logActivity($db, 'admin', '', 'sides_update', 'Accompagnement modifié : ' . ($nm !== false ? $nm : $sid) . ' — ' . implode(', ', $updated), ['pkey' => $pkey, 'sid' => $sid]);
    ok(['ok' => true]);
}

case 'admin_box_save': {
    requireAdmin();
    $body = bodyJson();
    $box = (isset($body['box']) && is_array($body['box'])) ? $body['box'] : $body;
    $id = (int)numField($box, 'id', 0);
    $name = strField($box, 'name');
    $oldPrice = (float)numField($box, 'oldPrice', 0);
    $promoPrice = null;
    if (isset($box['promoPrice']) && $box['promoPrice'] !== null && $box['promoPrice'] !== '' && is_numeric($box['promoPrice'])) {
        $promoPrice = (float)$box['promoPrice'];
    }
    $startDate = strField($box, 'startDate');
    $endDate = strField($box, 'endDate');

    if ($name === '') fail(400, 'Nom de la BOX manquant.');
    if ($oldPrice <= 0) fail(400, 'Prix normal invalide.');
    if ($promoPrice !== null && ($promoPrice <= 0 || $promoPrice >= $oldPrice)) fail(400, 'Le prix promotionnel doit être inférieur au prix normal.');
    if ($startDate !== '' && $endDate !== '' && $startDate > $endDate) fail(400, 'Dates incohérentes.');

    if ($id > 0 && $id <= 2147483647) {
        $chk = $db->prepare('SELECT COUNT(*) FROM boxes WHERE id = ?');
        $chk->execute([$id]);
        if ((int)$chk->fetchColumn() > 0) {
            $upd = $db->prepare('UPDATE boxes SET name=?, img=?, descr=?, old_price=?, promo_price=?, promo_text=?, start_date=?, end_date=?, position=?, enabled=? WHERE id=?');
            $upd->execute([
                $name, strField($box, 'img'), strField($box, 'desc'), $oldPrice, $promoPrice,
                strField($box, 'promoText'), $startDate, $endDate,
                (int)numField($box, 'position', 1), !empty($box['enabled']) ? 1 : 0, $id
            ]);
            logActivity($db, 'admin', '', 'box_update', 'BOX modifiée : ' . $name, ['id' => $id]);
            ok(['ok' => true, 'id' => $id]);
        }
    }
    $maxPos = (int)$db->query('SELECT COALESCE(MAX(position), 0) FROM boxes')->fetchColumn();
    $ins = $db->prepare('INSERT INTO boxes (name, img, descr, old_price, promo_price, promo_text, start_date, end_date, position, enabled) VALUES (?,?,?,?,?,?,?,?,?,?)');
    $ins->execute([
        $name, strField($box, 'img'), strField($box, 'desc'), $oldPrice, $promoPrice,
        strField($box, 'promoText'), $startDate, $endDate, $maxPos + 1,
        !empty($box['enabled']) ? 1 : 0
    ]);
    $newId = (int)$db->lastInsertId();
    logActivity($db, 'admin', '', 'box_add', 'Nouvelle BOX ajoutée : ' . $name, ['id' => $newId]);
    ok(['ok' => true, 'id' => $newId]);
}

case 'admin_box_toggle': {
    requireAdmin();
    $body = bodyJson();
    $id = (int)numField($body, 'id', 0);
    if ($id <= 0) fail(400, 'Identifiant manquant.');
    $upd = $db->prepare('UPDATE boxes SET enabled = 1 - enabled WHERE id = ?');
    $upd->execute([$id]);
    $stmt = $db->prepare('SELECT name, enabled FROM boxes WHERE id = ?');
    $stmt->execute([$id]);
    $r = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($r) {
        logActivity($db, 'admin', '', 'box_toggle', 'BOX ' . $r['name'] . ' ' . ($r['enabled'] ? 'publiée' : 'masquée'), ['id' => $id]);
    }
    ok(['ok' => true]);
}

case 'admin_box_delete': {
    requireAdmin();
    $body = bodyJson();
    $id = (int)numField($body, 'id', 0);
    if ($id <= 0) fail(400, 'Identifiant manquant.');
    $stmt = $db->prepare('SELECT name FROM boxes WHERE id = ?');
    $stmt->execute([$id]);
    $nm = $stmt->fetchColumn();
    $del = $db->prepare('DELETE FROM boxes WHERE id = ?');
    $del->execute([$id]);
    logActivity($db, 'admin', '', 'box_delete', 'BOX supprimée : ' . ($nm !== false ? $nm : '#' . $id), ['id' => $id]);
    ok(['ok' => true]);
}

case 'admin_vip_save': {
    requireAdmin();
    $body = bodyJson();
    if (isset($body['members']) && is_array($body['members'])) {
        $members = $body['members'];
    } elseif (isset($body['member']) && is_array($body['member'])) {
        $members = [$body['member']];
    } else {
        $members = [$body];
    }
    $count = 0;
    $now = date('Y-m-d H:i:s');
    foreach ($members as $m) {
        if (!is_array($m)) continue;
        $card = strtoupper(strField($m, 'cardNumber'));
        $name = strField($m, 'name');
        $phone = strField($m, 'phone');
        if ($card === '' || $name === '' || $phone === '') continue;
        $date = strField($m, 'dateInscription', date('Y-m-d'));
        $niveau = strField($m, 'niveau', 'Bronze');
        $statut = strField($m, 'statut', 'actif');
        $notes = strField($m, 'notes');
        $orders = (int)numField($m, 'orders', 0);
        $points = (int)numField($m, 'points', 0);
        $email = strField($m, 'email');

        $exists = $db->prepare('SELECT card_number FROM vip_members WHERE card_number = ?');
        $exists->execute([$card]);
        if ($exists->fetchColumn()) {
            $upd = $db->prepare('UPDATE vip_members SET name=?, phone=?, email=?, date_inscription=?, niveau=?, statut=?, notes=?, orders=?, points=?, updated_at=? WHERE card_number=?');
            $upd->execute([$name, $phone, $email, $date, $niveau, $statut, $notes, $orders, $points, $now, $card]);
        } else {
            $ins = $db->prepare('INSERT INTO vip_members (card_number, name, phone, email, date_inscription, niveau, statut, notes, orders, points, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)');
            $ins->execute([$card, $name, $phone, $email, $date, $niveau, $statut, $notes, $orders, $points, $now, $now]);
        }
        $count++;
    }
    logActivity($db, 'admin', '', 'vip_save', 'Membres VIP enregistrés (' . $count . ')');
    ok(['ok' => true, 'saved' => $count]);
}

case 'admin_vip_delete': {
    requireAdmin();
    $body = bodyJson();
    $card = strtoupper(strField($body, 'card'));
    if ($card === '') fail(400, 'Carte manquante.');
    $del = $db->prepare('DELETE FROM vip_members WHERE card_number = ?');
    $del->execute([$card]);
    logActivity($db, 'admin', '', 'vip_delete', 'Membre VIP supprimé : ' . $card);
    ok(['ok' => true]);
}

case 'admin_vip_points': {
    requireAdmin();
    $body = bodyJson();
    $card = strtoupper(strField($body, 'card'));
    $delta = (int)numField($body, 'delta', 0);
    if ($card === '' || $delta === 0) fail(400, 'Paramètres invalides.');
    $stmt = $db->prepare('SELECT points FROM vip_members WHERE card_number = ?');
    $stmt->execute([$card]);
    $cur = $stmt->fetchColumn();
    if ($cur === false) fail(404, 'Membre introuvable.');
    $newPoints = max(0, (int)$cur + $delta);
    $upd = $db->prepare('UPDATE vip_members SET points = ?, updated_at = ? WHERE card_number = ?');
    $upd->execute([$newPoints, date('Y-m-d H:i:s'), $card]);
    logActivity($db, 'admin', '', 'vip_points', (($delta > 0) ? 'Ajout de ' . $delta . ' points' : 'Retrait de ' . abs($delta) . ' points') . ' — carte ' . $card, ['card' => $card, 'delta' => $delta]);
    ok(['ok' => true, 'points' => $newPoints]);
}

case 'admin_request_update': {
    requireAdmin();
    $body = bodyJson();
    $ref = strField($body, 'ref');
    if ($ref === '') fail(400, 'Référence manquante.');

    $stmt = $db->prepare('SELECT ref FROM contact_requests WHERE ref = ?');
    $stmt->execute([$ref]);
    if (!$stmt->fetchColumn()) fail(404, 'Demande introuvable.');

    if (isset($body['statut'])) {
        $allowed = ['Nouvelle', 'En cours', 'Traitée', 'Refusée'];
        if (!in_array((string)$body['statut'], $allowed, true)) fail(400, 'Statut invalide.');
        $upd = $db->prepare('UPDATE contact_requests SET statut = ? WHERE ref = ?');
        $upd->execute([(string)$body['statut'], $ref]);
        logActivity($db, 'admin', '', 'request_update', 'Demande ' . $ref . ' : statut ' . $body['statut']);
    }
    if (array_key_exists('notes', $body)) {
        $notes = strField($body, 'notes');
        $upd = $db->prepare('UPDATE contact_requests SET notes = ? WHERE ref = ?');
        $upd->execute([$notes, $ref]);
        logActivity($db, 'admin', '', 'request_update', 'Notes internes modifiées pour ' . $ref);
    }
    ok(['ok' => true]);
}

case 'admin_request_delete': {
    requireAdmin();
    $body = bodyJson();
    $ref = strField($body, 'ref');
    if ($ref === '') fail(400, 'Référence manquante.');
    $del = $db->prepare('DELETE FROM contact_requests WHERE ref = ?');
    $del->execute([$ref]);
    logActivity($db, 'admin', '', 'request_delete', 'Demande supprimée : ' . $ref);
    ok(['ok' => true]);
}

case 'admin_order_update': {
    requireAdmin();
    $body = bodyJson();
    $id = (int)numField($body, 'id', 0);
    if ($id <= 0) fail(400, 'Commande introuvable.');
    $stmt = $db->prepare('SELECT ref FROM orders WHERE id = ?');
    $stmt->execute([$id]);
    $ref = $stmt->fetchColumn();
    if ($ref === false) fail(404, 'Commande introuvable.');

    if (isset($body['statut'])) {
        $allowed = ['Nouvelle', 'Confirmée', 'En préparation', 'Livrée', 'Annulée'];
        if (!in_array((string)$body['statut'], $allowed, true)) fail(400, 'Statut invalide.');
        $upd = $db->prepare('UPDATE orders SET statut = ? WHERE id = ?');
        $upd->execute([(string)$body['statut'], $id]);
        logActivity($db, 'admin', '', 'order_update', 'Commande ' . $ref . ' : statut ' . $body['statut']);
    }
    if (array_key_exists('notes', $body)) {
        $notes = strField($body, 'notes');
        $upd = $db->prepare('UPDATE orders SET notes = ? WHERE id = ?');
        $upd->execute([$notes, $id]);
        logActivity($db, 'admin', '', 'order_update', 'Notes modifiées pour la commande ' . $ref);
    }
    ok(['ok' => true]);
}

case 'admin_nl_delete': {
    requireAdmin();
    $body = bodyJson();
    $email = strtolower(strField($body, 'email'));
    if ($email === '') fail(400, 'E-mail manquant.');
    $del = $db->prepare('DELETE FROM newsletter_subscribers WHERE email = ?');
    $del->execute([$email]);
    logActivity($db, 'admin', '', 'newsletter_delete', 'Abonné supprimé : ' . $email);
    ok(['ok' => true]);
}

case 'admin_campaign_add': {
    requireAdmin();
    $body = bodyJson();
    $titre = strField($body, 'titre');
    if ($titre === '') fail(400, 'Titre de campagne manquant.');
    $ins = $db->prepare('INSERT INTO newsletter_campaigns (created_at, titre, img, link, text, envois) VALUES (?,?,?,?,?,?)');
    $ins->execute([date('Y-m-d'), $titre, strField($body, 'img'), strField($body, 'link'), strField($body, 'text'), (int)numField($body, 'envois', 0)]);
    logActivity($db, 'admin', '', 'campaign', 'Campagne newsletter enregistrée : ' . $titre);
    ok(['ok' => true, 'id' => (int)$db->lastInsertId()]);
}

case 'admin_partner_save': {
    requireAdmin();
    $body = bodyJson();
    $partner = (isset($body['partner']) && is_array($body['partner'])) ? $body['partner'] : $body;
    $id = (int)numField($partner, 'id', 0);
    $name = strField($partner, 'name');
    if ($name === '') fail(400, 'Nom du partenaire manquant.');
    $website = strField($partner, 'website');
    if ($website !== '' && !preg_match('#^https?://\S+\.\S+#', $website)) fail(400, 'Site web invalide.');
    $email = strField($partner, 'email');
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) fail(400, 'E-mail de contact invalide.');

    if ($id > 0 && $id <= 2147483647) {
        $chk = $db->prepare('SELECT COUNT(*) FROM partners WHERE id = ?');
        $chk->execute([$id]);
        if ((int)$chk->fetchColumn() > 0) {
            if (isset($partner['logo']) && $partner['logo'] !== '') {
                $upd = $db->prepare('UPDATE partners SET name=?, logo=?, website=?, email=?, category=?, sort=?, published=?, descr=? WHERE id=?');
                $upd->execute([$name, $partner['logo'], $website, $email, strField($partner, 'category', 'Fournisseur'), (int)numField($partner, 'order', 1), !empty($partner['published']) ? 1 : 0, strField($partner, 'desc'), $id]);
            } else {
                $upd = $db->prepare('UPDATE partners SET name=?, website=?, email=?, category=?, sort=?, published=?, descr=? WHERE id=?');
                $upd->execute([$name, $website, $email, strField($partner, 'category', 'Fournisseur'), (int)numField($partner, 'order', 1), !empty($partner['published']) ? 1 : 0, strField($partner, 'desc'), $id]);
            }
            logActivity($db, 'admin', '', 'partner_update', 'Partenaire modifié : ' . $name, ['id' => $id]);
            ok(['ok' => true, 'id' => $id]);
        }
    }
    if (!isset($partner['logo']) || $partner['logo'] === '') fail(400, 'Logo du partenaire obligatoire.');
    $ins = $db->prepare('INSERT INTO partners (name, logo, website, email, category, sort, published, descr) VALUES (?,?,?,?,?,?,?,?)');
    $ins->execute([$name, $partner['logo'], $website, $email, strField($partner, 'category', 'Fournisseur'), (int)numField($partner, 'order', 1), !empty($partner['published']) ? 1 : 0, strField($partner, 'desc')]);
    $newId = (int)$db->lastInsertId();
    logActivity($db, 'admin', '', 'partner_add', 'Nouveau partenaire : ' . $name, ['id' => $newId]);
    ok(['ok' => true, 'id' => $newId]);
}

case 'admin_partner_toggle': {
    requireAdmin();
    $body = bodyJson();
    $id = (int)numField($body, 'id', 0);
    if ($id <= 0) fail(400, 'Identifiant manquant.');
    $upd = $db->prepare('UPDATE partners SET published = 1 - published WHERE id = ?');
    $upd->execute([$id]);
    $stmt = $db->prepare('SELECT name, published FROM partners WHERE id = ?');
    $stmt->execute([$id]);
    $r = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($r) {
        logActivity($db, 'admin', '', 'partner_toggle', 'Partenaire ' . $r['name'] . ' ' . ($r['published'] ? 'publié' : 'masqué'), ['id' => $id]);
    }
    ok(['ok' => true]);
}

case 'admin_partner_delete': {
    requireAdmin();
    $body = bodyJson();
    $id = (int)numField($body, 'id', 0);
    if ($id <= 0) fail(400, 'Identifiant manquant.');
    $stmt = $db->prepare('DELETE FROM partners WHERE id = ?');
    $stmt->execute([$id]);
    logActivity($db, 'admin', '', 'partner_delete', 'Partenaire supprimé #' . $id, ['id' => $id]);
    ok(['ok' => true]);
}

case 'admin_partner_move': {
    requireAdmin();
    $body = bodyJson();
    $id = (int)numField($body, 'id', 0);
    $dir = (int)numField($body, 'dir', 0);
    if ($id <= 0 || abs($dir) !== 1) fail(400, 'Paramètres invalides.');

    $list = $db->query('SELECT id, sort FROM partners ORDER BY sort, id')->fetchAll(PDO::FETCH_ASSOC);
    $idx = -1;
    foreach ($list as $k => $p) {
        if ((int)$p['id'] === $id) { $idx = $k; break; }
    }
    $swapIdx = $idx + $dir;
    if ($idx < 0 || $swapIdx < 0 || $swapIdx >= count($list)) ok(['ok' => true]);

    $a = $list[$idx];
    $b = $list[$swapIdx];
    $sortA = (int)$a['sort'];
    $sortB = (int)$b['sort'];
    $u1 = $db->prepare('UPDATE partners SET sort=? WHERE id=?');
    $u2 = $db->prepare('UPDATE partners SET sort=? WHERE id=?');
    $u1->execute([$sortB, $a['id']]);
    $u2->execute([$sortA === $sortB ? $sortB + $dir : $sortA, $b['id']]);
    logActivity($db, 'admin', '', 'partner_move', 'Réordonnancement des partenaires', ['id' => $id, 'dir' => $dir]);
    ok(['ok' => true]);
}

case 'admin_activity': {
    requireAdmin();
    $limit = isset($_GET['limit']) ? max(1, min(2000, (int)$_GET['limit'])) : 400;
    ok(['activity' => getActivity($db, $limit)]);
}

case 'admin_export_all': {
    requireAdmin();
    $data = [
        'exported_at' => date('c'),
        'menu' => getMenu($db),
        'sides' => getSides($db),
        'boxes' => getBoxes($db),
        'partners' => getPartners($db),
        'settings' => getSettingsPublic($db),
        'vip' => getVipList($db),
        'orders' => getOrders($db),
        'requests' => getRequests($db),
        'subscribers' => getSubscribers($db),
        'campaigns' => getCampaigns($db),
        'activity' => getActivity($db, 2000)
    ];
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="sauvegarde-site-' . date('Y-m-d') . '.json"');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

default:
    fail(400, 'Action inconnue.');
}
