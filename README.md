# RH Blueprint

Wiederverwendbares WordPress-Plugin von Robin Herbeck. Grundgeruest das in jedes neue Kundenprojekt als Basis eingebaut wird und sich automatisch via GitHub updatet.

## Features

- **Settings-Page** mit Tab-Kategorien, Live-Suche und pro-Tab-isolierten Option-Groups
- **Dashboard-Cleanup** entfernt WordPress-Default-Widgets und ersetzt sie durch ein eigenes Widget mit Support-Box, Quick-Links und erweiterbaren Sections
- **DB-Tools** — Export/Import/Restore mit Shared-Hosting-kompatiblem Backup-System (kein `mysqldump`, pure PHP, chunked SELECT)
- **Sync Network** — Peer-to-Peer Sync zwischen WordPress-Instanzen ueber REST-API + HMAC-SHA256 Signaturen (in Arbeit)
- **Smooth-Scroll-Toggle** — laedt ein Lenis-Init-Script wenn das Theme die Library bereitstellt
- **Login-Bridge** fuer WPS Hide Login via `RHBP_LOGIN_SLUG` Konstante in `wp-config.php`
- **Auto-Update** ueber GitHub Releases — kein eigenes Update-UI, Updates erscheinen wie wp.org Plugins

## Voraussetzungen

- WordPress 6.5+
- PHP 8.1+
- Empfohlene Plugin-Dependencies (ueber `Requires Plugins:` Header deklariert):
  - [WPS Hide Login](https://wordpress.org/plugins/wps-hide-login/)
  - [Limit Login Attempts Reloaded](https://wordpress.org/plugins/limit-login-attempts-reloaded/)
  - [WP Mail SMTP](https://wordpress.org/plugins/wp-mail-smtp/)

## Installation

### Auf einer neuen Site

1. Neueste ZIP aus [Releases](https://github.com/herbeckrobin/rh-blueprint/releases) laden
2. In WordPress Admin unter **Plugins → Installieren → Plugin hochladen**
3. Aktivieren — WordPress prompted zur Installation der fehlenden Plugin-Dependencies (wenn noch nicht vorhanden)
4. Unter **Einstellungen → RH Blueprint** konfigurieren

### Login-URL setzen (optional)

In `wp-config.php` eintragen:

```php
define( 'RHBP_LOGIN_SLUG', 'backdoor' );
```

Das Plugin synced den Slug automatisch in die WPS Hide Login Option.

### Smooth Scroll aktivieren (optional)

Das Plugin liefert nur die Init-Logik. Das aktive Theme muss die Lenis-Library registrieren:

```php
add_action( 'wp_enqueue_scripts', function () {
    wp_register_script(
        'rh-blueprint-lenis',
        'https://unpkg.com/lenis@1/dist/lenis.min.js',
        [],
        '1.0.0',
        true
    );
} );
```

Danach in **Einstellungen → RH Blueprint → Tools → Smooth Scroll** aktivieren.

## Updates

Das Plugin prueft regelmaessig GitHub auf neue Releases (via [plugin-update-checker](https://github.com/YahnisElsts/plugin-update-checker)) und haengt sich in die native WP-Update-Mechanik ein. Updates erscheinen unter **Dashboard → Aktualisierungen** wie normale Plugin-Updates.

### Release-Workflow fuer Robin

Kleine Checkliste fuer ein neues Release:

1. Version bumpen in `rh-blueprint.php`:
   ```
    * Version: 0.2.0
   ```
2. Commit + Push zu `main`
3. Tag erstellen und pushen:
   ```bash
   git tag v0.2.0
   git push origin v0.2.0
   ```
4. GitHub Action `release.yml` startet automatisch:
   - Installiert Production-Dependencies (`composer install --no-dev`)
   - Prueft dass Tag-Version mit Plugin-Header matcht
   - Baut ZIP ohne Dev-Files (`.git/`, `.github/`, `phpstan.*`, `tests/`, etc.)
   - Erstellt GitHub Release mit auto-generierten Notes + ZIP als Asset
5. Alle Sites mit aktivem Plugin sehen das Update innerhalb von 12h (WP-Cron-Default)

**Wichtig:** Tag muss exakt mit `Version:` im Plugin-Header uebereinstimmen — die Action bricht sonst ab.

## Entwicklung

```bash
# Dependencies installieren
composer install

# Statische Analyse (Level 5)
vendor/bin/phpstan analyse

# Lokales Test-WordPress
cd ../../  # zurueck ins Projekt-Root
ddev start
ddev launch
```

Plugin ist per Symlink in `Code/wp/wp-content/plugins/rh-blueprint` eingebunden.

## Architektur

```
rh-blueprint/
├── rh-blueprint.php           # Plugin-Header, Autoloader, Bootstrap
├── composer.json              # PSR-4 RhBlueprint\\ -> inc/
├── vendor/                    # Committed (Shared-Hosting-Kompatibilitaet)
├── inc/
│   ├── Plugin.php             # Singleton-Bootstrap
│   ├── helpers.php            # Globale Helper (rhbp_support_info, rhbp_setting)
│   ├── UpdateChecker.php      # GitHub Auto-Update
│   ├── Settings/              # Tab-basierte Settings-Page
│   ├── Admin/                 # Dashboard-Cleanup, Widget, DB-Tools
│   ├── Db/                    # BackupStorage, Exporter, Importer, SearchReplace
│   ├── Frontend/              # Smooth-Scroll-Enqueue
│   ├── Integrations/          # WPS-Hide-Login-Bridge
│   └── Sync/                  # Peer-Registry, HMAC-Auth, Sync-Network (WIP)
├── assets/admin/              # Admin-CSS + JS (Settings, Dashboard-Widget)
├── assets/public/             # Frontend-Scripts (Smooth-Scroll-Init)
└── .github/workflows/         # Release-Automation
```

## Lizenz

GPL-2.0-or-later
