<?php
/**
 * Elementor integration for the plugin.
 *
 * Handles Elementor-specific tracking functionality.
 *
 * @since      1.0.0
 */

// If this file is called directly, abort.
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Elementor integration for the plugin.
 */
class Editing_Time_Tracker_Elementor {

    /**
     * The session manager
     *
     * @var Editing_Time_Tracker_Session_Manager
     */
    private $session_manager;

    /**
     * Initialize the class and set its properties.
     *
     * @since    1.0.0
     * @param    Editing_Time_Tracker_Session_Manager    $session_manager    The session manager
     */
    public function __construct($session_manager) {
        $this->session_manager = $session_manager;
    }

    /**
     * Register all Elementor-related hooks
     * This method is called at multiple points to ensure hooks are registered
     *
     * @since    1.0.0
     */
    public function register_elementor_hooks() {
        // Check if Elementor exists
        if (!did_action('elementor/loaded') && !class_exists('\\Elementor\\Plugin')) {
            $this->session_manager->debug_log('Elementor not loaded yet, hooks will be registered when Elementor loads', null, false);
            return;
        }
        
        // Check if hooks are already registered to prevent duplicate registrations
        $already_registered = has_action('elementor/editor/before_enqueue_scripts', array($this, 'track_elementor_edit_start'));
        
        $this->session_manager->debug_log('Registering Elementor hooks', [
            'already_registered' => $already_registered ? 'yes' : 'no',
            'load_action' => current_action(),
            'elementor_version' => defined('ELEMENTOR_VERSION') ? ELEMENTOR_VERSION : 'unknown',
            'wp_actions' => count($GLOBALS['wp_actions']),
            'current_hook' => current_filter()
        ], defined('ETT_ELEMENTOR_DEBUG') && ETT_ELEMENTOR_DEBUG);

        try {
            // Only register hooks if not already registered
            if (!$already_registered) {
                // Track when Elementor editor is loaded (when editor UI is being prepared)
                add_action('elementor/editor/before_enqueue_scripts', array($this, 'track_elementor_edit_start'), 5);
                
                // Track when Elementor saves content (regular save)
                add_action('elementor/document/after_save', array($this, 'track_elementor_edit_end'), 10, 2);
                
                // Track Elementor auto-save and preview updates with higher priority
                add_action('elementor/db/before_save', array($this, 'track_elementor_before_save'), 5, 2);
                add_action('elementor/editor/after_save', array($this, 'track_elementor_after_save'), 5, 2);
                
                // Also track document saves at an earlier priority to ensure we catch all changes
                add_action('elementor/document/before_save', array($this, 'track_elementor_before_save'), 5, 2);
                
                // Backup: Also track via ajax actions to ensure we catch all save events
                add_action('wp_ajax_elementor_ajax', array($this, 'track_elementor_ajax'), 5);
                add_action('wp_ajax_elementor_save_builder', array($this, 'track_elementor_save_builder'), 5);
                
                // Track when switching to Elementor editor from the native WordPress editor
                add_action('elementor/editor/init', array($this, 'track_elementor_edit_start'), 5);
                
                // Additional tracking points for special Elementor events
                add_action('elementor/editor/after_enqueue_styles', array($this, 'track_elementor_edit_start'), 5);
                
                // Additional hooks for global widget editing
                add_action('elementor/widget/before_render_content', array($this, 'track_elementor_widget_render'), 5, 1);
                
                // New hooks for better tracking
                add_action('elementor/preview/enqueue_styles', array($this, 'track_elementor_edit_start'), 5);
                add_action('elementor/frontend/after_register_scripts', array($this, 'track_elementor_edit_start'), 5);
                
                // Track when editor is closed
                add_action('wp_ajax_heartbeat', array($this, 'track_elementor_heartbeat'), 5);
                
                $this->session_manager->debug_log('Successfully registered all Elementor hooks', null, defined('ETT_ELEMENTOR_DEBUG') && ETT_ELEMENTOR_DEBUG);
            } else {
                $this->session_manager->debug_log('Elementor hooks already registered, skipping registration', null, false);
            }
        } catch (Exception $e) {
            $this->session_manager->debug_log('Error registering Elementor hooks', array(
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ), true);
        }
    }

    /**
     * Track when Elementor editor is loaded
     *
     * @since    1.0.0
     */
    public function track_elementor_edit_start() {
        try {
            // Get the post ID from the URL
            $post_id = isset($_GET['post']) ? (int)$_GET['post'] : 0;
            
            // Alternative ways to get post ID from Elementor
            if (!$post_id && isset($_GET['editor_post_id'])) {
                $post_id = (int)$_GET['editor_post_id'];
            }
            
            // Check for document ID that's also sometimes used
            if (!$post_id && isset($_REQUEST['document_id'])) {
                $document_id = $_REQUEST['document_id'];
                // If using Elementor Pro, try to get the main post ID
                if (class_exists('\\Elementor\\Plugin')) {
                    $document = \Elementor\Plugin::$instance->documents->get($document_id);
                    if ($document) {
                        $post_id = $document->get_main_id();
                    }
                }
            }
            
            // Check for post_id in POST data (AJAX requests)
            if (!$post_id && isset($_POST['post_id'])) {
                $post_id = (int)$_POST['post_id'];
            }
            
            // Check for editor_post_id in POST data (AJAX requests)
            if (!$post_id && isset($_POST['editor_post_id'])) {
                $post_id = (int)$_POST['editor_post_id'];
            }
            
            // Last resort - try to get from referer
            if (!$post_id && isset($_SERVER['HTTP_REFERER'])) {
                $referer_parts = parse_url($_SERVER['HTTP_REFERER']);
                if (isset($referer_parts['query'])) {
                    parse_str($referer_parts['query'], $query_vars);
                    if (isset($query_vars['post'])) {
                        $post_id = (int)$query_vars['post'];
                    } else if (isset($query_vars['editor_post_id'])) {
                        $post_id = (int)$query_vars['editor_post_id'];
                    }
                }
            }
            
            if (!$post_id) {
                $this->session_manager->debug_log('Could not determine post ID for Elementor edit start', [
                    'url_params' => $_GET,
                    'request_params' => $_REQUEST,
                    'action' => current_action(),
                ], defined('ETT_ELEMENTOR_DEBUG') && ETT_ELEMENTOR_DEBUG);
                return;
            }
            
            $this->session_manager->debug_log('Elementor edit session started', array(
                'post_id' => $post_id,
                'source' => current_action(),
                'url_params' => $_GET
            ), defined('ETT_ELEMENTOR_DEBUG') && ETT_ELEMENTOR_DEBUG);
            
            // Use the session manager to start tracking
            $this->session_manager->start_tracking_session($post_id);
        } catch (Exception $e) {
            $this->session_manager->debug_log('Error in track_elementor_edit_start', array(
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ), true);
        }
    }

    /**
     * Track Elementor widget render for global widgets
     *
     * @since    1.0.0
     * @param    \Elementor\Widget_Base    $widget    The widget being rendered
     */
    public function track_elementor_widget_render($widget) {
        try {
            if (!$widget) {
                return;
            }
            
            // Only track global widgets
            if (!method_exists($widget, 'is_global') || !$widget->is_global()) {
                return;
            }
            
            // Get the current post being edited
            $post_id = 0;
            if (isset($_REQUEST['editor_post_id'])) {
                $post_id = (int)$_REQUEST['editor_post_id'];
            } else if (isset($_GET['post'])) {
                $post_id = (int)$_GET['post'];
            }
            
            if (!$post_id) {
                return;
            }
            
            $this->session_manager->debug_log('Elementor global widget render', array(
                'post_id' => $post_id,
                'widget_type' => get_class($widget),
                'widget_name' => $widget->get_name()
            ), false);
            
            // Ensure we have a tracking session
            $this->session_manager->start_tracking_session($post_id);
        } catch (Exception $e) {
            $this->session_manager->debug_log('Error in track_elementor_widget_render', array(
                'error' => $e->getMessage()
            ), true);
        }
    }

    /**
     * Track when Elementor is about to save data
     * This catches auto-saves as well
     * 
     * @since    1.0.0
     * @param    array    $data       The data being saved
     * @param    int      $post_id    The post ID
     */
    public function track_elementor_before_save($data, $post_id) {
        try {
            $this->session_manager->debug_log('Elementor before save', array(
                'post_id' => $post_id,
                'has_data' => !empty($data),
                'data_type' => gettype($data),
                'is_autosave' => isset($_POST['is_autosave']) ? $_POST['is_autosave'] : 'unknown',
                'action' => isset($_POST['action']) ? $_POST['action'] : 'unknown',
                'editor_post_id' => isset($_POST['editor_post_id']) ? $_POST['editor_post_id'] : 'none',
                'current_hook' => current_action(),
                'wp_doing_ajax' => wp_doing_ajax() ? 'yes' : 'no',
                'elementor_data_changes' => is_array($data) && !empty($data) ? count($data) : 0
            ), true);
            
            // If this is the first Elementor edit, we need to start a tracking session
            if (!$post_id) {
                $this->session_manager->debug_log('No post_id provided in Elementor save', null, true);
                return;
            }
            
            // Create or extend the session
            $user_id = get_current_user_id();
            $transient_key = 'ett_session_' . $user_id . '_' . $post_id;
            $session_data = get_transient($transient_key);
            
            if (!$session_data) {
                // If no session exists, create one
                $this->session_manager->debug_log('No existing session found for Elementor save, creating new session', array(
                    'post_id' => $post_id
                ), true);
                $this->session_manager->start_tracking_session($post_id);
            } else {
                // Extend the session timeout to ensure it doesn't expire during save process
                $this->session_manager->debug_log('Extending existing session for Elementor save', array(
                    'post_id' => $post_id,
                    'session_start_time' => isset($session_data['start_time']) ? $session_data['start_time'] : 'unknown'
                ), true);
                set_transient($transient_key, $session_data, 12 * HOUR_IN_SECONDS);
            }
        } catch (Exception $e) {
            $this->session_manager->debug_log('Error in track_elementor_before_save', array(
                'error' => $e->getMessage(),
                'post_id' => $post_id
            ), true);
        }
    }

    /**
     * Track Elementor ajax operations
     * Captures and processes Elementor's Ajax-based save operations
     *
     * @since    1.0.0
     */
    public function track_elementor_ajax() {
        try {
            // Only process if this is a save-related action
            if (!isset($_REQUEST['actions']) || !is_string($_REQUEST['actions'])) {
                return;
            }
            
            $actions = json_decode(stripslashes($_REQUEST['actions']), true);
            if (!is_array($actions)) {
                return;
            }
            
            // Check if any save-related actions are being performed
            $save_actions = array_intersect(array_keys($actions), array(
                'save_builder', 
                'save_template', 
                'update_element', 
                'save_page_settings',
                'save_document_settings'
            ));
            
            if (empty($save_actions)) {
                return;
            }
            
            // Extract the post ID
            $post_id = 0;
            if (isset($_REQUEST['editor_post_id'])) {
                $post_id = (int)$_REQUEST['editor_post_id'];
            } else if (isset($_POST['editor_post_id'])) {
                $post_id = (int)$_POST['editor_post_id'];
            } else if (isset($_REQUEST['post_id'])) {
                $post_id = (int)$_REQUEST['post_id'];
            } else if (isset($_POST['post_id'])) {
                $post_id = (int)$_POST['post_id'];
            }
            
            if (!$post_id) {
                // Try to extract post ID from the actions data
                foreach ($actions as $action_data) {
                    if (isset($action_data['data']['id'])) {
                        $post_id = (int)$action_data['data']['id'];
                        break;
                    }
                }
            }
            
            if (!$post_id) {
                $this->session_manager->debug_log('Could not determine post ID for Elementor ajax save', array(
                    'actions' => $save_actions,
                    'request' => $_REQUEST
                ), defined('ETT_ELEMENTOR_DEBUG') && ETT_ELEMENTOR_DEBUG);
                return;
            }
            
            $this->session_manager->debug_log('Elementor ajax save detected', array(
                'post_id' => $post_id,
                'actions' => $save_actions
            ), defined('ETT_ELEMENTOR_DEBUG') && ETT_ELEMENTOR_DEBUG);
            
            // Process the save
            $post = get_post($post_id);
            if ($post) {
                $this->session_manager->track_edit_end($post_id, $post, true);
            }
        } catch (Exception $e) {
            $this->session_manager->debug_log('Error in track_elementor_ajax', array(
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ), true);
        }
    }

    /**
     * Track Elementor save_builder ajax action
     * Handles direct save operations from the Elementor editor
     *
     * @since    1.0.0
     */
    public function track_elementor_save_builder() {
        try {
            if (!isset($_POST['post_id'])) {
                return;
            }
            
            $post_id = (int)$_POST['post_id'];
            
            $this->session_manager->debug_log('Elementor save_builder action detected', array(
                'post_id' => $post_id,
                'is_autosave' => isset($_POST['is_autosave']) ? $_POST['is_autosave'] : false,
                'data_size' => isset($_POST['data']) ? strlen($_POST['data']) : 0
            ), defined('ETT_ELEMENTOR_DEBUG') && ETT_ELEMENTOR_DEBUG);
            
            // Check if we have a session and update the Elementor data
            if (isset($_POST['data']) && !empty($_POST['data'])) {
                $user_id = get_current_user_id();
                $transient_key = 'ett_session_' . $user_id . '_' . $post_id;
                $session_data = get_transient($transient_key);
                
                if ($session_data) {
                    // Update the session with the new Elementor data hash
                    $session_data['final_elementor_data'] = md5($_POST['data']);
                    $session_data['final_elementor_data_length'] = strlen($_POST['data']);
                    
                    // Check if there are actual changes in the Elementor data
                    $has_elementor_changes = false;
                    if (isset($session_data['initial_elementor_data']) && 
                        $session_data['initial_elementor_data'] !== $session_data['final_elementor_data']) {
                        $has_elementor_changes = true;
                    }
                    
                    $session_data['has_elementor_changes'] = $has_elementor_changes;
                    
                    $this->session_manager->debug_log('Updated session with Elementor data changes from save_builder', array(
                        'post_id' => $post_id,
                        'has_changes' => $has_elementor_changes ? 'yes' : 'no',
                        'data_length' => strlen($_POST['data'])
                    ), defined('ETT_ELEMENTOR_DEBUG') && ETT_ELEMENTOR_DEBUG);
                    
                    // Update the session
                    set_transient($transient_key, $session_data, 12 * HOUR_IN_SECONDS);
                }
            }
            
            $post = get_post($post_id);
            if ($post) {
                $this->session_manager->track_edit_end($post_id, $post, true);
            }
        } catch (Exception $e) {
            $this->session_manager->debug_log('Error in track_elementor_save_builder', array(
                'error' => $e->getMessage(),
                'post_id' => isset($_POST['post_id']) ? $_POST['post_id'] : 'undefined'
            ), true);
        }
    }

    /**
     * Track when Elementor editor completes a save
     * 
     * @since    1.0.0
     * @param    int      $post_id       The post ID
     * @param    array    $editor_data    The editor data
     */
    public function track_elementor_after_save($post_id, $editor_data) {
        try {
            $this->session_manager->debug_log('Elementor after save', array(
                'post_id' => $post_id,
                'has_editor_data' => !empty($editor_data),
                'editor_data_count' => is_array($editor_data) ? count($editor_data) : 'not_array',
                'current_action' => current_action(),
                'doing_ajax' => defined('DOING_AJAX') && DOING_AJAX ? 'yes' : 'no',
                'wp_doing_ajax' => wp_doing_ajax() ? 'yes' : 'no',
                'is_preview' => isset($_POST['wp-preview']) ? $_POST['wp-preview'] : 'none',
                'is_autosave' => isset($_POST['is_autosave']) ? $_POST['is_autosave'] : 'none'
            ), defined('ETT_ELEMENTOR_DEBUG') && ETT_ELEMENTOR_DEBUG);
            
            if (!$post_id) {
                $this->session_manager->debug_log('Invalid post ID in Elementor after_save', null, true);
                return;
            }
            
            // Check if we have a session and update the Elementor data hash
            $user_id = get_current_user_id();
            $transient_key = 'ett_session_' . $user_id . '_' . $post_id;
            $session_data = get_transient($transient_key);
            
            if ($session_data) {
                // Get the current Elementor data
                $elementor_data = get_post_meta($post_id, '_elementor_data', true);
                
                // Update the session with the new Elementor data hash
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
                    
                    $this->session_manager->debug_log('Updated session with Elementor data changes', array(
                        'post_id' => $post_id,
                        'has_changes' => $has_elementor_changes ? 'yes' : 'no',
                        'initial_hash' => isset($session_data['initial_elementor_data']) ? 
                            substr($session_data['initial_elementor_data'], 0, 8) . '...' : 'none',
                        'final_hash' => substr($session_data['final_elementor_data'], 0, 8) . '...',
                        'initial_length' => isset($session_data['initial_elementor_data_length']) ? 
                            $session_data['initial_elementor_data_length'] : 0,
                        'final_length' => $session_data['final_elementor_data_length']
                    ), defined('ETT_ELEMENTOR_DEBUG') && ETT_ELEMENTOR_DEBUG);
                    
                    // Update the session
                    set_transient($transient_key, $session_data, 12 * HOUR_IN_SECONDS);
                }
            }
            
            $post = get_post($post_id);
            if ($post) {
                $this->session_manager->track_edit_end($post_id, $post, true);
            } else {
                $this->session_manager->debug_log('Could not retrieve post object for Elementor after_save', array(
                    'post_id' => $post_id
                ), true);
            }
        } catch (Exception $e) {
            $this->session_manager->debug_log('Error in track_elementor_after_save', array(
                'error' => $e->getMessage(),
                'post_id' => $post_id
            ), true);
        }
    }

    /**
     * Track when Elementor saves a document
     *
     * @since    1.0.0
     * @param    \Elementor\Core\Base\Document    $document    The document being saved
     * @param    array                            $data        The data being saved
     */
    public function track_elementor_edit_end($document, $data) {
        try {
            // Get the main document ID
            $post_id = $document->get_main_id();
            
            if (!$post_id) {
                $this->session_manager->debug_log('No post ID found in Elementor document save', array(
                    'document_class' => get_class($document)
                ), true);
                return;
            }
            
            $this->session_manager->debug_log('Elementor document save', array(
                'post_id' => $post_id,
                'document_type' => get_class($document),
                'is_autosave' => isset($data['status']) && $data['status'] === 'autosave',
                'status' => isset($data['status']) ? $data['status'] : 'unknown',
                'wp_preview' => isset($_POST['wp-preview']) ? $_POST['wp-preview'] : 'none',
                'data_elements' => isset($data['elements']) ? 'yes' : 'no',
                'data_settings' => isset($data['settings']) ? 'yes' : 'no',
                'data_size' => !empty($data) ? count($data) : 0,
                'current_hook' => current_action()
            ), true);
            
            // Manually fetch the post to pass to track_edit_end
            $post = get_post($post_id);
            if ($post) {
                $this->session_manager->track_edit_end($post_id, $post, true);
            } else {
                $this->session_manager->debug_log('Could not retrieve post object for Elementor save', array(
                    'post_id' => $post_id
                ), true);
            }
        } catch (Exception $e) {
            $this->session_manager->debug_log('Error in track_elementor_edit_end', array(
                'error' => $e->getMessage(),
                'document_class' => isset($document) ? get_class($document) : 'undefined'
            ), true);
        }
    }

    /**
     * Track Elementor heartbeat to detect when editor is closed
     *
     * @since    1.0.0
     */
    public function track_elementor_heartbeat() {
        if (!isset($_POST['data']) || !is_array($_POST['data']) || !isset($_POST['screen_id'])) {
            return;
        }
        
        // Check if this is an Elementor heartbeat
        if (strpos($_POST['screen_id'], 'elementor') === false) {
            return;
        }
        
        $this->session_manager->debug_log('Elementor heartbeat detected', array(
            'screen_id' => $_POST['screen_id'],
            'has_data' => !empty($_POST['data'])
        ), defined('ETT_ELEMENTOR_DEBUG') && ETT_ELEMENTOR_DEBUG);
        
        // Get post ID from various sources
        $post_id = 0;
        
        // Try to get post ID from heartbeat data
        if (isset($_POST['data']['elementor_post_id'])) {
            $post_id = (int)$_POST['data']['elementor_post_id'];
        } else if (isset($_POST['post_id'])) {
            $post_id = (int)$_POST['post_id'];
        } else if (isset($_POST['data']['wp_autosave']) && isset($_POST['data']['wp_autosave']['post_id'])) {
            $post_id = (int)$_POST['data']['wp_autosave']['post_id'];
        }
        
        // If no post ID found, try to get from referer
        if (!$post_id && isset($_SERVER['HTTP_REFERER'])) {
            $referer_parts = parse_url($_SERVER['HTTP_REFERER']);
            if (isset($referer_parts['query'])) {
                parse_str($referer_parts['query'], $query_vars);
                if (isset($query_vars['post'])) {
                    $post_id = (int)$query_vars['post'];
                } else if (isset($query_vars['editor_post_id'])) {
                    $post_id = (int)$query_vars['editor_post_id'];
                }
            }
        }
        
        if (!$post_id) {
            $this->session_manager->debug_log('Could not determine post ID for Elementor heartbeat', array(
                'screen_id' => $_POST['screen_id'],
                'data' => $_POST['data']
            ), defined('ETT_ELEMENTOR_DEBUG') && ETT_ELEMENTOR_DEBUG);
            return;
        }
        
        // Check if we have an active session for this post
        $user_id = get_current_user_id();
        $transient_key = 'ett_session_' . $user_id . '_' . $post_id;
        $session_data = get_transient($transient_key);
        
        if (!$session_data) {
            return;
        }
        
        // Update the session with the latest Elementor data
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
            
            // Update the session
            set_transient($transient_key, $session_data, 12 * HOUR_IN_SECONDS);
            
            $this->session_manager->debug_log('Updated session with Elementor data from heartbeat', array(
                'post_id' => $post_id,
                'has_changes' => $has_elementor_changes ? 'yes' : 'no',
                'data_length' => strlen($elementor_data)
            ), defined('ETT_ELEMENTOR_DEBUG') && ETT_ELEMENTOR_DEBUG);
        }
        
        // If this is the last heartbeat before editor is closed, we should finalize the session
        if (isset($_POST['data']['wp_autosave']) || 
            (isset($_POST['data']['elementor_heartbeat']) && $_POST['data']['elementor_heartbeat'] === 'last')) {
            
            $this->session_manager->debug_log('Finalizing Elementor session from heartbeat', array(
                'post_id' => $post_id,
                'is_autosave' => isset($_POST['data']['wp_autosave']) ? 'yes' : 'no'
            ), defined('ETT_ELEMENTOR_DEBUG') && ETT_ELEMENTOR_DEBUG);
            
            $post = get_post($post_id);
            if ($post) {
                $this->session_manager->track_edit_end($post_id, $post, true);
            }
        }
    }
}
