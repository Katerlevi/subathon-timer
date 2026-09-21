<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class RFS_Twitch {
	private const API_BASE = 'https://api.twitch.tv/helix';
	private const ID_BASE  = 'https://id.twitch.tv/oauth2';

	public static function configured(): bool {
		return '' !== self::client_id() && '' !== self::client_secret();
	}

	public static function client_id(): string {
		return trim( (string) get_option( 'rfs_twitch_client_id', '' ) );
	}

	public static function client_secret(): string {
		$stored = (string) get_option( 'rfs_twitch_client_secret', '' );
		if ( '' === $stored ) {
			return '';
		}
		try {
			return RFS_Crypto::decrypt( $stored );
		} catch ( Throwable $error ) {
			return '';
		}
	}

	public static function eventsub_secret(): string {
		$stored = (string) get_option( 'rfs_eventsub_secret', '' );
		if ( '' === $stored ) {
			return '';
		}
		return RFS_Crypto::decrypt( $stored );
	}

	public static function callback_url(): string {
		return rest_url( 'royal-family-subathon/v1/auth/callback' );
	}

	public static function webhook_url(): string {
		return rest_url( 'royal-family-subathon/v1/eventsub' );
	}

	public static function authorization_url( string $state ): string {
		$query = array(
			'response_type' => 'code',
			'client_id'     => self::client_id(),
			'redirect_uri'  => self::callback_url(),
			'scope'         => implode( ' ', RFS_Core::SCOPES ),
			'state'         => $state,
			'force_verify'  => 'true',
		);
		return add_query_arg( $query, self::ID_BASE . '/authorize' );
	}

	public static function exchange_code( string $code ): array {
		return self::request_json(
			self::ID_BASE . '/token',
			array(
				'method'  => 'POST',
				'timeout' => 15,
				'body'    => array(
					'client_id'     => self::client_id(),
					'client_secret' => self::client_secret(),
					'code'          => $code,
					'grant_type'    => 'authorization_code',
					'redirect_uri'  => self::callback_url(),
				),
			)
		);
	}

	public static function validate_token( string $access_token ): array {
		return self::request_json(
			self::ID_BASE . '/validate',
			array(
				'method'  => 'GET',
				'timeout' => 10,
				'headers' => array( 'Authorization' => 'OAuth ' . $access_token ),
			)
		);
	}

	public static function get_user( string $access_token ): array {
		$payload = self::request_json(
			self::API_BASE . '/users',
			array(
				'method'  => 'GET',
				'timeout' => 10,
				'headers' => self::api_headers( $access_token ),
			)
		);
		if ( empty( $payload['data'][0] ) || ! is_array( $payload['data'][0] ) ) {
			throw new RuntimeException( 'Twitch-Benutzer konnte nicht geladen werden.' );
		}
		return $payload['data'][0];
	}

	public static function setup_subscriptions( string $broadcaster_id ): void {
		$app_token = self::app_token();
		$callback  = self::webhook_url();
		$secret    = self::eventsub_secret();
		$items     = array(
			array( 'channel.subscribe', '1', array( 'broadcaster_user_id' => $broadcaster_id ) ),
			array( 'channel.subscription.message', '1', array( 'broadcaster_user_id' => $broadcaster_id ) ),
			array( 'channel.subscription.gift', '1', array( 'broadcaster_user_id' => $broadcaster_id ) ),
			array( 'channel.cheer', '1', array( 'broadcaster_user_id' => $broadcaster_id ) ),
			array( 'channel.follow', '2', array( 'broadcaster_user_id' => $broadcaster_id, 'moderator_user_id' => $broadcaster_id ) ),
			array( 'channel.raid', '1', array( 'to_broadcaster_user_id' => $broadcaster_id ) ),
			array( 'channel.channel_points_custom_reward_redemption.add', '1', array( 'broadcaster_user_id' => $broadcaster_id ) ),
			array( 'channel.channel_points_automatic_reward_redemption.add', '2', array( 'broadcaster_user_id' => $broadcaster_id ) ),
		);

		foreach ( $items as $item ) {
			$response = wp_remote_post(
				self::API_BASE . '/eventsub/subscriptions',
				array(
					'timeout' => 15,
					'headers' => self::api_headers( $app_token, true ),
					'body'    => wp_json_encode(
						array(
							'type'      => $item[0],
							'version'   => $item[1],
							'condition' => $item[2],
							'transport' => array( 'method' => 'webhook', 'callback' => $callback, 'secret' => $secret ),
						)
					),
				)
			);
			if ( is_wp_error( $response ) ) {
				throw new RuntimeException( 'Twitch EventSub ist nicht erreichbar.' );
			}
			$status = (int) wp_remote_retrieve_response_code( $response );
			if ( $status < 200 || ( $status >= 300 && 409 !== $status ) ) {
				throw new RuntimeException( 'Twitch EventSub konnte nicht vollständig eingerichtet werden.' );
			}
		}
	}

	public static function subscription_status( string $broadcaster_id ): array {
		$types = array(
			'channel.subscribe' => array( 'subscribe', '1', 'broadcaster_user_id' ),
			'channel.subscription.message' => array( 'resub', '1', 'broadcaster_user_id' ),
			'channel.subscription.gift' => array( 'gift', '1', 'broadcaster_user_id' ),
			'channel.cheer' => array( 'cheer', '1', 'broadcaster_user_id' ),
			'channel.follow' => array( 'follow', '2', 'broadcaster_user_id' ),
			'channel.raid' => array( 'raid', '1', 'to_broadcaster_user_id' ),
			'channel.channel_points_custom_reward_redemption.add' => array( 'custom', '1', 'broadcaster_user_id' ),
			'channel.channel_points_automatic_reward_redemption.add' => array( 'automatic', '2', 'broadcaster_user_id' ),
		);
		$statuses = array_fill_keys( array_column( $types, 0 ), 'missing' );
		$priority = array( 'missing' => 0, 'webhook_callback_verification_failed' => 1, 'webhook_callback_verification_pending' => 2, 'enabled' => 3 );
		$token = self::app_token();
		$cursor = '';
		for ( $page = 0; $page < 10; $page++ ) {
			$url = self::API_BASE . '/eventsub/subscriptions?first=100';
			if ( '' !== $cursor ) {
				$url = add_query_arg( 'after', $cursor, $url );
			}
			$payload = self::request_json( $url, array( 'method' => 'GET', 'timeout' => 15, 'headers' => self::api_headers( $token ) ) );
			foreach ( (array) ( $payload['data'] ?? array() ) as $subscription ) {
				$type = (string) ( $subscription['type'] ?? '' );
				if ( ! isset( $types[ $type ] ) || (string) ( $subscription['version'] ?? '' ) !== $types[ $type ][1] || (string) ( $subscription['condition'][ $types[ $type ][2] ] ?? '' ) !== $broadcaster_id || (string) ( $subscription['transport']['callback'] ?? '' ) !== self::webhook_url() ) {
					continue;
				}
				$key = $types[ $type ][0];
				$status = (string) ( $subscription['status'] ?? 'unknown' );
				if ( ( $priority[ $status ] ?? 1 ) > ( $priority[ $statuses[ $key ] ] ?? 0 ) ) {
					$statuses[ $key ] = $status;
				}
			}
			$cursor = (string) ( $payload['pagination']['cursor'] ?? '' );
			if ( '' === $cursor ) {
				break;
			}
		}
		$values = array_values( $statuses );
		$active_count = count( array_filter( $values, static function ( $status ) { return 'enabled' === $status; } ) );
		$pending_count = count( array_filter( $values, static function ( $status ) { return 'webhook_callback_verification_pending' === $status; } ) );
		$overall = count( $values ) === $active_count ? 'enabled' : ( count( $values ) === $active_count + $pending_count ? 'pending' : 'unavailable' );
		return array_merge( $statuses, array( 'overall' => $overall, 'activeCount' => $active_count, 'requiredCount' => count( $values ) ) );
	}

	public static function revoke_token( string $access_token ): void {
		$response = wp_remote_post(
			self::ID_BASE . '/revoke',
			array(
				'timeout' => 10,
				'body'    => array( 'client_id' => self::client_id(), 'token' => $access_token ),
			)
		);
		if ( is_wp_error( $response ) ) {
			return;
		}
	}

	public static function remove_subscriptions( string $broadcaster_id ): void {
		try {
			$app_token = self::app_token();
			$cursor    = '';
			for ( $page = 0; $page < 10; $page++ ) {
				$url = self::API_BASE . '/eventsub/subscriptions?first=100';
				if ( '' !== $cursor ) {
					$url = add_query_arg( 'after', $cursor, $url );
				}
				$payload = self::request_json( $url, array( 'method' => 'GET', 'timeout' => 15, 'headers' => self::api_headers( $app_token ) ) );
				foreach ( $payload['data'] ?? array() as $subscription ) {
					$condition = $subscription['condition'] ?? array();
					if ( in_array( $broadcaster_id, array_map( 'strval', array_values( $condition ) ), true ) ) {
						wp_remote_request(
							add_query_arg( 'id', (string) $subscription['id'], self::API_BASE . '/eventsub/subscriptions' ),
							array( 'method' => 'DELETE', 'timeout' => 10, 'headers' => self::api_headers( $app_token ) )
						);
					}
				}
				$cursor = (string) ( $payload['pagination']['cursor'] ?? '' );
				if ( '' === $cursor ) {
					break;
				}
			}
		} catch ( Throwable $error ) {
			return;
		}
	}

	private static function app_token(): string {
		$cached = get_transient( 'rfs_twitch_app_token' );
		if ( is_string( $cached ) && '' !== $cached ) {
			try {
				return RFS_Crypto::decrypt( $cached );
			} catch ( Throwable $error ) {
				delete_transient( 'rfs_twitch_app_token' );
			}
		}

		$payload = self::request_json(
			self::ID_BASE . '/token',
			array(
				'method'  => 'POST',
				'timeout' => 15,
				'body'    => array( 'client_id' => self::client_id(), 'client_secret' => self::client_secret(), 'grant_type' => 'client_credentials' ),
			)
		);
		$token = (string) ( $payload['access_token'] ?? '' );
		if ( '' === $token ) {
			throw new RuntimeException( 'Twitch-App-Token fehlt.' );
		}
		set_transient( 'rfs_twitch_app_token', RFS_Crypto::encrypt( $token ), max( 60, (int) ( $payload['expires_in'] ?? 3600 ) - 60 ) );
		return $token;
	}

	private static function api_headers( string $token, bool $json = false ): array {
		$headers = array( 'Authorization' => 'Bearer ' . $token, 'Client-Id' => self::client_id() );
		if ( $json ) {
			$headers['Content-Type'] = 'application/json';
		}
		return $headers;
	}

	private static function request_json( string $url, array $args ): array {
		$response = wp_remote_request( $url, $args );
		if ( is_wp_error( $response ) ) {
			throw new RuntimeException( 'Twitch ist momentan nicht erreichbar.' );
		}
		$status = (int) wp_remote_retrieve_response_code( $response );
		$body   = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( $status < 200 || $status >= 300 || ! is_array( $body ) ) {
			throw new RuntimeException( 'Twitch-Anfrage wurde abgelehnt.' );
		}
		return $body;
	}
}
