<?php
/**
 * Settings and Key Pool REST Controller.
 *
 * @package AIChatbot\Rest
 */

namespace AIChatbot\Rest;

use WP_REST_Request;
use WP_REST_Response;
use AIChatbot\Settings;
use AIChatbot\Security\Encryption;
use AIChatbot\AI\ModelRegistry;
use AIChatbot\AI\KeyPool;
use AIChatbot\Email\EmailQueue;
use AIChatbot\Guardrails\TopicGuardrail;
use AIChatbot\Links\LinkRegistry;

defined( 'ABSPATH' ) || exit;

/**
 * Class SettingsController.
 */
class SettingsController extends RestController {

	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		// Settings CRUD.
		register_rest_route(
			self::NAMESPACE,
			'/settings',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_settings' ),
					'permission_callback' => array( $this, 'check_admin_permission' ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'save_settings' ),
					'permission_callback' => array( $this, 'check_admin_permission' ),
				),
			)
		);

		// Live model catalogue for the configured provider key.
		register_rest_route(
			self::NAMESPACE,
			'/providers/models',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'list_provider_models' ),
					'permission_callback' => array( $this, 'check_admin_permission' ),
				),
			)
		);

		// Key Pool routes.
		register_rest_route(
			self::NAMESPACE,
			'/keys',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_keys' ),
					'permission_callback' => array( $this, 'check_admin_permission' ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'add_key' ),
					'permission_callback' => array( $this, 'check_admin_permission' ),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/keys/(?P<id>\d+)',
			array(
				array(
					'methods'             => 'PUT',
					'callback'            => array( $this, 'update_key' ),
					'permission_callback' => array( $this, 'check_admin_permission' ),
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( $this, 'delete_key' ),
					'permission_callback' => array( $this, 'check_admin_permission' ),
				),
			)
		);

		// Test Connection endpoints.
		register_rest_route(
			self::NAMESPACE,
			'/keys/test',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'test_key' ),
				'permission_callback' => array( $this, 'check_admin_permission' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/email/test',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'test_email' ),
				'permission_callback' => array( $this, 'check_admin_permission' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/guardrails/test',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'test_guardrails' ),
				'permission_callback' => array( $this, 'check_admin_permission' ),
			)
		);

		// Verified Link Registry routes.
		register_rest_route(
			self::NAMESPACE,
			'/links/status',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_links_status' ),
				'permission_callback' => array( $this, 'check_admin_permission' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/links/rebuild',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'rebuild_links' ),
				'permission_callback' => array( $this, 'check_admin_permission' ),
			)
		);
	}

	/**
	 * Get settings.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_settings( WP_REST_Request $request ): WP_REST_Response {
		$agent_id = (int) ( $request->get_param( 'agent_id' ) ?: 1 );
		$settings = Settings::get_all( $agent_id, true );

		return $this->success( array( 'settings' => $settings ) );
	}

	/**
	 * Save settings.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function save_settings( WP_REST_Request $request ): WP_REST_Response {
		$params   = $request->get_json_params() ?: array();
		$agent_id = (int) ( $params['agent_id'] ?? 1 );
		$settings = $params['settings'] ?? array();

		if ( empty( $settings ) || ! is_array( $settings ) ) {
			return $this->error( __( 'Invalid settings payload.', 'ai-chatbot' ), 'invalid_payload', 400 );
		}

		$validation = $this->validate_provider_settings( $settings, $agent_id );
		if ( ! empty( $validation ) ) {
			return $this->error( implode( ' ', $validation ), 'invalid_provider_settings', 400 );
		}

		$validation = $this->validate_language_settings( $settings );
		if ( ! empty( $validation ) ) {
			return $this->error( implode( ' ', $validation ), 'invalid_language_settings', 400 );
		}

		$validation = $this->validate_email_settings( $settings );
		if ( ! empty( $validation ) ) {
			return $this->error( implode( ' ', $validation ), 'invalid_email_settings', 400 );
		}

		// Sanitize response behavior / Smart Source Links settings.
		if ( isset( $settings['response_behavior'] ) && is_array( $settings['response_behavior'] ) ) {
			$resp = &$settings['response_behavior'];
		} elseif ( isset( $settings['response'] ) && is_array( $settings['response'] ) ) {
			$resp = &$settings['response'];
		} else {
			$resp = null;
		}

		if ( is_array( $resp ) ) {
			if ( isset( $resp['link_style'] ) ) {
				$resp['link_style'] = in_array( $resp['link_style'], array( 'inline', 'block', 'both' ), true ) ? $resp['link_style'] : 'inline';
			}
			if ( isset( $resp['max_links_per_answer'] ) ) {
				$resp['max_links_per_answer'] = max( 1, min( 5, (int) $resp['max_links_per_answer'] ) );
			}
			if ( isset( $resp['link_relevance_threshold'] ) ) {
				$resp['link_relevance_threshold'] = max( 0.0, min( 1.0, (float) $resp['link_relevance_threshold'] ) );
			}
			if ( isset( $resp['rebuild_interval'] ) ) {
				$resp['rebuild_interval'] = in_array( $resp['rebuild_interval'], array( 'hourly', 'daily', 'weekly' ), true ) ? $resp['rebuild_interval'] : 'daily';
			}
			if ( isset( $resp['deduplicate_conversation_links'] ) ) {
				$resp['deduplicate_conversation_links'] = (bool) $resp['deduplicate_conversation_links'];
			}
			if ( isset( $resp['sitemap_urls'] ) ) {
				$resp['sitemap_urls'] = sanitize_textarea_field( $resp['sitemap_urls'] );
			}
			if ( isset( $resp['allowed_domains'] ) ) {
				$resp['allowed_domains'] = sanitize_textarea_field( $resp['allowed_domains'] );
			}
			if ( isset( $resp['excluded_patterns'] ) ) {
				$resp['excluded_patterns'] = sanitize_textarea_field( $resp['excluded_patterns'] );
			}
			unset( $resp );
		}

		Settings::save_all( $agent_id, $settings );

		if ( class_exists( '\AIChatbot\Links\LinkRegistry' ) ) {
			\AIChatbot\Links\LinkRegistry::reschedule_cron();
		}

		return $this->success( array( 'message' => __( 'Settings saved successfully.', 'ai-chatbot' ) ) );
	}

	/**
	 * Reject credential and model combinations that cannot possibly work.
	 *
	 * Catches the common mistakes that silently break AI calls: a Groq key
	 * stored in the OpenAI field, masked keys rejected improperly, and models
	 * that do not exist on the selected provider.
	 *
	 * @param array $settings Incoming settings payload (passed by reference to sanitize keys).
	 * @param int   $agent_id Agent ID.
	 * @return array List of human-readable problems. Empty when valid.
	 */
	protected function validate_provider_settings( array &$settings, int $agent_id = 1 ): array {
		$problems  = array();
		$providers = &$settings['providers'] ?? null;

		if ( ! is_array( $providers ) ) {
			return $problems;
		}

		// A Groq key pasted into a provider field that expects an OpenAI or
		// Mistral key is rejected with an opaque 401 at request time.
		foreach ( array(
			'openai_api_key'  => array( 'openai', 'sk-' ),
			'mistral_api_key' => array( 'mistral', null ),
		) as $field => $expect ) {
			list( $owner, $prefix ) = $expect;

			$raw_value = (string) ( $providers[ $field ] ?? '' );
			$value     = trim( $raw_value );

			// 1. If empty, or already encrypted (sod:), or masked with bullets (•) or asterisks (*):
			// it is already safely configured and stored. Never reject masked values!
			if ( '' === $value || str_starts_with( $value, 'sod:' ) || str_contains( $value, '•' ) || str_contains( $value, '****' ) || str_contains( $value, '***' ) ) {
				continue;
			}

			// 2. Sanitize user input: strip outer quotes and "Bearer " prefix if user pasted them.
			$clean = trim( $value, "\"' \t\n\r\0\x0B" );
			if ( str_starts_with( strtolower( $clean ), 'bearer ' ) ) {
				$clean = trim( substr( $clean, 7 ) );
			}
			$providers[ $field ] = $clean;
			$value               = $clean;

			// 3. Check for obvious Groq key mix-up (gsk_...).
			if ( str_starts_with( strtolower( $value ), 'gsk' ) ) {
				$problems[] = sprintf(
					/* translators: 1: provider display name. */
					__( 'The %1$s field contains a Groq key (starts with "gsk_"), which %1$s cannot use. Please add this key to the Groq Key Pool below, and enter a valid %1$s key here.', 'ai-chatbot' ),
					ucfirst( $owner )
				);
				continue;
			}

			// 4. Validate OpenAI key format.
			if ( 'openai_api_key' === $field ) {
				$lower = strtolower( $value );
				if ( ! str_starts_with( $lower, 'sk-' ) ) {
					// Check if user accidentally pasted a 32-character hex Mistral key.
					if ( 32 === strlen( $value ) && ctype_xdigit( $value ) ) {
						$problems[] = __( 'The OpenAI API Key field appears to contain a Mistral key. If you are using Mistral, please select Mistral AI as your Embeddings Provider.', 'ai-chatbot' );
					} else {
						$problems[] = __( 'The OpenAI API Key format is invalid. OpenAI API keys start with "sk-" or "sk-proj-" (e.g., sk-proj-abc...). Please copy your key from platform.openai.com/api-keys.', 'ai-chatbot' );
					}
					continue;
				}

				if ( strlen( $value ) < 15 ) {
					$problems[] = __( 'The OpenAI API Key is too short to be valid. Please check your key from platform.openai.com/api-keys and try again.', 'ai-chatbot' );
					continue;
				}
			}

			// 5. Validate Mistral key format if provided.
			if ( 'mistral_api_key' === $field ) {
				if ( str_starts_with( strtolower( $value ), 'sk-' ) ) {
					$problems[] = __( 'The Mistral API Key field contains an OpenAI key (starts with "sk-"). Please enter a valid Mistral API key from console.mistral.ai.', 'ai-chatbot' );
					continue;
				}
			}
		}

		// Model must belong to the selected provider.
		$chat_provider = (string) ( $providers['chat_provider'] ?? '' );
		$chat_model    = (string) ( $providers['chat_model'] ?? '' );

		if ( '' !== $chat_provider && '' !== $chat_model ) {
			$model_info = self::find_model( $chat_model );

			if ( $model_info && $model_info['provider'] !== $chat_provider ) {
				$problems[] = sprintf(
					/* translators: 1: model name, 2: the provider that owns the model, 3: the provider currently selected. */
					__( 'The model "%1$s" belongs to %2$s, but the chat provider is set to %3$s. Pick a model from the selected provider, or switch the provider.', 'ai-chatbot' ),
					$chat_model,
					$model_info['provider'],
					$chat_provider
				);
			}
		}

		return $problems;
	}

	/**
	 * Validate language settings to ensure only supported languages are used.
	 *
	 * @param array $settings Incoming settings payload.
	 * @return array List of human-readable problems. Empty when valid.
	 */
	protected function validate_language_settings( array $settings ): array {
		$problems = array();
		if ( ! isset( $settings['language'] ) || ! is_array( $settings['language'] ) ) {
			return $problems;
		}

		$languages = $settings['language'];

		$allowed = \AIChatbot\Settings::ALLOWED_LANGUAGES;
		$allowed_list = implode( ', ', array_values( \AIChatbot\Settings::LANGUAGE_LABELS ) );

		// Validate fixed_language
		$fixed = $languages['fixed_language'] ?? '';
		if ( $fixed && ! in_array( $fixed, $allowed, true ) ) {
			/* translators: %s: list of allowed languages. */
			$problems[] = sprintf( __( 'The fixed language is not supported. Supported languages: %s.', 'ai-chatbot' ), $allowed_list );
		}

		// Validate default_language
		$default = $languages['default_language'] ?? '';
		if ( $default && ! in_array( $default, $allowed, true ) ) {
			/* translators: %s: list of allowed languages. */
			$problems[] = sprintf( __( 'The default language is not supported. Supported languages: %s.', 'ai-chatbot' ), $allowed_list );
		}

		// Validate fallback_language
		$fallback = $languages['fallback_language'] ?? '';
		if ( $fallback && ! in_array( $fallback, $allowed, true ) ) {
			/* translators: %s: list of allowed languages. */
			$problems[] = sprintf( __( 'The fallback language is not supported. Supported languages: %s.', 'ai-chatbot' ), $allowed_list );
		}

		// Validate switching_behavior
		$switching = $languages['switching_behavior'] ?? '';
		if ( $switching && ! in_array( $switching, array( 'auto_switch', 'sticky', 'fixed' ), true ) ) {
			$problems[] = __( 'The language switching behavior is not supported. Use auto_switch, sticky, or fixed.', 'ai-chatbot' );
		}

		// Validate enabled_languages
		$enabled = $languages['enabled_languages'] ?? array();
		if ( ! is_array( $enabled ) ) {
			$problems[] = __( 'Enabled languages must be an array.', 'ai-chatbot' );
		} else {
			$invalid = array_diff( $enabled, $allowed );
			if ( ! empty( $invalid ) ) {
				$problems[] = __( 'Enabled languages contain unsupported values. Only English, German, Nepali, and Spanish are allowed.', 'ai-chatbot' );
			}
			// Ensure at least one language is enabled
			if ( empty( $enabled ) ) {
				$problems[] = __( 'At least one language must be enabled.', 'ai-chatbot' );
			}
		}

		return $problems;
	}

	/**
	 * Determine which provider owns a model identifier.
	 *
	 * There is no static model catalogue in the registry, so this uses
	 * unambiguous model-family markers. Markers are checked in order, and the
	 * distinctive ones ("gpt-oss" before "gpt-") disambiguate provider-specific
	 * namespaces.
	 *
	 * @param string $model Model identifier.
	 * @return array|null [ 'provider' => string ] or null when the model cannot be attributed.
	 */
	protected static function find_model( string $model ): ?array {
		$model = strtolower( trim( $model ) );

		if ( '' === $model ) {
			return null;
		}

		// Markers that appear inside a longer identifier.
		$contains = array(
			'gpt-oss'        => 'groq',
			'llama'          => 'groq',
			'mixtral'        => 'groq',
			'gemma'          => 'groq',
			'qwen'           => 'groq',
			'deepseek'       => 'groq',
			'moonshotai'     => 'groq',
			'kimi'           => 'groq',
			'compound'       => 'groq',
			'text-embedding' => 'openai',
			'davinci'        => 'openai',
			'babbage'        => 'openai',
			'gpt-'           => 'openai',
			'gpt3'           => 'openai',
			'gpt4'           => 'openai',
			// Mistral serves both chat and embeddings.
			'mistral-embed'  => 'mistral',
			'codestral-embed' => 'mistral',
			'mistral-'       => 'mistral',
			'codestral-'     => 'mistral',
			'ministral-'     => 'mistral',
			'devstral'       => 'mistral',
			'pixtral'        => 'mistral',
			'open-mistral'   => 'mistral',
		);

		foreach ( $contains as $marker => $provider ) {
			if ( str_contains( $model, $marker ) ) {
				return array( 'provider' => $provider );
			}
		}

		// Short prefixes that are too weak to match anywhere in the string.
		foreach ( array( 'o1-', 'o3-', 'o4-' ) as $prefix ) {
			if ( str_starts_with( $model, $prefix ) ) {
				return array( 'provider' => 'openai' );
			}
		}

		return null;
	}

	/**
	 * Return the models the configured provider key can actually use.
	 *
	 * The settings screen previously offered a hardcoded list, so an account
	 * without the classic free-tier models (for example Groq keys that only see
	 * gpt-oss / qwen) could only pick a model that returns model_not_found.
	 * This queries the provider with a live key instead.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function list_provider_models( WP_REST_Request $request ): WP_REST_Response {
		$provider_id = sanitize_text_field( $request->get_param( 'provider' ) ?: 'groq' );

		$provider = ModelRegistry::get( $provider_id );

		if ( ! $provider ) {
			return $this->error(
				sprintf(
					/* translators: %s: provider identifier. */
					__( 'Unknown provider "%s".', 'ai-chatbot' ),
					$provider_id
				),
				'unknown_provider',
				400
			);
		}

		if ( ! method_exists( $provider, 'list_models' ) ) {
			return $this->error( __( 'This provider does not expose a model catalogue.', 'ai-chatbot' ), 'not_supported', 400 );
		}

		// Chat providers authenticate through the key pool; providers with their own
		// settings field (OpenAI, Mistral) read that field instead.
		$api_key = '';
		$own_fields = array(
			'openai'  => 'openai_api_key',
			'mistral' => 'mistral_api_key',
		);

		if ( isset( $own_fields[ $provider_id ] ) ) {
			$settings = Settings::get_all( (int) ( $request->get_param( 'agent_id' ) ?: 1 ), false );
			$api_key  = Encryption::decrypt( (string) ( $settings['providers'][ $own_fields[ $provider_id ] ] ?? '' ) );
		} else {
			$keys = KeyPool::get_active_keys_for_request( $provider_id );
			if ( ! empty( $keys ) ) {
				$api_key = (string) $keys[0]['key'];
			}
		}

		if ( '' === trim( $api_key ) ) {
			return $this->error(
				sprintf(
					/* translators: %s: provider display name. */
					__( 'No active API key is configured for %s, so the live model list cannot be retrieved.', 'ai-chatbot' ),
					$provider_id
				),
				'missing_key',
				400
			);
		}

		$models = $provider->list_models( $api_key );

		$chat       = array_values( array_unique( (array) ( $models['chat'] ?? array() ) ) );
		$embeddings = array_values( array_unique( (array) ( $models['embeddings'] ?? array() ) ) );

		// Providers fall back to a hardcoded list when the API call fails. That
		// list is exactly what caused unavailable models to be selectable, so
		// flag the response as not authoritative.
		$known_fallbacks = array(
			'llama-3.3-70b-versatile',
			'llama-3.1-8b-instant',
			'mixtral-8x7b-32768',
			'gemma2-9b-it',
			'gpt-4o',
			'gpt-4o-mini',
		);

		// A list made up entirely of hardcoded defaults means the API was not reached.
		$live = ! empty( $chat ) && ( count( array_diff( $chat, $known_fallbacks ) ) > 0 );

		return $this->success(
			array(
				'provider'         => $provider_id,
				'chat'             => $chat,
				'embeddings'       => $embeddings,
				'supports_chat'    => $provider->supports_chat(),
				'supports_embeddings' => $provider->supports_embeddings(),
				'live'             => $live,
				'notice'           => $live
					? ''
					: __( 'The provider could not be reached, so a generic model list is shown. Models may not work with your key.', 'ai-chatbot' ),
			)
		);
	}

	/**
	 * Get keys from pool.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_keys( WP_REST_Request $request ): WP_REST_Response {
		$provider = sanitize_text_field( $request->get_param( 'provider' ) ?: 'groq' );
		$keys     = KeyPool::get_keys( $provider );

		return $this->success( array( 'keys' => $keys ) );
	}

	/**
	 * Add key to pool.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function add_key( WP_REST_Request $request ): WP_REST_Response {
		$params   = $request->get_json_params() ?: array();
		$provider = sanitize_text_field( $params['provider'] ?? 'groq' );
		$label    = sanitize_text_field( $params['label'] ?? 'API Key' );
		$raw_key  = trim( $params['key'] ?? '' );
		$priority = (int) ( $params['priority'] ?? 0 );

		if ( empty( $raw_key ) ) {
			return $this->error( __( 'API Key cannot be empty.', 'ai-chatbot' ), 'empty_key', 400 );
		}

		$id = KeyPool::add_key( $provider, $label, $raw_key, $priority );
		if ( ! $id ) {
			return $this->error( __( 'Failed to save API key.', 'ai-chatbot' ), 'save_failed', 500 );
		}

		return $this->success(
			array(
				'message' => __( 'API key added to pool successfully.', 'ai-chatbot' ),
				'key_id'  => $id,
			),
			201
		);
	}

	/**
	 * Update key in pool.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function update_key( WP_REST_Request $request ): WP_REST_Response {
		$id     = (int) $request->get_param( 'id' );
		$params = $request->get_json_params() ?: array();

		$res = KeyPool::update_key( $id, $params );
		if ( ! $res ) {
			return $this->error( __( 'Failed to update key.', 'ai-chatbot' ), 'update_failed', 400 );
		}

		return $this->success( array( 'message' => __( 'Key updated successfully.', 'ai-chatbot' ) ) );
	}

	/**
	 * Delete key from pool.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function delete_key( WP_REST_Request $request ): WP_REST_Response {
		$id  = (int) $request->get_param( 'id' );
		$res = KeyPool::delete_key( $id );

		if ( ! $res ) {
			return $this->error( __( 'Failed to delete key.', 'ai-chatbot' ), 'delete_failed', 400 );
		}

		return $this->success( array( 'message' => __( 'Key removed from pool.', 'ai-chatbot' ) ) );
	}

	/**
	 * Test provider connection.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function test_key( WP_REST_Request $request ): WP_REST_Response {
		$params      = $request->get_json_params() ?: array();
		$provider_id = sanitize_text_field( $params['provider'] ?? 'groq' );
		$raw_key     = trim( (string) ( $params['key'] ?? '' ) );
		$agent_id    = (int) ( $params['agent_id'] ?? 1 );

		// Clean up user input: strip outer quotes and "Bearer " prefix.
		if ( ! empty( $raw_key ) ) {
			$raw_key = trim( $raw_key, "\"' \t\n\r\0\x0B" );
			if ( str_starts_with( strtolower( $raw_key ), 'bearer ' ) ) {
				$raw_key = trim( substr( $raw_key, 7 ) );
			}
		}

		// If key contains masked bullets (or is empty) and provider has its own stored settings:
		if ( empty( $raw_key ) || str_contains( $raw_key, '•' ) || str_contains( $raw_key, '****' ) || str_contains( $raw_key, '***' ) ) {
			if ( ! empty( $params['key_id'] ) ) {
				global $wpdb;
				$table = $wpdb->prefix . 'ai_api_keys';
				$enc   = $wpdb->get_var( $wpdb->prepare( "SELECT encrypted_key FROM {$table} WHERE id = %d", (int) $params['key_id'] ) );
				if ( $enc ) {
					$raw_key = Encryption::decrypt( $enc );
				}
			} elseif ( 'openai' === $provider_id || 'mistral' === $provider_id ) {
				$field    = ( 'openai' === $provider_id ) ? 'openai_api_key' : 'mistral_api_key';
				$settings = Settings::get_all( $agent_id, false );
				$raw_key  = Encryption::decrypt( (string) ( $settings['providers'][ $field ] ?? '' ) );
			}
		}

		if ( empty( $raw_key ) ) {
			return $this->error(
				sprintf(
					/* translators: %s: provider name */
					__( 'No API key provided or configured for %s. Please enter a valid API key to test.', 'ai-chatbot' ),
					ucfirst( $provider_id )
				),
				'missing_key',
				400
			);
		}

		$provider = ModelRegistry::get( $provider_id );
		if ( ! $provider ) {
			return $this->error( __( 'Invalid provider specified.', 'ai-chatbot' ), 'invalid_provider', 400 );
		}

		$result = $provider->test_connection( $raw_key );

		if ( ! $result['success'] ) {
			return $this->error( $result['message'], 'connection_failed', 400 );
		}

		return $this->success( $result );
	}

	/**
	 * Validate email notification settings.
	 *
	 * @param array $settings Incoming settings payload.
	 * @return array List of human-readable problems. Empty when valid.
	 */
	protected function validate_email_settings( array &$settings ): array {
		$problems = array();
		$email    = &$settings['email'] ?? null;

		if ( ! is_array( $email ) ) {
			return $problems;
		}

		$recipient_type = $email['recipient_type'] ?? 'admin';

		if ( 'custom' === $recipient_type ) {
			$custom_email = trim( (string) ( $email['custom_email'] ?? '' ) );
			if ( empty( $custom_email ) || ! is_email( $custom_email ) ) {
				$problems[] = __( 'Please provide a valid custom email address for notifications, or select the WordPress Administration Email.', 'ai-chatbot' );
			} else {
				$email['custom_email']    = sanitize_email( $custom_email );
				$email['notification_to'] = $email['custom_email'];
			}
		} else {
			$email['recipient_type']  = 'admin';
			$email['notification_to'] = (string) get_option( 'admin_email' );
		}

		$email['delivery_method'] = 'wp_mail';

		return $problems;
	}

	/**
	 * Test email delivery using native WordPress mail.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function test_email( WP_REST_Request $request ): WP_REST_Response {
		$params         = $request->get_json_params() ?: array();
		$recipient_type = sanitize_text_field( $params['recipient_type'] ?? 'admin' );
		$admin_email    = (string) get_option( 'admin_email' );

		// Determine target recipient.
		if ( 'custom' === $recipient_type ) {
			$custom_email = sanitize_email( $params['custom_email'] ?? ( $params['to_email'] ?? '' ) );
			if ( empty( $custom_email ) || ! is_email( $custom_email ) ) {
				return $this->error(
					__( 'Please provide a valid custom email address to send the test email.', 'ai-chatbot' ),
					'invalid_email',
					400
				);
			}
			$to_email = $custom_email;
		} else {
			$to_email = $admin_email;
		}

		if ( empty( $to_email ) || ! is_email( $to_email ) ) {
			return $this->error(
				__( 'The WordPress Administration Email is not configured or is invalid. Please check WordPress → Settings → General.', 'ai-chatbot' ),
				'missing_admin_email',
				400
			);
		}

		$site_name = get_bloginfo( 'name' );
		$subject   = sprintf(
			/* translators: %s: site name */
			__( '[%s] Test Email from AI Chatbot', 'ai-chatbot' ),
			$site_name
		);

		$date_time = current_time( 'mysql' );
		$html      = <<<HTML
<div style="font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 24px; border: 1px solid #e2e8f0; border-radius: 8px;">
	<h2 style="color: #1e293b; margin-top: 0;">✓ Test Email Delivered Successfully</h2>
	<p style="color: #475569; font-size: 15px; line-height: 1.6;">
		Congratulations! Your WordPress AI Chatbot email notification system is configured and working properly using WordPress native mail (<code>wp_mail</code>).
	</p>
	<table style="width: 100%; border-collapse: collapse; margin-top: 16px; margin-bottom: 16px; font-size: 14px;">
		<tr>
			<td style="padding: 8px 12px; background: #f8fafc; border: 1px solid #e2e8f0; font-weight: 600; width: 140px;">Website:</td>
			<td style="padding: 8px 12px; border: 1px solid #e2e8f0;">{$site_name}</td>
		</tr>
		<tr>
			<td style="padding: 8px 12px; background: #f8fafc; border: 1px solid #e2e8f0; font-weight: 600;">Recipient:</td>
			<td style="padding: 8px 12px; border: 1px solid #e2e8f0;">{$to_email}</td>
		</tr>
		<tr>
			<td style="padding: 8px 12px; background: #f8fafc; border: 1px solid #e2e8f0; font-weight: 600;">Delivery Method:</td>
			<td style="padding: 8px 12px; border: 1px solid #e2e8f0;">WordPress Native (wp_mail)</td>
		</tr>
		<tr>
			<td style="padding: 8px 12px; background: #f8fafc; border: 1px solid #e2e8f0; font-weight: 600;">Timestamp:</td>
			<td style="padding: 8px 12px; border: 1px solid #e2e8f0;">{$date_time}</td>
		</tr>
	</table>
	<p style="color: #64748b; font-size: 13px; margin-bottom: 0;">
		You will automatically receive notifications at this address when visitors submit contact forms, request human handoff, or generate new leads.
	</p>
</div>
HTML;

		$provider = new \AIChatbot\Email\WpMailProvider();
		$send_res = $provider->send( $to_email, $subject, $html );

		if ( ! $send_res['success'] ) {
			return $this->error(
				__( 'Unable to send the test email. Your WordPress hosting/mail configuration may need attention.', 'ai-chatbot' ),
				'send_failed',
				400,
				array(
					'detail' => $send_res['error'] ?? '',
				)
			);
		}

		return $this->success(
			array(
				'message'   => sprintf(
					/* translators: %s: recipient email address */
					__( 'Test email sent successfully to %s.', 'ai-chatbot' ),
					esc_html( $to_email )
				),
				'recipient' => $to_email,
			)
		);
	}

	/**
	 * Test Guardrails panel runner.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function test_guardrails( WP_REST_Request $request ): WP_REST_Response {
		$params   = $request->get_json_params() ?: array();
		$message  = trim( $params['message'] ?? '' );
		$agent_id = (int) ( $params['agent_id'] ?? 1 );

		if ( empty( $message ) ) {
			return $this->error( __( 'Sample message cannot be empty.', 'ai-chatbot' ), 'empty_message', 400 );
		}

		global $wpdb;
		$settings   = Settings::get_all( $agent_id, false );
		$guardrails = $settings['guardrails'] ?? array();

		$table_sources = $wpdb->prefix . 'ai_knowledge_sources';
		$biz_source    = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT content FROM {$table_sources} WHERE agent_id = %d AND type = 'business_info'",
				$agent_id
			),
			ARRAY_A
		);
		$biz_info                   = ! empty( $biz_source['content'] ) ? json_decode( $biz_source['content'], true ) : array();
		$company_name               = $biz_info['business_name'] ?? get_bloginfo( 'name' );
		$guardrails['company_name'] = $company_name;
		$guardrails['industry']     = $biz_info['industry'] ?? 'General Business';

		$eval = TopicGuardrail::evaluate( $message, $guardrails );

		$simulated_reply = $eval['is_allowed']
			? __( 'Allowed: This question is within scope and will be answered using verified knowledge base context.', 'ai-chatbot' )
			: ( $eval['decline_message'] ?? TopicGuardrail::get_decline_message( $guardrails, 1, 'auto', $message ) );

		return $this->success(
			array(
				'verdict'         => $eval['verdict'],
				'is_allowed'      => $eval['is_allowed'],
				'reason'          => $eval['reason'],
				'simulated_reply' => $simulated_reply,
			)
		);
	}

	/**
	 * Get link registry status and validation logs.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_links_status( WP_REST_Request $request ): WP_REST_Response {
		$report = LinkRegistry::get_status_report();
		return $this->success( $report );
	}

	/**
	 * Trigger immediate rebuild and validation of links.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function rebuild_links( WP_REST_Request $request ): WP_REST_Response {
		$params   = $request->get_json_params() ?: array();
		$agent_id = (int) ( $params['agent_id'] ?? 1 );

		$report = LinkRegistry::rebuild_registry( $agent_id );
		return $this->success( $report );
	}
}

