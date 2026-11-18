<?php
/**
 * Verified Link Registry.
 *
 * Implements the server-side link verification engine:
 * 1. Discovers canonical URLs from XML sitemaps, robots.txt, and knowledge base sources.
 * 2. Strictly validates URLs (HTTP 200, max 2 redirects, noindex checks, soft-404 checks, SSRF safe).
 * 3. Enforces language matching (German replies only link German pages).
 * 4. Syncs with post lifecycle hooks (save_post, transition_post_status, deleted_post).
 * 5. Provides O(1) fast canonical link lookups and 2s real-time freshness re-checks.
 *
 * @package AIChatbot\Links
 */

namespace AIChatbot\Links;

use AIChatbot\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Class LinkRegistry.
 */
class LinkRegistry {

	/**
	 * Table name without prefix.
	 */
	public const TABLE_NAME = 'ai_verified_links';

	/**
	 * Option key for validation logs (last 100 entries).
	 */
	public const LOG_OPTION = 'ai_chatbot_link_validation_log';

	/**
	 * Option key for last full rebuild timestamp.
	 */
	public const LAST_REBUILD_OPTION = 'ai_chatbot_last_link_rebuild';

	/**
	 * Option key for total active verified link count.
	 */
	public const LINK_COUNT_OPTION = 'ai_chatbot_link_count';

	/**
	 * Transient key for the cached link index.
	 */
	public const LINK_INDEX_TRANSIENT = 'ai_chatbot_link_index_cache';

	/**
	 * Option key fallback for the link index.
	 */
	public const LINK_INDEX_OPTION = 'ai_chatbot_link_index';

	/**
	 * Default exclusion patterns.
	 *
	 * @var string[]
	 */
	public const DEFAULT_EXCLUSIONS = array(
		'draft',
		'test',
		'staging',
		'ai-chatbot',
		'preview',
		'/wp-admin',
		'/wp-login',
		'wp-login.php',
		'/login',
		'/wp-json',
		'/xmlrpc.php',
		'/feed',
		'/cart',
		'/checkout',
		'/my-account',
		'?replytocom',
		'/attachment/',
		'/tag/',
		'/author/',
		'.xml',
		'sitemap',
		'btf_builder',
		'elementor',
		'/template/',
	);

	/**
	 * Initialize lifecycle hooks and scheduled events.
	 *
	 * @return void
	 */
	public static function init(): void {
		// Post lifecycle hooks for real-time link sync.
		add_action( 'save_post', array( __CLASS__, 'on_save_post' ), 20, 3 );
		add_action( 'transition_post_status', array( __CLASS__, 'on_transition_post_status' ), 20, 3 );
		add_action( 'deleted_post', array( __CLASS__, 'on_deleted_post' ), 20, 1 );

		// Hook when KB sources change.
		add_action( 'ai_chatbot_knowledge_sources_changed', array( __CLASS__, 'on_knowledge_sources_changed' ), 20, 1 );

		// Register custom cron intervals (e.g. weekly).
		add_filter( 'cron_schedules', array( __CLASS__, 'register_cron_schedules' ) );

		// Scheduled cron revalidation hook.
		add_action( 'ai_chatbot_link_revalidation', array( __CLASS__, 'run_cron_revalidation' ) );
		add_action( 'ai_chatbot_rebuild_link_index', array( __CLASS__, 'rebuild_registry' ) );

		if ( ! wp_next_scheduled( 'ai_chatbot_link_revalidation' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'ai_chatbot_link_revalidation' );
		}
		if ( ! wp_next_scheduled( 'ai_chatbot_rebuild_link_index' ) ) {
			wp_schedule_event( time() + ( 2 * HOUR_IN_SECONDS ), 'daily', 'ai_chatbot_rebuild_link_index' );
		}

		// Startup check that logs registry size and alerts if 0.
		add_action( 'init', array( __CLASS__, 'check_startup_registry' ), 1 );

		// Register WP-CLI commands when running under CLI.
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'ai-chatbot links rebuild', array( __CLASS__, 'cli_rebuild' ) );
			\WP_CLI::add_command( 'ai-chatbot links list', array( __CLASS__, 'cli_list' ) );
			\WP_CLI::add_command( 'ai-chatbot links export', array( __CLASS__, 'cli_export' ) );
		}
	}

	/**
	 * Check registry size on startup, log size, and alert loudly if 0.
	 *
	 * @return void
	 */
	public static function check_startup_registry(): void {
		$count = self::get_active_count();
		if ( 0 === $count ) {
			error_log( '[AI Chatbot LinkRegistry] CRITICAL STARTUP ALERT: Verified Link Registry is EMPTY (0 links)! Triggering immediate automatic rebuild.' );
			$res   = self::rebuild_registry();
			$count = $res['valid'] ?? self::get_active_count();
			if ( 0 === $count ) {
				error_log( '[AI Chatbot LinkRegistry] FATAL ERROR: Verified Link Registry rebuild failed. 0 verified URLs found. Chatbot linking is disabled.' );
			} else {
				error_log( sprintf( '[AI Chatbot LinkRegistry] Startup rebuild completed successfully. %d verified links registered.', $count ) );
			}
		} else {
			error_log( sprintf( '[AI Chatbot LinkRegistry] Startup check passed: %d active verified links in registry.', $count ) );
		}
	}

	/**
	 * Get total count of active verified links.
	 *
	 * @return int Total count.
	 */
	public static function get_active_count(): int {
		global $wpdb;
		$table = self::get_table_name();
		self::ensure_table_exists();
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE http_status = 200 AND fail_count = 0" );
	}

	/**
	 * Register custom WP-Cron schedules.
	 *
	 * @param array $schedules Cron schedules.
	 * @return array
	 */
	public static function register_cron_schedules( array $schedules ): array {
		if ( ! isset( $schedules['weekly'] ) ) {
			$schedules['weekly'] = array(
				'interval' => 7 * DAY_IN_SECONDS,
				'display'  => __( 'Once Weekly', 'ai-chatbot' ),
			);
		}
		return $schedules;
	}

	/**
	 * Reschedule link revalidation WP-Cron when interval setting changes.
	 *
	 * @param ?string $interval 'hourly', 'daily', or 'weekly' (reads setting if null).
	 * @return void
	 */
	public static function reschedule_cron( ?string $interval = null ): void {
		if ( empty( $interval ) ) {
			$settings        = Settings::get_all( 1, false );
			$response_config = $settings['response_behavior'] ?? ( $settings['response'] ?? array() );
			$interval        = $response_config['rebuild_interval'] ?? 'daily';
		}

		$valid_intervals = array( 'hourly', 'daily', 'weekly' );
		if ( ! in_array( $interval, $valid_intervals, true ) ) {
			$interval = 'daily';
		}

		$timestamp = wp_next_scheduled( 'ai_chatbot_link_revalidation' );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, 'ai_chatbot_link_revalidation' );
		}
		wp_schedule_event( time() + HOUR_IN_SECONDS, $interval, 'ai_chatbot_link_revalidation' );

		$rb_timestamp = wp_next_scheduled( 'ai_chatbot_rebuild_link_index' );
		if ( $rb_timestamp ) {
			wp_unschedule_event( $rb_timestamp, 'ai_chatbot_rebuild_link_index' );
		}
		wp_schedule_event( time() + ( 2 * HOUR_IN_SECONDS ), $interval, 'ai_chatbot_rebuild_link_index' );
	}

	/**
	 * Trigger link index rebuild when knowledge base sources change.
	 *
	 * @param int $agent_id Agent ID.
	 * @return void
	 */
	public static function on_knowledge_sources_changed( int $agent_id = 1 ): void {
		self::rebuild_registry( $agent_id );
	}

	/**
	 * Get full table name.
	 *
	 * @return string
	 */
	public static function get_table_name(): string {
		global $wpdb;
		return $wpdb->prefix . self::TABLE_NAME;
	}

	/**
	 * Ensure table exists via dbDelta and columns are up to date.
	 *
	 * @return void
	 */
	public static function ensure_table_exists(): void {
		static $table_checked = false;
		if ( $table_checked ) {
			return;
		}

		global $wpdb;
		$table_links = self::get_table_name();

		$table_exists = ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_links ) ) === $table_links );

		if ( ! $table_exists ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
			$charset_collate = $wpdb->get_charset_collate();

			$sql_links = "CREATE TABLE {$table_links} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				url varchar(500) NOT NULL,
				canonical_url varchar(500) NOT NULL,
				slug varchar(255) DEFAULT NULL,
				path varchar(500) DEFAULT NULL,
				title varchar(255) NOT NULL DEFAULT '',
				language varchar(10) NOT NULL DEFAULT 'de',
				http_status int(11) NOT NULL DEFAULT 200,
				last_verified datetime DEFAULT NULL,
				fail_count int(11) NOT NULL DEFAULT 0,
				content_hash varchar(64) DEFAULT NULL,
				source varchar(50) NOT NULL DEFAULT 'sitemap',
				summary text DEFAULT NULL,
				keywords text DEFAULT NULL,
				match_phrases text DEFAULT NULL,
				lastmod varchar(50) DEFAULT NULL,
				created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
				updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
				PRIMARY KEY  (id),
				KEY canonical_url (canonical_url(191)),
				KEY url (url(191)),
				KEY slug (slug(191)),
				KEY language (language),
				KEY source (source)
			) {$charset_collate};";

			dbDelta( $sql_links );
		} else {
			// Check if new columns exist; if missing, add them non-destructively.
			if ( method_exists( $wpdb, 'get_col' ) ) {
				$cols = (array) $wpdb->get_col( "DESCRIBE {$table_links}" );
				if ( ! in_array( 'slug', $cols, true ) ) {
					$wpdb->query( "ALTER TABLE {$table_links} ADD COLUMN slug varchar(255) DEFAULT NULL AFTER canonical_url" );
				}
				if ( ! in_array( 'path', $cols, true ) ) {
					$wpdb->query( "ALTER TABLE {$table_links} ADD COLUMN path varchar(500) DEFAULT NULL AFTER slug" );
				}
				if ( ! in_array( 'summary', $cols, true ) ) {
					$wpdb->query( "ALTER TABLE {$table_links} ADD COLUMN summary text DEFAULT NULL AFTER source" );
				}
				if ( ! in_array( 'keywords', $cols, true ) ) {
					$wpdb->query( "ALTER TABLE {$table_links} ADD COLUMN keywords text DEFAULT NULL AFTER summary" );
				}
				if ( ! in_array( 'match_phrases', $cols, true ) ) {
					$wpdb->query( "ALTER TABLE {$table_links} ADD COLUMN match_phrases text DEFAULT NULL AFTER keywords" );
				}
				if ( ! in_array( 'lastmod', $cols, true ) ) {
					$wpdb->query( "ALTER TABLE {$table_links} ADD COLUMN lastmod varchar(50) DEFAULT NULL AFTER match_phrases" );
				}
			}
		}

		$table_checked = true;
	}

	/**
	 * Validate SSRF safety of target URL.
	 * Blocks private, loopback, link-local, and reserved IP spaces.
	 *
	 * @param string $url URL to test.
	 * @return bool True if safe, false if forbidden.
	 */
	public static function is_ssrf_safe_url( string $url ): bool {
		$parsed = wp_parse_url( $url );
		if ( empty( $parsed['host'] ) ) {
			return false;
		}

		$scheme = strtolower( $parsed['scheme'] ?? '' );
		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
			return false;
		}

		$host = strtolower( $parsed['host'] );

		// Local test environment compatibility: allow localhost only if site is actually hosted on localhost.
		$site_host = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		if ( ( 'localhost' === $host || '127.0.0.1' === $host ) && ( 'localhost' === $site_host || '127.0.0.1' === $site_host ) ) {
			return true;
		}

		// Disallow literal localhost/127.0.0.1 if site is live.
		if ( 'localhost' === $host || '127.0.0.1' === $host ) {
			return false;
		}

		// Resolve host IPs to prevent DNS rebinding to internal addresses.
		$ips = gethostbynamel( $host );
		if ( empty( $ips ) || ! is_array( $ips ) ) {
			// Single fallback.
			$ip = gethostbyname( $host );
			if ( $ip === $host ) {
				return false; // Could not resolve.
			}
			$ips = array( $ip );
		}

		foreach ( $ips as $ip ) {
			// Reject invalid IPs.
			if ( ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
				return false;
			}

			// Reject private and reserved IPv4 ranges.
			if ( ! filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
				return false;
			}

			// Explicit check for AWS/Cloud metadata service (169.254.169.254) and loopback.
			if ( str_starts_with( $ip, '169.254.' ) || str_starts_with( $ip, '127.' ) || '0.0.0.0' === $ip ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Check whether the URL host matches the site or an admin-allowlisted host.
	 *
	 * @param string $url URL to check.
	 * @param array  $response_config Config array containing allowed_domains.
	 * @return bool
	 */
	public static function is_allowed_host( string $url, array $response_config = array() ): bool {
		$parsed = wp_parse_url( $url );
		if ( empty( $parsed['host'] ) ) {
			return false;
		}

		$url_host  = strtolower( $parsed['host'] );
		$site_host = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );

		// Match site host (allowing optional www prefix variation).
		$url_bare  = preg_replace( '/^www\./', '', $url_host );
		$site_bare = preg_replace( '/^www\./', '', $site_host );

		if ( $url_bare === $site_bare ) {
			return true;
		}

		// Match official contact URL host if available.
		$contact_url = ! empty( $response_config['contact_url'] )
			? $response_config['contact_url']
			: ( ! empty( $response_config['business_info']['contact_url'] )
				? $response_config['business_info']['contact_url']
				: ( class_exists( '\AIChatbot\Rest\ChatController' ) ? \AIChatbot\Rest\ChatController::resolve_site_contact_url() : '' ) );
		if ( ! empty( $contact_url ) ) {
			$c_host = strtolower( (string) wp_parse_url( $contact_url, PHP_URL_HOST ) );
			$c_bare = preg_replace( '/^www\./', '', $c_host );
			if ( ! empty( $c_bare ) && $url_bare === $c_bare ) {
				return true;
			}
		}

		// Check admin allowlisted domains.
		$extra = $response_config['allowed_domains'] ?? '';
		if ( ! empty( $extra ) ) {
			$allowed = is_array( $extra ) ? $extra : explode( "\n", str_replace( ',', "\n", (string) $extra ) );
			foreach ( $allowed as $domain ) {
				$clean_dom = strtolower( trim( preg_replace( '#^https?://#i', '', trim( $domain ) ), " /\t\r\n" ) );
				if ( '' === $clean_dom ) {
					continue;
				}
				$clean_bare = preg_replace( '/^www\./', '', $clean_dom );
				if ( $url_bare === $clean_bare || str_ends_with( $url_bare, '.' . $clean_bare ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Clean and normalize a URL.
	 * Removes tracking parameters, fragments, trailing punctuation, and cleans slashes.
	 *
	 * @param string $url Raw URL.
	 * @return string Normalized clean URL.
	 */
	public static function normalize_url( string $url ): string {
		$url = trim( $url );
		if ( '' === $url ) {
			return '';
		}

		// Strip trailing markdown, parentheses, punctuation, and quotes.
		$url = preg_replace( '/[.,;:!?\)\]"\'>]+$/', '', $url );
		$url = trim( $url );

		$parts = wp_parse_url( $url );
		if ( empty( $parts['host'] ) ) {
			return '';
		}

		$scheme = ! empty( $parts['scheme'] ) ? strtolower( $parts['scheme'] ) : 'https';
		// Prefer https if site is https.
		if ( is_ssl() || str_starts_with( home_url(), 'https://' ) ) {
			$scheme = 'https';
		}

		$host = strtolower( $parts['host'] );
		$path = $parts['path'] ?? '/';
		if ( '' === $path ) {
			$path = '/';
		}

		// Strip query parameters: tracking and noise.
		$query = '';
		if ( ! empty( $parts['query'] ) ) {
			parse_str( $parts['query'], $q_params );
			$tracking = array( 'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'fbclid', 'gclid', 'ref', '_ga', 'mc_eid', 'replytocom' );
			foreach ( $tracking as $tp ) {
				unset( $q_params[ $tp ] );
			}
			if ( ! empty( $q_params ) ) {
				$query = '?' . http_build_query( $q_params );
			}
		}

		// Normalize trailing slash: add trailing slash to non-file paths.
		if ( ! preg_match( '/\.[a-zA-Z0-9]{2,5}$/', $path ) && ! str_ends_with( $path, '/' ) ) {
			$path .= '/';
		}

		return $scheme . '://' . $host . $path . $query;
	}

	/**
	 * Normalize a URL strictly for comparison and indexing lookups.
	 *
	 * STEP 1 CORE PRINCIPLE:
	 * Normalize ONLY for comparison (lowercase host, strip trailing slash,
	 * remove tracking params and fragments). Always output the original
	 * canonical_url exactly as it appears in the sitemap/knowledge.
	 *
	 * @param string $url URL to normalize for comparison.
	 * @return string Normalized key.
	 */
	public static function normalize_for_comparison( string $url ): string {
		$url = trim( $url );
		if ( '' === $url ) {
			return '';
		}

		// Strip URL fragments.
		$fragment_pos = strpos( $url, '#' );
		if ( false !== $fragment_pos ) {
			$url = substr( $url, 0, $fragment_pos );
		}

		// Strip trailing punctuation from markdown or prose.
		$url = preg_replace( '/[.,;:!?\)\]"\'>]+$/', '', $url );
		$url = trim( $url );

		$parts = wp_parse_url( $url );
		if ( empty( $parts['host'] ) ) {
			if ( str_starts_with( $url, '/' ) ) {
				$p = rtrim( $url, '/' );
				return '' === $p ? '/' : strtolower( $p );
			}
			return '';
		}

		$scheme = strtolower( $parts['scheme'] ?? 'https' );
		$host   = strtolower( $parts['host'] );
		$host   = preg_replace( '/^www\./', '', $host );

		$path = $parts['path'] ?? '/';
		if ( '' === $path ) {
			$path = '/';
		}

		$query = '';
		if ( ! empty( $parts['query'] ) ) {
			parse_str( $parts['query'], $q_params );
			$tracking = array( 'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'fbclid', 'gclid', 'ref', '_ga', 'mc_eid', 'replytocom' );
			foreach ( $tracking as $tp ) {
				unset( $q_params[ $tp ] );
			}
			if ( ! empty( $q_params ) ) {
				ksort( $q_params );
				$query = '?' . http_build_query( $q_params );
			}
		}

		// Strip trailing slash for comparison.
		$path = rtrim( $path, '/' );

		return $scheme . '://' . $host . ( '' !== $path ? $path : '' ) . $query;
	}

	/**
	 * Extract clean page slug from URL, safely removing site subdirectory path.
	 *
	 * @param string $url URL to extract slug from.
	 * @return string Clean slug (e.g. 'ueber-uns', 'kontakt', or '' for home).
	 */
	public static function extract_slug_from_url( string $url ): string {
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		$home_path = (string) wp_parse_url( home_url(), PHP_URL_PATH );
		$trimmed_home = trim( $home_path, '/' );
		$trimmed_path = trim( $path, '/' );
		if ( ! empty( $trimmed_home ) && str_starts_with( $trimmed_path, $trimmed_home ) ) {
			$trimmed_path = trim( substr( $trimmed_path, strlen( $trimmed_home ) ), '/' );
		}
		return $trimmed_path;
	}

	/**
	 * Check whether a URL or title matches any exclusion patterns.
	 *
	 * @param string $url URL to check.
	 * @param string $title Page title.
	 * @param array  $response_config Response behavior config.
	 * @return bool True if excluded.
	 */
	public static function is_excluded( string $url, string $title = '', array $response_config = array() ): bool {
		$url_lower   = strtolower( $url );
		$title_lower = strtolower( $title );
		$path_lower  = strtolower( (string) wp_parse_url( $url, PHP_URL_PATH ) );

		// Check default exclusions.
		foreach ( self::DEFAULT_EXCLUSIONS as $pattern ) {
			$p = strtolower( trim( $pattern ) );
			if ( '' === $p ) {
				continue;
			}
			if ( str_contains( $url_lower, $p ) || str_contains( $title_lower, $p ) ) {
				return true;
			}
		}

		// Check query string tracking.
		if ( preg_match( '/[?&](utm_|fbclid|gclid)/i', $url_lower ) ) {
			return true;
		}

		// Exclude image and binary assets.
		if ( preg_match( '/\.(pdf|jpe?g|png|gif|svg|webp|ico|zip|mp4|mp3|woff2?|ttf|css|js)$/i', $path_lower ) ) {
			return true;
		}

		// Custom exclusions from settings.
		$custom = $response_config['excluded_patterns'] ?? '';
		if ( ! empty( $custom ) ) {
			$patterns = is_array( $custom ) ? $custom : explode( "\n", str_replace( ',', "\n", (string) $custom ) );
			foreach ( $patterns as $pat ) {
				$pat = strtolower( trim( $pat ) );
				if ( '' === $pat ) {
					continue;
				}
				if ( str_contains( $url_lower, $pat ) || str_contains( $title_lower, $pat ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Detect language for a URL.
	 *
	 * @param string $url URL.
	 * @param string $html Optional page HTML body.
	 * @param string $hint Optional hint from sitemap hreflang.
	 * @return string ISO language code (e.g. 'de', 'en', 'es', 'ne').
	 */
	public static function detect_language( string $url, string $html = '', string $hint = '' ): string {
		$hint = strtolower( trim( $hint ) );
		if ( in_array( $hint, Settings::ALLOWED_LANGUAGES, true ) ) {
			return $hint;
		}

		// Check URL path prefix: /en/, /de/, /ne/, /es/, /fr/, etc.
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		if ( preg_match( '#^/([a-z]{2})(?:/|$)#i', $path, $m ) ) {
			$code = strtolower( $m[1] );
			if ( in_array( $code, Settings::ALLOWED_LANGUAGES, true ) ) {
				return $code;
			}
		}

		// Check HTML lang attribute.
		if ( ! empty( $html ) && preg_match( '/<html[^>]+lang=["\']([a-zA-Z]{2})/i', $html, $m ) ) {
			$code = strtolower( $m[1] );
			if ( in_array( $code, Settings::ALLOWED_LANGUAGES, true ) ) {
				return $code;
			}
		}

		// Check for German indicators in title, path, or body.
		if ( preg_match( '/\b(kontakt|leistungen|über|ueber|impressum|datenschutz|referenzen|preise|tarife|anfrage|angebote|karriere|dienstleistungen|unternehmen)\b/ui', $url . ' ' . $html ) ) {
			return 'de';
		}

		// Check for explicit English path indicators (e.g. /manual-for-...).
		if ( preg_match( '#/(?:en|manual-for|terms-and-conditions|career)/#i', $path ) ) {
			return 'en';
		}

		// Default to site locale or 'en' as universal fallback.
		$site_locale = get_locale();
		$lang = substr( $site_locale, 0, 2 );
		if ( in_array( $lang, Settings::ALLOWED_LANGUAGES, true ) ) {
			return $lang;
		}

		return 'en';
	}

	/**
	 * Validate a URL against all requirements.
	 * Follows up to 2 redirects, verifies HTTP 200, checks noindex and soft-404.
	 *
	 * @param string $raw_url Raw URL.
	 * @param array  $context Context array (source, title, lang_hint, response_config).
	 * @return array [ 'valid' => bool, 'canonical_url' => string, 'title' => string, 'language' => string, 'http_status' => int, 'reason' => string ]
	 */
	public static function validate_url( string $raw_url, array $context = array() ): array {
		$clean_url = self::normalize_url( $raw_url );
		if ( empty( $clean_url ) ) {
			return array(
				'valid'         => false,
				'url'           => $raw_url,
				'canonical_url' => '',
				'title'         => '',
				'language'      => 'de',
				'http_status'   => 0,
				'reason'        => 'Invalid or empty URL format.',
			);
		}

		$response_config = $context['response_config'] ?? Settings::get_all( 1, false )['response_behavior'] ?? array();

		// 1. Allowed host check.
		if ( ! self::is_allowed_host( $clean_url, $response_config ) ) {
			return array(
				'valid'         => false,
				'url'           => $clean_url,
				'canonical_url' => $clean_url,
				'title'         => $context['title'] ?? '',
				'language'      => 'de',
				'http_status'   => 0,
				'reason'        => 'Host not allowed (external domain not in allowlist).',
			);
		}

		// 2. SSRF check.
		if ( ! self::is_ssrf_safe_url( $clean_url ) ) {
			return array(
				'valid'         => false,
				'url'           => $clean_url,
				'canonical_url' => $clean_url,
				'title'         => $context['title'] ?? '',
				'language'      => 'de',
				'http_status'   => 0,
				'reason'        => 'SSRF safety check failed (private/loopback IP address blocked).',
			);
		}

		// 3. Exclusion pattern check.
		if ( self::is_excluded( $clean_url, $context['title'] ?? '', $response_config ) ) {
			return array(
				'valid'         => false,
				'url'           => $clean_url,
				'canonical_url' => $clean_url,
				'title'         => $context['title'] ?? '',
				'language'      => 'de',
				'http_status'   => 0,
				'reason'        => 'URL matched exclusion patterns (draft, test, admin, or tracking pattern).',
			);
		}

		// Fast-path: If pre-verified by WordPress post query or already confirmed published.
		if ( ! empty( $context['pre_verified'] ) ) {
			return array(
				'valid'         => true,
				'url'           => $clean_url,
				'canonical_url' => $clean_url,
				'title'         => ! empty( $context['title'] ) ? $context['title'] : $clean_url,
				'language'      => ! empty( $context['lang_hint'] ) ? $context['lang_hint'] : self::detect_language( $clean_url ),
				'http_status'   => 200,
				'reason'        => 'Verified OK (Published WordPress Content).',
			);
		}

		// Fast-path: Check local WordPress post/page without making slow network loopback calls.
		$parsed_host = strtolower( (string) wp_parse_url( $clean_url, PHP_URL_HOST ) );
		$site_host   = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		$url_bare    = preg_replace( '/^www\./', '', $parsed_host );
		$site_bare   = preg_replace( '/^www\./', '', $site_host );

		if ( $url_bare === $site_bare ) {
			// Site root URL check.
			$home_norm = self::normalize_url( home_url() );
			if ( strtolower( rtrim( $clean_url, '/' ) ) === strtolower( rtrim( $home_norm, '/' ) ) ) {
				return array(
					'valid'         => true,
					'url'           => $clean_url,
					'canonical_url' => home_url( '/' ),
					'title'         => get_bloginfo( 'name' ),
					'language'      => ! empty( $context['lang_hint'] ) ? $context['lang_hint'] : self::detect_language( $clean_url ),
					'http_status'   => 200,
					'reason'        => 'Verified OK (Website Home URL).',
				);
			}

			$post_id = url_to_postid( $clean_url );
			if ( $post_id > 0 ) {
				$p = get_post( $post_id );
				if ( $p && 'publish' === $p->post_status && empty( $p->post_password ) ) {
					$yoast = get_post_meta( $p->ID, '_yoast_wpseo_meta-robots-noindex', true );
					$rank  = (string) get_post_meta( $p->ID, 'rank_math_robots', true );
					if ( '1' !== (string) $yoast && ! str_contains( strtolower( $rank ), 'noindex' ) ) {
						return array(
							'valid'         => true,
							'url'           => $clean_url,
							'canonical_url' => $clean_url,
							'title'         => ! empty( $context['title'] ) ? $context['title'] : get_the_title( $post_id ),
							'language'      => ! empty( $context['lang_hint'] ) ? $context['lang_hint'] : self::detect_language( $clean_url ),
							'http_status'   => 200,
							'reason'        => 'Verified OK (Local WordPress Post).',
						);
					}
				}
			}
		}

		// 4. Remote HTTP Request: follow up to 2 redirects.
		$user_agent = 'Mozilla/5.0 (compatible; AIChatbotLinkVerifier/1.3.0; +' . home_url() . ')';
		$args       = array(
			'timeout'     => 3,
			'redirection' => 2,
			'sslverify'   => ! ( 'localhost' === $parsed_host || '127.0.0.1' === $parsed_host ),
			'user-agent'  => $user_agent,
			'headers'     => array(
				'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
			),
		);

		// Try HEAD first; fallback to GET on 405/403 or failure.
		$response    = wp_remote_head( $clean_url, $args );
		$status_code = (int) wp_remote_retrieve_response_code( $response );
		$body        = '';

		if ( is_wp_error( $response ) || in_array( $status_code, array( 403, 405, 0 ), true ) ) {
			$response    = wp_remote_get( $clean_url, $args );
			$status_code = (int) wp_remote_retrieve_response_code( $response );
		}

		if ( is_wp_error( $response ) ) {
			return array(
				'valid'         => false,
				'url'           => $clean_url,
				'canonical_url' => $clean_url,
				'title'         => $context['title'] ?? '',
				'language'      => 'de',
				'http_status'   => 0,
				'reason'        => 'HTTP connection error: ' . $response->get_error_message(),
			);
		}

		if ( 200 !== $status_code ) {
			return array(
				'valid'         => false,
				'url'           => $clean_url,
				'canonical_url' => $clean_url,
				'title'         => $context['title'] ?? '',
				'language'      => 'de',
				'http_status'   => $status_code,
				'reason'        => sprintf( 'HTTP status %d is not 200.', $status_code ),
			);
		}

		// Check X-Robots-Tag header.
		$x_robots = (string) wp_remote_retrieve_header( $response, 'x-robots-tag' );
		if ( str_contains( strtolower( $x_robots ), 'noindex' ) ) {
			return array(
				'valid'         => false,
				'url'           => $clean_url,
				'canonical_url' => $clean_url,
				'title'         => $context['title'] ?? '',
				'language'      => 'de',
				'http_status'   => 200,
				'reason'        => 'X-Robots-Tag specifies noindex.',
			);
		}

		// Read body if available to extract title and check meta robots / soft 404.
		$body = (string) wp_remote_retrieve_body( $response );
		if ( empty( $body ) ) {
			// Fast fetch GET for HTML inspection if HEAD had no body.
			$get_resp = wp_remote_get( $clean_url, array_merge( $args, array( 'timeout' => 5 ) ) );
			if ( ! is_wp_error( $get_resp ) && 200 === (int) wp_remote_retrieve_response_code( $get_resp ) ) {
				$body = (string) wp_remote_retrieve_body( $get_resp );
			}
		}

		$title = $context['title'] ?? '';

		if ( ! empty( $body ) ) {
			// Check meta robots.
			if ( preg_match( '/<meta[^>]+name=["\'](?:robots|googlebot)["\'][^>]+content=["\'][^"\']*noindex/i', $body ) ) {
				return array(
					'valid'         => false,
					'url'           => $clean_url,
					'canonical_url' => $clean_url,
					'title'         => $title,
					'language'      => 'de',
					'http_status'   => 200,
					'reason'        => 'Meta robots specifies noindex.',
				);
			}

			// Check password protection.
			if ( str_contains( $body, 'post-password-form' ) ) {
				return array(
					'valid'         => false,
					'url'           => $clean_url,
					'canonical_url' => $clean_url,
					'title'         => $title,
					'language'      => 'de',
					'http_status'   => 200,
					'reason'        => 'Page is password-protected.',
				);
			}

			// Extract title if not provided.
			if ( empty( $title ) && preg_match( '/<title[^>]*>(.*?)<\/title>/si', $body, $m ) ) {
				$title = trim( html_entity_decode( wp_strip_all_tags( $m[1] ), ENT_QUOTES, 'UTF-8' ) );
			}

			// Soft 404 check.
			if ( preg_match( '/\b(404\s*[-–:]?\s*(?:not found|seite nicht gefunden|page not found)|seite wurde nicht gefunden)\b/i', $title ) ) {
				return array(
					'valid'         => false,
					'url'           => $clean_url,
					'canonical_url' => $clean_url,
					'title'         => $title,
					'language'      => 'de',
					'http_status'   => 200,
					'reason'        => 'Soft-404 detected in page title.',
				);
			}
		}

		// Re-check title against exclusions.
		if ( ! empty( $title ) && self::is_excluded( $clean_url, $title, $response_config ) ) {
			return array(
				'valid'         => false,
				'url'           => $clean_url,
				'canonical_url' => $clean_url,
				'title'         => $title,
				'language'      => 'de',
				'http_status'   => 200,
				'reason'        => sprintf( 'Page title "%s" matched exclusion patterns.', $title ),
			);
		}

		// Detect language.
		$lang = self::detect_language( $clean_url, $body, $context['lang_hint'] ?? '' );

		return array(
			'valid'         => true,
			'url'           => $clean_url,
			'canonical_url' => $clean_url,
			'title'         => $title ?: $clean_url,
			'language'      => $lang,
			'http_status'   => 200,
			'reason'        => 'Verified OK (HTTP 200, indexed, active).',
		);
	}

	/**
	 * Insert or update a verified link in the registry.
	 *
	 * @param array $data Link data.
	 * @return bool
	 */
	public static function add_or_update( array $data ): bool {
		global $wpdb;
		self::ensure_table_exists();

		$table = self::get_table_name();
		$url   = self::normalize_url( $data['url'] ?? '' );
		if ( empty( $url ) ) {
			return false;
		}

		// Retain exact original string as canonical_url.
		$canonical = ! empty( $data['canonical_url'] ) ? trim( (string) $data['canonical_url'] ) : $url;
		$title     = sanitize_text_field( $data['title'] ?? '' );
		$language  = sanitize_text_field( $data['language'] ?? 'de' );
		$status    = (int) ( $data['http_status'] ?? 200 );
		$source    = sanitize_key( $data['source'] ?? 'sitemap' );
		$hash      = ! empty( $data['content_hash'] ) ? sanitize_text_field( $data['content_hash'] ) : md5( $canonical );
		$summary   = isset( $data['summary'] ) ? sanitize_textarea_field( $data['summary'] ) : null;
		$keywords  = isset( $data['keywords'] ) ? sanitize_text_field( $data['keywords'] ) : null;
		$lastmod   = isset( $data['lastmod'] ) ? sanitize_text_field( $data['lastmod'] ) : null;

		$path = ! empty( $data['path'] ) ? sanitize_text_field( $data['path'] ) : (string) wp_parse_url( $canonical, PHP_URL_PATH );
		if ( empty( $path ) ) {
			$path = '/';
		}

		$clean_slug = ! empty( $data['slug'] ) ? sanitize_text_field( $data['slug'] ) : '';
		$home_path  = trim( (string) wp_parse_url( home_url(), PHP_URL_PATH ), '/' );
		if ( ! empty( $home_path ) && str_starts_with( $clean_slug, $home_path ) ) {
			$clean_slug = trim( substr( $clean_slug, strlen( $home_path ) ), '/' );
		}
		if ( empty( $clean_slug ) ) {
			$clean_slug = self::extract_slug_from_url( $canonical );
		}
		$slug = $clean_slug;

		$match_phrases = null;
		if ( isset( $data['match_phrases'] ) ) {
			if ( is_array( $data['match_phrases'] ) ) {
				$match_phrases = wp_json_encode( array_values( array_unique( array_filter( $data['match_phrases'] ) ) ) );
			} elseif ( is_string( $data['match_phrases'] ) ) {
				$match_phrases = $data['match_phrases'];
			}
		}

		$existing = $wpdb->get_row(
			$wpdb->prepare( "SELECT id FROM {$table} WHERE canonical_url = %s LIMIT 1", $canonical ),
			ARRAY_A
		);

		$fields = array(
			'url'           => $url,
			'canonical_url' => $canonical,
			'slug'          => $slug,
			'path'          => $path,
			'title'         => $title,
			'language'      => $language,
			'http_status'   => $status,
			'last_verified' => current_time( 'mysql' ),
			'fail_count'    => 0,
			'content_hash'  => $hash,
			'source'        => $source,
			'summary'       => $summary,
			'keywords'      => $keywords,
			'match_phrases' => $match_phrases,
			'lastmod'       => $lastmod,
			'updated_at'    => current_time( 'mysql' ),
		);
		$formats = array( '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s' );

		if ( $existing ) {
			$wpdb->update(
				$table,
				$fields,
				array( 'id' => (int) $existing['id'] ),
				$formats,
				array( '%d' )
			);
		} else {
			$fields['created_at'] = current_time( 'mysql' );
			$formats[]            = '%s';
			$wpdb->insert(
				$table,
				$fields,
				$formats
			);
		}

		delete_transient( self::LINK_INDEX_TRANSIENT );
		self::log_event( $canonical, 'valid', 'Link verified and active in registry.', 200 );

		return true;
	}

	/**
	 * Record a verification failure.
	 * Increments fail_count; removes from registry after 2 consecutive failures.
	 *
	 * @param string $url URL that failed.
	 * @param string $reason Reason for failure.
	 * @param int    $http_code HTTP code.
	 * @return void
	 */
	public static function record_failure( string $url, string $reason, int $http_code = 0 ): void {
		global $wpdb;
		self::ensure_table_exists();

		$table     = self::get_table_name();
		$canonical = self::normalize_url( $url );

		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT id, fail_count FROM {$table} WHERE canonical_url = %s OR url = %s LIMIT 1", $canonical, $canonical ),
			ARRAY_A
		);

		if ( ! $row ) {
			self::log_event( $canonical, 'failed', $reason, $http_code );
			return;
		}

		$fails = (int) $row['fail_count'] + 1;

		if ( $fails >= 2 ) {
			// Remove from active registry after 2 consecutive failures.
			$wpdb->delete( $table, array( 'id' => (int) $row['id'] ), array( '%d' ) );
			delete_transient( self::LINK_INDEX_TRANSIENT );
			self::log_event( $canonical, 'removed', sprintf( 'Removed after 2 consecutive failures: %s', $reason ), $http_code );
		} else {
			$wpdb->update(
				$table,
				array(
					'fail_count'    => $fails,
					'http_status'   => $http_code,
					'last_verified' => current_time( 'mysql' ),
					'updated_at'    => current_time( 'mysql' ),
				),
				array( 'id' => (int) $row['id'] ),
				array( '%d', '%d', '%s', '%s' ),
				array( '%d' )
			);
			self::log_event( $canonical, 'failed', sprintf( 'Verification failed (attempt 1/2): %s', $reason ), $http_code );
		}
	}

	/**
	 * Remove a URL from the registry immediately (e.g. on post delete/unpublish).
	 *
	 * @param string $url URL to remove.
	 * @param string $reason Reason.
	 * @return bool
	 */
	public static function remove_link( string $url, string $reason = 'Unpublished or deleted' ): bool {
		global $wpdb;
		self::ensure_table_exists();

		$table     = self::get_table_name();
		$canonical = self::normalize_url( $url );

		$deleted = $wpdb->query(
			$wpdb->prepare( "DELETE FROM {$table} WHERE canonical_url = %s OR url = %s", $canonical, $canonical )
		);

		if ( $deleted ) {
			delete_transient( self::LINK_INDEX_TRANSIENT );
			self::log_event( $canonical, 'removed', $reason, 200 );
			return true;
		}

		return false;
	}

	/**
	 * Append an event to the circular validation log (last 100 entries).
	 *
	 * @param string $url URL.
	 * @param string $status Status ('valid' | 'failed' | 'removed').
	 * @param string $reason Description.
	 * @param int    $code HTTP code.
	 * @return void
	 */
	public static function log_event( string $url, string $status, string $reason, int $code = 0 ): void {
		$log = get_option( self::LOG_OPTION, array() );
		if ( ! is_array( $log ) ) {
			$log = array();
		}

		array_unshift(
			$log,
			array(
				'timestamp' => current_time( 'mysql' ),
				'url'       => $url,
				'status'    => $status,
				'reason'    => $reason,
				'http_code' => $code,
			)
		);

		// Keep last 100 entries.
		if ( count( $log ) > 100 ) {
			$log = array_slice( $log, 0, 100 );
		}

		update_option( self::LOG_OPTION, $log, false );
	}

	/**
	 * Discover all sitemaps for the site.
	 *
	 * @param array $response_config Settings.
	 * @return string[] Unique sitemap URLs.
	 */
	public static function discover_sitemaps( array $response_config = array() ): array {
		$site_url  = home_url();
		$site_host = strtolower( (string) wp_parse_url( $site_url, PHP_URL_HOST ) );
		$is_local  = ( 'localhost' === $site_host || '127.0.0.1' === $site_host );
		$base      = rtrim( $site_url, '/' );

		$candidates = array();

		// Always check standard WordPress core and SEO plugin sitemap endpoints.
		$candidates[] = $base . '/wp-sitemap.xml';
		$candidates[] = $base . '/sitemap_index.xml';
		$candidates[] = $base . '/sitemap.xml';

		// Check local disk if on local installation.
		if ( $is_local ) {
			if ( file_exists( ABSPATH . 'sitemap.xml' ) ) {
				$candidates[] = $base . '/sitemap.xml';
			}
			if ( file_exists( ABSPATH . 'sitemap_index.xml' ) ) {
				$candidates[] = $base . '/sitemap_index.xml';
			}
		}

		// Check robots.txt for Sitemap: directives.
		$robots_url = $base . '/robots.txt';
		if ( $is_local && file_exists( ABSPATH . 'robots.txt' ) ) {
			$body = (string) file_get_contents( ABSPATH . 'robots.txt' );
			if ( preg_match_all( '/^sitemap:\s*(https?:\/\/[^\s]+)/im', $body, $m ) ) {
				foreach ( $m[1] as $discovered ) {
					$candidates[] = trim( $discovered );
				}
			}
		} elseif ( ! $is_local ) {
			$robots_res = wp_remote_get( $robots_url, array( 'timeout' => 5 ) );
			if ( ! is_wp_error( $robots_res ) && 200 === (int) wp_remote_retrieve_response_code( $robots_res ) ) {
				$body = (string) wp_remote_retrieve_body( $robots_res );
				if ( preg_match_all( '/^sitemap:\s*(https?:\/\/[^\s]+)/im', $body, $m ) ) {
					foreach ( $m[1] as $discovered ) {
						$candidates[] = trim( $discovered );
					}
				}
			}
		}

		// Add custom sitemaps from settings.
		$custom = $response_config['sitemap_urls'] ?? '';
		if ( ! empty( $custom ) ) {
			$lines = is_array( $custom ) ? $custom : explode( "\n", str_replace( ',', "\n", (string) $custom ) );
			foreach ( $lines as $line ) {
				$line = trim( $line );
				if ( ! empty( $line ) && filter_var( $line, FILTER_VALIDATE_URL ) ) {
					$candidates[] = $line;
				}
			}
		}

		return array_values( array_unique( array_filter( $candidates ) ) );
	}

	/**
	 * Parse sitemap recursively, extracting URLs, lastmod, slug, title, and hreflang translations.
	 *
	 * @param string $sitemap_url Sitemap URL.
	 * @param int    $depth Recursion depth.
	 * @param array  $visited Visited sitemaps to prevent cycles.
	 * @return array Array of [ 'url' => string, 'lang' => string, 'lastmod' => string, 'slug' => string, 'title' => string ].
	 */
	public static function parse_sitemap_recursive( string $sitemap_url, int $depth = 0, array &$visited = array() ): array {
		if ( $depth > 5 || isset( $visited[ $sitemap_url ] ) ) {
			return array();
		}
		$visited[ $sitemap_url ] = true;

		$url_host = strtolower( (string) wp_parse_url( $sitemap_url, PHP_URL_HOST ) );
		$is_local = ( 'localhost' === $url_host || '127.0.0.1' === $url_host );
		$xml      = '';

		// Check local disk if on localhost.
		if ( $is_local ) {
			$path_part = ltrim( (string) wp_parse_url( $sitemap_url, PHP_URL_PATH ), '/' );
			// Remove site directory prefix if in subdirectory (e.g. mysite/).
			$site_sub = trim( (string) wp_parse_url( home_url(), PHP_URL_PATH ), '/' );
			if ( ! empty( $site_sub ) && str_starts_with( $path_part, $site_sub . '/' ) ) {
				$path_part = substr( $path_part, strlen( $site_sub ) + 1 );
			}
			$disk_file = ABSPATH . $path_part;
			if ( file_exists( $disk_file ) ) {
				$xml = (string) file_get_contents( $disk_file );
			}
		}

		if ( empty( $xml ) ) {
			$response = wp_remote_get(
				$sitemap_url,
				array(
					'timeout'     => 15,
					'redirection' => 3,
					'sslverify'   => ! $is_local,
					'user-agent'  => 'Mozilla/5.0 (compatible; AIChatbotSitemapCrawler/1.3.0; +' . home_url() . ')',
				)
			);

			if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
				return array();
			}

			$xml = (string) wp_remote_retrieve_body( $response );
		}
		if ( empty( $xml ) ) {
			return array();
		}

		$items = array();

		// Normalize CDATA.
		$xml = str_replace( array( '<![CDATA[', ']]>' ), '', $xml );

		// Check for sitemap index (<sitemap><loc>...</loc></sitemap>).
		if ( preg_match_all( '#<sitemap[^>]*>.*?<loc>(.*?)</loc>.*?</sitemap>#is', $xml, $matches ) ) {
			foreach ( $matches[1] as $sub_url ) {
				$sub_url = trim( html_entity_decode( $sub_url, ENT_QUOTES | ENT_XML1, 'UTF-8' ) );
				if ( ! empty( $sub_url ) ) {
					$sub_items = self::parse_sitemap_recursive( $sub_url, $depth + 1, $visited );
					$items     = array_merge( $items, $sub_items );
				}
			}
			return $items;
		}

		// Parse standard <url> entries.
		if ( preg_match_all( '#<url[^>]*>(.*?)</url>#is', $xml, $url_blocks ) ) {
			foreach ( $url_blocks[1] as $block ) {
				if ( preg_match( '#<loc>(.*?)</loc>#is', $block, $loc_m ) ) {
					$loc = trim( html_entity_decode( $loc_m[1], ENT_QUOTES | ENT_XML1, 'UTF-8' ) );
					if ( empty( $loc ) ) {
						continue;
					}

					// Exclude sitemap files themselves from being content targets.
					if ( preg_match( '/\.(xml|gz)$/i', $loc ) || str_contains( strtolower( $loc ), 'sitemap' ) ) {
						continue;
					}

					$lastmod = '';
					if ( preg_match( '#<lastmod>(.*?)</lastmod>#is', $block, $lm ) ) {
						$lastmod = trim( sanitize_text_field( $lm[1] ) );
					}

					$lang_hint = '';
					// Check hreflang alternate link inside this block.
					if ( preg_match( '#hreflang=["\']([a-zA-Z]{2})["\']#i', $block, $hl ) ) {
						$lang_hint = strtolower( $hl[1] );
					}

					$slug          = self::extract_slug_from_url( $loc );
					$slug_basename = basename( $slug );
					$derived_title = ucwords( str_replace( array( '-', '_' ), ' ', $slug_basename ) );

					$items[] = array(
						'url'     => $loc,
						'lang'    => $lang_hint,
						'lastmod' => $lastmod,
						'slug'    => $slug,
						'title'   => $derived_title,
					);
				}
			}
		}

		return $items;
	}

	/**
	 * Rebuild the Verified Link Registry from sitemaps, published pages, and knowledge base.
	 *
	 * @param int $agent_id Agent ID.
	 * @return array Report array [ 'total_discovered', 'valid', 'valid_count', 'invalid', 'failed_count', 'removed', 'url_count', 'last_rebuild' ].
	 */
	public static function rebuild_registry( int $agent_id = 1 ): array {
		global $wpdb;
		self::ensure_table_exists();

		$settings        = Settings::get_all( $agent_id, false );
		$response_config = $settings['response_behavior'] ?? ( $settings['response'] ?? array() );

		$discovered = array();

		// 1. Sitemaps discovery (XML sitemaps, index files, nested sitemaps).
		$sitemap_urls = self::discover_sitemaps( $response_config );
		$visited      = array();

		foreach ( $sitemap_urls as $sm ) {
			$found = self::parse_sitemap_recursive( $sm, 0, $visited );
			foreach ( $found as $f ) {
				$norm = self::normalize_url( $f['url'] );
				if ( ! empty( $norm ) && ! preg_match( '/\.(xml|gz)$/i', $norm ) && ! str_contains( strtolower( $norm ), 'sitemap' ) ) {
					$discovered[ $norm ] = array(
						'url'       => $norm,
						'title'     => ! empty( $f['title'] ) ? $f['title'] : '',
						'lang_hint' => $f['lang'] ?? '',
						'lastmod'   => $f['lastmod'] ?? '',
						'slug'      => $f['slug'] ?? '',
						'source'    => 'sitemap',
					);
				}
			}
		}

		// 2. Published WordPress posts & pages (excluding draft, private, noindex, and non-German/non-English translations).
		$post_args = array(
			'post_type'      => array( 'page', 'post' ),
			'post_status'    => 'publish',
			'posts_per_page' => 400,
		);
		if ( function_exists( 'pll_get_post_language' ) ) {
			$post_args['lang'] = 'de,en';
		}
		$published_posts = get_posts( $post_args );
		foreach ( $published_posts as $p ) {
			// Skip any posts belonging to languages other than German or English (e.g. Polylang/WPML French translations).
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

			// Check password protection.
			if ( ! empty( $p->post_password ) ) {
				continue;
			}

			// Check Yoast / Rank Math / standard noindex meta.
			$yoast_noindex = get_post_meta( $p->ID, '_yoast_wpseo_meta-robots-noindex', true );
			$rank_noindex  = (string) get_post_meta( $p->ID, 'rank_math_robots', true );
			if ( '1' === (string) $yoast_noindex || str_contains( strtolower( $rank_noindex ), 'noindex' ) ) {
				continue;
			}

			$permalink = get_permalink( $p->ID );
			$norm      = self::normalize_url( $permalink );
			if ( ! empty( $norm ) ) {
				$title     = get_the_title( $p->ID );
				$post_text = ! empty( $p->post_excerpt ) ? $p->post_excerpt : wp_strip_all_tags( $p->post_content );
				$summary   = mb_substr( trim( preg_replace( '/\s+/', ' ', $post_text ) ), 0, 200 );
				$slug      = $p->post_name;
				$keywords  = str_replace( array( '-', '_' ), ' ', $slug ) . ' ' . $title;

				if ( ! isset( $discovered[ $norm ] ) ) {
					$discovered[ $norm ] = array(
						'url'          => $norm,
						'title'        => $title,
						'lang_hint'    => '',
						'summary'      => $summary,
						'keywords'     => $keywords,
						'slug'         => $slug,
						'source'       => 'sitemap',
						'pre_verified' => true,
					);
				} else {
					$discovered[ $norm ]['pre_verified'] = true;
					// Enrich title and summary if sitemap didn't have full post title.
					if ( empty( $discovered[ $norm ]['title'] ) || $discovered[ $norm ]['title'] === basename( $norm ) ) {
						$discovered[ $norm ]['title'] = $title;
					}
					if ( empty( $discovered[ $norm ]['summary'] ) ) {
						$discovered[ $norm ]['summary'] = $summary;
					}
					if ( empty( $discovered[ $norm ]['keywords'] ) ) {
						$discovered[ $norm ]['keywords'] = $keywords;
					}
				}
			}
		}

		// 3. Knowledge base source URLs & Chunks.
		$table_sources = $wpdb->prefix . 'ai_knowledge_sources';
		$kb_urls       = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT source_url, title, content, metadata FROM {$table_sources} WHERE agent_id = %d AND status != 'disabled' AND source_url IS NOT NULL",
				$agent_id
			),
			ARRAY_A
		);
		foreach ( (array) $kb_urls as $kb ) {
			$norm = self::normalize_url( $kb['source_url'] );
			if ( empty( $norm ) ) {
				continue;
			}

			$meta = ! empty( $kb['metadata'] ) ? json_decode( $kb['metadata'], true ) : array();
			$meta = is_array( $meta ) ? $meta : array();

			$summary = ! empty( $meta['summary'] ) ? $meta['summary'] : '';
			if ( empty( $summary ) && ! empty( $kb['content'] ) ) {
				$summary = mb_substr( trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $kb['content'] ) ) ), 0, 200 );
			}

			$keywords = ! empty( $meta['keywords'] ) ? $meta['keywords'] : ( $kb['title'] ?? '' );
			$lang     = ! empty( $meta['language'] ) ? $meta['language'] : '';

			if ( ! isset( $discovered[ $norm ] ) ) {
				$discovered[ $norm ] = array(
					'url'       => $norm,
					'title'     => $kb['title'] ?? '',
					'lang_hint' => $lang,
					'summary'   => $summary,
					'keywords'  => $keywords,
					'source'    => 'kb',
				);
			} else {
				$discovered[ $norm ]['source'] = 'kb';
				if ( ! empty( $summary ) && empty( $discovered[ $norm ]['summary'] ) ) {
					$discovered[ $norm ]['summary'] = $summary;
				}
				if ( ! empty( $keywords ) && empty( $discovered[ $norm ]['keywords'] ) ) {
					$discovered[ $norm ]['keywords'] = $keywords;
				}
				if ( ! empty( $lang ) && empty( $discovered[ $norm ]['lang_hint'] ) ) {
					$discovered[ $norm ]['lang_hint'] = $lang;
				}
			}
		}

		// Also check chunks metadata for distinct indexed URLs.
		$table_chunks = $wpdb->prefix . 'ai_knowledge_chunks';
		$chunk_rows   = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT chunk_text, language, metadata FROM {$table_chunks} WHERE agent_id = %d AND is_enabled = 1 AND metadata IS NOT NULL",
				$agent_id
			),
			ARRAY_A
		);
		foreach ( (array) $chunk_rows as $cr ) {
			$cmeta = json_decode( (string) $cr['metadata'], true );
			if ( ! is_array( $cmeta ) ) {
				continue;
			}
			$c_url = $cmeta['page_url'] ?? ( $cmeta['source_url'] ?? ( $cmeta['url'] ?? '' ) );
			if ( empty( $c_url ) ) {
				continue;
			}
			$c_norm = self::normalize_url( $c_url );
			if ( empty( $c_norm ) ) {
				continue;
			}
			if ( ! isset( $discovered[ $c_norm ] ) ) {
				$c_title   = $cmeta['page_title'] ?? ( $cmeta['title'] ?? '' );
				$c_summary = mb_substr( trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $cr['chunk_text'] ) ) ), 0, 200 );
				$discovered[ $c_norm ] = array(
					'url'       => $c_norm,
					'title'     => $c_title,
					'lang_hint' => $cr['language'] ?? '',
					'summary'   => $c_summary,
					'keywords'  => $c_title,
					'source'    => 'kb',
				);
			}
		}

		$valid_count   = 0;
		$invalid_count = 0;
		$verified_keys = array();

		// Validate discovered URLs.
		foreach ( $discovered as $item ) {
			$res = self::validate_url(
				$item['url'],
				array(
					'title'           => $item['title'] ?? '',
					'lang_hint'       => $item['lang_hint'] ?? '',
					'response_config' => $response_config,
					'pre_verified'    => ! empty( $item['pre_verified'] ),
				)
			);

			if ( $res['valid'] ) {
				$clean_slug = self::extract_slug_from_url( $res['canonical_url'] );
				$page_title = $res['title'];
				$page_desc  = $item['summary'] ?? '';
				$post_id    = url_to_postid( $res['canonical_url'] );
				$h1_val     = '';
				if ( $post_id > 0 ) {
					$post_obj = get_post( $post_id );
					if ( $post_obj ) {
						$h1_val = $post_obj->post_title;
						if ( preg_match( '/<h1[^>]*>(.*?)<\/h1>/is', $post_obj->post_content, $hm ) ) {
							$h1_val = trim( wp_strip_all_tags( $hm[1] ) );
						}
						if ( empty( $page_desc ) ) {
							$yoast_desc = (string) get_post_meta( $post_id, '_yoast_wpseo_metadesc', true );
							$rm_desc    = (string) get_post_meta( $post_id, 'rank_math_description', true );
							$page_desc  = ! empty( $yoast_desc ) ? $yoast_desc : ( ! empty( $rm_desc ) ? $rm_desc : '' );
							if ( empty( $page_desc ) && ! empty( $post_obj->post_excerpt ) ) {
								$page_desc = trim( wp_strip_all_tags( $post_obj->post_excerpt ) );
							}
							if ( empty( $page_desc ) && ! empty( $post_obj->post_content ) ) {
								$page_desc = mb_substr( trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $post_obj->post_content ) ) ), 0, 200 );
							}
						}
					}
				}

				self::add_or_update(
					array(
						'url'           => $res['url'],
						'canonical_url' => $res['canonical_url'],
						'title'         => $page_title,
						'slug'          => $clean_slug,
						'path'          => (string) wp_parse_url( $res['canonical_url'], PHP_URL_PATH ),
						'language'      => $res['language'],
						'http_status'   => 200,
						'source'        => $item['source'],
						'summary'       => $page_desc,
						'keywords'      => $item['keywords'] ?? '',
						'match_phrases' => array_filter( array( $page_title, $h1_val, $clean_slug, $item['keywords'] ?? '' ) ),
						'lastmod'       => $item['lastmod'] ?? '',
					)
				);
				$verified_keys[] = $res['canonical_url'];
				$valid_count++;
			} else {
				self::record_failure( $item['url'], $res['reason'], $res['http_status'] );
				$invalid_count++;
			}
		}

		// Remove URLs from registry that were not discovered in this run and had failures.
		$table      = self::get_table_name();
		$existing   = (array) $wpdb->get_col( "SELECT canonical_url FROM {$table}" );
		$removed    = 0;
		$stale_urls = array_diff( $existing, $verified_keys );

		foreach ( $stale_urls as $stale ) {
			// Check if stale URL still returns 200.
			$check = self::validate_url( $stale, array( 'response_config' => $response_config ) );
			if ( ! $check['valid'] ) {
				self::remove_link( $stale, 'Stale link no longer found in sitemap and failed validation' );
				$removed++;
			}
		}

		// Build and cache the Link Index.
		self::get_link_index( true );

		// Export JSON registry snapshot for offline/unit-testable lookup.
		self::export_to_json();

		update_option( self::LAST_REBUILD_OPTION, current_time( 'mysql' ), false );
		update_option( self::LINK_COUNT_OPTION, $valid_count, false );

		$logs = get_option( self::LOG_OPTION, array() );

		return array(
			'total_discovered' => count( $discovered ),
			'valid'            => $valid_count,
			'valid_count'      => $valid_count,
			'failed_count'     => $invalid_count,
			'url_count'        => $valid_count,
			'invalid'          => $invalid_count,
			'removed'          => $removed,
			'last_rebuild'     => current_time( 'mysql' ),
			'logs'             => is_array( $logs ) ? array_slice( $logs, 0, 100 ) : array(),
		);
	}

	/**
	 * Run daily WP-Cron revalidation in batches of 30.
	 *
	 * @return void
	 */
	public static function run_cron_revalidation(): void {
		global $wpdb;
		self::ensure_table_exists();

		$settings        = Settings::get_all( 1, false );
		$response_config = $settings['response_behavior'] ?? array();

		if ( isset( $response_config['cron_revalidation_enabled'] ) && ! $response_config['cron_revalidation_enabled'] ) {
			return;
		}

		$table = self::get_table_name();
		$rows  = $wpdb->get_results(
			"SELECT * FROM {$table} ORDER BY last_verified ASC LIMIT 30",
			ARRAY_A
		);

		if ( empty( $rows ) ) {
			return;
		}

		foreach ( $rows as $row ) {
			$res = self::validate_url(
				$row['canonical_url'],
				array(
					'title'           => $row['title'],
					'lang_hint'       => $row['language'],
					'response_config' => $response_config,
				)
			);

			if ( $res['valid'] ) {
				$wpdb->update(
					$table,
					array(
						'last_verified' => current_time( 'mysql' ),
						'fail_count'    => 0,
						'http_status'   => 200,
						'title'         => $res['title'] ?: $row['title'],
					),
					array( 'id' => (int) $row['id'] )
				);
			} else {
				self::record_failure( $row['canonical_url'], $res['reason'], $res['http_status'] );
			}
		}
	}

	/**
	 * Real-time freshness re-check (fast HEAD, 2s timeout) if older than 24h.
	 *
	 * @param string $canonical_url Canonical URL.
	 * @return bool True if healthy.
	 */
	public static function verify_realtime_freshness( string $canonical_url ): bool {
		global $wpdb;
		self::ensure_table_exists();

		$table = self::get_table_name();
		$row   = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE canonical_url = %s LIMIT 1", $canonical_url ),
			ARRAY_A
		);

		if ( ! $row ) {
			return false;
		}

		$last = ! empty( $row['last_verified'] ) ? strtotime( $row['last_verified'] ) : 0;
		if ( ( time() - $last ) < DAY_IN_SECONDS ) {
			return true; // Already verified within the last 24h.
		}

		// Fast HEAD check (2s timeout).
		$resp = wp_remote_head(
			$canonical_url,
			array(
				'timeout'     => 2,
				'redirection' => 2,
				'sslverify'   => true,
			)
		);

		$status = (int) wp_remote_retrieve_response_code( $resp );
		if ( 200 === $status ) {
			$wpdb->update(
				$table,
				array(
					'last_verified' => current_time( 'mysql' ),
					'fail_count'    => 0,
				),
				array( 'id' => (int) $row['id'] )
			);
			return true;
		}

		// Failed 2s re-check: mark failure and return false.
		self::record_failure( $canonical_url, sprintf( 'Real-time check failed with status %d', $status ), $status );

		return false;
	}

	/**
	 * Retrieve verified links for a set of retrieved chunks.
	 *
	 * @param array  $chunks Retrieved RAG chunks.
	 * @param string $language Response language (e.g. 'de', 'en', 'es', 'ne').
	 * @param int    $limit Maximum links to return (default 2).
	 * @return array Array of [ 'title' => string, 'url' => string, 'placeholder' => string ].
	 */
	public static function get_verified_links_for_chunks( array $chunks, string $language = 'de', int $limit = 2 ): array {
		global $wpdb;
		self::ensure_table_exists();

		if ( empty( $chunks ) || $limit <= 0 ) {
			return array();
		}

		$table = self::get_table_name();

		// Extract all source URLs from chunks.
		$urls = array();
		foreach ( $chunks as $chunk ) {
			$meta = $chunk['metadata'] ?? array();
			if ( is_string( $meta ) ) {
				$decoded = json_decode( $meta, true );
				$meta    = is_array( $decoded ) ? $decoded : array();
			}
			$src = ! empty( $meta['page_url'] ) ? $meta['page_url'] : ( ! empty( $meta['source_url'] ) ? $meta['source_url'] : ( $meta['url'] ?? '' ) );
			if ( ! empty( $src ) ) {
				$norm = self::normalize_url( $src );
				if ( ! empty( $norm ) ) {
					$urls[ $norm ] = $meta['page_title'] ?? ( $meta['title'] ?? '' );
				}
			}
		}

		if ( empty( $urls ) ) {
			return array();
		}

		$links = array();
		$index = 1;

		foreach ( $urls as $target_url => $fallback_title ) {
			if ( count( $links ) >= $limit ) {
				break;
			}

			// Query registry for verified canonical match.
			$row = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT * FROM {$table} WHERE canonical_url = %s OR url = %s LIMIT 1",
					$target_url,
					$target_url
				),
				ARRAY_A
			);

			if ( ! $row ) {
				continue;
			}

			// Strict Language Matching: German answers may only link German pages.
			$link_lang = strtolower( (string) $row['language'] );
			$user_lang = strtolower( (string) $language );

			if ( '' !== $user_lang && 'auto' !== $user_lang && $link_lang !== $user_lang ) {
				// If link language does not match visitor language, do not provide this link.
				continue;
			}

			// Verify real-time freshness.
			if ( ! self::verify_realtime_freshness( $row['canonical_url'] ) ) {
				continue;
			}

			$title = ! empty( $row['title'] ) ? $row['title'] : ( $fallback_title ?: $row['canonical_url'] );

			$links[] = array(
				'index'         => $index,
				'placeholder'   => '[[link:' . $index . ']]',
				'title'         => $title,
				'url'           => $row['canonical_url'],
				'canonical_url' => $row['canonical_url'],
				'language'      => $row['language'],
			);

			$index++;
		}

		return $links;
	}

	/**
	 * Get the complete in-memory or transient-cached Link Index.
	 *
	 * @param bool $force_refresh Whether to bypass cache.
	 * @return array Map of canonical_url => entry.
	 */
	public static function get_link_index( bool $force_refresh = false ): array {
		if ( ! $force_refresh ) {
			$cached = get_transient( self::LINK_INDEX_TRANSIENT );
			if ( is_array( $cached ) && ! empty( $cached ) ) {
				return $cached;
			}
			$opt = get_option( self::LINK_INDEX_OPTION, null );
			if ( is_array( $opt ) && ! empty( $opt ) ) {
				set_transient( self::LINK_INDEX_TRANSIENT, $opt, DAY_IN_SECONDS );
				return $opt;
			}
		}

		global $wpdb;
		self::ensure_table_exists();
		$table = self::get_table_name();

		$rows = $wpdb->get_results(
			"SELECT * FROM {$table} WHERE http_status = 200 AND fail_count = 0",
			ARRAY_A
		);

		$index = array();
		if ( ! empty( $rows ) ) {
			foreach ( $rows as $row ) {
				$canon = self::normalize_url( $row['canonical_url'] );
				if ( ! empty( $canon ) ) {
					$slug = ! empty( $row['slug'] ) ? $row['slug'] : self::extract_slug_from_url( $canon );
					$index[ strtolower( rtrim( $canon, '/' ) ) ] = array(
						'url'           => $row['url'],
						'canonical_url' => $canon,
						'title'         => $row['title'],
						'language'      => $row['language'] ?: 'de',
						'summary'       => $row['summary'] ?? '',
						'keywords'      => $row['keywords'] ?? '',
						'lastmod'       => $row['lastmod'] ?? '',
						'slug'          => $slug,
						'source'        => $row['source'] ?? 'sitemap',
						'match_phrases' => $row['match_phrases'] ?? '',
					);
				}
			}
		}

		set_transient( self::LINK_INDEX_TRANSIENT, $index, DAY_IN_SECONDS );
		update_option( self::LINK_INDEX_OPTION, $index, false );
		update_option( self::LINK_COUNT_OPTION, count( $index ), false );

		return $index;
	}

	/**
	 * Find relevant links matching query and retrieved chunks against the internal link index.
	 *
	 * Match priority:
	 * 1. Retrieved Knowledge Base sources (high similarity score).
	 * 2. Sitemap URL / slug / title keyword matching.
	 * 3. Language preference: matches user language, or translated page, or fallback to site default.
	 * 4. Deduplication against conversation history.
	 *
	 * @param string $query User message.
	 * @param array  $retrieved_chunks Retrieved RAG chunks.
	 * @param string $language Answer language ('de', 'en', 'es', 'ne', etc.).
	 * @param int    $max_links Maximum links (1-5).
	 * @param float  $threshold Relevance threshold (default 0.60).
	 * @param array  $response_config Settings config.
	 * @param array  $previously_linked Array of canonical URLs already linked in conversation.
	 * @return array Formatted candidate links array.
	 */
	public static function find_relevant_links(
		string $query,
		array $retrieved_chunks = array(),
		string $language = 'de',
		int $max_links = 3,
		float $threshold = 0.60,
		array $response_config = array(),
		array $previously_linked = array()
	): array {
		$max_links = min( 3, max( 1, $max_links ) );

		$index = self::get_link_index();
		if ( empty( $index ) ) {
			return array();
		}

		$user_lang = strtolower( trim( $language ) );
		if ( empty( $user_lang ) || 'auto' === $user_lang ) {
			$user_lang = 'de';
		}

		$candidates = array();
		$seen_keys  = array();

		$norm_prev = array();
		foreach ( (array) $previously_linked as $p_url ) {
			$norm_prev[ strtolower( rtrim( self::normalize_url( (string) $p_url ), '/' ) ) ] = true;
		}

		$dedup_conv = ! isset( $response_config['deduplicate_conversation_links'] ) || ! empty( $response_config['deduplicate_conversation_links'] );

		// 1. Retrieved Knowledge Base Sources.
		if ( ! empty( $retrieved_chunks ) ) {
			foreach ( $retrieved_chunks as $i => $chunk ) {
				$meta = $chunk['metadata'] ?? array();
				if ( is_string( $meta ) ) {
					$decoded = json_decode( $meta, true );
					$meta    = is_array( $decoded ) ? $decoded : array();
				}

				$src = ! empty( $meta['page_url'] ) ? $meta['page_url'] : ( ! empty( $meta['source_url'] ) ? $meta['source_url'] : ( $meta['url'] ?? '' ) );
				if ( empty( $src ) ) {
					continue;
				}

				$norm_url = self::normalize_url( $src );
				$norm_key = strtolower( rtrim( $norm_url, '/' ) );

				if ( $dedup_conv && isset( $norm_prev[ $norm_key ] ) ) {
					continue;
				}

				if ( isset( $seen_keys[ $norm_key ] ) ) {
					continue;
				}

				// Find in index.
				$entry = $index[ $norm_key ] ?? null;
				if ( ! $entry ) {
					$v_row = self::is_verified_url( $norm_url );
					if ( $v_row && self::verify_realtime_freshness( $v_row['canonical_url'] ) ) {
						$entry = array(
							'canonical_url' => $v_row['canonical_url'],
							'title'         => $v_row['title'],
							'slug'          => $v_row['slug'] ?? self::extract_slug_from_url( $v_row['canonical_url'] ),
							'language'      => $v_row['language'],
							'summary'       => $v_row['summary'] ?? '',
							'keywords'      => $v_row['keywords'] ?? '',
						);
					}
				}

				if ( ! $entry ) {
					continue;
				}

				// Language matching.
				$link_lang     = strtolower( (string) $entry['language'] );
				$matched_entry = $entry;

				if ( $link_lang !== $user_lang ) {
					$translated = self::find_translated_page( $entry, $user_lang, $index );
					if ( $translated ) {
						$matched_entry = $translated;
						$norm_key      = strtolower( rtrim( $translated['canonical_url'], '/' ) );
					} else {
						// Strict language match check: if no translation exists and target is not default, skip.
						if ( 'de' !== $link_lang && 'de' !== $user_lang ) {
							continue;
						}
					}
				}

				if ( ! self::verify_realtime_freshness( $matched_entry['canonical_url'] ) ) {
					continue;
				}

				$score = max( 0.70, 0.96 - ( $i * 0.05 ) );
				if ( $score >= $threshold ) {
					$seen_keys[ $norm_key ] = true;
					$candidates[]           = array(
						'title'          => ! empty( $matched_entry['title'] ) ? $matched_entry['title'] : ( $meta['page_title'] ?? $matched_entry['canonical_url'] ),
						'url'            => $matched_entry['canonical_url'],
						'canonical_url'  => $matched_entry['canonical_url'],
						'language'       => $matched_entry['language'],
						'summary'        => ! empty( $matched_entry['summary'] ) ? $matched_entry['summary'] : ( $meta['summary'] ?? '' ),
						'score'          => $score,
						'phrase_matched' => false,
						'slug_len'       => strlen( $matched_entry['canonical_url'] ),
					);
				}
			}
		}

		// 2. Query-time link matching across Link Registry (Synonyms + Semantic Keywords).
		$q_lower = mb_strtolower( $query );
		$raw_kws      = self::extract_query_keywords( $query );
		$site_brand   = function_exists( 'get_bloginfo' ) ? (string) get_bloginfo( 'name' ) : '';
		$brand_tokens = array_filter( preg_split( '/[\s\-_\.]+/', mb_strtolower( $site_brand ) ) );
		$kws          = array_values( array_filter( $raw_kws, function( $w ) use ( $brand_tokens ) {
			return ! in_array( $w, $brand_tokens, true );
		} ) );

		$synonym_phrases = array(
			'kontakt'              => array( 'kontakt', 'kontaktseite', 'contact', 'contact page', 'customer service', 'kundenservice', 'support', 'reach you', 'reach out', 'kundensupport', 'kontaktdaten', 'anfahrt' ),
			'datenschutzerklarung' => array( 'datenschutzerklärung', 'datenschutz', 'privacy policy', 'privacy', 'datenschutzbestimmungen' ),
			'impressum'            => array( 'impressum', 'legal notice', 'imprint', 'legal information' ),
			'ueber-uns'            => array( 'über uns', 'ueber uns', 'about us', 'about', 'who are you', 'who you are', 'who we are', 'company', 'uber uns' ),
			'career'               => array( 'career', 'careers', 'jobs', 'hiring', 'karriere', 'job openings', 'career opportunities' ),
			'services'             => array( 'services', 'leistungen', 'our services', 'what services', 'dienstleistungen', 'angebote' ),
			'pricing'              => array( 'pricing', 'prices', 'preise', 'kosten', 'rates', 'tarife' ),
		);

		$site_path = trim( (string) wp_parse_url( home_url(), PHP_URL_PATH ), '/' );

		$scored_registry = array();
		foreach ( $index as $norm_key => $entry ) {
			if ( isset( $seen_keys[ $norm_key ] ) ) {
				continue;
			}
			if ( $dedup_conv && isset( $norm_prev[ $norm_key ] ) ) {
				continue;
			}

			// Language matching.
			$link_lang    = strtolower( (string) ( $entry['language'] ?? 'de' ) );
			$target_entry = $entry;

			if ( $link_lang !== $user_lang ) {
				$translated = self::find_translated_page( $entry, $user_lang, $index );
				if ( $translated ) {
					$target_entry = $translated;
					$norm_key     = strtolower( rtrim( $translated['canonical_url'], '/' ) );
				} elseif ( 'de' !== $link_lang && 'de' !== $user_lang ) {
					continue;
				}
			}

			$path     = strtolower( (string) ( $target_entry['canonical_url'] ?? ( $target_entry['url'] ?? '' ) ) );
			$raw_slug = trim( (string) ( $target_entry['slug'] ?? wp_parse_url( $path, PHP_URL_PATH ) ), '/' );
			if ( ! empty( $site_path ) && str_starts_with( $raw_slug, $site_path ) ) {
				$raw_slug = trim( substr( $raw_slug, strlen( $site_path ) ), '/' );
			}
			$slug_base = basename( $raw_slug );

			$entry_fixed = $target_entry;
			$entry_fixed['slug'] = $raw_slug;

			$phrase_matched = false;
			$score          = 0.0;

			foreach ( $synonym_phrases as $key => $phrases ) {
				if ( $slug_base === $key || str_ends_with( rtrim( $path, '/' ), '/' . $key ) ) {
					foreach ( $phrases as $phrase ) {
						$p_clean = trim( mb_strtolower( (string) $phrase ) );
						if ( mb_strlen( $p_clean ) >= 4 && preg_match( '/\b' . preg_quote( $p_clean, '/' ) . '\b/u', $q_lower ) ) {
							$score          = 1.0;
							$phrase_matched = true;
							break;
						}
					}
				}
			}

			if ( ! $phrase_matched && ! empty( $kws ) ) {
				$kw_score = self::calculate_keyword_score( $kws, $entry_fixed );
				$score    = max( $score, $kw_score );
			}

			if ( $score >= $threshold ) {
				$scored_registry[] = array(
					'title'          => $target_entry['title'],
					'url'            => $target_entry['canonical_url'],
					'canonical_url'  => $target_entry['canonical_url'],
					'language'       => $target_entry['language'],
					'summary'        => $target_entry['summary'] ?? '',
					'score'          => $score,
					'norm_key'       => $norm_key,
					'phrase_matched' => $phrase_matched,
					'slug_len'       => strlen( $raw_slug ),
				);
			}
		}

		// Pool chunk candidates and query-matched registry candidates together.
		$all_candidates = array_merge( $candidates, $scored_registry );

		usort( $all_candidates, function( $a, $b ) {
			// Direct query phrase matches (e.g. user asks for "customer service" -> contact page) ALWAYS take top precedence.
			if ( $a['phrase_matched'] !== $b['phrase_matched'] ) {
				return $b['phrase_matched'] ? 1 : -1;
			}
			// Higher relevance score wins.
			if ( abs( $b['score'] - $a['score'] ) >= 0.05 ) {
				return $b['score'] <=> $a['score'];
			}
			// Shorter slug length wins for tie-breaking.
			return $a['slug_len'] <=> $b['slug_len'];
		} );

		$final_candidates = array();
		$seen_final_keys  = array();

		foreach ( $all_candidates as $c_item ) {
			if ( count( $final_candidates ) >= $max_links ) {
				break;
			}
			$c_key = strtolower( rtrim( (string) $c_item['canonical_url'], '/' ) );
			if ( isset( $seen_final_keys[ $c_key ] ) ) {
				continue;
			}
			if ( self::verify_realtime_freshness( $c_item['canonical_url'] ) ) {
				$seen_final_keys[ $c_key ] = true;
				$final_candidates[]        = $c_item;
			}
		}

		$candidates = $final_candidates;

		// Log matches and scores for query-time auditing.
		error_log( sprintf( '[AI Chatbot LinkRegistry] Query: "%s" | Matches (%d): %s', $query, count( $candidates ), wp_json_encode( array_map( function( $c ) {
			return array( 'title' => $c['title'], 'url' => $c['canonical_url'], 'score' => $c['score'] );
		}, $candidates ) ) ) );

		$formatted = array();
		foreach ( $candidates as $idx => $c ) {
			$num         = $idx + 1;
			$formatted[] = array(
				'index'         => $num,
				'placeholder'   => '[[LINK_' . $num . ']]',
				'title'         => $c['title'],
				'url'           => $c['canonical_url'],
				'canonical_url' => $c['canonical_url'],
				'language'      => $c['language'],
				'summary'       => $c['summary'],
				'score'         => $c['score'],
			);
		}

		return $formatted;
	}

	/**
	 * Find a translated alternate version of a page in the target language.
	 *
	 * @param array  $entry Link index entry.
	 * @param string $target_lang Target language code.
	 * @param array  $index Link index.
	 * @return array|null Matched translated entry or null.
	 */
	public static function find_translated_page( array $entry, string $target_lang, array $index ): ?array {
		$target_lang = strtolower( trim( $target_lang ) );
		$orig_slug   = self::extract_slug_from_url( $entry['canonical_url'] );
		$orig_base   = basename( $orig_slug );

		if ( empty( $orig_base ) ) {
			return null;
		}

		foreach ( $index as $item ) {
			if ( strtolower( (string) ( $item['language'] ?? '' ) ) !== $target_lang ) {
				continue;
			}
			$item_slug = self::extract_slug_from_url( $item['canonical_url'] );
			$item_base = basename( $item_slug );

			if ( empty( $item_base ) ) {
				continue;
			}

			// Clean language prefix if present (e.g. en/page vs de/page)
			$clean_orig = preg_replace( '#^(?:en|de|es|fr|it|pt|ne|hi)/#i', '', $orig_slug );
			$clean_item = preg_replace( '#^(?:en|de|es|fr|it|pt|ne|hi)/#i', '', $item_slug );

			if ( '' !== $clean_orig && $clean_orig === $clean_item ) {
				return $item;
			}

			if ( $orig_base === $item_base && strlen( $orig_base ) >= 3 ) {
				return $item;
			}
		}

		return null;
	}

	/**
	 * Extract meaningful search keywords from user query.
	 *
	 * @param string $query User query text.
	 * @return string[] Keywords.
	 */
	public static function extract_query_keywords( string $query ): array {
		$clean = preg_replace( '/[^\p{L}\p{M}\p{N}\s]/u', ' ', $query );
		$words = preg_split( '/\s+/u', mb_strtolower( trim( (string) $clean ) ) );
		if ( empty( $words ) ) {
			return array();
		}

		$stopwords = array(
			// German
			'der', 'die', 'das', 'den', 'dem', 'des', 'ein', 'eine', 'einer', 'eines', 'einem', 'einen',
			'und', 'oder', 'aber', 'in', 'im', 'zu', 'zum', 'zur', 'auf', 'mit', 'von', 'aus', 'nach',
			'bei', 'für', 'uber', 'über', 'unter', 'vor', 'ist', 'sind', 'war', 'kann', 'können', 'sie',
			'wir', 'ich', 'du', 'ihr', 'was', 'wie', 'wo', 'wann', 'warum', 'wer', 'gibt', 'haben',
			'bitte', 'danke', 'möchte', 'mochte',
			// English
			'the', 'a', 'an', 'and', 'or', 'but', 'in', 'on', 'at', 'to', 'for', 'of', 'with', 'from',
			'by', 'about', 'is', 'are', 'was', 'were', 'can', 'could', 'would', 'should', 'you', 'i',
			'we', 'they', 'what', 'how', 'where', 'when', 'why', 'who', 'please', 'tell', 'me', 'have',
			// Spanish
			'el', 'la', 'los', 'las', 'un', 'una', 'unos', 'unas', 'y', 'o', 'pero', 'en', 'de', 'para',
			'por', 'con', 'es', 'son', 'que', 'como', 'donde', 'puedo', 'puedes',
			// Nepali
			'को', 'का', 'की', 'मा', 'र', 'वा', 'के', 'हो', 'छ', 'छन्', 'मलाई', 'तपाईं', 'तपाईंको',
		);
		$stop_map = array_flip( $stopwords );

		$keywords = array();
		foreach ( $words as $w ) {
			if ( mb_strlen( $w ) >= 3 && ! isset( $stop_map[ $w ] ) ) {
				$keywords[] = $w;
			}
		}

		return array_values( array_unique( $keywords ) );
	}

	/**
	 * Calculate keyword matching score between query tokens and link index item.
	 *
	 * @param array $keywords Keywords.
	 * @param array $entry Link index item.
	 * @return float Score between 0.0 and 1.0.
	 */
	public static function calculate_keyword_score( array $keywords, array $entry ): float {
		if ( empty( $keywords ) ) {
			return 0.0;
		}

		$title    = mb_strtolower( (string) ( $entry['title'] ?? '' ) );
		$slug     = mb_strtolower( str_replace( array( '-', '_' ), ' ', (string) ( $entry['slug'] ?? '' ) ) );
		$item_kws = mb_strtolower( (string) ( $entry['keywords'] ?? '' ) );
		$summary  = mb_strtolower( (string) ( $entry['summary'] ?? '' ) );

		$match_phrases = $entry['match_phrases'] ?? '';
		if ( is_array( $match_phrases ) ) {
			$match_phrases = implode( ' ', $match_phrases );
		}
		$mp_lower = mb_strtolower( (string) $match_phrases );

		$score   = 0.0;
		$matches = 0;

		foreach ( $keywords as $kw ) {
			if ( $title === $kw || $slug === $kw ) {
				$score += 0.85;
				$matches++;
			} elseif ( preg_match( '/\b' . preg_quote( $kw, '/' ) . '\b/u', $title ) || preg_match( '/\b' . preg_quote( $kw, '/' ) . '\b/u', $slug ) ) {
				$score += 0.70;
				$matches++;
			} elseif ( str_contains( $title, $kw ) || str_contains( $slug, $kw ) ) {
				$score += 0.45;
				$matches++;
			} elseif ( ! empty( $mp_lower ) && ( str_contains( $mp_lower, $kw ) || preg_match( '/\b' . preg_quote( $kw, '/' ) . '\b/u', $mp_lower ) ) ) {
				$score += 0.70;
				$matches++;
			} elseif ( str_contains( $item_kws, $kw ) ) {
				$score += 0.35;
				$matches++;
			} elseif ( str_contains( $summary, $kw ) ) {
				$score += 0.20;
				$matches++;
			}
		}

		if ( $matches === 0 ) {
			return 0.0;
		}

		if ( count( $keywords ) > 1 && $matches > 1 ) {
			$score += 0.15;
		}

		return min( 1.0, round( $score, 2 ) );
	}

	/**
	 * Check if a URL exists in the internal link index (or verified registry) and is valid to display.
	 *
	 * Whitelist check: only URLs in this index or site home/contact may appear in replies.
	 *
	 * @param string $url Target URL.
	 * @param array  $response_config Settings.
	 * @return array|null Returns entry array if valid and in index, null otherwise.
	 */
	public static function is_url_in_link_index( string $url, array $response_config = array() ): ?array {
		$clean = self::normalize_url( $url );
		if ( empty( $clean ) ) {
			return null;
		}

		// 1. Allowed host check.
		if ( ! self::is_allowed_host( $clean, $response_config ) ) {
			return null;
		}

		// 2. Exclusion pattern check.
		if ( self::is_excluded( $clean, '', $response_config ) ) {
			return null;
		}

		$norm_key = strtolower( rtrim( $clean, '/' ) );
		$index    = self::get_link_index();

		if ( isset( $index[ $norm_key ] ) ) {
			return $index[ $norm_key ];
		}

		// Check with trailing slash variation or without.
		$slash_key = str_ends_with( $clean, '/' ) ? strtolower( rtrim( $clean, '/' ) ) : strtolower( $clean . '/' );
		if ( isset( $index[ $slash_key ] ) ) {
			return $index[ $slash_key ];
		}

		// Site home URL or official contact URL check.
		$home_key = strtolower( rtrim( self::normalize_url( home_url() ), '/' ) );
		if ( $norm_key === $home_key ) {
			return array(
				'url'           => home_url(),
				'canonical_url' => home_url(),
				'title'         => get_bloginfo( 'name' ),
				'language'      => 'de',
				'summary'       => '',
			);
		}

		// Contact URL from settings or site.
		$contact_url = ! empty( $response_config['contact_url'] )
			? $response_config['contact_url']
			: ( ! empty( $response_config['business_info']['contact_url'] )
				? $response_config['business_info']['contact_url']
				: ( class_exists( '\AIChatbot\Rest\ChatController' ) ? \AIChatbot\Rest\ChatController::resolve_site_contact_url() : '' ) );
		if ( ! empty( $contact_url ) && strtolower( rtrim( self::normalize_url( $contact_url ), '/' ) ) === $norm_key ) {
			return array(
				'url'           => $contact_url,
				'canonical_url' => $contact_url,
				'title'         => 'Kontakt',
				'language'      => 'de',
				'summary'       => '',
			);
		}

		// Database registry fallback check.
		$v_row = self::is_verified_url( $clean );
		if ( $v_row && (int) ( $v_row['http_status'] ?? 0 ) === 200 && (int) ( $v_row['fail_count'] ?? 0 ) === 0 ) {
			return array(
				'url'           => $v_row['canonical_url'],
				'canonical_url' => $v_row['canonical_url'],
				'title'         => $v_row['title'],
				'language'      => $v_row['language'],
				'summary'       => $v_row['summary'] ?? '',
			);
		}

		return null;
	}

	/**
	 * Get link registry row for a URL.
	 *
	 * @param string $url URL to find.
	 * @param string $language Optional language code.
	 * @return array|null Row or null.
	 */
	public static function get_link_by_url( string $url, string $language = '' ): ?array {
		return self::is_verified_url( $url, $language );
	}

	/**
	 * Check whether a given URL is verified in the registry.
	 *
	 * @param string $url URL to test.
	 * @param string $language Optional language to match.
	 * @return array|null Registry row or null if not verified.
	 */
	public static function is_verified_url( string $url, string $language = '' ): ?array {
		global $wpdb;
		self::ensure_table_exists();

		$clean = self::normalize_url( $url );
		if ( empty( $clean ) ) {
			return null;
		}

		$table = self::get_table_name();
		$row   = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE canonical_url = %s OR url = %s LIMIT 1",
				$clean,
				$clean
			),
			ARRAY_A
		);

		if ( ! $row ) {
			return null;
		}

		if ( '' !== $language && 'auto' !== $language ) {
			if ( strtolower( (string) $row['language'] ) !== strtolower( $language ) ) {
				return null;
			}
		}

		return $row;
	}

	/**
	 * Unit-testable lookup interface for finding canonical link data.
	 *
	 * Normalizes input URL strictly for comparison and retrieves the exact
	 * canonical URL and metadata from the verified registry.
	 *
	 * @param string  $url URL to look up.
	 * @param ?string $language Optional language code filter.
	 * @return array|null Registry record or null.
	 */
	public static function lookup_canonical( string $url, ?string $language = null ): ?array {
		$target_norm = self::normalize_for_comparison( $url );
		if ( empty( $target_norm ) ) {
			return null;
		}

		$all = self::get_all_verified_links( $language );
		foreach ( $all as $link ) {
			$canon = $link['canonical_url'] ?? ( $link['url'] ?? '' );
			if ( self::normalize_for_comparison( $canon ) === $target_norm ) {
				return $link;
			}
			if ( ! empty( $link['url'] ) && self::normalize_for_comparison( $link['url'] ) === $target_norm ) {
				return $link;
			}
		}

		// Fallback: If site has no distinct translated variant for the language, check active verified links.
		if ( ! empty( $language ) && 'auto' !== $language ) {
			$all_unfiltered = self::get_all_verified_links( null );
			foreach ( $all_unfiltered as $link ) {
				$canon = $link['canonical_url'] ?? ( $link['url'] ?? '' );
				if ( self::normalize_for_comparison( $canon ) === $target_norm ) {
					return $link;
				}
				if ( ! empty( $link['url'] ) && self::normalize_for_comparison( $link['url'] ) === $target_norm ) {
					return $link;
				}
			}
		}

		return null;
	}

	/**
	 * Check whether an exact canonical URL exists in the verified registry.
	 *
	 * @param string  $url Target URL.
	 * @param ?string $language Optional language code.
	 * @return bool True if strictly verified and present in registry.
	 */
	public static function is_exact_canonical_in_registry( string $url, ?string $language = null ): bool {
		return null !== self::lookup_canonical( $url, $language );
	}

	/**
	 * Get all active verified links from registry (DB or JSON fallback).
	 *
	 * @param ?string $language Optional language filter.
	 * @return array[] Array of link records.
	 */
	public static function get_all_verified_links( ?string $language = null ): array {
		global $wpdb;
		$links = array();

		if ( isset( $wpdb ) && method_exists( $wpdb, 'get_results' ) ) {
			self::ensure_table_exists();
			$table = self::get_table_name();
			$rows  = $wpdb->get_results(
				"SELECT * FROM {$table} WHERE http_status = 200 AND fail_count = 0 ORDER BY id ASC",
				defined( 'ARRAY_A' ) ? ARRAY_A : 'ARRAY_A'
			);
			if ( ! empty( $rows ) ) {
				$links = $rows;
			}
		}

		// Fallback to JSON file if DB query returned nothing or in standalone/test environment.
		if ( empty( $links ) ) {
			$from_json = self::load_from_json();
			if ( ! empty( $from_json['urls'] ) ) {
				$links = $from_json['urls'];
			}
		}

		if ( ! empty( $language ) && 'auto' !== $language ) {
			$lang_lower = strtolower( trim( $language ) );
			$by_concept = array();
			foreach ( $links as $item ) {
				$item_lang = strtolower( trim( $item['language'] ?? 'de' ) );
				$slug      = trim( (string) ( $item['slug'] ?? wp_parse_url( $item['canonical_url'] ?? '', PHP_URL_PATH ) ), '/' );
				$base_slug = preg_replace( '#^(?:de|en|es|fr|it|ne)/#i', '', $slug );

				if ( ! isset( $by_concept[ $base_slug ] ) ) {
					$by_concept[ $base_slug ] = $item;
				} else {
					$current_lang = strtolower( trim( $by_concept[ $base_slug ]['language'] ?? 'de' ) );
					if ( $current_lang !== $lang_lower && $item_lang === $lang_lower ) {
						$by_concept[ $base_slug ] = $item;
					}
				}
			}
			$links = array_values( $by_concept );
		}

		return $links;
	}

	/**
	 * Build a curated phrase-to-URL map for auto-linking.
	 *
	 * STEP 1 & 3c REQUIREMENT:
	 * Exact whole-word, case-insensitive matching against curated phrase-to-URL map.
	 * If a phrase maps to multiple distinct canonical URLs, do NOT link it (avoid ambiguity!).
	 *
	 * @param ?string $language Language filter.
	 * @return array<string, array{phrase: string, canonical_url: string, url: string, title: string, language: string}>
	 */
	public static function get_phrase_to_url_map( ?string $language = null ): array {
		$links        = self::get_all_verified_links( $language );
		$phrase_map   = array();
		$ambiguous    = array();

		foreach ( $links as $link ) {
			$canon = $link['canonical_url'] ?? '';
			if ( empty( $canon ) ) {
				continue;
			}

			// Ignore home page and header/footer builder templates from phrase auto-linking
			$clean_path = trim( (string) wp_parse_url( $canon, PHP_URL_PATH ), '/' );
			if ( empty( $clean_path ) || str_starts_with( $clean_path, 'btf_builder' ) ) {
				continue;
			}

			$phrases = array();

			// 1. Title itself (and clean title without suffix branding).
			$raw_title = trim( $link['title'] ?? '' );
			if ( ! empty( $raw_title ) ) {
				$phrases[] = $raw_title;
				$site_brand = function_exists( 'get_bloginfo' ) ? preg_quote( (string) get_bloginfo( 'name' ), '/' ) : '';
				if ( ! empty( $site_brand ) && preg_match( '/^(.*?)\s*[-|–]\s*(?:' . $site_brand . ')/ui', $raw_title, $tm ) ) {
					$phrases[] = trim( $tm[1] );
				}
			}

			// 2. Slug derived phrase.
			$slug = $link['slug'] ?? '';
			if ( ! empty( $slug ) ) {
				$slug_words = trim( str_replace( array( '-', '_' ), ' ', $slug ) );
				if ( ! empty( $slug_words ) && mb_strlen( $slug_words ) >= 4 ) {
					$phrases[] = $slug_words;
				}
			}

			// 3. Stored match phrases from DB / sitemap.
			$stored_mp = $link['match_phrases'] ?? '';
			if ( ! empty( $stored_mp ) ) {
				$decoded = is_string( $stored_mp ) ? json_decode( $stored_mp, true ) : $stored_mp;
				if ( is_array( $decoded ) ) {
					foreach ( $decoded as $p ) {
						if ( is_string( $p ) && '' !== trim( $p ) ) {
							$phrases[] = trim( $p );
						}
					}
				}
			}

			// 4. Standard semantic phrases mapped to canonical pages.
			$path = strtolower( (string) ( $link['path'] ?? wp_parse_url( $canon, PHP_URL_PATH ) ) );
			if ( str_contains( $path, 'kontakt' ) || str_contains( $path, 'contact' ) ) {
				$phrases[] = 'Kontakt';
				$phrases[] = 'Kontaktseite';
				$phrases[] = 'Contact';
				$phrases[] = 'Contact page';
				$phrases[] = 'Customer service';
				$phrases[] = 'Kundenservice';
			} elseif ( str_contains( $path, 'datenschutzerklarung' ) || str_contains( $path, 'privacy-policy' ) || ( str_contains( $path, 'datenschutz' ) && ! str_contains( $path, 'bestimmungen' ) ) ) {
				$phrases[] = 'Datenschutzerklärung';
				$phrases[] = 'Datenschutz';
				$phrases[] = 'Privacy Policy';
				$phrases[] = 'Privacy policy';
			} elseif ( str_contains( $path, 'datenschutzbestimmungen' ) ) {
				$phrases[] = 'Datenschutzbestimmungen';
			} elseif ( str_contains( $path, 'impressum' ) || str_contains( $path, 'imprint' ) ) {
				$phrases[] = 'Impressum';
				$phrases[] = 'Legal Notice';
			}

			foreach ( $phrases as $phrase ) {
				$p_norm = mb_strtolower( trim( $phrase ) );
				// Discard very short tokens (<3 chars) and generic/brand stop phrases.
				if ( mb_strlen( $p_norm ) < 3 ) {
					continue;
				}

				$site_title = function_exists( 'get_bloginfo' ) ? mb_strtolower( trim( (string) get_bloginfo( 'name' ) ) ) : '';
				$site_host  = mb_strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
				$stop_brand = array( 'homepage', 'home', 'startseite', 'website', 'footer', 'header', 'blogs', 'blog' );
				if ( ! empty( $site_title ) ) {
					$stop_brand[] = $site_title;
				}
				if ( ! empty( $site_host ) ) {
					$stop_brand[] = $site_host;
					$stop_brand[] = preg_replace( '/^www\./', '', $site_host );
				}

				if ( in_array( $p_norm, $stop_brand, true ) ) {
					continue;
				}

				if ( isset( $ambiguous[ $p_norm ] ) ) {
					continue;
				}

				if ( isset( $phrase_map[ $p_norm ] ) ) {
					// Check if already mapped to a different canonical URL.
					if ( self::normalize_for_comparison( $phrase_map[ $p_norm ]['canonical_url'] ) !== self::normalize_for_comparison( $canon ) ) {
						// AMBIGUOUS! Disqualify this phrase completely.
						$ambiguous[ $p_norm ] = true;
						unset( $phrase_map[ $p_norm ] );
					}
				} else {
					$phrase_map[ $p_norm ] = array(
						'phrase'        => $phrase,
						'canonical_url' => $canon,
						'url'           => $canon,
						'title'         => $link['title'] ?? $phrase,
						'language'      => $link['language'] ?? 'de',
					);
				}
			}
		}

		// Sort phrases by character length descending so longer phrases match first.
		uksort( $phrase_map, function( $a, $b ) {
			return mb_strlen( $b ) <=> mb_strlen( $a );
		} );

		return $phrase_map;
	}

	/**
	 * Get default JSON file path for url_registry.json.
	 *
	 * @return string Filepath.
	 */
	public static function get_json_file_path(): string {
		$plugin_data_dir = dirname( __DIR__, 2 ) . '/data';
		if ( ! is_dir( $plugin_data_dir ) && function_exists( 'wp_mkdir_p' ) ) {
			wp_mkdir_p( $plugin_data_dir );
		}
		return $plugin_data_dir . '/url_registry.json';
	}

	/**
	 * Export verified registry to JSON file.
	 *
	 * @param ?string $filepath Optional destination path.
	 * @return string Path to exported file.
	 */
	public static function export_to_json( ?string $filepath = null ): string {
		if ( empty( $filepath ) ) {
			$filepath = self::get_json_file_path();
		}

		$links   = self::get_all_verified_links();
		$payload = array(
			'last_updated' => current_time( 'mysql' ),
			'count'        => count( $links ),
			'urls'         => $links,
		);

		$dir = dirname( $filepath );
		if ( ! is_dir( $dir ) && function_exists( 'wp_mkdir_p' ) ) {
			wp_mkdir_p( $dir );
		} elseif ( ! is_dir( $dir ) ) {
			mkdir( $dir, 0755, true );
		}

		file_put_contents( $filepath, wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );

		// Also export to uploads directory as backup.
		if ( defined( 'WP_CONTENT_DIR' ) ) {
			$upload_path = WP_CONTENT_DIR . '/uploads/ai-chatbot/url_registry.json';
			$u_dir       = dirname( $upload_path );
			if ( ! is_dir( $u_dir ) && function_exists( 'wp_mkdir_p' ) ) {
				wp_mkdir_p( $u_dir );
			}
			@file_put_contents( $upload_path, wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
		}

		return $filepath;
	}

	/**
	 * Load verified registry from JSON file.
	 *
	 * @param ?string $filepath Optional source path.
	 * @return array Decoded data array or empty.
	 */
	public static function load_from_json( ?string $filepath = null ): array {
		if ( empty( $filepath ) ) {
			$filepath = self::get_json_file_path();
		}

		if ( ! file_exists( $filepath ) ) {
			if ( defined( 'WP_CONTENT_DIR' ) ) {
				$upload_path = WP_CONTENT_DIR . '/uploads/ai-chatbot/url_registry.json';
				if ( file_exists( $upload_path ) ) {
					$filepath = $upload_path;
				}
			}
		}

		if ( file_exists( $filepath ) ) {
			$raw = file_get_contents( $filepath );
			if ( ! empty( $raw ) ) {
				$decoded = json_decode( $raw, true );
				if ( is_array( $decoded ) ) {
					return $decoded;
				}
			}
		}

		return array();
	}

	/**
	 * WP-CLI command: wp ai-chatbot links rebuild
	 */
	public static function cli_rebuild( $args, $assoc_args ): void {
		if ( class_exists( '\WP_CLI' ) ) {
			\WP_CLI::line( 'Rebuilding Verified Link Registry from sitemaps and knowledge base...' );
			$report = self::rebuild_registry();
			\WP_CLI::success( sprintf( 'Rebuild complete. Discovered: %d, Valid: %d, Failed: %d, Removed: %d', $report['total_discovered'], $report['valid_count'], $report['failed_count'], $report['removed'] ) );
		}
	}

	/**
	 * WP-CLI command: wp ai-chatbot links list
	 */
	public static function cli_list( $args, $assoc_args ): void {
		if ( class_exists( '\WP_CLI' ) ) {
			$links = self::get_all_verified_links();
			if ( empty( $links ) ) {
				\WP_CLI::warning( 'No verified links currently registered.' );
				return;
			}
			$formatter = new \WP_CLI\Formatter( $assoc_args, array( 'id', 'title', 'canonical_url', 'language', 'source' ) );
			$formatter->display_items( $links );
		}
	}

	/**
	 * WP-CLI command: wp ai-chatbot links export
	 */
	public static function cli_export( $args, $assoc_args ): void {
		if ( class_exists( '\WP_CLI' ) ) {
			$path = self::export_to_json();
			\WP_CLI::success( "Link registry exported to {$path}" );
		}
	}

	/**
	 * Get current registry status report.
	 *
	 * @return array Status stats and recent logs.
	 */
	public static function get_status_report(): array {
		global $wpdb;
		self::ensure_table_exists();

		$table = self::get_table_name();
		$valid = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE http_status = 200 AND fail_count = 0" );
		$fails = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE fail_count > 0" );
		$last  = get_option( self::LAST_REBUILD_OPTION, '' );
		$logs  = get_option( self::LOG_OPTION, array() );

		return array(
			'valid_count'  => $valid,
			'url_count'    => $valid,
			'failed_count' => $fails,
			'last_rebuild' => $last,
			'logs'         => is_array( $logs ) ? array_slice( $logs, 0, 100 ) : array(),
		);
	}

	/**
	 * Post lifecycle hook: save_post.
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post Post object.
	 * @param bool     $update Whether this is an update.
	 * @return void
	 */
	public static function on_save_post( int $post_id, $post, bool $update ): void {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}

		if ( ! in_array( $post->post_type, array( 'page', 'post' ), true ) ) {
			return;
		}

		$permalink = get_permalink( $post_id );
		if ( ! $permalink ) {
			return;
		}

		if ( 'publish' === $post->post_status ) {
			$title = get_the_title( $post_id );
			$res   = self::validate_url( $permalink, array( 'title' => $title ) );
			if ( $res['valid'] ) {
				self::add_or_update(
					array(
						'url'           => $res['url'],
						'canonical_url' => $res['canonical_url'],
						'title'         => $res['title'],
						'language'      => $res['language'],
						'http_status'   => 200,
						'source'        => 'sitemap',
					)
				);
			} else {
				self::remove_link( $permalink, 'Post failed validation on save: ' . $res['reason'] );
			}
		} else {
			// Not published: remove immediately.
			self::remove_link( $permalink, sprintf( 'Post status changed to %s', $post->post_status ) );
		}
	}

	/**
	 * Post lifecycle hook: transition_post_status.
	 *
	 * @param string   $new_status New post status.
	 * @param string   $old_status Old post status.
	 * @param \WP_Post $post Post object.
	 * @return void
	 */
	public static function on_transition_post_status( string $new_status, string $old_status, $post ): void {
		if ( $new_status === $old_status ) {
			return;
		}

		if ( empty( $post->ID ) || ! in_array( $post->post_type, array( 'page', 'post' ), true ) ) {
			return;
		}

		$permalink = get_permalink( $post->ID );
		if ( ! $permalink ) {
			return;
		}

		if ( 'publish' === $new_status ) {
			$res = self::validate_url( $permalink, array( 'title' => get_the_title( $post->ID ) ) );
			if ( $res['valid'] ) {
				self::add_or_update(
					array(
						'url'           => $res['url'],
						'canonical_url' => $res['canonical_url'],
						'title'         => $res['title'],
						'language'      => $res['language'],
						'http_status'   => 200,
						'source'        => 'sitemap',
					)
				);
			}
		} else {
			self::remove_link( $permalink, sprintf( 'Status transitioned from %s to %s', $old_status, $new_status ) );
		}
	}

	/**
	 * Post lifecycle hook: deleted_post.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public static function on_deleted_post( int $post_id ): void {
		$permalink = get_permalink( $post_id );
		if ( $permalink ) {
			self::remove_link( $permalink, 'Post deleted permanently' );
		}
	}
}


