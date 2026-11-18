<?php
/**
 * One-Paste Setup Wizard coordinator.
 *
 * @package AIChatbot\Wizard
 */

namespace AIChatbot\Wizard;

use AIChatbot\AI\AIProviderInterface;
use AIChatbot\Knowledge\KnowledgeManager;
use AIChatbot\Knowledge\Indexer;

defined( 'ABSPATH' ) || exit;

/**
 * Class SetupWizard.
 */
class SetupWizard {

	/**
	 * Number of pages crawled by the wizard.
	 *
	 * Deliberately bounded even though website import is unlimited: the wizard
	 * runs inside a single REST request and feeds every crawled page into one
	 * AI analysis call, so an unbounded crawl would exceed the request timeout
	 * and exhaust the model's context window long before it finished.
	 */
	public const CRAWL_PAGES = 10;

	/**
	 * Run wizard analysis on target URL.
	 *
	 * @param string              $url Website URL to scan.
	 * @param AIProviderInterface $provider AI Provider.
	 * @param string              $api_key API Key.
	 * @param int                 $agent_id Agent ID.
	 * @return array
	 */
	public static function run( string $url, AIProviderInterface $provider, string $api_key, int $agent_id = 1 ): array {
		$transient_key = 'ai_wizard_state_' . $agent_id;

		// 1. Update state: Crawling.
		set_transient(
			$transient_key,
			array(
				'step'     => 'crawling',
				'progress' => 20,
				'message'  => __( 'Scanning domain and crawling key pages...', 'ai-chatbot' ),
				'pages'    => array(),
			),
			HOUR_IN_SECONDS
		);

		$pages = SiteCrawler::crawl( $url, self::CRAWL_PAGES, SiteCrawler::INDEX_TEXT_LIMIT );

		if ( empty( $pages ) ) {
			$err = array(
				'step'     => 'failed',
				'progress' => 0,
				'message'  => __( 'Could not crawl the website. Please check the URL or try adding knowledge sources manually.', 'ai-chatbot' ),
			);
			set_transient( $transient_key, $err, HOUR_IN_SECONDS );
			return $err;
		}

		// Never persist raw HTML into the transient; keep title and text only.
		$pages = array_map(
			static function ( array $page ): array {
				unset( $page['html'] );
				return $page;
			},
			$pages
		);

		// 2. Update state: Drafting.
		set_transient(
			$transient_key,
			array(
				'step'     => 'drafting',
				'progress' => 50,
				'message'  => sprintf( __( 'Read %d pages. Drafting business facts, persona, and starter Q&A with AI...', 'ai-chatbot' ), count( $pages ) ),
				'pages'    => $pages,
			),
			HOUR_IN_SECONDS
		);

		$combined_text = '';
		foreach ( $pages as $p ) {
			$combined_text .= "--- Page: {$p['title']} ({$p['url']}) ---\n" . mb_substr( $p['text'], 0, 1500, 'UTF-8' ) . "\n\n";
		}

		$draft = self::generate_draft( $combined_text, $pages[0]['url'] ?? $url, $provider, $api_key );

		// 3. Update state: Ready for Review.
		$final_state = array(
			'step'     => 'review',
			'progress' => 100,
			'message'  => __( 'Analysis complete! Review and approve the drafted settings.', 'ai-chatbot' ),
			'pages'    => $pages,
			'draft'    => $draft,
		);

		set_transient( $transient_key, $final_state, HOUR_IN_SECONDS );

		return $final_state;
	}

	/**
	 * Call AI model to parse site text and produce structured configuration draft.
	 *
	 * @param string              $site_text Combined page text.
	 * @param string              $root_url Home URL.
	 * @param AIProviderInterface $provider Provider.
	 * @param string              $api_key Key.
	 * @return array
	 */
	protected static function generate_draft( string $site_text, string $root_url, AIProviderInterface $provider, string $api_key ): array {
		$system = <<<SYS
You are an expert system setting up a corporate AI Chatbot for a website.
Analyze the provided website text and output ONLY valid JSON matching this schema:
{
  "business_info": {
    "business_name": "...",
    "description": "...",
    "industry": "...",
    "website": "{$root_url}",
    "email": "...",
    "phone": "...",
    "address": "...",
    "business_hours": "...",
    "services": "...",
    "contact_url": "..."
  },
  "agent": {
    "name": "...",
    "role": "...",
    "tone": "friendly",
    "greeting": "Hello! Welcome to {{company_name}}. How can I help you today?",
    "allowed_topics": "..."
  },
  "suggested_questions": [
    "Question 1",
    "Question 2",
    "Question 3",
    "Question 4"
  ],
  "starter_qa": [
    {"question": "...", "answer": "..."}
  ]
}
RULES:
1. NEVER invent facts. Every value you output must be supported by the provided website text. If the website does not state a fact, omit that field or leave it as an empty string "".
2. Suggested questions must reflect what this specific site actually offers.
3. Keep the tone appropriate for the industry (friendly, professional, or technical).
4. Output valid JSON only, without markdown fences or backticks.
5. For "starter_qa": write each answer using ONLY service names, products and terms that literally appear in the provided website text. Do not generalise, do not infer an industry the site does not state, and do not add capabilities the site never mentions. If you cannot ground an answer in the text, return fewer starter_qa entries rather than inventing one.
6. Prefer an empty "starter_qa": [] over a single speculative answer.
SYS;

		$messages = array(
			array( 'role' => 'system', 'content' => $system ),
			array( 'role' => 'user', 'content' => "WEBSITE CONTENT:\n\n" . $site_text ),
		);

		$options = array(
			'model'       => 'openai' === $provider->get_id() ? 'gpt-4o-mini' : 'llama-3.3-70b-versatile',
			'temperature' => 0.2,
			'max_tokens'  => 1500,
		);

		$res = $provider->chat( $messages, $options, $api_key );

		if ( ! empty( $res['content'] ) ) {
			$raw = trim( $res['content'] );
			// Strip code fences if present.
			$raw  = preg_replace( '/^```json\s*/i', '', $raw );
			$raw  = preg_replace( '/^```\s*/i', '', $raw );
			$raw  = preg_replace( '/\s*```$/', '', $raw );
			$json = json_decode( $raw, true );
			if ( is_array( $json ) ) {
				if ( empty( $json['business_info']['contact_url'] ) || preg_match( '#/(?:contact-us|contact)/?$#i', (string) ( $json['business_info']['contact_url'] ?? '' ) ) ) {
					$real_contact = class_exists( '\AIChatbot\Rest\ChatController' ) ? \AIChatbot\Rest\ChatController::resolve_site_contact_url() : '';
					if ( ! empty( $real_contact ) ) {
						$json['business_info']['contact_url'] = $real_contact;
					}
				}
				return $json;
			}
		}

		$real_contact = class_exists( '\AIChatbot\Rest\ChatController' ) ? \AIChatbot\Rest\ChatController::resolve_site_contact_url() : '';

		// Fallback draft if parsing failed.
		return array(
			'business_info'       => array(
				'business_name'  => get_bloginfo( 'name' ),
				'description'    => get_bloginfo( 'description' ),
				'industry'       => 'General Business',
				'website'        => $root_url,
				'email'          => '',
				'phone'          => '',
				'address'        => '',
				'business_hours' => '',
				'services'       => '',
				'contact_url'    => $real_contact,
			),
			'agent'               => array(
				'name'           => get_bloginfo( 'name' ) . ' Assistant',
				'role'           => 'You are a helpful assistant for ' . get_bloginfo( 'name' ) . '.',
				'tone'           => 'friendly',
				'greeting'       => 'Hello! Welcome to {{company_name}}. How can I assist you today?',
				'allowed_topics' => 'Our business, services, contact information, hours, and support.',
			),
			'suggested_questions' => array(
				'What services do you offer?',
				'How can I get in touch?',
				'Where are you located?',
				'What are your hours?',
			),
			'starter_qa'          => array(),
		);
	}

	/**
	 * Apply approved drafted settings into agent and knowledge tables.
	 *
	 * @param array $approved Approved values submitted by admin.
	 * @param int   $agent_id Agent ID.
	 * @return bool
	 */
	public static function apply( array $approved, int $agent_id = 1 ): bool {
		global $wpdb;
		$table_agents   = $wpdb->prefix . 'ai_agents';
		$table_sources  = $wpdb->prefix . 'ai_knowledge_sources';
		$table_settings = $wpdb->prefix . 'ai_settings';

		// 1. Update Agent.
		if ( ! empty( $approved['agent'] ) ) {
			$ag = $approved['agent'];

			$questions = array();
			if ( ! empty( $approved['suggested_questions'] ) && is_array( $approved['suggested_questions'] ) ) {
				foreach ( $approved['suggested_questions'] as $i => $q_text ) {
					$clean_q = is_array( $q_text ) ? ( $q_text['text'] ?? '' ) : (string) $q_text;
					if ( ! empty( trim( $clean_q ) ) ) {
						$questions[] = array(
							'id'         => 'wiz_' . ( $i + 1 ),
							'text'       => sanitize_text_field( $clean_q ),
							'icon'       => 'chat',
							'enabled'    => true,
							'sort_order' => $i,
						);
					}
				}
			}

			$wpdb->update(
				$table_agents,
				array(
					'name'                => sanitize_text_field( $ag['name'] ?? 'AI Assistant' ),
					'role'                => sanitize_textarea_field( $ag['role'] ?? '' ),
					'tone'                => sanitize_text_field( $ag['tone'] ?? 'friendly' ),
					'greeting'            => sanitize_textarea_field( $ag['greeting'] ?? '' ),
					'suggested_questions' => ! empty( $questions ) ? wp_json_encode( $questions ) : null,
					'updated_at'          => current_time( 'mysql' ),
				),
				array( 'id' => $agent_id ),
				array( '%s', '%s', '%s', '%s', '%s', '%s' ),
				array( '%d' )
			);

			// Update scope in settings.
			if ( ! empty( $ag['allowed_topics'] ) ) {
				$wpdb->replace(
					$table_settings,
					array(
						'agent_id'      => $agent_id,
						'setting_group' => 'guardrails',
						'setting_key'   => 'allowed_topics',
						'setting_value' => sanitize_textarea_field( $ag['allowed_topics'] ),
					),
					array( '%d', '%s', '%s', '%s' )
				);
			}
		}

		// 2. Save Business Information.
		if ( ! empty( $approved['business_info'] ) && is_array( $approved['business_info'] ) ) {
			if ( empty( $approved['business_info']['contact_url'] ) || preg_match( '#/(?:contact-us|contact)/?$#i', (string) ( $approved['business_info']['contact_url'] ?? '' ) ) ) {
				$real_contact = class_exists( '\AIChatbot\Rest\ChatController' ) ? \AIChatbot\Rest\ChatController::resolve_site_contact_url() : '';
				if ( ! empty( $real_contact ) ) {
					$approved['business_info']['contact_url'] = $real_contact;
				} elseif ( ! empty( $approved['business_info']['contact_url'] ) && preg_match( '#/(?:contact-us|contact)/?$#i', (string) $approved['business_info']['contact_url'] ) ) {
					unset( $approved['business_info']['contact_url'] );
				}
			}

			$biz_id = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT id FROM {$table_sources} WHERE agent_id = %d AND type = 'business_info'",
					$agent_id
				)
			);

			if ( $biz_id ) {
				$wpdb->update(
					$table_sources,
					array(
						'content'    => wp_json_encode( $approved['business_info'] ),
						'status'     => 'indexed',
						'updated_at' => current_time( 'mysql' ),
					),
					array( 'id' => $biz_id ),
					array( '%s', '%s', '%s' ),
					array( '%d' )
				);
			} else {
				KnowledgeManager::add_source(
					array(
						'agent_id' => $agent_id,
						'type'     => 'business_info',
						'title'    => 'Business Profile & Contact Facts',
						'content'  => wp_json_encode( $approved['business_info'] ),
					)
				);
			}
		}

		// 3. Save Starter Q&A pairs, but only those grounded in the crawled text.
		if ( ! empty( $approved['starter_qa'] ) && is_array( $approved['starter_qa'] ) ) {
			/*
			 * The wizard drafts these with a language model, and a draft answer
			 * can drift into services the site never mentions. A curated Q&A is
			 * served in preference to page content, so an invented one silently
			 * becomes the company's public answer. Every answer must therefore
			 * be traceable to the crawled text before it is stored.
			 */
			$grounding_text = self::build_grounding_text( $approved, $agent_id );

			foreach ( $approved['starter_qa'] as $qa ) {
				$q = trim( $qa['question'] ?? '' );
				$a = trim( $qa['answer'] ?? '' );

				if ( empty( $q ) || empty( $a ) ) {
					continue;
				}

				if ( '' !== $grounding_text && ! self::answer_is_grounded( $a, $grounding_text ) ) {
					continue;
				}

				KnowledgeManager::add_source(
					array(
						'agent_id' => $agent_id,
						'type'     => 'qa',
						'title'    => $q,
						'content'  => wp_json_encode( array( 'question' => $q, 'answer' => $a ) ),
						'metadata' => array( 'category' => 'General' ),
					)
				);
			}
		}

		// 4. Add crawled pages as webpage sources and index them.
		$crawled_pages = array();

		if ( ! empty( $approved['crawled_pages'] ) && is_array( $approved['crawled_pages'] ) ) {
			$crawled_pages = $approved['crawled_pages'];
		} else {
			// Fall back to the pages discovered during the crawl so the wizard
			// always seeds the knowledge base even if the client omits them.
			$state = get_transient( 'ai_wizard_state_' . $agent_id );
			if ( ! empty( $state['pages'] ) && is_array( $state['pages'] ) ) {
				$crawled_pages = $state['pages'];
			}
		}

		if ( ! empty( $crawled_pages ) ) {
			self::index_crawled_pages( $crawled_pages, $agent_id );
		}

		// Clear wizard transient.
		delete_transient( 'ai_wizard_state_' . $agent_id );

		return true;
	}

	/**
	 * Collect the crawled page text used to validate drafted answers.
	 *
	 * @param array $approved Approved draft payload.
	 * @param int   $agent_id Agent ID.
	 * @return string Lower-cased searchable text.
	 */
	protected static function build_grounding_text( array $approved, int $agent_id ): string {
		$pages = array();

		if ( ! empty( $approved['crawled_pages'] ) && is_array( $approved['crawled_pages'] ) ) {
			$pages = $approved['crawled_pages'];
		} else {
			$state = get_transient( 'ai_wizard_state_' . $agent_id );
			if ( ! empty( $state['pages'] ) && is_array( $state['pages'] ) ) {
				$pages = $state['pages'];
			}
		}

		$texts = array();

		foreach ( $pages as $page ) {
			if ( ! empty( $page['text'] ) ) {
				$texts[] = (string) $page['text'];
			}
			if ( ! empty( $page['title'] ) ) {
				$texts[] = (string) $page['title'];
			}
		}

		$blob = mb_strtolower( implode( "\n", $texts ) );

		// Strip accents so accented and unaccented terms match reliably.
		if ( function_exists( 'remove_accents' ) ) {
			$blob = remove_accents( $blob );
		}

		return $blob;
	}

	/**
	 * Decide whether a drafted answer is supported by the crawled text.
	 *
	 * The test is deliberately conservative: the distinctive words of an answer
	 * (anything long enough not to be filler) must appear in the page text. A
	 * drafted answer about a service the site never mentions will introduce
	 * terms that do not appear, so it is rejected.
	 *
	 * @param string $answer  Drafted answer.
	 * @param string $grounding Lower-cased crawled text.
	 * @return bool
	 */
	protected static function answer_is_grounded( string $answer, string $grounding ): bool {
		if ( '' === trim( $grounding ) ) {
			return true;
		}

		$normalised = mb_strtolower( $answer );

		if ( function_exists( 'remove_accents' ) ) {
			$normalised = remove_accents( $normalised );
		}

		// Content words worth verifying, ignoring common filler.
		$stop_words = array(
			'the', 'and', 'for', 'with', 'that', 'this', 'from', 'our', 'are', 'was',
			'were', 'has', 'have', 'had', 'not', 'but', 'you', 'your', 'we', 'its',
			'it', 'as', 'at', 'by', 'or', 'in', 'on', 'to', 'of', 'is', 'be', 'can',
			'will', 'their', 'they', 'them', 'include', 'includes', 'including',
			'such', 'also', 'into', 'such', 'provides', 'provide', 'offers', 'offer',
			'services', 'service', 'solutions', 'solution', 'business', 'company',
			'focused', 'focuses', 'specialises', 'specializes', 'der', 'die', 'das',
			'und', 'für', 'mit', 'ist', 'sind', 'werden', 'einen', 'eine', 'auch',
		);

		$words = preg_split( '/[^a-z0-9äöüß]+/u', $normalised, -1, PREG_SPLIT_NO_EMPTY );

		$checked     = 0;
		$unsupported = 0;

		foreach ( (array) $words as $word ) {
			if ( mb_strlen( $word ) < 5 || in_array( $word, $stop_words, true ) ) {
				continue;
			}

			$checked++;

			if ( ! str_contains( $grounding, $word ) ) {
				$unsupported++;
			}
		}

		// Require a clear majority of checkable terms to be present.
		if ( $checked < 2 ) {
			return true;
		}

		return ( $unsupported / $checked ) <= 0.34;
	}

	/**
	 * Store and index the pages discovered during the wizard crawl.
	 *
	 * @param array $pages    Crawled pages [ 'url', 'title', 'text' ].
	 * @param int   $agent_id Agent ID.
	 * @return array Summary with 'imported', 'indexed', 'chunks', 'failed'.
	 */
	public static function index_crawled_pages( array $pages, int $agent_id = 1 ): array {
		global $wpdb;
		$table_sources = $wpdb->prefix . 'ai_knowledge_sources';

		$summary = array(
			'imported' => 0,
			'indexed'  => 0,
			'chunks'   => 0,
			'failed'   => 0,
		);

		$credentials = self::resolve_embeddings_credentials( $agent_id );

		foreach ( $pages as $page ) {
			if ( empty( $page['url'] ) ) {
				continue;
			}

			$url        = SiteCrawler::normalize_url( $page['url'] );
			$url        = $url ? $url : esc_url_raw( $page['url'] );
			$title      = ! empty( $page['title'] ) ? $page['title'] : $url;
			$extracted  = isset( $page['text'] ) ? trim( (string) $page['text'] ) : '';

			// Skip pages that are already indexed for this agent.
			$existing = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT id FROM {$table_sources} WHERE agent_id = %d AND source_url = %s LIMIT 1",
					$agent_id,
					$url
				)
			);

			if ( $existing ) {
				continue;
			}

			$source_id = KnowledgeManager::add_source(
				array(
					'agent_id'   => $agent_id,
					'type'       => 'webpage',
					'title'      => $title,
					'source_url' => $url,
					'content'    => $extracted,
					'metadata'   => array(
						'imported_from' => 'setup_wizard',
						'page_url'      => $url,
					),
				)
			);

			if ( ! $source_id ) {
				$summary['failed']++;
				continue;
			}

			$summary['imported']++;

			// Without embeddings credentials the page is stored but not vectorized.
			if ( ! $credentials ) {
				continue;
			}

			$result = Indexer::index_source(
				$source_id,
				$credentials['provider'],
				$credentials['model'],
				$credentials['key']
			);

			if ( ! empty( $result['success'] ) ) {
				$summary['indexed']++;
				$summary['chunks'] += (int) $result['chunks'];
			} else {
				$summary['failed']++;
			}
		}

		return $summary;
	}

	/**
	 * Resolve embeddings credentials for indexing crawled pages.
	 *
	 * @param int $agent_id Agent ID.
	 * @return array|null [ 'provider', 'model', 'key' ] or null when unusable.
	 */
	protected static function resolve_embeddings_credentials( int $agent_id ): ?array {
		$settings = \AIChatbot\Settings::get_all( $agent_id, false );

		$provider_id = $settings['providers']['embeddings_provider'] ?? 'openai';

		// Each embeddings provider keeps its credential in its own settings field.
		$key_fields = array(
			'openai'  => 'openai_api_key',
			'mistral' => 'mistral_api_key',
		);

		$key_field = $key_fields[ $provider_id ] ?? $key_fields['openai'];
		$model     = $settings['providers']['embeddings_model'] ?? 'text-embedding-3-small';
		$raw_key   = \AIChatbot\Security\Encryption::decrypt( (string) ( $settings['providers'][ $key_field ] ?? '' ) );

		$provider = \AIChatbot\AI\ModelRegistry::get( $provider_id );

		if ( ! $provider || ! $provider->supports_embeddings() || empty( $raw_key ) ) {
			return null;
		}

		return array(
			'provider' => $provider,
			'model'    => $model,
			'key'      => $raw_key,
		);
	}
}
