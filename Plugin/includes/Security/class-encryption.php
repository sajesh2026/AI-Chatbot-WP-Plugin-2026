<?php
/**
 * Secret encryption and key masking service.
 *
 * @package AIChatbot\Security
 */

namespace AIChatbot\Security;

defined( 'ABSPATH' ) || exit;

/**
 * Encryption class.
 */
class Encryption {

	/**
	 * Derive 32-byte binary encryption key from salts or constant.
	 *
	 * @return string
	 */
	public static function get_derived_key(): string {
		if ( defined( 'AI_CHATBOT_ENCRYPTION_KEY' ) && ! empty( AI_CHATBOT_ENCRYPTION_KEY ) ) {
			return hash( 'sha256', AI_CHATBOT_ENCRYPTION_KEY, true );
		}

		$salts = '';
		if ( defined( 'AUTH_KEY' ) ) {
			$salts .= AUTH_KEY;
		}
		if ( defined( 'SECURE_AUTH_KEY' ) ) {
			$salts .= SECURE_AUTH_KEY;
		}
		if ( defined( 'LOGGED_IN_KEY' ) ) {
			$salts .= LOGGED_IN_KEY;
		}
		if ( defined( 'NONCE_KEY' ) ) {
			$salts .= NONCE_KEY;
		}

		if ( empty( $salts ) ) {
			$salts = 'ai-chatbot-secure-fallback-salt-983421764';
		}

		return hash( 'sha256', $salts . 'ai_chatbot_key_vault', true );
	}

	/**
	 * Encrypt a plaintext secret string.
	 *
	 * @param string $plaintext Value to encrypt.
	 * @return string Base64 encoded payload with nonce/IV.
	 */
	public static function encrypt( string $plaintext ): string {
		if ( '' === $plaintext ) {
			return '';
		}

		// Prevent nested re-encryption if an already encrypted payload is passed.
		if ( str_starts_with( $plaintext, 'sod:' ) || str_starts_with( $plaintext, 'gcm:' ) ) {
			return $plaintext;
		}

		$key = self::get_derived_key();

		if ( function_exists( 'sodium_crypto_secretbox' ) ) {
			$nonce      = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			$ciphertext = sodium_crypto_secretbox( $plaintext, $nonce, $key );
			return 'sod:' . base64_encode( $nonce . $ciphertext );
		}

		// Fallback to AES-256-GCM via OpenSSL.
		$iv         = random_bytes( 12 );
		$tag        = '';
		$ciphertext = openssl_encrypt( $plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag );
		return 'gcm:' . base64_encode( $iv . $tag . $ciphertext );
	}

	/**
	 * Decrypt an encrypted string payload.
	 * Supports multi-round unwrap to recover from legacy double-encryption.
	 *
	 * @param string $encrypted Base64 encoded payload.
	 * @return string Decrypted plaintext or empty on failure.
	 */
	public static function decrypt( string $encrypted ): string {
		if ( empty( $encrypted ) ) {
			return '';
		}

		$current    = trim( (string) $encrypted );
		$rounds     = 0;
		$max_rounds = 10;

		while ( ( str_starts_with( $current, 'sod:' ) || str_starts_with( $current, 'gcm:' ) ) && $rounds < $max_rounds ) {
			$rounds++;
			$key       = self::get_derived_key();
			$decrypted = false;

			if ( str_starts_with( $current, 'sod:' ) ) {
				$raw   = base64_decode( substr( $current, 4 ) );
				$n_len = SODIUM_CRYPTO_SECRETBOX_NONCEBYTES;
				if ( strlen( $raw ) <= $n_len ) {
					return '';
				}
				$nonce      = substr( $raw, 0, $n_len );
				$ciphertext = substr( $raw, $n_len );

				if ( function_exists( 'sodium_crypto_secretbox_open' ) ) {
					$decrypted = sodium_crypto_secretbox_open( $ciphertext, $nonce, $key );
				}
			} elseif ( str_starts_with( $current, 'gcm:' ) ) {
				$raw = base64_decode( substr( $current, 4 ) );
				if ( strlen( $raw ) < 28 ) { // 12 iv + 16 tag
					return '';
				}
				$iv         = substr( $raw, 0, 12 );
				$tag        = substr( $raw, 12, 16 );
				$ciphertext = substr( $raw, 28 );
				$decrypted  = openssl_decrypt( $ciphertext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag );
			}

			if ( false === $decrypted || '' === $decrypted ) {
				return $current;
			}

			$current = (string) $decrypted;
		}

		return $current;
	}

	/**
	 * Mask a secret string for safe browser display.
	 * Shows only the last 4 characters.
	 *
	 * @param string $key Plaintext key.
	 * @return string Masked key representation (e.g., '••••••••••••1234').
	 */
	public static function mask_key( string $key ): string {
		$len = strlen( $key );
		if ( 0 === $len ) {
			return '';
		}

		if ( $len <= 6 ) {
			return str_repeat( '•', $len );
		}

		$visible = substr( $key, -4 );
		return str_repeat( '•', max( 8, $len - 4 ) ) . $visible;
	}

	/**
	 * Extract safe last 4 characters from key.
	 *
	 * @param string $key Plaintext key.
	 * @return string Last 4 characters.
	 */
	public static function get_last_four( string $key ): string {
		if ( strlen( $key ) < 4 ) {
			return str_pad( $key, 4, '*' );
		}
		return substr( $key, -4 );
	}
}
