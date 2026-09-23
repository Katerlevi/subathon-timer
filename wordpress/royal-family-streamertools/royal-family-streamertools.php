<?php
/**
 * Plugin Name: Royal Family Streamertools — Donations & Media
 * Description: Multi-channel extensions for Levi's Royal Family Subathon Timer. Disabled until installation checks pass.
 * Version: 0.2.1-personal2
 * Requires PHP: 8.1
 * Author: Royal Family / Levi + Corvus
 */
if (!defined('ABSPATH')) exit;
foreach (['presentation','donation-rule','domain','signature','store','personal','oauth','rest'] as $name) {
    require_once __DIR__.'/includes/class-'.($name==='donation-rule'?'rfs-':'rfst-').$name.'.php';
}
register_activation_hook(__FILE__, ['RFST_Store','activate']);
add_action('plugins_loaded', static function() {
    if (class_exists('RFS_REST')&&class_exists('RFS_Crypto')&&class_exists('RFS_DB')) RFST_REST::init();
});
