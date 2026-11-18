<?php
/**
 * Polite domain crawler for the One-Paste Setup Wizard and website import.
 *
 * Discovery order:
 *   1. robots.txt is always read first and respected.
 *   2. XML sitemaps (sitemap_index.xml, sitemap.xml, wp-sitemap.xml) are used
 *      for complete, ordered coverage of a site.
 *   3. HTML link crawling is used as a fallback / top-up when no sitemap exists
 *      or when the sitemap does not yield enough pages.
 *
 * @package AIChatbot\Wizard
 */

namespace AIChatbot\Wizard;

use AIChatbot\Knowledge\Extractor;

defined( 'ABSPATH' ) || exit;

/**
 * Class SiteCrawler.
 */
class SiteCrawler {

	/**
	 * Maximum characters of extracted text kept per page for AI drafting.
	 */
	public const DRAFT_TEXT_LIMIT = 4000;

	/**
	 * Maximum characters of extracted text stored per page for indexing.
	 */
	public const INDEX_TEXT_LIMIT = 20000;

	/**
	 * Number of nested sitemaps followed before giving up.
	 */
	protected const MAX_NESTED_SITEMAPS = 10;

	/**
	 * URL path fragments that carry business-critical information.
	 * Used to prioritize sitemap entries ahead of blog noise.
	 *
	 * @var string[]
	 */
	protected const PRIORITY_PATTERNS = array(
		'about', 'contact', 'service', 'product', 'pricing', 'price', 'faq',
		'home', 'mission', 'vision', 'value', 'team', 'career', 'solution',
		'portfolio', 'project', 'industry', 'support', 'help', 'impressum',
		'angebot', 'kontakt', 'leistung', 'produkt', 'uber-uns', 'ueber-uns',
	);

	/**
	 * URL path fragments that should never be imported.
	 *
	 * @var string[]
	 */
	protected const SKIP_PATTERNS = array(
		'/wp-admin', '/wp-login', '/wp-json', '/xmlrpc.php', '/feed/',
		'/author/', '/tag/', '/?replytocom', 'cart', 'checkout', 'my-account',
		'wp-content/uploads', '.pdf', '.jpg', '.jpeg', '.png', '.gif', '.svg',
		'.webp', '.zip', '.mp4', '.mp3', '/comment-page-', 'search?q=',
		'login', 'register', 'sign-up', 'signin', 'logout', 'privacy-policy',
		'/fr/', '/fr-',
	);

	/**
	 * Hard safety ceiling applied even when no page limit was requested.
	 *
	 * A genuinely unbounded crawl of a large site can exhaust PHP's execution
	 * time and memory long before it finishes. This keeps a runaway crawl from
	 * taking the site down while still allowing effectively unlimited imports.
	 */
	public const HARD_PAGE_CEILING = 2000;

	/**
	 * Crawl a website and return pages with extracted text.
	 *
	 * @param string $start_url  Initial website URL (or any page on the domain).
	 * @param int    $max_pages  Maximum pages to crawl. Use 0 for no limit.
	 * @param int    $text_limit Maximum characters of text kept per page.
	 * @return array List of pages: [ [ 'url', 'title', 'text' ], ... ].
	 */
	public static function crawl( string $start_url, int $max_pages = 0, int $text_limit = self::DRAFT_TEXT_LIMIT ): array {
		$parsed = wp_parse_url( $start_url );

		if ( empty( $parsed['host'] ) ) {
			return array();
		}

		// 0 means unlimited; the ceiling still applies as a runaway guard.
		$max_pages = $max_pages > 0 ? $max_pages : self::HARD_PAGE_CEILING;

		$disallowed = self::get_disallowed_paths( $start_url );

		$pages = array();
		$seen  = array();

		// 1. Sitemap-driven discovery.
		$sitemap_urls = self::discover_urls( $start_url, 0, $disallowed );

		foreach ( $sitemap_urls as $candidate ) {
			if ( count( $pages ) >= $max_pages ) {
				break;
			}
			$page = self::fetch_page( $candidate, $text_limit );
			if ( $page ) {
				$pages[] = $page;
				$seen[ $page['url'] ] = true;
			}
		}

		// 2. Top up with HTML link crawling when the sitemap was missing or thin.
		if ( count( $pages ) < $max_pages ) {
			$queue = array( $start_url );

			while ( ! empty( $queue ) && count( $pages ) < $max_pages ) {
				$url = array_shift( $queue );

				if ( isset( $seen[ $url ] ) ) {
					continue;
				}
				$seen[ $url ] = true;

				$path = wp_parse_url( $url, PHP_URL_PATH ) ?: '/';
				if ( self::is_disallowed( $path, $disallowed ) || self::is_skippable( $url ) ) {
					continue;
				}

				$page = self::fetch_page( $url, $text_limit );
				if ( ! $page ) {
					continue;
				}

				$pages[] = $page;

				foreach ( self::extract_internal_links( $url, $page['html'] ) as $link ) {
					if ( ! isset( $seen[ $link ] ) && ! in_array( $link, $queue, true ) ) {
						$queue[] = $link;
					}
				}
			}
		}

		return $pages;
	}

	/**
	 * Discover crawlable URLs for a domain, sitemap-first.
	 *
	 * @param string $start_url  Any URL on the target domain.
	 * @param int    $max_urls   Maximum URLs to return. Use 0 for no limit.
	 * @param ?array $disallowed Optional pre-parsed robots.txt disallow rules.
	 * @return array Absolute, de-duplicated, priority-ordered URLs.
	 */
	public static function discover_urls( string $start_url, int $max_urls = 0, ?array $disallowed = null ): array {
		$parsed = wp_parse_url( $start_url );

		if ( empty( $parsed['host'] ) ) {
			return array();
		}

		if ( null === $disallowed ) {
			$disallowed = self::get_disallowed_paths( $start_url );
		}

		// A site can live in a subdirectory (https://host/shop/), so the sitemap
		// lives there too. Trying only scheme://host silently found nothing on
		// subdirectory installs and made the crawl look like a single-page site.
		$bases = self::sitemap_bases( $start_url );

		$collected = array();

		foreach ( $bases as $base ) {
			foreach ( self::read_sitemaps( $base, $max_urls, $disallowed ) as $url ) {
				$collected[] = $url;
			}

			if ( $max_urls > 0 && count( $collected ) >= $max_urls ) {
				break;
			}
		}

		// Guarantee the start URL is always considered first.
		$normalized_start = self::normalize_url( $start_url );
		if ( ! empty( $normalized_start ) ) {
			array_unshift( $collected, $normalized_start );
		}

		// De-duplicate on the URL strings themselves. Running array_unique()
		// over a [url => true] map collapses the list to a single true.
		$urls = array_values( array_unique( array_filter( $collected ) ) );

		usort( $urls, array( __CLASS__, 'compare_priority' ) );

		if ( $max_urls > 0 ) {
			return array_slice( $urls, 0, $max_urls );
		}

		if ( count( $urls ) > self::HARD_PAGE_CEILING ) {
			return array_slice( $urls, 0, self::HARD_PAGE_CEILING );
		}

		return $urls;
	}

	/**
	 * Build the ordered list of paths a sitemap may live under.
	 *
	 * @param string $start_url Any URL on the target site.
	 * @return array Absolute base URLs without a trailing slash.
	 */
	protected static function sitemap_bases( string $start_url ): array {
		$parsed = wp_parse_url( $start_url );

		if ( empty( $parsed['host'] ) ) {
			return array();
		}

		$scheme = $parsed['scheme'] ?? 'https';
		$host   = $scheme . '://' . $parsed['host'];
		$path   = rtrim( (string) ( $parsed['path'] ?? '' ), '/' );

		$bases = array();

		// Subdirectory install: /shop/wp-sitemap.xml.
		if ( '' !== $path && '/' !== $path ) {
			$bases[] = $host . $path;
		}

		// Host root, either as a real fallback or the primary location.
		$bases[] = $host;

		return array_values( array_unique( $bases ) );
	}

	/**
	 * Read and parse XML sitemaps for a domain.
	 *
	 * @param string $root_url   Domain root (scheme://host).
	 * @param int    $max_urls   Maximum URLs to collect.
	 * @param array  $disallowed robots.txt disallow rules.
	 * @return array URLs.
	 */
	protected static function read_sitemaps( string $root_url, int $max_urls, array $disallowed ): array {
		$candidates = array(
			$root_url . '/sitemap_index.xml',
			$root_url . '/sitemap.xml',
			$root_url . '/wp-sitemap.xml',
			$root_url . '/sitemap-index.xml',
		);

		$urls = array();

		foreach ( $candidates as $sitemap_url ) {
			$xml = self::fetch_raw( $sitemap_url, 10 );

			if ( empty( $xml ) ) {
				continue;
			}

			$parsed = self::parse_sitemap( $xml, $max_urls, 0 );

			foreach ( $parsed as $url ) {
				$url = self::normalize_url( $url );
				if ( ! $url ) {
					continue;
				}
				$path = wp_parse_url( $url, PHP_URL_PATH ) ?: '/';
				if ( self::is_disallowed( $path, $disallowed ) || self::is_skippable( $url ) ) {
					continue;
				}
				$urls[ $url ] = true;
			}

			if ( $max_urls > 0 && count( $urls ) >= $max_urls ) {
				break;
			}
		}

		return array_keys( $urls );
	}

	/**
	 * Parse a sitemap XML document, following sitemap indexes recursively.
	 *
	 * @param string $xml      Raw XML.
	 * @param int    $max_urls Maximum URLs to collect.
	 * @param int    $depth    Current recursion depth.
	 * @return array URLs.
	 */
	protected static function parse_sitemap( string $xml, int $max_urls, int $depth ): array {
		$urls = array();

		// Normalize CDATA-wrapped <loc> values.
		$normalized = str_replace( array( '<![CDATA[', ']]>' ), '', $xml );

		// Sitemap index: follow nested sitemaps.
		if ( preg_match_all( '#<sitemap>\s*<loc>(.*?)</loc>\s*</sitemap>#is', $normalized, $matches ) ) {
			if ( $depth >= self::MAX_NESTED_SITEMAPS ) {
				return array();
			}

			foreach ( $matches[1] as $nested_url ) {
				$nested_url = trim( html_entity_decode( $nested_url, ENT_QUOTES | ENT_XML1, 'UTF-8' ) );
				if ( empty( $nested_url ) ) {
					continue;
				}

				$nested_xml = self::fetch_raw( $nested_url, 10 );
				if ( empty( $nested_xml ) ) {
					continue;
				}

				$urls = array_merge( $urls, self::parse_sitemap( $nested_xml, $max_urls, $depth + 1 ) );

				if ( $max_urls > 0 && count( $urls ) >= $max_urls ) {
					break;
				}
			}

			return $urls;
		}

		// Standard urlset.
		if ( preg_match_all( '#<url>\s*<loc>(.*?)</loc>#is', $normalized, $matches ) ) {
			foreach ( $matches[1] as $url ) {
				$url = trim( html_entity_decode( $url, ENT_QUOTES | ENT_XML1, 'UTF-8' ) );
				if ( ! empty( $url ) ) {
					$urls[] = $url;
				}
			}
		}

		return $urls;
	}

	/**
	 * Fetch a single page and extract its title and readable text.
	 *
	 * @param string $url        Absolute URL.
	 * @param int    $text_limit Maximum characters of text to keep.
	 * @return array|null [ 'url', 'title', 'text', 'html' ] or null on failure.
	 */
	public static function fetch_page( string $url, int $text_limit = self::DRAFT_TEXT_LIMIT ): ?array {
		$html = self::fetch_raw( $url, 15 );

		if ( empty( $html ) ) {
			return null;
		}

		$title = self::extract_title( $html );
		$text  = Extractor::clean_html( $html );

		if ( '' === $text ) {
			return null;
		}

		return array(
			'url'   => $url,
			'title' => $title,
			'text'  => mb_substr( $text, 0, $text_limit, 'UTF-8' ),
			'html'  => $html,
		);
	}

	/**
	 * Perform an HTTP GET and return the raw body.
	 *
	 * @param string $url     URL to fetch.
	 * @param int    $timeout Timeout in seconds.
	 * @return string Raw body, or empty string on failure.
	 */
	public static function fetch_raw( string $url, int $timeout = 15 ): string {
		$response = wp_remote_get(
			$url,
			array(
				'timeout'     => $timeout,
				'redirection' => 5,
				'sslverify'   => true,
				'user-agent'  => 'Mozilla/5.0 (compatible; AIChatbotSiteCrawler/1.0; +' . home_url() . ')',
				'headers'     => array(
					'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return '';
		}

		if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return '';
		}

		return (string) wp_remote_retrieve_body( $response );
	}

	/**
	 * Extract the <title> of an HTML document.
	 *
	 * @param string $html Raw HTML.
	 * @return string Page title, or an empty string.
	 */
	protected static function extract_title( string $html ): string {
		if ( preg_match( '/<title[^>]*>(.*?)<\/title>/si', $html, $m ) ) {
			$title = trim( html_entity_decode( wp_strip_all_tags( $m[1] ), ENT_QUOTES, 'UTF-8' ) );
			if ( '' !== $title ) {
				return $title;
			}
		}

		// Fall back to og:title or the first <h1>.
		if ( preg_match( '/<meta[^>]+property=["\']og:title["\'][^>]+content=["\']([^"\']+)["\']/i', $html, $m ) ) {
			return trim( html_entity_decode( $m[1], ENT_QUOTES, 'UTF-8' ) );
		}

		if ( preg_match( '/<h1[^>]*>(.*?)<\/h1>/si', $html, $m ) ) {
			return trim( html_entity_decode( wp_strip_all_tags( $m[1] ), ENT_QUOTES, 'UTF-8' ) );
		}

		return '';
	}

	/**
	 * Extract same-host links from an HTML document.
	 *
	 * @param string $base_url URL the document was loaded from.
	 * @param string $html     Raw HTML.
	 * @return array Absolute URLs on the same host.
	 */
	protected static function extract_internal_links( string $base_url, string $html ): array {
		$base  = wp_parse_url( $base_url );
		$host  = $base['host'] ?? '';
		$roots = ( $base['scheme'] ?? 'https' ) . '://' . $host;

		$links = array();

		if ( ! preg_match_all( '/<a\s+[^>]*href\s*=\s*(["\'])(.*?)\1/i', $html, $matches, PREG_SET_ORDER ) ) {
			return $links;
		}

		foreach ( $matches as $match ) {
			$href = trim( html_entity_decode( $match[2], ENT_QUOTES, 'UTF-8' ) );

			if ( '' === $href
				|| str_starts_with( $href, '#' )
				|| str_starts_with( $href, 'mailto:' )
				|| str_starts_with( $href, 'tel:' )
				|| str_starts_with( $href, 'javascript:' )
				|| str_starts_with( $href, 'data:' ) ) {
				continue;
			}

			$absolute = self::resolve_url( $href, $roots );

			if ( ! $absolute || ( wp_parse_url( $absolute, PHP_URL_HOST ) ?: '' ) !== $host ) {
				continue;
			}

			$links[ $absolute ] = true;
		}

		return array_keys( $links );
	}

	/**
	 * Resolve a possibly relative href against a scheme://host root.
	 *
	 * @param string $href  Raw href value.
	 * @param string $roots "scheme://host".
	 * @return string Absolute URL, or empty string when unusable.
	 */
	protected static function resolve_url( string $href, string $roots ): string {
		if ( str_starts_with( $href, '//' ) ) {
			return self::normalize_url( 'https:' . $href );
		}

		if ( preg_match( '#^https?://#i', $href ) ) {
			return self::normalize_url( $href );
		}

		if ( str_starts_with( $href, '/' ) ) {
			return self::normalize_url( $roots . $href );
		}

		// Relative path: resolve against the root to stay predictable.
		return self::normalize_url( $roots . '/' . ltrim( $href, './' ) );
	}

	/**
	 * Normalize a URL: strip fragments and tracking parameters.
	 *
	 * @param string $url Raw URL.
	 * @return string Clean URL, or empty string when invalid.
	 */
	public static function normalize_url( string $url ): string {
		$url = trim( $url );

		if ( '' === $url ) {
			return '';
		}

		$parts = wp_parse_url( $url );

		if ( empty( $parts['host'] ) ) {
			return '';
		}

		$scheme = $parts['scheme'] ?? 'https';
		$path   = $parts['path'] ?? '/';

		if ( '' === $path ) {
			$path = '/';
		}

		$clean = $scheme . '://' . $parts['host'] . $path;

		if ( ! empty( $parts['query'] ) ) {
			// Drop known tracking parameters.
			parse_str( $parts['query'], $query );
			unset( $query['utm_source'], $query['utm_medium'], $query['utm_campaign'], $query['utm_term'], $query['utm_content'], $query['gclid'], $query['fbclid'] );

			if ( ! empty( $query ) ) {
				$clean .= '?' . http_build_query( $query );
			}
		}

		return $clean;
	}

	/**
	 * Priority comparator: business-critical paths first, then shallower paths.
	 *
	 * @param string $a URL A.
	 * @param string $b URL B.
	 * @return int Comparison result.
	 */
	public static function compare_priority( string $a, string $b ): int {
		$rank_a = self::priority_rank( $a );
		$rank_b = self::priority_rank( $b );

		if ( $rank_a !== $rank_b ) {
			return $rank_a <=> $rank_b;
		}

		$depth_a = substr_count( wp_parse_url( $a, PHP_URL_PATH ) ?: '/', '/' );
		$depth_b = substr_count( wp_parse_url( $b, PHP_URL_PATH ) ?: '/', '/' );

		if ( $depth_a !== $depth_b ) {
			return $depth_a <=> $depth_b;
		}

		return strcmp( $a, $b );
	}

	/**
	 * Score a URL for crawl priority (lower is more important).
	 *
	 * @param string $url URL to score.
	 * @return int Priority rank.
	 */
	protected static function priority_rank( string $url ): int {
		$path = strtolower( wp_parse_url( $url, PHP_URL_PATH ) ?: '/' );
		$host_path = strtolower( ( wp_parse_url( $url, PHP_URL_HOST ) ?: '' ) . $path );

		foreach ( self::PRIORITY_PATTERNS as $index => $pattern ) {
			if ( false !== strpos( $path, $pattern ) ) {
				return $index;
			}
		}

		// Deprioritize paginated and date archives.
		if ( preg_match( '#/(page|tag|category|author|\d{4})/#', $path ) ) {
			return count( self::PRIORITY_PATTERNS ) + 2;
		}

		// The bare domain root ranks just after the explicit business patterns.
		if ( '/' === $path ) {
			return count( self::PRIORITY_PATTERNS );
		}

		return count( self::PRIORITY_PATTERNS ) + 1;
	}

	/**
	 * Determine whether a URL should be skipped entirely.
	 *
	 * @param string $url URL to test.
	 * @return bool
	 */
	public static function is_skippable( string $url ): bool {
		$path = strtolower( wp_parse_url( $url, PHP_URL_PATH ) ?: '' );

		foreach ( self::SKIP_PATTERNS as $pattern ) {
			if ( false !== strpos( $path, $pattern ) ) {
				return true;
			}
		}

		// Skip URLs pointing at binary assets.
		if ( preg_match( '/\.(pdf|jpe?g|png|gif|svg|webp|ico|zip|mp4|mp3|woff2?|ttf|css|js)$/i', $path ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Parse robots.txt disallow rules for the target domain.
	 *
	 * @param string $start_url Any URL on the domain.
	 * @return array List of disallowed path prefixes.
	 */
	public static function get_disallowed_paths( string $start_url ): array {
		$parsed = wp_parse_url( $start_url );

		if ( empty( $parsed['host'] ) ) {
			return array();
		}

		$scheme = $parsed['scheme'] ?? 'https';
		$robots_url = $scheme . '://' . $parsed['host'] . '/robots.txt';

		$body = self::fetch_raw( $robots_url, 5 );

		// Always excluded, regardless of robots.txt.
		$base = array( '/wp-admin/', '/wp-login.php', '/wp-json/' );

		if ( empty( $body ) ) {
			return $base;
		}

		$rules = array();

		foreach ( preg_split( '/\r\n|\r|\n/', $body ) as $line ) {
			$line = trim( $line );

			if ( '' === $line || str_starts_with( $line, '#' ) ) {
				continue;
			}

			if ( preg_match( '/^disallow:\s*(.*)$/i', $line, $m ) ) {
				$path = trim( $m[1] );

				// "Disallow:" with an empty value allows everything.
				if ( '' !== $path ) {
					$rules[] = $path;
				}
			}
		}

		return array_merge( $rules, $base );
	}

	/**
	 * Check whether a URL path is disallowed by robots.txt rules.
	 *
	 * @param string $path       URL path.
	 * @param array  $disallowed Disallow rules.
	 * @return bool
	 */
	protected static function is_disallowed( string $path, array $disallowed ): bool {
		if ( empty( $disallowed ) ) {
			return false;
		}

		foreach ( $disallowed as $rule ) {
			if ( '' === $rule ) {
				continue;
			}

			// Support wildcard rules such as /*.pdf$
			if ( str_contains( $rule, '*' ) ) {
				$regex = '#^' . str_replace( '\*', '.*', preg_quote( $rule, '#' ) ) . '$#i';
				$regex = str_replace( '\$', '\$', $regex );

				if ( preg_match( $regex, $path ) ) {
					return true;
				}
				continue;
			}

			if ( str_starts_with( $path, $rule ) ) {
				return true;
			}
		}

		return false;
	}
}