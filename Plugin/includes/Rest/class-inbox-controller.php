<?php
/**
 * Unanswered Questions and Out of Scope Log REST Controller.
 *
 * @package AIChatbot\Rest
 */

namespace AIChatbot\Rest;

use WP_REST_Request;
use WP_REST_Response;
use WP_Error;
use AIChatbot\Settings;
use AIChatbot\Security\Encryption;
use AIChatbot\AI\ModelRegistry;
use AIChatbot\Knowledge\UnansweredInbox;
use AIChatbot\Knowledge\ScopeTuning;
use AIChatbot\Knowledge\AnswerOverrideLog;
use AIChatbot\Knowledge\DraftAccuracy;

defined( 'ABSPATH' ) || exit;

/**
 * Class InboxController.
 */
class InboxController extends RestController {

	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/unanswered',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_unanswered' ),
				'permission_callback' => array( $this, 'check_admin_permission' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/unanswered/(?P<id>\d+)/approve',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'approve_question' ),
				'permission_callback' => array( $this, 'check_admin_permission' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/unanswered/(?P<id>\d+)/edit',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'edit_question' ),
				'permission_callback' => array( $this, 'check_admin_permission' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/unanswered/(?P<id>\d+)/dismiss',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'dismiss_question' ),
				'permission_callback' => array( $this, 'check_admin_permission' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/unanswered/(?P<id>\d+)/delete',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'delete_question' ),
				'permission_callback' => array( $this, 'check_admin_permission' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/unanswered/delete-all',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'delete_all_questions' ),
				'permission_callback' => array( $this, 'check_admin_permission' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/out-of-scope',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_out_of_scope' ),
				'permission_callback' => array( $this, 'check_admin_permission' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/out-of-scope/(?P<id>\d+)/in-scope',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'mark_in_scope' ),
				'permission_callback' => array( $this, 'check_admin_permission' ),
			)
		);

		// --- Manual draft editing (1.1.0) ---------------------------------
		// Draft editing is split across four verbs rather than folded into the
		// approve endpoint. Saving a correction and publishing it are different
		// consequences and must not share one button.

		register_rest_route(
			self::NAMESPACE,
			'/unanswered/(?P<id>\d+)/draft',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'save_draft' ),
				'permission_callback' => array( $this, 'check_admin_permission' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/unanswered/(?P<id>\d+)/draft/regenerate',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'regenerate_draft' ),
				'permission_callback' => array( $this, 'check_admin_permission' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/unanswered/(?P<id>\d+)/draft/revert',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'revert_draft' ),
				'permission_callback' => array( $this, 'check_admin_permission' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/unanswered/(?P<id>\d+)/reopen',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'reopen_question' ),
				'permission_callback' => array( $this, 'check_admin_permission' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/out-of-scope/(?P<id>\d+)/draft',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'save_scope_draft' ),
				'permission_callback' => array( $this, 'check_admin_permission' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/out-of-scope/(?P<id>\d+)/draft/regenerate',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'regenerate_scope_draft' ),
				'permission_callback' => array( $this, 'check_admin_permission' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/out-of-scope/(?P<id>\d+)/draft/revert',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'revert_scope_draft' ),
				'permission_callback' => array( $this, 'check_admin_permission' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/out-of-scope/(?P<id>\d+)/rule',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'rule_scope' ),
				'permission_callback' => array( $this, 'check_admin_permission' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/out-of-scope/(?P<id>\d+)/reopen',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'reopen_scope_entry' ),
				'permission_callback' => array( $this, 'check_admin_permission' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/out-of-scope/(?P<id>\d+)/edit',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'edit_scope_question' ),
				'permission_callback' => array( $this, 'check_admin_permission' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/drafts/accuracy',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_draft_accuracy' ),
				'permission_callback' => array( $this, 'check_admin_permission' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/drafts/overrides',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_override_log' ),
				'permission_callback' => array( $this, 'check_admin_permission' ),
			)
		);
	}

	/**
	 * Translate a WP_Error from the domain layer into the standard envelope.
	 *
	 * The domain layer answers with WP_Error so refusal reasons carry an HTTP
	 * status; the admin client should not have to special-case one handler.
	 *
	 * @param WP_Error $error Domain error.
	 * @return WP_REST_Response
	 */
	protected function error_from( WP_Error $error ): WP_REST_Response {
		$data   = $error->get_error_data();
		$status = ( is_array( $data ) && isset( $data['status'] ) ) ? (int) $data['status'] : 400;

		return new WP_REST_Response(
			array(
				'success' => false,
				'code'    => $error->get_error_code(),
				'message' => $error->get_error_message(),
			),
			$status
		);
	}

	/**
	 * Resolve embeddings credentials for immediate indexing of a new Q&A.
	 *
	 * @param int $agent_id Agent ID.
	 * @return array{provider: ?AIProviderInterface, model: string, key: string}
	 */
	protected function resolve_embeddings( int $agent_id ): array {
		$settings    = Settings::get_all( $agent_id, false );
		$provider_id = $settings['providers']['embeddings_provider'] ?? 'openai';
		$model       = $settings['providers']['embeddings_model'] ?? 'text-embedding-3-small';
		$raw_key     = Encryption::decrypt( $settings['providers']['openai_api_key'] ?? '' );

		$provider = ModelRegistry::get( $provider_id );

		if ( ! $provider instanceof \AIChatbot\AI\AIProviderInterface ) {
			$provider = null;
		}

		return array(
			'provider' => $provider,
			'model'    => $model,
			'key'      => (string) $raw_key,
		);
	}

	/**
	 * Get unanswered questions.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_unanswered( WP_REST_Request $request ): WP_REST_Response {
		$agent_id = (int) ( $request->get_param( 'agent_id' ) ?: 1 );
		$status   = sanitize_text_field( $request->get_param( 'status' ) ?: 'all' );

		$items = UnansweredInbox::get_questions( $agent_id, $status );

		return $this->success( array( 'questions' => $items ) );
	}

	/**
	 * Approve question draft and create Q&A source.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function approve_question( WP_REST_Request $request ): WP_REST_Response {
		$id     = (int) $request->get_param( 'id' );
		$params = $request->get_json_params() ?: array();
		$answer = trim( $params['answer'] ?? '' );

		/*
		 * Approving without sending an answer is the "I reviewed the draft and
		 * it is good" action. Publishing the stored draft verbatim is what the
		 * button means when the editor is opened and closed without a change,
		 * so the stored text is the correct answer here rather than a surprise
		 * empty rejection.
		 */
		if ( '' === $answer ) {
			$row = UnansweredInbox::get_question( $id );

			if ( ! $row ) {
				return $this->error( __( 'Question not found.', 'ai-chatbot' ), 'not_found', 404 );
			}

			$answer = trim( (string) ( $row['draft_answer'] ?? '' ) );
		}

		if ( empty( $answer ) ) {
			return $this->error( __( 'Answer cannot be empty.', 'ai-chatbot' ), 'empty_answer', 400 );
		}

		$agent_id = (int) ( $params['agent_id'] ?? 1 );
		$emb      = $this->resolve_embeddings( $agent_id );

		$res = UnansweredInbox::approve( $id, $answer, $emb['provider'], $emb['model'], $emb['key'] );

		if ( ! $res ) {
			return $this->error( __( 'Failed to approve question.', 'ai-chatbot' ), 'approve_failed', 500 );
		}

		return $this->success( array( 'message' => __( 'Question approved and added to knowledge base as high-priority Q&A.', 'ai-chatbot' ) ) );
	}

	/**
	 * Correct the text of a logged question.
	 *
	 * Editing is not only a text swap. The deduplication hash is recomputed and
	 * the AI draft is discarded, because the draft answered the previous
	 * wording. UnansweredInbox::edit_question() owns that reasoning.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function edit_question( WP_REST_Request $request ): WP_REST_Response {
		$id     = (int) $request->get_param( 'id' );
		$params = $request->get_json_params() ?: array();
		$text   = trim( (string) ( $params['question'] ?? '' ) );

		if ( '' === $text ) {
			return $this->error( __( 'Question cannot be empty.', 'ai-chatbot' ), 'empty_question', 400 );
		}

		$result = UnansweredInbox::edit_question( $id, $text );

		/*
		 * The domain layer answers with WP_Error so the refusal reasons carry an
		 * HTTP status. Translated here into the same envelope the other handlers
		 * use, so the admin client handles every failure identically.
		 */
		if ( is_wp_error( $result ) ) {
			return $this->error_from( $result );
		}

		return $this->success(
			array(
				'message'  => __( 'Question updated. The AI draft was cleared because it answered the previous wording.', 'ai-chatbot' ),
				'question' => $result,
			)
		);
	}

	/**
	 * Dismiss question.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function dismiss_question( WP_REST_Request $request ): WP_REST_Response {
		$id  = (int) $request->get_param( 'id' );
		$res = UnansweredInbox::dismiss( $id );

		if ( ! $res ) {
			return $this->error( __( 'Failed to dismiss question.', 'ai-chatbot' ), 'dismiss_failed', 400 );
		}

		return $this->success( array( 'message' => __( 'Question dismissed.', 'ai-chatbot' ) ) );
	}

	/**
	 * Permanently delete a single logged question.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function delete_question( WP_REST_Request $request ): WP_REST_Response {
		$id  = (int) $request->get_param( 'id' );
		$res = UnansweredInbox::delete_question( $id );

		if ( ! $res ) {
			return $this->error( __( 'Failed to delete question.', 'ai-chatbot' ), 'delete_failed', 400 );
		}

		return $this->success(
			array(
				'message' => __( 'Question deleted.', 'ai-chatbot' ),
				'id'      => $id,
			)
		);
	}

	/**
	 * Permanently delete every logged question for an agent.
	 *
	 * Requires the explicit confirmation string "DELETE ALL" so a stray or
	 * replayed request cannot empty the inbox.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function delete_all_questions( WP_REST_Request $request ): WP_REST_Response {
		$agent_id  = (int) ( $request->get_param( 'agent_id' ) ?: 1 );
		$params    = $request->get_json_params() ?: array();
		$confirm   = (string) ( $params['confirm'] ?? $request->get_param( 'confirm' ) ?? '' );

		if ( 'DELETE ALL' !== strtoupper( trim( $confirm ) ) ) {
			return $this->error(
				__( 'Deleting every logged question requires confirm = "DELETE ALL".', 'ai-chatbot' ),
				'confirmation_required',
				400
			);
		}

		$deleted = UnansweredInbox::delete_all_questions( $agent_id );

		return $this->success(
			array(
				'deleted' => $deleted,
				'message' => sprintf(
					/* translators: %d: number of deleted questions. */
					__( 'Deleted %d logged question(s).', 'ai-chatbot' ),
					$deleted
				),
			)
		);
	}

	/**
	 * Get out of scope log questions.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_out_of_scope( WP_REST_Request $request ): WP_REST_Response {
		$agent_id = (int) ( $request->get_param( 'agent_id' ) ?: 1 );
		$status   = sanitize_key( (string) ( $request->get_param( 'status' ) ?: '' ) );

		/*
		 * The full row is returned rather than the previous four columns because
		 * the editor needs draft_answer, ai_draft_answer and draft_source to
		 * render the diff and decide which actions to offer. Rows still waiting
		 * on a ruling are returned by default; 'all' returns every ruling too.
		 */
		$items = ScopeTuning::get_entries( $agent_id, $status );

		return $this->success( array( 'items' => $items ) );
	}

	/**
	 * Mark an out of scope question as in scope (converts to Q&A or adds to scope).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function mark_in_scope( WP_REST_Request $request ): WP_REST_Response {
		$id     = (int) $request->get_param( 'id' );
		$params = $request->get_json_params() ?: array();

		/*
		 * Ruling a question in scope publishes the answer as a curated Q&A
		 * source and indexes it immediately, so the correction takes effect for
		 * the very next visitor. The old handler created the source but left it
		 * unindexed, which meant a promoted answer was invisible to retrieval
		 * until someone manually reindexed the knowledge base.
		 *
		 * The row is retained with status 'in_scope' instead of deleted, so the
		 * decision stays auditable and the accuracy report can measure how often
		 * the guardrails deflect a question that was actually in scope.
		 */
		$row = ScopeTuning::get_entry( $id );

		if ( ! $row ) {
			return $this->error( __( 'Item not found.', 'ai-chatbot' ), 'not_found', 404 );
		}

		$answer = trim( (string) ( $params['answer'] ?? '' ) );

		if ( '' === $answer ) {
			$answer = trim( (string) ( $row['draft_answer'] ?? '' ) );
		}

		if ( '' === $answer ) {
			return $this->error(
				__( 'Write an answer before ruling this question in scope.', 'ai-chatbot' ),
				'empty_answer',
				400
			);
		}

		$agent_id = (int) $row['agent_id'];
		$qa_id    = ScopeTuning::promote_to_qa( $row, $answer );

		if ( $qa_id <= 0 ) {
			return $this->error( __( 'Failed to create the Q&A entry.', 'ai-chatbot' ), 'qa_failed', 500 );
		}

		ScopeTuning::attach_qa_id( $id, $qa_id );

		global $wpdb;
		$wpdb->update(
			$wpdb->prefix . ScopeTuning::TABLE_NAME,
			array(
				'status'     => 'in_scope',
				'updated_at' => current_time( 'mysql' ),
			),
			array( 'id' => $id ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		$emb = $this->resolve_embeddings( $agent_id );

		if ( $emb['provider'] && ! empty( $emb['model'] ) && ! empty( $emb['key'] ) ) {
			\AIChatbot\Knowledge\Indexer::index_source( $qa_id, $emb['provider'], $emb['model'], $emb['key'] );
		}

		AnswerOverrideLog::record(
			array(
				'agent_id'        => $agent_id,
				'source_type'     => 'out_of_scope',
				'source_id'       => $id,
				'action'          => 'in_scope',
				'question'        => (string) $row['question'],
				'logged_question' => (string) $row['question'],
				'ai_draft'        => $row['ai_draft_answer'] ?? null,
				'edited_answer'   => $answer,
				'resolved_qa_id'  => $qa_id,
			)
		);

		return $this->success(
			array(
				'message' => __( 'Item ruled in scope, published to the Q&A knowledge base and indexed.', 'ai-chatbot' ),
				'item'    => ScopeTuning::get_entry( $id ),
			)
		);
	}

	/**
	 * Save a corrected answer for an unanswered question draft.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function save_draft( WP_REST_Request $request ): WP_REST_Response {
		$id     = (int) $request->get_param( 'id' );
		$params = $request->get_json_params() ?: array();
		$answer = trim( (string) ( $params['answer'] ?? '' ) );

		if ( '' === $answer ) {
			return $this->error( __( 'Answer cannot be empty.', 'ai-chatbot' ), 'empty_answer', 400 );
		}

		$result = UnansweredInbox::save_draft( $id, $answer );

		if ( is_wp_error( $result ) ) {
			return $this->error_from( $result );
		}

		return $this->success(
			array(
				'message' => __( 'Draft saved. Approve it separately to publish it to the knowledge base.', 'ai-chatbot' ),
				'item'    => $result,
			)
		);
	}

	/**
	 * Replace a draft with a freshly generated AI draft.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function regenerate_draft( WP_REST_Request $request ): WP_REST_Response {
		$id      = (int) $request->get_param( 'id' );
		$creds   = UnansweredInbox::resolve_chat_credentials( (int) ( $this->agent_id_param( $request ) ) );
		$result  = UnansweredInbox::save_draft( $id, '', true, $creds['provider'], $creds['key'] );

		if ( is_wp_error( $result ) ) {
			return $this->error_from( $result );
		}

		return $this->success(
			array(
				'message' => __( 'New AI draft generated. The first draft is kept for comparison.', 'ai-chatbot' ),
				'item'    => $result,
			)
		);
	}

	/**
	 * Restore the original AI draft.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function revert_draft( WP_REST_Request $request ): WP_REST_Response {
		$result = UnansweredInbox::revert_draft( (int) $request->get_param( 'id' ) );

		if ( is_wp_error( $result ) ) {
			return $this->error_from( $result );
		}

		return $this->success(
			array(
				'message' => __( 'Restored the original AI draft.', 'ai-chatbot' ),
				'item'    => $result,
			)
		);
	}

	/**
	 * Reopen a dismissed question.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function reopen_question( WP_REST_Request $request ): WP_REST_Response {
		$result = UnansweredInbox::reopen( (int) $request->get_param( 'id' ) );

		if ( is_wp_error( $result ) ) {
			return $this->error_from( $result );
		}

		return $this->success(
			array(
				'message' => __( 'Question reopened for editing.', 'ai-chatbot' ),
				'item'    => $result,
			)
		);
	}

	/**
	 * Save a corrected answer for a deflected (out of scope) question.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function save_scope_draft( WP_REST_Request $request ): WP_REST_Response {
		$id     = (int) $request->get_param( 'id' );
		$params = $request->get_json_params() ?: array();
		$answer = trim( (string) ( $params['answer'] ?? '' ) );

		if ( '' === $answer ) {
			return $this->error( __( 'Answer cannot be empty.', 'ai-chatbot' ), 'empty_answer', 400 );
		}

		$result = ScopeTuning::save_draft( $id, $answer );

		if ( is_wp_error( $result ) ) {
			return $this->error_from( $result );
		}

		return $this->success(
			array(
				'message' => __( 'Draft saved. Rule the question in scope to publish it.', 'ai-chatbot' ),
				'item'    => $result,
			)
		);
	}

	/**
	 * Regenerate the AI holding reply for a deflected question.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function regenerate_scope_draft( WP_REST_Request $request ): WP_REST_Response {
		$id      = (int) $request->get_param( 'id' );
		$creds   = UnansweredInbox::resolve_chat_credentials( $this->agent_id_param( $request ) );
		$result  = ScopeTuning::save_draft( $id, '', true, $creds['provider'], $creds['key'] );

		if ( is_wp_error( $result ) ) {
			return $this->error_from( $result );
		}

		return $this->success(
			array(
				'message' => __( 'New holding reply generated.', 'ai-chatbot' ),
				'item'    => $result,
			)
		);
	}

	/**
	 * Restore the original AI holding reply for a deflected question.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function revert_scope_draft( WP_REST_Request $request ): WP_REST_Response {
		$result = ScopeTuning::revert_draft( (int) $request->get_param( 'id' ) );

		if ( is_wp_error( $result ) ) {
			return $this->error_from( $result );
		}

		return $this->success(
			array(
				'message' => __( 'Restored the original AI draft.', 'ai-chatbot' ),
				'item'    => $result,
			)
		);
	}

	/**
	 * Record a scope ruling without publishing a Q&A entry.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function rule_scope( WP_REST_Request $request ): WP_REST_Response {
		$id     = (int) $request->get_param( 'id' );
		$params = $request->get_json_params() ?: array();
		$ruling = sanitize_key( (string) ( $params['ruling'] ?? '' ) );

		if ( 'out_of_scope' !== $ruling ) {
			return $this->error(
				__( 'Ruling must be "in_scope" or "out_of_scope". Use the in-scope action to publish an answer.', 'ai-chatbot' ),
				'invalid_ruling',
				400
			);
		}

		$result = ScopeTuning::rule_out_of_scope( $id );

		if ( is_wp_error( $result ) ) {
			return $this->error_from( $result );
		}

		return $this->success(
			array(
				'message' => __( 'Question confirmed out of scope. The chatbot will keep deflecting it.', 'ai-chatbot' ),
				'item'    => $result,
			)
		);
	}

	/**
	 * Reopen a ruled out of scope entry.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function reopen_scope_entry( WP_REST_Request $request ): WP_REST_Response {
		$result = ScopeTuning::reopen( (int) $request->get_param( 'id' ) );

		if ( is_wp_error( $result ) ) {
			return $this->error_from( $result );
		}

		return $this->success(
			array(
				'message' => __( 'Entry reopened.', 'ai-chatbot' ),
				'item'    => $result,
			)
		);
	}

	/**
	 * Correct the wording of a deflected question.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function edit_scope_question( WP_REST_Request $request ): WP_REST_Response {
		$params = $request->get_json_params() ?: array();
		$text   = trim( (string) ( $params['question'] ?? '' ) );

		if ( '' === $text ) {
			return $this->error( __( 'Question cannot be empty.', 'ai-chatbot' ), 'empty_question', 400 );
		}

		$result = ScopeTuning::edit_question( (int) $request->get_param( 'id' ), $text );

		if ( is_wp_error( $result ) ) {
			return $this->error_from( $result );
		}

		return $this->success(
			array(
				'message' => __( 'Question updated and the AI draft cleared.', 'ai-chatbot' ),
				'item'    => $result,
			)
		);
	}

	/**
	 * Accuracy report for manual draft edits.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_draft_accuracy( WP_REST_Request $request ): WP_REST_Response {
		$agent_id = $this->agent_id_param( $request );
		$stats    = DraftAccuracy::stats( $agent_id );

		return $this->success(
			array(
				'stats'    => $stats,
				'insights' => DraftAccuracy::insights( $stats ),
			)
		);
	}

	/**
	 * Raw override audit entries.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_override_log( WP_REST_Request $request ): WP_REST_Response {
		$agent_id    = $this->agent_id_param( $request );
		$source_type = sanitize_key( (string) ( $request->get_param( 'source_type' ) ?: '' ) );
		$limit       = min( 200, max( 1, (int) ( $request->get_param( 'limit' ) ?: 50 ) ) );

		return $this->success(
			array(
				'entries' => AnswerOverrideLog::get_entries( $agent_id, $source_type, $limit ),
			)
		);
	}

	/**
	 * Read the agent ID, falling back to the single-agent default.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return int
	 */
	protected function agent_id_param( WP_REST_Request $request ): int {
		return (int) ( $request->get_param( 'agent_id' ) ?: 1 );
	}
}
