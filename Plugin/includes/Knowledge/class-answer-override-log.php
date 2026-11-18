<?php
/**
 * Manual Answer Override Audit Log.
 *
 * @package AIChatbot\Knowledge
 */

namespace AIChatbot\Knowledge;

use AIChatbot\Database\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Class AnswerOverrideLog.
 *
 * Records every administrator correction of an AI-drafted answer so the edit
 * history survives the inbox row itself being approved, dismissed or deleted.
 *
 * The log is what turns "a human disagreed with the AI" into a signal that can
 * be measured. Two ratios are stored per entry and surfaced by
 * DraftAccuracy::stats():
 *
 * - ai_similarity: how close the human answer stayed to the AI draft. A low
 *   value means the model produced something actively wrong, not merely a
 *   different phrasing, and is the entry worth reviewing as a prompt or
 *   retrieval defect.
 * - question_similarity: how close the question stayed to the logged visitor
 *   question. A low value means the log was deduplicating on an inaccurate
 *   normalized_hash, so occurrence counts under-report.
 *
 * Scores are word-level Jaccard similarity computed locally. Embeddings would
 * give a more semantic measure, but they cost an API call per edit and these
 * entries are read as a rough signal, not a benchmark.
 */
class AnswerOverrideLog {

	/**
	 * Table name without prefix.
	 */
	public const TABLE_NAME = Schema::OVERRIDE_LOG_TABLE;

	/**
	 * Record one override action.
	 *
	 * @param array $data {
	 *     @type int    $agent_id           Agent ID.
	 *     @type string $source_type        'unanswered' or 'out_of_scope'.
	 *     @type int    $source_id          Row ID in the source table.
	 *     @type string $action             edit|regenerate|revert|approve|in_scope|out_of_scope.
	 *     @type string $question           Visitor question at the time of the action.
	 *     @type ?string $ai_draft         The AI draft as it was before the action.
	 *     @type ?string $edited_answer     The answer after the action.
	 *     @type ?int   $resolved_qa_id     Knowledge source created by the action.
	 * }
	 * @return int Inserted log ID, or 0 on failure.
	 */
	public static function record( array $data ): int {
		global $wpdb;

		$table    = $wpdb->prefix . self::TABLE_NAME;
		$question = (string) ( $data['question'] ?? '' );
		$ai_draft = isset( $data['ai_draft'] ) ? (string) $data['ai_draft'] : null;
		$edited   = isset( $data['edited_answer'] ) ? (string) $data['edited_answer'] : null;

		$inserted = $wpdb->insert(
			$table,
			array(
				'agent_id'           => (int) ( $data['agent_id'] ?? 1 ),
				'source_type'        => sanitize_key( $data['source_type'] ?? 'unanswered' ),
				'source_id'          => (int) ( $data['source_id'] ?? 0 ),
				'action'             => sanitize_key( $data['action'] ?? 'edit' ),
				'question'           => $question,
				'ai_draft'           => $ai_draft,
				'edited_answer'      => $edited,
				'ai_similarity'      => self::similarity( $ai_draft, $edited ),
				'question_similarity' => self::similarity( $question, $data['logged_question'] ?? $question ),
				'resolved_qa_id'     => ! empty( $data['resolved_qa_id'] ) ? (int) $data['resolved_qa_id'] : null,
				'user_id'            => (int) ( $data['user_id'] ?? get_current_user_id() ),
				'created_at'         => current_time( 'mysql' ),
			),
			array( '%d', '%s', '%d', '%s', '%s', '%s', '%s', '%f', '%f', '%d', '%d', '%s' )
		);

		return $inserted ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * Word-level Jaccard similarity between two texts.
	 *
	 * Returns null when either side is empty, because "0.0" would be
	 * indistinguishable from "these are entirely different".
	 *
	 * @param ?string $a First text.
	 * @param ?string $b Second text.
	 * @return ?float Similarity between 0 and 1, or null when not comparable.
	 */
	public static function similarity( ?string $a, ?string $b ): ?float {
		$set_a = self::tokenize( $a );
		$set_b = self::tokenize( $b );

		if ( empty( $set_a ) || empty( $set_b ) ) {
			return null;
		}

		$intersection = count( array_intersect( $set_a, $set_b ) );
		$union        = count( array_unique( array_merge( $set_a, $set_b ) ) );

		if ( 0 === $union ) {
			return null;
		}

		return round( $intersection / $union, 4 );
	}

	/**
	 * Reduce text to a comparable set of lowercased content words.
	 *
	 * @param ?string $text Input text.
	 * @return array<string>
	 */
	protected static function tokenize( ?string $text ): array {
		if ( null === $text || '' === trim( $text ) ) {
			return array();
		}

		$stripped = strtolower( (string) preg_replace( '/[^\p{L}\p{N}\s]/u', ' ', $text ) );
		$words    = preg_split( '/\s+/', trim( $stripped ), -1, PREG_SPLIT_NO_EMPTY );

		// Stop words carry no answer content and inflate every score.
		$stop_words = array(
			'the', 'a', 'an', 'and', 'or', 'but', 'is', 'are', 'was', 'were', 'be', 'been',
			'to', 'of', 'in', 'on', 'for', 'with', 'as', 'at', 'by', 'from', 'it', 'its',
			'this', 'that', 'these', 'those', 'you', 'your', 'we', 'our', 'us', 'i',
		);

		return array_values(
			array_diff(
				array_unique( $words ),
				$stop_words
			)
		);
	}

	/**
	 * Fetch override entries for the audit table.
	 *
	 * @param int    $agent_id    Agent ID.
	 * @param string $source_type Optional filter: 'unanswered' or 'out_of_scope'.
	 * @param int    $limit       Max rows.
	 * @return array
	 */
	public static function get_entries( int $agent_id = 1, string $source_type = '', int $limit = 50 ): array {
		global $wpdb;

		$table = $wpdb->prefix . self::TABLE_NAME;

		if ( '' !== $source_type ) {
			return $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$table} WHERE agent_id = %d AND source_type = %s ORDER BY created_at DESC LIMIT %d",
					$agent_id,
					$source_type,
					$limit
				),
				ARRAY_A
			) ?: array();
		}

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE agent_id = %d ORDER BY created_at DESC LIMIT %d",
				$agent_id,
				$limit
			),
			ARRAY_A
		) ?: array();
	}
}