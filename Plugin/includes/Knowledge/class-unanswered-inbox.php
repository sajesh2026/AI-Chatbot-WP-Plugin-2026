<?php
/**
 * Unanswered Questions Inbox (Self-improving Knowledge Base).
 *
 * @package AIChatbot\Knowledge
 */

namespace AIChatbot\Knowledge;

use AIChatbot\Security\Sanitizer;
use AIChatbot\AI\AIProviderInterface;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Class UnansweredInbox.
 */
class UnansweredInbox {

	/**
	 * Table name without prefix.
	 */
	public const TABLE_NAME = 'ai_unanswered_questions';

	/**
	 * Log an unanswered question into the inbox.
	 *
	 * @param int    $agent_id Agent ID.
	 * @param string $question Visitor question.
	 * @param ?AIProviderInterface $ai_provider AI provider to draft answer.
	 * @param ?string $api_key API key.
	 * @return int Record ID.
	 */
	public static function log_question( int $agent_id, string $question, ?AIProviderInterface $ai_provider = null, ?string $api_key = null ): int {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE_NAME;

		// 1. Scrub PII (emails, phone numbers).
		$clean_q = Sanitizer::scrub_pii( $question );
		if ( empty( $clean_q ) ) {
			return 0;
		}

		// Normalize hash: lowercase, punctuation removed, trimmed.
		$normalized = strtolower( (string) preg_replace( '/[^\p{L}\p{N}\s]/u', '', $clean_q ) );
		$hash       = md5( trim( $normalized ) );

		$existing = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, occurrences, status FROM {$table} WHERE agent_id = %d AND normalized_hash = %s",
				$agent_id,
				$hash
			)
		);

		if ( $existing ) {
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$table}
					 SET occurrences = occurrences + 1, last_asked_at = %s, updated_at = %s
					 WHERE id = %d",
					current_time( 'mysql' ),
					current_time( 'mysql' ),
					$existing->id
				)
			);
			return (int) $existing->id;
		}

		// Draft answer if AI provider is supplied.
		$draft_answer = null;
		$status       = 'new';
		if ( $ai_provider && ! empty( $api_key ) ) {
			$draft_answer = self::generate_draft_answer( $clean_q, $ai_provider, $api_key );
			if ( ! empty( $draft_answer ) ) {
				$status = 'draft_ready';
			}
		}

		$wpdb->insert(
			$table,
			array(
				'agent_id'         => $agent_id,
				'question'         => $clean_q,
				'normalized_hash'  => $hash,
				'occurrences'      => 1,
				'status'           => $status,
				'draft_answer'     => $draft_answer,
				'ai_draft_answer'  => $draft_answer,
				'draft_source'     => $draft_answer ? 'ai' : 'none',
				'last_asked_at'    => current_time( 'mysql' ),
				'created_at'       => current_time( 'mysql' ),
			),
			array( '%d', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * Generate an initial AI draft answer based on prompt instructions.
	 *
	 * @param string              $question Question.
	 * @param AIProviderInterface $provider Provider.
	 * @param string              $api_key API key.
	 * @return ?string
	 */
	public static function generate_draft_answer( string $question, AIProviderInterface $provider, string $api_key ): ?string {
		$system = 'You are helping a website administrator draft a clear, helpful FAQ answer to a customer question. If specific business facts are unknown, provide a professional, friendly template answer that invites the visitor to reach out, or answer general concepts accurately.';
		$messages = array(
			array( 'role' => 'system', 'content' => $system ),
			array( 'role' => 'user', 'content' => "Please draft a concise, helpful answer for this customer question: \"{$question}\"" ),
		);

		$options = array(
			'model'       => 'openai' === $provider->get_id() ? 'gpt-4o-mini' : 'llama-3.3-70b-versatile',
			'temperature' => 0.5,
			'max_tokens'  => 300,
		);

		$res = $provider->chat( $messages, $options, $api_key );
		return ! empty( $res['content'] ) ? trim( $res['content'] ) : null;
	}

	/**
	 * Get unanswered questions with filtering and sorting.
	 *
	 * @param int    $agent_id Agent ID.
	 * @param string $status Filter status ('all', 'new', 'draft_ready', 'approved', 'dismissed').
	 * @return array
	 */
	public static function get_questions( int $agent_id = 1, string $status = 'all' ): array {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE_NAME;

		if ( 'all' !== $status ) {
			return $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$table} WHERE agent_id = %d AND status = %s ORDER BY occurrences DESC, last_asked_at DESC",
					$agent_id,
					$status
				),
				ARRAY_A
			) ?: array();
		}

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE agent_id = %d ORDER BY occurrences DESC, last_asked_at DESC",
				$agent_id
			),
			ARRAY_A
		) ?: array();
	}

	/**
	 * Approve question: creates a high-priority Q&A source and marks item approved.
	 *
	 * @param int                  $id Unanswered question record ID.
	 * @param string               $approved_answer Final approved answer text.
	 * @param ?AIProviderInterface $embeddings_provider Optional provider to immediately index the new Q&A.
	 * @param ?string              $embedding_model Model.
	 * @param ?string              $api_key Key.
	 * @return bool
	 */
	public static function approve(
		int $id,
		string $approved_answer,
		?AIProviderInterface $embeddings_provider = null,
		?string $embedding_model = null,
		?string $api_key = null
	): bool {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE_NAME;

		$item = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );
		if ( ! $item ) {
			return false;
		}

		$agent_id = (int) $item['agent_id'];
		$question = $item['question'];
		$answer   = trim( $approved_answer );

		// 1. Create curated Q&A knowledge source.
		$source_id = KnowledgeManager::add_source(
			array(
				'agent_id' => $agent_id,
				'type'     => 'qa',
				'title'    => $question,
				'content'  => wp_json_encode( array( 'question' => $question, 'answer' => $answer ) ),
				'metadata' => array( 'category' => 'FAQ', 'from_unanswered_id' => $id ),
			)
		);

		// 2. Index immediately if provider credentials available.
		if ( $source_id > 0 && $embeddings_provider && ! empty( $embedding_model ) && ! empty( $api_key ) ) {
			Indexer::index_source( $source_id, $embeddings_provider, $embedding_model, $api_key );
		}

		// 3. Mark approved in inbox.
		$wpdb->update(
			$table,
			array(
				'status'         => 'approved',
				'draft_answer'   => $answer,
				'resolved_qa_id' => $source_id,
				'updated_at'     => current_time( 'mysql' ),
			),
			array( 'id' => $id ),
			array( '%s', '%s', '%d', '%s' ),
			array( '%d' )
		);

		/*
		 * Record what was published. The answer reaching the knowledge base may
		 * be the AI draft approved untouched, a manual correction, or an AI
		 * regeneration, and the accuracy report needs to tell those apart.
		 */
		AnswerOverrideLog::record(
			array(
				'agent_id'        => $agent_id,
				'source_type'     => 'unanswered',
				'source_id'       => $id,
				'action'          => 'approve',
				'question'        => $question,
				'logged_question' => $question,
				'ai_draft'        => $item['ai_draft_answer'] ?? null,
				'edited_answer'   => $answer,
				'resolved_qa_id'  => $source_id,
			)
		);

		return true;
	}

	/**
	 * Dismiss a question from the inbox (spam, off-topic, or unwanted).
	 *
	 * @param int $id Question record ID.
	 * @return bool
	 */
	public static function dismiss( int $id ): bool {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE_NAME;

		$updated = $wpdb->update(
			$table,
			array(
				'status'     => 'dismissed',
				'updated_at' => current_time( 'mysql' ),
			),
			array( 'id' => $id ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		return false !== $updated;
	}

	/**
	 * Permanently delete a single logged question.
	 *
	 * Unlike dismiss(), which only flags the row, this removes it so the same
	 * question is treated as new if it is ever asked again.
	 *
	 * @param int $id Question record ID.
	 * @return bool True when a row was removed.
	 */
	public static function delete_question( int $id ): bool {
		global $wpdb;

		$table = $wpdb->prefix . self::TABLE_NAME;

		$exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE id = %d", $id ) );

		if ( ! $exists ) {
			return false;
		}

		$deleted = $wpdb->delete( $table, array( 'id' => $id ), array( '%d' ) );

		return (bool) $deleted;
	}

	/**
	 * Permanently delete every logged question for an agent.
	 *
	 * @param int $agent_id Agent ID.
	 * @return int Number of rows removed.
	 */
	public static function delete_all_questions( int $agent_id = 1 ): int {
		global $wpdb;

		$table = $wpdb->prefix . self::TABLE_NAME;

		return (int) $wpdb->query(
			$wpdb->prepare( "DELETE FROM {$table} WHERE agent_id = %d", $agent_id )
		);
	}

	/**
	 * Edit the text of a logged question.
	 *
	 * Two columns have to move with the text, otherwise the inbox silently
	 * corrupts itself:
	 *
	 * - normalized_hash is what log_question() deduplicates on. Without a
	 *   recompute, asking the original question again would create a second row
	 *   instead of incrementing occurrences, and asking the edited question
	 *   would not find this row either.
	 * - draft_answer was written for the old wording, so it is discarded and
	 *   the status drops back to 'new'. Keeping it would let an administrator
	 *   approve an answer to a question that is no longer being asked.
	 *
	 * Approved items are refused. approve() has already published a knowledge
	 * source and indexed it, so correcting the inbox row alone would leave the
	 * published Q&A and the inbox disagreeing; fixing that means re-embedding.
	 *
	 * @param int    $id       Question record ID.
	 * @param string $question Replacement question text.
	 * @return array|WP_Error Updated columns, or an error explaining the refusal.
	 */
	public static function edit_question( int $id, string $question ) {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE_NAME;

		$item = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );

		if ( ! $item ) {
			return new WP_Error(
				'not_found',
				__( 'Question not found.', 'ai-chatbot' ),
				array( 'status' => 404 )
			);
		}

		if ( 'approved' === $item['status'] ) {
			return new WP_Error(
				'question_already_approved',
				__( 'This question is already in the knowledge base. Edit the Q&A entry itself instead.', 'ai-chatbot' ),
				array( 'status' => 409 )
			);
		}

		// Same PII scrub log_question() applies, so a corrected question is
		// stored no less carefully than a captured one.
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

		/*
		 * Refuse a collision rather than letting two rows share a hash. They
		 * would be indistinguishable to log_question() from then on, so neither
		 * would ever report an occurrence.
		 */
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
				__( 'Another inbox entry already has this exact question. Edit or delete that one instead.', 'ai-chatbot' ),
				array( 'status' => 409 )
			);
		}

		$updated = $wpdb->update(
			$table,
			array(
				'question'        => $clean_q,
				'normalized_hash' => $hash,
				'draft_answer'    => null,
				'ai_draft_answer' => null,
				'draft_source'    => 'none',
				'status'          => 'new',
				'updated_at'      => current_time( 'mysql' ),
			),
			array( 'id' => $id ),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s' ),
			array( '%d' )
		);

		if ( false === $updated ) {
			return new WP_Error(
				'update_failed',
				__( 'Failed to update question.', 'ai-chatbot' ),
				array( 'status' => 500 )
			);
		}

		return array(
			'id'              => $id,
			'question'        => $clean_q,
			'normalized_hash' => $hash,
			'draft_answer'    => null,
			'status'          => 'new',
		);
	}

	/**
	 * Load a single row.
	 *
	 * @param int $id Question record ID.
	 * @return ?array
	 */
	public static function get_question( int $id ): ?array {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE_NAME;

		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );

		return $row ?: null;
	}

	/**
	 * Save a corrected draft answer.
	 *
	 * This is the manual override path. Until 1.1.0 an administrator could only
	 * take an AI draft and approve it verbatim, write a replacement from
	 * scratch, or leave it alone, and the AI text was destroyed in the process.
	 *
	 * Two things are preserved rather than overwritten:
	 *
	 * - ai_draft_answer holds the original AI text, written once on the first
	 *   manual save. It powers the diff view in the editor and the revert
	 *   action, and it is what the accuracy report compares against.
	 * - edit_count records how many times the answer has been touched, so the
	 *   inbox can flag a question that keeps needing correction.
	 *
	 * The draft is not published here. Publication stays an explicit, separate
	 * action, because saving an edit and approving it to the knowledge base have
	 * very different consequences and should not share one button.
	 *
	 * @param int    $id      Question record ID.
	 * @param string $answer  Corrected answer text.
	 * @param bool   $regenerate Replace the draft with a fresh AI draft instead of storing the text.
	 * @param ?AIProviderInterface $provider Provider used when regenerating.
	 * @param ?string $api_key Key used when regenerating.
	 * @return array|WP_Error Updated row, or an error explaining the refusal.
	 */
	public static function save_draft( int $id, string $answer, bool $regenerate = false, ?AIProviderInterface $provider = null, ?string $api_key = null ) {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE_NAME;

		$item = self::get_question( $id );

		if ( ! $item ) {
			return new WP_Error(
				'not_found',
				__( 'Question not found.', 'ai-chatbot' ),
				array( 'status' => 404 )
			);
		}

		if ( 'approved' === $item['status'] ) {
			return new WP_Error(
				'question_already_approved',
				__( 'This question is already in the knowledge base. Edit the Q&A entry itself instead.', 'ai-chatbot' ),
				array( 'status' => 409 )
			);
		}

		if ( 'dismissed' === $item['status'] ) {
			return new WP_Error(
				'question_dismissed',
				__( 'This question was dismissed. Reopen it before editing its draft.', 'ai-chatbot' ),
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

			// Keep the very first AI draft as the comparison baseline. A second
			// regeneration overwriting it would make the revert target and the
			// accuracy report both measure against a moving reference.
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
				'status'          => 'draft_ready',
				'updated_at'      => current_time( 'mysql' ),
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
				'source_type'     => 'unanswered',
				'source_id'       => $id,
				'action'          => $regenerate ? 'regenerate' : 'edit',
				'question'        => (string) $item['question'],
				'logged_question' => (string) $item['question'],
				'ai_draft'        => $ai_draft ?? $previous,
				'edited_answer'   => $answer,
			)
		);

		return self::get_question( $id );
	}

	/**
	 * Restore the original AI draft, discarding the manual correction.
	 *
	 * @param int $id Question record ID.
	 * @return array|WP_Error
	 */
	public static function revert_draft( int $id ) {
		$item = self::get_question( $id );

		if ( ! $item ) {
			return new WP_Error(
				'not_found',
				__( 'Question not found.', 'ai-chatbot' ),
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
				'updated_at'      => current_time( 'mysql' ),
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
				'source_type'     => 'unanswered',
				'source_id'       => $id,
				'action'          => 'revert',
				'question'        => (string) $item['question'],
				'logged_question' => (string) $item['question'],
				'ai_draft'        => (string) $item['ai_draft_answer'],
				'edited_answer'   => (string) $item['ai_draft_answer'],
			)
		);

		return self::get_question( $id );
	}

	/**
	 * Reopen a dismissed question so its answer can be edited again.
	 *
	 * @param int $id Question record ID.
	 * @return array|WP_Error
	 */
	public static function reopen( int $id ) {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE_NAME;

		$item = self::get_question( $id );

		if ( ! $item ) {
			return new WP_Error(
				'not_found',
				__( 'Question not found.', 'ai-chatbot' ),
				array( 'status' => 404 )
			);
		}

		$updated = $wpdb->update(
			$table,
			array(
				'status'     => ! empty( $item['draft_answer'] ) ? 'draft_ready' : 'new',
				'updated_at' => current_time( 'mysql' ),
			),
			array( 'id' => $id ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		if ( false === $updated ) {
			return new WP_Error(
				'update_failed',
				__( 'Failed to reopen the question.', 'ai-chatbot' ),
				array( 'status' => 500 )
			);
		}

		return self::get_question( $id );
	}

	/**
	 * Pull the configured chat provider and key for draft generation.
	 *
	 * Kept here rather than in the REST controller because both the unanswered
	 * inbox and the scope tuning editor need it, and the settings-to-credentials
	 * plumbing is the part that is easy to get subtly wrong.
	 *
	 * @param int $agent_id Agent ID.
	 * @return array{provider: ?AIProviderInterface, key: ?string, model: string}
	 */
	public static function resolve_chat_credentials( int $agent_id = 1 ): array {
		$settings = \AIChatbot\Settings::get_all( $agent_id, false );

		$provider_id = $settings['providers']['chat_provider'] ?? 'openai';
		$model       = $settings['providers']['chat_model'] ?? 'gpt-4o-mini';

		$key_setting = $provider_id . '_api_key';
		$raw_key     = \AIChatbot\Security\Encryption::decrypt( $settings['providers'][ $key_setting ] ?? '' );

		if ( empty( $raw_key ) ) {
			return array(
				'provider' => null,
				'key'      => null,
				'model'    => $model,
			);
		}

		$provider = \AIChatbot\AI\ModelRegistry::get( $provider_id );

		if ( ! $provider instanceof AIProviderInterface ) {
			return array(
				'provider' => null,
				'key'      => null,
				'model'    => $model,
			);
		}

		return array(
			'provider' => $provider,
			'key'      => $raw_key,
			'model'    => $model,
		);
	}

	/**
	 * Count how often each visitor question was overridden by hand.
	 *
	 * A question that needed correcting two or more times is not a one-off model
	 * slip: either the knowledge base is missing the topic entirely, or the
	 * retrieval boost is pulling a misleading page chunk into the draft context.
	 *
	 * @param int $agent_id Agent ID.
	 * @return array Rows of id, question, edit_count, occurrences.
	 */
	public static function get_heavily_edited( int $agent_id = 1 ): array {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE_NAME;

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, question, edit_count, occurrences, draft_source, status
				 FROM {$table}
				 WHERE agent_id = %d AND edit_count >= 2
				 ORDER BY edit_count DESC, occurrences DESC
				 LIMIT 50",
				$agent_id
			),
			ARRAY_A
		) ?: array();
	}
}
