<?php
/**
 * Public Chat REST Controller.
 *
 * @package AIChatbot\Rest
 */

namespace AIChatbot\Rest;

use WP_REST_Request;
use WP_REST_Response;
use AIChatbot\Settings;
use AIChatbot\Security\Sanitizer;
use AIChatbot\Security\Encryption;
use AIChatbot\AI\ModelRegistry;
use AIChatbot\AI\KeyPool;
use AIChatbot\Knowledge\Retriever;
use AIChatbot\Knowledge\UnansweredInbox;
use AIChatbot\Guardrails\TopicGuardrail;
use AIChatbot\Prompt\SystemPromptBuilder;
use AIChatbot\Skills\SkillsManager;
use AIChatbot\Language\LanguageService;
use AIChatbot\Links\LinkRegistry;
use AIChatbot\Links\LinkPostProcessor;

defined( 'ABSPATH' ) || exit;

/**
 * Class ChatController.
 */
class ChatController extends RestController {

	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/chat',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_chat' ),
				'permission_callback' => array( $this, 'check_public_permission' ),
			)
		);

		/*
		 * Dedicated edit endpoint rather than a flag on /chat.
		 *
		 * Editing is not "sending a corrected message": it discards the branch of
		 * the conversation that followed the edited message and regenerates from
		 * that point. Giving it its own route keeps that truncation logic
		 * impossible to reach by accident from a normal send, and lets it carry a
		 * tighter rate limit than the 25/min that applies to ordinary messages.
		 */
		register_rest_route(
			self::NAMESPACE,
			'/chat/edit',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_chat_edit' ),
				'permission_callback' => array( $this, 'check_public_permission' ),
			)
		);
	}

	/**
	 * Rewrite a visitor message and regenerate the branch that followed it.
	 *
	 * Modelled on ChatGPT and Gemini: editing an earlier message discards
	 * everything after it and produces a fresh answer from the edited text.
	 * Keeping the stale replies would leave the model reasoning over a history
	 * that answers a question the visitor is no longer asking.
	 *
	 * Ownership is enforced against the session, not against the message id
	 * alone. Message ids are sequential and therefore guessable, so a bare
	 * "update where id = ?" would let anyone rewrite or truncate any
	 * conversation on the site. The conversation is resolved from the caller's
	 * own session_id and the message must belong to it.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function handle_chat_edit( WP_REST_Request $request ): WP_REST_Response {
		global $wpdb;

		$ip = Sanitizer::get_client_ip();

		$params     = $request->get_json_params() ?: array();
		$message_id = (int) ( $params['message_id'] ?? 0 );
		$message    = trim( sanitize_textarea_field( $params['message'] ?? '' ) );
		$session_id = sanitize_text_field( $params['session_id'] ?? '' );
		$agent_id   = (int) ( $params['agent_id'] ?? 1 );

		$all_settings    = Settings::get_all( $agent_id, false );
		$chat_rate_limit = (int) ( $all_settings['security']['chat_rate_limit'] ?? 0 );
		if ( $chat_rate_limit > 0 && ! Sanitizer::check_rate_limit( 'chat_edit', $ip, $chat_rate_limit, 60 ) ) {
			return $this->error(
				__( 'Too many edits. Please wait a moment before editing another message.', 'ai-chatbot' ),
				'rate_limit_exceeded',
				429
			);
		}

		// The chatbot is disabled until first-time setup is complete.
		if ( ! Settings::is_setup_complete( $agent_id ) ) {
			return $this->error(
				__( 'This chatbot is still being set up. Please check back shortly, or contact us directly.', 'ai-chatbot' ),
				'setup_incomplete',
				503
			);
		}

		if ( $message_id <= 0 ) {
			return $this->error( __( 'No message was specified for editing.', 'ai-chatbot' ), 'missing_message_id', 400 );
		}

		if ( '' === $message ) {
			return $this->error( __( 'Message cannot be empty.', 'ai-chatbot' ), 'empty_message', 400 );
		}

		// Mirrors the widget's MAX_LENGTH so the cap is enforced server-side too.
		$message = substr( $message, 0, 2000 );

		if ( '' === $session_id ) {
			return $this->error( __( 'No session was supplied.', 'ai-chatbot' ), 'missing_session', 400 );
		}

		$table_conv = $wpdb->prefix . 'ai_conversations';
		$table_msg  = $wpdb->prefix . 'ai_messages';

		$conv = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table_conv} WHERE session_id = %s", $session_id ),
			ARRAY_A
		);

		// Deliberately not created on demand: an edit can only apply to an
		// existing conversation.
		if ( ! $conv ) {
			return $this->error( __( 'Conversation not found.', 'ai-chatbot' ), 'conversation_not_found', 404 );
		}

		$conversation_id = (int) $conv['id'];

		// The conversation_id predicate is the ownership check. Without it the
		// message id alone would be enough to reach any conversation.
		$target = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, role, message FROM {$table_msg} WHERE id = %d AND conversation_id = %d",
				$message_id,
				$conversation_id
			),
			ARRAY_A
		);

		if ( ! $target ) {
			return $this->error( __( 'Message not found in this conversation.', 'ai-chatbot' ), 'message_not_found', 404 );
		}

		/*
		 * Only the visitor's own messages are editable.
		 *
		 * ChatGPT and Gemini both refuse this, and so does this endpoint: an
		 * assistant message is the record of what the bot actually said. Letting
		 * a visitor rewrite it would let them put words in the bot's mouth and
		 * then feed that back as context on the next turn.
		 */
		if ( 'user' !== $target['role'] ) {
			return $this->error(
				__( 'Only your own messages can be edited.', 'ai-chatbot' ),
				'not_editable_role',
				403
			);
		}

		$original = (string) $target['message'];

		// Nothing to do if the text is unchanged. Reported rather than silently
		// regenerated, so the widget can skip the round trip entirely.
		if ( $original === $message ) {
			return $this->success(
				array(
					'reply'           => null,
					'unchanged'       => true,
					'message_id'      => (int) $target['id'],
					'session_id'      => $session_id,
					'conversation_id' => $conversation_id,
					'discarded'       => 0,
				)
			);
		}

		/*
		 * Discard the branch after the edited message.
		 *
		 * The replies built on the old wording are now answers to a question
		 * nobody asked any more, and they would otherwise be replayed as context
		 * on the very next turn.
		 */
		$discarded = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table_msg} WHERE conversation_id = %d AND id > %d",
				$conversation_id,
				(int) $target['id']
			)
		);
		$discarded = is_numeric( $discarded ) ? (int) $discarded : 0;

		$updated = $wpdb->update(
			$table_msg,
			array(
				'message'    => $message,
				'is_edited'  => 1,
				'edited_at'  => current_time( 'mysql' ),
			),
			array( 'id' => (int) $target['id'] ),
			array( '%s', '%d', '%s' ),
			array( '%d' )
		);

		if ( false === $updated ) {
			return $this->error( __( 'Could not save the edited message.', 'ai-chatbot' ), 'update_failed', 500 );
		}

		/*
		 * Reset the off-topic counter.
		 *
		 * The throttle counts deflected messages in this conversation. Having
		 * discarded the branch, those messages no longer exist, so carrying the
		 * count forward would let a visitor edit their way out of a throttle
		 * that was earned by the discarded turns.
		 */
		$wpdb->update(
			$table_conv,
			array(
				'off_topic_count' => 0,
				'is_throttled'    => 0,
				'throttled_until' => null,
				'updated_at'      => current_time( 'mysql' ),
			),
			array( 'id' => $conversation_id )
		);
		$conv['off_topic_count'] = 0;
		$conv['is_throttled']    = 0;

		/*
		 * Regeneration is delegated to handle_chat() with a sentinel, so the
		 * edited message is not inserted a second time. Everything downstream —
		 * retrieval, guardrails, grounding, model call, persistence — is the
		 * exact same path a normal message takes, which is what keeps an edited
		 * answer consistent with a first-time one.
		 */
		$request->set_param( 'message', $message );
		$request->set_param( 'session_id', $session_id );
		$request->set_param( 'agent_id', $agent_id );
		$request->set_param( '_reuse_existing_message_id', (int) $target['id'] );

		$response = $this->handle_chat( $request );

		if ( ! $response instanceof WP_REST_Response ) {
			return $response;
		}

		$data = $response->get_data();

		if ( is_array( $data ) && isset( $data['data'] ) && is_array( $data['data'] ) ) {
			$data['data']['edited']       = true;
			$data['data']['discarded']    = $discarded;
			$data['data']['original_text'] = $original;
			$response->set_data( $data );
		}

		return $response;
	}

	/**
	 * Handle incoming public chat message.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function handle_chat( WP_REST_Request $request ): WP_REST_Response {
		global $wpdb;

		$params     = $request->get_json_params() ?: array();
		$message    = trim( sanitize_textarea_field( $params['message'] ?? '' ) );
		$session_id = sanitize_text_field( $params['session_id'] ?? '' );
		$agent_id   = (int) ( $params['agent_id'] ?? 1 );

		// 1. Rate limiting (0 or unset = Unlimited messages, no cap).
		$all_settings    = Settings::get_all( $agent_id, false );
		$chat_rate_limit = (int) ( $all_settings['security']['chat_rate_limit'] ?? 0 );
		if ( $chat_rate_limit > 0 && ! Sanitizer::check_rate_limit( 'chat', $ip, $chat_rate_limit, 60 ) ) {
			return $this->error(
				__( 'Too many requests. Please wait a minute before sending another message.', 'ai-chatbot' ),
				'rate_limit_exceeded',
				429
			);
		}

		// The chatbot is disabled until first-time setup is complete.
		if ( ! Settings::is_setup_complete( $agent_id ) ) {
			return $this->error(
				__( 'This chatbot is still being set up. Please check back shortly, or contact us directly.', 'ai-chatbot' ),
				'setup_incomplete',
				503
			);
		}

		/*
		 * Set by handle_chat_edit() when regenerating after a rewrite. The
		 * message row already exists and has just been updated, so it must not
		 * be inserted again. Internal sentinel, deliberately not settable from
		 * the request body: a caller passing it directly would suppress the
		 * save of their own message and lose the transcript entry.
		 */
		$reuse_message_id = (int) $request->get_param( '_reuse_existing_message_id' );

		if ( empty( $message ) ) {
			return $this->error( __( 'Message cannot be empty.', 'ai-chatbot' ), 'empty_message', 400 );
		}

		if ( empty( $session_id ) ) {
			$session_id = 'sess_' . wp_generate_uuid4();
		}

		// 2. Fetch or create conversation record.
		$table_conv = $wpdb->prefix . 'ai_conversations';
		$table_msg  = $wpdb->prefix . 'ai_messages';

		$conv = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table_conv} WHERE session_id = %s", $session_id ),
			ARRAY_A
		);

		if ( ! $conv ) {
			$wpdb->insert(
				$table_conv,
				array(
					'agent_id'   => $agent_id,
					'session_id' => $session_id,
					'visitor_id' => md5( $ip ),
					'created_at' => current_time( 'mysql' ),
				)
			);
			$conversation_id = (int) $wpdb->insert_id;
			$conv            = array(
				'id'              => $conversation_id,
				'off_topic_count' => 0,
				'is_throttled'    => 0,
			);
	} else {
		$conversation_id = (int) $conv['id'];
	}

	/*
	 * The conversation's language is carried in its metadata JSON so
	 * it survives across turns without a schema change. It is reused
	 * for ambiguous follow-ups and only replaced when the visitor
	 * switches language.
	 */
	$conv_metadata    = ! empty( $conv['metadata'] ) ? json_decode( (string) $conv['metadata'], true ) : array();
	$conv_metadata    = is_array( $conv_metadata ) ? $conv_metadata : array();
	$stored_language  = (string) ( $conv_metadata['language'] ?? '' );
	$stored_language  = LanguageService::sanitize( $stored_language );

		// 3. Save visitor message.
		if ( $reuse_message_id > 0 ) {
			$user_msg_id = $reuse_message_id;
		} else {
			$wpdb->insert(
				$table_msg,
				array(
					'conversation_id' => $conversation_id,
					'role'            => 'user',
					'message'         => $message,
					'tokens'          => (int) ceil( strlen( $message ) / 4 ),
					'created_at'      => current_time( 'mysql' ),
				)
			);
			$user_msg_id = (int) $wpdb->insert_id;
		}

		// 4. Check if session is currently throttled due to repeated off-topic attempts.
		if ( ! empty( $conv['is_throttled'] ) && ! empty( $conv['throttled_until'] ) ) {
			if ( strtotime( $conv['throttled_until'] ) > time() ) {
				$throttled_lang = LanguageService::resolve( $message, $stored_language, array() );
				return $this->success(
					array(
						'reply'               => LanguageService::get_throttled_message( $throttled_lang ),
						'message_id'          => 0,
						'user_message_id'     => $user_msg_id,
						'session_id'          => $session_id,
						'conversation_id'     => $conversation_id,
						'offer_contact'       => false,
						'handoff'             => false,
						'suggested_questions' => array(),
						'language'            => $throttled_lang,
					)
				);
			} else {
				// Throttle expired, reset.
				$wpdb->update(
					$table_conv,
					array( 'is_throttled' => 0, 'throttled_until' => null, 'off_topic_count' => 0 ),
					array( 'id' => $conversation_id )
				);
				$conv['off_topic_count'] = 0;
			}
		}

		// 5. Load settings, agent, and business information.
		$all_settings     = Settings::get_all( $agent_id, false );
		$guardrail_config = $all_settings['guardrails'] ?? array();
		$providers_config = $all_settings['providers'] ?? array();
		$skills_config    = SkillsManager::get_skills( $agent_id );

		// Load Agent.
		$table_agents = $wpdb->prefix . 'ai_agents';
		$agent = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table_agents} WHERE id = %d", $agent_id ), ARRAY_A );
		if ( ! $agent ) {
			$agent = array(
				'name'          => 'AI Assistant',
				'role'          => 'Helpful company assistant',
				'tone'          => 'friendly',
				'answer_length' => 'medium',
				'greeting'      => 'Hello!',
			);
		}

		// Load Business Information.
		$table_sources = $wpdb->prefix . 'ai_knowledge_sources';
		$biz_source    = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT content FROM {$table_sources} WHERE agent_id = %d AND type = 'business_info'",
				$agent_id
			),
			ARRAY_A
		);
		$business_info = ! empty( $biz_source['content'] ) ? json_decode( $biz_source['content'], true ) : array();
		if ( ! is_array( $business_info ) ) {
			$business_info = array();
		}

	$company_name = $business_info['business_name'] ?? get_bloginfo( 'name' );
	$guardrail_config['company_name']             = $company_name;
	$guardrail_config['business_info']            = $business_info;
	$guardrail_config['customer_service_contact'] = ! empty( $business_info['phone'] )
		? $business_info['phone']
		: ( ! empty( $business_info['email'] )
			? $business_info['email']
			: (string) Settings::get_support_email( $agent_id ) );

	/*
	 * Resolve the response language for this turn.
	 *
	 * Detection is a cheap PHP heuristic with no API call, so it runs
	 * inline rather than asynchronously: the resolved language is
	 * needed before retrieval and before the prompt is built, and a
	 * network round trip here would add latency to every message.
	 */
	$language_settings = $all_settings['language'] ?? array();
	$default_language  = LanguageService::sanitize( (string) ( $language_settings['default_language'] ?? LanguageService::DEFAULT_LANGUAGE ) ) ?: LanguageService::DEFAULT_LANGUAGE;

	$resolved_language = LanguageService::resolve( $message, $stored_language, $language_settings );

	// Carry the language forward so the next turn can reuse it.
	$persisted_language = LanguageService::persist_language( $resolved_language, $message, $default_language );

	if ( $persisted_language !== $stored_language ) {
		$conv_metadata['language'] = $persisted_language;
		$wpdb->update(
			$table_conv,
			array( 'metadata' => wp_json_encode( $conv_metadata ) ),
			array( 'id' => $conversation_id ),
			array( '%s' ),
			array( '%d' )
		);
	}

	// 5b. Response Caching: check if an identical prompt was answered under current link settings.
	$response_config      = $all_settings['response_behavior'] ?? ( $all_settings['response'] ?? array() );
	$link_settings_subset = array(
		'auto_links_verified'            => ! empty( $response_config['auto_links_verified'] ) || ! empty( $response_config['auto_links_citations'] ),
		'allow_requested_links'          => ! isset( $response_config['allow_requested_links'] ) || ! empty( $response_config['allow_requested_links'] ),
		'link_style'                     => $response_config['link_style'] ?? 'inline',
		'max_links_per_answer'           => (int) ( $response_config['max_links_per_answer'] ?? 2 ),
		'link_relevance_threshold'       => (float) ( $response_config['link_relevance_threshold'] ?? 0.60 ),
		'rebuild_interval'               => $response_config['rebuild_interval'] ?? 'daily',
		'deduplicate_conversation_links' => ! isset( $response_config['deduplicate_conversation_links'] ) || ! empty( $response_config['deduplicate_conversation_links'] ),
		'allowed_domains'                => trim( (string) ( $response_config['allowed_domains'] ?? '' ) ),
		'excluded_patterns'              => trim( (string) ( $response_config['excluded_patterns'] ?? '' ) ),
	);
	$link_settings_hash   = md5( (string) wp_json_encode( $link_settings_subset ) );
	$cache_key            = 'ai_chat_rsp_' . md5( $agent_id . '|' . mb_strtolower( trim( $message ) ) . '|' . $resolved_language . '|' . $link_settings_hash );
	$cached_rsp           = get_transient( $cache_key );

	if ( is_array( $cached_rsp ) && ! empty( $cached_rsp['reply'] ) ) {
		$wpdb->insert(
			$table_msg,
			array(
				'conversation_id' => $conversation_id,
				'role'            => 'assistant',
				'message'         => $cached_rsp['reply'],
				'tokens'          => (int) ( $cached_rsp['tokens'] ?? 0 ),
				'created_at'      => current_time( 'mysql' ),
			)
		);
		$assistant_msg_id = (int) $wpdb->insert_id;

		$cached_debug_rag = array(
			'sources_found_count' => (int) ( $cached_rsp['debug_rag']['sources_found_count'] ?? 1 ),
			'context_length'      => (int) ( $cached_rsp['debug_rag']['context_length'] ?? 0 ),
			'context_preview'     => (string) ( $cached_rsp['debug_rag']['context_preview'] ?? 'Cached response' ),
		);

		$cached_res = $this->success(
			array(
				'reply'               => $cached_rsp['reply'],
				'message_id'          => $assistant_msg_id,
				'user_message_id'     => $user_msg_id,
				'session_id'          => $session_id,
				'conversation_id'     => $conversation_id,
				'offer_contact'       => false,
				'handoff'             => false,
				'support_email'       => esc_attr( Settings::get_support_email( $agent_id ) ),
				'tokens'              => (int) ( $cached_rsp['tokens'] ?? 0 ),
				'suggested_questions' => $cached_rsp['suggested_questions'] ?? array(),
				'language'            => $persisted_language,
				'cached'              => true,
				'_debug_rag'          => $cached_debug_rag,
			)
		);
		$c_data     = $cached_res->get_data();
		if ( is_array( $c_data ) ) {
			$c_data['_debug_rag'] = $cached_debug_rag;
			$cached_res->set_data( $c_data );
		}
		return $cached_res;
	}

	// 6. Retrieve relevant knowledge chunks.
		$embeddings_provider_id = $providers_config['embeddings_provider'] ?? 'openai';
		$embeddings_model       = $providers_config['embeddings_model'] ?? 'text-embedding-3-small';

		// Each embeddings provider keeps its credential in its own settings
		// field. Reading only the OpenAI field meant that selecting Mistral
		// silently disabled retrieval entirely, which made the chatbot fall
		// back to the model's general knowledge instead of the website.
		$embeddings_key_fields = array(
			'openai'  => 'openai_api_key',
			'mistral' => 'mistral_api_key',
		);
		$embeddings_key_field  = $embeddings_key_fields[ $embeddings_provider_id ] ?? 'openai_api_key';
		$embeddings_key_enc    = $providers_config[ $embeddings_key_field ] ?? '';
		$embeddings_key        = ! empty( $embeddings_key_enc ) ? Encryption::decrypt( $embeddings_key_enc ) : '';

		// Also check active keys in KeyPool if not in settings table.
		if ( empty( $embeddings_key ) ) {
			$pool_keys = KeyPool::get_active_keys_for_request( $embeddings_provider_id, 'priority' );
			if ( ! empty( $pool_keys[0]['key'] ) ) {
				$embeddings_key = (string) $pool_keys[0]['key'];
			}
		}
		if ( empty( $embeddings_key ) && 'openai' === $embeddings_provider_id ) {
			$pool_keys = KeyPool::get_active_keys_for_request( 'openai', 'priority' );
			if ( ! empty( $pool_keys[0]['key'] ) ) {
				$embeddings_key = (string) $pool_keys[0]['key'];
			}
		}

		$embeddings_provider = ModelRegistry::get( $embeddings_provider_id );
		$retrieved_chunks    = array();
		$top_score           = 0.0;

		if ( $embeddings_provider && $embeddings_provider->supports_embeddings() && ! empty( $embeddings_key ) ) {
			// The previous visitor turn is folded into the retrieval query so a
			// follow-up like "and how long does that take?" can still find the
			// page the first question was about.
			$prior_turn = '';
			$prev_row   = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT message FROM {$table_msg}
					 WHERE conversation_id = %d AND role = 'user' AND id < %d
					 ORDER BY id DESC LIMIT 1",
					$conversation_id,
					$user_msg_id
				)
			);
			if ( ! empty( $prev_row ) ) {
				$prior_turn = (string) $prev_row;
			}

			$retrieval_res = Retriever::retrieve(
				$message,
				$agent_id,
				$embeddings_provider,
				$embeddings_model,
				$embeddings_key,
				5,
				array(),
				null,
				$prior_turn,
				$persisted_language
			);
			$retrieved_chunks = $retrieval_res['chunks'];
			$top_score        = $retrieval_res['top_score'];
		} else {
			// Fallback: keyword search when embeddings provider is unavailable or unkeyed.
			$store = new \AIChatbot\VectorStore\CustomTableStore();
			$retrieved_chunks = $store->query_by_keywords(
				$message,
				$agent_id,
				5,
				array(),
				Retriever::get_cross_lingual_variants( $message, $persisted_language )
			);
			$top_score = ! empty( $retrieved_chunks ) ? (float) $retrieved_chunks[0]['score'] : 0.0;
		}

		// Weak matches are treated as "not found" rather than fed to the model.
		//
		// A fixed threshold cannot work across embedding models: an unrelated
		// question scores ~0.29 against text-embedding-3 but ~0.75 against
		// mistral-embed, so a constant either filters nothing or everything.
		// The floor is therefore calibrated against this provider and model,
		// and the configured value only acts as an absolute floor.
		$min_score = (float) ( $all_settings['knowledge']['min_relevance_score'] ?? 0.32 );

		if ( ! empty( $embeddings_key ) && $embeddings_provider ) {
			$floor = Retriever::calibrated_floor(
				$embeddings_provider_id,
				$embeddings_provider,
				$embeddings_model,
				$embeddings_key
			);

			if ( null !== $floor ) {
				$min_score = max( $min_score, $floor + Retriever::FLOOR_MARGIN );
			}
		}

		// If score is below floor or empty, attempt keyword search fallback before discarding:
		if ( $top_score < $min_score || empty( $retrieved_chunks ) ) {
			$store = new \AIChatbot\VectorStore\CustomTableStore();
			$kw_chunks = $store->query_by_keywords(
				$message,
				$agent_id,
				5,
				array(),
				Retriever::get_cross_lingual_variants( $message, $persisted_language )
			);
			if ( ! empty( $kw_chunks ) ) {
				$retrieved_chunks = $kw_chunks;
				$top_score        = (float) $kw_chunks[0]['score'];
			}
			// Do not clear $retrieved_chunks if semantic candidates were already found by vector search.
		}

		// Step 1: Guaranteed Cross-Lingual Knowledge Base Fallback
		// If retrieved chunks is still empty, load the balanced core Knowledge Base sources/chunks
		// directly from the database so context is NEVER empty on cross-lingual queries or keyword misses.
		if ( empty( $retrieved_chunks ) ) {
			$retrieved_chunks = Retriever::get_core_knowledge_chunks( $agent_id, 6 );
			if ( ! empty( $retrieved_chunks ) ) {
				$top_score = 0.75;
			}
		}

		$include_links_in_ctx = ! empty( $response_config['auto_links_verified'] )
			|| ! empty( $response_config['auto_links_citations'] )
			|| ( ( ! isset( $response_config['allow_requested_links'] ) || ! empty( $response_config['allow_requested_links'] ) ) && self::is_explicit_link_request( $message ) );

		$knowledge_text = SystemPromptBuilder::format_knowledge_context(
			$retrieved_chunks,
			$include_links_in_ctx,
			$include_links_in_ctx
		);

		// Step 3 Mandatory Debug RAG payload:
		$debug_rag = array(
			'sources_found_count' => count( $retrieved_chunks ),
			'context_length'      => strlen( $knowledge_text ),
			'context_preview'     => mb_substr( $knowledge_text, 0, 200 ),
		);

		// RAG Debug logging.
		error_log( sprintf(
			'[AI Chatbot _debug_rag] Query: "%s" | Lang: %s | sources_found_count: %d | context_length: %d | Top score: %.4f',
			$message,
			$resolved_language,
			$debug_rag['sources_found_count'],
			$debug_rag['context_length'],
			$top_score
		) );

		// 7. Evaluate Topic Guardrails.
		$chat_provider_id = $providers_config['chat_provider'] ?? 'groq';
		$chat_provider    = ModelRegistry::get( $chat_provider_id );

		// The chat credential is resolved separately from the embeddings one.
		// They are frequently different providers, and reusing the embeddings key
		// here handed a Mistral key to OpenAI (and vice versa).
		$chat_key = '';

		if ( 'groq' === $chat_provider_id ) {
			$active_groq = KeyPool::get_active_keys_for_request( 'groq', 'priority' );
			$chat_key    = ! empty( $active_groq[0]['key'] ) ? (string) $active_groq[0]['key'] : '';
		} else {
			$chat_key_fields = array(
				'openai'  => 'openai_api_key',
				'mistral' => 'mistral_api_key',
			);
			$chat_key_field  = $chat_key_fields[ $chat_provider_id ] ?? 'openai_api_key';
			$chat_key_enc    = $providers_config[ $chat_key_field ] ?? '';
			$chat_key        = ! empty( $chat_key_enc ) ? Encryption::decrypt( $chat_key_enc ) : '';
		}

		$classifier_key = '' !== trim( $chat_key ) ? $chat_key : null;

		// Fallback provider needs its own key and only makes sense when one exists.
		$fallback_provider = null;
		$fallback_key      = null;

		if ( ! empty( $providers_config['fallback_enabled'] ) ) {
			if ( 'openai' !== $chat_provider_id && ! empty( $providers_config['openai_api_key'] ) ) {
				$fallback_enc = $providers_config['openai_api_key'] ?? '';
				$fallback_raw = ! empty( $fallback_enc ) ? Encryption::decrypt( $fallback_enc ) : '';

				if ( ! empty( $fallback_raw ) && ! str_starts_with( $fallback_raw, 'sk-proj-Test' ) ) {
					$fallback_provider = ModelRegistry::get( 'openai' );
					$fallback_key      = $fallback_raw;
				}
			}

			// If OpenAI is unavailable, try Mistral as fallback provider
			if ( ! $fallback_provider && 'mistral' !== $chat_provider_id && ! empty( $providers_config['mistral_api_key'] ) ) {
				$fallback_enc = $providers_config['mistral_api_key'] ?? '';
				$fallback_raw = ! empty( $fallback_enc ) ? Encryption::decrypt( $fallback_enc ) : '';

				if ( ! empty( $fallback_raw ) ) {
					$fallback_provider = ModelRegistry::get( 'mistral' );
					$fallback_key      = $fallback_raw;
				}
			}
		}

		$guardrail_decision = TopicGuardrail::evaluate(
			$message,
			$guardrail_config,
			$retrieved_chunks,
			$chat_provider,
			$classifier_key,
			$resolved_language
		);

		// Handle OUT OF SCOPE.
		if ( ! $guardrail_decision['is_allowed'] ) {
			$off_count = (int) $conv['off_topic_count'] + 1;

			// Check throttle escalation.
			$throttle_limit = (int) ( $guardrail_config['repeated_off_topic_throttle'] ?? 6 );
			$is_throttled   = 0;
			$throttle_until = null;

			if ( $off_count >= $throttle_limit ) {
				$is_throttled   = 1;
				$throttle_until = gmdate( 'Y-m-d H:i:s', time() + ( 15 * MINUTE_IN_SECONDS ) );
			}

			$wpdb->update(
				$table_conv,
				array(
					'off_topic_count' => $off_count,
					'is_throttled'    => $is_throttled,
					'throttled_until' => $throttle_until,
					'updated_at'      => current_time( 'mysql' ),
				),
				array( 'id' => $conversation_id )
			);

			// Log to wp_ai_out_of_scope_log.
			TopicGuardrail::log_out_of_scope( $agent_id, $message );

			$decline_msg = ! empty( $guardrail_decision['decline_message'] )
				? $guardrail_decision['decline_message']
				: LanguageService::get_decline_message( $resolved_language, $company_name, $off_count );

			// Save assistant decline message.
			$wpdb->insert(
				$table_msg,
				array(
					'conversation_id' => $conversation_id,
					'role'            => 'assistant',
					'message'         => $decline_msg,
					'tokens'          => (int) ceil( strlen( $decline_msg ) / 4 ),
					'created_at'      => current_time( 'mysql' ),
				)
			);
			$assistant_msg_id = (int) $wpdb->insert_id;

			$questions = ! empty( $agent['suggested_questions'] ) ? json_decode( $agent['suggested_questions'], true ) : array();
			$localized_questions = LanguageService::localize_suggested_questions( is_array( $questions ) ? $questions : array(), $resolved_language );

			$decline_payload = array(
				'reply'               => $decline_msg,
				'message_id'          => $assistant_msg_id,
				'user_message_id'     => $user_msg_id,
				'session_id'          => $session_id,
				'conversation_id'     => $conversation_id,
				'offer_contact'       => false,
				'handoff'             => false,
				'support_email'       => esc_attr( Settings::get_support_email( $agent_id ) ),
				'suggested_questions' => $localized_questions,
				'language'            => $persisted_language,
				'_debug_rag'          => $debug_rag,
			);
			$decline_res = $this->success( $decline_payload );
			$d_data      = $decline_res->get_data();
			if ( is_array( $d_data ) ) {
				$d_data['_debug_rag'] = $debug_rag;
				$decline_res->set_data( $d_data );
			}
			return $decline_res;
		}

		// 7b. Strict grounding: if the website has no relevant content, answer
		// deterministically instead of letting the model fall back on its
		// general training knowledge. Small talk, contact queries, and link requests still pass through.
		$guardrail_strictness = $guardrail_config['strictness'] ?? 'strict';
		if ( 'off' !== $guardrail_strictness
			&& empty( $retrieved_chunks )
			&& ! self::is_conversational_message( $message )
			&& ! TopicGuardrail::is_always_allowed( $message )
			&& ! self::is_explicit_link_request( $message )
		) {
			$not_found = self::build_not_found_reply( $guardrail_config, $company_name, $message, $resolved_language );

			$wpdb->insert(
				$table_msg,
				array(
					'conversation_id' => $conversation_id,
					'role'            => 'assistant',
					'message'         => $not_found,
					'tokens'          => (int) ceil( strlen( $not_found ) / 4 ),
					'created_at'      => current_time( 'mysql' ),
				)
			);
			$assistant_msg_id = (int) $wpdb->insert_id;

			// Track unanswered questions so knowledge gaps can be reviewed.
			UnansweredInbox::log_question( $agent_id, $message );

			$questions = ! empty( $agent['suggested_questions'] ) ? json_decode( $agent['suggested_questions'], true ) : array();
			$localized_questions = LanguageService::localize_suggested_questions( is_array( $questions ) ? $questions : array(), $resolved_language );

			$not_found_payload = array(
				'reply'               => $not_found,
				'message_id'          => $assistant_msg_id,
				'user_message_id'     => $user_msg_id,
				'session_id'          => $session_id,
				'conversation_id'     => $conversation_id,
				'offer_contact'       => false,
				'handoff'             => false,
				'support_email'       => esc_attr( Settings::get_support_email( $agent_id ) ),
				'grounded'            => false,
				'suggested_questions' => $localized_questions,
				'language'            => $persisted_language,
				'_debug_rag'          => $debug_rag,
				'debug'               => array(
					'chunks_retrieved'   => count( $retrieved_chunks ),
					'top_score'          => round( $top_score, 4 ),
					'resolved_language'  => $resolved_language,
					'persisted_language' => $persisted_language,
				),
			);
			$nf_res  = $this->success( $not_found_payload );
			$nf_data = $nf_res->get_data();
			if ( is_array( $nf_data ) ) {
				$nf_data['_debug_rag'] = $debug_rag;
				$nf_res->set_data( $nf_data );
			}
			return $nf_res;
		}

	// 8. Build System Prompt on Server with Link Control settings.
	$response_config          = $all_settings['response_behavior'] ?? ( $all_settings['response'] ?? array() );
	$is_explicit_link_request = self::is_explicit_link_request( $message );

	$max_links = isset( $response_config['max_links_per_answer'] )
		? max( 1, min( 3, (int) $response_config['max_links_per_answer'] ) )
		: 3;

	$verified_links = array();
	if ( class_exists( '\AIChatbot\Links\LinkRegistry' ) ) {
		$threshold   = isset( $response_config['link_relevance_threshold'] ) ? (float) $response_config['link_relevance_threshold'] : 0.60;
		$dedup_conv  = ! isset( $response_config['deduplicate_conversation_links'] ) || ! empty( $response_config['deduplicate_conversation_links'] );
		$prev_linked = ( $dedup_conv && ! $is_explicit_link_request ) ? (array) ( $conv_metadata['linked_urls'] ?? array() ) : array();

		// Run query-time link matching on EVERY user message independent of the LLM.
		$verified_links = \AIChatbot\Links\LinkRegistry::find_relevant_links(
			$message,
			$retrieved_chunks,
			$resolved_language,
			$max_links,
			$threshold,
			$response_config,
			$prev_linked
		);

			// If explicitly requested and no chunk link matched, look up contact page or website root.
			if ( empty( $verified_links ) && $is_explicit_link_request ) {
				if ( preg_match( '/\b(kontakt|contact|anfrage)\b/ui', $message ) ) {
					$contact_url = ! empty( $response_config['contact_url'] ) ? $response_config['contact_url'] : self::resolve_site_contact_url();
					if ( ! empty( $contact_url ) ) {
						$c_check = LinkRegistry::validate_url( $contact_url, array( 'response_config' => $response_config ) );
						$verified_links[] = array(
							'index'         => 1,
							'placeholder'   => '[[LINK_1]]',
							'title'         => ( ! empty( $c_check['title'] ) && 'Page' !== $c_check['title'] ) ? $c_check['title'] : ( 'de' === $resolved_language ? sprintf( 'Kontakt von %s', $company_name ) : sprintf( 'Contact %s', $company_name ) ),
							'url'           => ! empty( $c_check['canonical_url'] ) ? $c_check['canonical_url'] : $contact_url,
							'canonical_url' => ! empty( $c_check['canonical_url'] ) ? $c_check['canonical_url'] : $contact_url,
							'language'      => $resolved_language,
							'summary'       => '',
							'score'         => 1.0,
						);
					}
				} elseif ( preg_match( '/\b(website|webseite|homepage|site|url|link)\b/ui', $message ) ) {
					$home    = home_url();
					$h_check = LinkRegistry::validate_url( $home, array( 'response_config' => $response_config ) );
					$verified_links[] = array(
						'index'         => 1,
						'placeholder'   => '[[LINK_1]]',
						'title'         => ( ! empty( $h_check['title'] ) && 'Page' !== $h_check['title'] ) ? $h_check['title'] : $company_name,
						'url'           => ! empty( $h_check['canonical_url'] ) ? $h_check['canonical_url'] : $home,
						'canonical_url' => ! empty( $h_check['canonical_url'] ) ? $h_check['canonical_url'] : $home,
						'language'      => $resolved_language,
						'summary'       => '',
						'score'         => 1.0,
					);
			}
		}
	}

	$system_prompt = SystemPromptBuilder::build(
		$agent,
		$business_info,
		$retrieved_chunks,
		$guardrail_config,
		$skills_config,
		$resolved_language,
		$response_config,
		$is_explicit_link_request,
		$verified_links
	);

		// 9. Prepare chat conversation messages history with strict Sliding Window.
		// Keeps the system prompt + only the last 6 messages (3 user/assistant turns)
		// so conversation memory never crashes the API or exceeds Token-Per-Minute (TPM) limits.
		$sliding_window_size = 6;
		if ( ! empty( $all_settings['session']['sliding_window_size'] ) ) {
			$sliding_window_size = max( 4, min( 10, (int) $all_settings['session']['sliding_window_size'] ) );
		}

		$recent_msgs = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT role, message FROM {$table_msg} WHERE conversation_id = %d ORDER BY id DESC LIMIT %d",
				$conversation_id,
				$sliding_window_size
			),
			ARRAY_A
		);
		$recent_msgs = array_reverse( $recent_msgs ?: array() );

		// Step 2: Strip initial greeting message from conversation history
		// so it does not pollute the language context or bias the LLM into replying in another language.
		$agent_greeting_raw = (string) ( $agent['greeting'] ?? '' );
		$clean_greeting     = trim( mb_strtolower( preg_replace( '/\s+/u', ' ', $agent_greeting_raw ) ) );

		$filtered_history = array();
		foreach ( $recent_msgs as $rm ) {
			if ( 'assistant' === $rm['role'] ) {
				$norm_msg = trim( mb_strtolower( preg_replace( '/\s+/u', ' ', (string) $rm['message'] ) ) );
				if ( ( '' !== $clean_greeting && str_contains( $norm_msg, mb_substr( $clean_greeting, 0, 25 ) ) )
					|| preg_match( '/^(?:hallo!?|guten\s+tag|willkommen\s+bei|ich\s+bin\s+der\s+assistent)/iu', $norm_msg )
				) {
					// Skip initial greeting message to prevent language pollution.
					continue;
				}
			}
			$filtered_history[] = $rm;
		}

		// Enforce sliding context window boundary.
		if ( count( $filtered_history ) > $sliding_window_size ) {
			$filtered_history = array_slice( $filtered_history, -$sliding_window_size );
		}

		$chat_messages = array(
			array( 'role' => 'system', 'content' => $system_prompt ),
		);

		$total_history = count( $filtered_history );
		foreach ( $filtered_history as $i => $rm ) {
			$msg_text = $rm['message'];
			if ( 'assistant' === $rm['role'] ) {
				$msg_text = self::sanitize_response_urls(
					$msg_text,
					array(),
					$business_info,
					$response_config,
					false,
					$resolved_language,
					array()
				);

				// Bound length of past assistant turns to 600 chars to avoid TPM exhaustion.
				if ( mb_strlen( $msg_text ) > 600 ) {
					$msg_text = mb_substr( $msg_text, 0, 600 ) . '...';
				}
			}

			// Fix 3: Isolate the User Query at the VERY END of the Prompt with an Explicit Language Lock
			if ( $i === $total_history - 1 && 'user' === $rm['role'] ) {
				$msg_text = "USER QUESTION:\n" . $msg_text . "\n\n"
					. "CRITICAL OUTPUT RULE: Analyze the language of the 'USER QUESTION' above. "
					. "Respond entirely and strictly in the user's language. "
					. "Do not switch to another language because technical terms appear in multiple languages or in the Knowledge Base context.";
			}

			$chat_messages[] = array(
				'role'    => $rm['role'],
				'content' => $msg_text,
			);
		}

		// 10. Execute AI Model Call via Key Pool.
		$default_models = array(
			'groq'    => 'llama-3.1-8b-instant',
			'openai'  => 'gpt-4o-mini',
			'mistral' => 'mistral-small-latest',
		);

		$tone          = $agent['tone'] ?? 'friendly';
		$answer_length = $agent['answer_length'] ?? 'medium';

		// Calibrate sampling temperature by Tone of Voice.
		$tone_temperatures = array(
			'concise'      => 0.3,
			'technical'    => 0.35,
			'formal'       => 0.4,
			'professional' => 0.5,
			'helpful'      => 0.6,
			'sales'        => 0.65,
			'empathetic'   => 0.65,
			'friendly'     => 0.7,
			'casual'       => 0.7,
		);
		$temperature = $tone_temperatures[ $tone ] ?? (float) ( $providers_config['temperature'] ?? 0.7 );

		// Enforce token budget strictly based on Answer Length (capped at 500-800 tokens max to prevent TPM quota exhaustion).
		$is_devanagari = in_array( $persisted_language, array( 'ne', 'hi' ), true );
		switch ( $answer_length ) {
			case 'short':
				$max_tokens = $is_devanagari ? 260 : 220;
				break;
			case 'long':
				$configured_max = (int) ( $providers_config['max_tokens'] ?? 750 );
				$max_tokens     = max( 500, min( 800, $configured_max ) );
				break;
			case 'custom':
				$custom_len_text = strtolower( (string) ( $agent['custom_answer_length'] ?? '' ) );
				if ( preg_match( '/(\d+)\s*(?:word|worte|palabras|mots|parole|शब्द)/i', $custom_len_text, $m ) ) {
					$words      = (int) $m[1];
					$max_tokens = max( 100, min( 800, (int) ceil( $words * 2.2 ) ) );
				} else {
					$max_tokens = $is_devanagari ? 500 : 400;
				}
				break;
			case 'medium':
			default:
				$max_tokens = $is_devanagari ? 550 : 450;
				break;
		}

		// Optimization 1: Hardcode temperature to 0.1 for strict factual grounding and zero hallucinations.
		$options = array(
			'model'       => $providers_config['chat_model'] ?? ( $default_models[ $chat_provider_id ] ?? 'llama-3.1-8b-instant' ),
			'temperature' => 0.1,
			'max_tokens'  => $max_tokens,
			'top_p'       => (float) ( $providers_config['top_p'] ?? 1.0 ),
		);

		// Per-language generation tuning. These settings are configured
		// per language and adjust generation parameters for accuracy and
		// naturalness (for example more tokens for Devanagari, a slightly
		// lower temperature for Nepali). The persisted language is used
		// because it is always concrete, whereas the resolved language is
		// "auto" on the first undetected turn.
		$options = self::apply_language_optimizations( $options, $persisted_language, $language_settings );

		// Hardcode temperature strictly to 0.1 to prevent model extrapolation or false positive assumptions.
		$options['temperature'] = 0.1;

		$strategy = $providers_config['rotation_strategy'] ?? 'priority';

		if ( 'groq' === $chat_provider_id ) {
			$result = KeyPool::execute_with_failover(
				$chat_provider,
				$chat_messages,
				$options,
				$strategy,
				$fallback_provider,
				$fallback_key
			);
		} elseif ( $chat_provider ) {
			$result = $chat_provider->chat( $chat_messages, $options, $chat_key );
		} else {
			$result = array(
				'content'     => '',
				'tokens'      => 0,
				'error'       => __( 'The selected chat provider is not registered.', 'ai-chatbot' ),
				'status_code' => 0,
			);
		}

		/*
		 * Failure detection.
		 *
		 * KeyPool used to return a canned "I'm very busy" sentence as the
		 * content, which made a rate limit indistinguishable from a real
		 * answer: nothing was logged and the contact button stayed hidden.
		 * It now returns an explicit is_error flag, which is checked first.
		 */
		$provider_failed = ! empty( $result['is_error'] ) || empty( $result['content'] );

		if ( $provider_failed ) {
			/*
			 * The key pool's error code separates a temporary rate
			 * limit (retrying may help) from a permanent key shortage
			 * (retrying cannot help), so the visitor gets an accurate,
			 * professional reply instead of a casual "I'm busy".
			 */
			$status = (int) ( $result['status_code'] ?? 0 );
			$error  = trim( (string) ( $result['error'] ?? '' ) );

			$err_type = 'generic';
			if ( 'no_keys_available' === $error || 503 === $status ) {
				$err_type = 'no_keys_available';
			} elseif ( 'rate_limited' === $error || 429 === $status ) {
				$err_type = 'rate_limited';
			} elseif ( in_array( $status, array( 401, 403 ), true ) ) {
				$err_type = 'auth_error';
			}

			$reply = LanguageService::get_error_message( $err_type, $resolved_language );

			// Keep the real cause for the administrator, never for the visitor.
			$raw_detail = '' !== $error ? $error : ( ! empty( $result['raw'] ) ? wp_json_encode( $result['raw'] ) : ( 'HTTP status ' . $status ) );
			error_log( sprintf(
				'[AI Chatbot] Chat fallback triggered (err_type=%s, provider=%s, status=%d): %s',
				$err_type,
				$chat_provider_id,
				$status,
				$raw_detail
			) );

			update_option( 'ai_chatbot_last_chat_error', $error ? $error : ( 'HTTP ' . $status ), false );
		} else {
			$reply = (string) $result['content'];

			// 10b. Response validation check before sending.
			if ( 'auto' !== $resolved_language && '' !== $resolved_language ) {
				$validation = LanguageService::validate_response( $reply, $resolved_language );

				if ( ! $validation['valid'] ) {
					$fix_messages = array(
						array(
							'role'    => 'system',
							'content' => LanguageService::get_translation_system_prompt( $resolved_language ),
						),
						array(
							'role'    => 'user',
							'content' => $reply,
						),
					);
					$fix_opts = array_merge( $options, array( 'temperature' => 0.2, 'max_tokens' => (int) round( $options['max_tokens'] * 1.2 ) ) );

					$fix_result = null;
					if ( 'groq' === $chat_provider_id ) {
						$fix_result = KeyPool::execute_with_failover(
							$chat_provider,
							$fix_messages,
							$fix_opts,
							$strategy,
							$fallback_provider,
							$fallback_key
						);
					} elseif ( $chat_provider ) {
						$fix_result = $chat_provider->chat( $fix_messages, $fix_opts, $chat_key );
					}

					if ( ! empty( $fix_result['content'] ) && empty( $fix_result['is_error'] ) ) {
						$reply = (string) $fix_result['content'];
					}
				}
			}
		}

		// 10c. Check for knowledge gap / fallback trigger before sanitizing URLs.
		$reply_lower = strtolower( $reply );
		$is_fallback = str_contains( $reply_lower, "don't have enough information" )
			|| str_contains( $reply_lower, "do not have enough information" )
			|| str_contains( $reply_lower, "leider habe ich dazu keine informationen" )
			|| str_contains( $reply_lower, "keine informationen auf dieser website" )
			|| ( $top_score < 0.60 && ! empty( $retrieved_chunks ) );

		if ( $is_fallback ) {
			// Capture in Unanswered Questions Inbox.
			UnansweredInbox::log_question( $agent_id, $message, $chat_provider, $classifier_key );
		}

		// Sanitize URLs to prevent dead links, hallucinations, and format Link Style.
		$reply = self::sanitize_response_urls(
			$reply,
			$retrieved_chunks,
			$business_info,
			$response_config,
			$is_explicit_link_request,
			$resolved_language,
			$verified_links,
			$message,
			$is_fallback,
			$provider_failed
		);

		// Strip any internal [HUMAN_HANDOFF] token cleanly from reply.
		$reply = trim( (string) preg_replace( '/\s*\[?HUMAN_HANDOFF\]?\s*/i', ' ', $reply ) );

		$tokens = (int) ( $result['tokens'] ?? 0 );

		// Contact form injection is completely disabled; reply remains strictly in plain text/Markdown.
		$offer_contact = false;

		// 12. Save assistant response message.
		$wpdb->insert(
			$table_msg,
			array(
				'conversation_id' => $conversation_id,
				'role'            => 'assistant',
				'message'         => $reply,
				'tokens'          => $tokens,
				'created_at'      => current_time( 'mysql' ),
			)
		);
		$assistant_msg_id = (int) $wpdb->insert_id;

		// Track linked URLs in conversation metadata for deduplication across the conversation.
		$newly_linked = array();
		if ( preg_match_all( '#\[[^\]]+\]\((https?://[^\)\s]+)\)#i', $reply, $url_matches ) ) {
			foreach ( $url_matches[1] as $u ) {
				if ( class_exists( '\AIChatbot\Links\LinkRegistry' ) ) {
					$newly_linked[] = strtolower( rtrim( \AIChatbot\Links\LinkRegistry::normalize_url( $u ), '/' ) );
				}
			}
		}
		if ( ! empty( $newly_linked ) ) {
			$existing_linked = (array) ( $conv_metadata['linked_urls'] ?? array() );
			$conv_metadata['linked_urls'] = array_values( array_unique( array_merge( $existing_linked, $newly_linked ) ) );
			$wpdb->update(
				$table_conv,
				array( 'metadata' => wp_json_encode( $conv_metadata ) ),
				array( 'id' => $conversation_id ),
				array( '%s' ),
				array( '%d' )
			);
		}

		$questions = ! empty( $agent['suggested_questions'] ) ? json_decode( $agent['suggested_questions'], true ) : array();
		$localized_questions = LanguageService::localize_suggested_questions( is_array( $questions ) ? $questions : array(), $resolved_language );

		// Cache successful response with link settings hash.
		if ( ! $provider_failed && ! $is_fallback && ! empty( $reply ) ) {
			set_transient(
				$cache_key,
				array(
					'reply'               => $reply,
					'tokens'              => $tokens,
					'suggested_questions' => $localized_questions,
					'language'            => $persisted_language,
					'debug_rag'           => $debug_rag,
				),
				12 * HOUR_IN_SECONDS
			);
		}

		$final_payload = array(
			'reply'               => $reply,
			'message_id'          => $assistant_msg_id,
			'user_message_id'     => $user_msg_id,
			'session_id'          => $session_id,
			'conversation_id'     => $conversation_id,
			'offer_contact'       => false,
			'handoff'             => false,
			'support_email'       => esc_attr( Settings::get_support_email( $agent_id ) ),
			'tokens'              => $tokens,
			'suggested_questions' => $localized_questions,
			'language'            => $persisted_language,
			'_debug_rag'          => $debug_rag,
			'debug'               => array(
				'chunks_retrieved'   => count( $retrieved_chunks ),
				'top_score'          => round( $top_score, 4 ),
				'resolved_language'  => $resolved_language,
				'persisted_language' => $persisted_language,
			),
		);

		$final_res  = $this->success( $final_payload );
		$final_data = $final_res->get_data();
		if ( is_array( $final_data ) ) {
			$final_data['_debug_rag'] = $debug_rag;
			$final_res->set_data( $final_data );
		}
		return $final_res;
	}

	/**
	 * Detect whether a visitor's message is explicitly requesting a link, website URL,
	 * contact page, or web address.
	 *
	 * Supports English, German, Spanish, French, and Nepali/Hindi.
	 *
	 * @param string $message Visitor message.
	 * @return bool True if the visitor explicitly asks for a link/URL.
	 */
	public static function is_explicit_link_request( string $message ): bool {
		$stripped   = preg_replace( '/[^\p{L}\p{M}\p{N}\s\-_\?\/:]/u', ' ', $message );
		$normalized = mb_strtolower( trim( preg_replace( '/\s+/u', ' ', (string) $stripped ) ) );

		if ( '' === $normalized ) {
			return false;
		}

		$patterns = array(
			// English link/URL queries
			'\b(what(?:[\'\s]s| is) (?:your |the )?(?:official |company |main )?(?:website |site )?(?:url|link|web address|page link))\b',
			'\b(?:website|site)\s+(?:url|link|address)\b',
			'\b(?:give|send|share|provide|show|drop|post|have)\b.*\b(?:the |a |your )?(?:link|url|website url|web address)\b',
			'\b(?:can|could|would)\s+(?:you|i)\s+(?:have|get|see|provide|send|share)\b.*\b(?:the |a )?(?:link|url|website|webpage)\b',
			'\b(?:link|url)\s+(?:to|for|of|please)\b',
			'\b(?:where (?:can i find|is) the (?:link|url|website))\b',
			'\b(?:do you have a (?:link|url|website))\b',

			// German link/URL queries
			'\b(?:was ist|wie lautet|wo ist)\b.*\b(?:die |der |das |ihre |eure )?(?:url|link|website|webseite|internetadresse|homepage|webadresse)\b',
			'\b(?:website|webseite|homepage|internet)-?(?:url|link|adresse)\b',
			'\b(?:link|url)\s+(?:zur|zum|zu|für|bitte)\b',
			'\b(?:schicken|senden|geben|teilen|zeigen|haben)\b.*\b(?:den |einen |die )?(?:link|url|webadresse)\b',
			'\b(?:können|kannst)\s+(?:sie|du)\s+mir\b.*\b(?:link|url|website|webseite)\b',
			'\b(?:wo finde ich (?:den |die )?(?:link|url|webseite|website))\b',
			'\b(?:haben sie (?:einen |den )?(?:link|url))\b',
			'\b(?:gibt es einen link)\b',
			'\b(?:kontakt(?:seite|link|url)?|contact\s*(?:page|link|url)?)\b',
			'\b(?:how (?:can|do) i (?:contact|reach|get in touch with)|customer support|customer service|reach out|contact details|contact information|contact page)\b',
			'\b(?:kundenservice|kundensupport|kontaktieren|kontaktdaten|kontaktinformationen|kontaktseite|ansprechpartner)\b',
			'\b(?:where (?:can i |do i )(?:see|find) (?:your |the )?([a-z0-9\s\-]+) (?:page|link|url|site))\b',
			'\b(?:wo (?:kann ich |finde ich )(?:die |den |ihre |eure )?([a-z0-9\s\-]+) (?:seite|link|url))\b',

			// Spanish link/URL queries
			'\b(?:cuál|cual)\s+es\s+(?:el |la |tu |su )?(?:enlace|link|url|sitio web|página web)\b',
			'\b(?:puedes|podría|dame|enviar|comparte|pasa)\b.*\b(?:el |un )?(?:enlace|link|url)\b',
			'\b(?:enlace|link|url)\s+(?:a|de|para|por favor)\b',
			'\b(?:dónde (?:está|encuentro) el (?:enlace|link|url))\b',

			// French link/URL queries
			'\b(?:quel est|donnez-moi|avez-vous|pouvez-vous m\'envoyer)\b.*\b(?:lien|url|site web|adresse web)\b',
			'\b(?:lien|url)\s+(?:vers|pour|du|de|s\'il vous plaît)\b',

			// Nepali / Hindi link queries (Devanagari and transliteration)
			'(?:वेबसाइट|website|साइट|site).*(?:लिंक|link|यूआरएल|url|ठेगाना)',
			'(?:लिंक|link|url|यूआरएल).*(?:दिनुहोस्|दिनुहोस|के हो|दीजिए|क्या है|चाहियो|कहाँ छ|कहाँ हो)',
			'(?:दिनुहोस्|दिनुहोस|दीजिए|चाहियो).*(?:लिंक|link|url)',
		);

		foreach ( $patterns as $pattern ) {
			if ( preg_match( '/' . $pattern . '/ui', $normalized ) ) {
				return true;
			}
		}

		if ( preg_match( '/^(?:url|link|website-link|website-url|link bitte|url bitte|link please|url please)[\s\?\!.]*$/ui', $normalized ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Whether a visitor message is small talk that needs no website grounding.
	 *
	 * Greetings, thanks and "who are you" must not be treated as "not found"
	 * just because no knowledge chunk matched, otherwise the bot looks broken.
	 *
	 * @param string $message Visitor message.
	 * @return bool
	 */
	protected static function is_conversational_message( string $message ): bool {
		$stripped = preg_replace( '/[^\p{L}\p{M}\p{N}\s]/u', ' ', $message );
		$normalized = mb_strtolower( trim( preg_replace( '/\s+/u', ' ', (string) $stripped ) ) );

		if ( '' === $normalized ) {
			return true;
		}

		$patterns = array(
			// Greetings
			'^(hi|hello|hey|hallo|hiya|servus|moin|gruss gott|guten tag|guten morgen|guten abend|hola|buenos dias|buenas tardes|buenas noches|bonjour|bonsoir|salut|ciao|buongiorno|buonasera|salve|ola|bom dia|boa tarde|boa noite|namaste|namaskar|नमस्ते|नमस्कार)\s*$',
			// Thanks & short affirmative/pleasantries
			'^(thanks|thank you|danke|dankeschon|danke schon|vielen dank|ok|okay|yes|no|na|jo|super|perfekt|bitte|gerne|gracias|muchas gracias|de nada|por favor|vale|si|merci|merci beaucoup|de rien|oui|non|d accord|grazie|grazie mille|prego|va bene|obrigado|obrigada|muito obrigado|sim|nao|dhanyabad|dhanyavad|धन्यवाद|thik cha|thik chha|thik hai|हजुर|जी|हो|होइन|हाँ|नहीं)\s*$',
			// Goodbyes
			'^(bye|goodbye|tsschuss|tschuss|auf wiedersehen|adios|chao|hasta luego|au revoir|a bientot|arrivederci|a presto|tchau|adeus|ate logo)\s*$',
			// Identity / Who are you
			'^(who are you|what are you|wer bist du|wie heisst du|what can you do|was kannst du|bist du echt|are you a bot|are you human|bist du ein bot|bist du ein mensch|quien eres|que eres|eres un bot|qui es tu|qui etes vous|chi sei|sei un bot|quem e voce|voce e um robo|तपाईं को हुनुहुन्छ|तपाईं को हो|आप कौन हैं)\s*$',
		);

		foreach ( $patterns as $pattern ) {
			if ( preg_match( '#' . $pattern . '#u', $normalized ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Build the reply used when the website contains nothing relevant.
	 *
	 * Deliberately does not call the model: a fixed answer is the only reliable
	 * way to guarantee nothing is invented when the knowledge base has no match.
	 *
	 * The wording follows the visitor's message and the configured response
	 * language, so a German site does not reply in English to a German question.
	 *
	 * @param array  $guardrail_config Guardrail settings.
	 * @param string $company_name     Company name.
	 * @param string $message          Visitor message, used to detect the language.
	 * @param string $resolved_language Language already resolved for this turn.
	 * @return string
	 */
	protected static function build_not_found_reply( array $guardrail_config, string $company_name, string $message = '', string $resolved_language = '' ): string {
		$custom = trim( (string) ( $guardrail_config['not_found_message'] ?? '' ) );

		if ( '' !== $custom ) {
			return $custom;
		}

		$lang = self::detect_reply_language( $message, $guardrail_config, $resolved_language );

		return LanguageService::get_not_found_reply( $lang, $company_name );
	}

	/**
	 * Pick the language for a fixed reply.
	 *
	 * A configured fixed language always wins. Otherwise the language
	 * already resolved for this turn is reused, so the fallback is
	 * written in the language the visitor actually used rather than
	 * always in English. Detection is delegated to the shared
	 * LanguageService so the deterministic reply and the model reply
	 * never disagree about the conversation's language.
	 *
	 * @param string $message           Visitor message.
	 * @param array  $guardrail_config  Guardrail settings, may carry a language hint.
	 * @param string $resolved_language Language already resolved for this turn.
	 * @return string Language code.
	 */
	protected static function detect_reply_language( string $message, array $guardrail_config, string $resolved_language = '' ): string {
		$forced = trim( (string) ( $guardrail_config['reply_language'] ?? '' ) );

		if ( '' !== $forced ) {
			return $forced;
		}

		// Reuse the language resolved for this turn when it is concrete.
		$resolved = LanguageService::sanitize( $resolved_language );

		if ( '' !== $resolved && 'auto' !== $resolved ) {
			return $resolved;
		}

		// Fix 1: Removed heuristic detection. Never misclassify English queries as French.
		if ( preg_match( '/[äöüß]/iu', $message ) || preg_match( '/\b(ist|sind|der|die|das|haben|fuer|f\xc3\xbcr|nicht)\b/iu', $message ) ) {
			return 'de';
		}

		return 'en';
	}

	/**
	 * Apply the per-language generation tuning.
	 *
	 * The `language_optimizations` setting maps each language to
	 * provider parameters that improve accuracy and naturalness for
	 * that language. Temperature and top_p are passed through when
	 * the tuning provides them, and max_tokens is scaled by the
	 * language's factor so scripts that need more tokens per concept
	 * (Devanagari) are not cut short. The model itself is never
	 * overridden.
	 *
	 * @param array  $options          Provider options.
	 * @param string $language         Resolved response language.
	 * @param array  $language_settings The `language` settings group.
	 * @return array Tuned options.
	 */
	protected static function apply_language_optimizations( array $options, string $language, array $language_settings ): array {
		$optimizations = $language_settings['language_optimizations'] ?? array();

		if ( ! is_array( $optimizations ) ) {
			return $options;
		}

		$tuning = $optimizations[ $language ] ?? array();

		if ( ! is_array( $tuning ) || empty( $tuning ) ) {
			return $options;
		}

		foreach ( array( 'temperature', 'top_p' ) as $key ) {
			if ( isset( $tuning[ $key ] ) && is_numeric( $tuning[ $key ] ) ) {
				$options[ $key ] = (float) $tuning[ $key ];
			}
		}

		if ( isset( $tuning['max_tokens_factor'] ) && is_numeric( $tuning['max_tokens_factor'] ) ) {
			$factor = (float) $tuning['max_tokens_factor'];

			if ( $factor > 0 ) {
				$base              = (int) ( $options['max_tokens'] ?? 800 );
				$options['max_tokens'] = (int) round( $base * $factor );
			}
		}

		return $options;
	}

	/**
	 * Dynamically resolve the site's real contact page URL.
	 *
	 * @return string Real contact URL or empty string.
	 */
	public static function resolve_site_contact_url(): string {
		// Check common slugs in order of likelihood for German/multilingual sites.
		$slugs = array( 'kontakt', 'contact', 'kontaktformular', 'kontakt-formular', 'anfrage' );
		foreach ( $slugs as $slug ) {
			$page = get_page_by_path( $slug );
			if ( $page && 'publish' === $page->post_status ) {
				return get_permalink( $page->ID );
			}
		}

		// Query by title if slug differs.
		$pages = get_posts(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
				'title'       => 'Kontakt',
				'numberposts' => 1,
			)
		);
		if ( ! empty( $pages[0] ) ) {
			return get_permalink( $pages[0]->ID );
		}

		$pages_en = get_posts(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
				'title'       => 'Contact',
				'numberposts' => 1,
			)
		);
		if ( ! empty( $pages_en[0] ) ) {
			return get_permalink( $pages_en[0]->ID );
		}

		return '';
	}

	/**
	 * Validate and sanitize URLs in the model's reply against the server-side Verified Link Registry.
	 *
	 * Core Rule: The model never invents links.
	 * 1. Swaps placeholders [[link:N]] for verified canonical URLs with descriptive anchor text.
	 * 2. Unsolicited links stripped if automatic links are disabled and request was not explicit.
	 * 3. Compares all URLs against Verified Link Registry (exact canonical match + language matching).
	 * 4. Strips unverified links (unwrapping to text) and drops unverified citation footers.
	 * 5. Real-time re-check (fast 2s HEAD) for links with last_verified > 24h.
	 * 6. Enforces max links per answer cap (default 2).
	 *
	 * @param string $reply Model reply text.
	 * @param array  $retrieved_chunks Retrieved knowledge chunks.
	 * @param array  $business_info Business profile info.
	 * @param array  $response_config Response behavior configuration.
	 * @param bool   $is_explicit_link_request Whether visitor explicitly asked for a link/URL.
	 * @param string $language Response language (e.g. 'de', 'en', 'es', 'ne').
	 * @param array  $verified_links Verified links mapped for placeholders.
	 * @return string Sanitized reply.
	 */
	public static function sanitize_response_urls(
		string $reply,
		array $retrieved_chunks = array(),
		array $business_info = array(),
		array $response_config = array(),
		bool $is_explicit_link_request = false,
		string $language = 'de',
		array $verified_links = array(),
		string $message = '',
		bool $is_fallback = false,
		bool $provider_failed = false
	): string {
		if ( '' === trim( $reply ) ) {
			return $reply;
		}

		$site_url  = home_url();
		$site_host = wp_parse_url( $site_url, PHP_URL_HOST );

		// Strip pseudo-buttons, markdown placeholders, "(button)" text, or handoff triggers.
		$reply = preg_replace( '/\s*\(button\)/i', '', $reply );
		$reply = preg_replace( '/\s*\[button\]/i', '', $reply );
		$reply = preg_replace( '/\[Contact a Human\](?:\s*\(button\))?/i', 'Contact Our Team', $reply );
		$reply = preg_replace( '/Contact a Human\s*\(button\)/i', 'Contact Our Team', $reply );
		$reply = preg_replace( '/\[Mit einem Menschen sprechen\](?:\s*\(button\))?/i', 'Unser Team kontaktieren', $reply );
		$reply = preg_replace( '/Mit einem Menschen sprechen\s*\(button\)/i', 'Unser Team kontaktieren', $reply );
		$reply = preg_replace( '/\[Email Our Team\](?:\s*\(button\))?/i', 'Email Our Team', $reply );
		$reply = preg_replace( '/Email Our Team\s*\(button\)/i', 'Email Our Team', $reply );
		$reply = preg_replace( '/\s*\[?HUMAN_HANDOFF\]?\s*/i', ' ', $reply );

		// Link configuration.
		$max_links  = isset( $response_config['max_links_per_answer'] )
			? max( 1, min( 3, (int) $response_config['max_links_per_answer'] ) )
			: 3;
		$link_style = $response_config['link_style'] ?? 'inline';

		$auto_links = ! empty( $response_config['auto_links_verified'] )
			|| ( isset( $response_config['auto_links_citations'] ) && ! empty( $response_config['auto_links_citations'] ) );
		$allow_requested = ! isset( $response_config['allow_requested_links'] ) || ! empty( $response_config['allow_requested_links'] );

		// When verified links are matched or requested, links are allowed.
		$links_allowed = ( $auto_links && ! empty( $verified_links ) ) || ( $allow_requested && $is_explicit_link_request );

		// Check non-linkable conditions.
		$is_conversational      = '' !== $message && self::is_conversational_message( $message );
		$is_clarifying_question = preg_match( '/\b(?:könnten sie|koennten sie|kannst du|können sie|präzisieren|genauer erläutern|could you clarify|please clarify|can you specify|did you mean|meinten sie)\b/ui', $reply );

		$suppress_automatic_links = (
			$provider_failed ||
			( empty( $verified_links ) && ( $is_fallback || ( $is_conversational && ! $is_explicit_link_request ) || ( $is_clarifying_question && ! $is_explicit_link_request ) ) )
		);

		// If links are disallowed or suppressed:
		if ( ! $links_allowed || 0 === $max_links || $suppress_automatic_links ) {
			$reply = preg_replace( '/\[\[(?:LINK_|link:)\d+\]\]/i', '', $reply );
			$reply = self::strip_citation_footers( $reply );
			$reply = LinkPostProcessor::strip_trailing_link_blocks( $reply );

			// Unwrap all markdown links.
			$reply = preg_replace_callback(
				'#\[([^\]\r\n]+)\]\((https?://[^\)\s]+)\)#i',
				function ( $m ) {
					$label = trim( $m[1] );
					return preg_match( '#^https?://#i', $label ) ? '' : $label;
				},
				$reply
			);

			// Strip bare URLs.
			$reply = preg_replace_callback(
				'#(?<!@)(?<=^|\s)(https?://[^\s\)<>]+)(?=\s|$|[.,;:!?])#i',
				function () {
					return '';
				},
				$reply
			);

			$reply = LinkPostProcessor::clean_dangling_links( $reply );
			return self::clean_whitespace( $reply );
		}

		// Normalize localhost URLs if live host is available.
		if ( ! empty( $site_host ) && 'localhost' !== $site_host && ! str_contains( $site_host, '127.0.0.1' ) ) {
			$reply = preg_replace( '#http://localhost(?:/[a-zA-Z0-9_\-\.]+)?(/|$)#i', $site_url . '$1', $reply );
		}

		// Mask protected fragments (headings, bold-only lines) so auto-linking does not modify them.
		$masked_blocks = array();
		$mask_index    = 0;

		// 1. Headings (# Title)
		$reply = preg_replace_callback(
			'/^(#{1,6}\s+.*)$/m',
			function ( $m ) use ( &$masked_blocks, &$mask_index ) {
				$key = '%%%HEADING_' . ( $mask_index++ ) . '%%%';
				$masked_blocks[ $key ] = $m[0];
				return $key;
			},
			$reply
		);

		// 2. Bold-only lines (e.g. **Wichtiger Hinweis**)
		$reply = preg_replace_callback(
			'/^(\s*\*\*[^*]+\*\*\s*)$/m',
			function ( $m ) use ( &$masked_blocks, &$mask_index ) {
				$key = '%%%BOLD_LINE_' . ( $mask_index++ ) . '%%%';
				$masked_blocks[ $key ] = $m[0];
				return $key;
			},
			$reply
		);

		// Build allowed links map for LinkPostProcessor.
		$allowed_map = array();
		foreach ( $verified_links as $vl ) {
			$idx = (int) ( $vl['index'] ?? 0 );
			if ( $idx > 0 ) {
				$allowed_map[ $idx ] = $vl;
			}
		}

		$post_context = array(
			'allowed_links'    => $allowed_map,
			'language'         => $language,
			'knowledge_chunks' => $retrieved_chunks,
			'business_info'    => $business_info,
			'response_config'  => $response_config,
		);

		// Execute deterministic linking pipeline (Steps 3a-3e + step 3f strip + step 5 safety assertion).
		// CORE RULE: All links are strictly inline. Never append trailing link blocks.
		$reply = LinkPostProcessor::process( $reply, $post_context );

		// Strip unwanted/hallucinated citation dumps and trailing link blocks.
		$reply = self::strip_citation_footers( $reply );
		$reply = LinkPostProcessor::strip_trailing_link_blocks( $reply );

		// Restore masked protected fragments.
		if ( ! empty( $masked_blocks ) ) {
			$reply = strtr( $reply, $masked_blocks );
		}

		return self::clean_whitespace( $reply );
	}

	/**
	 * Strip trailing citation footers from the end of a response.
	 *
	 * @param string $text Input text.
	 * @return string Stripped text.
	 */
	public static function strip_citation_footers( string $text ): string {
		$citation_prefixes = '(?:weitere\s+informationen|mehr\s+informationen|weitere\s+infos|weitere\s+details|weitere\s+quellen|informationen|quelle|quellen|source|sources|reference|references|ref|referenz|link|links|website|webseite|hier\s+erfahren\s+sie\s+mehr|erfahren\s+sie\s+mehr|read\s+more|learn\s+more|for\s+more\s+information|for\s+more\s+details|details|see\s+more|consulte|más\s+información|mas\s+informacion|fuente|fuentes|plus\s+d\'informations|en\s+savoir\s+plus|fonte|fontes|maggiore\s+informazione|विस्तृत\s*जानकारी|थप\s*जानकारी)';

		do {
			$prev = $text;

			// Strip labeled citation line at the end.
			$text = preg_replace(
				'/(?:\r?\n)+\s*' . $citation_prefixes . '\s*[:\-]?\s*(?:\[[^\]]*\]\([^\)]*\)|https?:\/\/[^\s\)\],<]+|[^\r\n]*https?:\/\/[^\s\)\],<]+)?\s*$/ui',
				'',
				$text
			);

			// Strip trailing bare URL on its own line.
			$text = preg_replace(
				'/(?:\r?\n)+\s*(?:\[[^\]]*\]\()?https?:\/\/[^\s\)\],<]+(?:\))?\s*$/i',
				'',
				$text
			);

			// Strip trailing citation prefix alone left on a line.
			$text = preg_replace(
				'/(?:\r?\n)+\s*' . $citation_prefixes . '\s*[:\-]?\s*$/ui',
				'',
				$text
			);
		} while ( $text !== $prev );

		return $text;
	}

	/**
	 * Clean up dangling prepositions, empty placeholders, and grammatical artifacts left when links are stripped.
	 *
	 * Prevents blanks such as "visit our official contact page at ." or "erreichen Sie uns unter ."
	 *
	 * @param string $text Text to clean.
	 * @return string Cleaned text.
	 */
	public static function cleanup_dangling_link_artifacts( string $text ): string {
		// 1. Remove empty markdown link syntax: [](), [ ](), [](url), [text]()
		$text = preg_replace( '#\[\s*\]\([^\)]*\)#', '', $text );
		$text = preg_replace( '#\[([^\]\n]+)\]\(\s*\)#', '$1', $text );
		$text = preg_replace( '#\[\s*\]#', '', $text );

		// 2. Remove stray placeholder tokens [[link:N]] or unclosed placeholders
		$text = preg_replace( '/\[\[link:\d+\]\]/i', '', $text );
		$text = preg_replace( '/\[\[link:[^\]]*\]?/i', '', $text );

		// 3. Clean specific dangling phrases ending with prepositions before punctuation:
		// "visit our official contact page at ." -> "visit our official contact page."
		$text = preg_replace(
			'#\b(official\s+contact\s+page|contact\s+page|website|homepage|site|kontaktseite|webseite)\s+(?:at|on|under|unter|auf|über|en|sur)\s*([.,;:!?])#ui',
			'$1$2',
			$text
		);

		// "reach us at ." / "erreichen sie uns unter ."
		$text = preg_replace(
			'#\b(reach\s+(?:us|our\s+team)|contact\s+(?:us|our\s+team)|erreichen\s+sie\s+uns)\s+(?:at|under|unter|bei)\s*([.,;:!?])#ui',
			'$1 directly$2',
			$text
		);

		// 4. Clean double prepositions created by URL stripping: e.g. "at for details" -> "for details"
		$text = preg_replace( '/\b(?:at|on|under|unter|auf|über|bei)\s+(for|to|with|and|or|in|mit|zu|für|und|oder)\b/ui', '$1', $text );

		// 5. Clean general dangling prepositions before punctuation:
		// e.g. "at .", "at ,", "under .", "unter .", "unter: .", "auf .", "bei ."
		$prepositions = '(?:at|on|under|via|visit|see|unter|auf|über|bei|siehe|en|sur|à|por|para)';
		$text = preg_replace( '/(?:\s+' . $prepositions . ')\s*[:\-]?\s*([.,;:!?])/ui', '$1', $text );

		// 6. Clean trailing dangling preposition at the very end of string:
		$text = preg_replace( '/(?:\s+' . $prepositions . ')\s*[:\-]?\s*$/ui', '.', $text );

		// 7. Clean dangling colons or commas before sentence-ending punctuation: e.g. "page: ." -> "page.", ", ." -> "."
		$text = preg_replace( '/[:,;]\s*([.!?])/', '$1', $text );

		// 8. Clean space before punctuation: "page ." -> "page."
		$text = preg_replace( '/\s+([.,;:!?])/', '$1', $text );

		// 9. Clean double punctuation (e.g. "..", avoiding "..." ellipsis)
		$text = preg_replace( '/(?<!\.)\.\.(?!\.)/', '.', $text );

		// 10. Clean double spaces
		$text = preg_replace( '/[ \t]{2,}/', ' ', $text );
		$text = preg_replace( '/(\r?\n){3,}/', "\n\n", $text );

		return trim( $text );
	}

	/**
	 * Clean up double spaces, broken empty links, and excessive blank lines.
	 *
	 * @param string $text Text.
	 * @return string Cleaned text.
	 */
	public static function clean_whitespace( string $text ): string {
		$text = self::cleanup_dangling_link_artifacts( $text );
		$text = preg_replace( '#\[\s*\]\(\s*\)#', '', $text );
		$text = preg_replace( '/[ \t]{2,}/', ' ', $text );
		$text = preg_replace( '/(\r?\n){3,}/', "\n\n", $text );
		return trim( $text );
	}
}
