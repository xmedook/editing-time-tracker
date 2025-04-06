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
            // Get combined content including Elementor content if available
            $post_content = $this->get_post_content($post_id);
            $stripped_content = wp_strip_all_tags($post_content);
            
            // Calculate character lengths using multibyte safe functions
            $initial_content_length = isset($session_data['initial_content_length']) ? intval($session_data['initial_content_length']) : 0;
            $initial_stripped_length = isset($session_data['initial_stripped_length']) ? intval($session_data['initial_stripped_length']) : 0;
            
            $final_content_length = mb_strlen($post_content, 'UTF-8');
            $final_stripped_length = mb_strlen($stripped_content, 'UTF-8');
            
            // Improved word count calculation that handles multiple languages and special characters
            $initial_word_count = isset($session_data['initial_word_count']) ? intval($session_data['initial_word_count']) : 0;
            $final_word_count = $this->count_words($post_content);
            
            // Calculate changes - use the raw content length for consistency
            $char_diff = $final_content_length - $initial_content_length;
            $word_diff = $final_word_count - $initial_word_count;
            
            // More accurate char diff using stripped content (removes HTML influence)
            $stripped_char_diff = $final_stripped_length - $initial_stripped_length;
            
            $this->debug_log('Ending edit session', array(
                'post_id' => $post_id,
                'user_id' => get_current_user_id(),
                'post_title' => $post->post_title,
                'initial_content_length' => $initial_content_length,
                'final_content_length' => $final_content_length,
                'initial_stripped_length' => $initial_stripped_length, 
                'final_stripped_length' => $final_stripped_length,
                'char_diff_raw' => $char_diff,
                'char_diff_stripped' => $stripped_char_diff,
                'initial_word_count' => $initial_word_count,
                'final_word_count' => $final_word_count,
                'word_diff' => $word_diff
            ), true);
            
            // Check if this is an Elementor save
            $is_elementor_save = $this->is_elementor_save();
            $save_source = $this->get_elementor_save_source();
            
            if ($is_elementor_save) {
                $this->debug_log('Detected Elementor save operation', array(
                    'post_id' => $post_id,
                    'source' => $save_source
                ), true);
            }
            
            $start_time = new DateTime($session_data['start_time']);
            $end_time = new DateTime(current_time('mysql', false));
            
            // Calculate duration in seconds
            $duration = $end_time->getTimestamp() - $start_time->getTimestamp();
            
            // Define minimum thresholds for recording a session
            $min_duration = apply_filters('ett_min_duration_threshold', defined('ETT_MIN_SESSION_DURATION') ? ETT_MIN_SESSION_DURATION : 10);
            $min_char_change = apply_filters('ett_min_char_change_threshold', 3); // At least 3 characters changed
            
            // Check if changes meet the minimum thresholds
            $has_significant_changes = abs($stripped_char_diff) >= $min_char_change || abs($word_diff) >= 1;
            
            // For Elementor, also check if there are changes in the Elementor data
            if (!$has_significant_changes && isset($session_data['has_elementor_changes']) && $session_data['has_elementor_changes']) {
                $has_significant_changes = true;
                $this->debug_log('Detected significant changes in Elementor data', array(
                    'post_id' => $post_id,
                    'initial_length' => isset($session_data['initial_elementor_data_length']) ? 
                        $session_data['initial_elementor_data_length'] : 0,
                    'final_length' => isset($session_data['final_elementor_data_length']) ? 
                        $session_data['final_elementor_data_length'] : 0
                ), defined('ETT_ELEMENTOR_DEBUG') && ETT_ELEMENTOR_DEBUG);
            }
            
            $has_sufficient_duration = $duration >= $min_duration;
            
            $tracking_status = '';
            
            if (!$has_significant_changes && !$has_sufficient_duration) {
                $tracking_status = 'skipped_no_changes_short_duration';
                $this->debug_log('Skipping session recording - no significant changes and short duration', array(
                    'stripped_char_diff' => $stripped_char_diff,
                    'min_char_change' => $min_char_change,
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
            
            // Add information about Elementor changes to the activity summary
            $elementor_changes_info = '';
            if (isset($session_data['has_elementor_changes']) && $session_data['has_elementor_changes']) {
                $elementor_data_diff = isset($session_data['final_elementor_data_length']) && isset($session_data['initial_elementor_data_length']) ? 
                    $session_data['final_elementor_data_length'] - $session_data['initial_elementor_data_length'] : 0;
                
                $elementor_changes_info = sprintf(', Elementor data: %+d bytes', $elementor_data_diff);
            }
            
            // Prepare data for database insertion
            $data = array(
                'user_id'              => $user_id,
                'post_id'              => $post_id,
                'start_time'           => $session_data['start_time'],
                'end_time'             => $end_time->format('Y-m-d H:i:s'),
                'duration'             => $duration,
                'initial_content_length' => $initial_content_length,
                'final_content_length' => $final_content_length,
                'initial_word_count'   => $initial_word_count,
                'final_word_count'     => $final_word_count,
                'activity_summary'     => sprintf('%s %s: %s (Chars: %+d, Words: %+d%s)',
                    $is_elementor_save ? 'Elementor edit' : 'Edited',
                    isset($session_data['post_type']) ? $session_data['post_type'] : $post->post_type,
                    isset($session_data['post_title']) ? $session_data['post_title'] : $post->post_title,
                    $stripped_char_diff, // Use stripped content diff for more accuracy
                    $word_diff,
                    $elementor_changes_info
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
                'char_diff' => $stripped_char_diff,
                'word_diff' => $word_diff,
                'duration' => $duration
            ), 30);

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
     * @param    int    $post_id    The post ID being edited
     */
    public function start_tracking_session($post_id) {
        if (!current_user_can('edit_posts')) {
            return;
        }
        
        $user_id = get_current_user_id();
        $transient_key = 'ett_session_' . $user_id . '_' . $post_id;
        
        // Check if we already have a session
        $existing_session = get_transient($transient_key);
        
        // If session exists and is less than 5 minutes old, don't create a new one
        if ($existing_session && isset($existing_session['start_time'])) {
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
        
        // Get combined content including Elementor content if available
        $initial_content = $this->get_post_content($post_id);
        $stripped_content = wp_strip_all_tags($initial_content);
        
        $raw_length = mb_strlen($initial_content, 'UTF-8');
        $stripped_length = mb_strlen($stripped_content, 'UTF-8');
        $word_count = $this->count_words($initial_content);
        
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
            'raw_length' => $raw_length,
            'stripped_length' => $stripped_length,
            'word_count' => $word_count,
            'title' => $post->post_title,
            'has_elementor' => $is_elementor,
            'elementor_data_length' => $is_elementor ? strlen($elementor_data) : 0
        ), defined('ETT_ELEMENTOR_DEBUG') && ETT_ELEMENTOR_DEBUG);
        
        $session_data = array(
            'start_time' => current_time('mysql', false),
            'post_id'    => $post_id,
            'user_id'    => $user_id,
            'initial_content_length' => $raw_length,
            'initial_stripped_length' => $stripped_length,
            'initial_word_count' => $word_count,
            'post_title' => $post->post_title,
            'post_type' => $post->post_type,
            'is_elementor' => $is_elementor,
            'initial_elementor_data' => $is_elementor ? md5($elementor_data) : '',
            'initial_elementor_data_length' => $is_elementor ? strlen($elementor_data) : 0
        );
        
        set_transient($transient_key, $session_data, 12 * HOUR_IN_SECONDS);
    }

    /**
     * Get combined content for a post (regular content + Elementor content)
     *
     * @since    1.0.0
     * @param    int       $post_id    Post ID
     * @return   string                Combined content
     */
    public function get_post_content($post_id) {
        $post = get_post($post_id);
        if (!$post) {
            return '';
        }
        
        $content = $post->post_content;
        
        // Check if this is an Elementor post
        $is_elementor = get_post_meta($post_id, '_elementor_edit_mode', true) === 'builder';
        
        if ($is_elementor) {
            $elementor_data_string = get_post_meta($post_id, '_elementor_data', true);
            if (!empty($elementor_data_string)) {
                $elementor_data = json_decode($elementor_data_string, true);
                if (is_array($elementor_data)) {
                    $this->debug_log('Processing Elementor content', array(
                        'post_id' => $post_id,
                        'data_length' => strlen($elementor_data_string),
                        'elements_count' => count($elementor_data)
                    ), false);
                    
                    try {
                        $elementor_content = $this->extract_elementor_content($elementor_data);
                        $content .= ' ' . $elementor_content;
                    } catch (Exception $e) {
                        $this->debug_log('Error extracting Elementor content', array(
                            'message' => $e->getMessage(),
                            'post_id' => $post_id
                        ), true);
                    }
                } else {
                    $this->debug_log('Invalid Elementor data format', array(
                        'post_id' => $post_id,
                        'data_type' => gettype($elementor_data)
                    ), true);
                }
            } else {
                $this->debug_log('Empty Elementor data for post', array(
                    'post_id' => $post_id
                ), false);
            }
        }
        
        return $content;
    }

    /**
     * Extract text content from Elementor data
     *
     * @since    1.0.0
     * @param    array    $elementor_data    The Elementor data structure
     * @return   string                      Extracted text content
     */
    public function extract_elementor_content($elementor_data) {
        $content = '';
        
        if (!is_array($elementor_data)) {
            $this->debug_log('Invalid Elementor data format', ['data_type' => gettype($elementor_data)], true);
            return $content;
        }
        
        foreach ($elementor_data as $element) {
            // Skip invalid elements
            if (!is_array($element)) {
                continue;
            }
            
            // Handle nested elements (recursively process any child elements)
            if (isset($element['elements']) && is_array($element['elements'])) {
                $content .= ' ' . $this->extract_elementor_content($element['elements']);
            }
            
            // Extract widget settings
            if (isset($element['settings']) && is_array($element['settings'])) {
                $settings = $element['settings'];
                
                // Extract content from common Elementor widgets
                $text_fields = [
                    'title', 'heading', 'editor', 'text', 'description', 'content',
                    'caption', 'subtitle', 'button_text', 'link_text', 'testimonial_content',
                    'alert_title', 'alert_description', 'tab_title', 'tab_content',
                    'field_label', 'field_placeholder', 'item_title', 'item_description',
                    'form_name', 'form_description', 'success_message', 'error_message',
                    'button_title', 'link_title', 'counter_title', 'counter_prefix',
                    'counter_suffix', 'prefix', 'suffix', 'before_text', 'after_text',
                    'headline', 'sub_headline', 'feature_title', 'feature_description',
                    'price', 'currency_symbol', 'period', 'ribbon_title', 'yelp_title',
                    'google_title', 'tweets_limit', 'author_name', 'author_info'
                ];
                
                // Extract all available text fields
                foreach ($text_fields as $field) {
                    if (isset($settings[$field]) && is_string($settings[$field])) {
                        $content .= ' ' . $settings[$field];
                    }
                }
                
                // Handle special case for HTML widget
                if (isset($element['widgetType']) && $element['widgetType'] === 'html' && 
                    isset($settings['html']) && is_string($settings['html'])) {
                    $content .= ' ' . wp_strip_all_tags($settings['html']);
                }
                
                // Handle repeater fields (like icon list, price list, etc.)
                if (isset($settings['icon_list']) && is_array($settings['icon_list'])) {
                    foreach ($settings['icon_list'] as $item) {
                        if (isset($item['text']) && is_string($item['text'])) {
                            $content .= ' ' . $item['text'];
                        }
                    }
                }
                
                // Handle price list items
                if (isset($settings['price_list']) && is_array($settings['price_list'])) {
                    foreach ($settings['price_list'] as $item) {
                        if (isset($item['title']) && is_string($item['title'])) {
                            $content .= ' ' . $item['title'];
                        }
                        if (isset($item['description']) && is_string($item['description'])) {
                            $content .= ' ' . $item['description'];
                        }
                        if (isset($item['price']) && is_string($item['price'])) {
                            $content .= ' ' . $item['price'];
                        }
                    }
                }
                
                // Handle image carousel
                if (isset($settings['slides']) && is_array($settings['slides'])) {
                    foreach ($settings['slides'] as $slide) {
                        if (isset($slide['caption']) && is_string($slide['caption'])) {
                            $content .= ' ' . $slide['caption'];
                        }
                    }
                }
                
                // Handle testimonial carousel
                if (isset($settings['testimonial_list']) && is_array($settings['testimonial_list'])) {
                    foreach ($settings['testimonial_list'] as $testimonial) {
                        if (isset($testimonial['content']) && is_string($testimonial['content'])) {
                            $content .= ' ' . $testimonial['content'];
                        }
                        if (isset($testimonial['name']) && is_string($testimonial['name'])) {
                            $content .= ' ' . $testimonial['name'];
                        }
                        if (isset($testimonial['title']) && is_string($testimonial['title'])) {
                            $content .= ' ' . $testimonial['title'];
                        }
                    }
                }
                
                // Handle tabs and accordion
                if (isset($settings['tabs']) && is_array($settings['tabs'])) {
                    foreach ($settings['tabs'] as $tab) {
                        if (isset($tab['tab_title']) && is_string($tab['tab_title'])) {
                            $content .= ' ' . $tab['tab_title'];
                        }
                        if (isset($tab['tab_content']) && is_string($tab['tab_content'])) {
                            $content .= ' ' . $tab['tab_content'];
                        }
                    }
                }
                
                // Handle form fields
                if (isset($settings['form_fields']) && is_array($settings['form_fields'])) {
                    foreach ($settings['form_fields'] as $field) {
                        if (isset($field['field_label']) && is_string($field['field_label'])) {
                            $content .= ' ' . $field['field_label'];
                        }
                        if (isset($field['placeholder']) && is_string($field['placeholder'])) {
                            $content .= ' ' . $field['placeholder'];
                        }
                    }
                }
            }
            
            // Handle template widgets
            if (isset($element['widgetType']) && $element['widgetType'] === 'template') {
                if (isset($element['templateID'])) {
                    $template_id = $element['templateID'];
                    $template_content = get_post_meta($template_id, '_elementor_data', true);
                    if (!empty($template_content)) {
                        $template_data = json_decode($template_content, true);
                        if (is_array($template_data)) {
                            $content .= ' ' . $this->extract_elementor_content($template_data);
                        }
                    }
                }
            }
        }
        
        $this->debug_log('Extracted Elementor content', [
            'length' => mb_strlen($content, 'UTF-8'),
            'word_count' => $this->count_words($content),
            'elements_count' => count($elementor_data)
        ], false);
        
        return $content;
    }

    /**
     * Count words in text with better support for multiple languages
     * 
     * @since    1.0.0
     * @param    string    $text    The text to count words in
     * @return   int                Word count
     */
    public function count_words($text) {
        // First, ensure we're working with plain text
        $text = wp_strip_all_tags($text);
        
        // Remove all punctuation and extra whitespace
        $text = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $text);
        $text = trim($text);
        
        if (empty($text)) {
            return 0;
        }
        
        // Split by whitespace and count non-empty words
        $words = preg_split('/\s+/', $text, -1, PREG_SPLIT_NO_EMPTY);
        return count($words);
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
