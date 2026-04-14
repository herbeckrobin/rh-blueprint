# RH Blueprint

Wiederverwendbares WordPress-Plugin von Robin Herbeck. Grundgeruest das in jedes neue Kundenprojekt als Basis eingebaut wird und sich automatisch via GitHub updatet.

## Features

- **Settings-Page** mit Tab-Kategorien, Live-Suche und pro-Tab-isolierten Option-Groups
- **Dashboard-Cleanup** entfernt WordPress-Default-Widgets und ersetzt sie durch ein eigenes Widget mit Support-Box, Quick-Links und erweiterbaren Sections
- **DB-Tools** — Export/Import/Restore mit Shared-Hosting-kompatiblem Backup-System (kein `mysqldump`, pure PHP, chunked SELECT)
- **Sync Network** — Peer-to-Peer Sync zwischen WordPress-Instanzen ueber REST-API + HMAC-SHA256 Signaturen. Schuetzt site-spezifische Options (`rhbp_*`, `siteurl`, `active_plugins`, `cron`, `whl_page` u.a.) vor Ueberschreiben beim Import, exkludiert `wp_actionscheduler_*` und `wp_woocommerce_sessions` beim Export.
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

## Sync-Network: Was wird (nicht) gesynct?

Beim Pull/Push dumpt der Exporter die gesamte Datenbank und importiert sie auf der Ziel-Seite. Der `LocalOptionGuard` und `SyncDefaults::excludedTables()` schuetzen site-spezifische Dinge.

**Geschuetzte Options (bleiben auf dem Ziel unveraendert):**

| Bereich | Optionen |
|---|---|
| Plugin-eigene | `rhbp_*`, `_transient_rhbp_*` etc. |
| Site-Identitaet | `siteurl`, `home`, `admin_email`, `new_admin_email` |
| Plugin-Aktivierung | `active_plugins`, `active_sitewide_plugins` |
| Cron + Rewrite | `cron`, `rewrite_rules` |
| Hosting-Pfade | `upload_path`, `upload_url_path` |
| WP-Core SMTP | `mailserver_url/login/pass/port` |
| DB-Schema | `db_version`, `db_upgraded`, `fresh_site` |
| WP-Core Updates | `_site_transient_update_*` |
| Plugin-Options | `limit_login_*`, `wp_mail_smtp*`, `whl_page` |

**Ausgeschlossene Tabellen (landen nicht im Export):**

- `wp_actionscheduler_actions`, `_claims`, `_groups`, `_logs`
- `wp_woocommerce_sessions`

**Erweiterbar via Filter:**

```php
// In mu-plugin oder theme functions.php
add_filter('rh-blueprint/sync/preserved_option_names', function (array $names): array {
    $names[] = 'custom_api_key';
    return $names;
});

add_filter('rh-blueprint/sync/preserved_option_patterns', function (array $patterns): array {
    $patterns[] = 'my\\_plugin\\_%';
    return $patterns;
});

add_filter('rh-blueprint/sync/excluded_tables', function (array $tables): array {
    global $wpdb;
    $tables[] = $wpdb->prefix . 'visitor_tracking';
    return $tables;
});
```

## ⚠️ Bekannte Grenzen des Sync-Netzwerks

- **`wp_users` + `wp_usermeta` werden komplett gesynct.** Admin-Passwoerter wandern mit der Datenbank — der User-Satz auf dem Ziel wird durch den Source-Satz ersetzt. Fuer echte Produktionssyncs (zu Kunden-Sites) ist das ein Problem. Fuer lokal → stage im gleichen Projektkontext in Ordnung. Eine `UserGuard`-Implementierung analog zu `LocalOptionGuard` ist geplant.
- **Kein Background-Job-Modus.** Export und Import laufen synchron im REST-Request. Bei DBs > ~50 MB kann das ins HTTP-Timeout laufen. Action-Scheduler-Integration ist geplant.
- **Kein Rate-Limiting.** Ein Peer mit gueltigem Token kann beliebig viele Syncs triggern. Fuer Production sollte das absichtlich ergaenzt werden.
- **Upload-Files werden optional mitgesynct.** Beim Push/Pull werden nur die Datenbank-Tabellen transferiert — Medien-Files sind nicht dabei. Wenn Media auf beiden Seiten synchron sein muss, braucht es zusaetzlich `rsync` o.ae.

## Lizenz

GPL-2.0-or-later
