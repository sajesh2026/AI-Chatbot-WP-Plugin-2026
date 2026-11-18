<?php
/**
 * WordPress native wp_mail fallback provider.
 *
 * @package AIChatbot\Email
 */

namespace AIChatbot\Email;

use AIChatbot\Security\Sanitizer;

defined( 'ABSPATH' ) || exit;

/**
 * Class WpMailProvider.
 */
class WpMailProvider implements EmailProviderInterface {

	/**
	 * Provider slug.
	 *
	 * @return string
	 */
	public function get_id(): string {
		return 'wp_mail';
	}

	/**
	 * Provider display name.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return 'WordPress Mail (Default)';
	}

	/**
	 * Send email via wp_mail.
	 *
	 * @param string      $to Recipient.
	 * @param string      $subject Subject.
	 * @param string      $html Body HTML.
	 * @param array       $headers Custom headers.
	 * @param ?string     $idempotency_key Key.
	 * @return array
	 */
	public function send( string $to, string $subject, string $html, array $headers = array(), ?string $idempotency_key = null ): array {
		$clean_to = sanitize_email( $to );
		if ( ! is_email( $clean_to ) ) {
			return array(
				'success' => false,
				'error'   => __( 'Invalid recipient email address.', 'ai-chatbot' ),
			);
		}

		$mail_headers = array( 'Content-Type: text/html; charset=UTF-8' );

		if ( ! empty( $headers['from'] ) ) {
			$mail_headers[] = 'From: ' . Sanitizer::sanitize_header( $headers['from'] );
		}
		if ( ! empty( $headers['reply_to'] ) ) {
			$mail_headers[] = 'Reply-To: ' . Sanitizer::sanitize_header( $headers['reply_to'] );
		}

		$error_message = '';
		$capture_fail  = function( $wp_error ) use ( &$error_message ) {
			if ( is_wp_error( $wp_error ) ) {
				$error_message = $wp_error->get_error_message();
			}
		};
		add_action( 'wp_mail_failed', $capture_fail, 10, 1 );

		$sent = wp_mail(
			$clean_to,
			Sanitizer::sanitize_header( $subject ),
			$html,
			$mail_headers
		);

		remove_action( 'wp_mail_failed', $capture_fail, 10 );

		if ( ! $sent ) {
			return array(
				'success' => false,
				'error'   => ! empty( $error_message )
					? $error_message
					: __( 'WordPress wp_mail function failed to deliver email.', 'ai-chatbot' ),
			);
		}

		return array(
			'success' => true,
		);
	}

	/**
	 * Test connection.
	 *
	 * @param string $api_key Key (unused for wp_mail).
	 * @param string $from_email From address (optional).
	 * @return array
	 */
	public function test_connection( string $api_key = '', string $from_email = '' ): array {
		return array(
			'success' => true,
			'message' => __( 'WordPress default mailer is ready.', 'ai-chatbot' ),
		);
	}
}
