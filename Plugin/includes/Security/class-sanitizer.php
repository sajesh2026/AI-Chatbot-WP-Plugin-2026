<?php
/**
 * Sanitization, PII scrubbing, and abuse prevention helper.
 *
 * @package AIChatbot\Security
 */

namespace AIChatbot\Security;

defined( 'ABSPATH' ) || exit;

/**
 * Sanitizer class.
 */
class Sanitizer {

	/**
	 * Strip newlines and null bytes to prevent email header injection.
	 *
	 * @param string $value Input string.
	 * @return string Sanitized header string.
	 */
	public static function sanitize_header( string $value ): string {
		return trim( str_replace( array( "\r", "\n", "%0a", "%0d", "\0" ), '', $value ) );
	}

	/**
	 * Scrub Personally Identifiable Information (PII) like emails and phone numbers.
	 * Used before logging questions in unanswered inbox and out-of-scope log.
	 *
	 * @param string $text Raw question or text.
	 * @return string Scrubbed text.
	 */
	public static function scrub_pii( string $text ): string {
		// Replace email addresses with [email].
		$text = (string) preg_replace( '/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/', '[email]', $text );

		// Replace phone numbers with [phone].
		$text = (string) preg_replace( '/(\+?\d{1,3}[-.\s]?)?(\(?\d{2,4}\)?[-.\s]?)?\d{3,4}[-.\s]?\d{3,4}/', '[phone]', $text );

		return trim( $text );
	}

	/**
	 * Check honeypot submission.
	 * If honeypot is filled, the request is marked as a bot.
	 *
	 * @param array $params Request parameters.
	 * @param string $field_name Honeypot field name.
	 * @return bool True if honeypot was triggered (bot detected), false if clean.
	 */
	public static function is_honeypot_triggered( array $params, string $field_name = 'website_hp' ): bool {
		return ! empty( $params[ $field_name ] );
	}

	/**
	 * Simple IP and session rate limiter using WordPress transients.
	 *
	 * @param string $action Action identifier (e.g. 'chat', 'contact').
	 * @param string $identifier IP address or session token.
	 * @param int    $max_requests Maximum allowed requests.
	 * @param int    $window_seconds Time window in seconds.
	 * @return bool True if allowed, false if limit exceeded.
	 */
	public static function check_rate_limit( string $action, string $identifier, int $max_requests, int $window_seconds ): bool {
		$key  = 'ai_rl_' . md5( $action . '_' . $identifier );
		$data = get_transient( $key );
		$now  = time();

		if ( ! is_array( $data ) || empty( $data['expires_at'] ) || $data['expires_at'] <= $now ) {
			set_transient( $key, array( 'count' => 1, 'expires_at' => $now + $window_seconds ), $window_seconds );
			return true;
		}

		if ( (int) $data['count'] >= $max_requests ) {
			return false;
		}

		$data['count'] = (int) $data['count'] + 1;
		$remaining     = max( 1, $data['expires_at'] - $now );
		set_transient( $key, $data, $remaining );

		return true;
	}

	/**
	 * Get visitor IP address safely.
	 *
	 * @return string IP address.
	 */
	public static function get_client_ip(): string {
		if ( ! empty( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ) {
			return sanitize_text_field( wp_unslash( $_SERVER['HTTP_CF_CONNECTING_IP'] ) );
		}
		if ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
			$ips = explode( ',', sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) );
			return trim( $ips[0] );
		}
		return ! empty( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '127.0.0.1';
	}
}
