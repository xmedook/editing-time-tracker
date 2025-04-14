<?php
/**
 * Integrations handler for the plugin.
 *
 * Handles integration with external services like ClickUp.
 *
 * @since      1.1.0
 */

// If this file is called directly, abort.
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Integrations handler for the plugin.
 */
class Editing_Time_Tracker_Integrations {

    /**
     * Initialize integrations
     *
     * @since    1.1.0
     */
    public function init() {
        // Add hook to send data to integrations when a session is saved
        add_action('ett_session_saved', array($this, 'process_integrations'), 10, 2);
    }
    
    /**
     * Process integrations when a session is saved
     *
     * @since    1.1.0
     * @param    int       $session_id    The session ID
     * @param    array     $session_data  The session data
     */
    public function process_integrations($session_id, $session_data) {
        // Check if ClickUp integration is enabled
        if (get_option('ett_enable_clickup_integration', false)) {
            $this->send_to_clickup($session_id, $session_data);
        }
    }
    
    /**
     * Send session data to ClickUp
     *
     * @since    1.1.0
     * @param    int       $session_id    The session ID
     * @param    array     $session_data  The session data
     */
    public function send_to_clickup($session_id, $session_data) {
        // This function will be implemented in the next MVP
        // For now, we just log that it was called
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('ETT: Session data ready to send to ClickUp: ' . $session_id);
        }
        
        // Future implementation will include:
        // 1. Get ClickUp API key from settings
        // 2. Get ClickUp task ID from post meta or settings
        // 3. Format session data for ClickUp time tracking
        // 4. Send data to ClickUp API
        // 5. Store response in post meta
    }
}
