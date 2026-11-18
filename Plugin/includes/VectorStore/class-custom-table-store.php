<?php
/**
 * Custom database table vector store implementation with pure PHP cosine similarity.
 *
 * @package AIChatbot\VectorStore
 */

namespace AIChatbot\VectorStore;

defined( 'ABSPATH' ) || exit;

/**
 * Class CustomTableStore.
 */
class CustomTableStore implements VectorStoreInterface {

	/**
	 * Table name without prefix.
	 */
	public const TABLE_NAME = 'ai_knowledge_chunks';

	/**
	 * Maximum candidate chunks scanned in PHP memory per query.
	 */
	public const MAX_CANDIDATES = 2500;

	/**
	 * Store a knowledge chunk with its embedding vector.
	 *
	 * @param int    $source_id Source ID.
	 * @param int    $agent_id Agent ID.
	 * @param string $chunk_text Chunk text.
	 * @param array  $embedding Embedding vector.
	 * @param array  $metadata Metadata.
	 * @return int Inserted ID.
	 */
	public function store_chunk( int $source_id, int $agent_id, string $chunk_text, array $embedding, array $metadata = array() ): int {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE_NAME;

		$source_type = $metadata['source_type'] ?? 'text_block';
		$category    = $metadata['category'] ?? null;
		$language    = $metadata['language'] ?? 'en';
		$token_count = isset( $metadata['token_count'] ) ? (int) $metadata['token_count'] : (int) ( strlen( $chunk_text ) / 4 );
		$chunk_index = isset( $metadata['chunk_index'] ) ? (int) $metadata['chunk_index'] : 0;

		$inserted = $wpdb->insert(
			$table,
			array(
				'source_id'   => $source_id,
				'agent_id'    => $agent_id,
				'chunk_index' => $chunk_index,
				'chunk_text'  => $chunk_text,
				'token_count' => $token_count,
				'embedding'   => wp_json_encode( $embedding ),
				'source_type' => $source_type,
				'category'    => $category,
				'language'    => $language,
				'metadata'    => wp_json_encode( $metadata ),
				'is_enabled'  => 1,
				'created_at'  => current_time( 'mysql' ),
			),
			array( '%d', '%d', '%d', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s' )
		);

		return $inserted ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * Delete all chunks for a source.
	 *
	 * @param int $source_id Source ID.
	 * @return bool
	 */
	public function delete_by_source( int $source_id ): bool {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE_NAME;
		$res   = $wpdb->delete( $table, array( 'source_id' => $source_id ), array( '%d' ) );
		return false !== $res;
	}

	/**
	 * Delete all chunks for an agent.
	 *
	 * @param int $agent_id Agent ID.
	 * @return bool
	 */
	public function delete_by_agent( int $agent_id ): bool {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE_NAME;
		$res   = $wpdb->delete( $table, array( 'agent_id' => $agent_id ), array( '%d' ) );
		return false !== $res;
	}

	/**
	 * Calculate cosine similarity between two numerical vectors.
	 *
	 * @param array $vec_a Vector A.
	 * @param array $vec_b Vector B.
	 * @return float Score between -1.0 and 1.0.
	 */
	public function calculate_cosine_similarity( array $vec_a, array $vec_b ): float {
		$len = count( $vec_a );
		if ( $len !== count( $vec_b ) || 0 === $len ) {
			return 0.0;
		}

		$dot_product = 0.0;
		$mag_a       = 0.0;
		$mag_b       = 0.0;

		for ( $i = 0; $i < $len; $i++ ) {
			$a            = (float) $vec_a[ $i ];
			$b            = (float) $vec_b[ $i ];
			$dot_product += $a * $b;
			$mag_a       += $a * $a;
			$mag_b       += $b * $b;
		}

		if ( $mag_a <= 0.0 || $mag_b <= 0.0 ) {
			return 0.0;
		}

		return (float) ( $dot_product / ( sqrt( $mag_a ) * sqrt( $mag_b ) ) );
	}

	/**
	 * Query chunks by vector similarity.
	 *
	 * @param array $query_embedding Query vector.
	 * @param int   $agent_id Agent ID.
	 * @param int   $limit Max results.
	 * @param array $filters Optional metadata filters.
	 * @return array
	 */
	public function query_similar( array $query_embedding, int $agent_id, int $limit = 5, array $filters = array() ): array {
		global $wpdb;
		$table         = $wpdb->prefix . self::TABLE_NAME;
		$sources_table = $wpdb->prefix . 'ai_knowledge_sources';

		if ( empty( $query_embedding ) ) {
			return array();
		}

		// Qualified with "c." because the join makes these column names ambiguous.
		// Fix 2: Ensure it ONLY retrieves German ('de') or English ('en') content—never French ('fr') or other duplicates.
		$where  = array(
			'c.agent_id = %d',
			'c.is_enabled = 1',
			"(c.language IN ('de', 'en') OR c.language IS NULL OR c.language = '')",
		);
		$params = array( $agent_id );

		if ( ! empty( $filters['source_type'] ) ) {
			$where[]  = 'c.source_type = %s';
			$params[] = $filters['source_type'];
		}
		if ( ! empty( $filters['category'] ) ) {
			$where[]  = 'c.category = %s';
			$params[] = $filters['category'];
		}

$where_clause = implode( ' AND ', $where );
		$max_cands    = self::MAX_CANDIDATES;

		// Join the sources table so chunks whose source was deleted can never be
		// returned as knowledge. Without this, stale vectors left behind by a
		// deleted page were still retrievable and could be answered from.
	$sql     = "SELECT c.id, c.source_id, c.source_type, c.chunk_text, c.token_count, c.embedding, c.metadata, c.language
		FROM {$table} c
			INNER JOIN {$sources_table} s ON s.id = c.source_id
			WHERE {$where_clause}
				AND c.agent_id = s.agent_id
			ORDER BY c.id DESC
			LIMIT {$max_cands}";
		$results = $wpdb->get_results( $wpdb->prepare( $sql, ...$params ), ARRAY_A );

		if ( empty( $results ) ) {
			return array();
		}

		$scored = array();
		foreach ( $results as $row ) {
			$embedding = json_decode( $row['embedding'], true );
			if ( ! is_array( $embedding ) ) {
				continue;
			}

			$sim = $this->calculate_cosine_similarity( $query_embedding, $embedding );

			// Section 4 requirement: Curated Q&A entries have highest retrieval priority.
			$boost = 1.0;
			if ( 'qa' === $row['source_type'] ) {
				$boost = 1.25;
			}

			$final_score = min( 1.0, $sim * $boost );

			$row['raw_similarity'] = $sim;
			$row['score']          = round( $final_score, 4 );
			$row['metadata']       = ! empty( $row['metadata'] ) ? json_decode( $row['metadata'], true ) : array();
			unset( $row['embedding'] ); // Free memory.

			$scored[] = $row;
		}

		// Sort by score descending.
		usort(
			$scored,
			fn( $x, $y ) => $y['score'] <=> $x['score']
		);

		return array_slice( $scored, 0, $limit );
	}

	/**
	 * Fallback keyword search for chunks when vector search is unavailable or returns 0 results.
	 *
	 * @param string $query User query text or keywords.
	 * @param int    $agent_id Agent ID.
	 * @param int    $limit Max chunks to return.
	 * @param array  $filters Optional metadata filters.
	 * @param array  $synonyms Optional additional search keywords / translations.
	 * @return array
	 */
	public function query_by_keywords( string $query, int $agent_id, int $limit = 5, array $filters = array(), array $synonyms = array() ): array {
		global $wpdb;
		$table         = $wpdb->prefix . self::TABLE_NAME;
		$sources_table = $wpdb->prefix . 'ai_knowledge_sources';

		// Clean and tokenize query into keywords of at least 3 chars.
		$clean = preg_replace( '/[^\p{L}\p{N}\s]/u', ' ', mb_strtolower( $query ) );
		$words = array_filter( preg_split( '/\s+/u', (string) $clean ), fn( $w ) => mb_strlen( $w ) >= 3 );

		// Merge with any synonyms/translations passed.
		foreach ( $synonyms as $syn ) {
			$s_clean = preg_replace( '/[^\p{L}\p{N}\s]/u', ' ', mb_strtolower( (string) $syn ) );
			$s_words = array_filter( preg_split( '/\s+/u', (string) $s_clean ), fn( $w ) => mb_strlen( $w ) >= 3 );
			$words   = array_merge( $words, $s_words );
		}

		$words = array_values( array_unique( $words ) );

		// Filter common stop words in EN and DE.
		$stop_words = array(
			'what', 'when', 'where', 'which', 'who', 'whom', 'this', 'that', 'these', 'those', 'have', 'from',
			'with', 'about', 'your', 'offer', 'does', 'doing', 'will', 'would', 'could', 'should', 'tell', 'explain',
			'dies', 'diese', 'dieser', 'haben', 'sein', 'wird', 'werden', 'oder', 'aber', 'nicht', 'kann', 'können',
			'bitte', 'gibt', 'mich', 'dich', 'sich', 'euch', 'ihnen', 'ihre', 'ihrem', 'ihren', 'unser', 'unsere',
		);
		$meaningful = array_values( array_diff( $words, $stop_words ) );

		if ( empty( $meaningful ) ) {
			$meaningful = $words;
		}

		if ( empty( $meaningful ) ) {
			return array();
		}

		// Fix 2: Ensure keyword search ONLY retrieves German ('de') or English ('en') content.
		$where  = array(
			'c.agent_id = %d',
			'c.is_enabled = 1',
			"(c.language IN ('de', 'en') OR c.language IS NULL OR c.language = '')",
		);
		$params = array( $agent_id );

		if ( ! empty( $filters['source_type'] ) ) {
			$where[]  = 'c.source_type = %s';
			$params[] = $filters['source_type'];
		}
		if ( ! empty( $filters['category'] ) ) {
			$where[]  = 'c.category = %s';
			$params[] = $filters['category'];
		}

		$kw_conditions = array();
		foreach ( $meaningful as $word ) {
			$kw_conditions[] = 'c.chunk_text LIKE %s';
			$params[]        = '%' . $wpdb->esc_like( $word ) . '%';
		}

		$where[]      = '(' . implode( ' OR ', $kw_conditions ) . ')';
		$where_clause = implode( ' AND ', $where );

		$sql = "SELECT c.id, c.source_id, c.source_type, c.chunk_text, c.token_count, c.metadata, c.language
			FROM {$table} c
			INNER JOIN {$sources_table} s ON s.id = c.source_id
			WHERE {$where_clause}
				AND c.agent_id = s.agent_id
			ORDER BY c.id DESC
			LIMIT 30";

		$results = $wpdb->get_results( $wpdb->prepare( $sql, ...$params ), ARRAY_A );
		if ( empty( $results ) ) {
			return array();
		}

		// Score results based on term occurrences.
		$scored = array();
		foreach ( $results as $row ) {
			$text_lower = mb_strtolower( (string) $row['chunk_text'] );
			$hits       = 0;
			foreach ( $meaningful as $word ) {
				if ( str_contains( $text_lower, $word ) ) {
					$hits++;
				}
			}

			// Normalized score between 0.60 and 0.85
			$ratio = count( $meaningful ) > 0 ? ( $hits / count( $meaningful ) ) : 0.5;
			$score = round( 0.60 + ( 0.25 * min( 1.0, $ratio ) ), 4 );

			$row['raw_similarity'] = $score;
			$row['score']          = $score;
			$row['metadata']       = ! empty( $row['metadata'] ) ? json_decode( $row['metadata'], true ) : array();
			$scored[]              = $row;
		}

		usort( $scored, fn( $x, $y ) => $y['score'] <=> $x['score'] );

		return array_slice( $scored, 0, $limit );
	}
}
