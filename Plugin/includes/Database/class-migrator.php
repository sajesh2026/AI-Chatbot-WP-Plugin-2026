<?php
/**
 * Database Schema Migrator — Handles version-gated schema migrations and seed data.
 *
 * @package AIChatbot\Database
 */

namespace AIChatbot\Database;

defined( 'ABSPATH' ) || exit;

/**
 * Class Migrator.
 *
 * Runs schema migrations when the stored DB version is behind the
 * current plugin version, and seeds default data on first activation.
 */
class Migrator {

	/**
	 * Option key storing the installed DB version.
	 */
	const VERSION_OPTION = 'ai_chatbot_db_version';

	/**
	 * Run pending migrations.
	 *
	 * Called on every plugin load after tables are confirmed to exist,
	 * so upgrades happen automatically on plugin update.
	 *
	 * @return void
	 */
	public static function run(): void {
		$installed = get_option( self::VERSION_OPTION, '0.0.0' );
		$target    = AI_CHATBOT_DB_VERSION;

		if ( version_compare( $installed, $target, '>=' ) ) {
			return; // Nothing to do.
		}

		// Run ordered migrations.
		self::migrate_to_1_0_0( $installed );
		self::migrate_to_1_1_0( $installed );
		self::migrate_to_1_2_0( $installed );
		self::migrate_to_1_3_0( $installed );
		self::migrate_to_1_4_0( $installed );

		update_option( self::VERSION_OPTION, $target );
	}

	/**
	 * Seed the default agent row on first activation.
	 *
	 * @return void
	 */
	public static function seed_defaults(): void {
		global $wpdb;

		$table = $wpdb->prefix . 'ai_agents';

		// Only seed if no agent exists yet.
		$exists = $wpdb->get_var( "SELECT id FROM {$table} LIMIT 1" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( $exists ) {
			return;
		}

		$wpdb->insert(
			$table,
			array(
				'name'              => 'AI Assistant',
				'status'            => 'online',
				'role'              => 'I am a helpful AI assistant for this website. My goal is to answer visitor questions accurately and guide them to the right resources.',
				'tone'              => 'friendly',
				'answer_length'     => 'medium',
				'greeting'          => 'Hi there! 👋 I\'m your AI assistant. How can I help you today?',
				'suggested_questions' => wp_json_encode(
					array(
						array(
							'id'         => 'sq_1',
							'text'       => 'What services do you offer?',
							'icon'       => '🔍',
							'enabled'    => true,
							'sort_order' => 0,
						),
						array(
							'id'         => 'sq_2',
							'text'       => 'How do I get started?',
							'icon'       => '🚀',
							'enabled'    => true,
							'sort_order' => 1,
						),
						array(
							'id'         => 'sq_3',
							'text'       => 'How can I contact support?',
							'icon'       => '💬',
							'enabled'    => true,
							'sort_order' => 2,
						),
					)
				),
				'max_questions_desktop' => 4,
				'max_questions_mobile'  => 2,
				'show_on_welcome'       => 1,
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d' )
		);
	}

	// -------------------------------------------------------------------------
	// Version-gated migrations (add a new static method per future version).
	// -------------------------------------------------------------------------

	/**
	 * Migrations introduced in 1.0.0 — the initial release.
	 *
	 * All table creation happens via Schema::create_tables() / dbDelta.
	 * This method seeds the first-run defaults.
	 *
	 * @param string $from_version The previously installed version.
	 * @return void
	 */
	private static function migrate_to_1_0_0( string $from_version ): void {
		if ( version_compare( $from_version, '1.0.0', '<' ) ) {
			self::seed_defaults();
		}
	}

	/**
	 * Migrations introduced in 1.1.0 — "Edit AI Drafted Answers".
	 *
	 * Adds the manual-override columns to the unanswered questions and out of
	 * scope tables, plus the ai_answer_overrides audit log.
	 *
	 * Schema::create_tables() only runs on activation, so an update to an
	 * already-active site would otherwise never gain these columns.
	 *
	 * @param string $from_version The previously installed version.
	 * @return void
	 */
	private static function migrate_to_1_1_0( string $from_version ): void {
		if ( version_compare( $from_version, '1.1.0', '<' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';

			Schema::create_override_columns();
			Schema::create_override_log_table();
		}
	}

	/**
	 * Migrations introduced in 1.2.0 — "Edit sent chat messages".
	 *
	 * Adds is_edited / edited_at to ai_messages so a visitor rewording a
	 * message is recorded rather than silently overwriting the original text.
	 *
	 * @param string $from_version The previously installed version.
	 * @return void
	 */
	private static function migrate_to_1_2_0( string $from_version ): void {
		if ( version_compare( $from_version, '1.2.0', '<' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';

			Schema::create_message_edit_columns();
		}
	}

	/**
	 * Migrations introduced in 1.3.0 — "Feedback-driven continuous improvement".
	 *
	 * Creates the ai_chatbot_feedback table used to store enriched binary
	 * feedback and power the gap-detection, RAG-tuning and response-improvement
	 * analytics pipelines.
	 *
	 * @param string $from_version The previously installed version.
	 * @return void
	 */
	private static function migrate_to_1_3_0( string $from_version ): void {
		if ( version_compare( $from_version, '1.3.0', '<' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';

			Schema::create_tables();
		}
	}

	/**
	 * Migrations introduced in 1.4.0 — "Verified Link Registry".
	 *
	 * Creates the ai_verified_links table for strict URL grounding and zero hallucinated links.
	 *
	 * @param string $from_version The previously installed version.
	 * @return void
	 */
	private static function migrate_to_1_4_0( string $from_version ): void {
		if ( version_compare( $from_version, '1.4.0', '<' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';

			Schema::create_link_registry_table();
		}
	}
}
