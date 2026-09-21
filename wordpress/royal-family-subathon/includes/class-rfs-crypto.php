<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class RFS_Crypto {
	private const SODIUM_PREFIX  = 's1.';
	private const OPENSSL_PREFIX = 'o1.';

	public static function random_token( int $bytes = 32 ): string {
		return self::base64url_encode( random_bytes( $bytes ) );
	}

	public static function hash_token( string $token ): string {
		return hash( 'sha256', $token );
	}

	public static function encrypt( string $plain_text ): string {
		$key = self::key();
		if ( function_exists( 'sodium_crypto_secretbox' ) ) {
			$nonce  = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			$cipher = sodium_crypto_secretbox( $plain_text, $nonce, $key );
			return self::SODIUM_PREFIX . self::base64url_encode( $nonce . $cipher );
		}

		if ( ! function_exists( 'openssl_encrypt' ) ) {
			throw new RuntimeException( 'Weder Sodium noch OpenSSL ist verfügbar.' );
		}
		$iv     = random_bytes( 12 );
		$tag    = '';
		$cipher = openssl_encrypt( $plain_text, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag );
		if ( false === $cipher ) {
			throw new RuntimeException( 'Verschlüsselung fehlgeschlagen.' );
		}
		return self::OPENSSL_PREFIX . self::base64url_encode( $iv . $tag . $cipher );
	}

	public static function decrypt( string $stored ): string {
		$key = self::key();
		if ( 0 === strpos( $stored, self::SODIUM_PREFIX ) ) {
			if ( ! function_exists( 'sodium_crypto_secretbox_open' ) ) {
				throw new RuntimeException( 'Sodium ist für diese Daten nicht verfügbar.' );
			}
			$packed = self::base64url_decode( substr( $stored, strlen( self::SODIUM_PREFIX ) ) );
			$nonce  = substr( $packed, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			$cipher = substr( $packed, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			$plain  = sodium_crypto_secretbox_open( $cipher, $nonce, $key );
			if ( false === $plain ) {
				throw new RuntimeException( 'Entschlüsselung fehlgeschlagen.' );
			}
			return $plain;
		}

		if ( 0 === strpos( $stored, self::OPENSSL_PREFIX ) ) {
			if ( ! function_exists( 'openssl_decrypt' ) ) {
				throw new RuntimeException( 'OpenSSL ist für diese Daten nicht verfügbar.' );
			}
			$packed = self::base64url_decode( substr( $stored, strlen( self::OPENSSL_PREFIX ) ) );
			$iv     = substr( $packed, 0, 12 );
			$tag    = substr( $packed, 12, 16 );
			$cipher = substr( $packed, 28 );
			$plain  = openssl_decrypt( $cipher, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag );
			if ( false === $plain ) {
				throw new RuntimeException( 'Entschlüsselung fehlgeschlagen.' );
			}
			return $plain;
		}

		throw new RuntimeException( 'Unbekanntes Verschlüsselungsformat.' );
	}

	private static function key(): string {
		$material = wp_salt( 'auth' ) . '|' . wp_salt( 'secure_auth' );
		return hash_hkdf( 'sha256', $material, 32, 'royal-family-subathon' );
	}

	private static function base64url_encode( string $value ): string {
		return rtrim( strtr( base64_encode( $value ), '+/', '-_' ), '=' );
	}

	private static function base64url_decode( string $value ): string {
		$padding = strlen( $value ) % 4;
		if ( $padding ) {
			$value .= str_repeat( '=', 4 - $padding );
		}
		$decoded = base64_decode( strtr( $value, '-_', '+/' ), true );
		if ( false === $decoded ) {
			throw new RuntimeException( 'Ungültige verschlüsselte Daten.' );
		}
		return $decoded;
	}
}
