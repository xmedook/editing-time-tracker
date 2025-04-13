<?php
/**
 * Plugin Name: Editing Time Tracker
 * Plugin URI: https://koode.mx
 * Description: Tracks time spent editing posts and pages
 * Version: 1.1.0
 * Author: koode.mx
 * Author URI: https://koode.mx
 * Text Domain: editing-time-tracker
 * Domain Path: /languages
 * License: GPL-2.0+
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 */

// If this file is called directly, abort.
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('ETT_VERSION', '1.1.0');
define('ETT_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('ETT_PLUGIN_URL', plugin_dir_url(__FILE__));
define('ETT_PLUGIN_BASENAME', plugin_basename(__FILE__));

// Define constants for plugin behavior
if (!defined('ETT_SHOW_DEBUG_NOTICES')) {
    define('ETT_SHOW_DEBUG_NOTICES', true); // Set to false in production
}

// Define constant for Elementor debug mode
if (!defined('ETT_ELEMENTOR_DEBUG')) {
    define('ETT_ELEMENTOR_DEBUG', true); // Set to false in production
}

// Define constant for minimum session duration
if (!defined('ETT_MIN_SESSION_DURATION')) {
    define('ETT_MIN_SESSION_DURATION', 10); // Minimum session duration in seconds
}

/**
 * The core plugin class
 */
require_once ETT_PLUGIN_DIR . 'includes/class-editing-time-tracker.php';

/**
 * Register activation hook
 */
function editing_time_tracker_activate() {
    require_once ETT_PLUGIN_DIR . 'includes/class-editing-time-tracker-db.php';
    $db = new Editing_Time_Tracker_DB();
    $db->activate();
}
register_activation_hook(__FILE__, 'editing_time_tracker_activate');

/**
 * Begins execution of the plugin.
 */
function run_editing_time_tracker() {
    $plugin = new Editing_Time_Tracker();
    $plugin->run();
}

// Start the plugin
run_editing_time_tracker();
