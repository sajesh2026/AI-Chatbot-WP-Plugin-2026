<?php
/**
 * Database schema and migration management.
 *
 * @package AIChatbot\Database
 */

namespace AIChatbot\Database;

defined( 'ABSPATH' ) || exit;

/**
 * Class Schema.
 */
class Schema {

	/**
	 * Table name (without prefix) holding the manual-override audit log.
	 */
	public const OVERRIDE_LOG_TABLE = 'ai_answer_overrides';

	/**
	 * Table name (without prefix) holding enriched feedback events.
	 */
	public const FEEDBACK_TABLE = 'ai_chatbot_feedback';

	/**
	 * Create tables and run schema updates via dbDelta.
	 *
	 * @return void
	 */
	public static function create_tables(): void {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		// 1. Agents table.
		$table_agents = $wpdb->prefix . 'ai_agents';
		$sql_agents   = "CREATE TABLE {$table_agents} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			name varchar(255) NOT NULL DEFAULT 'AI Assistant',
			status varchar(50) NOT NULL DEFAULT 'online',
			custom_status_text varchar(255) DEFAULT NULL,
			role text NOT NULL,
			tone varchar(50) NOT NULL DEFAULT 'friendly',
			custom_tone text DEFAULT NULL,
			answer_length varchar(50) NOT NULL DEFAULT 'medium',
			custom_answer_length text DEFAULT NULL,
			greeting text NOT NULL,
			suggested_questions longtext DEFAULT NULL,
			max_questions_desktop int(11) NOT NULL DEFAULT 4,
			max_questions_mobile int(11) NOT NULL DEFAULT 2,
			show_on_welcome tinyint(1) NOT NULL DEFAULT 1,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id)
		) {$charset_collate};";
		dbDelta( $sql_agents );

		// 2. Knowledge sources table.
		$table_sources = $wpdb->prefix . 'ai_knowledge_sources';
		$sql_sources   = "CREATE TABLE {$table_sources} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			agent_id bigint(20) unsigned NOT NULL DEFAULT 1,
			type varchar(50) NOT NULL,
			title varchar(255) NOT NULL,
			source_url text DEFAULT NULL,
			content longtext DEFAULT NULL,
			status varchar(50) NOT NULL DEFAULT 'pending',
			error_message text DEFAULT NULL,
			chunk_count int(11) NOT NULL DEFAULT 0,
			metadata longtext DEFAULT NULL,
			last_indexed_at datetime DEFAULT NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY agent_type (agent_id, type),
			KEY status (status)
		) {$charset_collate};";
		dbDelta( $sql_sources );

		// 3. Knowledge chunks table (Vector storage).
		$table_chunks = $wpdb->prefix . 'ai_knowledge_chunks';
		$sql_chunks   = "CREATE TABLE {$table_chunks} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			source_id bigint(20) unsigned NOT NULL,
			agent_id bigint(20) unsigned NOT NULL DEFAULT 1,
			chunk_index int(11) NOT NULL DEFAULT 0,
			chunk_text longtext NOT NULL,
			token_count int(11) NOT NULL DEFAULT 0,
			embedding longtext NOT NULL,
			source_type varchar(50) NOT NULL,
			category varchar(100) DEFAULT NULL,
			language varchar(10) DEFAULT 'en',
			metadata longtext DEFAULT NULL,
			is_enabled tinyint(1) NOT NULL DEFAULT 1,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY source_id (source_id),
			KEY agent_enabled (agent_id, is_enabled),
			KEY source_type (source_type)
		) {$charset_collate};";
		dbDelta( $sql_chunks );

		// 4. Conversations table.
		$table_conversations = $wpdb->prefix . 'ai_conversations';
		$sql_conversations   = "CREATE TABLE {$table_conversations} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			agent_id bigint(20) unsigned NOT NULL DEFAULT 1,
			session_id varchar(128) NOT NULL,
			visitor_id varchar(128) NOT NULL,
			off_topic_count int(11) NOT NULL DEFAULT 0,
			is_throttled tinyint(1) NOT NULL DEFAULT 0,
			throttled_until datetime DEFAULT NULL,
			metadata longtext DEFAULT NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY session_id (session_id),
			KEY agent_created (agent_id, created_at)
		) {$charset_collate};";
		dbDelta( $sql_conversations );

		// 5. Messages table.
		$table_messages = $wpdb->prefix . 'ai_messages';
		$sql_messages   = "CREATE TABLE {$table_messages} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			conversation_id bigint(20) unsigned NOT NULL,
			role varchar(20) NOT NULL,
			message longtext NOT NULL,
			tokens int(11) NOT NULL DEFAULT 0,
			feedback varchar(20) DEFAULT NULL,
			is_edited tinyint(1) NOT NULL DEFAULT 0,
			edited_at datetime DEFAULT NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY conversation_role (conversation_id, role)
		) {$charset_collate};";
		dbDelta( $sql_messages );

		// 6. Settings table.
		$table_settings = $wpdb->prefix . 'ai_settings';
		$sql_settings   = "CREATE TABLE {$table_settings} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			agent_id bigint(20) unsigned NOT NULL DEFAULT 1,
			setting_group varchar(50) NOT NULL,
			setting_key varchar(100) NOT NULL,
			setting_value longtext DEFAULT NULL,
			updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY agent_group_key (agent_id, setting_group, setting_key)
		) {$charset_collate};";
		dbDelta( $sql_settings );

		// 7. Leads table.
		$table_leads = $wpdb->prefix . 'ai_leads';
		$sql_leads   = "CREATE TABLE {$table_leads} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			agent_id bigint(20) unsigned NOT NULL DEFAULT 1,
			conversation_id bigint(20) unsigned DEFAULT NULL,
			name varchar(255) NOT NULL,
			email varchar(255) NOT NULL,
			phone varchar(50) DEFAULT NULL,
			company varchar(255) DEFAULT NULL,
			message text NOT NULL,
			status varchar(50) NOT NULL DEFAULT 'new',
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY agent_status (agent_id, status)
		) {$charset_collate};";
		dbDelta( $sql_leads );

		// 8. API Keys table.
		$table_keys = $wpdb->prefix . 'ai_api_keys';
		$sql_keys   = "CREATE TABLE {$table_keys} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			provider varchar(50) NOT NULL,
			label varchar(255) NOT NULL,
			encrypted_key text NOT NULL,
			key_last_four varchar(8) NOT NULL,
			priority int(11) NOT NULL DEFAULT 0,
			status varchar(50) NOT NULL DEFAULT 'active',
			cooldown_until datetime DEFAULT NULL,
			last_error text DEFAULT NULL,
			requests_count bigint(20) unsigned NOT NULL DEFAULT 0,
			rate_limit_hits bigint(20) unsigned NOT NULL DEFAULT 0,
			last_used_at datetime DEFAULT NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY provider_status_priority (provider, status, priority)
		) {$charset_collate};";
		dbDelta( $sql_keys );

		// 9. Email log table.
		$table_email_log = $wpdb->prefix . 'ai_email_log';
		$sql_email_log   = "CREATE TABLE {$table_email_log} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			provider varchar(50) NOT NULL,
			idempotency_key varchar(255) NOT NULL,
			recipient varchar(255) NOT NULL,
			subject varchar(255) NOT NULL,
			status varchar(50) NOT NULL DEFAULT 'queued',
			retry_count int(11) NOT NULL DEFAULT 0,
			last_error text DEFAULT NULL,
			sent_at datetime DEFAULT NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY idempotency_key (idempotency_key),
			KEY status (status)
		) {$charset_collate};";
		dbDelta( $sql_email_log );

		// 10. Unanswered questions table.
		$table_unanswered = $wpdb->prefix . 'ai_unanswered_questions';
		$sql_unanswered   = "CREATE TABLE {$table_unanswered} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			agent_id bigint(20) unsigned NOT NULL DEFAULT 1,
			question text NOT NULL,
			normalized_hash varchar(64) NOT NULL,
			embedding longtext DEFAULT NULL,
			occurrences int(11) NOT NULL DEFAULT 1,
			status varchar(50) NOT NULL DEFAULT 'new',
			draft_answer text DEFAULT NULL,
			resolved_qa_id bigint(20) unsigned DEFAULT NULL,
			last_asked_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY agent_status (agent_id, status),
			KEY normalized_hash (normalized_hash)
		) {$charset_collate};";
		dbDelta( $sql_unanswered );

		// 11. Out of scope log table.
		$table_out_of_scope = $wpdb->prefix . 'ai_out_of_scope_log';
		$sql_out_of_scope   = "CREATE TABLE {$table_out_of_scope} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			agent_id bigint(20) unsigned NOT NULL DEFAULT 1,
			question text NOT NULL,
			normalized_hash varchar(64) NOT NULL,
			occurrences int(11) NOT NULL DEFAULT 1,
			last_asked_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY agent_hash (agent_id, normalized_hash)
		) {$charset_collate};";
		dbDelta( $sql_out_of_scope );

		// 12. Enriched feedback table.
		$table_feedback = $wpdb->prefix . self::FEEDBACK_TABLE;
		$sql_feedback   = "CREATE TABLE {$table_feedback} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			message_id bigint(20) unsigned NOT NULL,
			session_id varchar(64) NOT NULL,
			user_id bigint(20) DEFAULT NULL,
			agent_id bigint(20) unsigned NOT NULL DEFAULT 1,
			rating enum('helpful','unhelpful') NOT NULL,
			response_time_ms int(10) unsigned DEFAULT NULL,
			follow_up_count tinyint(4) unsigned NOT NULL DEFAULT 0,
			escalated_to_human tinyint(1) NOT NULL DEFAULT 0,
			retry_count tinyint(4) unsigned NOT NULL DEFAULT 0,
			user_correction text DEFAULT NULL,
			query_text text NOT NULL,
			response_text longtext NOT NULL,
			retrieved_chunks json DEFAULT NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY idx_message_id (message_id),
			KEY idx_session (session_id),
			KEY idx_agent_rating (agent_id, rating, created_at),
			KEY idx_created (created_at)
		) {$charset_collate};";
		dbDelta( $sql_feedback );

		// 13. Verified links registry table.
		self::create_link_registry_table();

		// Seed initial default agent and settings if absent.
		self::seed_defaults();

		// Record current schema version.
		update_option( 'ai_chatbot_db_version', AI_CHATBOT_DB_VERSION );
	}

	/**
	 * Create verified links registry table.
	 *
	 * @return void
	 */
	public static function create_link_registry_table(): void {
		global $wpdb;
		$charset_collate = $wpdb->get_charset_collate();
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table_links = $wpdb->prefix . 'ai_verified_links';
		$sql_links   = "CREATE TABLE {$table_links} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			url varchar(500) NOT NULL,
			canonical_url varchar(500) NOT NULL,
			title varchar(255) NOT NULL DEFAULT '',
			language varchar(10) NOT NULL DEFAULT 'de',
			http_status int(11) NOT NULL DEFAULT 200,
			last_verified datetime DEFAULT NULL,
			fail_count int(11) NOT NULL DEFAULT 0,
			content_hash varchar(64) DEFAULT NULL,
			source varchar(50) NOT NULL DEFAULT 'sitemap',
			summary text DEFAULT NULL,
			keywords text DEFAULT NULL,
			lastmod varchar(50) DEFAULT NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY canonical_url (canonical_url(191)),
			KEY url (url(191)),
			KEY language (language),
			KEY source (source)
		) {$charset_collate};";
		dbDelta( $sql_links );
	}

	/**
	 * Add the manual-override columns to the two review tables.
	 *
	 * Kept separate from create_tables() because that method only runs on
	 * activation, while this one runs from the migrator so an update to an
	 * existing site adds the columns without a deactivate/reactivate cycle.
	 * dbDelta performs the ALTER for any column the live table is missing.
	 *
	 * @return void
	 */
	public static function create_override_columns(): void {
		global $wpdb;

		$table_unanswered = $wpdb->prefix . 'ai_unanswered_questions';
		$table_scope      = $wpdb->prefix . 'ai_out_of_scope_log';

		/*
		 * Targeted ADD COLUMN statements rather than a dbDelta CREATE TABLE.
		 *
		 * dbDelta also reconciles indexes, and on an existing table it re-issues
		 * "ADD KEY agent_status" even though the index is already there, which
		 * fails with "Duplicate key name" and logs a database error on every
		 * upgrade. This migration only ever adds columns, so it only ever issues
		 * ADD COLUMN, and skips the ones already present.
		 */
		$columns = array(
			$table_unanswered => array(
				'ai_draft_answer'  => 'text DEFAULT NULL',
				'draft_source'     => "varchar(20) NOT NULL DEFAULT 'none'",
				'draft_edited_at'  => 'datetime DEFAULT NULL',
				'draft_edited_by'  => 'bigint(20) unsigned DEFAULT NULL',
				'edit_count'       => 'int(11) NOT NULL DEFAULT 0',
			),
			$table_scope      => array(
				'status'           => "varchar(50) NOT NULL DEFAULT 'pending'",
				'draft_answer'     => 'text DEFAULT NULL',
				'ai_draft_answer'  => 'text DEFAULT NULL',
				'draft_source'     => "varchar(20) NOT NULL DEFAULT 'none'",
				'draft_edited_at'  => 'datetime DEFAULT NULL',
				'draft_edited_by'  => 'bigint(20) unsigned DEFAULT NULL',
				'edit_count'       => 'int(11) NOT NULL DEFAULT 0',
				'resolved_qa_id'   => 'bigint(20) unsigned DEFAULT NULL',
				'updated_at'       => 'datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
			),
		);

		foreach ( $columns as $table => $defs ) {
			if ( ! self::table_exists( $table ) ) {
				continue;
			}

			$existing = $wpdb->get_col( "SHOW COLUMNS FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$existing = is_array( $existing ) ? $existing : array();

			foreach ( $defs as $column => $definition ) {
				if ( in_array( $column, $existing, true ) ) {
					continue;
				}

				$wpdb->query( "ALTER TABLE {$table} ADD COLUMN {$column} {$definition}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			}
		}

		/*
		 * The status index lets the Scope Tuning screen filter by ruling without
		 * a full scan. Added only if absent, since the columns above may already
		 * have arrived via a fresh activation.
		 */
		if ( self::table_exists( $table_scope ) && ! self::index_exists( $table_scope, 'status' ) ) {
			$wpdb->query( "ALTER TABLE {$table_scope} ADD KEY status (status)" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}

		/*
		 * Backfill draft_source for rows that already carry a draft. Those drafts
		 * were all written by generate_draft_answer(), and without this the admin
		 * UI would label them "untouched" and offer a Revert button that has
		 * nothing to revert to.
		 */
		if ( self::table_exists( $table_unanswered ) ) {
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$table_unanswered} SET draft_source = %s WHERE draft_answer IS NOT NULL AND draft_source = %s",
					'ai',
					'none'
				)
			);
		}
	}

	/**
	 * Whether a plugin table is present.
	 *
	 * @param string $table Full table name including prefix.
	 * @return bool
	 */
	public static function table_exists( string $table ): bool {
		global $wpdb;

		return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
	}

	/**
	 * Whether an index exists on a table.
	 *
	 * @param string $table     Full table name including prefix.
	 * @param string $index_name Index name.
	 * @return bool
	 */
	public static function index_exists( string $table, string $index_name ): bool {
		global $wpdb;

		$found = $wpdb->get_var(
			$wpdb->prepare( 'SHOW INDEX FROM ' . $table . ' WHERE Key_name = %s', $index_name ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);

		return ! empty( $found );
	}

	/**
	 * Add the message-editing columns.
	 *
	 * is_edited / edited_at mark a visitor message the visitor later rewrote.
	 * Kept separate from a plain UPDATE so the transcript can show the edit,
	 * and so the reworded question is distinguishable from a first-time ask in
	 * analytics.
	 *
	 * @return void
	 */
	public static function create_message_edit_columns(): void {
		global $wpdb;

		$table = $wpdb->prefix . 'ai_messages';

		if ( ! self::table_exists( $table ) ) {
			return;
		}

		$columns = array(
			'is_edited' => 'tinyint(1) NOT NULL DEFAULT 0',
			'edited_at' => 'datetime DEFAULT NULL',
		);

		// Routed through add_column() so a table hit by the stale-row-format
		// rejection recovers instead of silently missing the columns.
		foreach ( $columns as $column => $definition ) {
			self::add_column( $table, $column, $definition );
		}
	}

	/**
	 * Add one column, recovering from a row-size rejection.
	 *
	 * Some MariaDB installs reject an ADD COLUMN on these tables with "Row size
	 * too large", even though the column definition is valid and a clone of the
	 * same definition accepts it. The table was created under an older row
	 * format and its on-disk metadata no longer matches what SHOW CREATE TABLE
	 * reports, so InnoDB computes the row budget from the stale format and
	 * refuses the change.
	 *
	 * Rebuilding the table with ROW_FORMAT=DYNAMIC rewrites the metadata and
	 * makes the identical ALTER succeed, which is what happens here. The rebuild
	 * is safe: InnoDB does it in place, so the data and the AUTO_INCREMENT
	 * counter are preserved.
	 *
	 * Only attempted when the first ALTER was actually rejected, so a table that
	 * accepts the change normally never pays for a rebuild.
	 *
	 * @param string $table      Full table name including prefix.
	 * @param string $column     Column name.
	 * @param string $definition Column definition.
	 * @return bool True when the column exists afterwards.
	 */
	protected static function add_column( string $table, string $column, string $definition ): bool {
		global $wpdb;

		$existing = $wpdb->get_col( "SHOW COLUMNS FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( in_array( $column, (array) $existing, true ) ) {
			return true;
		}

		$added = $wpdb->query( "ALTER TABLE {$table} ADD COLUMN {$column} {$definition}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( false !== $added ) {
			return true;
		}

		$error = (string) $wpdb->last_error;
		$wpdb->last_error = '';

		if ( ! str_contains( $error, 'Row size too large' ) ) {
			error_log( sprintf( '[AI Chatbot] Could not add column %s to %s: %s', $column, $table, $error ) );
			return false;
		}

		$wpdb->query( "ALTER TABLE {$table} ENGINE=InnoDB ROW_FORMAT=DYNAMIC" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$retry = $wpdb->query( "ALTER TABLE {$table} ADD COLUMN {$column} {$definition}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( false === $retry ) {
			error_log( sprintf( '[AI Chatbot] Could not add column %s to %s after a row-format rebuild: %s', $column, $table, (string) $wpdb->last_error ) );
			return false;
		}

		return true;
	}

	/**
	 * Create the manual-override audit log table.
	 *
	 * @return void
	 */
	public static function create_override_log_table(): void {
		global $wpdb;
		$charset_collate = $wpdb->get_charset_collate();

		$table = $wpdb->prefix . self::OVERRIDE_LOG_TABLE;
		$sql   = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			agent_id bigint(20) unsigned NOT NULL DEFAULT 1,
			source_type varchar(30) NOT NULL DEFAULT 'unanswered',
			source_id bigint(20) unsigned NOT NULL DEFAULT 0,
			action varchar(30) NOT NULL DEFAULT 'edit',
			question text NOT NULL,
			ai_draft text DEFAULT NULL,
			edited_answer text DEFAULT NULL,
			ai_similarity decimal(5,4) DEFAULT NULL,
			question_similarity decimal(5,4) DEFAULT NULL,
			resolved_qa_id bigint(20) unsigned DEFAULT NULL,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY agent_created (agent_id, created_at),
			KEY source (source_type, source_id),
			KEY action (action)
		) {$charset_collate};";
		dbDelta( $sql );
	}

	/**
	 * Seed initial default agent and configuration.
	 *
	 * @return void
	 */
	public static function seed_defaults(): void {
		global $wpdb;

		$table_agents = $wpdb->prefix . 'ai_agents';
		$existing     = $wpdb->get_var( "SELECT COUNT(*) FROM {$table_agents}" );

		if ( 0 === (int) $existing ) {
			$starter_questions = array(
				array(
					'id'         => 'q1',
					'text'       => 'What services do you offer?',
					'icon'       => 'briefcase',
					'enabled'    => true,
					'sort_order' => 0,
				),
				array(
					'id'         => 'q2',
					'text'       => 'How can I contact your team?',
					'icon'       => 'envelope',
					'enabled'    => true,
					'sort_order' => 1,
				),
				array(
					'id'         => 'q3',
					'text'       => 'Where are you located?',
					'icon'       => 'location',
					'enabled'    => true,
					'sort_order' => 2,
				),
				array(
					'id'         => 'q4',
					'text'       => 'What are your business hours?',
					'icon'       => 'clock',
					'enabled'    => true,
					'sort_order' => 3,
				),
			);

			$site_name = get_bloginfo( 'name' );
			$wpdb->insert(
				$table_agents,
				array(
					'id'                   => 1,
					'name'                 => $site_name ? $site_name . ' Assistant' : 'AI Assistant',
					'status'               => 'online',
					'custom_status_text'   => 'Available 24/7',
					'role'                 => 'You are a helpful, professional AI assistant representing ' . ( $site_name ?: 'our business' ) . '. You answer visitor inquiries using only verified knowledge, explain our services, and politely offer human contact when needed.',
					'tone'                 => 'friendly',
					'answer_length'        => 'medium',
					'greeting'             => 'Hello! Welcome to {{company_name}}. I am {{agent_name}}. How can I assist you today?',
					'suggested_questions'  => wp_json_encode( $starter_questions ),
					'max_questions_desktop'=> 4,
					'max_questions_mobile' => 2,
					'show_on_welcome'      => 1,
				)
			);
		}

		// Seed initial business info source if not present.
		$table_sources = $wpdb->prefix . 'ai_knowledge_sources';
		$has_biz_info  = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table_sources} WHERE agent_id = 1 AND type = %s",
				'business_info'
			)
		);

		if ( 0 === (int) $has_biz_info ) {
			$initial_biz = array(
				'business_name'  => get_bloginfo( 'name' ),
				'description'    => get_bloginfo( 'description' ),
				'industry'       => 'General Business',
				'website'        => home_url(),
				'email'          => get_option( 'admin_email' ),
				'phone'          => '',
				'address'        => '',
				'country'        => '',
				'city'           => '',
				'business_hours' => 'Mon-Fri 9:00 AM - 5:00 PM',
				'services'       => '',
				'products'       => '',
				'social_media'   => '',
				'contact_url'    => class_exists( '\AIChatbot\Rest\ChatController' ) ? \AIChatbot\Rest\ChatController::resolve_site_contact_url() : '',
				'booking_url'    => '',
				'support_url'    => '',
			);

			$wpdb->insert(
				$table_sources,
				array(
					'agent_id'   => 1,
					'type'       => 'business_info',
					'title'      => 'Business Profile & Contact Facts',
					'content'    => wp_json_encode( $initial_biz ),
					'status'     => 'indexed',
					'metadata'   => wp_json_encode( array( 'version' => 1 ) ),
				)
			);
		}
	}
}
