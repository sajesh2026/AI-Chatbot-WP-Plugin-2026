<?php
/**
 * Plugin Name:       AI Chatbot
 * Plugin URI:        https://iqronix.com/ai-chatbot
 * Description:       Production-ready AI Chatbot for WordPress. Build, train, and deploy an AI assistant in 5 to 10 minutes with RAG, Groq failover, and WordPress email notifications.
 * Version:           1.3.5
 * Requires at least: 6.4
 * Requires PHP:      8.0
 * Author:            IQronix & Antigravity
 * Author URI:        https://iqronix.com
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       ai-chatbot
 * Domain Path:       /languages
 *
 * @package AIChatbot
 */

defined( 'ABSPATH' ) || exit;

// Core plugin constants.
define( 'AI_CHATBOT_VERSION', '1.3.5' );

// Schema version. 1.2.0 added message editing (is_edited / edited_at on
// ai_messages). 1.3.0 adds the enriched feedback table. 1.4.0 adds the
// verified link registry table (ai_verified_links).
define( 'AI_CHATBOT_DB_VERSION', '1.4.0' );
define( 'AI_CHATBOT_PATH', plugin_dir_path( __FILE__ ) );
define( 'AI_CHATBOT_URL', plugin_dir_url( __FILE__ ) );
define( 'AI_CHATBOT_BASENAME', plugin_basename( __FILE__ ) );

// Require autoloader.
require_once AI_CHATBOT_PATH . 'includes/class-autoloader.php';

// Initialize autoloader.
\AIChatbot\Autoloader::register();

// Activation and deactivation hooks.
register_activation_hook( __FILE__, array( '\AIChatbot\Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( '\AIChatbot\Plugin', 'deactivate' ) );

// Run plugin bootstrap.
add_action( 'plugins_loaded', array( '\AIChatbot\Plugin', 'init' ) );
