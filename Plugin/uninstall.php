<?php
/**
 * Uninstallation cleanup script.
 * Fired when the plugin is deleted via the WordPress admin plugins screen.
 *
 * @package AIChatbot
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

// Check if admin opted to delete all data on uninstall.
$table_settings = $wpdb->prefix . 'ai_settings';
$delete_data    = false;

if ( $wpdb->get_var( "SHOW TABLES LIKE '{$table_settings}'" ) === $table_settings ) {
	$val = $wpdb->get_var(
		$wpdb->prepare(
			"SELECT setting_value FROM {$table_settings} WHERE setting_group = %s AND setting_key = %s",
			'session',
			'delete_data_on_uninstall'
		)
	);
	$delete_data = ( '1' === $val || 'true' === $val );
}

if ( $delete_data ) {
	// Drop all custom tables.
	$tables = array(
		$wpdb->prefix . 'ai_agents',
		$wpdb->prefix . 'ai_knowledge_sources',
		$wpdb->prefix . 'ai_knowledge_chunks',
		$wpdb->prefix . 'ai_conversations',
		$wpdb->prefix . 'ai_messages',
		$wpdb->prefix . 'ai_settings',
		$wpdb->prefix . 'ai_leads',
		$wpdb->prefix . 'ai_api_keys',
		$wpdb->prefix . 'ai_email_log',
		$wpdb->prefix . 'ai_unanswered_questions',
		$wpdb->prefix . 'ai_out_of_scope_log',
		$wpdb->prefix . 'ai_answer_overrides',
	);

	foreach ( $tables as $tbl ) {
		$wpdb->query( "DROP TABLE IF EXISTS {$tbl}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	// Remove options.
	delete_option( 'ai_chatbot_db_version' );

	// Clear scheduled cron events.
	wp_clear_scheduled_hook( 'ai_chatbot_daily_retention_cleanup' );
}
