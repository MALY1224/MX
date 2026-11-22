# MAXIN CHICKEN x INDIAN CHICKEN HOUSE — Marrakech
# MX

Site vitrine + commande en ligne du restaurant **MAXIN CHICKEN x INDIAN CHICKEN HOUSE** (Marrakech), avec administration centralisée.

## Contenu

| Fichier / dossier | Rôle |
|---|---|
| `index.html` | Site complet — design d'origine, toutes les données servies par `api/` |
| `api/` | Backend PHP sans dépendances (36 actions API : commandes, fidélité VIP, demandes contact, réservations, newsletter, partenaires, promotion, journal d'activité, export) |
| `api/seed-data.php` | Menu initial (plats, sides, BOX, images) — injecté automatiquement au premier appel API |
| `api/config.sample.php` | Modèle de configuration — **à renommer en `api/config.php`** chez l'hébergeur |
| `DEPLOIEMENT.md` | **Guide de mise en ligne pas à pas (en français)** |
| `schema.sql` | DDL MySQL de référence (facultatif — l'installation est automatique) |

## Mise en ligne (résumé)

1. Créer une base MySQL chez l'hébergeur (ou choisir le driver `sqlite`, aucune base à créer).
2. Envoyer ces fichiers vers `public_html/`.
3. Renommer `api/config.sample.php` en `api/config.php` et y saisir les identifiants de la base.
4. Ouvrir le site : les 12 tables et le menu s'installent automatiquement au premier appel API.
5. Se connecter à l'admin (PIN par défaut `1234`) et **le changer immédiatement**.

Détails complets, sécurité, sauvegardes et dépannage : voir **[DEPLOIEMENT.md](DEPLOIEMENT.md)**.

## Sécurité

- `.gitignore` exclut `api/config.php` (identifiants) et `api/data/*` (base SQLite) : ils ne seront jamais commités par accident.
- `.htaccess` bloque l'accès web direct à ces fichiers.
