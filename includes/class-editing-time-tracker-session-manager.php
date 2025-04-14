<?php
/**
 * Session manager for the plugin.
 *
 * Handles tracking editing sessions.
 *
 * @since      1.0.0
 */

// If this file is called directly, abort.
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Session manager for the plugin.
 */
class Editing_Time_Tracker_Session_Manager {

    /**
     * The database handler
     *
     * @var Editing_Time_Tracker_DB
     */
    private $db;

    /**
     * Initialize the class and set its properties.
     *
     * @since    1.0.0
     */
    public function __construct() {
        $this->db = new Editing_Time_Tracker_DB();
    }

    /**
     * Track when a user starts editing a post in the standard WordPress editor
     *
     * @since    1.0.0
     */
    public function track_edit_start() {
        $post_id = isset($_GET['post']) ? (int)$_GET['post'] : 0;
        if (!$post_id || !current_user_can('edit_posts')) {
            return;
        }
        
        $this->debug_log('Standard WordPress editor session started', array(
            'post_id' => $post_id,
            'screen' => get_current_screen()->id
        ), true);
        
        $this->start_tracking_session($post_id);
    }

    /**
     * Track when a user finishes editing a post
     * 
     * @since    1.0.0
     * @param    int       $post_id    Post ID
     * @param    WP_Post   $post       Post object
     * @param    bool      $update     Whether this is an update
     */
    public function track_edit_end($post_id, $post, $update = true) {
        if (!current_user_can('edit_posts')) return;
        
        // Special handling for Elementor saves which may not set update flag
        $is_elementor_save = $this->is_elementor_save();
        
        // Don't track revisions, auto-saves or other non-main post types
        if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id) || 
            !in_array($post->post_type, apply_filters('ett_tracked_post_types', array('post', 'page')))) {
            $this->debug_log('Skipping non-tracked post type or revision', array(
                'post_id' => $post_id,
                'post_type' => $post->post_type,
                'is_revision' => wp_is_post_revision($post_id) ? 'yes' : 'no',
                'is_autosave' => wp_is_post_autosave($post_id) ? 'yes' : 'no',
                'is_elementor' => $is_elementor_save ? 'yes' : 'no'
            ), false);
            return;
        }
        
        // Get user ID and session data
        $user_id = get_current_user_id();
        $transient_key = 'ett_session_' . $user_id . '_' . $post_id;
        $session_data = get_transient($transient_key);
        
        // Forces session creation if none exists but we're in Elementor
        if (!$session_data && $this->is_elementor_save()) {
            $save_source = $this->get_elementor_save_source();
            
            if (isset($_POST['action']) && 
                in_array($_POST['action'], array('elementor_ajax', 'elementor_save_builder'))) {
                $this->debug_log('Processing Elementor AJAX save', array(
                    'post_id' => $post_id,
                    'action' => $_POST['action']
                ), true);
            }
            
            $this->debug_log('Creating session data for Elementor save', array(
                'post_id' => $post_id,
                'action' => isset($_POST['action']) ? $_POST['action'] : 'unknown',
                'save_source' => $save_source
            ), true);
            $this->start_tracking_session($post_id);
            $session_data = get_transient($transient_key);
        }

        if ($session_data) {
            // Check if this is an Elementor save
            $is_elementor_save = $this->is_elementor_save();
            $save_source = $this->get_elementor_save_source();
            
            if ($is_elementor_save) {
                $this->debug_log('Detected Elementor save operation', array(
                    'post_id' => $post_id,
                    'source' => $save_source
                ), true);
            }
            
            $this->debug_log('Ending edit session', array(
                'post_id' => $post_id,
                'user_id' => get_current_user_id(),
                'post_title' => $post->post_title
            ), true);
            
            $start_time = new DateTime($session_data['start_time']);
            $end_time = new DateTime(current_time('mysql', false));
            
            // Calculate duration in seconds
            $duration = $end_time->getTimestamp() - $start_time->getTimestamp();
            
            // If we have timer data, use that for more accurate duration
            if (isset($session_data['has_timer_data']) && $session_data['has_timer_data'] && 
                isset($session_data['timer_duration']) && $session_data['timer_duration'] > 0) {
                
                $timer_duration = $session_data['timer_duration'];
                
                $this->debug_log('Using timer duration instead of calculated duration', array(
                    'post_id' => $post_id,
                    'calculated_duration' => $duration,
                    'timer_duration' => $timer_duration,
                    'difference' => $timer_duration - $duration
                ), defined('ETT_ELEMENTOR_DEBUG') && ETT_ELEMENTOR_DEBUG);
                
                // Use the timer duration instead
                $duration = $timer_duration;
            }
            
            // Define minimum threshold for recording a session
            $min_duration = apply_filters('ett_min_duration_threshold', defined('ETT_MIN_SESSION_DURATION') ? ETT_MIN_SESSION_DURATION : 10);
            
            // For Elementor, check if there are changes in the Elementor data
            $has_significant_changes = false;
            
            // Check for Elementor data changes
            if (isset($session_data['has_elementor_changes']) && $session_data['has_elementor_changes']) {
                $has_significant_changes = true;
                $this->debug_log('Detected significant changes in Elementor data', array(
                    'post_id' => $post_id,
                    'initial_length' => isset($session_data['initial_elementor_data_length']) ? 
                        $session_data['initial_elementor_data_length'] : 0,
                    'final_length' => isset($session_data['final_elementor_data_length']) ? 
                        $session_data['final_elementor_data_length'] : 0
                ), defined('ETT_ELEMENTOR_DEBUG') && ETT_ELEMENTOR_DEBUG);
            }
            
            // Check for activity data from JavaScript tracking
            $has_activity_data = isset($session_data['activity_data']) && 
                                is_array($session_data['activity_data']) && 
                                isset($session_data['activity_data']['changes']) && 
                                $session_data['activity_data']['changes'] > 0;
            
            if ($has_activity_data) {
                $has_significant_changes = true;
                $this->debug_log('Detected changes from JavaScript tracking', array(
                    'post_id' => $post_id,
                    'changes' => $session_data['activity_data']['changes'],
                    'elements_modified' => isset($session_data['activity_data']['elements_modified']) ? 
                        count($session_data['activity_data']['elements_modified']) : 0
                ), defined('ETT_ELEMENTOR_DEBUG') && ETT_ELEMENTOR_DEBUG);
            }
            
            $has_sufficient_duration = $duration >= $min_duration;
            
            $tracking_status = '';
            
            if (!$has_significant_changes && !$has_sufficient_duration) {
                $tracking_status = 'skipped_no_changes_short_duration';
                $this->debug_log('Skipping session recording - no significant changes and short duration', array(
                    'duration' => $duration,
                    'min_duration' => $min_duration,
                    'has_elementor_changes' => isset($session_data['has_elementor_changes']) ? 
                        ($session_data['has_elementor_changes'] ? 'yes' : 'no') : 'unknown'
                ), true);
                
                // Store tracking status in a transient for admin notice
                set_transient('ett_tracking_status_' . $user_id, array(
                    'status' => $tracking_status,
                    'post_id' => $post_id,
                    'post_title' => $post->post_title
                ), 30);
                
                delete_transient($transient_key);
                return;
            } else if (!$has_significant_changes) {
                $tracking_status = 'tracked_duration_only';
            } else if (!$has_sufficient_duration) {
                $tracking_status = 'tracked_changes_only';
            } else {
                $tracking_status = 'tracked_full';
            }
            
            // Add information about changes to the activity summary
            $changes_info = '';
            
            // Add Elementor data changes info
            if (isset($session_data['has_elementor_changes']) && $session_data['has_elementor_changes']) {
                $elementor_data_diff = isset($session_data['final_elementor_data_length']) && isset($session_data['initial_elementor_data_length']) ? 
                    $session_data['final_elementor_data_length'] - $session_data['initial_elementor_data_length'] : 0;
                
                $changes_info .= sprintf(', Elementor data: %+d bytes', $elementor_data_diff);
            }
            
            // Add JavaScript tracking info
            if ($has_activity_data) {
                $changes_info .= sprintf(', JS tracked changes: %d', $session_data['activity_data']['changes']);
                
                if (isset($session_data['activity_data']['elements_modified']) && 
                    is_array($session_data['activity_data']['elements_modified']) && 
                    !empty($session_data['activity_data']['elements_modified'])) {
                    $changes_info .= sprintf(', Elements modified: %d', count($session_data['activity_data']['elements_modified']));
                }
            }
            
            // Prepare data for database insertion
            $data = array(
                'user_id'              => $user_id,
                'post_id'              => $post_id,
                'start_time'           => $session_data['start_time'],
                'end_time'             => $end_time->format('Y-m-d H:i:s'),
                'duration'             => $duration,
                'activity_summary'     => sprintf('%s %s: %s%s',
                    $is_elementor_save ? 'Elementor edit' : 'Edited',
                    isset($session_data['post_type']) ? $session_data['post_type'] : $post->post_type,
                    isset($session_data['post_title']) ? $session_data['post_title'] : $post->post_title,
                    $changes_info
                )
            );
            
            // Insert the session record
            $result = $this->db->insert_session($data);
            
            if (false === $result) {
                $tracking_status = 'error_db';
            }
            
            // Store tracking status in a transient for admin notice
            set_transient('ett_tracking_status_' . get_current_user_id(), array(
                'status' => $tracking_status,
                'post_id' => $post_id,
                'post_title' => $post->post_title,
                'duration' => $duration,
                'has_elementor_changes' => isset($session_data['has_elementor_changes']) ? $session_data['has_elementor_changes'] : false
            ), 30);
            
            // Always delete the session after recording it to prevent duplication
            // The JavaScript will create a new session via AJAX if needed
            delete_transient($transient_key);
        } else {
            // No session data found - possibly expired or never started
            set_transient('ett_tracking_status_' . get_current_user_id(), array(
                'status' => 'no_session',
                'post_id' => $post_id,
                'post_title' => $post->post_title
            ), 30);
        }
    }

    /**
     * Start tracking a new editing session
     *
     * @since    1.0.0
     * @param    int     $post_id     The post ID being edited
     * @param    bool    $force_new   Whether to force a new session even if one exists
     * @param    string  $source      Source of the session start request
     */
    public function start_tracking_session($post_id, $force_new = false, $source = 'standard') {
        if (!current_user_can('edit_posts')) {
            return;
        }
        
        $user_id = get_current_user_id();
        $transient_key = 'ett_session_' . $user_id . '_' . $post_id;
        
        // Check if we already have a session
        $existing_session = get_transient($transient_key);
        
        // If force_new is true, always create a new session
        if ($force_new) {
            $this->debug_log('Forcing creation of new session', array(
                'post_id' => $post_id,
                'user_id' => $user_id,
                'had_existing_session' => $existing_session ? 'yes' : 'no',
                'source' => $source
            ), defined('ETT_ELEMENTOR_DEBUG') && ETT_ELEMENTOR_DEBUG);
            
            // Delete existing session without recording it again
            // This prevents duplicate sessions
            if ($existing_session) {
                delete_transient($transient_key);
            }
            
            $existing_session = false;
        }
        // If session exists and is less than 5 minutes old, don't create a new one
        else if ($existing_session && isset($existing_session['start_time'])) {
            $start_time = new DateTime($existing_session['start_time']);
            $now = new DateTime(current_time('mysql', false));
            $interval = $start_time->diff($now);
            $minutes_elapsed = ($interval->days * 24 * 60) + ($interval->h * 60) + $interval->i;
            
            if ($minutes_elapsed < 5) {
                $this->debug_log('Using existing session (less than 5 minutes old)', array(
                    'post_id' => $post_id,
                    'user_id' => $user_id,
                    'minutes_elapsed' => $minutes_elapsed,
                    'session_start_time' => $existing_session['start_time']
                ), defined('ETT_ELEMENTOR_DEBUG') && ETT_ELEMENTOR_DEBUG);
                
                // Extend the session timeout
                set_transient($transient_key, $existing_session, 12 * HOUR_IN_SECONDS);
                return;
            }
        }
        
        // Create a new session
        $post = get_post($post_id);
        if (!$post) {
            $this->debug_log('Could not retrieve post for session start', array(
                'post_id' => $post_id
            ), true);
            return;
        }
        
        // Check if this is an Elementor post
        $is_elementor = get_post_meta($post_id, '_elementor_edit_mode', true) === 'builder';
        
        // Get Elementor data if available
        $elementor_data = '';
        if ($is_elementor) {
            $elementor_data = get_post_meta($post_id, '_elementor_data', true);
        }
        
        $this->debug_log('Starting edit session', array(
            'post_id' => $post_id,
            'user_id' => $user_id,
            'title' => $post->post_title,
            'has_elementor' => $is_elementor,
            'elementor_data_length' => $is_elementor ? strlen($elementor_data) : 0
        ), defined('ETT_ELEMENTOR_DEBUG') && ETT_ELEMENTOR_DEBUG);
        
        $session_data = array(
            'start_time' => current_time('mysql', false),
            'post_id'    => $post_id,
            'user_id'    => $user_id,
            'post_title' => $post->post_title,
            'post_type' => $post->post_type,
            'is_elementor' => $is_elementor,
            'initial_elementor_data' => $is_elementor ? md5($elementor_data) : '',
            'initial_elementor_data_length' => $is_elementor ? strlen($elementor_data) : 0
        );
        
        set_transient($transient_key, $session_data, 12 * HOUR_IN_SECONDS);
    }


    /**
     * Format duration in seconds to human-readable string
     * 
     * @since    1.0.0
     * @param    int       $seconds    Duration in seconds
     * @return   string                Formatted duration string
     */
    public function format_duration($seconds) {
        $hours = floor($seconds / 3600);
        $minutes = floor(($seconds % 3600) / 60);
        $seconds = $seconds % 60;
        
        $parts = [];
        if ($hours > 0) $parts[] = $hours . 'h';
        if ($minutes > 0) $parts[] = $minutes . 'm';
        if ($seconds > 0 || empty($parts)) $parts[] = $seconds . 's';
        
        return implode(' ', $parts);
    }

    /**
     * Helper function to check if the current save operation is from Elementor
     * 
     * @since    1.0.0
     * @return   bool    True if this is an Elementor save operation
     */
    public function is_elementor_save() {
        // Check AJAX requests for Elementor actions
        if (defined('DOING_AJAX') && DOING_AJAX) {
            if (isset($_POST['action']) && in_array($_POST['action'], array('elementor_ajax', 'elementor_save_builder'))) {
                return true;
            }
            
            // Check for Elementor preview data
            if (isset($_REQUEST['actions']) && is_string($_REQUEST['actions'])) {
                $actions = json_decode(stripslashes($_REQUEST['actions']), true);
                if (is_array($actions) && !empty(array_intersect(array_keys($actions), array('save_builder', 'save_template')))) {
                    return true;
                }
            }
        }
        
        // Check for Elementor action hooks being fired
        if (did_action('elementor/document/after_save') || did_action('elementor/editor/after_save')) {
            return true;
        }
        
        // Check for Elementor meta in post
        if (isset($_GET['post']) && get_post_meta((int)$_GET['post'], '_elementor_edit_mode', true) === 'builder') {
            return true;
        }
        
        return false;
    }

    /**
     * Get the source of the Elementor save operation
     * 
     * @since    1.0.0
     * @return   string    The source identifier
     */
    public function get_elementor_save_source() {
        if (defined('DOING_AJAX') && DOING_AJAX) {
            if (isset($_POST['action'])) {
                if ($_POST['action'] === 'elementor_ajax') {
                    return 'elementor_ajax';
                } else if ($_POST['action'] === 'elementor_save_builder') {
                    return 'elementor_save_builder';
                }
            }
        }
        
        if (did_action('elementor/document/after_save')) {
            return 'elementor_document_save';
        }
        
        if (did_action('elementor/editor/after_save')) {
            return 'elementor_editor_save';
        }
        
        return 'standard';
    }

    /**
     * Debug logging helper
     * 
     * @since    1.0.0
     * @param    string    $message       The message to log
     * @param    mixed     $data          Optional data to include in the log
     * @param    bool      $show_notice   Whether to show this message as an admin notice
     */
    public function debug_log($message, $data = null, $show_notice = false) {
        $log_message = '';
        
        if ($data !== null) {
            $formatted_data = is_array($data) || is_object($data) ? json_encode($data) : $data;
            $log_message = 'ETT Debug: ' . $message . ' - ' . $formatted_data;
        } else {
            $log_message = 'ETT Debug: ' . $message;
        }
        
        // Standard debug logging
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log($log_message);
        }
        
        // Store for admin notices if enabled
        if (defined('ETT_SHOW_DEBUG_NOTICES') && ETT_SHOW_DEBUG_NOTICES && ($show_notice || is_admin())) {
            $user_id = get_current_user_id();
            if ($user_id) {
                $debug_messages = get_transient('ett_debug_messages_' . $user_id) ?: array();
                $debug_messages[] = array(
                    'message' => $message,
                    'data' => $data ? $formatted_data : null,
                    'time' => current_time('mysql')
                );
                
                // Keep only the last 10 messages
                if (count($debug_messages) > 10) {
                    $debug_messages = array_slice($debug_messages, -10);
                }
                
                set_transient('ett_debug_messages_' . $user_id, $debug_messages, 30 * MINUTE_IN_SECONDS);
            }
        }
    }
}
