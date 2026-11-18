<?php
/**
 * Frontend Controller for chat widget and shortcode.
 *
 * @package AIChatbot\Frontend
 */

namespace AIChatbot\Frontend;

use AIChatbot\Settings;
use AIChatbot\Rest\AgentController;
use AIChatbot\Language\LanguageService;

defined( 'ABSPATH' ) || exit;

/**
 * Class FrontendController.
 */
class FrontendController {

	/**
	 * Register frontend hooks.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_action( 'wp_footer', array( __CLASS__, 'render_widget_container' ) );
		add_shortcode( 'ai_chatbot', array( __CLASS__, 'render_shortcode' ) );
	}

	/**
	 * Check visibility conditions for current request.
	 *
	 * @param array $visibility Settings.
	 * @return bool
	 */
	public static function is_visible( array $visibility ): bool {
		// Device check.
		$device = $visibility['device'] ?? 'all';
		if ( 'desktop_only' === $device && wp_is_mobile() ) {
			return false;
		}
		if ( 'mobile_only' === $device && ! wp_is_mobile() ) {
			return false;
		}

		// User login condition.
		$user_cond = $visibility['user_condition'] ?? 'all';
		if ( 'logged_in' === $user_cond && ! is_user_logged_in() ) {
			return false;
		}
		if ( 'logged_out' === $user_cond && is_user_logged_in() ) {
			return false;
		}

		// Mode check.
		$mode = $visibility['mode'] ?? 'all_pages';
		if ( 'specific_pages' === $mode ) {
			$page_ids = (array) ( $visibility['page_ids'] ?? array() );
			if ( ! is_page( $page_ids ) && ! is_single( $page_ids ) ) {
				return false;
			}
		} elseif ( 'hide_on_pages' === $mode ) {
			$page_ids = (array) ( $visibility['page_ids'] ?? array() );
			if ( is_page( $page_ids ) || is_single( $page_ids ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Enqueue widget assets if visible.
	 *
	 * @return void
	 */
	public static function enqueue_assets(): void {
		// The chatbot stays hidden until first-time setup is complete.
		if ( ! Settings::is_setup_complete() ) {
			return;
		}

		$settings   = Settings::get_all( 1, true );
		$visibility = $settings['visibility'] ?? array();

		if ( ! self::is_visible( $visibility ) && empty( $GLOBALS['ai_chatbot_shortcode_used'] ) ) {
			return;
		}

		// filemtime() busts the browser cache whenever the asset is edited.
		// The hardcoded AI_CHATBOT_VERSION never changes between releases,
		// so edits to these two files were served stale from the cache.
		$css_version = file_exists( AI_CHATBOT_PATH . 'public/css/chatbot-widget.css' )
			? filemtime( AI_CHATBOT_PATH . 'public/css/chatbot-widget.css' )
			: AI_CHATBOT_VERSION;
		$js_version  = file_exists( AI_CHATBOT_PATH . 'public/js/chatbot-widget.js' )
			? filemtime( AI_CHATBOT_PATH . 'public/js/chatbot-widget.js' )
			: AI_CHATBOT_VERSION;

		wp_enqueue_style(
			'ai-chatbot-widget',
			AI_CHATBOT_URL . 'public/css/chatbot-widget.css',
			array(),
			$css_version
		);

		wp_enqueue_script(
			'ai-chatbot-widget',
			AI_CHATBOT_URL . 'public/js/chatbot-widget.js',
			array(),
			$js_version,
			true
		);

		$lang_settings   = $settings['language'] ?? array();
		$initial_lang    = LanguageService::sanitize( (string) ( $lang_settings['default_language'] ?? 'en' ) ) ?: 'en';
		if ( 'fixed' === ( $lang_settings['mode'] ?? '' ) ) {
			$initial_lang = LanguageService::sanitize( (string) ( $lang_settings['fixed_language'] ?? $initial_lang ) ) ?: $initial_lang;
		}

		$agent_payload = AgentController::build_public_agent_payload( 1 );
		$company_name  = $agent_payload['company_name'] ?? get_bloginfo( 'name' );

		$all_starter_questions = $agent_payload['all_starter_questions'] ?? array(
			'de' => LanguageService::get_starter_questions( 'de', $company_name ),
			'en' => LanguageService::get_starter_questions( 'en', $company_name ),
			'es' => LanguageService::get_starter_questions( 'es', $company_name ),
			'ne' => LanguageService::get_starter_questions( 'ne', $company_name ),
			'fr' => LanguageService::get_starter_questions( 'fr', $company_name ),
			'it' => LanguageService::get_starter_questions( 'it', $company_name ),
			'pt' => LanguageService::get_starter_questions( 'pt', $company_name ),
			'hi' => LanguageService::get_starter_questions( 'hi', $company_name ),
		);

		// Localize frontend config.
		wp_localize_script(
			'ai-chatbot-widget',
			'aiChatbotConfig',
			array(
				'apiUrl'              => esc_url_raw( rest_url( 'ai-chatbot/v1' ) ),
				'nonce'               => wp_create_nonce( 'wp_rest' ),
				'agentId'             => 1,
				'siteName'            => get_bloginfo( 'name' ),
				'supportEmail'        => esc_attr( Settings::get_support_email( 1 ) ),
				'placement'           => $settings['placement'] ?? array(),
				'theme'               => $settings['theme'] ?? array(),
				'sounds'              => $settings['sounds'] ?? array(),
				'language'            => $settings['language'] ?? array(),
				'response_behavior'   => $settings['response_behavior'] ?? array(
					'auto_links_verified'   => false,
					'auto_links_citations'  => false,
					'allow_requested_links' => true,
				),
				'uiStrings'           => LanguageService::get_ui_strings( $initial_lang ),
				'allUiStrings'        => LanguageService::get_all_ui_strings(),
				'allStarterQuestions' => $all_starter_questions,
				'starterQuestions'    => $all_starter_questions,
				// Inline the public agent profile so the widget can paint
				// its first frame (launcher, window shell, theme, greeting)
				// without waiting for the /agent round-trip. The widget
				// still revalidates this in the background, so changes
				// saved in the dashboard are picked up on the next fetch.
				'agent'               => $agent_payload,
			)
		);
	}

	/**
	 * Render widget mount container in site footer.
	 *
	 * @return void
	 */
	public static function render_widget_container(): void {
		// The chatbot stays hidden until first-time setup is complete.
		if ( ! Settings::is_setup_complete() ) {
			return;
		}

		$settings   = Settings::get_all( 1, true );
		$visibility = $settings['visibility'] ?? array();
		$placement  = $settings['placement'] ?? array();

		if ( empty( $placement['site_wide'] ) || ! self::is_visible( $visibility ) ) {
			return;
		}

		echo '<div id="ai-chatbot-root" data-agent-id="1" data-mode="floating"></div>';
	}

	/**
	 * Shortcode [ai_chatbot id="1" mode="embedded"] handler.
	 *
	 * @param array $atts Attributes.
	 * @return string
	 */
	public static function render_shortcode( array $atts = array() ): string {
		$GLOBALS['ai_chatbot_shortcode_used'] = true;

		$a = shortcode_atts(
			array(
				'id'   => 1,
				'mode' => 'embedded',
			),
			$atts,
			'ai_chatbot'
		);

		$agent_id = (int) $a['id'];
		$mode     = esc_attr( $a['mode'] );

		// Enqueue scripts if not already enqueued.
		self::enqueue_assets();

		return sprintf(
			'<div class="ai-chatbot-shortcode-container" style="width:100%%; max-width:480px; height:600px; margin:20px auto;"><div id="ai-chatbot-root-%d" class="ai-chatbot-root" data-agent-id="%d" data-mode="%s"></div></div>',
			$agent_id,
			$agent_id,
			$mode
		);
	}
}
