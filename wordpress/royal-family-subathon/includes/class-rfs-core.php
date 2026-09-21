<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class RFS_Core {
	public const RULE_KEYS = array( 'tier1', 'tier2', 'tier3', 'gift', 'bits', 'follow', 'raid', 'reward' );
	public const SCOPES    = array( 'channel:read:subscriptions', 'bits:read', 'moderator:read:followers', 'channel:read:redemptions' );

	public static function safe_channel( $value ): string {
		$channel = strtolower( trim( (string) $value ) );
		if ( ! preg_match( '/^[a-z0-9_]{3,25}$/', $channel ) ) {
			throw new InvalidArgumentException( 'Ungültiger Twitch-Kanalname.' );
		}
		return $channel;
	}

	public static function validate_config( array $input ): array {
		$end_mode = (string) ( $input['endMode'] ?? 'open' );
		if ( ! in_array( $end_mode, array( 'open', 'fixed' ), true ) ) {
			throw new InvalidArgumentException( 'Ungültige Einstellung für das Streamende.' );
		}
		$max_mode = (string) ( $input['maxMode'] ?? 'limited' );
		if ( ! in_array( $max_mode, array( 'limited', 'open' ), true ) ) {
			throw new InvalidArgumentException( 'Ungültige Einstellung für die maximale Zeit.' );
		}
		$result = array(
			'startSeconds' => self::bounded_int( $input['startSeconds'] ?? null, 0, 2592000 ),
			'maxSeconds'   => self::bounded_int( $input['maxSeconds'] ?? null, 3600, 2592000 ),
			'maxMode'      => $max_mode,
			'streamStartAt' => self::bounded_int( $input['streamStartAt'] ?? 0, 0, 4102444800 ),
			'endMode'       => $end_mode,
			'streamEndAt'   => 'fixed' === $end_mode ? self::bounded_int( $input['streamEndAt'] ?? 0, 1, 4102444800 ) : 0,
			'sleepAdditionsEnabled' => array_key_exists( 'sleepAdditionsEnabled', $input ) ? ! empty( $input['sleepAdditionsEnabled'] ) : true,
			'sleepTimerContinues'   => array_key_exists( 'sleepTimerContinues', $input ) ? ! empty( $input['sleepTimerContinues'] ) : true,
		);
		if ( 'limited' === $result['maxMode'] && $result['maxSeconds'] < $result['startSeconds'] ) {
			throw new InvalidArgumentException( 'Das Zeitlimit muss mindestens so groß wie die Startzeit sein.' );
		}
		if ( 'fixed' === $result['endMode'] ) {
			if ( $result['streamEndAt'] <= time() ) {
				throw new InvalidArgumentException( 'Das späteste Streamende muss in der Zukunft liegen.' );
			}
			if ( $result['streamStartAt'] > 0 && $result['streamEndAt'] <= $result['streamStartAt'] ) {
				throw new InvalidArgumentException( 'Das späteste Streamende muss nach dem Start liegen.' );
			}
		}
		foreach ( self::RULE_KEYS as $key ) {
			$result[ $key . 'Seconds' ] = self::bounded_int( $input[ $key . 'Seconds' ] ?? null, 0, 43200 );
			$result[ $key . 'Enabled' ] = ! empty( $input[ $key . 'Enabled' ] );
		}
		return $result;
	}

	public static function broadcaster_id_from_event( string $type, array $event ): string {
		if ( 'channel.raid' === $type ) {
			return (string) ( $event['to_broadcaster_user_id'] ?? '' );
		}
		return (string) ( $event['broadcaster_user_id'] ?? '' );
	}

	public static function compute_event_delta( string $type, array $event, array $config ): array {
		if ( 'channel.subscribe' === $type ) {
			if ( ! empty( $event['is_gift'] ) ) {
				return array( 'seconds' => 0, 'label' => '', 'enabled' => false );
			}
			$key = self::tier_key( (string) ( $event['tier'] ?? '' ) );
			return self::rule_result( $key, $config, self::tier_label( (string) ( $event['tier'] ?? '' ) ) . ' · ' . ( $event['user_name'] ?? 'Sub' ) );
		}

		if ( 'channel.subscription.message' === $type ) {
			$key = self::tier_key( (string) ( $event['tier'] ?? '' ) );
			return self::rule_result( $key, $config, 'Resub ' . self::tier_label( (string) ( $event['tier'] ?? '' ) ) . ' · ' . ( $event['user_name'] ?? 'Sub' ) );
		}

		if ( 'channel.subscription.gift' === $type ) {
			$count   = max( 1, (int) ( $event['total'] ?? 1 ) );
			$seconds = ! empty( $config['giftEnabled'] ) ? (int) $config['giftSeconds'] * $count : 0;
			return array( 'seconds' => $seconds, 'label' => $count . ' Gift-Sub' . ( 1 === $count ? '' : 's' ) . ' · ' . ( $event['user_name'] ?? 'Anonym' ), 'enabled' => ! empty( $config['giftEnabled'] ) );
		}

		if ( 'channel.cheer' === $type ) {
			$steps   = max( 0, (int) floor( (int) ( $event['bits'] ?? 0 ) / 100 ) );
			$seconds = ! empty( $config['bitsEnabled'] ) ? (int) $config['bitsSeconds'] * $steps : 0;
			return array( 'seconds' => $seconds, 'label' => (int) ( $event['bits'] ?? 0 ) . ' Bits · ' . ( $event['user_name'] ?? 'Anonym' ), 'enabled' => ! empty( $config['bitsEnabled'] ) );
		}

		if ( 'channel.follow' === $type ) {
			return self::rule_result( 'follow', $config, 'Follow · ' . ( $event['user_name'] ?? 'Zuschauer' ) );
		}

		if ( 'channel.raid' === $type ) {
			$viewers = max( 0, (int) ( $event['viewers'] ?? 0 ) );
			$seconds = ! empty( $config['raidEnabled'] ) ? (int) $config['raidSeconds'] * $viewers : 0;
			return array( 'seconds' => $seconds, 'label' => ( $event['from_broadcaster_user_name'] ?? 'Raid' ) . ': ' . $viewers . ' Zuschauer', 'enabled' => ! empty( $config['raidEnabled'] ) );
		}

		if ( 'channel.channel_points_custom_reward_redemption.add' === $type ) {
			return self::rule_result( 'reward', $config, 'Channel Points · ' . ( $event['reward']['title'] ?? 'Belohnung' ) );
		}
		if ( 'channel.channel_points_automatic_reward_redemption.add' === $type ) {
			$reward_type = (string) ( $event['reward']['type'] ?? '' );
			$labels = array(
				'send_highlighted_message' => 'Nachricht hervorheben',
				'choose_sub_emote' => 'Sub-Emote wählen',
				'random_sub_emote_unlock' => 'Zufälligen Sub-Emote freischalten',
				'chosen_sub_emote_unlock' => 'Sub-Emote freischalten',
				'choose_modified_sub_emote' => 'Emote verändern',
			);
			return self::rule_result( 'reward', $config, 'Channel Points · ' . ( $labels[ $reward_type ] ?? 'Automatische Belohnung' ) );
		}

		return array( 'seconds' => 0, 'label' => '', 'enabled' => false );
	}

	public static function compute_test_delta( string $key, array $config ): array {
		if ( ! in_array( $key, self::RULE_KEYS, true ) ) {
			throw new InvalidArgumentException( 'Unbekanntes Test-Ereignis.' );
		}
		$labels = array(
			'tier1' => 'Test · T1 / Prime Sub',
			'tier2' => 'Test · Tier 2 Sub',
			'tier3' => 'Test · Tier 3 Sub',
			'gift' => 'Test · Gift-Sub',
			'bits' => 'Test · 100 Bits',
			'follow' => 'Test · Follow',
			'raid' => 'Test · 1 Raid-Zuschauer',
			'reward' => 'Test · Channel Points',
		);
		return array(
			'seconds' => (int) ( $config[ $key . 'Seconds' ] ?? 0 ),
			'label'   => $labels[ $key ],
			'enabled' => ! empty( $config[ $key . 'Enabled' ] ),
		);
	}

	public static function timer_from_row( array $row ): array {
		$running   = ! empty( $row['running'] );
		$ends_at   = (int) ( $row['ends_at'] ?? 0 );
		$remaining = $running ? max( 0, $ends_at - time() ) : max( 0, (int) ( $row['remaining_seconds'] ?? 0 ) );
		if ( $running && 0 === $remaining ) {
			$running = false;
		}
		return array(
			'running'          => $running,
			'remainingSeconds' => $remaining,
			'endsAt'           => $running ? $ends_at : 0,
			'lastEvent'        => $row['last_event'] ?? null,
			'sleeping'         => ! empty( $row['sleeping'] ),
			'sleepStartedAt'   => (int) ( $row['sleep_started_at'] ?? 0 ),
			'alertId'          => (int) ( $row['alert_id'] ?? 0 ),
			'alertLabel'       => $row['alert_label'] ?? null,
			'alertSeconds'     => (int) ( $row['alert_seconds'] ?? 0 ),
			'alertCreatedAt'   => (int) ( $row['alert_created_at'] ?? 0 ),
		);
	}

	private static function rule_result( string $key, array $config, string $label ): array {
		return array(
			'seconds' => ! empty( $config[ $key . 'Enabled' ] ) ? (int) $config[ $key . 'Seconds' ] : 0,
			'label'   => $label,
			'enabled' => ! empty( $config[ $key . 'Enabled' ] ),
		);
	}

	private static function tier_key( string $tier ): string {
		return '3000' === $tier ? 'tier3' : ( '2000' === $tier ? 'tier2' : 'tier1' );
	}

	private static function tier_label( string $tier ): string {
		return '3000' === $tier ? 'Tier 3' : ( '2000' === $tier ? 'Tier 2' : 'T1 / Prime' );
	}

	private static function bounded_int( $value, int $min, int $max ): int {
		if ( ! is_numeric( $value ) ) {
			throw new InvalidArgumentException( 'Ungültiger Zahlenwert.' );
		}
		$number = (int) round( (float) $value );
		return min( $max, max( $min, $number ) );
	}
}
