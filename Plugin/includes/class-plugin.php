<?php
/**
 * Main Plugin Coordinator Class.
 *
 * @package AIChatbot
 */

namespace AIChatbot;

use AIChatbot\Database\Schema;
use AIChatbot\Database\Migrator;
use AIChatbot\Admin\AdminController;
use AIChatbot\Admin\Privacy;
use AIChatbot\Frontend\FrontendController;
use AIChatbot\Frontend\Block;
use AIChatbot\AI\ModelRegistry;
use AIChatbot\Rest\ChatController;
use AIChatbot\Rest\AgentController;
use AIChatbot\Rest\ContactController;
use AIChatbot\Rest\FeedbackController;
use AIChatbot\Rest\KnowledgeController;
use AIChatbot\Rest\SettingsController;
use AIChatbot\Rest\WizardController;
use AIChatbot\Rest\InboxController;

defined( 'ABSPATH' ) || exit;

/**
 * Class Plugin.
 */
class Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var ?Plugin
	 */
	protected static ?Plugin $instance = null;

	/**
	 * Get singleton instance.
	 *
	 * @return Plugin
	 */
	public static function get_instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Initialize plugin.
	 *
	 * @return void
	 */
	public static function init(): void {
		// Registered on every load so the scheduled event is picked up even on
		// installs that were activated before this hook existed.
		add_action( 'ai_chatbot_sitemap_sync', array( __CLASS__, 'run_sitemap_sync' ) );
		add_action( 'ai_chatbot_feedback_improvement_pipeline', array( __CLASS__, 'run_feedback_improvement_pipeline' ) );

		self::ensure_scheduled_events();

		self::get_instance()->bootstrap();
	}

	/**
	 * Make sure the scheduled events exist.
	 *
	 * Relying on the activation hook alone meant the sitemap sync never got
	 * scheduled on any site that was already active before it existed.
	 * wp_next_scheduled() reads the cached cron option, so this is cheap.
	 *
	 * @return void
	 */
	protected static function ensure_scheduled_events(): void {
		if ( ! wp_next_scheduled( 'ai_chatbot_daily_retention_cleanup' ) ) {
			wp_schedule_event( time(), 'daily', 'ai_chatbot_daily_retention_cleanup' );
		}

		if ( ! wp_next_scheduled( 'ai_chatbot_sitemap_sync' ) ) {
			wp_schedule_event( time(), 'daily', 'ai_chatbot_sitemap_sync' );
		}

		if ( ! wp_next_scheduled( 'ai_chatbot_feedback_improvement_pipeline' ) ) {
			wp_schedule_event( time(), 'daily', 'ai_chatbot_feedback_improvement_pipeline' );
		}

		if ( ! wp_next_scheduled( 'ai_chatbot_link_revalidation' ) ) {
			wp_schedule_event( time(), 'daily', 'ai_chatbot_link_revalidation' );
		}
	}

	/**
	 * Activation hook.
	 *
	 * @return void
	 */
	public static function activate(): void {
		Schema::create_tables();
		Migrator::seed_defaults();

		self::ensure_scheduled_events();

		// Send first-time admins to the setup wizard.
		$onboarding_status = get_option( 'ai_chatbot_onboarding_completed', null );
		if ( null === $onboarding_status ) {
			if ( \AIChatbot\Settings::is_setup_complete() ) {
				update_option( 'ai_chatbot_onboarding_completed', 'completed', false );
			} else {
				update_option( 'ai_chatbot_onboarding_completed', 'not_started', false );
				update_option( 'ai_chatbot_do_setup_redirect', '1', false );
			}
		} elseif ( 'completed' !== $onboarding_status ) {
			update_option( 'ai_chatbot_do_setup_redirect', '1', false );
		}
	}

	/**
	 * Keep the knowledge base aligned with the live website.
	 *
	 * Runs daily but returns immediately unless auto-sync is enabled, so the
	 * event is cheap for sites that never turn it on.
	 *
	 * @return void
	 */
	public static function run_sitemap_sync(): void {
		$settings = \AIChatbot\Settings::get_all( 1, false );
		$config   = $settings['knowledge'] ?? array();

		if ( empty( $config['auto_sync'] ) ) {
			return;
		}

		$hours = max( 1, (int) ( $config['sync_frequency_hours'] ?? 24 ) );
		$last  = (int) get_option( 'ai_chatbot_last_sitemap_sync', 0 );

		if ( $last && ( time() - $last ) < ( $hours * HOUR_IN_SECONDS ) ) {
			return;
		}

		\AIChatbot\Knowledge\SitemapSync::sync( 1, (string) ( $config['sitemap_url'] ?? '' ) );

		update_option( 'ai_chatbot_last_sitemap_sync', time(), false );
	}

	/**
	 * Run the feedback-driven improvement pipeline.
	 *
	 * Processes recent negative feedback to identify improvement candidates
	 * and trigger response regeneration where needed.
	 *
	 * @return void
	 */
	public static function run_feedback_improvement_pipeline(): void {
		$store = new \AIChatbot\Analytics\FeedbackStore();
		$improver = new \AIChatbot\Analytics\ResponseImprover( $store );

		$recent = $store->get_negative_feedback( 1, 7, 50 );

		foreach ( $recent as $row ) {
			if ( ! empty( $row['message_id'] ) ) {
				$improver->process_negative_feedback( (int) $row['message_id'] );
			}
		}
	}

	/**
	 * Deactivation hook.
	 *
	 * @return void
	 */
	public static function deactivate(): void {
		wp_clear_scheduled_hook( 'ai_chatbot_daily_retention_cleanup' );
		wp_clear_scheduled_hook( 'ai_chatbot_sitemap_sync' );
		wp_clear_scheduled_hook( 'ai_chatbot_feedback_improvement_pipeline' );
		wp_clear_scheduled_hook( 'ai_chatbot_link_revalidation' );
	}

	/**
	 * Bootstrap plugin subsystems and hooks.
	 *
	 * @return void
	 */
	public function bootstrap(): void {
		// Run pending database migrations.
		Migrator::run();

		// Initialize Provider Registry.
		ModelRegistry::init();

		// Initialize Privacy & Retention.
		Privacy::init();

		// Initialize Verified Link Registry.
		\AIChatbot\Links\LinkRegistry::init();

		// Initialize REST API routes.
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );

		// Initialize Admin.
		if ( is_admin() ) {
			AdminController::init();

			// Redirect to Setup Wizard immediately after plugin activation.
			add_action( 'admin_init', array( __CLASS__, 'maybe_redirect_to_setup_wizard' ) );
		}

		// Initialize Frontend Widget.
		FrontendController::init();

		// Initialize Gutenberg Block.
		Block::init();
	}

	/**
	 * Redirect to the Setup Wizard page after plugin activation.
	 *
	 * Fires on admin_init so the admin menus are registered and the target URL
	 * is valid. Clears the flag immediately so it only fires once per activation.
	 *
	 * @return void
	 */
	public static function maybe_redirect_to_setup_wizard(): void {
		AdminController::enforce_onboarding_gate();
	}

	/**
	 * Register all REST API controllers.
	 *
	 * @return void
	 */
	public function register_rest_routes(): void {
		( new ChatController() )->register_routes();
		( new AgentController() )->register_routes();
		( new ContactController() )->register_routes();
		( new FeedbackController() )->register_routes();
		( new KnowledgeController() )->register_routes();
		( new SettingsController() )->register_routes();
		( new WizardController() )->register_routes();
		( new InboxController() )->register_routes();
	}
}
