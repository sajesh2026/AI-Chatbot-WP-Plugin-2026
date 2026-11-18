<?php
/**
 * Settings Manager.
 *
 * @package AIChatbot
 */

namespace AIChatbot;

use AIChatbot\AI\KeyPool;
use AIChatbot\Security\Encryption;

defined( 'ABSPATH' ) || exit;

/**
 * Class Settings.
 */
class Settings {

	/**
	 * Table name without prefix.
	 */
	public const TABLE_NAME = 'ai_settings';

	/**
	 * ISO-639-1 language codes the plugin supports.
	 *
	 * Only these values are accepted for fixed_language, default_language,
	 * and any entry inside enabled_languages. Adding a language here is the
	 * single place new locales must be registered to become selectable.
	 */
	public const ALLOWED_LANGUAGES = array( 'en', 'de', 'ne', 'es', 'fr', 'it', 'pt', 'hi' );

	/**
	 * Human-readable labels for the supported languages.
	 */
	public const LANGUAGE_LABELS = array(
		'en' => 'English',
		'de' => 'German (Deutsch)',
		'ne' => 'Nepali (नेपाली)',
		'es' => 'Spanish (Español)',
		'fr' => 'French (Français)',
		'it' => 'Italian (Italiano)',
		'pt' => 'Portuguese (Português)',
		'hi' => 'Hindi (हिन्दी)',
	);

	/**
	 * Per-request memoization of resolved settings.
	 *
	 * A single page request resolves the same agent's settings several
	 * times (asset enqueue, footer mount, the public /agent endpoint).
	 * Each call ran the same SELECT and re-decoded every JSON value.
	 * Caching the resolved array per request removes the duplicate
	 * queries without ever serving stale data across requests.
	 *
	 * @var array
	 */
	protected static $resolved_cache = array();

	/**
	 * Get all settings grouped for an agent.
	 *
	 * @param int  $agent_id Agent ID.
	 * @param bool $mask_keys Whether to mask encrypted API keys for frontend display.
	 * @return array
	 */
	public static function get_all( int $agent_id = 1, bool $mask_keys = true ): array {
		$cache_key = $agent_id . ':' . (int) $mask_keys;

		if ( isset( self::$resolved_cache[ $cache_key ] ) ) {
			return self::$resolved_cache[ $cache_key ];
		}

		global $wpdb;
		$table = $wpdb->prefix . self::TABLE_NAME;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT setting_group, setting_key, setting_value FROM {$table} WHERE agent_id = %d",
				$agent_id
			),
			ARRAY_A
		);

		$grouped = array();
		$defaults = self::get_defaults();

		if ( ! empty( $rows ) ) {
			foreach ( $rows as $r ) {
				$grp = $r['setting_group'];
				$key = $r['setting_key'];
				$val = $r['setting_value'];

				// Within a declared group, ignore keys that are not part of
				// the schema. Stale rows from older plugin versions (for
				// example badge timing keys stored under "theme") used to
				// leak into the merged settings and pollute the payload the
				// admin form and the widget receive. Groups that are not
				// declared in the defaults (skills_contact, skills_follow_up)
				// are passed through untouched.
				if ( isset( $defaults[ $grp ] ) && ! array_key_exists( $key, $defaults[ $grp ] ) ) {
					continue;
				}

				// The default decides the expected type for this key, so it has to be
				// resolved before the value is coerced. It used to be assigned inside
				// an `else` whose closing brace had been merged onto the same line as
				// the statement, which left $default undefined for every JSON-decoded
				// setting and turned the whole coercion chain into that else body.
				$default = $defaults[ $grp ][ $key ] ?? null;

				// Decode JSON if applicable.
				$json = json_decode( (string) $val, true );
				if ( null !== $json && is_array( $json ) ) {
					$val = $json;
				}

				// An empty stored value carries no information, so fall back to
				// the default instead of leaking '' where a bool is expected.
				if ( is_array( $val ) ) {
					// Already structured; only an empty array is worth replacing.
					if ( empty( $val ) && ! empty( $default ) ) {
						$val = $default;
					}
				} elseif ( '' === trim( (string) $val ) ) {
					$val = is_array( $default ) ? array() : $default;
				} elseif ( is_numeric( $val ) && false === strpos( (string) $val, '.' ) ) {
					/*
					 * The stored type is decided by the default for this key.
					 *
					 * Previously any string '1' or '0' was turned into a boolean
					 * before the integer branch was reached. That silently broke
					 * every integer setting whose value happens to be 0 or 1:
					 * load_delay_ms became true (1ms), nudge_after_ms became
					 * false, and max_per_day became true (always 1).
					 */
					if ( is_float( $default ) ) {
						$val = (float) $val;
					} elseif ( is_int( $default ) ) {
						$val = (int) $val;
					} else {
						$val = in_array( (string) $val, array( '1', 'true' ), true );
					}
				} elseif ( in_array( $val, array( 'true', 'false' ), true ) ) {
					$val = in_array( (string) $val, array( '1', 'true' ), true );
				} elseif ( is_numeric( $val ) ) {
					$val = (float) $val;
				}

				$grouped[ $grp ][ $key ] = $val;
			}
		}

		$defaults = self::get_defaults();

		$merged = array_replace_recursive( $defaults, $grouped );

		/*
		 * enabled_languages can carry locales from older plugin versions
		 * that supported more languages than the current ALLOWED_LANGUAGES
		 * list. The admin form cannot edit this field, so it re-sends the
		 * stored list verbatim, and the settings REST validation then
		 * rejects every save — including unrelated groups such as theme.
		 * Keep only the supported locales so the payload is always valid.
		 */
		if ( isset( $merged['language']['enabled_languages'] ) && is_array( $merged['language']['enabled_languages'] ) ) {
			$clean = array_values( array_intersect( $merged['language']['enabled_languages'], self::ALLOWED_LANGUAGES ) );

			$merged['language']['enabled_languages'] = empty( $clean )
				? $defaults['language']['enabled_languages']
				: $clean;
		}

		// Synchronize response_behavior and response groups so both keys are always available.
		if ( isset( $merged['response_behavior'] ) ) {
			$merged['response'] = $merged['response_behavior'];
		} elseif ( isset( $merged['response'] ) ) {
			$merged['response_behavior'] = $merged['response'];
		}

		if ( $mask_keys ) {
			// Mask sensitive keys.
			if ( ! empty( $merged['providers']['openai_api_key'] ) ) {
				$raw = Encryption::decrypt( (string) $merged['providers']['openai_api_key'] );
				$merged['providers']['openai_api_key'] = Encryption::mask_key( $raw );
			}
			if ( ! empty( $merged['providers']['mistral_api_key'] ) ) {
				$raw = Encryption::decrypt( (string) $merged['providers']['mistral_api_key'] );
				$merged['providers']['mistral_api_key'] = Encryption::mask_key( $raw );
			}
			if ( ! empty( $merged['email']['resend_api_key'] ) ) {
				$raw = Encryption::decrypt( (string) $merged['email']['resend_api_key'] );
				$merged['email']['resend_api_key'] = Encryption::mask_key( $raw );
			}
		}

		// Resolve dynamic email notification recipient and native WordPress delivery.
		$admin_email = (string) get_option( 'admin_email' );
		$merged['email']['delivery_method'] = 'wp_mail';
		$merged['email']['admin_email']     = $admin_email;

		if ( empty( $merged['email']['recipient_type'] ) ) {
			if ( ! empty( $merged['email']['notification_to'] ) && $merged['email']['notification_to'] !== $admin_email && is_email( $merged['email']['notification_to'] ) ) {
				$merged['email']['recipient_type'] = 'custom';
				$merged['email']['custom_email']   = $merged['email']['notification_to'];
			} else {
				$merged['email']['recipient_type'] = 'admin';
				$merged['email']['custom_email']   = '';
			}
		}

		if ( 'custom' === ( $merged['email']['recipient_type'] ?? 'admin' ) && ! empty( $merged['email']['custom_email'] ) && is_email( $merged['email']['custom_email'] ) ) {
			$merged['email']['notification_to'] = sanitize_email( $merged['email']['custom_email'] );
		} else {
			$merged['email']['recipient_type']  = 'admin';
			$merged['email']['notification_to'] = $admin_email;
		}

		if ( ! empty( $merged['email']['support_email'] ) && is_email( $merged['email']['support_email'] ) ) {
			$merged['email']['support_email'] = sanitize_email( $merged['email']['support_email'] );
		} else {
			$merged['email']['support_email'] = $merged['email']['notification_to'];
		}

		self::$resolved_cache[ $cache_key ] = $merged;

		return $merged;
	}

	/**
	 * Whether first-time setup is complete.
	 *
	 * Setup counts as complete once the wizard has been finished, or
	 * when a chat API key is already configured. The latter grandfathers
	 * sites that set up keys before the wizard existed, so an existing
	 * working install is neither forced through setup nor suddenly
	 * silenced by the frontend gate.
	 *
	 * @param int $agent_id Agent ID.
	 * @return bool
	 */
	public static function is_setup_complete( int $agent_id = 1 ): bool {
		$onboarding = get_option( 'ai_chatbot_onboarding_completed', null );
		if ( 'completed' === $onboarding ) {
			return true;
		}
		if ( 'not_started' === $onboarding || 'in_progress' === $onboarding ) {
			return false;
		}

		// The wizard has been finished at least once.
		if ( get_option( 'ai_chatbot_wizard_completed', false ) ) {
			return true;
		}

		$providers     = self::get_all( $agent_id, false )['providers'] ?? array();
		$chat_provider = $providers['chat_provider'] ?? 'groq';

		// Groq keys live in the shared key pool, not in the settings table.
		if ( 'groq' === $chat_provider ) {
			return KeyPool::has_configured_key( 'groq' );
		}

		$openai_key = Encryption::decrypt( (string) ( $providers['openai_api_key'] ?? '' ) );

		return '' !== trim( $openai_key );
	}

	/**
	 * Save multiple settings for an agent.
	 *
	 * @param int   $agent_id Agent ID.
	 * @param array $settings Grouped settings payload.
	 * @return bool
	 */
	public static function save_all( int $agent_id, array $settings ): bool {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE_NAME;

		// Normalize email settings before persisting.
		if ( isset( $settings['email'] ) && is_array( $settings['email'] ) ) {
			$admin_email = (string) get_option( 'admin_email' );
			$settings['email']['delivery_method'] = 'wp_mail';
			$recipient_type = $settings['email']['recipient_type'] ?? 'admin';

			if ( 'custom' === $recipient_type && ! empty( $settings['email']['custom_email'] ) && is_email( $settings['email']['custom_email'] ) ) {
				$settings['email']['recipient_type']  = 'custom';
				$settings['email']['custom_email']    = sanitize_email( $settings['email']['custom_email'] );
				$settings['email']['notification_to'] = $settings['email']['custom_email'];
			} else {
				$settings['email']['recipient_type']  = 'admin';
				$settings['email']['notification_to'] = $admin_email;
			}

			if ( ! empty( $settings['email']['support_email'] ) && is_email( $settings['email']['support_email'] ) ) {
				$settings['email']['support_email'] = sanitize_email( $settings['email']['support_email'] );
			} else {
				$settings['email']['support_email'] = $settings['email']['notification_to'];
			}
		}

		// Normalize response_behavior settings.
		if ( isset( $settings['response_behavior'] ) && is_array( $settings['response_behavior'] ) ) {
			$settings['response'] = $settings['response_behavior'];
		} elseif ( isset( $settings['response'] ) && is_array( $settings['response'] ) ) {
			$settings['response_behavior'] = $settings['response'];
		}

		foreach ( $settings as $group => $items ) {
			if ( ! is_array( $items ) ) {
				continue;
			}

			foreach ( $items as $key => $val ) {
				// Encrypt API keys if newly provided (not already masked).
				if ( in_array( $key, array( 'openai_api_key', 'mistral_api_key', 'resend_api_key' ), true ) && is_string( $val ) ) {
					$trimmed = trim( $val );

					// A masked or blank value means "leave the stored key as-is".
					// Blank used to fall through and overwrite the stored key with an
					// empty string, destroying it with no warning; the admin form
					// sends an empty field whenever the masked value was not echoed back.
					if ( '' === $trimmed || str_contains( $val, '•' ) || str_contains( $val, '****' ) || str_contains( $val, '***' ) ) {
						continue;
					}

					// If the value is already encrypted, do not re-encrypt it!
					if ( str_starts_with( $trimmed, 'sod:' ) || str_starts_with( $trimmed, 'gcm:' ) ) {
						$val = $trimmed;
					} else {
						// Sanitize newly entered keys: strip enclosing quotes and Bearer prefix.
						$clean = trim( $trimmed, "\"' \t\n\r\0\x0B" );
						if ( str_starts_with( strtolower( $clean ), 'bearer ' ) ) {
							$clean = trim( substr( $clean, 7 ) );
						}

						$val = Encryption::encrypt( $clean );
					}
				}

				/*
				 * Booleans are serialized explicitly. Casting false to string
				 * produced '', which get_all() reads back as "empty, use the
				 * default", so switching a boolean setting off silently left it
				 * on. Storing '0' keeps "off" distinguishable from "unset".
				 */
				if ( is_bool( $val ) ) {
					$stored_val = $val ? '1' : '0';
				} else {
					$stored_val = is_array( $val ) ? wp_json_encode( $val ) : (string) $val;
				}

				$wpdb->replace(
					$table,
					array(
						'agent_id'      => $agent_id,
						'setting_group' => sanitize_key( $group ),
						'setting_key'   => sanitize_key( $key ),
						'setting_value' => $stored_val,
					),
					array( '%d', '%s', '%s', '%s' )
				);
			}
		}

		// The resolved values changed, so any memoized copy for this
		// agent is now stale. Dropping the whole per-request cache is
		// cheaper than tracking which groups were touched and keeps a
		// save-then-read sequence in one request consistent.
		self::$resolved_cache = array();

		return true;
	}

	/**
	 * Default system settings.
	 *
	 * @return array
	 */
	public static function get_defaults(): array {
		return array(
			'placement'  => array(
				'position'        => 'bottom_right', // 'bottom_right', 'bottom_left', 'top_right', 'top_left'
				'launcher_shape'  => 'circle', // 'circle', 'pill', 'rounded'
				'launcher_size'   => 'standard', // 'compact', 'standard', 'large'
				'window_width'    => 380,
				'window_height'   => 620,
				'margin_bottom'   => 20,
				'margin_side'     => 20,
				'display_mode'    => 'floating', // 'floating', 'embedded', 'full_screen'
'site_wide'       => true,
   ),
   'badge'      => array(
     'enabled'       => true,
     // 'off' | 'dot' | 'count'
     'mode'          => 'dot',
     // Show the badge shortly after the page loads.
     'show_on_load'  => true,
     // Delay in ms before the load badge appears, so it does not compete with
     // the page hero for attention on first paint.
     'load_delay_ms' => 1200,
     // Opt-in nudge: re-show the badge after a period of inactivity. Useful to
     // bring a visitor back to the panel without a push channel.
     'nudge_after_ms'=> 0,
     // How many times per day the badge may appear before it stops. Opening the
     // chat resets the counter, so this only limits nagging within one visit
     // sequence. Kept above 1 because a value of 1 made the badge look broken
     // on the visitor's second page view of the day.
     'max_per_day'   => 3,
   ),
			'theme'      => array(
				'preset'          => 'modern', // 'default', 'modern', 'minimal', 'business', 'dark', 'custom'
				'primary_color'   => '#2563eb',
				'secondary_color' => '#1e40af',
				'bg_color'        => '#ffffff',
				'text_color'      => '#1e293b',
				'bot_bubble_bg'   => '#f1f5f9',
				'bot_bubble_text' => '#0f172a',
				'user_bubble_bg'  => '#2563eb',
				'user_bubble_text'=> '#ffffff',
				'border_radius'   => 16,
				'font_family'     => 'system-ui, -apple-system, sans-serif',
				'avatar_url'      => '',
				'launcher_icon'   => 'chat',
			),
			'visibility' => array(
				'mode'            => 'all_pages', // 'all_pages', 'specific_pages', 'hide_on_pages'
				'page_ids'        => array(),
				'user_condition'  => 'all', // 'all', 'logged_in', 'logged_out'
				'device'          => 'all', // 'all', 'desktop_only', 'mobile_only'
				'user_roles'      => array(),
				'url_rules'       => '',
			),
			'providers'  => array(
				'chat_provider'       => 'groq', // 'groq', 'openai'
				'chat_model'          => 'llama-3.3-70b-versatile',
				'embeddings_provider' => 'openai',
				'embeddings_model'    => 'text-embedding-3-small',
				'openai_api_key'      => '',
			'mistral_api_key'     => '',
				'temperature'         => 0.7,
				'max_tokens'          => 800,
				'top_p'               => 1.0,
				'rotation_strategy'   => 'priority', // 'priority', 'round_robin'
				'fallback_enabled'    => true,
			),
			'knowledge'  => array(
				'sitemap_url'            => '',
				'auto_sync'              => false,
				'sync_frequency_hours'   => 24,
				// Minimum cosine similarity for a chunk to count as relevant.
				// Below this the bot says the information is not on the site
				// rather than answering from the model's general knowledge.
				'min_relevance_score'    => 0.32,
			),
			'guardrails' => array(
				'strictness'          => 'strict', // 'strict', 'balanced', 'off'
				'allowed_topics'      => 'Our services, products, pricing, hours, contact information, and support.',
				'never_discuss'       => 'Competitors, other companies or external entities, medical advice, legal advice, politics, controversial topics, unrelated general knowledge.',
				'decline_message'     => 'I can only help with questions about {{company_name}}. For inquiries about other companies or further assistance, please contact our customer service: {{customer_service_contact}}.',
				'not_found_message'   => '',
				'classifier_enabled'  => true,
				'repeated_off_topic_warning' => 3,
				'repeated_off_topic_throttle'=> 6,
			),
			'session'    => array(
				'session_duration_minutes' => 30,
				'max_messages'             => 0, // 0 = Unlimited messages per session
				'sliding_window_size'      => 6, // Sliding context window: keeps system prompt + last 6 messages (3 turns)
				'store_conversations'      => true,
				'anonymous_sessions'       => true,
				'retention_days'           => 30, // 7, 30, 90, 0 (never)
				'delete_data_on_uninstall' => false,
			),
			'security'   => array(
				'chat_rate_limit' => 0, // 0 = Unlimited (no IP or session rate limiting cap)
			),
			'sounds'     => array(
				'enabled'         => false, // OFF by default
				'volume'          => 'medium', // 'low', 'medium', 'high'
				'message_sound'   => true,
				'open_sound'      => false,
			),
			'response_behavior' => array(
				'auto_links_verified'            => false, // Automatic Links and Citations: Disabled by default
				'auto_links_citations'           => false, // Legacy citation appender flag
				'allow_requested_links'          => true,  // Allow Links When Explicitly Requested: Enabled by default
				'link_style'                     => 'inline', // 'inline', 'block', 'both'
				'max_links_per_answer'           => 2,     // Maximum links per answer (1-5, default 2)
				'link_relevance_threshold'       => 0.60,  // Relevance score threshold (0.0 - 1.0, default 0.60)
				'sitemap_urls'                   => '',    // Sitemap URLs (custom sitemap URLs)
				'allowed_domains'                => '',    // Allowed extra domains (comma or newline separated)
				'excluded_patterns'              => "draft\ntest\nstaging\nai-chatbot\npreview\n/wp-admin\n/wp-json\n/feed\n/cart\n/checkout\n/my-account\n?replytocom",
				'cron_revalidation_enabled'      => true,  // WP-Cron link revalidation
				'rebuild_interval'               => 'daily', // 'hourly', 'daily', 'weekly'
				'deduplicate_conversation_links' => true,  // Do not link same page twice in same conversation
				'contact_url'                    => '',    // Contact page URL (validated)
			),
			'response'          => array(
				'auto_links_verified'            => false,
				'auto_links_citations'           => false,
				'allow_requested_links'          => true,
				'link_style'                     => 'inline',
				'max_links_per_answer'           => 2,
				'link_relevance_threshold'       => 0.60,
				'sitemap_urls'                   => '',
				'allowed_domains'                => '',
				'excluded_patterns'              => "draft\ntest\nstaging\nai-chatbot\npreview\n/wp-admin\n/wp-json\n/feed\n/cart\n/checkout\n/my-account\n?replytocom",
				'cron_revalidation_enabled'      => true,
				'rebuild_interval'               => 'daily',
				'deduplicate_conversation_links' => true,
				'contact_url'                    => '',
			),
		'language'   => array(
			'mode'              => 'auto', // 'auto', 'fixed', 'auto_fallback'
			'fixed_language'    => 'en',
			'default_language'  => 'en',
			// Used when auto_fallback mode cannot detect the language
			// confidently, and as the language of first resort.
			'fallback_language' => 'en',
			'enabled_languages' => array( 'en', 'de', 'ne', 'es', 'fr', 'it', 'pt', 'hi' ),
			'detect_on_input'   => true,
			// How the bot behaves when the visitor changes language:
			// 'auto_switch' follows the visitor's latest message,
			// 'sticky' keeps the conversation's language until a
			// confident switch, 'fixed' never switches away from the
			// language of the first message.
			'switching_behavior'=> 'auto_switch', // 'auto_switch', 'sticky', 'fixed'
			// Per-language response optimization. Each entry maps an ISO-639-1
				// language code to provider-side parameters that improve accuracy and
				// naturalness for that language. The keys mirror OpenAI/Groq request
				// parameters and are applied by ChatController when invoking a model.
				'language_optimizations' => array(
					'en' => array(
						'temperature'       => 0.7,
						'max_tokens_factor'   => 1.0,
						'top_p'              => 1.0,
						'prompt_optimization' => 'Use standard English tokenization. No transliteration needed.',
					),
					'de' => array(
						'temperature'       => 0.7,
						'max_tokens_factor'   => 1.15,
						'top_p'              => 0.95,
						'prompt_optimization' => 'German compound nouns can be split for better embedding retrieval. Allow umlaut expansion (ae->ä, oe->ö, ue->ü) in user queries for knowledge matching.',
					),
					'ne' => array(
						'temperature'       => 0.65,
						'max_tokens_factor'   => 1.35,
						'top_p'              => 0.9,
						'prompt_optimization' => 'Devanagari script: normalize NFD vs NFC encoding before retrieval. Increase max_tokens for Indic languages as they require more tokens per concept. Preserve honorific context (ji, sahab) in responses.',
					),
					'es' => array(
						'temperature'         => 0.75,
						'max_tokens_factor'     => 1.1,
						'top_p'                => 0.9,
						'prompt_optimization'  => 'Spanish: handle regionalisms (vos/tú vs usted, vosotros vs ustedes). Apply tildation correction so missing diacritics in user queries do not break embedding similarity.',
					),
					'fr' => array(
						'temperature'         => 0.7,
						'max_tokens_factor'     => 1.15,
						'top_p'                => 0.95,
						'prompt_optimization'  => 'French: handle accents and elisions. Maintain natural polite phrasing with formal address (vous).',
					),
					'it' => array(
						'temperature'         => 0.7,
						'max_tokens_factor'     => 1.15,
						'top_p'                => 0.95,
						'prompt_optimization'  => 'Italian: handle articulated prepositions and elisions. Maintain polite customer support tone.',
					),
					'pt' => array(
						'temperature'         => 0.7,
						'max_tokens_factor'     => 1.15,
						'top_p'                => 0.95,
						'prompt_optimization'  => 'Portuguese: support both Brazilian and European phrasing. Maintain polite natural customer interaction.',
					),
					'hi' => array(
						'temperature'         => 0.65,
						'max_tokens_factor'     => 1.35,
						'top_p'                => 0.9,
						'prompt_optimization'  => 'Devanagari script: increase max_tokens for Indic languages. Maintain polite and professional honorific tone (आप, जी).',
					),
				),
				'rtl_enabled'       => false,
			),
			'email'      => array(
				'enabled'          => true,
				'delivery_method'  => 'wp_mail',
				'recipient_type'   => 'admin',
				'custom_email'     => '',
				'support_email'    => (string) get_option( 'admin_email' ),
				'from_email'       => get_option( 'admin_email' ),
				'from_name'        => get_bloginfo( 'name' ),
				'notification_to'  => get_option( 'admin_email' ),
				'notify_on_lead'   => true,
				'notify_on_fail'   => true,
			),
		);
	}

	/**
	 * Get verified support/human-handoff email address.
	 *
	 * Reuses the existing email configuration in priority order:
	 * 1. Configured support_email in settings
	 * 2. Configured notification_to (custom_email or admin_email)
	 * 3. Business profile contact email (if set in business_info)
	 * 4. WordPress site administration email
	 *
	 * Returns empty string if no valid email can be resolved.
	 *
	 * @param int $agent_id Agent ID.
	 * @return string Validated email or empty string.
	 */
	public static function get_support_email( int $agent_id = 1 ): string {
		$settings     = self::get_all( $agent_id, false );
		$email_config = $settings['email'] ?? array();

		$candidates = array();

		// Priority 1: Email Notification recipient based on recipient_type ('custom' or 'admin')
		$recipient_type = $email_config['recipient_type'] ?? 'admin';
		if ( 'custom' === $recipient_type && ! empty( $email_config['custom_email'] ) ) {
			$candidates[] = $email_config['custom_email'];
		}
		if ( ! empty( $email_config['notification_to'] ) ) {
			$candidates[] = $email_config['notification_to'];
		}
		if ( ! empty( $email_config['support_email'] ) ) {
			$candidates[] = $email_config['support_email'];
		}

		// Check business info email if available.
		global $wpdb;
		$table_sources = $wpdb->prefix . 'ai_knowledge_sources';
		$biz_content   = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT content FROM {$table_sources} WHERE agent_id = %d AND type = 'business_info' LIMIT 1",
				$agent_id
			)
		);
		if ( $biz_content ) {
			$biz_data = json_decode( $biz_content, true );
			if ( ! empty( $biz_data['email'] ) ) {
				$candidates[] = $biz_data['email'];
			}
		}

		// Check Contact Human skill notification recipient if configured.
		$table_settings  = $wpdb->prefix . 'ai_settings';
		$skill_recipient = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT setting_value FROM {$table_settings} WHERE agent_id = %d AND setting_group = 'skills_contact' AND setting_key = 'notification_recipient' LIMIT 1",
				$agent_id
			)
		);
		if ( ! empty( $skill_recipient ) ) {
			$candidates[] = $skill_recipient;
		}

		$candidates[] = get_option( 'admin_email' );

		foreach ( $candidates as $candidate ) {
			$candidate = trim( (string) $candidate );
			if ( ! empty( $candidate ) && is_email( $candidate ) ) {
				return sanitize_email( $candidate );
			}
		}

		return '';
	}
}
