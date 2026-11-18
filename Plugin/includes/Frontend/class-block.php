<?php
/**
 * Gutenberg Block Registration.
 *
 * @package AIChatbot\Frontend
 */

namespace AIChatbot\Frontend;

defined( 'ABSPATH' ) || exit;

/**
 * Class Block.
 */
class Block {

	/**
	 * Register Gutenberg block.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'init', array( __CLASS__, 'register_block' ) );
	}

	/**
	 * Register block type.
	 *
	 * @return void
	 */
	public static function register_block(): void {
		if ( ! function_exists( 'register_block_type' ) ) {
			return;
		}

		register_block_type(
			'ai-chatbot/chat-widget',
			array(
				'render_callback' => array( __CLASS__, 'render_block' ),
				'attributes'      => array(
					'agentId' => array(
						'type'    => 'number',
						'default' => 1,
					),
					'mode'    => array(
						'type'    => 'string',
						'default' => 'embedded',
					),
				),
			)
		);
	}

	/**
	 * Render callback for block.
	 *
	 * @param array $attributes Block attributes.
	 * @return string
	 */
	public static function render_block( array $attributes ): string {
		$agent_id = (int) ( $attributes['agentId'] ?? 1 );
		$mode     = esc_attr( $attributes['mode'] ?? 'embedded' );

		FrontendController::enqueue_assets();

		return sprintf(
			'<div class="ai-chatbot-block-wrapper" style="width:100%%; max-width:480px; height:600px; margin:20px auto;"><div class="ai-chatbot-root" data-agent-id="%d" data-mode="%s"></div></div>',
			$agent_id,
			$mode
		);
	}
}
