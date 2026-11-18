<?php
/**
 * Email Provider Interface.
 *
 * @package AIChatbot\Email
 */

namespace AIChatbot\Email;

defined( 'ABSPATH' ) || exit;

/**
 * Interface EmailProviderInterface.
 */
interface EmailProviderInterface {

	/**
	 * Unique provider ID.
	 *
	 * @return string (e.g. 'wp_mail').
	 */
	public function get_id(): string;

	/**
	 * Provider display name.
	 *
	 * @return string Display name.
	 */
	public function get_name(): string;

	/**
	 * Send an email message.
	 *
	 * @param string      $to Recipient email.
	 * @param string      $subject Email subject line.
	 * @param string      $html HTML email body content.
	 * @param array       $headers Additional email headers (From, Reply-To, etc.).
	 * @param ?string     $idempotency_key Optional unique idempotency key to prevent duplicates.
	 * @return array{success: bool, id?: string, error?: string, status_code?: int}
	 */
	public function send( string $to, string $subject, string $html, array $headers = array(), ?string $idempotency_key = null ): array;

	/**
	 * Test provider connection and from-address domain verification.
	 *
	 * @param string $api_key Plaintext API key.
	 * @param string $from_email From email address.
	 * @return array{success: bool, message: string}
	 */
	public function test_connection( string $api_key, string $from_email ): array;
}
