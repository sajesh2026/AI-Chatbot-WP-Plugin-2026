<?php
/**
 * Sitemap synchronisation.
 *
 * Keeps the knowledge base aligned with the live website: imports pages that
 * appeared in the sitemap, refreshes pages whose content changed, and removes
 * pages that are no longer listed so stale answers cannot be served.
 *
 * @package AIChatbot\Knowledge
 */

namespace AIChatbot\Knowledge;

use AIChatbot\AI\ModelRegistry;
use AIChatbot\Security\Encryption;
use AIChatbot\Settings;
use AIChatbot\Wizard\SiteCrawler;

defined( 'ABSPATH' ) || exit;

/**
 * Class SitemapSync.
 */
class SitemapSync {

	/**
	 * How long a freshly indexed page is considered current, in seconds.
	 * Only pages older than this are re-crawled during a refresh.
	 */
	public const REFRESH_AFTER = 7 * DAY_IN_SECONDS;

	/**
	 * Synchronise an agent's sitemap knowledge base with the live website.
	 *
	 * @param int    $agent_id     Agent ID.
	 * @param string $sitemap_url  Domain or sitemap URL to crawl. Defaults to the site's home URL.
	 * @param bool   $force        Re-crawl every page even if it was indexed recently.
	 * @param int    $max_pages    Safety ceiling for a single run.
	 * @return array {
	 *     @type array $discovered URLs found in the sitemap.
	 *     @type int   $added      Sources created.
	 *     @type int   $updated    Sources re-indexed.
	 *     @type int   $removed    Sources deleted because they left the sitemap.
	 *     @type int   $unchanged  Sources left alone.
	 *     @type int   $failed     Sources that could not be indexed.
	 *     @type array $messages   Human-readable warnings.
	 * }
	 */
	public static function sync( int $agent_id = 1, string $sitemap_url = '', bool $force = false, int $max_pages = 200 ): array {
		global $wpdb;

		$table = $wpdb->prefix . 'ai_knowledge_sources';

		$result = array(
			'discovered' => 0,
			'added'      => 0,
			'updated'    => 0,
			'removed'    => 0,
			'unchanged'  => 0,
			'failed'     => 0,
			'messages'   => array(),
		);

		if ( '' === trim( $sitemap_url ) ) {
			$sitemap_url = home_url( '/' );
		}

		$sitemap_url = SiteCrawler::normalize_url( $sitemap_url );

		if ( empty( $sitemap_url ) ) {
			$result['messages'][] = __( 'Could not resolve the website URL to sync.', 'ai-chatbot' );
			return $result;
		}

		// 1. Discover the current sitemap.
		$urls = SiteCrawler::discover_urls( $sitemap_url, 0 );
		$urls = array_slice( $urls, 0, max( 1, $max_pages ) );
		$result['discovered'] = count( $urls );

		if ( empty( $urls ) ) {
			$result['messages'][] = __( 'No sitemap URLs could be discovered. Check that sitemap.xml is publicly reachable.', 'ai-chatbot' );
			return $result;
		}

		/*
		 * Guard against destructive syncs.
		 *
		 * When a sitemap cannot be read, discovery degrades to just the start
		 * URL. Treating that as the complete list would delete every knowledge
		 * source except one. Removal therefore only runs when the discovery
		 * clearly came from a real sitemap.
		 */
		$discovery_trusted = count( $urls ) > 1;

		if ( ! $discovery_trusted ) {
			$result['messages'][] = __( 'Only the start URL could be discovered, so no sitemap was read. Imports will run, but nothing will be removed.', 'ai-chatbot' );
		}

		// 2. Resolve embeddings credentials once.
		$credentials = self::resolve_credentials( $agent_id );

		if ( ! $credentials ) {
			$result['messages'][] = __( 'No embeddings credentials are configured, so pages can be stored but not indexed. Add an embeddings key under Settings → Providers first.', 'ai-chatbot' );
		}

		$keep = array();
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - self::REFRESH_AFTER );

		foreach ( $urls as $url ) {
			$existing = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT id, status, chunk_count, last_indexed_at FROM {$table} WHERE agent_id = %d AND source_url = %s LIMIT 1",
					$agent_id,
					$url
				),
				ARRAY_A
			);

			if ( $existing ) {
				$keep[] = (int) $existing['id'];

				$stale = $force || empty( $existing['last_indexed_at'] ) || (string) $existing['last_indexed_at'] < $cutoff;

				if ( ! $stale ) {
					$result['unchanged']++;
					continue;
				}

				self::reindex( (int) $existing['id'], $credentials, (string) $url, $force );
				$result['updated']++;
				continue;
			}

			// New page.
			$page = SiteCrawler::fetch_page( $url, SiteCrawler::INDEX_TEXT_LIMIT );

			if ( ! $page ) {
				$result['failed']++;
				continue;
			}

			$source_id = KnowledgeManager::add_source(
				array(
					'agent_id'   => $agent_id,
					'type'       => 'webpage',
					'title'      => $page['title'] ? $page['title'] : $url,
					'source_url' => $url,
					'content'    => $page['text'],
					'metadata'   => array(
						'imported_from' => 'sitemap_sync',
						'page_url'      => $url,
					),
				)
			);

			if ( ! $source_id ) {
				$result['failed']++;
				continue;
			}

			$keep[] = $source_id;
			$result['added']++;

			if ( $credentials ) {
				self::reindex( $source_id, $credentials, $url, true );
			}
		}

		// 3. Drop sitemap-managed pages that are no longer listed.
		$managed = array_map( 'intval', (array) $wpdb->get_col(
			$wpdb->prepare(
				"SELECT id FROM {$table}
				 WHERE agent_id = %d
				   AND type = 'webpage'
				   AND source_url IS NOT NULL
				   AND ( metadata LIKE %s OR metadata LIKE %s )",
				$agent_id,
				'%sitemap_sync%',
				'%imported_from%'
			)
		) );

		$obsolete = array_values( array_diff( $managed, $keep ) );

		if ( ! $discovery_trusted ) {
			// Never remove anything on the strength of an unreadable sitemap.
			if ( ! empty( $obsolete ) ) {
				$result['messages'][] = sprintf(
					/* translators: %d: number of pages that would have been removed. */
					__( '%d existing page(s) were kept because the sitemap could not be read.', 'ai-chatbot' ),
					count( $obsolete )
				);
			}
		} elseif ( ! empty( $obsolete ) ) {
			$in    = implode( ',', array_fill( 0, count( $obsolete ), '%d' ) );
			$store = new \AIChatbot\VectorStore\CustomTableStore();

			foreach ( $obsolete as $source_id ) {
				$store->delete_by_source( $source_id );
			}

			$deleted = $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE id IN ({$in})", $obsolete ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

			$result['removed'] = (int) $deleted;
		}

		// 4. Drop vector chunks whose source has gone.
		KnowledgeManager::purge_orphan_chunks();

		return $result;
	}

	/**
	 * Re-crawl a page and re-index it.
	 *
	 * @param int         $source_id   Source ID.
	 * @param ?array      $credentials Resolved embeddings credentials.
	 * @param string      $url         Page URL.
	 * @param bool        $refresh     Force a fresh crawl.
	 * @return bool
	 */
	protected static function reindex( int $source_id, ?array $credentials, string $url, bool $refresh = true ): bool {
		if ( $refresh ) {
			$page = SiteCrawler::fetch_page( $url, SiteCrawler::INDEX_TEXT_LIMIT );

			if ( $page ) {
				global $wpdb;
				$table = $wpdb->prefix . 'ai_knowledge_sources';

				$wpdb->update(
					$table,
					array(
						'title'   => $page['title'] ? $page['title'] : $url,
						'content' => $page['text'],
						'status'  => 'pending',
					),
					array( 'id' => $source_id ),
					array( '%s', '%s', '%s' ),
					array( '%d' )
				);
			}
		}

		if ( ! $credentials ) {
			return false;
		}

		$res = Indexer::index_source(
			$source_id,
			$credentials['provider'],
			$credentials['model'],
			$credentials['key']
		);

		return ! empty( $res['success'] );
	}

	/**
	 * Resolve the embeddings provider, model, and decrypted key for an agent.
	 *
	 * @param int $agent_id Agent ID.
	 * @return array|null [ 'provider', 'model', 'key' ] or null when unusable.
	 */
	protected static function resolve_credentials( int $agent_id ): ?array {
		$settings = Settings::get_all( $agent_id, false );

		$provider_id = (string) ( $settings['providers']['embeddings_provider'] ?? 'openai' );

		$key_fields = array(
			'openai'  => 'openai_api_key',
			'mistral' => 'mistral_api_key',
		);

		$key_field = $key_fields[ $provider_id ] ?? 'openai_api_key';
		$model     = (string) ( $settings['providers']['embeddings_model'] ?? 'text-embedding-3-small' );
		$raw_key   = Encryption::decrypt( (string) ( $settings['providers'][ $key_field ] ?? '' ) );

		$provider = ModelRegistry::get( $provider_id );

		if ( ! $provider || ! $provider->supports_embeddings() || '' === trim( $raw_key ) ) {
			return null;
		}

		return array(
			'provider' => $provider,
			'model'    => $model,
			'key'      => $raw_key,
		);
	}
}