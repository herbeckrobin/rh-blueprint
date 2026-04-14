<?php
/**
 * Plugin Name:       RH Blueprint
 * Plugin URI:        https://github.com/robinherbeck/rh-blueprint
 * Description:       Wiederverwendbares Blueprint-Plugin von Robin Herbeck. Admin-Features, Peer-to-Peer Sync Network, Blueprint-Theme.
 * Version:           0.0.1
 * Requires at least: 6.5
 * Requires PHP:      8.1
 * Author:            Robin Herbeck
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       rh-blueprint
 */

if (!defined('ABSPATH')) {
    exit;
}

define('RHBP_VERSION', '0.0.1');
define('RHBP_PLUGIN_FILE', __FILE__);
define('RHBP_PLUGIN_DIR', plugin_dir_path(__FILE__));
