<?php
/**
 * Vector Store Interface contract.
 *
 * @package AIChatbot\VectorStore
 */

namespace AIChatbot\VectorStore;

defined( 'ABSPATH' ) || exit;

/**
 * Interface VectorStoreInterface.
 */
interface VectorStoreInterface {

	/**
	 * Store a knowledge chunk with its embedding vector and metadata.
	 *
	 * @param int    $source_id Parent knowledge source ID.
	 * @param int    $agent_id Agent ID.
	 * @param string $chunk_text Chunk text content.
	 * @param array  $embedding Numerical vector embedding.
	 * @param array  $metadata Additional metadata (source_type, category, url, etc.).
	 * @return int Chunk record ID.
	 */
	public function store_chunk( int $source_id, int $agent_id, string $chunk_text, array $embedding, array $metadata = array() ): int;

	/**
	 * Delete all chunks associated with a specific knowledge source.
	 *
	 * @param int $source_id Source ID.
	 * @return bool
	 */
	public function delete_by_source( int $source_id ): bool;

	/**
	 * Delete all chunks belonging to an agent.
	 *
	 * @param int $agent_id Agent ID.
	 * @return bool
	 */
	public function delete_by_agent( int $agent_id ): bool;

	/**
	 * Query chunks by vector similarity against a query embedding.
	 *
	 * @param array $query_embedding Query vector.
	 * @param int   $agent_id Agent ID.
	 * @param int   $limit Max chunks to return.
	 * @param array $filters Optional metadata filters.
	 * @return array List of chunk records with 'score' (0.0 to 1.0) and 'chunk_text'.
	 */
	public function query_similar( array $query_embedding, int $agent_id, int $limit = 5, array $filters = array() ): array;

	/**
	 * Calculate cosine similarity between two numerical vectors.
	 *
	 * @param array $vec_a Vector A.
	 * @param array $vec_b Vector B.
	 * @return float Cosine similarity score between -1.0 and 1.0.
	 */
	public function calculate_cosine_similarity( array $vec_a, array $vec_b ): float;
}
