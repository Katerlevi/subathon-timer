<?php
/**
 * Plugin Name: Royal Family Subathon Timer
 * Description: Sicherer Twitch-Subathon-Timer mit persönlichen Dashboard- und OBS-Links.
 * Version: 1.1.0
 * Author: Royal Family
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Text Domain: royal-family-subathon
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'RFS_VERSION', '1.1.0' );
define( 'RFS_PLUGIN_FILE', __FILE__ );
define( 'RFS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

require_once RFS_PLUGIN_DIR . 'includes/class-rfs-crypto.php';
require_once RFS_PLUGIN_DIR . 'includes/class-rfs-db.php';
require_once RFS_PLUGIN_DIR . 'includes/class-rfs-core.php';
require_once RFS_PLUGIN_DIR . 'includes/class-rfs-twitch.php';
require_once RFS_PLUGIN_DIR . 'includes/class-rfs-rest.php';
require_once RFS_PLUGIN_DIR . 'includes/class-rfs-admin.php';

register_activation_hook( RFS_PLUGIN_FILE, array( 'RFS_DB', 'install' ) );

add_action(
	'plugins_loaded',
	static function () {
		RFS_DB::maybe_upgrade();
		RFS_REST::init();
		RFS_Admin::init();
	}
);
