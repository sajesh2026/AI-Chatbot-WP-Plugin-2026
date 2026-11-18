<?php
/**
 * Skills Manager Coordinator.
 *
 * @package AIChatbot\Skills
 */

namespace AIChatbot\Skills;

defined( 'ABSPATH' ) || exit;

/**
 * Class SkillsManager.
 */
class SkillsManager {

	/**
	 * Get combined skills configuration for an agent.
	 *
	 * @param int $agent_id Agent ID.
	 * @return array
	 */
	public static function get_skills( int $agent_id = 1 ): array {
		return array(
			'follow_up'     => FollowUp::get_config( $agent_id ),
			'contact_human' => ContactHuman::get_config( $agent_id ),
		);
	}
}
