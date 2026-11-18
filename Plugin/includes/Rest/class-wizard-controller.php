<?php
/**
 * Setup Wizard REST Controller.
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
use AIChatbot\Knowledge\KnowledgeManager;
use AIChatbot\Knowledge\Indexer;
use AIChatbot\Knowledge\Retriever;
use AIChatbot\Prompt\SystemPromptBuilder;
use AIChatbot\Wizard\SetupWizard;
use AIChatbot\Wizard\SiteCrawler;

defined( 'ABSPATH' ) || exit;

/**
 * Class WizardController.
 */
class WizardController extends RestController {

	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		// Onboarding state retrieval.
		register_rest_route(
			self::NAMESPACE,
			'/wizard/state',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_state' ),
				'permission_callback' => array( $this, 'check_admin_permission' ),
			)
		);

		// Step progress persistence.
		register_rest_route(
			self::NAMESPACE,
			'/wizard/save-step',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'save_step' ),
				'permission_callback' => array( $this, 'check_admin_permission' ),
			)
		);

		// Real website scanning & knowledge building.
		register_rest_route(
			self::NAMESPACE,
			'/wizard/scan-knowledge',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'scan_knowledge' ),
				'permission_callback' => array( $this, 'check_admin_permission' ),
			)
		);

		// Live test chat endpoint during wizard onboarding.
		register_rest_route(
			self::NAMESPACE,
			'/wizard/test-chat',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'test_chat' ),
				'permission_callback' => array( $this, 'check_admin_permission' ),
			)
		);

		// Complete setup & unlock dashboard.
		register_rest_route(
			self::NAMESPACE,
			'/wizard/complete',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'complete_wizard' ),
				'permission_callback' => array( $this, 'check_admin_permission' ),
			)
		);

		// Reset wizard progress for re-running.
		register_rest_route(
			self::NAMESPACE,
			'/wizard/reset',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'reset_wizard' ),
				'permission_callback' => array( $this, 'check_admin_permission' ),
			)
		);

		// Validate embedding API key.
		register_rest_route(
			self::NAMESPACE,
			'/wizard/validate-key',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'validate_embedding_key' ),
				'permission_callback' => array( $this, 'check_admin_permission' ),
			)
		);

		// Legacy / One-paste wizard endpoints (preserved for backwards compatibility).
		register_rest_route(
			self::NAMESPACE,
			'/wizard/start',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'start_wizard' ),
				'permission_callback' => array( $this, 'check_admin_permission' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/wizard/status',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_status' ),
				'permission_callback' => array( $this, 'check_admin_permission' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/wizard/apply',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'apply_wizard' ),
				'permission_callback' => array( $this, 'check_admin_permission' ),
			)
		);
	}

	/**
	 * Get current wizard state, detected providers, agent, and existing settings.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_state( WP_REST_Request $request ): WP_REST_Response {
		global $wpdb;

		$agent_id          = 1;
		$onboarding_status = get_option( 'ai_chatbot_onboarding_completed', 'not_started' );
		$saved_state       = get_option( 'ai_chatbot_onboarding_state', array() );

		// Load agent row.
		$table_agents = $wpdb->prefix . 'ai_agents';
		$agent        = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table_agents} WHERE id = %d", $agent_id ), ARRAY_A ) ?: array();

		// Load settings.
		$settings = Settings::get_all( $agent_id, true );

		// Check detected providers.
		$groq_configured    = KeyPool::has_configured_key( 'groq' );
		$openai_settings_key = Encryption::decrypt( (string) ( Settings::get_all( $agent_id, false )['providers']['openai_api_key'] ?? '' ) );
		$openai_configured  = ! empty( trim( $openai_settings_key ) ) || KeyPool::has_configured_key( 'openai' );
		$mistral_key        = Encryption::decrypt( (string) ( Settings::get_all( $agent_id, false )['providers']['mistral_api_key'] ?? '' ) );
		$mistral_configured = ! empty( trim( $mistral_key ) );

		// Count existing sources.
		$table_sources = $wpdb->prefix . 'ai_knowledge_sources';
		$sources_count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table_sources} WHERE agent_id = %d", $agent_id ) );

		$detected = array(
			'groq_configured'       => $groq_configured,
			'openai_configured'     => $openai_configured,
			'mistral_configured'    => $mistral_configured,
			'active_provider'       => $settings['providers']['chat_provider'] ?? 'groq',
			'embeddings_provider'   => $settings['providers']['embeddings_provider'] ?? 'mistral',
			'embeddings_model'      => $settings['providers']['embeddings_model'] ?? 'mistral-embed',
			'embeddings_configured' => ( 'openai' === ( $settings['providers']['embeddings_provider'] ?? 'mistral' ) ) ? $openai_configured : $mistral_configured,
			'sources_count'         => $sources_count,
			'agent'                 => $agent,
			'settings'              => $settings,
			'site_name'             => get_bloginfo( 'name' ),
			'home_url'              => home_url(),
			'admin_email'           => get_option( 'admin_email' ),
		);

		return $this->success(
			array(
				'status'          => $onboarding_status,
				'wizardCompleted' => 'completed' === $onboarding_status || (bool) get_option( 'ai_chatbot_wizard_completed', false ),
				'saved_state'     => is_array( $saved_state ) ? $saved_state : array(),
				'detected'        => $detected,
			)
		);
	}

	/**
	 * Save onboarding progress for a specific step.
	 *
	 * Persists both the wizard step state and synchronizes with underlying plugin settings.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function save_step( WP_REST_Request $request ): WP_REST_Response {
		global $wpdb;

		$params = $request->get_json_params();
		if ( empty( $params ) ) {
			$params = $request->get_params();
			if ( empty( $params ) ) {
				$raw = $request->get_body();
				if ( ! empty( $raw ) ) {
					$params = json_decode( $raw, true ) ?: array();
				}
			}
		}
		$step     = max( 1, min( 7, (int) ( $params['step'] ?? 1 ) ) );
		$config   = is_array( $params['config'] ?? null ) ? $params['config'] : array();
		$agent_id = 1;

		$existing_state = get_option( 'ai_chatbot_onboarding_state', array() );
		if ( ! is_array( $existing_state ) ) {
			$existing_state = array();
		}

		$current_config = is_array( $existing_state['config'] ?? null ) ? $existing_state['config'] : array();
		$merged_config  = array_merge( $current_config, $config );

		$completed_steps = is_array( $existing_state['completed_steps'] ?? null ) ? $existing_state['completed_steps'] : array();
		if ( ! in_array( $step, $completed_steps, true ) ) {
			$completed_steps[] = $step;
		}

		$new_state = array(
			'current_step'    => $step,
			'completed_steps' => array_values( array_unique( $completed_steps ) ),
			'config'          => $merged_config,
			'updated_at'      => current_time( 'mysql' ),
		);

		update_option( 'ai_chatbot_onboarding_state', $new_state, false );

		// Mark status as in_progress if not already completed.
		$current_status = get_option( 'ai_chatbot_onboarding_completed', 'not_started' );
		if ( 'completed' !== $current_status ) {
			update_option( 'ai_chatbot_onboarding_completed', 'in_progress', false );
		}

		// Synchronize specific step configuration directly into database.
		$this->persist_step_settings( $step, $merged_config, $agent_id );

		return $this->success(
			array(
				'step'            => $step,
				'saved'           => true,
				'completed_steps' => $new_state['completed_steps'],
			)
		);
	}

	/**
	 * Persist step-specific values into the core database and settings.
	 *
	 * @param int   $step Step number.
	 * @param array $config Merged configuration array.
	 * @param int   $agent_id Agent ID.
	 * @return void
	 */
	protected function persist_step_settings( int $step, array $config, int $agent_id = 1 ): void {
		global $wpdb;

		// 1. AI Connection (Step 2)
		if ( 2 === $step || isset( $config['provider'] ) || isset( $config['embeddingsProvider'] ) ) {
			$provider            = sanitize_key( $config['provider'] ?? 'groq' );
			$embeddings_provider = sanitize_key( $config['embeddingsProvider'] ?? ( $config['embeddings_provider'] ?? 'mistral' ) );
			$embeddings_model    = sanitize_text_field(
				$config['embeddingsModel'] ?? ( $config['embeddings_model'] ?? ( 'mistral' === $embeddings_provider ? 'mistral-embed' : 'text-embedding-3-small' ) )
			);

			$providers_save = array(
				'chat_provider'       => $provider,
				'embeddings_provider' => $embeddings_provider,
				'embeddings_model'    => $embeddings_model,
			);

			// Handle Chat Provider API Key.
			if ( ! empty( $config['apiKey'] ) && ! str_contains( (string) $config['apiKey'], '••••' ) && ! str_contains( (string) $config['apiKey'], '****' ) ) {
				$raw_key = trim( (string) $config['apiKey'] );

				if ( 'groq' === $provider ) {
					// Add Groq key to KeyPool if not already present.
					$existing = KeyPool::get_keys( 'groq' );
					$has_key  = false;
					foreach ( $existing as $k ) {
						if ( Encryption::get_last_four( $raw_key ) === $k['key_last_four'] ) {
							$has_key = true;
							break;
						}
					}
					if ( ! $has_key ) {
						KeyPool::add_key( 'groq', 'Setup Wizard Key', $raw_key, 0 );
					}
				} elseif ( 'openai' === $provider ) {
					$providers_save['openai_api_key'] = $raw_key;
				} elseif ( 'mistral' === $provider ) {
					$providers_save['mistral_api_key'] = $raw_key;
				}
			}

			// Handle Embeddings Provider API Key.
			$raw_emb_key = '';
			if ( ! empty( $config['embeddingsApiKey'] ) && ! str_contains( (string) $config['embeddingsApiKey'], '••••' ) && ! str_contains( (string) $config['embeddingsApiKey'], '****' ) ) {
				$raw_emb_key = trim( (string) $config['embeddingsApiKey'] );
			} elseif ( ! empty( $config['useSameKey'] ) || ( empty( $config['embeddingsApiKey'] ) && $provider === $embeddings_provider ) ) {
				// Re-use Chat API key if user enabled same key or if both providers match.
				if ( ! empty( $config['apiKey'] ) && ! str_contains( (string) $config['apiKey'], '••••' ) && ! str_contains( (string) $config['apiKey'], '****' ) ) {
					$raw_emb_key = trim( (string) $config['apiKey'] );
				}
			}

			if ( ! empty( $raw_emb_key ) ) {
				if ( 'openai' === $embeddings_provider ) {
					$providers_save['openai_api_key'] = $raw_emb_key;
				} elseif ( 'mistral' === $embeddings_provider ) {
					$providers_save['mistral_api_key'] = $raw_emb_key;
				}
			}

			Settings::save_all( $agent_id, array( 'providers' => $providers_save ) );
		}

		// 2. Chatbot Identity (Step 3)
		if ( 3 === $step || isset( $config['agentName'] ) ) {
			$table_agents = $wpdb->prefix . 'ai_agents';
			$agent_update = array();

			if ( ! empty( $config['agentName'] ) ) {
				$agent_update['name'] = sanitize_text_field( $config['agentName'] );
			}
			if ( ! empty( $config['greeting'] ) ) {
				$agent_update['greeting'] = sanitize_textarea_field( $config['greeting'] );
			}
			if ( ! empty( $config['tone'] ) ) {
				$agent_update['tone'] = sanitize_text_field( $config['tone'] );
			}
			if ( ! empty( $config['role'] ) ) {
				$agent_update['role'] = sanitize_textarea_field( $config['role'] );
			}

			if ( ! empty( $agent_update ) ) {
				$agent_update['updated_at'] = current_time( 'mysql' );
				$wpdb->update( $table_agents, $agent_update, array( 'id' => $agent_id ) );
			}

			// Language configuration.
			if ( ! empty( $config['language'] ) ) {
				$lang_code = sanitize_key( $config['language'] );
				$mode      = ( 'auto' === $lang_code ) ? 'auto' : 'fixed';
				$fixed     = ( 'auto' === $lang_code ) ? 'en' : $lang_code;

				Settings::save_all(
					$agent_id,
					array(
						'language' => array(
							'mode'           => $mode,
							'fixed_language' => $fixed,
						),
					)
				);
			}
		}

		// 3. Chatbot Appearance (Step 5)
		if ( 5 === $step || isset( $config['primaryColor'] ) ) {
			$theme_update = array();
			if ( ! empty( $config['primaryColor'] ) ) {
				$color                          = sanitize_hex_color( $config['primaryColor'] ) ?: '#2563eb';
				$theme_update['primary_color']   = $color;
				$theme_update['user_bubble_bg']  = $color;
			}
			if ( ! empty( $config['launcherIcon'] ) ) {
				$theme_update['launcher_icon'] = sanitize_key( $config['launcherIcon'] );
			}

			if ( ! empty( $theme_update ) ) {
				Settings::save_all( $agent_id, array( 'theme' => $theme_update ) );
			}

			if ( ! empty( $config['position'] ) ) {
				Settings::save_all(
					$agent_id,
					array(
						'placement' => array(
							'position' => sanitize_key( $config['position'] ),
						),
					)
				);
			}
		}

		// 4. Behavior & Guardrails (Step 6)
		if ( 6 === $step || isset( $config['strictness'] ) ) {
			$guard_update = array();
			if ( isset( $config['strictness'] ) ) {
				$guard_update['strictness'] = sanitize_key( $config['strictness'] );
			}
			if ( isset( $config['allowedTopics'] ) ) {
				$guard_update['allowed_topics'] = sanitize_textarea_field( $config['allowedTopics'] );
			}
			if ( isset( $config['declineMessage'] ) ) {
				$guard_update['decline_message'] = sanitize_textarea_field( $config['declineMessage'] );
			}

			if ( ! empty( $guard_update ) ) {
				Settings::save_all( $agent_id, array( 'guardrails' => $guard_update ) );
			}

			// Token limits based on answer length.
			if ( ! empty( $config['answerLength'] ) ) {
				$tokens_map = array(
					'short'  => 350,
					'medium' => 800,
					'long'   => 1500,
				);
				$tokens     = $tokens_map[ $config['answerLength'] ] ?? 800;
				Settings::save_all(
					$agent_id,
					array(
						'providers' => array(
							'max_tokens' => $tokens,
						),
					)
				);
			}

			if ( isset( $config['notifyLead'] ) ) {
				Settings::save_all(
					$agent_id,
					array(
						'email' => array(
							'enabled' => (bool) $config['notifyLead'],
						),
					)
				);
			}
		}
	}

	/**
	 * Real knowledge scanning & indexing of website pages and posts.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function scan_knowledge( WP_REST_Request $request ): WP_REST_Response {
		global $wpdb;

		$agent_id = 1;
		$params   = $request->get_json_params() ?: array();
		$scope    = sanitize_key( $params['scope'] ?? 'site' );

		$pages = array();

		// Fetch published WordPress pages and posts (German and English only).
		$post_args = array(
			'post_type'      => array( 'page', 'post' ),
			'post_status'    => 'publish',
			'posts_per_page' => 8,
			'orderby'        => 'date',
			'order'          => 'DESC',
		);
		if ( function_exists( 'pll_get_post_language' ) ) {
			$post_args['lang'] = 'de,en';
		}
		$posts = get_posts( $post_args );

		foreach ( $posts as $p ) {
			if ( function_exists( 'pll_get_post_language' ) ) {
				$plang = pll_get_post_language( $p->ID );
				if ( ! empty( $plang ) && ! in_array( $plang, array( 'de', 'en' ), true ) ) {
					continue;
				}
			} elseif ( function_exists( 'wpml_get_language_information' ) ) {
				$winfo = wpml_get_language_information( null, $p->ID );
				$wlang = ! empty( $winfo['language_code'] ) ? $winfo['language_code'] : '';
				if ( ! empty( $wlang ) && ! in_array( $wlang, array( 'de', 'en' ), true ) ) {
					continue;
				}
			}
			$url   = get_permalink( $p->ID );
			$title = get_the_title( $p->ID );
			$text  = wp_strip_all_tags( $p->post_content );

			if ( ! empty( $text ) && mb_strlen( $text ) > 40 ) {
				$pages[] = array(
					'url'   => $url,
					'title' => $title ?: $url,
					'text'  => mb_substr( $text, 0, 3000, 'UTF-8' ),
				);
			}
		}

		// Fallback: if fewer than 2 posts, crawl the front page.
		if ( count( $pages ) < 2 ) {
			$crawled = SiteCrawler::crawl( home_url(), 4, 3000 );
			foreach ( $crawled as $c ) {
				if ( ! empty( $c['text'] ) ) {
					$pages[] = array(
						'url'   => $c['url'] ?? home_url(),
						'title' => $c['title'] ?? get_bloginfo( 'name' ),
						'text'  => mb_substr( $c['text'], 0, 3000, 'UTF-8' ),
					);
				}
			}
		}

		if ( empty( $pages ) ) {
			return $this->error(
				__( 'No readable website content found to index. You can add knowledge sources manually from the Knowledge menu.', 'ai-chatbot' ),
				'no_content_found',
				400
			);
		}

		$table_sources = $wpdb->prefix . 'ai_knowledge_sources';
		$credentials   = $this->resolve_embeddings_credentials( $agent_id );
		$imported      = array();
		$total_chunks  = 0;

		foreach ( $pages as $item ) {
			$url   = SiteCrawler::normalize_url( $item['url'] ) ?: esc_url_raw( $item['url'] );
			$title = sanitize_text_field( $item['title'] );
			$text  = sanitize_textarea_field( $item['text'] );

			// Check if source already exists.
			$existing_id = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT id FROM {$table_sources} WHERE agent_id = %d AND source_url = %s LIMIT 1",
					$agent_id,
					$url
				)
			);

			if ( $existing_id ) {
				$source_id = (int) $existing_id;
			} else {
				$source_id = KnowledgeManager::add_source(
					array(
						'agent_id'   => $agent_id,
						'type'       => 'webpage',
						'title'      => $title,
						'source_url' => $url,
						'content'    => $text,
						'metadata'   => array(
							'imported_from' => 'setup_wizard',
							'page_url'      => $url,
						),
					)
				);
			}

			if ( ! $source_id ) {
				continue;
			}

			$chunks = 1;
			if ( $credentials ) {
				$index_res = Indexer::index_source(
					$source_id,
					$credentials['provider'],
					$credentials['model'],
					$credentials['key']
				);
				if ( ! empty( $index_res['success'] ) ) {
					$chunks = (int) $index_res['chunks'];
				}
			}

			$total_chunks += $chunks;
			$imported[]    = array(
				'id'     => $source_id,
				'title'  => $title,
				'url'    => $url,
				'chunks' => $chunks,
			);
		}

		return $this->success(
			array(
				'message'  => sprintf(
					/* translators: 1: page count, 2: chunk count */
					__( 'Knowledge base built: %1$d pages scanned, %2$d knowledge chunks ready.', 'ai-chatbot' ),
					count( $imported ),
					$total_chunks
				),
				'count'    => count( $imported ),
				'chunks'   => $total_chunks,
				'sources'  => $imported,
			)
		);
	}

	/**
	 * Run a live test question during wizard Step 7.
	 *
	 * Calls the real AI provider pipeline with the configured persona and settings.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function test_chat( WP_REST_Request $request ): WP_REST_Response {
		global $wpdb;

		$params = $request->get_json_params();
		if ( empty( $params ) ) {
			$params = $request->get_params();
			if ( empty( $params ) ) {
				$raw = $request->get_body();
				if ( ! empty( $raw ) ) {
					$params = json_decode( $raw, true ) ?: array();
				}
			}
		}
		$message  = trim( sanitize_textarea_field( $params['message'] ?? 'What can you help visitors with?' ) );
		$agent_id = 1;

		if ( empty( $message ) ) {
			return $this->error( __( 'Please enter a test question.', 'ai-chatbot' ), 'empty_message', 400 );
		}

		$settings = Settings::get_all( $agent_id, false );

		// 1. Resolve Provider.
		$provider_id = sanitize_key( $settings['providers']['chat_provider'] ?? 'groq' );
		$provider    = ModelRegistry::get( $provider_id );

		if ( ! $provider ) {
			return $this->error(
				__( 'The selected AI provider is unavailable. Please check Step 2.', 'ai-chatbot' ),
				'provider_unavailable',
				400
			);
		}

		// 2. Resolve API Key.
		$api_key = '';
		if ( 'groq' === $provider_id ) {
			$keys    = KeyPool::get_active_keys_for_request( 'groq' );
			$api_key = ! empty( $keys[0]['key'] ) ? $keys[0]['key'] : '';
		} elseif ( 'openai' === $provider_id ) {
			$api_key = Encryption::decrypt( (string) ( $settings['providers']['openai_api_key'] ?? '' ) );
		} elseif ( 'mistral' === $provider_id ) {
			$api_key = Encryption::decrypt( (string) ( $settings['providers']['mistral_api_key'] ?? '' ) );
		}

		// Also check request payload for freshly typed key.
		if ( empty( $api_key ) && ! empty( $params['apiKey'] ) ) {
			$api_key = trim( (string) $params['apiKey'] );
		}

		if ( empty( $api_key ) ) {
			return $this->error(
				__( 'No AI API key found. Please enter and validate your API key in Step 2 before testing.', 'ai-chatbot' ),
				'missing_key',
				400
			);
		}

		// 3. Load Agent persona.
		$table_agents = $wpdb->prefix . 'ai_agents';
		$agent        = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table_agents} WHERE id = %d", $agent_id ), ARRAY_A ) ?: array();

		// 4. Retrieve RAG context if sources and embeddings credentials exist.
		$retrieved_chunks = array();
		try {
			$creds = $this->resolve_embeddings_credentials( $agent_id );
			if ( $creds && ! empty( $creds['key'] ) ) {
				$retrieval_res    = Retriever::retrieve(
					$message,
					$agent_id,
					$creds['provider'],
					$creds['model'],
					$creds['key'],
					4
				);
				$retrieved_chunks = $retrieval_res['chunks'] ?? array();
			}
		} catch ( \Throwable $e ) {
			$retrieved_chunks = array();
		}

		// 5. Build System Prompt.
		$response_config          = $settings['response_behavior'] ?? ( $settings['response'] ?? array() );
		$is_explicit_link_request = ChatController::is_explicit_link_request( $message );
		$biz_info                 = array( 'business_name' => get_bloginfo( 'name' ), 'website' => home_url() );

		$system_prompt = SystemPromptBuilder::build(
			$agent,
			$biz_info,
			$retrieved_chunks,
			$settings['guardrails'] ?? array(),
			array(),
			$settings['language']['fixed_language'] ?? 'en',
			$response_config,
			$is_explicit_link_request
		);

		// 6. Call model.
		$chat_messages = array(
			array( 'role' => 'system', 'content' => $system_prompt ),
			array( 'role' => 'user', 'content' => $message ),
		);

		$model_options = array(
			'model'       => $settings['providers']['chat_model'] ?? ( 'openai' === $provider_id ? 'gpt-4o-mini' : 'llama-3.3-70b-versatile' ),
			'temperature' => 0.7,
			'max_tokens'  => (int) ( $settings['providers']['max_tokens'] ?? 800 ),
		);

		try {
			$response = $provider->chat( $chat_messages, $model_options, $api_key );
		} catch ( \Throwable $e ) {
			return $this->error(
				sprintf(
					/* translators: %s: error message */
					__( 'AI connection test failed: %s', 'ai-chatbot' ),
					$e->getMessage()
				),
				'ai_error',
				400
			);
		}

		if ( empty( $response['content'] ) ) {
			return $this->error(
				__( 'The AI model returned an empty response. Please check your credentials.', 'ai-chatbot' ),
				'empty_response',
				400
			);
		}

		$sanitized_reply = ChatController::sanitize_response_urls(
			(string) $response['content'],
			$retrieved_chunks,
			$biz_info,
			$response_config,
			$is_explicit_link_request
		);

		return $this->success(
			array(
				'reply'    => $sanitized_reply,
				'provider' => $provider_id,
				'model'    => $model_options['model'],
			)
		);
	}

	/**
	 * Mark setup wizard as completed and unlock dashboard permanently.
	 *
	 * @return WP_REST_Response
	 */
	public function complete_wizard( WP_REST_Request $request ): WP_REST_Response {
		$params = $request->get_json_params();
		if ( empty( $params ) ) {
			$params = $request->get_params();
			if ( empty( $params ) ) {
				$raw = $request->get_body();
				if ( ! empty( $raw ) ) {
					$params = json_decode( $raw, true ) ?: array();
				}
			}
		}
		$agent_id = 1;

		// Persist any final config passed in.
		if ( ! empty( $params['config'] ) && is_array( $params['config'] ) ) {
			$this->persist_step_settings( 7, $params['config'], $agent_id );
		}

		// Permanent completion markers.
		update_option( 'ai_chatbot_onboarding_completed', 'completed', false );
		update_option( 'ai_chatbot_wizard_completed', '1', false );
		delete_option( 'ai_chatbot_do_setup_redirect' );

		// Update agent status to online.
		global $wpdb;
		$table_agents = $wpdb->prefix . 'ai_agents';
		$wpdb->update(
			$table_agents,
			array( 'status' => 'online', 'updated_at' => current_time( 'mysql' ) ),
			array( 'id' => $agent_id )
		);

		return $this->success(
			array(
				'message'       => __( '🎉 Your AI Chatbot is ready! Your chatbot has been configured successfully and is now ready for your website visitors.', 'ai-chatbot' ),
				'dashboard_url' => admin_url( 'admin.php?page=ai-chatbot&onboarding_success=1' ),
			)
		);
	}

	/**
	 * Reset onboarding state to allow re-running wizard from dashboard.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function reset_wizard( WP_REST_Request $request ): WP_REST_Response {
		$existing_state = get_option( 'ai_chatbot_onboarding_state', array() );
		if ( is_array( $existing_state ) ) {
			$existing_state['current_step'] = 1;
			update_option( 'ai_chatbot_onboarding_state', $existing_state, false );
		}

		return $this->success(
			array(
				'message' => __( 'Setup wizard reset. Ready to re-configure.', 'ai-chatbot' ),
			)
		);
	}

	/**
	 * Validate an embedding API key supplied during wizard setup.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function validate_embedding_key( WP_REST_Request $request ): WP_REST_Response {
		$params      = $request->get_json_params() ?: array();
		$raw_key     = trim( (string) ( $params['api_key'] ?? '' ) );
		$provider_id = sanitize_key( $params['provider'] ?? 'openai' );
		$agent_id    = (int) ( $params['agent_id'] ?? 1 );

		if ( ! empty( $raw_key ) ) {
			$raw_key = trim( $raw_key, "\"' \t\n\r\0\x0B" );
			if ( str_starts_with( strtolower( $raw_key ), 'bearer ' ) ) {
				$raw_key = trim( substr( $raw_key, 7 ) );
			}
		}

		if ( empty( $raw_key ) || str_contains( $raw_key, '•' ) || str_contains( $raw_key, '****' ) ) {
			$settings = Settings::get_all( $agent_id, false );
			$field    = ( 'openai' === $provider_id ) ? 'openai_api_key' : 'mistral_api_key';
			$raw_key  = Encryption::decrypt( (string) ( $settings['providers'][ $field ] ?? '' ) );
		}

		if ( empty( $raw_key ) ) {
			return $this->error(
				__( 'An embedding API key is required to enable semantic search for your chatbot.', 'ai-chatbot' ),
				'missing_key',
				400
			);
		}

		$provider = ModelRegistry::get( $provider_id );

		if ( ! $provider || ! $provider->supports_embeddings() ) {
			return $this->error(
				__( 'The selected provider does not support embeddings.', 'ai-chatbot' ),
				'unsupported_provider',
				400
			);
		}

		$model = ( 'openai' === $provider_id ) ? 'text-embedding-3-small' : 'mistral-embed';

		try {
			$conn_res = $provider->test_connection( $raw_key );
			if ( empty( $conn_res['success'] ) ) {
				$msg = ! empty( $conn_res['message'] ) ? $conn_res['message'] : __( 'Invalid API key or insufficient permissions.', 'ai-chatbot' );
				return $this->error( $msg, 'invalid_key', 401 );
			}
		} catch ( \Throwable $e ) {
			return $this->error(
				sprintf(
					/* translators: %s: error message */
					__( 'Could not verify the API key: %s', 'ai-chatbot' ),
					$e->getMessage()
				),
				'key_error',
				400
			);
		}

		return $this->success(
			array(
				'valid'    => true,
				'provider' => $provider_id,
				'message'  => __( 'Embeddings connection verified! Ready for semantic knowledge search.', 'ai-chatbot' ),
			)
		);
	}

	/**
	 * Resolve embeddings credentials for indexing crawled pages.
	 *
	 * @param int $agent_id Agent ID.
	 * @return array|null
	 */
	protected function resolve_embeddings_credentials( int $agent_id = 1 ): ?array {
		$settings = Settings::get_all( $agent_id, false );

		$provider_id = $settings['providers']['embeddings_provider'] ?? 'openai';
		$key_fields  = array(
			'openai'  => 'openai_api_key',
			'mistral' => 'mistral_api_key',
		);

		$key_field = $key_fields[ $provider_id ] ?? $key_fields['openai'];
		$model     = $settings['providers']['embeddings_model'] ?? 'text-embedding-3-small';
		$raw_key   = Encryption::decrypt( (string) ( $settings['providers'][ $key_field ] ?? '' ) );

		$provider = ModelRegistry::get( $provider_id );

		if ( ! $provider || ! $provider->supports_embeddings() || empty( $raw_key ) ) {
			return null;
		}

		return array(
			'provider' => $provider,
			'model'    => $model,
			'key'      => $raw_key,
		);
	}

	/**
	 * Start setup wizard crawler and AI analysis.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function start_wizard( WP_REST_Request $request ): WP_REST_Response {
		$params   = $request->get_json_params() ?: array();
		$url      = esc_url_raw( $params['url'] ?? home_url() );
		$agent_id = (int) ( $params['agent_id'] ?? 1 );

		$settings = Settings::get_all( $agent_id, false );

		$provider_id = $settings['providers']['chat_provider'] ?? 'groq';
		$provider    = ModelRegistry::get( $provider_id );

		$api_key = '';
		if ( 'groq' === $provider_id ) {
			$keys    = KeyPool::get_active_keys_for_request( 'groq' );
			$api_key = ! empty( $keys[0]['key'] ) ? $keys[0]['key'] : '';
		} else {
			$api_key = Encryption::decrypt( $settings['providers']['openai_api_key'] ?? '' );
		}

		if ( empty( $api_key ) && ! empty( $params['api_key'] ) ) {
			$api_key = trim( $params['api_key'] );
		}

		if ( empty( $api_key ) ) {
			return $this->error(
				__( 'AI connection is required to run the setup wizard. Please configure an AI key first.', 'ai-chatbot' ),
				'missing_key',
				400
			);
		}

		$result = SetupWizard::run( $url, $provider, $api_key, $agent_id );

		return $this->success( $result );
	}

	/**
	 * Get wizard status and drafted values.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_status( WP_REST_Request $request ): WP_REST_Response {
		$agent_id = (int) ( $request->get_param( 'agent_id' ) ?: 1 );
		$state    = get_transient( 'ai_wizard_state_' . $agent_id );

		if ( ! $state ) {
			return $this->success(
				array(
					'step'     => 'idle',
					'progress' => 0,
					'message'  => __( 'Wizard is ready to start.', 'ai-chatbot' ),
				)
			);
		}

		return $this->success( $state );
	}

	/**
	 * Apply approved values from setup wizard.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function apply_wizard( WP_REST_Request $request ): WP_REST_Response {
		$params   = $request->get_json_params() ?: array();
		$approved = $params['approved'] ?? array();
		$agent_id = (int) ( $params['agent_id'] ?? 1 );

		if ( empty( $approved ) || ! is_array( $approved ) ) {
			return $this->error( __( 'No approved data submitted.', 'ai-chatbot' ), 'empty_approved', 400 );
		}

		if ( ! empty( $params['embedding_api_key'] ) ) {
			$embedding_key      = trim( (string) $params['embedding_api_key'] );
			$embedding_provider = sanitize_key( $params['embedding_provider'] ?? 'openai' );

			if ( ! empty( $embedding_key ) && ! str_contains( $embedding_key, '••••' ) ) {
				$key_field = ( 'openai' === $embedding_provider ) ? 'openai_api_key' : 'mistral_api_key';

				Settings::save_all(
					$agent_id,
					array(
						'providers' => array(
							$key_field            => $embedding_key,
							'embeddings_provider' => $embedding_provider,
						),
					)
				);
			}
		}

		SetupWizard::apply( $approved, $agent_id );

		update_option( 'ai_chatbot_onboarding_completed', 'completed', false );
		update_option( 'ai_chatbot_wizard_completed', '1', false );

		return $this->success(
			array(
				'message' => __( 'Wizard settings applied successfully! Your chatbot is now live and trained.', 'ai-chatbot' ),
			)
		);
	}
}
