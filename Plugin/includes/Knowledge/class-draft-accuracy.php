<?php
/**
 * Draft Accuracy Report.
 *
 * @package AIChatbot\Knowledge
 */

namespace AIChatbot\Knowledge;

defined( 'ABSPATH' ) || exit;

/**
 * Class DraftAccuracy.
 *
 * Answers the question "did editing the AI drafts actually make the chatbot
 * better?" using the ai_answer_overrides audit log.
 *
 * The plugin has no model training step and no fine-tuning. An edit changes
 * future behaviour through exactly one channel: the corrected text is
 * published into ai_knowledge_sources as a curated Q&A chunk, which retrieval
 * then surfaces with a 1.25x score boost. So the causal chain is
 *
 *     edit draft -> approve -> Q&A chunk -> higher retrieval score
 *                -> more likely to be cited in an answer
 *
 * This class measures the two ends of that chain and leaves the middle to the
 * existing relevance-floor and thumbs feedback signals:
 *
 * - Correction rate: of all published answers, how many were human-corrected
 *   rather than accepted as drafted. A high rate means the AI drafts are weak
 *   and the remaining prompt work belongs there, not in more manual editing.
 * - Mean divergence: how far the human answer sat from the AI draft. Low means
 *   the model was basically right. High means it was confidently wrong, which
 *   is a grounding problem worth fixing in the knowledge base.
 * - Scope yield: of the questions guardrails deflected, how many were ruled
 *   back in scope. A high yield means the scope classifier is too aggressive
 *   for real traffic, not that the classifier is broken.
 */
class DraftAccuracy {

	/**
	 * Similarity below which a correction counts as a rewrite.
	 *
	 * Chosen so that reformatting, added source URLs and a tightened opening
	 * sentence still read as "accepted with edits", while an answer that swaps
	 * the substance does not.
	 */
	public const MAJOR_CORRECTION_THRESHOLD = 0.5;

	/**
	 * Build the accuracy summary for an agent.
	 *
	 * @param int $agent_id Agent ID.
	 * @return array
	 */
	public static function stats( int $agent_id = 1 ): array {
		global $wpdb;

		$table = $wpdb->prefix . AnswerOverrideLog::TABLE_NAME;

		$totals = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT
					COUNT(*) AS total,
					SUM(CASE WHEN action = %s THEN 1 ELSE 0 END) AS approvals,
					SUM(CASE WHEN action = %s THEN 1 ELSE 0 END) AS edits,
					SUM(CASE WHEN action = %s THEN 1 ELSE 0 END) AS regenerations,
					AVG(CASE WHEN action = %s AND ai_similarity IS NOT NULL THEN ai_similarity END) AS mean_similarity,
					SUM(CASE WHEN action = %s AND ai_similarity IS NOT NULL AND ai_similarity < %f THEN 1 ELSE 0 END) AS major_corrections
				 FROM {$table}
				 WHERE agent_id = %d",
				'approve',
				'edit',
				'regenerate',
				'approve',
				'approve',
				self::MAJOR_CORRECTION_THRESHOLD,
				$agent_id
			),
			ARRAY_A
		) ?: array();

		$scope = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT
					COUNT(*) AS ruled_in,
					SUM(CASE WHEN occurrences > 1 THEN 1 ELSE 0 END) AS repeated_in
				 FROM " . $wpdb->prefix . ScopeTuning::TABLE_NAME . "
				 WHERE agent_id = %d AND status = %s",
				$agent_id,
				'in_scope'
			),
			ARRAY_A
		) ?: array();

		$total_deflected = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM " . $wpdb->prefix . ScopeTuning::TABLE_NAME . " WHERE agent_id = %d",
				$agent_id
			)
		);

		$approvals = (int) ( $totals['approvals'] ?? 0 );
		$scope_in  = (int) ( $scope['ruled_in'] ?? 0 );

		return array(
			'overrides_logged'   => (int) ( $totals['total'] ?? 0 ),
			'published_answers'  => $approvals,
			'manual_corrections' => (int) ( $totals['edits'] ?? 0 ),
			'regenerations'      => (int) ( $totals['regenerations'] ?? 0 ),
			'mean_ai_similarity' => null === ( $totals['mean_similarity'] ?? null )
				? null
				: round( (float) $totals['mean_similarity'], 4 ),
			'major_corrections'  => (int) ( $totals['major_corrections'] ?? 0 ),
			'correction_rate'    => $approvals > 0
				? round( (int) ( $totals['major_corrections'] ?? 0 ) / $approvals, 4 )
				: null,
			'scope_deflected'    => $total_deflected,
			'scope_ruled_in'     => $scope_in,
			'scope_yield'        => $total_deflected > 0 ? round( $scope_in / $total_deflected, 4 ) : null,
			'heavily_edited'     => UnansweredInbox::get_heavily_edited( $agent_id ),
		);
	}

	/**
	 * Render the stats into a short, plain-language reading for the admin.
	 *
	 * Deliberately not phrased as a score. The plugin cannot verify correctness
	 * of an answer, only that a human changed it, so any single percentage here
	 * would overclaim.
	 *
	 * @param array $stats Output of stats().
	 * @return array List of {tone, text}.
	 */
	public static function insights( array $stats ): array {
		$insights = array();

		if ( empty( $stats['published_answers'] ) ) {
			$insights[] = array(
				'tone' => 'neutral',
				'text' => __( 'No answers have been published from edited drafts yet. Correct a draft and approve it to start collecting accuracy data.', 'ai-chatbot' ),
			);
		} else {
			$insights[] = array(
				'tone' => 'neutral',
				'text' => sprintf(
					/* translators: 1: published answers, 2: corrected before publishing. */
					__( '%1$d answers published from drafts, %2$d of them corrected by hand before publishing.', 'ai-chatbot' ),
					$stats['published_answers'],
					$stats['major_corrections']
				),
			);
		}

		if ( null !== $stats['mean_ai_similarity'] && $stats['mean_ai_similarity'] < 0.4 ) {
			$insights[] = array(
				'tone' => 'warning',
				'text' => __( 'Corrected answers differ substantially from the AI drafts. The model is producing confidently wrong text rather than slightly off text, which usually points at missing or misleading knowledge sources.', 'ai-chatbot' ),
			);
		}

		if ( $stats['scope_deflected'] > 0 && null !== $stats['scope_yield'] && $stats['scope_yield'] > 0.4 ) {
			$insights[] = array(
				'tone' => 'warning',
				'text' => __( 'A large share of deflected questions were ruled back in scope. Consider widening the allowed topics list or setting strictness to balanced.', 'ai-chatbot' ),
			);
		}

		if ( ! empty( $stats['heavily_edited'] ) ) {
			$insights[] = array(
				'tone' => 'warning',
				'text' => sprintf(
					/* translators: %d: number of questions needing two or more corrections. */
					__( '%d question(s) have needed two or more corrections. Repeated edits on the same question usually mean the topic is missing from the knowledge base entirely.', 'ai-chatbot' ),
					count( $stats['heavily_edited'] )
				),
			);
		}

		return $insights;
	}
}