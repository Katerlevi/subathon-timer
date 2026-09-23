<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class RFS_DB {
	private const VERSION = '1.3.1-rf-planned-sleep';

	public static function table( string $name ): string {
		global $wpdb;
		$allowed = array( 'streamers', 'oauth_states', 'sessions', 'timer_configs', 'timer_states', 'event_dedupe', 'alerts' );
		if ( ! in_array( $name, $allowed, true ) ) {
			throw new InvalidArgumentException( 'Unbekannte Tabelle.' );
		}
		return $wpdb->prefix . 'rfs_' . $name;
	}

	public static function maybe_upgrade(): void {
		if ( get_option( 'rfs_db_version' ) !== self::VERSION ) {
			self::install();
		}
	}

	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		$queries = array();

		$queries[] = 'CREATE TABLE ' . self::table( 'streamers' ) . " (
			id char(36) NOT NULL,
			twitch_user_id varchar(32) NOT NULL,
			twitch_login varchar(64) NOT NULL,
			display_name varchar(128) NOT NULL,
			encrypted_access_token longtext NOT NULL,
			encrypted_refresh_token longtext NOT NULL,
			token_expires_at bigint(20) unsigned NOT NULL,
			scopes text NOT NULL,
			overlay_key_hash char(64) NOT NULL,
			encrypted_overlay_key text NOT NULL,
			setup_status varchar(32) NOT NULL DEFAULT 'pending',
			active tinyint(1) NOT NULL DEFAULT 1,
			created_at bigint(20) unsigned NOT NULL,
			updated_at bigint(20) unsigned NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY twitch_user_id (twitch_user_id),
			UNIQUE KEY twitch_login (twitch_login),
			UNIQUE KEY overlay_key_hash (overlay_key_hash),
			KEY active (active)
		) $charset;";

		$queries[] = 'CREATE TABLE ' . self::table( 'oauth_states' ) . " (
			state_hash char(64) NOT NULL,
			expected_login varchar(64) NOT NULL,
			expires_at bigint(20) unsigned NOT NULL,
			created_at bigint(20) unsigned NOT NULL,
			PRIMARY KEY  (state_hash),
			KEY expires_at (expires_at)
		) $charset;";

		$queries[] = 'CREATE TABLE ' . self::table( 'sessions' ) . " (
			token_hash char(64) NOT NULL,
			streamer_id char(36) NOT NULL,
			expires_at bigint(20) unsigned NOT NULL,
			created_at bigint(20) unsigned NOT NULL,
			PRIMARY KEY  (token_hash),
			KEY streamer_id (streamer_id),
			KEY expires_at (expires_at)
		) $charset;";

		$queries[] = 'CREATE TABLE ' . self::table( 'timer_configs' ) . " (
			streamer_id char(36) NOT NULL,
			start_seconds int(11) unsigned NOT NULL DEFAULT 14400,
			max_seconds int(11) unsigned NOT NULL DEFAULT 259200,
			max_mode varchar(16) NOT NULL DEFAULT 'limited',
			stream_start_at bigint(20) unsigned NOT NULL DEFAULT 0,
			end_mode varchar(16) NOT NULL DEFAULT 'open',
			stream_end_at bigint(20) unsigned NOT NULL DEFAULT 0,
			sleep_additions_enabled tinyint(1) NOT NULL DEFAULT 1,
			sleep_timer_continues tinyint(1) NOT NULL DEFAULT 1,
			tier1_seconds int(11) unsigned NOT NULL DEFAULT 240,
			tier1_enabled tinyint(1) NOT NULL DEFAULT 1,
			tier2_seconds int(11) unsigned NOT NULL DEFAULT 480,
			tier2_enabled tinyint(1) NOT NULL DEFAULT 1,
			tier3_seconds int(11) unsigned NOT NULL DEFAULT 900,
			tier3_enabled tinyint(1) NOT NULL DEFAULT 1,
			gift_seconds int(11) unsigned NOT NULL DEFAULT 240,
			gift_enabled tinyint(1) NOT NULL DEFAULT 1,
			bits_seconds int(11) unsigned NOT NULL DEFAULT 60,
			bits_enabled tinyint(1) NOT NULL DEFAULT 1,
			follow_seconds int(11) unsigned NOT NULL DEFAULT 0,
			follow_enabled tinyint(1) NOT NULL DEFAULT 0,
			raid_seconds int(11) unsigned NOT NULL DEFAULT 6,
			raid_enabled tinyint(1) NOT NULL DEFAULT 1,
			reward_seconds int(11) unsigned NOT NULL DEFAULT 120,
			reward_enabled tinyint(1) NOT NULL DEFAULT 1,
			updated_at bigint(20) unsigned NOT NULL,
			PRIMARY KEY  (streamer_id)
		) $charset;";

		$queries[] = 'CREATE TABLE ' . self::table( 'timer_states' ) . " (
			streamer_id char(36) NOT NULL,
			running tinyint(1) NOT NULL DEFAULT 0,
			remaining_seconds int(11) unsigned NOT NULL DEFAULT 14400,
			ends_at bigint(20) unsigned NOT NULL DEFAULT 0,
			sleeping tinyint(1) NOT NULL DEFAULT 0,
			sleep_started_at bigint(20) unsigned NOT NULL DEFAULT 0,
			sleep_duration_seconds int(11) unsigned NOT NULL DEFAULT 0,
			sleep_resume_timer tinyint(1) NOT NULL DEFAULT 0,
			last_event varchar(255) DEFAULT NULL,
			alert_id bigint(20) unsigned NOT NULL DEFAULT 0,
			alert_label varchar(255) DEFAULT NULL,
			alert_seconds int(11) NOT NULL DEFAULT 0,
			alert_created_at bigint(20) unsigned NOT NULL DEFAULT 0,
			updated_at bigint(20) unsigned NOT NULL,
			PRIMARY KEY  (streamer_id)
		) $charset;";

		$queries[] = 'CREATE TABLE ' . self::table( 'event_dedupe' ) . " (
			message_id varchar(128) NOT NULL,
			received_at bigint(20) unsigned NOT NULL,
			PRIMARY KEY  (message_id),
			KEY received_at (received_at)
		) $charset;";

		$queries[] = 'CREATE TABLE ' . self::table( 'alerts' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			streamer_id char(36) NOT NULL,
			label varchar(255) NOT NULL,
			seconds int(11) NOT NULL DEFAULT 0,
			created_at bigint(20) unsigned NOT NULL,
			PRIMARY KEY  (id),
			KEY streamer_created (streamer_id,created_at),
			KEY created_at (created_at)
		) $charset;";

		foreach ( $queries as $query ) {
			dbDelta( $query );
		}

		if ( ! get_option( 'rfs_eventsub_secret' ) ) {
			update_option( 'rfs_eventsub_secret', RFS_Crypto::encrypt( bin2hex( random_bytes( 32 ) ) ), false );
		}
		update_option( 'rfs_db_version', self::VERSION, false );
	}
}
