<?php
/**
 * Plugin Name:       RH Blueprint
 * Plugin URI:        https://github.com/herbeckrobin/rh-blueprint
 * Update URI:        https://github.com/herbeckrobin/rh-blueprint
 * Description:       Wiederverwendbares Blueprint-Plugin von Robin Herbeck. Admin-Features, Peer-to-Peer Sync Network, Blueprint-Theme.
 * Version:           0.2.1
 * Requires at least: 6.5
 * Requires PHP:      8.1
 * Requires Plugins:  wps-hide-login, limit-login-attempts-reloaded, wp-mail-smtp
 * Author:            Robin Herbeck
 * Author URI:        https://robinherbeck.de
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       rh-blueprint
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

define('RHBP_VERSION', '0.2.1');
define('RHBP_PLUGIN_FILE', __FILE__);
define('RHBP_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('RHBP_PLUGIN_URL', plugin_dir_url(__FILE__));

$rhbp_autoload = RHBP_PLUGIN_DIR . 'vendor/autoload.php';

if (!is_readable($rhbp_autoload)) {
    add_action('admin_notices', static function (): void {
        echo '<div class="notice notice-error"><p><strong>RH Blueprint:</strong> Composer-Dependencies fehlen. Bitte <code>composer install</code> im Plugin-Verzeichnis ausfuehren.</p></div>';
    });
    return;
}

require_once $rhbp_autoload;
require_once RHBP_PLUGIN_DIR . 'inc/helpers.php';

RhBlueprint\Plugin::boot();
