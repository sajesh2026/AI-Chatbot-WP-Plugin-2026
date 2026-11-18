<?php
/**
 * Refine RAG accuracy using feedback signals.
 *
 * @package AIChatbot\Analytics
 */

namespace AIChatbot\Analytics;

defined( 'ABSPATH' ) || exit;

/**
 * Class RagTuner.
 */
class RagTuner {

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
	 * Identify underperforming chunks based on negative feedback.
	 *
	 * @param int $agent_id Agent ID.
	 * @param int $days     Look-back window in days.
	 * @return array chunk_id => ['negative_count'=>int, 'positive_count'=>int, 'negative_ratio'=>float, 'impact_score'=>float]
	 */
	public function identify_underperforming_chunks( int $agent_id, int $days = 30 ): array {
		$negative_counts = $this->store->get_negative_chunk_counts( $agent_id, $days );

		if ( empty( $negative_counts ) ) {
			return array();
		}

		$scores = array();

		foreach ( $negative_counts as $chunk_id => $neg_count ) {
			$pos_count = $this->get_positive_count_for_chunk( (int) $chunk_id );
			$total = $neg_count + $pos_count;
			$scores[ $chunk_id ] = array(
				'negative_count'  => $neg_count,
				'positive_count'  => $pos_count,
				'negative_ratio'  => $total > 0 ? (float) ( $neg_count / $total ) : 1.0,
				'impact_score'    => $neg_count * ( 1 - ( $pos_count / max( 1, $total ) ) ),
			);
		}

		arsort( $scores );

		return $scores;
	}

	/**
	 * Compute retrieval weight adjustments for underperforming chunks.
	 *
	 * @param int $agent_id Agent ID.
	 * @return array chunk_id => ['action'=>'deprecate'|'demote', 'weight_multiplier'=>float]
	 */
	public function compute_retrieval_adjustments( int $agent_id ): array {
		$underperforming = $this->identify_underperforming_chunks( $agent_id );
		$adjustments = array();

		foreach ( $underperforming as $chunk_id => $data ) {
			if ( $data['negative_ratio'] > 0.7 && $data['negative_count'] >= 3 ) {
				$adjustments[ $chunk_id ] = array(
					'action'           => 'deprecate',
					'weight_multiplier'=> 0.3,
				);
			} elseif ( $data['negative_ratio'] > 0.5 ) {
				$adjustments[ $chunk_id ] = array(
					'action'           => 'demote',
					'weight_multiplier'=> 0.6,
				);
			}
		}

		return $adjustments;
	}

	/**
	 * Get positive feedback count for a chunk.
	 *
	 * @param int $chunk_id Chunk ID.
	 * @return int
	 */
	private function get_positive_count_for_chunk( int $chunk_id ): int {
		global $wpdb;

		$table = $this->store->table();

		$sql = $wpdb->prepare(
			"SELECT COUNT(*) FROM {$table}
			 WHERE rating = 'helpful'
			   AND JSON_EXTRACT(retrieved_chunks, '$[*].chunk_id') LIKE %s",
			'%' . $wpdb->esc_like( (string) $chunk_id ) . '%'
		);

		return (int) $wpdb->get_var( $sql );
	}
}
