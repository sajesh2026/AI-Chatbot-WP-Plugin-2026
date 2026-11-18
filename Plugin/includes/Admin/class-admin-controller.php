<?php
/**
 * Admin Controller for AI Chatbot React App.
 *
 * @package AIChatbot\Admin
 */

namespace AIChatbot\Admin;

use AIChatbot\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Class AdminController.
 */
class AdminController {

	/**
	 * Register admin hooks.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'register_admin_menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'enforce_onboarding_gate' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_admin_assets' ) );
	}

	/**
	 * Enforce the first-activation onboarding gate.
	 *
	 * Redirects all plugin pages and submenus to the setup wizard until onboarding
	 * is completed. WordPress core pages remain completely accessible.
	 *
	 * @return void
	 */
	public static function enforce_onboarding_gate(): void {
		// Never redirect during background, AJAX, REST, or WP-CLI requests.
		if ( wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return;
		}

		// Only admins who can manage options are gated.
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$onboarding_status = get_option( 'ai_chatbot_onboarding_completed', 'not_started' );

		// 1. Check activation redirect flag.
		if ( get_option( 'ai_chatbot_do_setup_redirect' ) ) {
			delete_option( 'ai_chatbot_do_setup_redirect' );

			if ( 'completed' !== $onboarding_status ) {
				// Don't redirect during bulk plugin actions.
				if ( ! isset( $_REQUEST['action'] ) || 'activate-selected' !== sanitize_key( $_REQUEST['action'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
					wp_safe_redirect( admin_url( 'admin.php?page=ai-chatbot-setup' ) );
					exit;
				}
			}
		}

		// 2. If onboarding is already completed, the plugin is fully unlocked.
		if ( 'completed' === $onboarding_status ) {
			return;
		}

		// 3. Setup is incomplete: Gate access to AI Chatbot pages.
		$current_page = sanitize_key( $_GET['page'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification
		if ( empty( $current_page ) ) {
			// Core WordPress admin pages (index.php, edit.php, plugins.php, etc.) remain untouched.
			return;
		}

		// The setup wizard itself must be accessible.
		if ( 'ai-chatbot-setup' === $current_page ) {
			return;
		}

		// Gate any AI Chatbot plugin page or submenu.
		if ( 'ai-chatbot' === $current_page || str_starts_with( $current_page, 'ai-chatbot' ) ) {
			wp_safe_redirect( admin_url( 'admin.php?page=ai-chatbot-setup' ) );
			exit;
		}
	}

	/**
	 * Register top-level admin menu and submenus.
	 *
	 * @return void
	 */
	public static function register_admin_menu(): void {
		$capability = 'manage_options';
		$slug       = 'ai-chatbot';

		// Top-level menu.
		add_menu_page(
			__( 'AI Chatbot', 'ai-chatbot' ),
			__( 'AI Chatbot', 'ai-chatbot' ),
			$capability,
			$slug,
			array( __CLASS__, 'render_admin_app' ),
			'dashicons-format-chat',
			30
		);

		// Submenus mapping directly to React app views.
		add_submenu_page(
			$slug,
			__( 'Agent', 'ai-chatbot' ),
			__( 'Agent', 'ai-chatbot' ),
			$capability,
			$slug . '#/agent',
			array( __CLASS__, 'render_admin_app' )
		);

		add_submenu_page(
			$slug,
			__( 'Knowledge', 'ai-chatbot' ),
			__( 'Knowledge', 'ai-chatbot' ),
			$capability,
			$slug . '#/knowledge',
			array( __CLASS__, 'render_admin_app' )
		);

		add_submenu_page(
			$slug,
			__( 'Skills & Leads', 'ai-chatbot' ),
			__( 'Skills', 'ai-chatbot' ),
			$capability,
			$slug . '#/skills',
			array( __CLASS__, 'render_admin_app' )
		);

		add_submenu_page(
			$slug,
			__( 'Settings', 'ai-chatbot' ),
			__( 'Settings', 'ai-chatbot' ),
			$capability,
			$slug . '#/settings',
			array( __CLASS__, 'render_admin_app' )
		);

		add_submenu_page(
			$slug,
			__( 'Setup Wizard', 'ai-chatbot' ),
			__( 'Setup Wizard', 'ai-chatbot' ),
			$capability,
			'ai-chatbot-setup',
			array( __CLASS__, 'render_setup_wizard' )
		);

		add_submenu_page(
			$slug,
			__( 'Unanswered Questions', 'ai-chatbot' ),
			__( 'Unanswered Inbox', 'ai-chatbot' ),
			$capability,
			$slug . '#/inbox',
			array( __CLASS__, 'render_admin_app' )
		);

		// Hidden setup wizard page endpoint.
		add_submenu_page(
			null,
			__( 'AI Chatbot Setup', 'ai-chatbot' ),
			__( 'Setup Wizard', 'ai-chatbot' ),
			$capability,
			'ai-chatbot-setup',
			array( __CLASS__, 'render_setup_wizard' )
		);
	}

	/**
	 * Enqueue admin React bundle and CSS.
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public static function enqueue_admin_assets( string $hook ): void {
		if ( ! str_contains( $hook, 'ai-chatbot' ) ) {
			return;
		}

		$asset_file = AI_CHATBOT_PATH . 'admin/build/index.asset.php';
		$deps       = array( 'wp-element', 'wp-components', 'wp-i18n', 'wp-api-fetch' );
		$version    = AI_CHATBOT_VERSION;

		if ( file_exists( $asset_file ) ) {
			$asset   = require $asset_file;
			$deps    = $asset['dependencies'] ?? $deps;
			$version = $asset['version'] ?? $version;
		}

		wp_enqueue_style( 'wp-components' );

		/*
		 * The theme tab lets an administrator pick the chatbot avatar from the
		 * media library, which needs wp.media. Without this the picker silently
		 * had no library to open.
		 */
		wp_enqueue_media();

		if ( file_exists( AI_CHATBOT_PATH . 'admin/build/index.css' ) ) {
			wp_enqueue_style(
				'ai-chatbot-admin-css',
				AI_CHATBOT_URL . 'admin/build/index.css',
				array( 'wp-components' ),
				$version
			);
		}

		if ( file_exists( AI_CHATBOT_PATH . 'admin/build/index.js' ) ) {
			wp_enqueue_script(
				'ai-chatbot-admin-js',
				AI_CHATBOT_URL . 'admin/build/index.js',
				$deps,
				$version,
				true
			);

			$onboarding_status = get_option( 'ai_chatbot_onboarding_completed', 'not_started' );
			$saved_state       = get_option( 'ai_chatbot_onboarding_state', array() );
			$is_setup_page     = isset( $_GET['page'] ) && 'ai-chatbot-setup' === $_GET['page']; // phpcs:ignore WordPress.Security.NonceVerification

			wp_localize_script(
				'ai-chatbot-admin-js',
				'aiChatbotAdminData',
				array(
					'apiUrl'            => esc_url_raw( rest_url( 'ai-chatbot/v1' ) ),
					'nonce'             => wp_create_nonce( 'wp_rest' ),
					'agentId'           => 1,
					'siteName'          => get_bloginfo( 'name' ),
					'homeUrl'           => home_url(),
					'adminEmail'        => get_option( 'admin_email' ),
					'pluginVersion'     => AI_CHATBOT_VERSION,
					'launchWizard'      => ( isset( $_GET['setup_wizard'] ) && '1' === $_GET['setup_wizard'] ), // phpcs:ignore WordPress.Security.NonceVerification
					'wizardCompleted'   => (bool) ( 'completed' === $onboarding_status || get_option( 'ai_chatbot_wizard_completed', false ) ),
					'onboardingStatus'  => $onboarding_status,
					'onboardingState'   => is_array( $saved_state ) ? $saved_state : array(),
					'isSetupPage'       => $is_setup_page,
					'onboardingSuccess' => isset( $_GET['onboarding_success'] ) && '1' === $_GET['onboarding_success'], // phpcs:ignore WordPress.Security.NonceVerification
					'setupWizardUrl'    => admin_url( 'admin.php?page=ai-chatbot-setup' ),
					'dashboardUrl'      => admin_url( 'admin.php?page=ai-chatbot' ),
				)
			);
		}
	}

	/**
	 * Render mount element for React application.
	 *
	 * @return void
	 */
	public static function render_admin_app(): void {
		echo '<div class="wrap" style="margin:0; padding:0;">';
		echo '<div id="ai-chatbot-admin-root"></div>';
		echo '</div>';
	}

	/**
	 * Render full-screen Setup Wizard application mount element.
	 *
	 * @return void
	 */
	public static function render_setup_wizard(): void {
		echo '<div class="wrap ai-chatbot-wizard-page" style="margin:0; padding:0;">';
		echo '<div id="ai-chatbot-wizard-root"></div>';
		echo '</div>';
	}
}
