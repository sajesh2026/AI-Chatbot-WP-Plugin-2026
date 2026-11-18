<?php
/**
 * Feedback REST Controller.
 *
 * @package AIChatbot\Rest
 */

namespace AIChatbot\Rest;

use WP_REST_Request;
use WP_REST_Response;
use AIChatbot\Knowledge\UnansweredInbox;
use AIChatbot\Analytics\FeedbackStore;
use AIChatbot\Analytics\GapDetector;
use AIChatbot\Analytics\RagTuner;
use AIChatbot\Analytics\ResponseImprover;

defined( 'ABSPATH' ) || exit;

/**
 * Class FeedbackController.
 */
class FeedbackController extends RestController {

	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/feedback',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'submit_feedback' ),
				'permission_callback' => array( $this, 'check_public_permission' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/feedback/analytics/gaps',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_knowledge_gaps' ),
				'permission_callback' => array( $this, 'check_admin_permission' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/feedback/analytics/chunks',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_underperforming_chunks' ),
				'permission_callback' => array( $this, 'check_admin_permission' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/feedback/analytics/metrics',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_dashboard_metrics' ),
				'permission_callback' => array( $this, 'check_admin_permission' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/feedback/analytics/improve',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'improve_negative_response' ),
				'permission_callback' => array( $this, 'check_admin_permission' ),
			)
		);
	}

	/**
	 * Handle feedback submission (thumbs up/down).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function submit_feedback( WP_REST_Request $request ): WP_REST_Response {
		global $wpdb;
		$table_msg  = $wpdb->prefix . 'ai_messages';
		$table_conv = $wpdb->prefix . 'ai_conversations';

		$params     = $request->get_json_params() ?: array();
		$message_id = (int) ( $params['message_id'] ?? 0 );
		$rating     = sanitize_text_field( $params['rating'] ?? '' );

		if ( $message_id <= 0 || ! in_array( $rating, array( 'helpful', 'unhelpful' ), true ) ) {
			return $this->error( __( 'Invalid feedback payload.', 'ai-chatbot' ), 'invalid_params', 400 );
		}

		// Update the message row for backward compatibility.
		$wpdb->update(
			$table_msg,
			array( 'feedback' => $rating ),
			array( 'id' => $message_id ),
			array( '%s' ),
			array( '%d' )
		);

		// Enrich and persist the feedback event.
		$enriched = $this->build_enriched_payload( $params, $rating, $message_id );

		if ( $enriched ) {
			$store = new FeedbackStore();
			$store->record( $enriched );
		}

		// If unhelpful, find previous user question and capture in Unanswered Inbox.
		if ( 'unhelpful' === $rating ) {
			$msg = $wpdb->get_row( $wpdb->prepare( "SELECT conversation_id FROM {$table_msg} WHERE id = %d", $message_id ), ARRAY_A );
			if ( $msg ) {
				$conv_id = (int) $msg['conversation_id'];
				$user_q  = $wpdb->get_var(
					$wpdb->prepare(
						"SELECT message FROM {$table_msg} WHERE conversation_id = %d AND role = 'user' AND id < %d ORDER BY id DESC LIMIT 1",
						$conv_id,
						$message_id
					)
				);
				$agent_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT agent_id FROM {$table_conv} WHERE id = %d", $conv_id ) ) ?: 1;

				if ( ! empty( $user_q ) ) {
					UnansweredInbox::log_question( $agent_id, $user_q );
				}
			}
		}

		return $this->success( array( 'message' => __( 'Thank you for your feedback.', 'ai-chatbot' ) ) );
	}

	/**
	 * Build enriched feedback payload from request params.
	 *
	 * @param array $params     Request params.
	 * @param string $rating    'helpful' or 'unhelpful'.
	 * @param int   $message_id Message ID.
	 * @return array|null
	 */
	protected function build_enriched_payload( array $params, string $rating, int $message_id ): ?array {
		global $wpdb;

		$table_msg  = $wpdb->prefix . 'ai_messages';
		$table_conv = $wpdb->prefix . 'ai_conversations';

		$msg = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT m.conversation_id, m.message, m.created_at, c.agent_id, c.session_id
				 FROM {$table_msg} m
				 JOIN {$table_conv} c ON c.id = m.conversation_id
				 WHERE m.id = %d
				 LIMIT 1",
				$message_id
			),
			ARRAY_A
		);

		if ( ! $msg ) {
			return null;
		}

		$response_text = $msg['message'];
		$agent_id      = (int) ( $msg['agent_id'] ?? 1 );
		$session_id    = sanitize_text_field( $msg['session_id'] ?? '' );

		$previous_user_q = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT message FROM {$table_msg}
				 WHERE conversation_id = %d AND role = 'user' AND id < %d
				 ORDER BY id DESC LIMIT 1",
				$msg['conversation_id'],
				$message_id
			)
		);

		$retry_count = 0;
		if ( ! empty( $previous_user_q ) ) {
			$retry_count = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$table_msg}
					 WHERE conversation_id = %d
					   AND role = 'user'
					   AND message = %s
					   AND id < %d",
					$msg['conversation_id'],
					$previous_user_q,
					$message_id
				)
			);
		}

		$follow_up_count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table_msg}
				 WHERE conversation_id = %d
				   AND id > %d
				   AND role = 'user'",
				$msg['conversation_id'],
				$message_id
			)
		);

		$response_time_ms = null;
		if ( ! empty( $params['response_time_ms'] ) ) {
			$response_time_ms = (int) $params['response_time_ms'];
		}

		$escalated_to_human = ! empty( $params['escalated_to_human'] ) ? 1 : 0;
		$user_correction    = ! empty( $params['user_correction'] ) ? sanitize_textarea_field( $params['user_correction'] ) : null;

		$retrieved_chunks = array();
		if ( ! empty( $params['retrieved_chunks'] ) && is_array( $params['retrieved_chunks'] ) ) {
			$retrieved_chunks = $params['retrieved_chunks'];
		}

		return array(
			'message_id'       => $message_id,
			'session_id'       => $session_id,
			'user_id'          => get_current_user_id() ?: null,
			'agent_id'         => $agent_id,
			'rating'           => $rating,
			'response_time_ms' => $response_time_ms,
			'follow_up_count'  => $follow_up_count,
			'escalated_to_human' => $escalated_to_human,
			'retry_count'      => $retry_count,
			'user_correction'  => $user_correction,
			'query_text'       => $previous_user_q ?: '',
			'response_text'    => $response_text,
			'retrieved_chunks' => $retrieved_chunks,
		);
	}

	/**
	 * Get knowledge gaps for an agent.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_knowledge_gaps( WP_REST_Request $request ): WP_REST_Response {
		$agent_id = (int) ( $request->get_param( 'agent_id' ) ?? 1 );
		$days     = (int) ( $request->get_param( 'days' ) ?? 30 );

		$detector = new GapDetector();
		$gaps = $detector->detect_gaps( $agent_id, $days );

		return $this->success( array( 'gaps' => $gaps ) );
	}

	/**
	 * Get underperforming chunks for an agent.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_underperforming_chunks( WP_REST_Request $request ): WP_REST_Response {
		$agent_id = (int) ( $request->get_param( 'agent_id' ) ?? 1 );
		$days     = (int) ( $request->get_param( 'days' ) ?? 30 );

		$tuner = new RagTuner();
		$chunks = $tuner->identify_underperforming_chunks( $agent_id, $days );

		return $this->success( array( 'chunks' => $chunks ) );
	}

	/**
	 * Get dashboard metrics combining all analytics.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_dashboard_metrics( WP_REST_Request $request ): WP_REST_Response {
		$agent_id = (int) ( $request->get_param( 'agent_id' ) ?? 1 );
		$days     = (int) ( $request->get_param( 'days' ) ?? 30 );

		$store = new FeedbackStore();

		$halfway = max( 1, (int) ( $days / 2 ) );
		$recent_rate     = $store->get_helpfulness_rate( $agent_id, $halfway );
		$previous_rate   = $store->get_helpfulness_rate( $agent_id, $days, $halfway );
		$delta           = $recent_rate - $previous_rate;

		$detector = new GapDetector();
		$tuner    = new RagTuner();

		return $this->success( array(
			'summary'             => array(
				'recent_rate'    => $recent_rate,
				'previous_rate'  => $previous_rate,
				'delta'          => $delta,
				'trend'          => $delta > 0.02 ? 'improving' : ( $delta < -0.02 ? 'declining' : 'stable' ),
			),
			'top_gaps'            => $detector->detect_gaps( $agent_id, $days ),
			'underperforming_chunks' => $tuner->identify_underperforming_chunks( $agent_id, $days ),
		) );
	}

	/**
	 * Trigger response improvement for a negatively-rated message.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function improve_negative_response( WP_REST_Request $request ): WP_REST_Response {
		$message_id = (int) $request->get_param( 'message_id' );

		if ( $message_id <= 0 ) {
			return $this->error( __( 'Invalid message ID.', 'ai-chatbot' ), 'invalid_params', 400 );
		}

		$improver = new ResponseImprover();
		$result = $improver->process_negative_feedback( $message_id );

		if ( ! $result ) {
			return $this->error( __( 'Unable to improve response.', 'ai-chatbot' ), 'improvement_failed', 500 );
		}

		return $this->success( $result );
	}
}
