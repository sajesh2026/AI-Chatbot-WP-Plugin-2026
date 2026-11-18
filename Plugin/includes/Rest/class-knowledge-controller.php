<?php
/**
 * Knowledge Management REST Controller.
 *
 * @package AIChatbot\Rest
 */

namespace AIChatbot\Rest;

use WP_REST_Request;
use WP_REST_Response;
use AIChatbot\Settings;
use AIChatbot\Security\Encryption;
use AIChatbot\AI\ModelRegistry;
use AIChatbot\Knowledge\KnowledgeManager;
use AIChatbot\Knowledge\Indexer;
use AIChatbot\Knowledge\SitemapSync;
use AIChatbot\Wizard\SiteCrawler;

defined( 'ABSPATH' ) || exit;

/**
 * Class KnowledgeController.
 */
class KnowledgeController extends RestController {

	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/knowledge',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_sources' ),
					'permission_callback' => array( $this, 'check_admin_permission' ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'create_source' ),
					'permission_callback' => array( $this, 'check_admin_permission' ),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/knowledge/bulk-delete',
			array(
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'bulk_delete_sources' ),
					'permission_callback' => array( $this, 'check_admin_permission' ),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/knowledge/(?P<id>\d+)',
			array(
				array(
					'methods'             => 'PUT',
					'callback'            => array( $this, 'update_source' ),
					'permission_callback' => array( $this, 'check_admin_permission' ),
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( $this, 'delete_source' ),
					'permission_callback' => array( $this, 'check_admin_permission' ),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/knowledge/(?P<id>\d+)/index',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'index_source' ),
				'permission_callback' => array( $this, 'check_admin_permission' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/knowledge/reindex-all',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'reindex_all' ),
				'permission_callback' => array( $this, 'check_admin_permission' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/knowledge/sync-sitemap',
			array(
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'sync_sitemap' ),
					'permission_callback' => array( $this, 'check_admin_permission' ),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/knowledge/scan',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'scan_website' ),
				'permission_callback' => array( $this, 'check_admin_permission' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/knowledge/import-website',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'import_website' ),
				'permission_callback' => array( $this, 'check_admin_permission' ),
			)
		);
	}

	/**
	 * Get sources list.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_sources( WP_REST_Request $request ): WP_REST_Response {
		$agent_id = (int) ( $request->get_param( 'agent_id' ) ?: 1 );
		$type     = $request->get_param( 'type' ) ? sanitize_text_field( $request->get_param( 'type' ) ) : null;

		$sources = KnowledgeManager::get_sources( $agent_id, $type );

		return $this->success( array( 'sources' => $sources ) );
	}

	/**
	 * Create a new knowledge source.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function create_source( WP_REST_Request $request ): WP_REST_Response {
		$params   = $request->get_json_params() ?: array();
		$agent_id = (int) ( $params['agent_id'] ?? 1 );

		$type = sanitize_text_field( $params['type'] ?? 'text_block' );
		if ( ! in_array( $type, array( 'webpage', 'file', 'qa', 'text_block', 'business_info' ), true ) ) {
			return $this->error( __( 'Invalid source type.', 'ai-chatbot' ), 'invalid_type', 400 );
		}

		$id = KnowledgeManager::add_source( $params );
		if ( ! $id ) {
			return $this->error( __( 'Failed to create knowledge source.', 'ai-chatbot' ), 'create_failed', 500 );
		}

		// Trigger immediate indexing if requested.
		$auto_index = $params['auto_index'] ?? true;
		if ( $auto_index ) {
			$this->run_indexing_for_source( $id, $agent_id );
		}

		return $this->success(
			array(
				'message'   => __( 'Knowledge source created successfully.', 'ai-chatbot' ),
				'source_id' => $id,
			),
			201
		);
	}

	/**
	 * Update source.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function update_source( WP_REST_Request $request ): WP_REST_Response {
		$id     = (int) $request->get_param( 'id' );
		$params = $request->get_json_params() ?: array();

		$res = KnowledgeManager::update_source( $id, $params );
		if ( ! $res ) {
			return $this->error( __( 'Failed to update source.', 'ai-chatbot' ), 'update_failed', 400 );
		}

		return $this->success( array( 'message' => __( 'Source updated successfully.', 'ai-chatbot' ) ) );
	}

	/**
	 * Delete a source.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function delete_source( WP_REST_Request $request ): WP_REST_Response {
		$id  = (int) $request->get_param( 'id' );
		$res = KnowledgeManager::delete_source( $id );

		if ( ! $res ) {
			return $this->error( __( 'Failed to delete source.', 'ai-chatbot' ), 'delete_failed', 400 );
		}

		return $this->success( array( 'message' => __( 'Source and associated vector chunks deleted successfully.', 'ai-chatbot' ) ) );
	}

	/**
	 * Delete multiple sources, or every source for an agent.
	 *
	 * Accepts either an explicit list of IDs or `all: true` to clear the agent.
	 * A confirmation string is required for the destructive "all" mode so an
	 * accidental or replayed request cannot wipe the knowledge base.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function bulk_delete_sources( WP_REST_Request $request ): WP_REST_Response {
		$params   = $request->get_json_params() ?: array();
		$agent_id = (int) ( $params['agent_id'] ?? 1 );

		$delete_all  = ! empty( $params['all'] );
		$source_ids  = isset( $params['ids'] ) && is_array( $params['ids'] ) ? $params['ids'] : array();
		$confirm     = (string) ( $params['confirm'] ?? '' );

		if ( ! $delete_all && empty( $source_ids ) ) {
			return $this->error( __( 'Provide a list of source IDs, or set "all" to true.', 'ai-chatbot' ), 'invalid_payload', 400 );
		}

		if ( $delete_all ) {
			// Guard against wiping everything on a stray or replayed request.
			if ( 'DELETE ALL' !== strtoupper( trim( $confirm ) ) ) {
				return $this->error(
					__( 'Deleting every knowledge source requires confirm = "DELETE ALL".', 'ai-chatbot' ),
					'confirmation_required',
					400
				);
			}

			$source_ids = KnowledgeManager::get_source_ids( $agent_id );
		}

		if ( empty( $source_ids ) ) {
			return $this->success(
				array(
					'deleted' => 0,
					'skipped' => 0,
					'message' => __( 'There were no knowledge sources to delete.', 'ai-chatbot' ),
				)
			);
		}

		$result = KnowledgeManager::delete_sources( $source_ids, $agent_id );

		$message = $delete_all
			? sprintf(
				/* translators: %d: number of deleted sources. */
				__( 'Deleted all %d knowledge source(s) and their vector chunks.', 'ai-chatbot' ),
				$result['deleted']
			)
			: sprintf(
				/* translators: %d: number of deleted sources. */
				_n(
					'Deleted %d knowledge source and its vector chunks.',
					'Deleted %d knowledge sources and their vector chunks.',
					$result['deleted'],
					'ai-chatbot'
				),
				$result['deleted']
			);

		if ( ! $delete_all && $result['skipped'] > 0 ) {
			$message .= ' ' . sprintf(
				/* translators: %d: number of skipped IDs. */
				__( '%d requested ID(s) did not belong to this agent and were ignored.', 'ai-chatbot' ),
				$result['skipped']
			);
		}

		return $this->success(
			array(
				'deleted' => $result['deleted'],
				'skipped' => $result['skipped'],
				'message' => $message,
			)
		);
	}

	/**
	 * Manually trigger indexing for a source.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function index_source( WP_REST_Request $request ): WP_REST_Response {
		$id       = (int) $request->get_param( 'id' );
		$agent_id = (int) ( $request->get_param( 'agent_id' ) ?: 1 );

		$res = $this->run_indexing_for_source( $id, $agent_id );
		if ( ! $res['success'] ) {
			return $this->error( $res['error'] ?? __( 'Indexing failed.', 'ai-chatbot' ), 'indexing_failed', 400 );
		}

		return $this->success(
			array(
				'message' => sprintf( __( 'Successfully indexed %d chunks.', 'ai-chatbot' ), $res['chunks'] ),
				'chunks'  => $res['chunks'],
			)
		);
	}

	/**
	 * Re-index all sources.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function reindex_all( WP_REST_Request $request ): WP_REST_Response {
		$agent_id = (int) ( $request->get_param( 'agent_id' ) ?: 1 );

		$credentials = $this->resolve_embeddings_credentials( $agent_id );

		if ( ! $credentials ) {
			return $this->error( __( 'Cannot reindex: Embeddings provider or API key is not configured.', 'ai-chatbot' ), 'missing_credentials', 400 );
		}

		$stats = Indexer::reindex_all(
			$agent_id,
			$credentials['provider'],
			$credentials['model'],
			$credentials['key']
		);

		return $this->success(
			array(
				'message' => sprintf( __( 'Re-indexing complete. %d indexed, %d failed.', 'ai-chatbot' ), $stats['indexed'], $stats['failed'] ),
				'stats'   => $stats,
			)
		);
	}

	/**
	 * Refresh the knowledge base from the live sitemap.
	 *
	 * Adds newly published pages, re-indexes changed ones, and drops pages that
	 * are no longer listed so stale answers stop being served.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function sync_sitemap( WP_REST_Request $request ): WP_REST_Response {
		$params   = $request->get_json_params() ?: array();
		$agent_id = (int) ( $params['agent_id'] ?? 1 );
		$force    = ! empty( $params['force'] );
		$max      = min( 500, max( 1, (int) ( $params['max_pages'] ?? 200 ) ) );

		$settings    = Settings::get_all( $agent_id, false );
		$sitemap_url = (string) ( $params['url'] ?? ( $settings['knowledge']['sitemap_url'] ?? '' ) );

		if ( '' === trim( $sitemap_url ) ) {
			$sitemap_url = home_url( '/' );
		}

		$result = SitemapSync::sync( $agent_id, $sitemap_url, $force, $max );

		if ( 0 === $result['discovered'] ) {
			return $this->error(
				implode( ' ', $result['messages'] ) ?: __( 'No sitemap URLs could be discovered.', 'ai-chatbot' ),
				'sitemap_empty',
				400
			);
		}

		// Remember the URL so the scheduled sync uses the same target.
		Settings::save_all(
			$agent_id,
			array(
				'knowledge' => array( 'sitemap_url' => $sitemap_url ),
			)
		);

		$message = sprintf(
			/* translators: 1: URLs discovered, 2: pages added, 3: pages updated, 4: pages removed. */
			__( 'Sitemap sync complete: %1$d URLs found, %2$d added, %3$d updated, %4$d removed.', 'ai-chatbot' ),
			$result['discovered'],
			$result['added'],
			$result['updated'],
			$result['removed']
		);

		if ( ! empty( $result['failed'] ) ) {
			$message .= ' ' . sprintf(
				/* translators: %d: number of pages that failed. */
				__( '%d page(s) could not be read.', 'ai-chatbot' ),
				$result['failed']
			);
		}

		return $this->success(
			array(
				'message'   => $message,
				'data'      => $result,
				'sitemap_url' => $sitemap_url,
			)
		);
	}

	/**
	 * Scan website for internal pages.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function scan_website( WP_REST_Request $request ): WP_REST_Response {
		$params = $request->get_json_params() ?: array();
		$url    = esc_url_raw( $params['url'] ?? home_url() );

		if ( empty( $url ) ) {
			return $this->error( __( 'A valid website URL is required.', 'ai-chatbot' ), 'invalid_url', 400 );
		}

		// 0 or an absent value means "no page limit".
		$max     = max( 0, (int) ( $params['max_pages'] ?? 0 ) );
		$pages   = KnowledgeManager::scan_website( $url, $max );
		$sitemap = $this->sitemap_locations( $url );

		return $this->success(
			array(
				'pages'    => $pages,
				'count'    => count( $pages ),
				'sitemaps' => $sitemap,
			)
		);
	}

	/**
	 * Report which sitemap files answered for a domain.
	 *
	 * @param string $url Domain URL.
	 * @return array List of sitemap paths that returned a valid sitemap.
	 */
	protected function sitemap_locations( string $url ): array {
		$parsed = wp_parse_url( $url );

		if ( empty( $parsed['host'] ) ) {
			return array();
		}

		$root  = ( $parsed['scheme'] ?? 'https' ) . '://' . $parsed['host'];
		$found = array();

		foreach ( array( '/sitemap_index.xml', '/sitemap.xml', '/wp-sitemap.xml' ) as $path ) {
			if ( '' !== SiteCrawler::fetch_raw( $root . $path, 8 ) ) {
				$found[] = $root . $path;
			}
		}

		return $found;
	}

	/**
	 * Crawl a website, extract its content, and index it into the knowledge base.
	 *
	 * This is the end-to-end path used when an administrator supplies a website
	 * URL: sitemap discovery, polite crawling of each page, text extraction, and
	 * vector indexing all happen in one call.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function import_website( WP_REST_Request $request ): WP_REST_Response {
		$params   = $request->get_json_params() ?: array();
		$url      = esc_url_raw( $params['url'] ?? '' );
		$agent_id = (int) ( $params['agent_id'] ?? 1 );

		// 0 or an absent value means "no page limit".
		$max = max( 0, (int) ( $params['max_pages'] ?? 0 ) );

		if ( empty( $url ) || ! wp_http_validate_url( $url ) ) {
			return $this->error( __( 'Please provide a valid http(s) website URL.', 'ai-chatbot' ), 'invalid_url', 400 );
		}

		$parsed = wp_parse_url( $url );
		if ( empty( $parsed['host'] ) ) {
			return $this->error( __( 'The website URL is missing a host.', 'ai-chatbot' ), 'invalid_url', 400 );
		}

		// Resolve embeddings credentials up front so we fail fast with a clear message.
		$credentials = $this->resolve_embeddings_credentials( $agent_id );

		if ( ! $credentials ) {
			return $this->error( $this->missing_embeddings_message( $agent_id ), 'missing_credentials', 400 );
		}

		// Crawl + extract.
		$pages = SiteCrawler::crawl( $url, $max, SiteCrawler::INDEX_TEXT_LIMIT );

		if ( empty( $pages ) ) {
			return $this->error(
				__( 'No readable pages could be crawled from that URL. Check the address, and confirm the pages are publicly reachable.', 'ai-chatbot' ),
				'crawl_failed',
				400
			);
		}

		$imported = array();
		$skipped  = array();
		$failed   = array();
		$chunks   = 0;

		foreach ( $pages as $page ) {
			$page_url = SiteCrawler::normalize_url( $page['url'] );

			if ( null !== KnowledgeManager::find_source_by_url( $agent_id, $page_url ) ) {
				$skipped[] = $page_url;
				continue;
			}

			$source_id = KnowledgeManager::add_source(
				array(
					'agent_id'    => $agent_id,
					'type'        => 'webpage',
					'title'       => $page['title'] ? $page['title'] : $page_url,
					'source_url'  => $page_url,
					// Persist the extracted text so indexing never needs a refetch.
					'content'     => $page['text'],
					'metadata'    => array(
						'imported_from' => 'website_import',
						'page_url'      => $page_url,
					),
				)
			);

			if ( ! $source_id ) {
				$failed[] = array( 'url' => $page_url, 'error' => __( 'Could not store the page.', 'ai-chatbot' ) );
				continue;
			}

			$result = Indexer::index_source(
				$source_id,
				$credentials['provider'],
				$credentials['model'],
				$credentials['key']
			);

			if ( ! empty( $result['success'] ) ) {
				$chunks += (int) $result['chunks'];
				$imported[] = array(
					'id'    => $source_id,
					'url'   => $page_url,
					'title' => $page['title'],
					'chunks' => (int) $result['chunks'],
				);
			} else {
				$failed[] = array( 'url' => $page_url, 'error' => $result['error'] ?? __( 'Indexing failed.', 'ai-chatbot' ) );
			}
		}

		$message = sprintf(
			/* translators: 1: number of pages imported, 2: number of indexed chunks, 3: number of failed pages. */
			__( 'Website import finished: %1$d pages indexed (%2$d chunks), %3$d failed.', 'ai-chatbot' ),
			count( $imported ),
			$chunks,
			count( $failed )
		);

		return $this->success(
			array(
				'message'   => $message,
				'imported'  => $imported,
				'skipped'   => $skipped,
				'failed'    => $failed,
				'chunks'    => $chunks,
				'crawled'   => count( $pages ),
			)
		);
	}

	/**
	 * Explain precisely which embeddings credential is missing.
	 *
	 * The message has to name the right provider: chat and embeddings are
	 * separate, so telling someone to add an OpenAI key when they selected
	 * Mistral sends them to the wrong field.
	 *
	 * @param int $agent_id Agent ID.
	 * @return string
	 */
	protected function missing_embeddings_message( int $agent_id ): string {
		$settings     = Settings::get_all( $agent_id, false );
		$provider_id  = (string) ( $settings['providers']['embeddings_provider'] ?? 'openai' );
		$provider     = ModelRegistry::get( $provider_id );
		$label        = $provider ? $provider->get_name() : ucfirst( $provider_id );

		if ( ! $provider || ! $provider->supports_embeddings() ) {
			return sprintf(
				/* translators: %s: provider display name. */
				__( 'The selected embeddings provider (%s) cannot create embeddings. Groq has no embedding model, so choose OpenAI or Mistral under Settings → Providers.', 'ai-chatbot' ),
				$label
			);
		}

		$key_fields = array(
			'openai'  => 'openai_api_key',
			'mistral' => 'mistral_api_key',
		);

		$key_field = $key_fields[ $provider_id ] ?? $key_fields['openai'];

		return sprintf(
			/* translators: 1: provider display name, 2: settings field name. */
			__( 'Embeddings are not configured. Add your %1$s API key in the "%2$s" field under Settings → Providers before importing a website, otherwise the pages cannot be indexed.', 'ai-chatbot' ),
			$label,
			$key_field
		);
	}

	/**
	 * Resolve the embeddings provider, model, and decrypted API key for an agent.
	 *
	 * @param int $agent_id Agent ID.
	 * @return array|null [ 'provider', 'model', 'key' ] or null when unusable.
	 */
	protected function resolve_embeddings_credentials( int $agent_id ): ?array {
		$settings = Settings::get_all( $agent_id, false );

		$provider_id = $settings['providers']['embeddings_provider'] ?? 'openai';

		// Each embeddings provider keeps its credential in its own settings field.
		$key_fields = array(
			'openai' => 'openai_api_key',
			'mistral' => 'mistral_api_key',
		);

		$key_field = $key_fields[ $provider_id ] ?? $key_fields['openai'];
		$model     = $settings['providers']['embeddings_model'] ?? 'text-embedding-3-small';
		$raw_key   = Encryption::decrypt( (string) ( $settings['providers'][ $key_field ] ?? '' ) );

		$provider = ModelRegistry::get( $provider_id );

		if ( ! $provider || ! $provider->supports_embeddings() ) {
			return null;
		}

		if ( empty( $raw_key ) ) {
			return null;
		}

		return array(
			'provider' => $provider,
			'model'    => $model,
			'key'      => $raw_key,
		);
	}

	/**
	 * Helper to execute source indexing with stored credentials.
	 *
	 * @param int $source_id Source ID.
	 * @param int $agent_id Agent ID.
	 * @return array
	 */
	protected function run_indexing_for_source( int $source_id, int $agent_id ): array {
		$credentials = $this->resolve_embeddings_credentials( $agent_id );

		if ( ! $credentials ) {
			return array(
				'success' => false,
				'error'   => __( 'Embeddings provider credentials not configured. Please add an OpenAI key in Settings.', 'ai-chatbot' ),
			);
		}

		return Indexer::index_source(
			$source_id,
			$credentials['provider'],
			$credentials['model'],
			$credentials['key']
		);
	}
}
