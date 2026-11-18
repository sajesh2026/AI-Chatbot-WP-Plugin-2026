<?php
/**
 * Knowledge Indexer pipeline.
 *
 * @package AIChatbot\Knowledge
 */

namespace AIChatbot\Knowledge;

use AIChatbot\AI\AIProviderInterface;
use AIChatbot\VectorStore\VectorStoreInterface;
use AIChatbot\VectorStore\CustomTableStore;
use AIChatbot\Language\LanguageService;

defined( 'ABSPATH' ) || exit;

/**
 * Class Indexer.
 */
class Indexer {

	/**
	 * Index a single knowledge source.
	 *
	 * @param int                  $source_id Knowledge source ID.
	 * @param AIProviderInterface  $embeddings_provider Embeddings provider (e.g. OpenAI).
	 * @param string               $embedding_model Embeddings model ID.
	 * @param string               $api_key Plaintext API key.
	 * @param ?VectorStoreInterface $vector_store Vector store instance.
	 * @return array{success: bool, chunks: int, error?: string}
	 */
	public static function index_source(
		int $source_id,
		AIProviderInterface $embeddings_provider,
		string $embedding_model,
		string $api_key,
		?VectorStoreInterface $vector_store = null
	): array {
		global $wpdb;
		$table_sources = $wpdb->prefix . 'ai_knowledge_sources';

		if ( ! $vector_store ) {
			$vector_store = new CustomTableStore();
		}

		$source = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table_sources} WHERE id = %d", $source_id ),
			ARRAY_A
		);

		if ( ! $source ) {
			return array(
				'success' => false,
				'chunks'  => 0,
				'error'   => __( 'Source not found.', 'ai-chatbot' ),
			);
		}

		// Mark status as processing.
		$wpdb->update(
			$table_sources,
			array( 'status' => 'processing', 'error_message' => null ),
			array( 'id' => $source_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		$type     = $source['type'];
		$agent_id = (int) $source['agent_id'];
		$metadata = ! empty( $source['metadata'] ) ? json_decode( $source['metadata'], true ) : array();
		if ( ! is_array( $metadata ) ) {
			$metadata = array();
		}

		$metadata['source_type'] = $type;
		$metadata['source_id']   = $source_id;

		$chunks = array();

		// 1. Extract content and chunk based on source type.
		if ( 'webpage' === $type ) {
			$url = $source['source_url'] ?? '';

			if ( empty( $url ) ) {
				return self::fail_source( $source_id, __( 'Missing webpage URL.', 'ai-chatbot' ) );
			}

			// Reuse text already extracted during the crawl/scan step to avoid a
			// second HTTP request (and to survive pages that rate-limit crawlers).
			$text = isset( $source['content'] ) ? trim( (string) $source['content'] ) : '';

			if ( '' === $text ) {
				$extract = Extractor::extract_url( $url );

				if ( ! $extract['success'] ) {
					return self::fail_source( $source_id, $extract['error'] ?? __( 'Failed to fetch webpage.', 'ai-chatbot' ) );
				}

				$text = $extract['text'];
			}

			if ( '' === trim( $text ) ) {
				return self::fail_source( $source_id, __( 'The page contained no readable text.', 'ai-chatbot' ) );
			}

			$metadata['page_url']   = $url;
			$metadata['page_title'] = (string) ( $source['title'] ?? '' );
			$chunks                 = Chunker::chunk_text( $text, $metadata );

		} elseif ( 'file' === $type ) {
			$file_path = $metadata['file_path'] ?? '';
			$extension = $metadata['extension'] ?? pathinfo( $file_path, PATHINFO_EXTENSION );
			$extract   = Extractor::extract_file( $file_path, $extension );
			if ( ! $extract['success'] ) {
				return self::fail_source( $source_id, $extract['error'] ?? __( 'Failed to extract file text.', 'ai-chatbot' ) );
			}
			$metadata['file_name'] = $source['title'];
			$chunks = Chunker::chunk_text( $extract['text'], $metadata );

		} elseif ( 'qa' === $type ) {
			$qa_data  = ! empty( $source['content'] ) ? json_decode( $source['content'], true ) : array();
			$question = $qa_data['question'] ?? $source['title'];
			$answer   = $qa_data['answer'] ?? '';
			if ( empty( $answer ) ) {
				return self::fail_source( $source_id, __( 'Q&A answer cannot be empty.', 'ai-chatbot' ) );
			}
			$chunks = Chunker::chunk_qa( $question, $answer, $metadata );

		} elseif ( 'text_block' === $type ) {
			$text = $source['content'] ?? '';
			if ( empty( trim( $text ) ) ) {
				return self::fail_source( $source_id, __( 'Text block content is empty.', 'ai-chatbot' ) );
			}
			$chunks = Chunker::chunk_text( $text, $metadata );

		} elseif ( 'business_info' === $type ) {
			$info = ! empty( $source['content'] ) ? json_decode( $source['content'], true ) : array();
			$formatted = array();
			foreach ( $info as $k => $v ) {
				if ( ! empty( $v ) && is_string( $v ) ) {
					$formatted[] = ucwords( str_replace( '_', ' ', $k ) ) . ': ' . trim( $v );
				}
			}
			$text   = implode( "\n", $formatted );
			$chunks = Chunker::chunk_text( $text, $metadata );
		}

		if ( empty( $chunks ) ) {
			return self::fail_source( $source_id, __( 'No extractable text found in source.', 'ai-chatbot' ) );
		}

		// 2. Clear old chunks for this source.
		$vector_store->delete_by_source( $source_id );

		// 3. Batch generate embeddings (batched to stay within API input limits).
		$embeddings = self::generate_embeddings_batched(
			$chunks,
			$embeddings_provider,
			$embedding_model,
			$api_key
		);

		if ( count( $embeddings ) !== count( $chunks ) ) {
			// Prefer the provider's own explanation (e.g. "this key is a Groq key")
			// over the bare counts, which on their own are not actionable.
			$provider_error = method_exists( $embeddings_provider, 'get_last_error' )
				? trim( (string) $embeddings_provider->get_last_error() )
				: '';

			if ( '' !== $provider_error ) {
				return self::fail_source( $source_id, $provider_error );
			}

			return self::fail_source(
				$source_id,
				sprintf(
					/* translators: 1: number of chunks processed, 2: number of embeddings returned. */
					__( 'Embedding generation incomplete: %1$d of %2$d chunks were embedded.', 'ai-chatbot' ),
					count( $embeddings ),
					count( $chunks )
				)
			);
		}

		// 4. Store chunks and embeddings in vector store.
		$stored_count = 0;

		/*
		 * Detect the source language once from a sample of the
		 * chunked text so every chunk carries it. This lets
		 * retrieval rank same-language content first without
		 * translating the knowledge base.
		 */
		$combined_text   = implode( ' ', array_column( $chunks, 'chunk_text' ) );
		$source_language = LanguageService::detect( mb_substr( $combined_text, 0, 2000 ) )['language'];

		foreach ( $chunks as $i => $chunk ) {
			$vector = $embeddings[ $i ];
			$meta   = array_merge(
				$chunk['metadata'],
				array(
					'token_count' => $chunk['token_count'],
					'language'    => $source_language,
				)
			);
			$id     = $vector_store->store_chunk( $source_id, $agent_id, $chunk['chunk_text'], $vector, $meta );
			if ( $id > 0 ) {
				$stored_count++;
			}
		}

		// 5. Update source to indexed status.
		$wpdb->update(
			$table_sources,
			array(
				'status'          => 'indexed',
				'chunk_count'     => $stored_count,
				'error_message'   => null,
				'last_indexed_at' => current_time( 'mysql' ),
			),
			array( 'id' => $source_id ),
			array( '%s', '%d', '%s', '%s' ),
			array( '%d' )
		);

		return array(
			'success' => true,
			'chunks'  => $stored_count,
		);
	}

	/**
	 * Generate embeddings for all chunks in API-safe batches.
	 *
	 * Embeddings providers cap how many inputs a single request accepts and
	 * reject oversized payloads, so a long page used to fail the whole source.
	 * Batching keeps indexing reliable while preserving chunk order.
	 *
	 * @param array                  $chunks      Prepared chunks.
	 * @param AIProviderInterface    $provider    Embeddings provider.
	 * @param string                 $model       Embeddings model ID.
	 * @param string                 $api_key     Plaintext API key.
	 * @param int                    $batch_size  Inputs per request.
	 * @return array Embedding vectors aligned with $chunks order.
	 */
	protected static function generate_embeddings_batched(
		array $chunks,
		AIProviderInterface $provider,
		string $model,
		string $api_key,
		int $batch_size = 64
	): array {
		if ( empty( $chunks ) ) {
			return array();
		}

		$batch_size = max( 1, $batch_size );
		$embeddings = array();

		foreach ( array_chunk( $chunks, $batch_size ) as $batch ) {
			$texts = array_map( fn( $c ) => (string) $c['chunk_text'], $batch );

			$vectors = $provider->generate_embeddings( $texts, $model, $api_key );

			if ( ! is_array( $vectors ) ) {
				$vectors = array();
			}

			$collected = array();

			foreach ( $vectors as $vector ) {
				if ( is_array( $vector ) && ! empty( $vector ) ) {
					$collected[] = $vector;
				}
			}

			$embeddings = array_merge( $embeddings, $collected );

			// Stop early when a batch comes back incomplete: the provider is
			// rejecting the request and further batches would fail the same way.
			if ( count( $collected ) < count( $batch ) ) {
				break;
			}
		}

		return $embeddings;
	}

	/**
	 * Mark source as failed with descriptive error.
	 *
	 * @param int    $source_id Source ID.
	 * @param string $error Error message.
	 * @return array
	 */
	protected static function fail_source( int $source_id, string $error ): array {
		global $wpdb;
		$table_sources = $wpdb->prefix . 'ai_knowledge_sources';

		$wpdb->update(
			$table_sources,
			array(
				'status'        => 'failed',
				'error_message' => $error,
			),
			array( 'id' => $source_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		return array(
			'success' => false,
			'chunks'  => 0,
			'error'   => $error,
		);
	}

	/**
	 * Re-index all active knowledge sources for an agent.
	 * Triggered when changing embeddings provider or model.
	 *
	 * @param int                 $agent_id Agent ID.
	 * @param AIProviderInterface $embeddings_provider Embeddings provider.
	 * @param string              $embedding_model Embeddings model.
	 * @param string              $api_key Plaintext key.
	 * @return array{indexed: int, failed: int}
	 */
	public static function reindex_all( int $agent_id, AIProviderInterface $embeddings_provider, string $embedding_model, string $api_key ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'ai_knowledge_sources';

		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE agent_id = %d AND status != 'disabled'",
				$agent_id
			)
		);

		$indexed = 0;
		$failed  = 0;

		foreach ( $ids as $id ) {
			$res = self::index_source( (int) $id, $embeddings_provider, $embedding_model, $api_key );
			if ( $res['success'] ) {
				$indexed++;
			} else {
				$failed++;
			}
		}

		return array(
			'indexed' => $indexed,
			'failed'  => $failed,
		);
	}
}
