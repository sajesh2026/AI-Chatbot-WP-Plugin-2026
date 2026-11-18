<?php
/**
 * WordPress Privacy Tools and Retention Policy.
 *
 * @package AIChatbot\Admin
 */

namespace AIChatbot\Admin;

use AIChatbot\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Class Privacy.
 */
class Privacy {

	/**
	 * Register WordPress hooks.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_filter( 'wp_privacy_personal_data_exporters', array( __CLASS__, 'register_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( __CLASS__, 'register_eraser' ) );
		add_action( 'ai_chatbot_daily_retention_cleanup', array( __CLASS__, 'cleanup_old_records' ) );

		if ( ! wp_next_scheduled( 'ai_chatbot_daily_retention_cleanup' ) ) {
			wp_schedule_event( time(), 'daily', 'ai_chatbot_daily_retention_cleanup' );
		}
	}

	/**
	 * Register privacy personal data exporter.
	 *
	 * @param array $exporters Exporters list.
	 * @return array
	 */
	public static function register_exporter( array $exporters ): array {
		$exporters['ai-chatbot'] = array(
			'exporter_friendly_name' => __( 'AI Chatbot Leads and Conversations', 'ai-chatbot' ),
			'callback'               => array( __CLASS__, 'export_personal_data' ),
		);
		return $exporters;
	}

	/**
	 * Export user personal data for email.
	 *
	 * @param string $email_address User email.
	 * @return array
	 */
	public static function export_personal_data( string $email_address ): array {
		global $wpdb;
		$table_leads = $wpdb->prefix . 'ai_leads';

		$leads = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table_leads} WHERE email = %s", $email_address ),
			ARRAY_A
		);

		$export_items = array();
		if ( ! empty( $leads ) ) {
			foreach ( $leads as $lead ) {
				$export_items[] = array(
					'group_id'    => 'ai_chatbot_leads',
					'group_label' => __( 'AI Chatbot Leads', 'ai-chatbot' ),
					'item_id'     => 'lead-' . $lead['id'],
					'data'        => array(
						array( 'name' => __( 'Name', 'ai-chatbot' ), 'value' => $lead['name'] ),
						array( 'name' => __( 'Email', 'ai-chatbot' ), 'value' => $lead['email'] ),
						array( 'name' => __( 'Phone', 'ai-chatbot' ), 'value' => $lead['phone'] ),
						array( 'name' => __( 'Company', 'ai-chatbot' ), 'value' => $lead['company'] ),
						array( 'name' => __( 'Message', 'ai-chatbot' ), 'value' => $lead['message'] ),
						array( 'name' => __( 'Date', 'ai-chatbot' ), 'value' => $lead['created_at'] ),
					),
				);
			}
		}

		return array(
			'data' => $export_items,
			'done' => true,
		);
	}

	/**
	 * Register privacy personal data eraser.
	 *
	 * @param array $erasers Erasers list.
	 * @return array
	 */
	public static function register_eraser( array $erasers ): array {
		$erasers['ai-chatbot'] = array(
			'eraser_friendly_name' => __( 'AI Chatbot Leads and Conversations', 'ai-chatbot' ),
			'callback'              => array( __CLASS__, 'erase_personal_data' ),
		);
		return $erasers;
	}

	/**
	 * Erase personal data for an email address.
	 *
	 * @param string $email_address Email address.
	 * @return array
	 */
	public static function erase_personal_data( string $email_address ): array {
		global $wpdb;
		$table_leads = $wpdb->prefix . 'ai_leads';

		$deleted = $wpdb->delete( $table_leads, array( 'email' => $email_address ), array( '%s' ) );

		return array(
			'items_removed'  => false !== $deleted && $deleted > 0,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => true,
		);
	}

	/**
	 * Scheduled retention cleanup: deletes conversations and messages older than configured retention days.
	 *
	 * @return void
	 */
	public static function cleanup_old_records(): void {
		global $wpdb;

		$settings = Settings::get_all( 1, false );
		$days     = (int) ( $settings['session']['retention_days'] ?? 30 );

		if ( 0 === $days ) {
			return; // Retention set to "Never delete"
		}

		$table_conversations = $wpdb->prefix . 'ai_conversations';
		$table_messages      = $wpdb->prefix . 'ai_messages';

		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );

		// Delete old messages.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE m FROM {$table_messages} m
				 INNER JOIN {$table_conversations} c ON m.conversation_id = c.id
				 WHERE c.created_at < %s",
				$cutoff
			)
		);

		// Delete old conversations.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table_conversations} WHERE created_at < %s",
				$cutoff
			)
		);
	}
}
