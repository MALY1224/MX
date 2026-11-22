# 🐔 MAXIN CHICKEN x INDIAN CHICKEN HOUSE — Guide de mise en ligne

Ce site est désormais composé :

- **`index.html`** — la page du site : **design identique à l'original, rien n'a changé visuellement**.
- **`api/`** — le serveur (PHP + base de données) qui centralise **toutes les données** :
  menu, promotions, BOX, commandes, réservations, demandes de contact, membres VIP,
  newsletter, partenaires, journal d'activité, réglages.

➡️ **Aucune donnée n'est stockée sur l'ordinateur des visiteurs** : tout est enregistré
sur le serveur et synchronisé instantanément pour tous les appareils.

---

## 1. Ce qu'il vous faut

1. Un **hébergement mutualisé PHP** (ovh, o2switch, Hostinger, ionos, Planethoster,
   cPanel, LWS… — le « standard » chez la quasi-totalité des hébergeurs suffit) avec :
   - **PHP 7.4 ou plus** (PHP 8.x recommandé),
   - **une base de données MySQL/MariaDB** (inclus dans toutes les offres au-dessus du gratuit basique).
2. Votre **nom de domaine** pointé vers cet hébergement (votre hébergeur s'en occupe en 2 clics).

> 💡 Option gratuite possible : InfinityFree / 000webhost offrent PHP + MySQL.
> Une offre payante (2 à 6 €/mois) est toutefois recommandée pour un site professionnel
> (e-mail du domaine, HTTPS simple, sauvegardes automatiques).

---

## 2. Créer la base de données (5 minutes)

1. Connectez-vous à votre espace client / tableau de bord (cPanel, hPanel…).
2. Ouvrez la rubrique **« Bases de données MySQL »**.
3. Créez une base (ex. `maxin_site`), un utilisateur (ex. `maxin_user`) et son mot de passe.
   - Notez précieusement ces **3 informations** + le **serveur** (souvent `localhost`).
4. Accordez **tous les droits** de l'utilisateur sur la base.

> ❌ Vous n'avez **rien à importer** : aucune commande SQL, aucun fichier `.sql`.
> Les tables et le menu d'origine sont créés **automatiquement** à la première visite.

---

## 3. Envoyer les fichiers

1. Ouvrez le **Gestionnaire de fichiers** de votre hébergeur (ou FileZilla en FTP).
2. Téléversez **tous les fichiers du projet** dans le dossier racine du site
   (`public_html/`, `www/` ou `htdocs/` selon l'hébergeur) en conservant la structure :

```
public_html/
├── index.html
├── .htaccess
└── api/
    ├── .htaccess
    ├── bootstrap.php
    ├── config.sample.php
    ├── index.php
    └── seed-data.php
```

3. Dans le dossier `api/`, **copiez** `config.sample.php` et **renommez la copie** en `config.php`.
4. Ouvrez `config.php` (éditeur de l'hébergeur) et complétez vos accès MySQL :

```php
'driver' => 'mysql',
'mysql' => [
    'host'     => 'localhost',
    'port'     => 3306,
    'database' => 'maxin_site',      // votre base
    'user'     => 'maxin_user',      // votre utilisateur
    'password' => '••••••••',        // votre mot de passe
    'charset'  => 'utf8mb4'
],
```

C'est **la seule modification à faire**. Sauvegardez.

---

## 4. Vérifier que tout fonctionne

1. Ouvrez votre site : `https://votre-domaine.com`
   - Le menu complet s'affiche (16 plats, accompagnements, boissons, 1 BOX) —
     chargés depuis la base de données.
2. Passez une commande test : la référence `MC-xxxxxx` s'affiche.
3. Ouvrez l'administration :
   - Raccourci clavier **Ctrl + Maj + A** (ou 3 clics rapides sur le logo du site),
   - Code PIN par défaut : **`1234`**,
   - Onglets disponibles : **Plats, Accompagnements, VIP, Ajouter un plat, Réglages,
     BOX, Commandes, Demandes, Activité, Newsletter, Partenaires**.

### 🔐 À faire immédiatement après la mise en ligne

1. Onglet **Réglages** → **Changer le code PIN gérant** (4 à 6 chiffres, mémorisable,
   non devinable). Le PIN est vérifié et haché côté serveur.
2. Onglet **Réglages** → vérifiez le **numéro WhatsApp** et les **codes promo**
   (le code `OUVERTURE15` d'origine est actif, modifiable/supprimable).

---

## 5. HTTPS (certificat SSL)

Depuis l'hébergeur : rubrique **SSL / HTTPS** → activez le certificat gratuit
(Let's Encrypt) et « forcer HTTPS ». C'est automatique et gratuit.

---

## 6. Ce que l'administration vous permet (sans toucher au code)

| Fonctionnalité | Où |
|---|---|
| Modifier prix, photos, promotions, rupture de stock, plats vedettes | Onglet **Plats & Promotions** |
| Ajouter / supprimer des plats, réinitialiser le menu d'origine | Onglets **Ajouter un Plat** / **Plats** |
| Prix et disponibilité des sauces, accompagnements, boissons | Onglet **Accompagnements** |
| Créer / programmer / masquer des BOX promotionnelles | Onglet **BOX** |
| Frais de livraison, WhatsApp, bandeau d'annonce, codes promo, réglages fidélité, PIN | Onglet **Réglages** |
| Suivre chaque commande et son statut (Nouvelle → Livrée) | Onglet **Commandes** |
| Demandes de contact **et réservations de table** | Onglet **Demandes** |
| L'ensemble des activités (commandes, inscriptions, connexions…) | Onglet **Activité** |
| Base clients fidélité : cartes, points, commandes, notes, +50 pts, import Excel | Onglet **Membres VIP** |
| Abonnés newsletter, campagnes, export Excel | Onglet **Newsletter** |
| Logos de partenaires, ordre d'affichage, publication | Onglet **Partenaires** |
| Export Excel complet du menu, des commandes, VIP, abonnés | Boutons **Exporter** de chaque onglet |

La fidélité fonctionne automatiquement :
- carte créée à l'inscription ou à la 1ʳᵉ commande (`VIP-MC-` + 4 derniers chiffres du téléphone),
- points de bienvenue + points calculés par le serveur (1 pt / 10 DH par défaut),
- récompense « Menu / Naan offert » au 3ᵉ commande (personnalisable dans Réglages),
- tout est consultable dans l'onglet VIP (historique, totaux, notes).

---

## 7. Sécurité — ce qui est mis en place

- Code PIN haché avec `password_hash()` côté serveur (jamais en clair, jamais dans le navigateur).
- Sessions PHP sécurisées (cookie httponly, SameSite=Lax, régénération à la connexion).
- Blocage des tentatives de connexion : 6 échecs → 15 minutes d'attente.
- Fichiers sensibles (`config.php`, base, seed, bootstrap) **inaccessibles** via `.htaccess`.
- Requêtes SQL toutes **préparées** (injections SQL impossibles).
- Prix, totaux, codes promo et points **recalculés côté serveur** (impossible de falsifier une commande).
- Journal d'activité horodaté de toutes les actions (avec IP).

## 8. Sauvegardes

Les données vivent dans MySQL chez votre hébergeur. Recommandé :
- activer les **sauvegardes automatiques** de l'offre (souvent intégrées),
- ou exporter ponctuellement via **phpMyAdmin → Exporter**,
- ou utiliser les boutons **Export Excel** de l'administration selon vos besoins.

## 9. Cas particulier : hébergeur sans MySQL

Le site supporte aussi **SQLite** (base dans un simple fichier, zéro configuration) :
dans `api/config.php`, mettez `'driver' => 'sqlite'` — c'est tout. Pratique pour les
petites offres ou les tests, MySQL reste recommandé en production.

## 10. Dépannage rapide

| Problème | Solution |
|---|---|
| « Connexion au serveur impossible » (bandeau rouge) | Vérifiez `api/config.php` (identifiants MySQL) |
| « Connexion à la base de données impossible » | Base/utilisateur/mot de passe incorrects, ou utilisateur sans droits |
| Menu vide | Le fichier `api/seed-data.php` doit être présent (il est fourni) |
| PIN oublié | Supprimez la ligne `admin_pin_hash` dans la table `settings` via phpMyAdmin (retour au PIN 1234) |
| WhatsApp n'ouvre pas | Autorisez les pop-ups pour votre domaine, vérifiez le numéro dans Réglages |

---

Bon service ! 🍗
