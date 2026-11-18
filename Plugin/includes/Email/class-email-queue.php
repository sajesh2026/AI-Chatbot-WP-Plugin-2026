<?php
/**
 * Asynchronous Email Queue and Delivery Manager.
 *
 * @package AIChatbot\Email
 */

namespace AIChatbot\Email;

use AIChatbot\Security\Encryption;

defined( 'ABSPATH' ) || exit;

/**
 * Class EmailQueue.
 */
class EmailQueue {

	/**
	 * Table name without prefix.
	 */
	public const TABLE_NAME = 'ai_email_log';

	/**
	 * Maximum retry attempts before marking permanently failed.
	 */
	public const MAX_RETRIES = 3;

	/**
	 * Queue an email for background delivery.
	 * Enforces idempotency to guarantee no duplicate sends.
	 *
	 * @param string  $recipient To address.
	 * @param string  $subject Subject line.
	 * @param string  $html Body HTML.
	 * @param array   $headers Headers (reply_to, from, etc.).
	 * @param string  $idempotency_key Unique key (e.g. 'lead-notification/123').
	 * @param bool    $send_immediately Whether to attempt immediate delivery.
	 * @return array{queued: bool, already_sent: bool, log_id: int}
	 */
	public static function queue(
		string $recipient,
		string $subject,
		string $html,
		array $headers = array(),
		string $idempotency_key = '',
		bool $send_immediately = true
	): array {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE_NAME;

		if ( empty( $idempotency_key ) ) {
			$idempotency_key = 'email_' . md5( $recipient . $subject . microtime( true ) );
		}

		// Check if this idempotency key already exists.
		$existing = $wpdb->get_row(
			$wpdb->prepare( "SELECT id, status FROM {$table} WHERE idempotency_key = %s", $idempotency_key ),
			ARRAY_A
		);

		if ( $existing ) {
			if ( 'sent' === $existing['status'] ) {
				return array(
					'queued'       => false,
					'already_sent' => true,
					'log_id'       => (int) $existing['id'],
				);
			}
			$log_id = (int) $existing['id'];
		} else {
			$settings    = self::get_email_settings();
			$provider_id = 'wp_mail';

			$inserted = $wpdb->insert(
				$table,
				array(
					'provider'        => $provider_id,
					'idempotency_key' => $idempotency_key,
					'recipient'       => $recipient,
					'subject'         => $subject,
					'status'          => 'queued',
					'retry_count'     => 0,
					'created_at'      => current_time( 'mysql' ),
				),
				array( '%s', '%s', '%s', '%s', '%s', '%d', '%s' )
			);

			$log_id = $inserted ? (int) $wpdb->insert_id : 0;
		}

		// Store payload transient for background delivery.
		set_transient(
			'ai_email_payload_' . $log_id,
			array(
				'html'            => $html,
				'headers'         => $headers,
				'idempotency_key' => $idempotency_key,
			),
			DAY_IN_SECONDS
		);

		if ( $send_immediately && $log_id > 0 ) {
			self::send_single( $log_id );
		}

		return array(
			'queued'       => true,
			'already_sent' => false,
			'log_id'       => $log_id,
		);
	}

	/**
	 * Send a single queued email.
	 *
	 * @param int $log_id Email log record ID.
	 * @return bool
	 */
	public static function send_single( int $log_id ): bool {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE_NAME;

		$log = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $log_id ), ARRAY_A );
		if ( ! $log || 'sent' === $log['status'] ) {
			return true;
		}

		$payload = get_transient( 'ai_email_payload_' . $log_id );
		if ( ! is_array( $payload ) ) {
			return false;
		}

		$settings = self::get_email_settings();
		$provider = self::get_provider_instance( 'wp_mail', $settings );

		$res = $provider->send(
			$log['recipient'],
			$log['subject'],
			$payload['html'] ?? '',
			$payload['headers'] ?? array(),
			$log['idempotency_key']
		);

		if ( $res['success'] ) {
			$wpdb->update(
				$table,
				array(
					'provider'   => 'wp_mail',
					'status'     => 'sent',
					'last_error' => null,
					'sent_at'    => current_time( 'mysql' ),
				),
				array( 'id' => $log_id ),
				array( '%s', '%s', '%s', '%s' ),
				array( '%d' )
			);
			delete_transient( 'ai_email_payload_' . $log_id );
			return true;
		}

		// Update failure and retry count.
		$retry_count = (int) $log['retry_count'] + 1;
		$status      = $retry_count >= self::MAX_RETRIES ? 'failed' : 'queued';

		$wpdb->update(
			$table,
			array(
				'provider'    => 'wp_mail',
				'status'      => $status,
				'retry_count' => $retry_count,
				'last_error'  => $res['error'] ?? __( 'WordPress wp_mail delivery failure.', 'ai-chatbot' ),
			),
			array( 'id' => $log_id ),
			array( '%s', '%s', '%d', '%s' ),
			array( '%d' )
		);

		return false;
	}

	/**
	 * Get configured email provider instance.
	 *
	 * Native WordPress wp_mail is always used.
	 *
	 * @param string $method Provider method (unused).
	 * @param array  $settings Settings.
	 * @return EmailProviderInterface
	 */
	public static function get_provider_instance( string $method = 'wp_mail', array $settings = array() ): EmailProviderInterface {
		return new WpMailProvider();
	}

	/**
	 * Get stored email notification settings.
	 *
	 * @return array
	 */
	public static function get_email_settings(): array {
		global $wpdb;
		$table = $wpdb->prefix . 'ai_settings';

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT setting_key, setting_value FROM {$table} WHERE setting_group = %s AND agent_id = 1",
				'email'
			),
			ARRAY_A
		);

		$admin_email = (string) get_option( 'admin_email' );

		$settings = array(
			'enabled'         => true,
			'delivery_method' => 'wp_mail',
			'recipient_type'  => 'admin',
			'custom_email'    => '',
			'notification_to' => $admin_email,
			'admin_email'     => $admin_email,
			'from_email'      => $admin_email,
			'from_name'       => get_bloginfo( 'name' ),
			'notify_on_lead'  => true,
			'notify_on_fail'  => true,
		);

		if ( ! empty( $rows ) ) {
			foreach ( $rows as $r ) {
				$val = $r['setting_value'];
				if ( in_array( $val, array( '1', '0', 'true', 'false' ), true ) ) {
					$settings[ $r['setting_key'] ] = in_array( $val, array( '1', 'true' ), true );
				} else {
					$settings[ $r['setting_key'] ] = $val;
				}
			}
		}

		$settings['delivery_method'] = 'wp_mail';
		$settings['admin_email']     = $admin_email;

		// Backward-compatibility: if an existing installation had a custom notification_to that differed from admin_email
		if ( empty( $settings['recipient_type'] ) ) {
			if ( ! empty( $settings['notification_to'] ) && $settings['notification_to'] !== $admin_email && is_email( $settings['notification_to'] ) ) {
				$settings['recipient_type'] = 'custom';
				$settings['custom_email']   = $settings['notification_to'];
			} else {
				$settings['recipient_type'] = 'admin';
				$settings['custom_email']   = '';
			}
		}

		if ( 'custom' === ( $settings['recipient_type'] ?? 'admin' ) && ! empty( $settings['custom_email'] ) && is_email( $settings['custom_email'] ) ) {
			$settings['notification_to'] = sanitize_email( $settings['custom_email'] );
		} else {
			$settings['recipient_type']  = 'admin';
			$settings['notification_to'] = $admin_email;
		}

		return $settings;
	}

	/**
	 * Fetch recent email logs.
	 *
	 * @param int $limit Max rows.
	 * @return array
	 */
	public static function get_logs( int $limit = 50 ): array {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE_NAME;

		return $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d", $limit ),
			ARRAY_A
		) ?: array();
	}
}
