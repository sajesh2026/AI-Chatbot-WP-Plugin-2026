<?php
/**
 * Data access layer for enriched feedback events.
 *
 * @package AIChatbot\Analytics
 */

namespace AIChatbot\Analytics;

use AIChatbot\Database\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Class FeedbackStore.
 */
class FeedbackStore {

	/**
	 * Table name (with prefix).
	 */
	private function table(): string {
		global $wpdb;
		return $wpdb->prefix . Schema::FEEDBACK_TABLE;
	}

	/**
	 * Record a new feedback event with enriched context.
	 *
	 * @param array $payload Feedback data.
	 * @return int|false Inserted ID or false on failure.
	 */
	public function record( array $payload ) {
		global $wpdb;

		$defaults = array(
			'message_id'      => 0,
			'session_id'      => '',
			'user_id'         => null,
			'agent_id'        => 1,
			'rating'          => 'helpful',
			'response_time_ms'=> null,
			'follow_up_count' => 0,
			'escalated_to_human' => 0,
			'retry_count'     => 0,
			'user_correction' => null,
			'query_text'      => '',
			'response_text'   => '',
			'retrieved_chunks'=> null,
		);

		$data = wp_parse_args( $payload, $defaults );
		$data['rating']            = in_array( $data['rating'], array( 'helpful', 'unhelpful' ), true ) ? $data['rating'] : 'helpful';
		$data['escalated_to_human'] = (int) ( ! empty( $data['escalated_to_human'] ) );
		$data['follow_up_count']   = max( 0, (int) $data['follow_up_count'] );
		$data['retry_count']       = max( 0, (int) $data['retry_count'] );
		$data['agent_id']          = max( 1, (int) $data['agent_id'] );

		$format = array(
			'%d', // message_id
			'%s', // session_id
			'%d', // user_id
			'%d', // agent_id
			'%s', // rating
			'%d', // response_time_ms
			'%d', // follow_up_count
			'%d', // escalated_to_human
			'%d', // retry_count
			'%s', // user_correction
			'%s', // query_text
			'%s', // response_text
			'%s', // retrieved_chunks
		);

		$values = array(
			$data['message_id'],
			$data['session_id'],
			$data['user_id'],
			$data['agent_id'],
			$data['rating'],
			$data['response_time_ms'],
			$data['follow_up_count'],
			$data['escalated_to_human'],
			$data['retry_count'],
			$data['user_correction'],
			$data['query_text'],
			$data['response_text'],
			$data['retrieved_chunks'] ? wp_json_encode( $data['retrieved_chunks'] ) : null,
		);

		$wpdb->insert( $this->table(), $values, $format );

		return (int) $wpdb->insert_id;
	}

	/**
	 * Get negative feedback for an agent within a look-back window.
	 *
	 * @param int $agent_id Agent ID.
	 * @param int $days     Look-back window in days.
	 * @param int $limit    Max rows to return.
	 * @return array
	 */
	public function get_negative_feedback( int $agent_id, int $days = 30, int $limit = 500 ): array {
		global $wpdb;

		$sql = $wpdb->prepare(
			"SELECT id, message_id, session_id, user_id, agent_id, rating,
					response_time_ms, follow_up_count, escalated_to_human,
					retry_count, user_correction, query_text, response_text,
					retrieved_chunks, created_at
			 FROM {$this->table()}
			 WHERE agent_id = %d
			   AND rating = 'unhelpful'
			   AND created_at >= DATE_SUB(NOW(), INTERVAL %d DAY)
			 ORDER BY created_at DESC
			 LIMIT %d",
			$agent_id,
			$days,
			$limit
		);

		return $wpdb->get_results( $sql, ARRAY_A );
	}

	/**
	 * Get positive feedback for an agent within a look-back window.
	 *
	 * @param int $agent_id Agent ID.
	 * @param int $days     Look-back window in days.
	 * @param int $limit    Max rows to return.
	 * @return array
	 */
	public function get_positive_feedback( int $agent_id, int $days = 30, int $limit = 500 ): array {
		global $wpdb;

		$sql = $wpdb->prepare(
			"SELECT id, message_id, session_id, user_id, agent_id, rating,
					response_time_ms, follow_up_count, escalated_to_human,
					retry_count, user_correction, query_text, response_text,
					retrieved_chunks, created_at
			 FROM {$this->table()}
			 WHERE agent_id = %d
			   AND rating = 'helpful'
			   AND created_at >= DATE_SUB(NOW(), INTERVAL %d DAY)
			 ORDER BY created_at DESC
			 LIMIT %d",
			$agent_id,
			$days,
			$limit
		);

		return $wpdb->get_results( $sql, ARRAY_A );
	}

	/**
	 * Get one feedback row by message id.
	 *
	 * @param int $message_id Message ID.
	 * @return array|null
	 */
	public function get_by_message_id( int $message_id ): ?array {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$this->table()} WHERE message_id = %d LIMIT 1",
				$message_id
			),
			ARRAY_A
		);

		return $row ?: null;
	}

	/**
	 * Get recent negative feedback for a session.
	 *
	 * @param string $session_id Session ID.
	 * @param int    $days       Look-back window.
	 * @return array
	 */
	public function get_recent_negative_for_session( string $session_id, int $days = 7 ): array {
		global $wpdb;

		$sql = $wpdb->prepare(
			"SELECT id, message_id, query_text, response_text, user_correction, created_at
			 FROM {$this->table()}
			 WHERE session_id = %s
			   AND rating = 'unhelpful'
			   AND created_at >= DATE_SUB(NOW(), INTERVAL %d DAY)
			 ORDER BY created_at DESC",
			$session_id,
			$days
		);

		return $wpdb->get_results( $sql, ARRAY_A );
	}

	/**
	 * Get chunk ids retrieved for negatively-rated messages.
	 *
	 * @param int $agent_id Agent ID.
	 * @param int $days     Look-back window.
	 * @return array chunk_id => count
	 */
	public function get_negative_chunk_counts( int $agent_id, int $days = 30 ): array {
		global $wpdb;

		$sql = $wpdb->prepare(
			"SELECT retrieved_chunks
			 FROM {$this->table()}
			 WHERE agent_id = %d
			   AND rating = 'unhelpful'
			   AND created_at >= DATE_SUB(NOW(), INTERVAL %d DAY)
			   AND retrieved_chunks IS NOT NULL",
			$agent_id,
			$days
		);

		$rows = $wpdb->get_results( $sql, ARRAY_A );
		$counts = array();

		foreach ( $rows as $row ) {
			$chunks = json_decode( $row['retrieved_chunks'], true );
			if ( ! is_array( $chunks ) ) {
				continue;
			}

			foreach ( $chunks as $chunk ) {
				$id = $chunk['chunk_id'] ?? null;
				if ( $id ) {
					$counts[ $id ] = ( $counts[ $id ] ?? 0 ) + 1;
				}
			}
		}

		return $counts;
	}

	/**
	 * Compute the helpfulness rate for an agent over a window.
	 *
	 * @param int $agent_id Agent ID.
	 * @param int $days     Window length in days.
	 * @param int $offset   Days to skip from now (for trend comparison).
	 * @return float Rate between 0 and 1.
	 */
	public function get_helpfulness_rate( int $agent_id, int $days = 30, int $offset = 0 ): float {
		global $wpdb;

		$offset_clause = $offset > 0 ? $wpdb->prepare( ' AND created_at <= DATE_SUB(NOW(), INTERVAL %d DAY)', $offset ) : '';

		$helpful = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$this->table()}
				 WHERE agent_id = %d
				   AND rating = 'helpful'
				   AND created_at >= DATE_SUB(NOW(), INTERVAL %d DAY)
				 {$offset_clause}",
				$agent_id,
				$days
			)
		);

		$total = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$this->table()}
				 WHERE agent_id = %d
				   AND created_at >= DATE_SUB(NOW(), INTERVAL %d DAY)
				 {$offset_clause}",
				$agent_id,
				$days
			)
		);

		return $total > 0 ? (float) ( $helpful / $total ) : 0.0;
	}
}
