<?php
/**
 * Scope Tuning — out of scope question rulings and manual answer overrides.
 *
 * @package AIChatbot\Knowledge
 */

namespace AIChatbot\Knowledge;

use AIChatbot\Security\Sanitizer;
use AIChatbot\AI\AIProviderInterface;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Class ScopeTuning.
 *
 * Backs the "Scope Tuning" half of the Unanswered Questions & Scope Tuning
 * screen, which until now could only be read and dismissed wholesale by
 * deletion.
 *
 * Every visitor message a guardrail deflects is written to
 * ai_out_of_scope_log by TopicGuardrail::log_out_of_scope(). That log had no
 * draft column and no verdict column, so an administrator could only decide
 * "in scope" (which deleted the row and published an answer) or delete it.
 *
 * This class adds the missing half: draft an answer for a deflected question,
 * correct that draft by hand, then rule on the question. Ruling "in scope"
 * publishes a Q&A source and marks the row 'in_scope'; ruling "out of scope"
 * records a negative example the accuracy report can count.
 */
class ScopeTuning {

	/**
	 * Table name without prefix.
	 */
	public const TABLE_NAME = 'ai_out_of_scope_log';

	/**
	 * Allowed values for the status column.
	 *
	 * 'pending' is the state a deflected question starts in and the only one
	 * that can still be ruled on.
	 */
	public const STATUSES = array( 'pending', 'draft_ready', 'in_scope', 'out_of_scope' );

	/**
	 * Get out of scope entries, filtered by status.
	 *
	 * @param int    $agent_id Agent ID.
	 * @param string $status   Optional status filter.
	 * @param int    $limit    Max rows.
	 * @return array
	 */
	public static function get_entries( int $agent_id = 1, string $status = '', int $limit = 100 ): array {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE_NAME;

		if ( '' !== $status && in_array( $status, self::STATUSES, true ) ) {
			return $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$table} WHERE agent_id = %d AND status = %s ORDER BY occurrences DESC, last_asked_at DESC LIMIT %d",
					$agent_id,
					$status,
					$limit
				),
				ARRAY_A
			) ?: array();
		}

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE agent_id = %d ORDER BY occurrences DESC, last_asked_at DESC LIMIT %d",
				$agent_id,
				$limit
			),
			ARRAY_A
		) ?: array();
	}

	/**
	 * Load a single row.
	 *
	 * @param int $id Entry ID.
	 * @return ?array
	 */
	public static function get_entry( int $id ): ?array {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE_NAME;

		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );

		return $row ?: null;
	}

	/**
	 * Generate an AI draft answer for a deflected question.
	 *
	 * The prompt differs from UnansweredInbox::generate_draft_answer() on
	 * purpose: a deflected question is one the guardrails judged out of scope,
	 * so the draft must not assert scope. It is written as a holding reply that
	 * acknowledges the question and routes to human contact, which is exactly
	 * the shape an administrator can then correct into a real answer.
	 *
	 * @param string              $question Question.
	 * @param AIProviderInterface $provider Provider.
	 * @param string              $api_key  API key.
	 * @return ?string
	 */
	public static function generate_draft_answer( string $question, AIProviderInterface $provider, string $api_key ): ?string {
		$company = get_bloginfo( 'name' );
		$system  = sprintf(
			/* translators: %s: company name. */
			__( 'You are helping a website administrator draft a short, professional holding reply to a visitor question that this website may not cover. Do not invent facts. Keep it to one or two sentences and offer to pass the visitor to the team at %s.', 'ai-chatbot' ),
			$company
		);

		$messages = array(
			array( 'role' => 'system', 'content' => $system ),
			array( 'role' => 'user', 'content' => sprintf( /* translators: %s: visitor question. */ __( 'Draft a holding reply for this visitor question: "%s"', 'ai-chatbot' ), $question ) ),
		);

		$options = array(
			'model'       => 'openai' === $provider->get_id() ? 'gpt-4o-mini' : 'llama-3.3-70b-versatile',
			'temperature' => 0.4,
			'max_tokens'  => 200,
		);

		$res = $provider->chat( $messages, $options, $api_key );

		return ! empty( $res['content'] ) ? trim( $res['content'] ) : null;
	}

	/**
	 * Save a corrected draft answer for a deflected question.
	 *
	 * The AI draft is never overwritten: it is copied into ai_draft_answer on
	 * first manual save so the admin can diff their correction against what the
	 * model produced, and so the accuracy report can measure how far the two
	 * diverged. Reverting restores that original.
	 *
	 * @param int    $id      Entry ID.
	 * @param string $answer  Corrected answer text.
	 * @param bool   $regenerate Replace the draft with a fresh AI draft instead of storing the text.
	 * @param ?AIProviderInterface $provider Provider used when regenerating.
	 * @param ?string $api_key Key used when regenerating.
	 * @return array|WP_Error Updated row, or an error.
	 */
	public static function save_draft( int $id, string $answer, bool $regenerate = false, ?AIProviderInterface $provider = null, ?string $api_key = null ) {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE_NAME;

		$item = self::get_entry( $id );

		if ( ! $item ) {
			return new WP_Error(
				'not_found',
				__( 'Item not found.', 'ai-chatbot' ),
				array( 'status' => 404 )
			);
		}

		if ( in_array( $item['status'], array( 'in_scope', 'out_of_scope' ), true ) ) {
			return new WP_Error(
				'already_ruled',
				__( 'This question has already been ruled on. Reopen it before editing its draft.', 'ai-chatbot' ),
				array( 'status' => 409 )
			);
		}

		$ai_draft = $item['ai_draft_answer'] ?? null;

		if ( $regenerate ) {
			if ( ! $provider || empty( $api_key ) ) {
				return new WP_Error(
				'no_provider',
				__( 'No AI provider is configured, so the draft cannot be regenerated.', 'ai-chatbot' ),
				array( 'status' => 400 )
			);
			}

			$regenerated = self::generate_draft_answer( (string) $item['question'], $provider, $api_key );

			if ( empty( $regenerated ) ) {
				return new WP_Error(
				'regenerate_failed',
				__( 'The AI provider returned no draft. Try again or write the answer yourself.', 'ai-chatbot' ),
				array( 'status' => 502 )
			);
			}

			// The first AI draft is the one worth comparing against, so only set
			// it when a manual correction does not already exist.
			if ( empty( $ai_draft ) ) {
				$ai_draft = $regenerated;
			}

			$answer = $regenerated;
		}

		$answer = trim( $answer );

		if ( '' === $answer ) {
			return new WP_Error(
				'empty_answer',
				__( 'Answer cannot be empty.', 'ai-chatbot' ),
				array( 'status' => 400 )
			);
		}

		$previous = $item['draft_answer'] ?? null;

		$updated = $wpdb->update(
			$table,
			array(
				'draft_answer'   => $answer,
				'ai_draft_answer' => $ai_draft,
				'draft_source'   => $regenerate ? 'ai' : 'human',
				'draft_edited_at' => current_time( 'mysql' ),
				'draft_edited_by' => get_current_user_id(),
				'edit_count'      => (int) $item['edit_count'] + 1,
				'status'         => 'draft_ready',
				'updated_at'     => current_time( 'mysql' ),
			),
			array( 'id' => $id ),
			array( '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s' ),
			array( '%d' )
		);

		if ( false === $updated ) {
			return new WP_Error(
				'update_failed',
				__( 'Failed to save the draft.', 'ai-chatbot' ),
				array( 'status' => 500 )
			);
		}

		AnswerOverrideLog::record(
			array(
				'agent_id'        => (int) $item['agent_id'],
				'source_type'     => 'out_of_scope',
				'source_id'       => $id,
				'action'          => $regenerate ? 'regenerate' : 'edit',
				'question'        => (string) $item['question'],
				'logged_question' => (string) $item['question'],
				'ai_draft'        => $regenerate ? ( $ai_draft ?? $previous ) : ( $ai_draft ?? $previous ),
				'edited_answer'   => $answer,
			)
		);

		return self::get_entry( $id );
	}

	/**
	 * Restore the original AI draft, discarding the manual correction.
	 *
	 * @param int $id Entry ID.
	 * @return array|WP_Error
	 */
	public static function revert_draft( int $id ) {
		$item = self::get_entry( $id );

		if ( ! $item ) {
			return new WP_Error(
				'not_found',
				__( 'Item not found.', 'ai-chatbot' ),
				array( 'status' => 404 )
			);
		}

		if ( empty( $item['ai_draft_answer'] ) ) {
			return new WP_Error(
				'no_ai_draft',
				__( 'There is no original AI draft to revert to.', 'ai-chatbot' ),
				array( 'status' => 400 )
			);
		}

		global $wpdb;
		$table = $wpdb->prefix . self::TABLE_NAME;

		$updated = $wpdb->update(
			$table,
			array(
				'draft_answer'   => $item['ai_draft_answer'],
				'draft_source'   => 'ai',
				'draft_edited_at' => null,
				'draft_edited_by' => null,
				'updated_at'     => current_time( 'mysql' ),
			),
			array( 'id' => $id ),
			array( '%s', '%s', '%s', '%s', '%s' ),
			array( '%d' )
		);

		if ( false === $updated ) {
			return new WP_Error(
				'update_failed',
				__( 'Failed to revert the draft.', 'ai-chatbot' ),
				array( 'status' => 500 )
			);
		}

		AnswerOverrideLog::record(
			array(
				'agent_id'        => (int) $item['agent_id'],
				'source_type'     => 'out_of_scope',
				'source_id'       => $id,
				'action'          => 'revert',
				'question'        => (string) $item['question'],
				'logged_question' => (string) $item['question'],
				'ai_draft'        => (string) $item['ai_draft_answer'],
				'edited_answer'   => (string) $item['ai_draft_answer'],
			)
		);

		return self::get_entry( $id );
	}

	/**
	 * Rule a deflected question out of scope for good.
	 *
	 * The row is kept rather than deleted so the decision is auditable and so
	 * a repeated deflection can be recognised later. It is also the negative
	 * half of the tuning signal: a question ruled out of scope several times is
	 * one the classifier is getting right, while several distinct questions
	 * ruled out of scope point at the allowed_topics list being too broad.
	 *
	 * @param int $id Entry ID.
	 * @return array|WP_Error
	 */
	public static function rule_out_of_scope( int $id ) {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE_NAME;

		$item = self::get_entry( $id );

		if ( ! $item ) {
			return new WP_Error(
				'not_found',
				__( 'Item not found.', 'ai-chatbot' ),
				array( 'status' => 404 )
			);
		}

		$updated = $wpdb->update(
			$table,
			array(
				'status'     => 'out_of_scope',
				'updated_at' => current_time( 'mysql' ),
			),
			array( 'id' => $id ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		if ( false === $updated ) {
			return new WP_Error(
				'update_failed',
				__( 'Failed to record the ruling.', 'ai-chatbot' ),
				array( 'status' => 500 )
			);
		}

		AnswerOverrideLog::record(
			array(
				'agent_id'        => (int) $item['agent_id'],
				'source_type'     => 'out_of_scope',
				'source_id'       => $id,
				'action'          => 'out_of_scope',
				'question'        => (string) $item['question'],
				'logged_question' => (string) $item['question'],
				'ai_draft'        => $item['draft_answer'] ?? null,
				'edited_answer'   => null,
			)
		);

		return self::get_entry( $id );
	}

	/**
	 * Reopen a ruled entry so its draft can be edited again.
	 *
	 * @param int $id Entry ID.
	 * @return array|WP_Error
	 */
	public static function reopen( int $id ) {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE_NAME;

		$item = self::get_entry( $id );

		if ( ! $item ) {
			return new WP_Error(
				'not_found',
				__( 'Item not found.', 'ai-chatbot' ),
				array( 'status' => 404 )
			);
		}

		$updated = $wpdb->update(
			$table,
			array(
				'status'     => ! empty( $item['draft_answer'] ) ? 'draft_ready' : 'pending',
				'updated_at' => current_time( 'mysql' ),
			),
			array( 'id' => $id ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		if ( false === $updated ) {
			return new WP_Error(
				'update_failed',
				__( 'Failed to reopen the entry.', 'ai-chatbot' ),
				array( 'status' => 500 )
			);
		}

		return self::get_entry( $id );
	}

	/**
	 * Correct the text of a logged out of scope question.
	 *
	 * Mirrors UnansweredInbox::edit_question(): the dedupe hash is recomputed so
	 * future visits increment this row instead of creating a duplicate.
	 *
	 * @param int    $id       Entry ID.
	 * @param string $question Replacement question text.
	 * @return array|WP_Error
	 */
	public static function edit_question( int $id, string $question ) {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE_NAME;

		$item = self::get_entry( $id );

		if ( ! $item ) {
			return new WP_Error(
				'not_found',
				__( 'Item not found.', 'ai-chatbot' ),
				array( 'status' => 404 )
			);
		}

		if ( in_array( $item['status'], array( 'in_scope', 'out_of_scope' ), true ) ) {
			return new WP_Error(
				'already_ruled',
				__( 'Reopen this entry before editing its question.', 'ai-chatbot' ),
				array( 'status' => 409 )
			);
		}

		$clean_q = Sanitizer::scrub_pii( $question );

		if ( empty( $clean_q ) ) {
			return new WP_Error(
				'empty_question',
				__( 'Question cannot be empty.', 'ai-chatbot' ),
				array( 'status' => 400 )
			);
		}

		$normalized = strtolower( (string) preg_replace( '/[^\p{L}\p{N}\s]/u', '', $clean_q ) );
		$hash       = md5( trim( $normalized ) );

		$clash = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE agent_id = %d AND normalized_hash = %s AND id <> %d",
				$item['agent_id'],
				$hash,
				$id
			)
		);

		if ( $clash ) {
			return new WP_Error(
				'duplicate_question',
				__( 'Another out of scope entry already has this exact question.', 'ai-chatbot' ),
				array( 'status' => 409 )
			);
		}

		// The draft was written for the old wording, so it is discarded.
		$updated = $wpdb->update(
			$table,
			array(
				'question'          => $clean_q,
				'normalized_hash'   => $hash,
				'draft_answer'      => null,
				'ai_draft_answer'   => null,
				'draft_source'      => 'none',
				'draft_edited_at'   => null,
				'draft_edited_by'   => null,
				'status'            => 'pending',
				'updated_at'        => current_time( 'mysql' ),
			),
			array( 'id' => $id ),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ),
			array( '%d' )
		);

		if ( false === $updated ) {
			return new WP_Error(
				'update_failed',
				__( 'Failed to update question.', 'ai-chatbot' ),
				array( 'status' => 500 )
			);
		}

		return self::get_entry( $id );
	}

	/**
	 * Promote a ruled-in-scope question to a curated Q&A knowledge source.
	 *
	 * Called by the REST controller after the ruling is recorded, because
	 * indexing needs provider credentials the domain layer does not hold.
	 *
	 * @param array $entry   Entry row from get_entry().
	 * @param string $answer  Answer to publish.
	 * @return int Knowledge source ID, or 0 on failure.
	 */
	public static function promote_to_qa( array $entry, string $answer ): int {
		return KnowledgeManager::add_source(
			array(
				'agent_id' => (int) $entry['agent_id'],
				'type'     => 'qa',
				'title'    => (string) $entry['question'],
				'content'  => wp_json_encode(
					array(
						'question' => (string) $entry['question'],
						'answer'   => $answer,
					)
				),
				'metadata' => array(
					'category'          => 'FAQ',
					'from_out_of_scope_id' => (int) $entry['id'],
					'answer_origin'     => (string) ( $entry['draft_source'] ?? 'human' ),
				),
			)
		);
	}

	/**
	 * Record that a ruled-in-scope entry produced a published Q&A source.
	 *
	 * @param int $entry_id Entry ID.
	 * @param int $qa_id    Knowledge source ID.
	 * @return void
	 */
	public static function attach_qa_id( int $entry_id, int $qa_id ): void {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE_NAME;

		$wpdb->update(
			$table,
			array( 'resolved_qa_id' => $qa_id ),
			array( 'id' => $entry_id ),
			array( '%d' ),
			array( '%d' )
		);
	}
}