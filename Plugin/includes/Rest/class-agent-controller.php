<?php
/**
 * Agent REST Controller.
 *
 * @package AIChatbot\Rest
 */

namespace AIChatbot\Rest;

use WP_REST_Request;
use WP_REST_Response;
use AIChatbot\Settings;
use AIChatbot\Language\LanguageService;

defined( 'ABSPATH' ) || exit;

/**
 * Class AgentController.
 */
class AgentController extends RestController {

	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		// Public read endpoint for widget initialization.
		register_rest_route(
			self::NAMESPACE,
			'/agent',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_public_agent' ),
					'permission_callback' => array( $this, 'check_public_permission' ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'update_agent' ),
					'permission_callback' => array( $this, 'check_admin_permission' ),
				),
			)
		);
	}

	/**
	 * Get sanitized public agent profile for frontend chat widget.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_public_agent( WP_REST_Request $request ): WP_REST_Response {
		$agent_id = (int) ( $request->get_param( 'agent_id' ) ?: 1 );

		$payload = self::build_public_agent_payload( $agent_id );

		if ( null === $payload ) {
			return $this->error( __( 'Agent not found.', 'ai-chatbot' ), 'agent_not_found', 404 );
		}

		return $this->success( $payload );
	}

	/**
	 * Build the sanitized public agent profile.
	 *
	 * Shared by the REST endpoint and the frontend asset loader, which
	 * inlines the profile into the page so the widget can paint its
	 * first frame without waiting for a network round-trip. Returns
	 * null when the agent does not exist.
	 *
	 * @param int $agent_id Agent ID.
	 * @return array|null
	 */
	public static function build_public_agent_payload( int $agent_id = 1 ): ?array {
		global $wpdb;
		$table_agents  = $wpdb->prefix . 'ai_agents';
		$table_sources = $wpdb->prefix . 'ai_knowledge_sources';

		$agent = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table_agents} WHERE id = %d", $agent_id ),
			ARRAY_A
		);

		if ( ! $agent ) {
			return null;
		}

		// Load business name.
		$biz = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT content FROM {$table_sources} WHERE agent_id = %d AND type = 'business_info'",
				$agent_id
			)
		);
		$biz_info     = ! empty( $biz ) ? json_decode( $biz, true ) : array();
		$company_name = $biz_info['business_name'] ?? get_bloginfo( 'name' );

		// Parse suggested questions, preserving enabled status and sort_order.
		$raw_questions = ! empty( $agent['suggested_questions'] ) ? json_decode( $agent['suggested_questions'], true ) : array();
		$questions     = array();
		if ( is_array( $raw_questions ) ) {
			foreach ( $raw_questions as $index => $q ) {
				if ( is_array( $q ) && ! empty( $q['text'] ) ) {
					$enabled = isset( $q['enabled'] ) ? (bool) $q['enabled'] : true;
					$questions[] = array(
						'id'         => ! empty( $q['id'] ) ? sanitize_text_field( (string) $q['id'] ) : 'q_' . ( $index + 1 ),
						'text'       => sanitize_text_field( (string) $q['text'] ),
						'icon'       => sanitize_text_field( (string) ( $q['icon'] ?? 'chat' ) ),
						'enabled'    => $enabled,
						'sort_order' => isset( $q['sort_order'] ) ? (int) $q['sort_order'] : $index,
					);
				}
			}
		}

		usort( $questions, function( $a, $b ) {
			return ( $a['sort_order'] ?? 0 ) <=> ( $b['sort_order'] ?? 0 );
		} );

		$settings        = Settings::get_all( $agent_id, true );
		$lang_settings   = $settings['language'] ?? array();
		$initial_lang    = LanguageService::sanitize( (string) ( $lang_settings['default_language'] ?? 'en' ) ) ?: 'en';
		if ( 'fixed' === ( $lang_settings['mode'] ?? '' ) ) {
			$initial_lang = LanguageService::sanitize( (string) ( $lang_settings['fixed_language'] ?? $initial_lang ) ) ?: $initial_lang;
		}

		$raw_greeting = (string) ( $agent['greeting'] ?? '' );
		if ( '' === trim( $raw_greeting ) || str_contains( $raw_greeting, 'How can I help you today?' ) || str_contains( $raw_greeting, 'How can I help' ) ) {
			if ( 'en' !== $initial_lang ) {
				$greeting = LanguageService::get_default_greeting( $initial_lang, $company_name, $agent['name'] );
			} else {
				$greeting = str_replace(
					array( '{{agent_name}}', '{{company_name}}' ),
					array( $agent['name'], $company_name ),
					$raw_greeting ?: 'Hello! Welcome to {{company_name}}. How can I help you today?'
				);
			}
		} else {
			$greeting = str_replace(
				array( '{{agent_name}}', '{{company_name}}' ),
				array( $agent['name'], $company_name ),
				$raw_greeting
			);
		}

		$configured_questions = $questions;
		if ( empty( $configured_questions ) ) {
			$questions = LanguageService::get_starter_questions( $initial_lang, $company_name );
			if ( empty( $questions ) ) {
				$questions = LanguageService::get_starter_questions( 'en', $company_name );
			}
		}

		$all_starter_questions = array(
			'de' => LanguageService::get_starter_questions( 'de', $company_name ),
			'en' => LanguageService::get_starter_questions( 'en', $company_name ),
			'es' => LanguageService::get_starter_questions( 'es', $company_name ),
			'ne' => LanguageService::get_starter_questions( 'ne', $company_name ),
			'fr' => LanguageService::get_starter_questions( 'fr', $company_name ),
			'it' => LanguageService::get_starter_questions( 'it', $company_name ),
			'pt' => LanguageService::get_starter_questions( 'pt', $company_name ),
			'hi' => LanguageService::get_starter_questions( 'hi', $company_name ),
		);

		// If custom questions are configured, override starter questions for default languages
		// so that widget in-page initialization has immediate access to them.
		if ( ! empty( $configured_questions ) ) {
			$enabled_questions = array_values( array_filter( $configured_questions, function( $q ) {
				return ! empty( $q['enabled'] );
			} ) );
			if ( ! empty( $enabled_questions ) ) {
				$all_starter_questions[ $initial_lang ] = $enabled_questions;
				$def_lang = LanguageService::sanitize( (string) ( $lang_settings['default_language'] ?? '' ) );
				if ( $def_lang && $def_lang !== $initial_lang ) {
					$all_starter_questions[ $def_lang ] = $enabled_questions;
				}
			}
		}

		return array(
			'id'                    => (int) $agent['id'],
			'name'                  => esc_html( $agent['name'] ),
			'role'                  => (string) ( $agent['role'] ?? '' ),
			'tone'                  => (string) ( $agent['tone'] ?? 'friendly' ),
			'custom_tone'           => (string) ( $agent['custom_tone'] ?? '' ),
			'answer_length'         => (string) ( $agent['answer_length'] ?? 'medium' ),
			'custom_answer_length'  => (string) ( $agent['custom_answer_length'] ?? '' ),
			'status'                => esc_attr( $agent['status'] ),
			'custom_status_text'    => esc_html( $agent['custom_status_text'] ?? '' ),
			'greeting'              => esc_html( $greeting ),
			'suggested_questions'   => $questions,
			'all_starter_questions' => $all_starter_questions,
			'max_questions_desktop' => (int) $agent['max_questions_desktop'],
			'max_questions_mobile'  => (int) $agent['max_questions_mobile'],
			'show_on_welcome'       => (bool) $agent['show_on_welcome'],
			'company_name'          => esc_html( $company_name ),
			'placement'             => $settings['placement'] ?? array(),
			'theme'                 => $settings['theme'] ?? array(),
			'sounds'                => $settings['sounds'] ?? array(),
			'badge'                 => $settings['badge'] ?? array(),
			'response_behavior'     => $settings['response_behavior'] ?? array(
				'auto_links_verified'   => false,
				'auto_links_citations'  => false,
				'allow_requested_links' => true,
			),
			'skills'                => array(
				'contact_human' => ! empty( $settings['skills_contact']['enabled'] ),
				'support_email' => esc_attr( Settings::get_support_email( (int) $agent['id'] ) ),
				'follow_up'     => $settings['skills_follow_up'] ?? array(),
			),
			'ui_strings'            => LanguageService::get_ui_strings( $initial_lang ),
			'all_ui_strings'        => LanguageService::get_all_ui_strings(),
		);
	}

	/**
	 * Update agent configuration (Admin only).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function update_agent( WP_REST_Request $request ): WP_REST_Response {
		global $wpdb;
		$table = $wpdb->prefix . 'ai_agents';

		$params   = $request->get_json_params() ?: array();
		$agent_id = (int) ( $params['id'] ?? 1 );

		$update = array();
		$format = array();

		if ( isset( $params['name'] ) ) {
			$update['name'] = sanitize_text_field( $params['name'] );
			$format[]       = '%s';
		}
		if ( isset( $params['status'] ) ) {
			$update['status'] = sanitize_text_field( $params['status'] );
			$format[]         = '%s';
		}
		if ( isset( $params['custom_status_text'] ) ) {
			$update['custom_status_text'] = sanitize_text_field( $params['custom_status_text'] );
			$format[]                     = '%s';
		}
		if ( isset( $params['role'] ) ) {
			$update['role'] = sanitize_textarea_field( $params['role'] );
			$format[]       = '%s';
		}
		if ( isset( $params['tone'] ) ) {
			$update['tone'] = sanitize_text_field( $params['tone'] );
			$format[]       = '%s';
		}
		if ( isset( $params['custom_tone'] ) ) {
			$update['custom_tone'] = sanitize_textarea_field( $params['custom_tone'] );
			$format[]              = '%s';
		}
		if ( isset( $params['answer_length'] ) ) {
			$update['answer_length'] = sanitize_text_field( $params['answer_length'] );
			$format[]                = '%s';
		}
		if ( isset( $params['custom_answer_length'] ) ) {
			$update['custom_answer_length'] = sanitize_textarea_field( $params['custom_answer_length'] );
			$format[]                       = '%s';
		}
		if ( isset( $params['greeting'] ) ) {
			$update['greeting'] = sanitize_textarea_field( $params['greeting'] );
			$format[]           = '%s';
		}
		if ( isset( $params['suggested_questions'] ) ) {
			$raw = is_array( $params['suggested_questions'] ) ? $params['suggested_questions'] : array();
			$cleaned_questions = array();
			$seen_texts = array();

			foreach ( $raw as $index => $q ) {
				if ( ! is_array( $q ) ) {
					continue;
				}
				$text = isset( $q['text'] ) ? trim( sanitize_text_field( (string) $q['text'] ) ) : '';
				if ( '' === $text ) {
					continue;
				}

				// Deduplicate by text (case-insensitive UTF-8).
				$text_lower = mb_strtolower( $text, 'UTF-8' );
				if ( isset( $seen_texts[ $text_lower ] ) ) {
					continue;
				}
				$seen_texts[ $text_lower ] = true;

				// Limit length to 255 characters.
				if ( mb_strlen( $text, 'UTF-8' ) > 255 ) {
					$text = mb_substr( $text, 0, 255, 'UTF-8' );
				}

				$cleaned_questions[] = array(
					'id'         => ! empty( $q['id'] ) ? sanitize_text_field( (string) $q['id'] ) : 'q_' . ( $index + 1 ) . '_' . time(),
					'text'       => $text,
					'icon'       => ! empty( $q['icon'] ) ? sanitize_text_field( (string) $q['icon'] ) : 'chat',
					'enabled'    => isset( $q['enabled'] ) ? (bool) $q['enabled'] : true,
					'sort_order' => isset( $q['sort_order'] ) ? (int) $q['sort_order'] : $index,
				);
			}

			// Sort by sort_order.
			usort( $cleaned_questions, function( $a, $b ) {
				return ( $a['sort_order'] ?? 0 ) <=> ( $b['sort_order'] ?? 0 );
			} );

			// Re-index sort_order cleanly.
			foreach ( $cleaned_questions as $idx => &$cq ) {
				$cq['sort_order'] = $idx;
			}
			unset( $cq );

			$update['suggested_questions'] = wp_json_encode( $cleaned_questions );
			$format[]                      = '%s';
		}
		if ( isset( $params['max_questions_desktop'] ) ) {
			$update['max_questions_desktop'] = (int) $params['max_questions_desktop'];
			$format[]                        = '%d';
		}
		if ( isset( $params['max_questions_mobile'] ) ) {
			$update['max_questions_mobile'] = (int) $params['max_questions_mobile'];
			$format[]                       = '%d';
		}
		if ( isset( $params['show_on_welcome'] ) ) {
			$update['show_on_welcome'] = ! empty( $params['show_on_welcome'] ) ? 1 : 0;
			$format[]                  = '%d';
		}

		if ( empty( $update ) ) {
			return $this->error( __( 'No valid fields provided for update.', 'ai-chatbot' ), 'no_fields', 400 );
		}

		$result = $wpdb->update( $table, $update, array( 'id' => $agent_id ), $format, array( '%d' ) );
		if ( false === $result ) {
			return $this->error( __( 'Database update failed.', 'ai-chatbot' ), 'db_error', 500 );
		}

		// Clear transient caches, object cache, and full-page caching plugins (WP Rocket, LiteSpeed, W3TC, WP Super Cache).
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_ai_chat_rsp_%' OR option_name LIKE '_transient_timeout_ai_chat_rsp_%'" );
		wp_cache_delete( 'ai_agent_' . $agent_id );
		if ( function_exists( 'rocket_clean_domain' ) ) {
			rocket_clean_domain();
		}
		if ( function_exists( 'wp_cache_clean_cache' ) ) {
			wp_cache_clean_cache( 'all' );
		}
		if ( function_exists( 'w3tc_flush_all' ) ) {
			w3tc_flush_all();
		}
		if ( has_action( 'litespeed_purge_all' ) ) {
			do_action( 'litespeed_purge_all' );
		}
		if ( function_exists( 'wp_cache_flush' ) ) {
			wp_cache_flush();
		}

		$fresh_payload = self::build_public_agent_payload( $agent_id );

		return $this->success(
			array(
				'message' => __( 'Agent updated successfully.', 'ai-chatbot' ),
				'agent'   => $fresh_payload,
			)
		);
	}
}
