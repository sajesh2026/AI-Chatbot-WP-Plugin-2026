<?php
/**
 * Knowledge Sources Manager and Website Scanner.
 *
 * @package AIChatbot\Knowledge
 */

namespace AIChatbot\Knowledge;

use AIChatbot\VectorStore\CustomTableStore;
use AIChatbot\Wizard\SiteCrawler;

defined( 'ABSPATH' ) || exit;

/**
 * Class KnowledgeManager.
 */
class KnowledgeManager {

	/**
	 * Get knowledge sources for an agent.
	 *
	 * @param int     $agent_id Agent ID.
	 * @param ?string $type Optional filter by source type.
	 * @return array
	 */
	public static function get_sources( int $agent_id = 1, ?string $type = null ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'ai_knowledge_sources';

		if ( $type ) {
			return $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$table} WHERE agent_id = %d AND type = %s ORDER BY id DESC",
					$agent_id,
					$type
				),
				ARRAY_A
			) ?: array();
		}

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE agent_id = %d ORDER BY id DESC",
				$agent_id
			),
			ARRAY_A
		) ?: array();
	}

	/**
	 * Add a new knowledge source.
	 *
	 * @param array $data Source data.
	 * @return int Inserted ID.
	 */
	public static function add_source( array $data ): int {
		global $wpdb;
		$table = $wpdb->prefix . 'ai_knowledge_sources';

		$type = sanitize_text_field( $data['type'] ?? 'text_block' );

		// Normalize URLs so duplicate detection and re-imports stay reliable.
		$source_url = null;
		if ( ! empty( $data['source_url'] ) ) {
			$normalized = SiteCrawler::normalize_url( (string) $data['source_url'] );
			$source_url = $normalized ? $normalized : esc_url_raw( (string) $data['source_url'] );
		}

		$inserted = $wpdb->insert(
			$table,
			array(
				'agent_id'      => (int) ( $data['agent_id'] ?? 1 ),
				'type'          => $type,
				'title'         => sanitize_text_field( $data['title'] ?? 'Untitled Source' ),
				'source_url'    => $source_url,
				'content'       => $data['content'] ?? null,
				'status'        => $data['status'] ?? 'pending',
				'metadata'      => ! empty( $data['metadata'] ) ? wp_json_encode( $data['metadata'] ) : null,
				'created_at'    => current_time( 'mysql' ),
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		if ( $inserted ) {
			$new_id = (int) $wpdb->insert_id;
			do_action( 'ai_chatbot_knowledge_sources_changed', (int) ( $data['agent_id'] ?? 1 ) );
			return $new_id;
		}

		return 0;
	}

	/**
	 * Update an existing source.
	 *
	 * @param int   $id Source ID.
	 * @param array $data Fields.
	 * @return bool
	 */
	public static function update_source( int $id, array $data ): bool {
		global $wpdb;
		$table = $wpdb->prefix . 'ai_knowledge_sources';

		$update = array();
		$format = array();

		if ( isset( $data['title'] ) ) {
			$update['title'] = sanitize_text_field( $data['title'] );
			$format[]        = '%s';
		}
		if ( isset( $data['content'] ) ) {
			$update['content'] = $data['content'];
			$format[]          = '%s';
		}
		if ( isset( $data['status'] ) ) {
			$update['status'] = sanitize_text_field( $data['status'] );
			$format[]         = '%s';
		}
		if ( isset( $data['metadata'] ) ) {
			$update['metadata'] = wp_json_encode( $data['metadata'] );
			$format[]           = '%s';
		}

		if ( empty( $update ) ) {
			return false;
		}

		$updated = $wpdb->update( $table, $update, array( 'id' => $id ), $format, array( '%d' ) );
		if ( false !== $updated ) {
			do_action( 'ai_chatbot_knowledge_sources_changed', 1 );
			return true;
		}

		return false;
	}

	/**
	 * Delete a source and all associated vector chunks.
	 *
	 * @param int $id Source ID.
	 * @return bool
	 */
	public static function delete_source( int $id ): bool {
		global $wpdb;
		$table = $wpdb->prefix . 'ai_knowledge_sources';

		// 1. Delete vector chunks.
		$store = new CustomTableStore();
		$store->delete_by_source( $id );

		// 2. Delete source record.
		$res = $wpdb->delete( $table, array( 'id' => $id ), array( '%d' ) );
		if ( false !== $res ) {
			do_action( 'ai_chatbot_knowledge_sources_changed', 1 );
			return true;
		}

		return false;
	}

	/**
	 * Delete several knowledge sources in one pass, including their vectors.
	 *
	 * Chunk rows are removed in bulk rather than one query per source, so a
	 * "delete all" on a large knowledge base stays fast.
	 *
	 * @param array  $source_ids Source IDs to delete.
	 * @param string $scope      Optional agent ID; when set, only these IDs are
	 *                           deleted and the set is intersected with the agent.
	 * @return array {
	 *     @type int $deleted Number of sources actually removed.
	 *     @type int $skipped Number of IDs that were not owned by this agent.
	 * }
	 */
	public static function delete_sources( array $source_ids, int $agent_id = 0 ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'ai_knowledge_sources';

		$ids = array_values( array_unique( array_filter( array_map( 'intval', $source_ids ) ) ) );

		$result = array(
			'deleted' => 0,
			'skipped' => 0,
		);

		if ( empty( $ids ) ) {
			return $result;
		}

		// Restrict to the agent's own sources so one agent cannot delete another's.
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$params       = $ids;

		$sql = "SELECT id FROM {$table} WHERE id IN ({$placeholders})";

		if ( $agent_id > 0 ) {
			$sql     .= ' AND agent_id = %d';
			$params[] = $agent_id;
		}

		$existing = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( $sql, $params ) ) );

		$owned             = array_values( array_intersect( $ids, $existing ) );
		$result['skipped'] = count( $ids ) - count( $owned );

		if ( empty( $owned ) ) {
			return $result;
		}

		$in       = implode( ',', array_fill( 0, count( $owned ), '%d' ) );
		$chunks   = $wpdb->prefix . 'ai_knowledge_chunks';
		$removed  = $wpdb->query( $wpdb->prepare( "DELETE FROM {$chunks} WHERE source_id IN ({$in})", $owned ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( false === $removed ) {
			return $result;
		}

		$deleted = $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE id IN ({$in})", $owned ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$result['deleted'] = (int) $deleted;

		if ( $result['deleted'] > 0 ) {
			do_action( 'ai_chatbot_knowledge_sources_changed', $agent_id ?: 1 );
		}

		return $result;
	}

	/**
	 * Delete vector chunks whose source no longer exists.
	 *
	 * Retrieval already joins against the sources table, so orphans cannot be
	 * answered from; this just stops the table growing indefinitely.
	 *
	 * @return int Number of orphan chunks removed.
	 */
	public static function purge_orphan_chunks(): int {
		global $wpdb;
		$chunks  = $wpdb->prefix . 'ai_knowledge_chunks';
		$sources = $wpdb->prefix . 'ai_knowledge_sources';

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$removed = $wpdb->query(
			"DELETE c FROM {$chunks} c
			 LEFT JOIN {$sources} s ON s.id = c.source_id
			 WHERE s.id IS NULL"
		);

		return (int) $removed;
	}

	/**
	 * List the IDs of all sources belonging to an agent.
	 *
	 * @param int $agent_id Agent ID. Use 0 to list across all agents.
	 * @return array List of integer source IDs.
	 */
	public static function get_source_ids( int $agent_id = 1 ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'ai_knowledge_sources';

		if ( $agent_id > 0 ) {
			$ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$table} WHERE agent_id = %d", $agent_id ) );
		} else {
			$ids = $wpdb->get_col( "SELECT id FROM {$table}" );
		}

		return array_map( 'intval', (array) $ids );
	}

	/**
	 * Scan a website and return candidate pages for indexing.
	 *
	 * Uses XML sitemaps first for complete coverage, then falls back to
	 * HTML link discovery on the same host.
	 *
	 * @param string $root_url   Target root URL.
	 * @param int    $max_links  Maximum pages to return. Use 0 for no limit.
	 * @return array List of [ 'url', 'title' ] entries.
	 */
	public static function scan_website( string $root_url, int $max_links = 0 ): array {
		$parsed_root = wp_parse_url( $root_url );
		$root_host   = $parsed_root['host'] ?? '';

		if ( empty( $root_host ) ) {
			return array();
		}

		// 0 means no limit.
		$max_links = max( 0, $max_links );

		$disallowed = SiteCrawler::get_disallowed_paths( $root_url );
		$urls       = SiteCrawler::discover_urls( $root_url, $max_links, $disallowed );

		$results = array();

		foreach ( $urls as $url ) {
			if ( $max_links > 0 && count( $results ) >= $max_links ) {
				break;
			}

			$parsed = wp_parse_url( $url );

			if ( ( $parsed['host'] ?? '' ) !== $root_host ) {
				continue;
			}

			$path = wp_parse_url( $url, PHP_URL_PATH ) ?: '/';

			$results[] = array(
				'url'      => $url,
				'title'    => '/' === $path ? __( 'Home', 'ai-chatbot' ) : trim( $path, '/' ),
				'path'     => $path,
				// True when this URL is already stored for any agent, so the UI
				// can show "Already indexed" instead of offering a duplicate add.
				'imported' => null !== self::find_source_by_url( 0, $url ),
			);
		}

		return $results;
	}

	/**
	 * Find an existing knowledge source by its URL across agents.
	 *
	 * @param int    $agent_id Agent ID (0 to search all agents).
	 * @param string $url      Source URL.
	 * @return ?array Source row, or null when not found.
	 */
	public static function find_source_by_url( int $agent_id, string $url ): ?array {
		global $wpdb;
		$table = $wpdb->prefix . 'ai_knowledge_sources';

		$normalized = SiteCrawler::normalize_url( $url );

		if ( empty( $normalized ) ) {
			return null;
		}

		if ( $agent_id > 0 ) {
			$row = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT * FROM {$table} WHERE agent_id = %d AND source_url = %s LIMIT 1",
					$agent_id,
					$normalized
				),
				ARRAY_A
			);
		} else {
			$row = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT * FROM {$table} WHERE source_url = %s LIMIT 1",
					$normalized
				),
				ARRAY_A
			);
		}

		return $row ?: null;
	}
}
