<?php
/**
 * Deprecated Resend Email Provider implementation.
 *
 * All email deliveries have transitioned to native WordPress wp_mail().
 * This class is maintained solely for backward compatibility.
 *
 * @package AIChatbot\Email
 */

namespace AIChatbot\Email;

defined( 'ABSPATH' ) || exit;

/**
 * Class ResendProvider.
 *
 * @deprecated 1.11.0 Replaced by native WpMailProvider.
 */
class ResendProvider implements EmailProviderInterface {

	/**
	 * Default from email.
	 */
	protected string $from_email;

	/**
	 * Default from name.
	 */
	protected string $from_name;

	/**
	 * Constructor.
	 *
	 * @param string $api_key Obsolete API key.
	 * @param string $from_email From address.
	 * @param string $from_name From name.
	 */
	public function __construct( string $api_key = '', string $from_email = '', string $from_name = '' ) {
		$this->from_email = $from_email ?: (string) get_option( 'admin_email' );
		$this->from_name  = $from_name ?: (string) get_bloginfo( 'name' );
	}

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
		return 'WordPress Mail (Native)';
	}

	/**
	 * Send an email via native WordPress mailer.
	 *
	 * @param string      $to Recipient.
	 * @param string      $subject Subject.
	 * @param string      $html Body HTML.
	 * @param array       $headers Custom headers.
	 * @param ?string     $idempotency_key Idempotency key.
	 * @return array
	 */
	public function send( string $to, string $subject, string $html, array $headers = array(), ?string $idempotency_key = null ): array {
		$native = new WpMailProvider();
		return $native->send( $to, $subject, $html, $headers, $idempotency_key );
	}

	/**
	 * Test connection (always succeeds using WordPress native mail).
	 *
	 * @param string $api_key Obsolete key.
	 * @param string $from_email From email.
	 * @return array
	 */
	public function test_connection( string $api_key = '', string $from_email = '' ): array {
		return array(
			'success' => true,
			'message' => __( 'WordPress default mailer is ready.', 'ai-chatbot' ),
		);
	}
}

