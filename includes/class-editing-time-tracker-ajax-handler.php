<?php
/**
 * AJAX handler for the plugin.
 *
 * Handles all AJAX requests with improved error handling and debugging.
 *
 * @since      1.1.0
 */

// If this file is called directly, abort.
if (!defined('ABSPATH')) {
    exit;
}

/**
 * AJAX handler for the plugin.
 */
class Editing_Time_Tracker_AJAX_Handler {

    /**
     * The session manager
     *
     * @var Editing_Time_Tracker_Session_Manager
     */
    private $session_manager;

    /**
     * Debug mode
     *
     * @var bool
     */
    private $debug_mode;

    /**
     * Initialize the class and set its properties.
     *
     * @since    1.1.0
     * @param    Editing_Time_Tracker_Session_Manager    $session_manager    The session manager
     */
    public function __construct($session_manager) {
        $this->session_manager = $session_manager;
        $this->debug_mode = defined('ETT_DEBUG') && ETT_DEBUG;
        $this->init();
    }

    /**
     * Initialize the AJAX handler
     *
     * @since    1.1.0
     */
    public function init() {
        // Register AJAX handlers
        add_action('wp_ajax_ett_update_session', array($this, 'ajax_update_session'));
        add_action('wp_ajax_ett_debug_log', array($this, 'ajax_debug_log'));
    }

    /**
     * Log debug information
     *
     * @since    1.1.0
     * @param    string    $message    The message to log
     * @param    array     $data       The data to log
     * @param    bool      $force      Whether to force logging even if debug mode is off
     */
    private function log($message, $data = array(), $force = false) {
        if ($this->debug_mode || $force) {
            $this->session_manager->debug_log($message, $data, $force);
        }
    }

    /**
     * AJAX handler for updating session data
     *
     * @since    1.1.0
     */
    public function ajax_update_session() {
        // Log all request data for debugging
        $this->log('AJAX update_session called', array(
            'post_data' => $_POST,
            'get_data' => $_GET,
            'user_id' => get_current_user_id(),
            'time' => current_time('mysql')
        ), true);

        // Get post ID with fallbacks
        $post_id = 0;
        
        // Try POST data first
        if (isset($_POST['post_id']) && !empty($_POST['post_id'])) {
            $post_id = (int)$_POST['post_id'];
        }
        
        // Try GET data if POST failed
        if (!$post_id && isset($_GET['post']) && !empty($_GET['post'])) {
            $post_id = (int)$_GET['post'];
        }
        
        // Try other common parameters
        if (!$post_id) {
            $possible_params = array('editor_post_id', 'elementor-preview', 'document_id');
            foreach ($possible_params as $param) {
                if (isset($_REQUEST[$param]) && !empty($_REQUEST[$param])) {
                    $post_id = (int)$_REQUEST[$param];
                    break;
                }
            }
        }
        
        // If still no post ID, try to get from referer
        if (!$post_id && isset($_SERVER['HTTP_REFERER'])) {
            $referer_parts = parse_url($_SERVER['HTTP_REFERER']);
            if (isset($referer_parts['query'])) {
                parse_str($referer_parts['query'], $query_vars);
                foreach (array('post', 'editor_post_id', 'elementor-preview') as $param) {
                    if (isset($query_vars[$param]) && !empty($query_vars[$param])) {
                        $post_id = (int)$query_vars[$param];
                        break;
                    }
                }
            }
        }
        
        // Validate post ID
        if (!$post_id) {
            $this->log('AJAX update_session error: Missing post ID', array(
                'post_data' => $_POST,
                'get_data' => $_GET
            ), true);
            
            wp_send_json_error(array(
                'message' => 'Missing post ID',
                'error_code' => 'missing_post_id'
            ));
            return;
        }
        
        // Get session ID
        $session_id = isset($_POST['session_id']) ? sanitize_text_field($_POST['session_id']) : 'ett_' . time();
        
        // Get activity data with error handling
        $activity_data = array();
        if (isset($_POST['activity_data'])) {
            try {
                $raw_data = $_POST['activity_data'];
                
                // Handle both JSON string and already decoded array
                if (is_string($raw_data)) {
                    $activity_data = json_decode(stripslashes($raw_data), true);
                    
                    if (json_last_error() !== JSON_ERROR_NONE) {
                        $this->log('JSON decode error', array(
                            'error' => json_last_error_msg(),
                            'raw_data' => $raw_data
                        ), true);
                        
                        $activity_data = array('error' => 'JSON decode error: ' . json_last_error_msg());
                    }
                } else if (is_array($raw_data)) {
                    $activity_data = $raw_data;
                }
            } catch (Exception $e) {
                $this->log('Exception processing activity data', array(
                    'error' => $e->getMessage(),
                    'raw_data' => isset($_POST['activity_data']) ? $_POST['activity_data'] : 'not set'
                ), true);
                
                $activity_data = array('error' => $e->getMessage());
            }
        }
        
        // Get other parameters
        $has_changes = isset($_POST['has_changes']) ? (bool)$_POST['has_changes'] : true;
        $last_activity = isset($_POST['last_activity']) ? (int)$_POST['last_activity'] : time() * 1000;
        $timer_duration = isset($activity_data['duration']) ? (int)$activity_data['duration'] : 0;
        
        // Log the processed data
        $this->log('Processed session data', array(
            'post_id' => $post_id,
            'session_id' => $session_id,
            'has_changes' => $has_changes,
            'last_activity' => date('Y-m-d H:i:s', $last_activity / 1000),
            'timer_duration' => $timer_duration,
            'activity_data' => $activity_data
        ), true);
        
        // Get or create session
        $user_id = get_current_user_id();
        $transient_key = 'ett_session_' . $user_id . '_' . $post_id;
        $session_data = get_transient($transient_key);
        
        if (!$session_data) {
            // Create new session
            $this->session_manager->start_tracking_session($post_id);
            $session_data = get_transient($transient_key);
            
            if (!$session_data) {
                $this->log('Failed to create session', array(
                    'post_id' => $post_id,
                    'user_id' => $user_id
                ), true);
                
                wp_send_json_error(array(
                    'message' => 'Failed to create session',
                    'error_code' => 'session_creation_failed'
                ));
                return;
            }
            
            $this->log('Created new session', array(
                'post_id' => $post_id,
                'session_id' => $session_id,
                'transient_key' => $transient_key
            ), true);
        }
        
        // Update session data
        $session_data['last_activity'] = date('Y-m-d H:i:s', $last_activity / 1000);
        $session_data['activity_data'] = $activity_data;
        
        // If we have a timer duration, use it for more accurate tracking
        if ($timer_duration > 0) {
            $session_data['timer_duration'] = $timer_duration;
            $session_data['has_timer_data'] = true;
            
            $this->log('Timer duration received', array(
                'post_id' => $post_id,
                'duration' => $timer_duration,
                'formatted' => $this->session_manager->format_duration($timer_duration)
            ), true);
        }
        
        // Update Elementor data if available
        $elementor_data = get_post_meta($post_id, '_elementor_data', true);
        if (!empty($elementor_data)) {
            $session_data['final_elementor_data'] = md5($elementor_data);
            $session_data['final_elementor_data_length'] = strlen($elementor_data);
            
            // Check if there are actual changes in the Elementor data
            $has_elementor_changes = false;
            if (isset($session_data['initial_elementor_data']) && 
                $session_data['initial_elementor_data'] !== $session_data['final_elementor_data']) {
                $has_elementor_changes = true;
            }
            
            $session_data['has_elementor_changes'] = $has_elementor_changes;
        }
        
        // Save updated session
        $result = set_transient($transient_key, $session_data, 12 * HOUR_IN_SECONDS);
        
        if (!$result) {
            $this->log('Failed to save session data', array(
                'post_id' => $post_id,
                'session_id' => $session_id,
                'transient_key' => $transient_key
            ), true);
            
            wp_send_json_error(array(
                'message' => 'Failed to save session data',
                'error_code' => 'session_save_failed'
            ));
            return;
        }
        
        $this->log('Session data saved successfully', array(
            'post_id' => $post_id,
            'session_id' => $session_id,
            'transient_key' => $transient_key
        ), true);
        
        // Only record the session if explicitly requested (save operation)
        // and the duration is meaningful (to avoid duplicate/unnecessary sessions)
        $is_save_operation = isset($_POST['is_save']) && $_POST['is_save'];
        $min_duration = apply_filters('ett_min_duration_threshold', 3); // Minimum 3 seconds
        
        if ($is_save_operation && $timer_duration >= $min_duration && $has_changes) {
            $post = get_post($post_id);
            if ($post) {
                // Check if we've recently recorded a session for this post
                $last_session_key = 'ett_last_session_' . $user_id . '_' . $post_id;
                $last_session_time = get_transient($last_session_key);
                
                // Only record if it's been at least 5 seconds since the last recording
                // This prevents duplicate recordings from multiple AJAX calls
                if (!$last_session_time || (time() - $last_session_time) > 5) {
                    $this->session_manager->track_edit_end($post_id, $post, true);
                    
                    // Remember when we recorded this session
                    set_transient($last_session_key, time(), 60); // Remember for 1 minute
                    
                    $this->log('Session recorded on save', array(
                        'post_id' => $post_id,
                        'post_title' => $post->post_title,
                        'duration' => $timer_duration
                    ), true);
                } else {
                    $this->log('Skipped duplicate session recording', array(
                        'post_id' => $post_id,
                        'last_recorded' => $last_session_time,
                        'seconds_ago' => time() - $last_session_time
                    ), true);
                }
            }
        } else {
            $this->log('Session not recorded', array(
                'is_save_operation' => $is_save_operation ? 'yes' : 'no',
                'timer_duration' => $timer_duration,
                'min_duration' => $min_duration,
                'has_changes' => $has_changes ? 'yes' : 'no'
            ), true);
        }
        
        wp_send_json_success(array(
            'message' => 'Session updated successfully',
            'session_id' => $session_id,
            'post_id' => $post_id,
            'timer_duration' => $timer_duration,
            'timestamp' => current_time('mysql')
        ));
    }

    /**
     * AJAX handler for debug logging
     *
     * @since    1.1.0
     */
    public function ajax_debug_log() {
        // Only process if debug mode is enabled
        if (!$this->debug_mode) {
            wp_send_json_error(array('message' => 'Debug mode is disabled'));
            return;
        }
        
        $message = isset($_POST['message']) ? sanitize_text_field($_POST['message']) : 'Debug log from client';
        $data = isset($_POST['data']) ? $_POST['data'] : array();
        
        if (is_string($data)) {
            try {
                $data = json_decode(stripslashes($data), true);
            } catch (Exception $e) {
                $data = array('error' => $e->getMessage(), 'raw' => $data);
            }
        }
        
        $this->log($message, $data, true);
        
        wp_send_json_success(array('message' => 'Debug log recorded'));
    }
}
