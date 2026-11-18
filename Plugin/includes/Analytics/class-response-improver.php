<?php
/**
 * Iteratively improve and re-generate negatively-rated responses.
 *
 * @package AIChatbot\Analytics
 */

namespace AIChatbot\Analytics;

use AIChatbot\Knowledge\UnansweredInbox;

defined( 'ABSPATH' ) || exit;

/**
 * Class ResponseImprover.
 */
class ResponseImprover {

	/**
	 * Feedback store instance.
	 *
	 * @var FeedbackStore
	 */
	private FeedbackStore $store;

	/**
	 * Constructor.
	 *
	 * @param FeedbackStore|null $store Optional store override.
	 */
	public function __construct( ?FeedbackStore $store = null ) {
		$this->store = $store ?? new FeedbackStore();
	}

	/**
	 * Process a negatively-rated response through the correction pipeline.
	 *
	 * @param int $message_id Message ID.
	 * @return array|null Result with candidate_id, failure_mode, confidence.
	 */
	public function process_negative_feedback( int $message_id ): ?array {
		$feedback = $this->store->get_by_message_id( $message_id );

		if ( ! $feedback ) {
			return null;
		}

		$analysis = $this->analyze_failure( $feedback );
		$improved = $this->generate_improved_response( $feedback, $analysis );

		if ( ! $improved ) {
			return null;
		}

		return array(
			'failure_mode'           => $analysis['mode'],
			'improvement_confidence' => $improved['confidence'],
			'improved_response'      => $improved['text'],
		);
	}

	/**
	 * Classify the failure mode of a negative response.
	 *
	 * @param array $feedback Feedback row.
	 * @return array ['mode'=>string, 'evidence'=>array]
	 */
	protected function analyze_failure( array $feedback ): array {
		$mode = 'unknown';
		$evidence = array();

		if ( ! empty( $feedback['escalated_to_human'] ) ) {
			$mode = 'insufficient_answer';
			$evidence[] = 'User escalated to human support';
		}

		if ( (int) $feedback['retry_count'] > 0 ) {
			$mode = 'misunderstood_query';
			$evidence[] = sprintf( 'User re-asked %d times', (int) $feedback['retry_count'] );
		}

		if ( ! empty( $feedback['user_correction'] ) ) {
			$mode = 'misinterpreted_intent';
			$evidence[] = 'User provided explicit correction';
		}

		$chunks = json_decode( $feedback['retrieved_chunks'] ?? '[]', true );
		if ( empty( $chunks ) ) {
			$mode = 'no_relevant_content';
			$evidence[] = 'No chunks were retrieved';
		} elseif ( count( $chunks ) < 2 ) {
			$evidence[] = sprintf( 'Only %d chunk(s) retrieved', count( $chunks ) );
		}

		return array(
			'mode'     => $mode,
			'evidence' => $evidence,
		);
	}

	/**
	 * Generate an improved response using the configured LLM.
	 *
	 * @param array $feedback Feedback row.
	 * @param array $analysis Failure analysis.
	 * @return array|null ['text'=>string, 'confidence'=>float, 'failure_mode'=>string]
	 */
	protected function generate_improved_response( array $feedback, array $analysis ): ?array {
		$prompt = $this->build_improvement_prompt( $feedback, $analysis );

		/*
		 * Chat and embeddings are separate concerns, so the improvement
		 * prompt runs through the configured chat provider. Resolving
		 * the credentials the same way the unanswered inbox does keeps
		 * the model and key in sync with what the administrator chose,
		 * and works for every registered provider instead of one.
		 */
		$credentials = UnansweredInbox::resolve_chat_credentials( (int) ( $feedback['agent_id'] ?? 1 ) );

		if ( ! $credentials['provider'] || empty( $credentials['key'] ) ) {
			return null;
		}

		$res = $credentials['provider']->chat(
			array(
				array(
					'role'    => 'system',
					'content' => 'You improve AI chatbot responses that received negative feedback. Produce the improved response only.',
				),
				array( 'role' => 'user', 'content' => $prompt ),
			),
			array(
				'model'       => $credentials['model'],
				'temperature' => 0.4,
				'max_tokens'  => 1000,
			),
			$credentials['key']
		);

		$text = ! empty( $res['content'] ) ? trim( $res['content'] ) : '';

		if ( '' === $text ) {
			return null;
		}

		return array(
			'text'         => $text,
			'confidence'   => $this->assess_confidence( $text, $feedback ),
			'failure_mode' => $analysis['mode'],
		);
	}

	/**
	 * Build a targeted prompt based on the failure mode.
	 *
	 * @param array $feedback Feedback row.
	 * @param array $analysis Failure analysis.
	 * @return string
	 */
	protected function build_improvement_prompt( array $feedback, array $analysis ): string {
		$prompt  = "You are improving an AI chatbot response that received negative feedback.\n\n";
		$prompt .= "Original user query: {$feedback['query_text']}\n";
		$prompt .= "Original response: {$feedback['response_text']}\n";

		if ( ! empty( $feedback['user_correction'] ) ) {
			$prompt .= "User correction: {$feedback['user_correction']}\n";
		}

		$prompt .= "\nFailure mode: {$analysis['mode']}\n";

		switch ( $analysis['mode'] ) {
			case 'insufficient_answer':
				$prompt .= "\nProvide a more complete, actionable answer. Include specific steps and examples.\n";
				break;
			case 'misunderstood_query':
				$prompt .= "\nRe-interpret the user's intent. Consider alternative meanings and address the most likely one.\n";
				break;
			case 'misinterpreted_intent':
				$prompt .= "\nAcknowledge the correction and provide the answer the user actually wanted.\n";
				break;
			case 'no_relevant_content':
				$prompt .= "\nBe honest about limitations and offer to connect with a human if needed.\n";
				break;
		}

		$prompt .= "\nImproved response:";

		return $prompt;
	}

	/**
	 * Assess confidence in the improved response.
	 *
	 * @param string $text      Improved text.
	 * @param array  $feedback  Original feedback.
	 * @return float
	 */
	protected function assess_confidence( string $text, array $feedback ): float {
		$length_factor = min( 1.0, max( 0.2, strlen( $text ) / 500 ) );

		$mode_factor = 0.5;
		switch ( $this->analyze_failure( $feedback )['mode'] ) {
			case 'no_relevant_content':
				$mode_factor = 0.3;
				break;
			case 'misunderstood_query':
				$mode_factor = 0.7;
				break;
			case 'misinterpreted_intent':
				$mode_factor = 0.8;
				break;
		}

		return ( $length_factor + $mode_factor ) / 2;
	}

	/**
	 * Store an improved response candidate.
	 *
	 * @param int   $message_id Original message ID.
	 * @param array $improved   Improved response data.
	 * @param array $analysis   Failure analysis.
	 * @return int Candidate ID.
	 */
	private function store_candidate( int $message_id, array $improved, array $analysis ): int {
		global $wpdb;

		$table = $wpdb->prefix . Schema::FEEDBACK_TABLE;

		$wpdb->insert(
			$table,
			array(
				'message_id'     => $message_id,
				'session_id'     => '',
				'agent_id'       => 1,
				'rating'         => 'helpful',
				'response_text'  => $improved['text'],
				'user_correction' => 'improved:' . $analysis['mode'],
				'query_text'     => '',
			),
			array( '%d', '%s', '%d', '%s', '%s', '%s', '%s' )
		);

		return (int) $wpdb->insert_id;
	}
}
