<?php
/**
 * Identify systemic knowledge gaps from negative feedback.
 *
 * @package AIChatbot\Analytics
 */

namespace AIChatbot\Analytics;

defined( 'ABSPATH' ) || exit;

/**
 * Class GapDetector.
 */
class GapDetector {

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
	 * Detect systemic knowledge gaps from negative feedback.
	 *
	 * @param int $agent_id Agent ID.
	 * @param int $days     Look-back window in days.
	 * @return array Ranked gap clusters.
	 */
	public function detect_gaps( int $agent_id, int $days = 30 ): array {
		$negative = $this->store->get_negative_feedback( $agent_id, $days );

		if ( empty( $negative ) ) {
			return array();
		}

		$queries = array_column( $negative, 'query_text' );
		$clusters = $this->cluster_queries( $queries );
		$gaps = array();

		foreach ( $clusters as $cluster ) {
			$neg_count   = $cluster['count'];
			$escalations = $cluster['escalations'];
			$retries     = $cluster['retries'];
			$score       = ( $neg_count * 2 ) + ( $escalations * 3 ) + ( $retries * 1 );

			$gaps[] = array(
				'topic_label'     => $cluster['label'],
				'gap_score'       => $score,
				'negative_count'  => $neg_count,
				'escalation_rate' => $neg_count > 0 ? $escalations / $neg_count : 0,
				'sample_queries'  => array_slice( $cluster['samples'], 0, 5 ),
				'recommended_action' => $this->recommend_action( $score, $escalations, $neg_count ),
			);
		}

		usort( $gaps, fn( $a, $b ) => $b['gap_score'] <=> $a['gap_score'] );

		return $gaps;
	}

	/**
	 * Group queries by keyword overlap.
	 *
	 * @param array $queries Query texts.
	 * @return array Clusters.
	 */
	protected function cluster_queries( array $queries ): array {
		$clusters = array();
		$used = array();

		foreach ( $queries as $i => $query ) {
			if ( isset( $used[ $i ] ) ) {
				continue;
			}

			$cluster = array(
				'samples'  => array( $query ),
				'indices'  => array( $i ),
				'escalations' => 0,
				'retries'     => 0,
			);
			$used[ $i ] = true;

			foreach ( $queries as $j => $other ) {
				if ( $i === $j || isset( $used[ $j ] ) ) {
					continue;
				}

				$similarity = $this->jaccard_similarity(
					$this->tokenize( $query ),
					$this->tokenize( $other )
				);

				if ( $similarity > 0.3 ) {
					$cluster['samples'][] = $other;
					$cluster['indices'][] = $j;
					$used[ $j ] = true;
				}
			}

			$cluster['label'] = $this->summarize_topic( $cluster['samples'] );
			$cluster['count'] = count( $cluster['samples'] );
			$clusters[] = $cluster;
		}

		return $clusters;
	}

	/**
	 * Compute Jaccard similarity between two token sets.
	 *
	 * @param array $a Tokens.
	 * @param array $b Tokens.
	 * @return float
	 */
	protected function jaccard_similarity( array $a, array $b ): float {
		$intersection = count( array_intersect( $a, $b ) );
		$union = count( array_unique( array_merge( $a, $b ) ) );

		return $union > 0 ? (float) ( $intersection / $union ) : 0.0;
	}

	/**
	 * Tokenize a string into lowercase words.
	 *
	 * @param string $text Text.
	 * @return array
	 */
	protected function tokenize( string $text ): array {
		return array_filter(
			preg_split( '/[^a-z0-9]+/i', strtolower( $text ) ),
			fn( $t ) => strlen( $t ) > 2
		);
	}

	/**
	 * Derive a short topic label from sample queries.
	 *
	 * @param array $samples Query texts.
	 * @return string
	 */
	protected function summarize_topic( array $samples ): string {
		$word_counts = array();

		foreach ( $samples as $sample ) {
			$tokens = $this->tokenize( $sample );
			foreach ( $tokens as $token ) {
				$word_counts[ $token ] = ( $word_counts[ $token ] ?? 0 ) + 1;
			}
		}

		arsort( $word_counts );

		$top = array_slice( array_keys( $word_counts ), 0, 3, true );

		return implode( ' / ', $top ) ?: 'General';
	}

	/**
	 * Recommend an action based on gap severity.
	 *
	 * @param int $score        Gap score.
	 * @param int $escalations  Escalation count.
	 * @param int $neg_count    Negative feedback count.
	 * @return string
	 */
	protected function recommend_action( int $score, int $escalations, int $neg_count ): string {
		if ( $score > 20 || $escalations > 3 ) {
			return 'URGENT: Knowledge base update required';
		}

		if ( $score > 10 || $escalations > 0 ) {
			return 'Review and add missing content';
		}

		if ( $neg_count >= 3 ) {
			return 'Improve response confidence / add clarification';
		}

		return 'Monitor for trend changes';
	}
}
