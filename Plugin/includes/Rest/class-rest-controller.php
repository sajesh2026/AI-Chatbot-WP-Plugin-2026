<?php
/**
 * Base REST Controller.
 *
 * @package AIChatbot\Rest
 */

namespace AIChatbot\Rest;

use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Class RestController.
 */
abstract class RestController extends WP_REST_Controller {

	/**
	 * Namespace for all plugin routes.
	 */
	public const NAMESPACE = 'ai-chatbot/v1';

	/**
	 * Permission callback for admin routes.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return bool|WP_Error
	 */
	public function check_admin_permission( WP_REST_Request $request ): bool|WP_Error {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to access this resource.', 'ai-chatbot' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}
		return true;
	}

	/**
	 * Public permission callback.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return bool
	 */
	public function check_public_permission( WP_REST_Request $request ): bool {
		return true;
	}

	/**
	 * Format JSON success response.
	 *
	 * @param mixed $data Response payload.
	 * @param int   $status HTTP status code.
	 * @return WP_REST_Response
	 */
	protected function success( mixed $data, int $status = 200 ): WP_REST_Response {
		return new WP_REST_Response(
			array(
				'success' => true,
				'data'    => $data,
			),
			$status
		);
	}

	/**
	 * Format JSON error response.
	 *
	 * @param string $message Error message.
	 * @param string $code Error code slug.
	 * @param int    $status HTTP status code.
	 * @return WP_REST_Response
	 */
	protected function error( string $message, string $code = 'error', int $status = 400 ): WP_REST_Response {
		return new WP_REST_Response(
			array(
				'success' => false,
				'code'    => $code,
				'message' => $message,
			),
			$status
		);
	}
}
