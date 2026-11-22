-- Structure de référence de la base (créée automatiquement — ce fichier est facultatif)
CREATE TABLE IF NOT EXISTS settings (
            skey VARCHAR(64) NOT NULL PRIMARY KEY,
            svalue TEXT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS menu_items (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS sides (
            idx INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            pkey VARCHAR(32) NOT NULL,
            sid VARCHAR(32) NOT NULL,
            name VARCHAR(255) NOT NULL,
            price DECIMAL(10,2) NOT NULL DEFAULT 0,
            sold_out TINYINT(1) NOT NULL DEFAULT 0,
            position INT NOT NULL DEFAULT 0,
            UNIQUE KEY uq_sides (pkey, sid)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS boxes (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS vip_members (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS orders (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS contact_requests (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS newsletter_subscribers (
            email VARCHAR(255) NOT NULL PRIMARY KEY,
            prenom VARCHAR(255) NULL,
            created_at VARCHAR(10) NOT NULL,
            consent TINYINT(1) NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS newsletter_campaigns (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            created_at VARCHAR(10) NOT NULL,
            titre VARCHAR(255) NOT NULL,
            img MEDIUMTEXT NULL,
            link VARCHAR(500) NULL,
            text MEDIUMTEXT NULL,
            envois INT NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS partners (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(255) NOT NULL,
            logo MEDIUMTEXT NULL,
            website VARCHAR(500) NULL,
            email VARCHAR(255) NULL,
            category VARCHAR(64) NULL,
            sort INT NOT NULL DEFAULT 0,
            published TINYINT(1) NOT NULL DEFAULT 1,
            descr TEXT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS activity_log (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            created_at VARCHAR(19) NOT NULL,
            actor VARCHAR(16) NOT NULL,
            actor_detail VARCHAR(255) NULL,
            type VARCHAR(48) NOT NULL,
            description TEXT NULL,
            data MEDIUMTEXT NULL,
            ip VARCHAR(64) NULL,
            INDEX idx_activity_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS login_attempts (
            ip VARCHAR(64) NOT NULL,
            created_at VARCHAR(19) NOT NULL,
            ok TINYINT(1) NOT NULL DEFAULT 0,
            INDEX idx_login_attempts (ip, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;