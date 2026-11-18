<?php
/**
 * Semantic Knowledge Retriever.
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
 * Class Retriever.
 */
class Retriever {

	/**
	 * Chunks pulled from the store for each query variant before balancing.
	 */
	public const CANDIDATES_PER_VARIANT = 12;

	/**
	 * Maximum chunks a single source page may contribute to the prompt.
	 *
	 * Prevents one long page from monopolising the context window and hiding
	 * the page that actually contains the answer.
	 */
	public const MAX_CHUNKS_PER_SOURCE = 2;

	/**
	 * Extra similarity required above the calibrated no-match floor.
	 *
	 * Deliberately zero. Measurements on a German-language site showed that
	 * raising this margin refuses correct answers while still admitting
	 * unrelated ones: English questions about German content scored 0.72-0.75
	 * and were rejected, while "Wie backe ich einen Apfelkuchen?" scored 0.7506
	 * and passed. The numeric floor only needs to separate "something was
	 * retrieved" from "nothing at all"; deciding whether the content actually
	 * answers the question is the model's job, and it already refuses with a
	 * "not on this website" reply when the context does not support an answer.
	 */
	public const FLOOR_MARGIN = 0.0;

	/**
	 * Ranking boost for chunks written in the visitor's language.
	 *
	 * Deliberately modest: it reorders same-language content ahead of
	 * cross-language content when both are relevant, but never hides a
	 * relevant cross-language chunk, because the model still decides
	 * whether the context actually answers the question. The knowledge
	 * base is never translated to achieve this.
	 */
	public const LANGUAGE_BOOST = 1.15;

	/**
	 * Retrieve relevant knowledge chunks for a visitor question.
	 *
	 * @param string               $query User question.
	 * @param int                  $agent_id Agent ID.
	 * @param AIProviderInterface  $embeddings_provider Embeddings provider.
	 * @param string               $embedding_model Embeddings model.
	 * @param string               $api_key API key.
	 * @param int                  $limit Maximum chunks to return.
	 * @param array                $filters Metadata filters.
	 * @param ?VectorStoreInterface $vector_store Optional custom vector store.
	 * @param string               $context Previous visitor turn, folded into the query.
	 * @param string               $language Visitor's language, used to rank
	 *                                     same-language chunks first.
	 * @return array{chunks: array, top_score: float}
	 */
	public static function retrieve(
		string $query,
		int $agent_id,
		AIProviderInterface $embeddings_provider,
		string $embedding_model,
		string $api_key,
		int $limit = 4,
		array $filters = array(),
		?VectorStoreInterface $vector_store = null,
		string $context = '',
		string $language = ''
	): array {
		if ( ! $vector_store ) {
			$vector_store = new CustomTableStore();
		}

		$query = trim( $query );
		if ( empty( $query ) ) {
			return array( 'chunks' => array(), 'top_score' => 0.0 );
		}

		/*
		 * Query expansion.
		 *
		 * Two common shapes of question were retrieving poorly:
		 *  - follow-ups such as "and how long does that take?", where the
		 *    sentence alone contains nothing searchable, and
		 *  - rephrasings that share few words with the page wording
		 *    ("what does a consultation cost?" vs. "Preise Beratung").
		 *
		 * Embedding the message on its own and again together with the
		 * preceding turn covers both. Chunk IDs are de-duplicated and the
		 * best score per chunk wins, so ordering stays meaningful.
		 */
		$queries = array( $query );

		if ( '' !== trim( $context ) ) {
			$queries[] = trim( $context . ' ' . $query );
		}

		// Cross-lingual query expansion: if visitor asked in non-German (e.g. English),
		// add German energy domain query variants so embeddings and keyword search match German knowledge chunks.
		$cross_lingual = self::get_cross_lingual_variants( $query, $language );
		foreach ( $cross_lingual as $cl_variant ) {
			if ( ! in_array( $cl_variant, $queries, true ) ) {
				$queries[] = $cl_variant;
			}
		}

		if ( count( $queries ) > 4 ) {
			$queries = array_slice( $queries, 0, 4 );
		}

		$merged = array();
		$best   = array();

		foreach ( $queries as $variant ) {
			$emb_cache_key = 'ai_emb_q_' . md5( $embedding_model . '_' . $variant );
			$vectors       = get_transient( $emb_cache_key );
			if ( ! is_array( $vectors ) ) {
				$vectors = $embeddings_provider->generate_embeddings( $variant, $embedding_model, $api_key );
				if ( ! empty( $vectors ) && isset( $vectors[0] ) ) {
					set_transient( $emb_cache_key, $vectors, 6 * HOUR_IN_SECONDS );
				}
			}

			if ( empty( $vectors ) || ! isset( $vectors[0] ) ) {
				continue;
			}

			$results = $vector_store->query_similar( $vectors[0], $agent_id, self::CANDIDATES_PER_VARIANT, $filters );

			foreach ( $results as $row ) {
				$id = (int) ( $row['id'] ?? 0 );

				if ( $id > 0 && ! isset( $best[ $id ] ) ) {
					$best[ $id ] = true;
					$merged[]    = $row;
				}
			}
		}

		// Fix 2: Filter out any non-German/non-English chunks (never return French or other languages).
		$filtered_merged = array();
		foreach ( $merged as $row ) {
			$chunk_lang = self::chunk_language( $row );
			if ( '' === $chunk_lang || in_array( $chunk_lang, array( 'de', 'en' ), true ) ) {
				$filtered_merged[] = $row;
			}
		}
		$merged = $filtered_merged;

		if ( empty( $merged ) ) {
			// Fallback to keyword search if vector search produced no candidates.
			if ( method_exists( $vector_store, 'query_by_keywords' ) ) {
				$kw_chunks = $vector_store->query_by_keywords( $query, $agent_id, $limit, $filters, $cross_lingual );
				if ( ! empty( $kw_chunks ) ) {
					return array(
						'chunks'    => $kw_chunks,
						'top_score' => (float) $kw_chunks[0]['score'],
					);
				}
			}

			// Guaranteed fallback: return balanced core Knowledge Base sources so context is never empty.
			$core_chunks = self::get_core_knowledge_chunks( $agent_id, $limit );
			if ( ! empty( $core_chunks ) ) {
				return array(
					'chunks'    => $core_chunks,
					'top_score' => 0.75,
				);
			}

			return array( 'chunks' => array(), 'top_score' => 0.0 );
		}

		/*
		 * Language-aware ranking.
		 *
		 * Chunks written in the visitor's language are boosted so
		 * same-language content ranks first when it exists. The boost
		 * is deliberately modest: when no same-language chunk is
		 * relevant, cross-language content still surfaces, and the
		 * model decides whether it actually answers the question. The
		 * knowledge base is never translated to achieve this.
		 */
		$query_language = LanguageService::sanitize( $language );

		if ( '' !== $query_language ) {
			foreach ( $merged as &$row ) {
				$chunk_language = self::chunk_language( $row );

				if ( '' !== $chunk_language && $chunk_language === $query_language ) {
					$row['score'] = round( min( 1.0, (float) $row['score'] * self::LANGUAGE_BOOST ), 4 );
				}
			}
			unset( $row );
		}

		usort(
			$merged,
			static fn( $a, $b ) => (float) $b['score'] <=> (float) $a['score']
		);

		/*
		 * Spread the context across pages.
		 *
		 * Without this a single long page (for example /services/) could supply
		 * all five chunks and the answer-bearing page would never reach the
		 * prompt at all. Capping per page keeps several sources in view.
		 */
		$per_source = array();
		$balanced   = array();

		foreach ( $merged as $row ) {
			$url = self::source_url_of( $row );

			$used = $per_source[ $url ] ?? 0;

			if ( $used >= self::MAX_CHUNKS_PER_SOURCE ) {
				continue;
			}

			$per_source[ $url ] = $used + 1;
			$balanced[]         = $row;

			if ( count( $balanced ) >= $limit ) {
				break;
			}
		}

		if ( empty( $balanced ) ) {
			$balanced = array_slice( $merged, 0, $limit );
		}

		if ( empty( $balanced ) ) {
			$balanced = self::get_core_knowledge_chunks( $agent_id, $limit );
		}

		return array(
			'chunks'    => $balanced,
			'top_score' => ! empty( $balanced ) ? (float) $balanced[0]['score'] : 0.0,
		);
	}

	/**
	 * Guaranteed fallback: retrieve balanced core Knowledge Base chunks across primary company pages
	 * so the LLM is never left with an empty context on cross-lingual queries or search misses.
	 *
	 * @param int $agent_id Agent ID.
	 * @param int $limit Maximum chunks to return.
	 * @return array Array of chunk records with metadata and score.
	 */
	public static function get_core_knowledge_chunks( int $agent_id, int $limit = 6 ): array {
		global $wpdb;

		$table_chunks  = $wpdb->prefix . 'ai_knowledge_chunks';
		$table_sources = $wpdb->prefix . 'ai_knowledge_sources';

		$sql = "SELECT c.id, c.source_id, c.source_type, c.chunk_text, c.token_count, c.metadata, c.language, s.title as source_title, s.source_url
			FROM {$table_chunks} c
			INNER JOIN {$table_sources} s ON s.id = c.source_id
			INNER JOIN (
				SELECT MIN(id) as first_chunk_id, source_id
				FROM {$table_chunks}
				WHERE agent_id = %d AND is_enabled = 1 AND (language IN ('de', 'en') OR language IS NULL OR language = '')
				GROUP BY source_id
			) fc ON fc.first_chunk_id = c.id
			WHERE c.agent_id = %d AND c.is_enabled = 1 AND s.status = 'indexed'
				AND (c.language IN ('de', 'en') OR c.language IS NULL OR c.language = '')
			ORDER BY CASE 
				WHEN s.source_url LIKE '%/services%' OR s.source_url LIKE '%/leistungen%' THEN 1
				WHEN s.source_url LIKE '%/products%' OR s.source_url LIKE '%/produkte%' THEN 2
				WHEN s.source_url LIKE '%/pricing%' OR s.source_url LIKE '%/preise%' OR s.source_url LIKE '%/tarife%' THEN 3
				WHEN s.source_url LIKE '%/about%' OR s.source_url LIKE '%/ueber-uns%' THEN 4
				WHEN s.source_url LIKE '%/contact%' OR s.source_url LIKE '%/kontakt%' THEN 5
				WHEN s.source_url LIKE '%/faq%' THEN 6
				ELSE 7
			END ASC, c.id ASC
			LIMIT %d";

		$results = $wpdb->get_results( $wpdb->prepare( $sql, $agent_id, $agent_id, $limit ), \ARRAY_A );

		if ( empty( $results ) ) {
			// Second fallback: any enabled German or English chunks for this agent
			$fallback_sql = "SELECT c.id, c.source_id, c.source_type, c.chunk_text, c.token_count, c.metadata, c.language
				FROM {$table_chunks} c
				WHERE c.agent_id = %d AND c.is_enabled = 1
					AND (c.language IN ('de', 'en') OR c.language IS NULL OR c.language = '')
				ORDER BY c.id ASC
				LIMIT %d";
			$results = $wpdb->get_results( $wpdb->prepare( $fallback_sql, $agent_id, $limit ), \ARRAY_A );
		}

		if ( empty( $results ) ) {
			return array();
		}

		$chunks = array();
		foreach ( $results as $row ) {
			$meta = ! empty( $row['metadata'] ) ? json_decode( $row['metadata'], true ) : array();
			if ( ! is_array( $meta ) ) {
				$meta = array();
			}
			if ( empty( $meta['title'] ) && ! empty( $row['source_title'] ) ) {
				$meta['title'] = $row['source_title'];
			}
			if ( empty( $meta['url'] ) && ! empty( $row['source_url'] ) ) {
				$meta['url'] = $row['source_url'];
			}
			$row['metadata'] = $meta;
			$row['score']    = 0.75;
			$chunks[]        = $row;
		}

		return $chunks;
	}

	/**
	 * Read the language of a retrieved chunk row.
	 *
	 * @param array $row Retrieved chunk row.
	 * @return string Supported language code, or an empty string when unknown.
	 */
	protected static function chunk_language( array $row ): string {
		$language = $row['language'] ?? '';

		if ( is_string( $language ) && '' !== trim( $language ) ) {
			return LanguageService::sanitize( trim( $language ) );
		}

		return '';
	}

	/**
	 * Read the originating page URL out of a chunk row.
	 *
	 * @param array $row Retrieved chunk row.
	 * @return string
	 */
	protected static function source_url_of( array $row ): string {
		$metadata = $row['metadata'] ?? array();

		if ( is_string( $metadata ) ) {
			$decoded  = json_decode( $metadata, true );
			$metadata = is_array( $decoded ) ? $decoded : array();
		}

		if ( is_array( $metadata ) && ! empty( $metadata['page_url'] ) ) {
			return (string) $metadata['page_url'];
		}

		// Fall back to the source id so different pages stay distinct.
		return 'source:' . (int) ( $row['source_id'] ?? 0 );
	}

	/**
	 * Resolve a meaningful relevance floor for the active embedding model.
	 *
	 * Cosine similarity is not comparable across models: an unrelated question
	 * scores about 0.29 against OpenAI's text-embedding-3 but about 0.75
	 * against Mistral's mistral-embed. A fixed threshold is therefore a no-op
	 * for some providers and would reject everything for others.
	 *
	 * Two deliberately generic probe questions are scored against the real
	 * index and the mean of their best matches approximates the "nothing
	 * relevant" floor for this provider and model. The result is cached for a
	 * week so this costs two embedding calls, not one per visitor message.
	 *
	 * @param string               $provider_id Provider slug, used in the cache key.
	 * @param AIProviderInterface  $provider    Embeddings provider.
	 * @param string               $model       Embeddings model.
	 * @param string               $api_key     Plaintext API key.
	 * @param ?VectorStoreInterface $vector_store Store to probe.
	 * @return float|null Calibrated floor, or null when it cannot be computed.
	 */
	public static function calibrated_floor(
		string $provider_id,
		AIProviderInterface $provider,
		string $model,
		string $api_key,
		?VectorStoreInterface $vector_store = null
	): ?float {
		$cache_key = 'ai_chatbot_relevance_floor_' . md5( $provider_id . '|' . $model );
		$cached    = get_option( $cache_key );

		if ( false !== $cached && null !== $cached && '' !== $cached ) {
			return (float) $cached;
		}

		if ( ! $vector_store ) {
			$vector_store = new CustomTableStore();
		}

		$probes = array(
			'Wie backe ich einen Apfelkuchen?',
			'What is the capital of France?',
		);

		$scores = array();

		foreach ( $probes as $probe ) {
			$vectors = $provider->generate_embeddings( $probe, $model, $api_key );

			if ( empty( $vectors ) || ! isset( $vectors[0] ) ) {
				continue;
			}

			$rows = $vector_store->query_similar( $vectors[0], 1, 1 );

			if ( ! empty( $rows ) ) {
				$scores[] = (float) $rows[0]['score'];
			}
		}

		if ( empty( $scores ) ) {
			return null;
		}

		$floor = array_sum( $scores ) / count( $scores );

		update_option( $cache_key, (string) $floor, false );
		set_transient( $cache_key . '_ts', time(), WEEK_IN_SECONDS );

		return (float) $floor;
	}

	/**
	 * Common cross-lingual business translations to ensure semantic embeddings
	 * and keyword matching find localized knowledge base content.
	 *
	 * @param string $query User query.
	 * @param string $language Visitor language code.
	 * @param string $company_name Optional company name.
	 * @return array Array of translated query strings or terms.
	 */
	public static function get_cross_lingual_variants( string $query, string $language = '', string $company_name = '' ): array {
		$variants = array();
		$clean_q  = mb_strtolower( trim( $query ) );

		if ( '' === $clean_q ) {
			return $variants;
		}

		$company = $company_name ?: ( function_exists( 'get_bloginfo' ) ? (string) get_bloginfo( 'name' ) : 'Company' );

		// Cross-lingual general business and service term mapping.
		$term_map = array(
			// Services / products
			'services'     => 'Dienstleistungen Leistungen Angebot',
			'service'      => 'Leistung Dienstleistung',
			'offer'        => 'Angebot Leistungen',
			'products'     => 'Produkte Angebote',
			'product'      => 'Produkt',
			// Pricing / cost / quote
			'cost'         => 'Kosten Preise',
			'costs'        => 'Kosten Preise',
			'price'        => 'Preis Kosten',
			'prices'       => 'Preise Tarife',
			'how much'     => 'Wie viel was kostet Kosten',
			'quote'        => 'Kostenvoranschlag Angebot anfragen',
			'pricing'      => 'Preise Tarife Kosten',
			// Company / Contact / Location
			'company'      => 'Unternehmen Firma ' . $company,
			'contact'      => 'Kontakt Ansprechpartner Kundenservice',
			'address'      => 'Adresse Standort',
			'location'     => 'Standort Adresse',
			'hours'        => 'Öffnungszeiten Geschäftszeiten',
			'support'      => 'Support Hilfe Kundendienst',
			'consultation' => 'Beratung Beratungstermin',
			'warranty'     => 'Garantie Gewährleistung',
			'maintenance'  => 'Wartung Reparatur Service',
			'installation' => 'Installation Montage Einrichtung',
			'order'        => 'Bestellung Auftrag',
			'refund'       => 'Rückerstattung Erstattung',
			'shipping'     => 'Versand Lieferung',
		);

		$matched_german_terms = array();
		foreach ( $term_map as $en_term => $de_terms ) {
			if ( preg_match( '/\b' . preg_quote( $en_term, '/' ) . '\b/iu', $clean_q ) ) {
				$matched_german_terms[] = $de_terms;
			}
		}

		if ( ! empty( $matched_german_terms ) ) {
			$variants[] = implode( ' ', array_unique( explode( ' ', implode( ' ', $matched_german_terms ) ) ) );
		}

		// Common high-intent query translations.
		if ( preg_match( '/\b(what services|what do you offer|which services)\b/iu', $clean_q ) ) {
			$variants[] = sprintf( 'Welche Leistungen und Dienstleistungen bietet %s an?', $company );
		} elseif ( preg_match( '/\b(how much|cost|price|prices)\b/iu', $clean_q ) ) {
			$variants[] = 'Was kosten Ihre Dienstleistungen und Angebote? Preise und Kosten';
		} elseif ( preg_match( '/\b(contact|reach|phone|email)\b/iu', $clean_q ) ) {
			$variants[] = sprintf( 'Wie kann ich %s kontaktieren? Kontakt und Adresse', $company );
		} elseif ( preg_match( '/\b(hours|opening hours|business hours)\b/iu', $clean_q ) ) {
			$variants[] = 'Wie sind die Öffnungszeiten und Geschäftszeiten?';
		}

		return array_values( array_unique( $variants ) );
	}
}
