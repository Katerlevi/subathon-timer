<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class RFS_REST {
	private const NAMESPACE       = 'royal-family-subathon/v1';
	private const SESSION_TTL     = 2592000;
	private const OAUTH_STATE_TTL = 600;
	private const MAX_JSON_BYTES  = 20480;
	private const MAX_HOOK_BYTES  = 102400;

	public static function init(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
		add_filter( 'rest_post_dispatch', array( __CLASS__, 'secure_headers' ), 10, 3 );
		add_filter( 'rest_pre_serve_request', array( __CLASS__, 'serve_challenge' ), 10, 4 );
	}

	public static function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/health',
			array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'health' ), 'permission_callback' => '__return_true' )
		);
		register_rest_route(
			self::NAMESPACE,
			'/auth/start',
			array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'auth_start' ), 'permission_callback' => '__return_true' )
		);
		register_rest_route(
			self::NAMESPACE,
			'/auth/callback',
			array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'auth_callback' ), 'permission_callback' => '__return_true' )
		);
		register_rest_route(
			self::NAMESPACE,
			'/eventsub',
			array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'eventsub' ), 'permission_callback' => '__return_true' )
		);
		register_rest_route(
			self::NAMESPACE,
			'/overlay/state',
			array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'overlay_state' ), 'permission_callback' => '__return_true' )
		);
		register_rest_route(
			self::NAMESPACE,
			'/me',
			array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'me' ), 'permission_callback' => array( __CLASS__, 'require_session' ) )
		);
		register_rest_route(
			self::NAMESPACE,
			'/config',
			array( 'methods' => 'PUT', 'callback' => array( __CLASS__, 'update_config' ), 'permission_callback' => array( __CLASS__, 'require_session' ) )
		);
		register_rest_route(
			self::NAMESPACE,
			'/timer/(?P<action>start|pause|reset)',
			array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'timer_action' ), 'permission_callback' => array( __CLASS__, 'require_session' ) )
		);
		register_rest_route(
			self::NAMESPACE,
			'/timer/adjust',
			array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'timer_adjust' ), 'permission_callback' => array( __CLASS__, 'require_session' ) )
		);
		register_rest_route(
			self::NAMESPACE,
			'/timer/state',
			array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'timer_state' ), 'permission_callback' => array( __CLASS__, 'require_session' ) )
		);
		register_rest_route(
			self::NAMESPACE,
			'/test-event',
			array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'test_event' ), 'permission_callback' => array( __CLASS__, 'require_session' ) )
		);
		register_rest_route(
			self::NAMESPACE,
			'/sleep/(?P<action>start|end)',
			array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'sleep_action' ), 'permission_callback' => array( __CLASS__, 'require_session' ) )
		);
		register_rest_route(
			self::NAMESPACE,
			'/disconnect',
			array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'disconnect' ), 'permission_callback' => array( __CLASS__, 'require_session' ) )
		);
	}

	public static function secure_headers( $response, $server, WP_REST_Request $request ) {
		if ( 0 === strpos( $request->get_route(), '/' . self::NAMESPACE . '/' ) && $response instanceof WP_REST_Response ) {
			$response->header( 'Cache-Control', 'no-store, private, max-age=0' );
			$response->header( 'Pragma', 'no-cache' );
			$response->header( 'X-Content-Type-Options', 'nosniff' );
			$response->header( 'Referrer-Policy', 'no-referrer' );
		}
		return $response;
	}

	public static function serve_challenge( $served, $result, WP_REST_Request $request, $server ): bool {
		if ( $result instanceof WP_REST_Response && '/'. self::NAMESPACE . '/eventsub' === $request->get_route() ) {
			$data = $result->get_data();
			if ( is_array( $data ) && isset( $data['_rfs_raw_challenge'] ) ) {
				$status = $result->get_status();
				status_header( $status );
				header( 'Content-Type: text/plain; charset=utf-8' );
				header( 'Cache-Control: no-store, max-age=0' );
				echo (string) $data['_rfs_raw_challenge']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				return true;
			}
		}
		return (bool) $served;
	}

	public static function health(): WP_REST_Response {
		return self::response(
			array(
				'ok'         => true,
				'configured' => RFS_Twitch::configured(),
				'version'    => RFS_VERSION,
			)
		);
	}

	public static function auth_start( WP_REST_Request $request ) {
		if ( ! RFS_Twitch::configured() ) {
			return self::error( 'not_configured', 'Die Twitch-Verbindung ist auf RoyalFamily.gg noch nicht eingerichtet.', 503 );
		}

		try {
			$channel = RFS_Core::safe_channel( $request->get_param( 'channel' ) );
		} catch ( InvalidArgumentException $error ) {
			return self::error( 'invalid_channel', $error->getMessage(), 400 );
		}

		$rate_key = 'rfs_auth_rate_' . substr( hash( 'sha256', (string) ( $_SERVER['REMOTE_ADDR'] ?? 'unknown' ) ), 0, 24 );
		$count    = (int) get_transient( $rate_key );
		if ( $count >= 10 ) {
			return self::error( 'rate_limited', 'Zu viele Versuche. Bitte warte zehn Minuten.', 429 );
		}
		set_transient( $rate_key, $count + 1, 600 );

		global $wpdb;
		self::cleanup();
		$state = RFS_Crypto::random_token( 32 );
		$now   = time();
		$ok    = $wpdb->insert(
			RFS_DB::table( 'oauth_states' ),
			array( 'state_hash' => RFS_Crypto::hash_token( $state ), 'expected_login' => $channel, 'expires_at' => $now + self::OAUTH_STATE_TTL, 'created_at' => $now ),
			array( '%s', '%s', '%d', '%d' )
		);
		if ( false === $ok ) {
			return self::error( 'database_error', 'Die Anmeldung konnte nicht gestartet werden.', 500 );
		}

		$response = new WP_REST_Response( null, 302 );
		$response->header( 'Location', RFS_Twitch::authorization_url( $state ) );
		return $response;
	}

	public static function auth_callback( WP_REST_Request $request ): WP_REST_Response {
		$oauth_error = sanitize_text_field( (string) $request->get_param( 'error' ) );
		if ( '' !== $oauth_error ) {
			return self::dashboard_redirect( 'Die Twitch-Anmeldung wurde abgebrochen.' );
		}

		$state = (string) $request->get_param( 'state' );
		$code  = (string) $request->get_param( 'code' );
		if ( ! preg_match( '/^[A-Za-z0-9_-]{40,60}$/', $state ) || '' === $code || strlen( $code ) > 512 ) {
			return self::dashboard_redirect( 'Ungültige oder unvollständige Twitch-Anmeldung.' );
		}

		global $wpdb;
		$state_hash = RFS_Crypto::hash_token( $state );
		$row        = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . RFS_DB::table( 'oauth_states' ) . ' WHERE state_hash = %s LIMIT 1', $state_hash ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->delete( RFS_DB::table( 'oauth_states' ), array( 'state_hash' => $state_hash ), array( '%s' ) );
		if ( ! $row || (int) $row['expires_at'] < time() ) {
			return self::dashboard_redirect( 'Der Anmeldelink ist abgelaufen. Bitte erneut verbinden.' );
		}

		try {
			$tokens    = RFS_Twitch::exchange_code( $code );
			$access    = (string) ( $tokens['access_token'] ?? '' );
			$refresh   = (string) ( $tokens['refresh_token'] ?? '' );
			$validated = RFS_Twitch::validate_token( $access );
			$scopes    = array_map( 'strval', (array) ( $validated['scopes'] ?? array() ) );
			if ( array_diff( RFS_Core::SCOPES, $scopes ) ) {
				throw new RuntimeException( 'Nicht alle benötigten Twitch-Rechte wurden erteilt.' );
			}
			$user = RFS_Twitch::get_user( $access );
			$login = RFS_Core::safe_channel( $user['login'] ?? '' );
			if ( $login !== (string) $row['expected_login'] ) {
				RFS_Twitch::revoke_token( $access );
				return self::dashboard_redirect( 'Der angemeldete Twitch-Kanal stimmt nicht mit der Eingabe überein.' );
			}
			if ( '' === $refresh ) {
				throw new RuntimeException( 'Twitch hat kein Aktualisierungstoken geliefert.' );
			}

			$streamer = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . RFS_DB::table( 'streamers' ) . ' WHERE twitch_user_id = %s LIMIT 1', (string) $user['id'] ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$now      = time();
			if ( $streamer ) {
				$streamer_id = (string) $streamer['id'];
				try {
					$overlay_key = RFS_Crypto::decrypt( (string) $streamer['encrypted_overlay_key'] );
				} catch ( Throwable $error ) {
					$overlay_key = RFS_Crypto::random_token( 32 );
				}
				$saved = $wpdb->update(
					RFS_DB::table( 'streamers' ),
					array(
						'twitch_login' => $login, 'display_name' => sanitize_text_field( (string) ( $user['display_name'] ?? $login ) ),
						'encrypted_access_token' => RFS_Crypto::encrypt( $access ), 'encrypted_refresh_token' => RFS_Crypto::encrypt( $refresh ),
						'token_expires_at' => $now + max( 60, (int) ( $tokens['expires_in'] ?? 0 ) ), 'scopes' => wp_json_encode( $scopes ),
						'overlay_key_hash' => RFS_Crypto::hash_token( $overlay_key ), 'encrypted_overlay_key' => RFS_Crypto::encrypt( $overlay_key ),
						'setup_status' => 'pending', 'active' => 1, 'updated_at' => $now,
					),
					array( 'id' => $streamer_id )
				);
				if ( false === $saved ) {
					throw new RuntimeException( 'Der Twitch-Kanal konnte nicht aktualisiert werden.' );
				}
			} else {
				$streamer_id = wp_generate_uuid4();
				$overlay_key = RFS_Crypto::random_token( 32 );
				$ok = $wpdb->insert(
					RFS_DB::table( 'streamers' ),
					array(
						'id' => $streamer_id, 'twitch_user_id' => (string) $user['id'], 'twitch_login' => $login,
						'display_name' => sanitize_text_field( (string) ( $user['display_name'] ?? $login ) ),
						'encrypted_access_token' => RFS_Crypto::encrypt( $access ), 'encrypted_refresh_token' => RFS_Crypto::encrypt( $refresh ),
						'token_expires_at' => $now + max( 60, (int) ( $tokens['expires_in'] ?? 0 ) ), 'scopes' => wp_json_encode( $scopes ),
						'overlay_key_hash' => RFS_Crypto::hash_token( $overlay_key ), 'encrypted_overlay_key' => RFS_Crypto::encrypt( $overlay_key ),
						'setup_status' => 'pending', 'active' => 1, 'created_at' => $now, 'updated_at' => $now,
					)
				);
				if ( false === $ok ) {
					throw new RuntimeException( 'Der Twitch-Kanal konnte nicht gespeichert werden.' );
				}
				self::create_defaults( $streamer_id, $now );
			}

			$session = RFS_Crypto::random_token( 32 );
			$session_saved = $wpdb->insert(
				RFS_DB::table( 'sessions' ),
				array( 'token_hash' => RFS_Crypto::hash_token( $session ), 'streamer_id' => $streamer_id, 'expires_at' => $now + self::SESSION_TTL, 'created_at' => $now ),
				array( '%s', '%s', '%d', '%d' )
			);
			if ( false === $session_saved ) {
				throw new RuntimeException( 'Die persönliche Sitzung konnte nicht erstellt werden.' );
			}

			$notice = '';
			try {
				RFS_Twitch::setup_subscriptions( (string) $user['id'] );
				$wpdb->update( RFS_DB::table( 'streamers' ), array( 'setup_status' => 'ready', 'updated_at' => time() ), array( 'id' => $streamer_id ) );
			} catch ( Throwable $error ) {
				$wpdb->update( RFS_DB::table( 'streamers' ), array( 'setup_status' => 'error', 'updated_at' => time() ), array( 'id' => $streamer_id ) );
				$notice = 'Die Verbindung wurde gespeichert, aber Twitch-Events konnten noch nicht vollständig aktiviert werden.';
			}

			self::cleanup();
			return self::dashboard_redirect( '', $session, $notice );
		} catch ( Throwable $error ) {
			return self::dashboard_redirect( $error->getMessage() );
		}
	}

	public static function eventsub( WP_REST_Request $request ) {
		$body = (string) $request->get_body();
		if ( strlen( $body ) > self::MAX_HOOK_BYTES ) {
			return self::error( 'payload_too_large', 'Webhook zu groß.', 413 );
		}

		$message_id = (string) $request->get_header( 'Twitch-Eventsub-Message-Id' );
		$timestamp  = (string) $request->get_header( 'Twitch-Eventsub-Message-Timestamp' );
		$signature  = (string) $request->get_header( 'Twitch-Eventsub-Message-Signature' );
		$message_type = (string) $request->get_header( 'Twitch-Eventsub-Message-Type' );
		if ( '' === $message_id || strlen( $message_id ) > 128 || '' === $timestamp || '' === $signature ) {
			return self::error( 'invalid_headers', 'Ungültige Twitch-Header.', 403 );
		}
		$sent_at = strtotime( $timestamp );
		if ( false === $sent_at || abs( time() - $sent_at ) > 600 ) {
			return self::error( 'stale_message', 'Veraltete Twitch-Nachricht.', 403 );
		}
		try {
			$expected = 'sha256=' . hash_hmac( 'sha256', $message_id . $timestamp . $body, RFS_Twitch::eventsub_secret() );
		} catch ( Throwable $error ) {
			return self::error( 'not_configured', 'Webhook nicht eingerichtet.', 503 );
		}
		if ( ! hash_equals( $expected, $signature ) ) {
			return self::error( 'invalid_signature', 'Ungültige Twitch-Signatur.', 403 );
		}
		$payload = json_decode( $body, true );
		if ( ! is_array( $payload ) ) {
			return self::error( 'invalid_json', 'Ungültige Twitch-Nachricht.', 400 );
		}

		if ( 'webhook_callback_verification' === $message_type ) {
			$challenge = (string) ( $payload['challenge'] ?? '' );
			if ( '' === $challenge || strlen( $challenge ) > 512 ) {
				return self::error( 'invalid_challenge', 'Ungültige Twitch-Bestätigung.', 400 );
			}
			return new WP_REST_Response( array( '_rfs_raw_challenge' => $challenge ), 200 );
		}

		if ( 'revocation' === $message_type ) {
			self::mark_revoked( (array) ( $payload['subscription'] ?? array() ) );
			return self::response( null, 204 );
		}
		if ( 'notification' !== $message_type ) {
			return self::response( null, 204 );
		}

		global $wpdb;
		$inserted = $wpdb->query( $wpdb->prepare( 'INSERT IGNORE INTO ' . RFS_DB::table( 'event_dedupe' ) . ' (message_id, received_at) VALUES (%s, %d)', $message_id, time() ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( 1 !== $inserted ) {
			return self::response( null, 204 );
		}

		$type  = (string) ( $payload['subscription']['type'] ?? '' );
		$event = (array) ( $payload['event'] ?? array() );
		$broadcaster_id = RFS_Core::broadcaster_id_from_event( $type, $event );
		$streamer = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . RFS_DB::table( 'streamers' ) . ' WHERE twitch_user_id = %s AND active = 1 LIMIT 1', $broadcaster_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( ! $streamer ) {
			return self::response( null, 204 );
		}
		$config = self::config_for( (string) $streamer['id'] );
		$result = RFS_Core::compute_event_delta( $type, $event, $config );
		if ( ! empty( $result['enabled'] ) ) {
			self::apply_adjustment( (string) $streamer['id'], (int) $result['seconds'], (string) $result['label'], true, $config['sleepAdditionsEnabled'] );
		}
		if ( random_int( 1, 100 ) <= 5 ) {
			self::cleanup();
		}
		return self::response( null, 204 );
	}

	public static function require_session( WP_REST_Request $request ) {
		$header = (string) $request->get_header( 'Authorization' );
		if ( ! preg_match( '/^Bearer\s+([A-Za-z0-9_-]{40,60})$/', $header, $match ) ) {
			return self::error( 'unauthorized', 'Sitzung fehlt oder ist ungültig.', 401 );
		}
		global $wpdb;
		$now = time();
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT st.* FROM ' . RFS_DB::table( 'streamers' ) . ' st INNER JOIN ' . RFS_DB::table( 'sessions' ) . ' se ON se.streamer_id = st.id WHERE se.token_hash = %s AND se.expires_at > %d AND st.active = 1 LIMIT 1',
				RFS_Crypto::hash_token( $match[1] ),
				$now
			),
			ARRAY_A
		); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( ! $row ) {
			return self::error( 'unauthorized', 'Sitzung abgelaufen. Bitte Twitch neu verbinden.', 401 );
		}
		$wpdb->update( RFS_DB::table( 'sessions' ), array( 'expires_at' => $now + self::SESSION_TTL ), array( 'token_hash' => RFS_Crypto::hash_token( $match[1] ) ), array( '%d' ), array( '%s' ) );
		$request->set_param( '_rfs_auth', $row );
		return true;
	}

	public static function me( WP_REST_Request $request ): WP_REST_Response {
		$streamer = (array) $request->get_param( '_rfs_auth' );
		return self::response( self::dashboard_data( $streamer ) );
	}

	public static function overlay_state( WP_REST_Request $request ) {
		$key = (string) $request->get_header( 'X-RFS-Overlay-Key' );
		if ( ! preg_match( '/^[A-Za-z0-9_-]{40,60}$/', $key ) ) {
			return self::error( 'not_found', 'OBS-Link ungültig.', 404 );
		}
		global $wpdb;
		$streamer = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . RFS_DB::table( 'streamers' ) . ' WHERE overlay_key_hash = %s AND active = 1 LIMIT 1', RFS_Crypto::hash_token( $key ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( ! $streamer ) {
			return self::error( 'not_found', 'OBS-Link ungültig.', 404 );
		}
		$timer = self::timer_for( (string) $streamer['id'] );
		$timer['channel'] = (string) $streamer['twitch_login'];
		$config = self::config_for( (string) $streamer['id'] );
		$timer['sleepAdditionsEnabled'] = $config['sleepAdditionsEnabled'];
		$timer['sleepTimerContinues'] = $config['sleepTimerContinues'];
		$after = max( 0, (int) $request->get_param( 'after' ) );
		$alerts = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT id, label, seconds, created_at FROM ' . RFS_DB::table( 'alerts' ) . ' WHERE streamer_id = %s AND id > %d AND created_at >= %d ORDER BY id ASC LIMIT 20',
				(string) $streamer['id'],
				$after,
				time() - 60
			),
			ARRAY_A
		); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$timer['alerts'] = array_map(
			static function ( array $alert ): array {
				return array( 'id' => (int) $alert['id'], 'label' => (string) $alert['label'], 'seconds' => (int) $alert['seconds'], 'createdAt' => (int) $alert['created_at'] );
			},
			(array) $alerts
		);
		return self::response( $timer );
	}

	public static function update_config( WP_REST_Request $request ) {
		if ( strlen( (string) $request->get_body() ) > self::MAX_JSON_BYTES ) {
			return self::error( 'payload_too_large', 'Einstellungen zu groß.', 413 );
		}
		$streamer = (array) $request->get_param( '_rfs_auth' );
		try {
			$config = RFS_Core::validate_config( (array) $request->get_json_params() );
		} catch ( InvalidArgumentException $error ) {
			return self::error( 'invalid_config', $error->getMessage(), 400 );
		}
		global $wpdb;
		$current_config = self::config_for( (string) $streamer['id'] );
		$current_timer  = self::raw_timer_for_update( (string) $streamer['id'] );
		if ( ! empty( $current_timer['sleeping'] ) && ( $config['sleepAdditionsEnabled'] !== $current_config['sleepAdditionsEnabled'] || $config['sleepTimerContinues'] !== $current_config['sleepTimerContinues'] ) ) {
			return self::error( 'sleep_active', 'Beende zuerst den Schlafmodus, bevor du seine Regeln änderst.', 409 );
		}
		$data = self::config_to_row( $config );
		$data['updated_at'] = time();
		$wpdb->update( RFS_DB::table( 'timer_configs' ), $data, array( 'streamer_id' => (string) $streamer['id'] ) );
		self::cap_timer( (string) $streamer['id'], (int) $config['maxSeconds'] );
		return self::response( array( 'config' => $config ) );
	}

	public static function timer_action( WP_REST_Request $request ): WP_REST_Response {
		$streamer = (array) $request->get_param( '_rfs_auth' );
		$id       = (string) $streamer['id'];
		$action   = (string) $request->get_param( 'action' );
		$config   = self::config_for( $id );
		global $wpdb;
		self::raw_timer_for_update( $id );
		$wpdb->query( 'START TRANSACTION' );
		try {
			$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . RFS_DB::table( 'timer_states' ) . ' WHERE streamer_id = %s FOR UPDATE', $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			if ( ! empty( $row['sleeping'] ) ) {
				$wpdb->query( 'ROLLBACK' );
				return self::error( 'sleep_active', 'Beende zuerst den Schlafmodus.', 409 );
			}
			$now = time();
			$current = RFS_Core::timer_from_row( (array) $row );
			if ( 'start' === $action ) {
				$remaining = min( (int) $config['maxSeconds'], (int) $current['remainingSeconds'] );
				$data = array( 'running' => $remaining > 0 ? 1 : 0, 'remaining_seconds' => $remaining, 'ends_at' => $remaining > 0 ? $now + $remaining : 0, 'updated_at' => $now );
			} elseif ( 'pause' === $action ) {
				$remaining = min( (int) $config['maxSeconds'], (int) $current['remainingSeconds'] );
				$data = array( 'running' => 0, 'remaining_seconds' => $remaining, 'ends_at' => 0, 'updated_at' => $now );
			} else {
				$data = array( 'running' => 0, 'remaining_seconds' => (int) $config['startSeconds'], 'ends_at' => 0, 'last_event' => null, 'alert_created_at' => 0, 'updated_at' => $now );
			}
			if ( false === $wpdb->update( RFS_DB::table( 'timer_states' ), $data, array( 'streamer_id' => $id ) ) ) {
				throw new RuntimeException( 'Timer konnte nicht gespeichert werden.' );
			}
			$wpdb->query( 'COMMIT' );
			return self::response( array( 'timer' => RFS_Core::timer_from_row( array_merge( (array) $row, $data ) ) ) );
		} catch ( Throwable $error ) {
			$wpdb->query( 'ROLLBACK' );
			return self::error( 'timer_error', 'Der Timer konnte nicht aktualisiert werden.', 500 );
		}
	}

	public static function timer_adjust( WP_REST_Request $request ) {
		if ( strlen( (string) $request->get_body() ) > self::MAX_JSON_BYTES ) {
			return self::error( 'payload_too_large', 'Anfrage zu groß.', 413 );
		}
		$input = (array) $request->get_json_params();
		if ( ! isset( $input['seconds'] ) || ! is_numeric( $input['seconds'] ) ) {
			return self::error( 'invalid_seconds', 'Ungültige Zeitänderung.', 400 );
		}
		$seconds = (int) round( (float) $input['seconds'] );
		if ( $seconds < -43200 || $seconds > 43200 ) {
			return self::error( 'invalid_seconds', 'Die Zeitänderung ist zu groß.', 400 );
		}
		$streamer = (array) $request->get_param( '_rfs_auth' );
		$timer = self::apply_adjustment( (string) $streamer['id'], $seconds, 'Manuelle Anpassung' );
		return self::response( array( 'timer' => $timer ) );
	}

	public static function timer_state( WP_REST_Request $request ): WP_REST_Response {
		$streamer = (array) $request->get_param( '_rfs_auth' );
		return self::response( array( 'timer' => self::timer_for( (string) $streamer['id'] ) ) );
	}

	public static function test_event( WP_REST_Request $request ) {
		if ( strlen( (string) $request->get_body() ) > self::MAX_JSON_BYTES ) {
			return self::error( 'payload_too_large', 'Anfrage zu groß.', 413 );
		}
		$input = (array) $request->get_json_params();
		$key   = sanitize_key( (string) ( $input['key'] ?? '' ) );
		$streamer = (array) $request->get_param( '_rfs_auth' );
		$config = self::config_for( (string) $streamer['id'] );
		try {
			$result = RFS_Core::compute_test_delta( $key, $config );
		} catch ( InvalidArgumentException $error ) {
			return self::error( 'invalid_test', $error->getMessage(), 400 );
		}
		if ( empty( $result['enabled'] ) ) {
			return self::error( 'rule_disabled', 'Aktiviere und speichere diese Regel zuerst.', 409 );
		}
		$updated = self::apply_adjustment( (string) $streamer['id'], (int) $result['seconds'], (string) $result['label'], true, $config['sleepAdditionsEnabled'] );
		return self::response( array( 'timer' => $updated, 'testedSeconds' => (int) $updated['alertSeconds'] ) );
	}

	public static function sleep_action( WP_REST_Request $request ) {
		$streamer = (array) $request->get_param( '_rfs_auth' );
		$id       = (string) $streamer['id'];
		$action   = (string) $request->get_param( 'action' );
		$config   = self::config_for( $id );
		self::raw_timer_for_update( $id );
		global $wpdb;
		$wpdb->query( 'START TRANSACTION' );
		try {
			$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . RFS_DB::table( 'timer_states' ) . ' WHERE streamer_id = %s FOR UPDATE', $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$now = time();
			$current = RFS_Core::timer_from_row( (array) $row );
			if ( 'start' === $action && empty( $row['sleeping'] ) ) {
				$data = array( 'sleeping' => 1, 'sleep_started_at' => $now, 'sleep_resume_timer' => 0, 'updated_at' => $now );
				if ( empty( $config['sleepTimerContinues'] ) && ! empty( $current['running'] ) ) {
					$data['running'] = 0;
					$data['remaining_seconds'] = (int) $current['remainingSeconds'];
					$data['ends_at'] = 0;
					$data['sleep_resume_timer'] = 1;
				}
			} elseif ( 'end' === $action && ! empty( $row['sleeping'] ) ) {
				$data = array( 'sleeping' => 0, 'sleep_started_at' => 0, 'sleep_resume_timer' => 0, 'updated_at' => $now );
				if ( ! empty( $row['sleep_resume_timer'] ) && (int) $current['remainingSeconds'] > 0 ) {
					$data['running'] = 1;
					$data['remaining_seconds'] = (int) $current['remainingSeconds'];
					$data['ends_at'] = $now + (int) $current['remainingSeconds'];
				}
			} else {
				$data = array( 'updated_at' => $now );
			}
			if ( empty( $current['running'] ) && ! empty( $row['running'] ) ) {
				$data['running'] = 0;
				$data['remaining_seconds'] = (int) $current['remainingSeconds'];
				$data['ends_at'] = 0;
			}
			if ( false === $wpdb->update( RFS_DB::table( 'timer_states' ), $data, array( 'streamer_id' => $id ) ) ) {
				throw new RuntimeException( 'Schlafmodus konnte nicht gespeichert werden.' );
			}
			$wpdb->query( 'COMMIT' );
			return self::response( array( 'timer' => RFS_Core::timer_from_row( array_merge( (array) $row, $data ) ) ) );
		} catch ( Throwable $error ) {
			$wpdb->query( 'ROLLBACK' );
			return self::error( 'sleep_error', 'Der Schlafmodus konnte nicht aktualisiert werden.', 500 );
		}
	}

	public static function disconnect( WP_REST_Request $request ): WP_REST_Response {
		$streamer = (array) $request->get_param( '_rfs_auth' );
		try {
			RFS_Twitch::remove_subscriptions( (string) $streamer['twitch_user_id'] );
			RFS_Twitch::revoke_token( RFS_Crypto::decrypt( (string) $streamer['encrypted_access_token'] ) );
		} catch ( Throwable $error ) {
			// Local access is still removed even if Twitch is temporarily unavailable.
		}
		global $wpdb;
		$wpdb->delete( RFS_DB::table( 'sessions' ), array( 'streamer_id' => (string) $streamer['id'] ), array( '%s' ) );
		$wpdb->update(
			RFS_DB::table( 'streamers' ),
			array( 'encrypted_access_token' => '', 'encrypted_refresh_token' => '', 'token_expires_at' => 0, 'setup_status' => 'disconnected', 'active' => 0, 'updated_at' => time() ),
			array( 'id' => (string) $streamer['id'] )
		);
		return self::response( array( 'ok' => true ) );
	}

	private static function dashboard_data( array $streamer ): array {
		$overlay_key = '';
		try {
			$overlay_key = RFS_Crypto::decrypt( (string) $streamer['encrypted_overlay_key'] );
		} catch ( Throwable $error ) {
			$overlay_key = '';
		}
		return array(
			'streamer' => array( 'login' => (string) $streamer['twitch_login'], 'displayName' => (string) $streamer['display_name'], 'setupStatus' => (string) $streamer['setup_status'] ),
			'config' => self::config_for( (string) $streamer['id'] ),
			'timer' => self::timer_for( (string) $streamer['id'] ),
			'overlayKey' => $overlay_key,
		);
	}

	private static function config_for( string $streamer_id ): array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . RFS_DB::table( 'timer_configs' ) . ' WHERE streamer_id = %s LIMIT 1', $streamer_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( ! $row ) {
			self::create_defaults( $streamer_id, time() );
			$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . RFS_DB::table( 'timer_configs' ) . ' WHERE streamer_id = %s LIMIT 1', $streamer_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}
		return self::config_from_row( (array) $row );
	}

	private static function config_from_row( array $row ): array {
		$config = array(
			'startSeconds' => (int) ( $row['start_seconds'] ?? 14400 ),
			'maxSeconds' => (int) ( $row['max_seconds'] ?? 259200 ),
			'sleepAdditionsEnabled' => ! isset( $row['sleep_additions_enabled'] ) || ! empty( $row['sleep_additions_enabled'] ),
			'sleepTimerContinues' => ! isset( $row['sleep_timer_continues'] ) || ! empty( $row['sleep_timer_continues'] ),
		);
		foreach ( RFS_Core::RULE_KEYS as $key ) {
			$config[ $key . 'Seconds' ] = (int) ( $row[ $key . '_seconds' ] ?? 0 );
			$config[ $key . 'Enabled' ] = ! empty( $row[ $key . '_enabled' ] );
		}
		return $config;
	}

	private static function config_to_row( array $config ): array {
		$row = array(
			'start_seconds' => (int) $config['startSeconds'],
			'max_seconds' => (int) $config['maxSeconds'],
			'sleep_additions_enabled' => ! empty( $config['sleepAdditionsEnabled'] ) ? 1 : 0,
			'sleep_timer_continues' => ! empty( $config['sleepTimerContinues'] ) ? 1 : 0,
		);
		foreach ( RFS_Core::RULE_KEYS as $key ) {
			$row[ $key . '_seconds' ] = (int) $config[ $key . 'Seconds' ];
			$row[ $key . '_enabled' ] = ! empty( $config[ $key . 'Enabled' ] ) ? 1 : 0;
		}
		return $row;
	}

	private static function timer_for( string $streamer_id ): array {
		return RFS_Core::timer_from_row( self::raw_timer_for_update( $streamer_id ) );
	}

	private static function raw_timer_for_update( string $streamer_id ): array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . RFS_DB::table( 'timer_states' ) . ' WHERE streamer_id = %s LIMIT 1', $streamer_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( ! $row ) {
			self::create_defaults( $streamer_id, time() );
			$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . RFS_DB::table( 'timer_states' ) . ' WHERE streamer_id = %s LIMIT 1', $streamer_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}
		return (array) $row;
	}

	private static function create_defaults( string $streamer_id, int $now ): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'INSERT IGNORE INTO ' . RFS_DB::table( 'timer_configs' ) . ' (streamer_id, updated_at) VALUES (%s, %d)', $streamer_id, $now ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( $wpdb->prepare( 'INSERT IGNORE INTO ' . RFS_DB::table( 'timer_states' ) . ' (streamer_id, updated_at) VALUES (%s, %d)', $streamer_id, $now ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	private static function apply_adjustment( string $streamer_id, int $seconds, string $label, bool $show_alert = false, ?bool $sleep_additions_enabled = null ): array {
		global $wpdb;
		$config = self::config_for( $streamer_id );
		$wpdb->query( 'START TRANSACTION' );
		try {
			$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . RFS_DB::table( 'timer_states' ) . ' WHERE streamer_id = %s FOR UPDATE', $streamer_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			if ( ! $row ) {
				self::create_defaults( $streamer_id, time() );
				$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . RFS_DB::table( 'timer_states' ) . ' WHERE streamer_id = %s FOR UPDATE', $streamer_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			}
			if ( null !== $sleep_additions_enabled && ! $sleep_additions_enabled && ! empty( $row['sleeping'] ) ) {
				$seconds = 0;
				if ( false === strpos( $label, 'im Schlafmodus nicht addiert' ) ) {
					$label .= ' · im Schlafmodus nicht addiert';
				}
			}
			$now       = time();
			$current   = RFS_Core::timer_from_row( (array) $row );
			$remaining = min( (int) $config['maxSeconds'], max( 0, (int) $current['remainingSeconds'] + $seconds ) );
			$running   = ! empty( $current['running'] ) && $remaining > 0;
			$data      = array(
				'running' => $running ? 1 : 0,
				'remaining_seconds' => $remaining,
				'ends_at' => $running ? $now + $remaining : 0,
				'last_event' => '' !== $label ? wp_html_excerpt( sanitize_text_field( $label ), 255, '' ) : null,
				'updated_at' => $now,
			);
			if ( $show_alert ) {
				$data['alert_id'] = (int) ( $row['alert_id'] ?? 0 ) + 1;
				$data['alert_label'] = wp_html_excerpt( sanitize_text_field( $label ), 255, '' );
				$data['alert_seconds'] = $seconds;
				$data['alert_created_at'] = $now;
			}
			if ( false === $wpdb->update( RFS_DB::table( 'timer_states' ), $data, array( 'streamer_id' => $streamer_id ) ) ) {
				throw new RuntimeException( 'Timer konnte nicht gespeichert werden.' );
			}
			if ( $show_alert ) {
				$alert_saved = $wpdb->insert(
					RFS_DB::table( 'alerts' ),
					array( 'streamer_id' => $streamer_id, 'label' => $data['alert_label'], 'seconds' => $seconds, 'created_at' => $now ),
					array( '%s', '%s', '%d', '%d' )
				);
				if ( false === $alert_saved ) {
					throw new RuntimeException( 'Alert konnte nicht gespeichert werden.' );
				}
			}
			$wpdb->query( 'COMMIT' );
			return RFS_Core::timer_from_row( array_merge( (array) $row, $data ) );
		} catch ( Throwable $error ) {
			$wpdb->query( 'ROLLBACK' );
			throw $error;
		}
	}

	private static function cap_timer( string $streamer_id, int $max_seconds ): void {
		global $wpdb;
		self::raw_timer_for_update( $streamer_id );
		$wpdb->query( 'START TRANSACTION' );
		try {
			$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . RFS_DB::table( 'timer_states' ) . ' WHERE streamer_id = %s FOR UPDATE', $streamer_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$current = RFS_Core::timer_from_row( (array) $row );
			$remaining = min( $max_seconds, (int) $current['remainingSeconds'] );
			$running = ! empty( $current['running'] ) && $remaining > 0;
			if ( false === $wpdb->update( RFS_DB::table( 'timer_states' ), array( 'running' => $running ? 1 : 0, 'remaining_seconds' => $remaining, 'ends_at' => $running ? time() + $remaining : 0, 'updated_at' => time() ), array( 'streamer_id' => $streamer_id ) ) ) {
				throw new RuntimeException( 'Timer konnte nicht begrenzt werden.' );
			}
			$wpdb->query( 'COMMIT' );
		} catch ( Throwable $error ) {
			$wpdb->query( 'ROLLBACK' );
			throw $error;
		}
	}

	private static function mark_revoked( array $subscription ): void {
		$condition = (array) ( $subscription['condition'] ?? array() );
		$ids = array_filter( array_map( 'strval', array_values( $condition ) ) );
		if ( ! $ids ) {
			return;
		}
		global $wpdb;
		foreach ( $ids as $id ) {
			$wpdb->update( RFS_DB::table( 'streamers' ), array( 'setup_status' => 'revoked', 'updated_at' => time() ), array( 'twitch_user_id' => $id ) );
		}
	}

	private static function cleanup(): void {
		global $wpdb;
		$now = time();
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . RFS_DB::table( 'oauth_states' ) . ' WHERE expires_at < %d', $now ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . RFS_DB::table( 'sessions' ) . ' WHERE expires_at < %d', $now ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . RFS_DB::table( 'event_dedupe' ) . ' WHERE received_at < %d', $now - DAY_IN_SECONDS ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . RFS_DB::table( 'alerts' ) . ' WHERE created_at < %d', $now - DAY_IN_SECONDS ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	private static function dashboard_redirect( string $error = '', string $session = '', string $notice = '' ): WP_REST_Response {
		$url = home_url( '/subathon/' );
		if ( '' !== $error ) {
			$url = add_query_arg( 'error', $error, $url );
		} elseif ( '' !== $notice ) {
			$url = add_query_arg( 'notice', $notice, $url );
		}
		if ( '' !== $session ) {
			$url .= '#session=' . rawurlencode( $session );
		}
		$response = new WP_REST_Response( null, 302 );
		$response->header( 'Location', $url );
		return $response;
	}

	private static function response( $data = null, int $status = 200 ): WP_REST_Response {
		return new WP_REST_Response( $data, $status );
	}

	private static function error( string $code, string $message, int $status ): WP_Error {
		return new WP_Error( $code, $message, array( 'status' => $status ) );
	}
}
