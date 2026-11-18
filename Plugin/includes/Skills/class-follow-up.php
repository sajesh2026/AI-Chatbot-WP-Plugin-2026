<?php
/**
 * Follow-up Proactive Message Skill.
 *
 * @package AIChatbot\Skills
 */

namespace AIChatbot\Skills;

defined( 'ABSPATH' ) || exit;

/**
 * Class FollowUp.
 */
class FollowUp {

	/**
	 * Get follow-up skill configuration.
	 *
	 * @param int $agent_id Agent ID.
	 * @return array
	 */
	public static function get_config( int $agent_id = 1 ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'ai_settings';

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT setting_key, setting_value FROM {$table} WHERE setting_group = %s AND agent_id = %d",
				'skills_follow_up',
				$agent_id
			),
			ARRAY_A
		);

		$config = array(
			'enabled'       => true,
			'message'       => __( 'Would you like to speak with one of our experts or book a consultation?', 'ai-chatbot' ),
			'delay'         => 8, // seconds
			'trigger'       => 'first_response', // 'first_response', 'after_x_messages', 'inactivity', 'time_delay'
			'message_count' => 2,
			'frequency_max' => 1, // Max follow-ups per session
		);

		if ( ! empty( $rows ) ) {
			foreach ( $rows as $r ) {
				$val = $r['setting_value'];
				if ( in_array( $val, array( '1', '0', 'true', 'false' ), true ) ) {
					$config[ $r['setting_key'] ] = in_array( $val, array( '1', 'true' ), true );
				} elseif ( is_numeric( $val ) ) {
					$config[ $r['setting_key'] ] = (int) $val;
				} else {
					$config[ $r['setting_key'] ] = $val;
				}
			}
		}

		return $config;
	}
}
