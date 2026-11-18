<?php
/**
 * Contact Human REST Controller.
 *
 * @package AIChatbot\Rest
 */

namespace AIChatbot\Rest;

use WP_REST_Request;
use WP_REST_Response;
use AIChatbot\Security\Sanitizer;
use AIChatbot\Skills\ContactHuman;

defined( 'ABSPATH' ) || exit;

/**
 * Class ContactController.
 */
class ContactController extends RestController {

	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/contact',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'submit_contact' ),
				'permission_callback' => array( $this, 'check_public_permission' ),
			)
		);
	}

	/**
	 * Handle contact form submission.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function submit_contact( WP_REST_Request $request ): WP_REST_Response {
		$ip = Sanitizer::get_client_ip();

		// Rate limit: 5 requests per hour per IP.
		if ( ! Sanitizer::check_rate_limit( 'contact', $ip, 5, 3600 ) ) {
			return $this->error(
				__( 'Too many contact submissions. Please try again later.', 'ai-chatbot' ),
				'rate_limit_exceeded',
				429
			);
		}

		$params   = $request->get_json_params() ?: array();
		$agent_id = (int) ( $params['agent_id'] ?? 1 );

		$res = ContactHuman::submit( $params, $agent_id );

		if ( ! $res['success'] ) {
			return $this->error( $res['message'], 'validation_failed', 400 );
		}

		return $this->success( $res );
	}
}
