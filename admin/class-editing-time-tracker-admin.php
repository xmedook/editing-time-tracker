<?php
/**
 * The admin-specific functionality of the plugin.
 *
 * @since      1.0.0
 */

// If this file is called directly, abort.
if (!defined('ABSPATH')) {
    exit;
}

/**
 * The admin-specific functionality of the plugin.
 */
class Editing_Time_Tracker_Admin {

    /**
     * The ID of this plugin.
     *
     * @since    1.0.0
     * @access   private
     * @var      string    $plugin_name    The ID of this plugin.
     */
    private $plugin_name;

    /**
     * The version of this plugin.
     *
     * @since    1.0.0
     * @access   private
     * @var      string    $version    The current version of this plugin.
     */
    private $version;

    /**
     * The database handler
     *
     * @since    1.0.0
     * @access   private
     * @var      Editing_Time_Tracker_DB    $db    The database handler.
     */
    private $db;

    /**
     * Initialize the class and set its properties.
     *
     * @since    1.0.0
     * @param    string    $plugin_name       The name of this plugin.
     * @param    string    $version           The version of this plugin.
     */
    public function __construct($plugin_name, $version) {
        $this->plugin_name = $plugin_name;
        $this->version = $version;
        $this->db = new Editing_Time_Tracker_DB();
    }

    /**
     * Register the admin menu
     *
     * @since    1.0.0
     */
    public function register_admin_menu() {
        // Add top-level menu
        add_menu_page(
            __('Editing Time Tracker', 'editing-time-tracker'),
            __('Editing Time', 'editing-time-tracker'),
            'manage_options',
            'editing-time-tracker',
            array($this, 'display_reports_page'),
            'dashicons-clock',
            30
        );
        
        // Add reports as submenu
        add_submenu_page(
            'editing-time-tracker',
            __('Editing Time Reports', 'editing-time-tracker'),
            __('Reports', 'editing-time-tracker'),
            'manage_options',
            'editing-time-tracker',
            array($this, 'display_reports_page')
        );
    }

    /**
     * Display the reports page
     *
     * @since    1.0.0
     */
    public function display_reports_page() {
        // Include the reports page template
        require_once plugin_dir_path(dirname(__FILE__)) . 'admin/templates/reports-page.php';
    }

    /**
     * Show admin notices about tracking status
     *
     * @since    1.0.0
     */
    public function show_tracking_notices() {
        $screen = get_current_screen();
        
        // Only show on post edit screens or after redirecting from saving
        if (!$screen || !in_array($screen->base, array('post', 'edit'))) {
            return;
        }
        
        $user_id = get_current_user_id();
        $tracking_status = get_transient('ett_tracking_status_' . $user_id);
        
        if (!$tracking_status) {
            return;
        }
        
        // Delete the transient so the notice only shows once
        delete_transient('ett_tracking_status_' . $user_id);
        
        $post_title = isset($tracking_status['post_title']) ? $tracking_status['post_title'] : 'Post';
        
        switch ($tracking_status['status']) {
            case 'tracked_full':
                $message = sprintf(
                    __('Editing session tracked successfully for "%s". Duration: %s.', 'editing-time-tracker'),
                    esc_html($post_title),
                    $this->format_duration($tracking_status['duration'])
                );
                $class = 'notice-success';
                break;
                
            case 'tracked_duration_only':
                $message = sprintf(
                    __('Editing session tracked for "%s" based on duration only.', 'editing-time-tracker'),
                    esc_html($post_title)
                );
                $class = 'notice-info';
                break;
                
            case 'tracked_changes_only':
                $message = sprintf(
                    __('Editing session tracked for "%s" based on Elementor changes. Short duration: %s.', 'editing-time-tracker'),
                    esc_html($post_title),
                    $this->format_duration($tracking_status['duration'])
                );
                $class = 'notice-info';
                break;
                
            case 'skipped_no_changes_short_duration':
                $message = sprintf(
                    __('Editing session not tracked for "%s". No significant Elementor changes and duration too short.', 'editing-time-tracker'),
                    esc_html($post_title)
                );
                $class = 'notice-warning';
                break;
                
            case 'error_db':
                $message = sprintf(
                    __('Failed to record editing session for "%s". Database error occurred.', 'editing-time-tracker'),
                    esc_html($post_title)
                );
                $class = 'notice-error';
                break;
                
            case 'no_session':
                $message = sprintf(
                    __('No active editing session found for "%s". Session may have expired.', 'editing-time-tracker'),
                    esc_html($post_title)
                );
                $class = 'notice-warning';
                break;
                
            default:
                return; // Unknown status, don't show notice
        }
        
        printf('<div class="notice %s is-dismissible"><p>%s</p></div>', $class, $message);
        
        // Display debug messages if enabled
        if (defined('ETT_SHOW_DEBUG_NOTICES') && ETT_SHOW_DEBUG_NOTICES) {
            $this->display_debug_messages($user_id);
        }
    }

    /**
     * Display debug messages
     *
     * @since    1.0.0
     * @param    int    $user_id    The user ID
     */
    private function display_debug_messages($user_id) {
        $debug_messages = get_transient('ett_debug_messages_' . $user_id);
        
        if ($debug_messages && !empty($debug_messages)) {
            echo '<div class="notice notice-info is-dismissible">';
            echo '<h3>' . __('Editing Time Tracker Debug Information', 'editing-time-tracker') . '</h3>';
            echo '<pre style="background: #f0f0f0; padding: 10px; overflow: auto; max-height: 300px;">';
            
            foreach ($debug_messages as $debug) {
                $time = isset($debug['time']) ? date('H:i:s', strtotime($debug['time'])) : '';
                echo '<strong>[' . esc_html($time) . '] ' . esc_html($debug['message']) . '</strong><br>';
                
                if (!empty($debug['data'])) {
                    // Format JSON for better readability
                    if (substr($debug['data'], 0, 1) === '{' || substr($debug['data'], 0, 1) === '[') {
                        $json_data = json_decode($debug['data'], true);
                        if ($json_data !== null) {
                            echo json_encode($json_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
                        } else {
                            echo esc_html($debug['data']);
                        }
                    } else {
                        echo esc_html($debug['data']);
                    }
                    echo '<br>';
                }
                echo '<hr>';
            }
            
            echo '</pre>';
            echo '<p><a href="#" onclick="jQuery(this).closest(\'.notice\').find(\'pre\').toggle(); return false;">' . __('Toggle Debug Details', 'editing-time-tracker') . '</a> | ';
            echo '<a href="' . esc_url(add_query_arg('ett_clear_debug', '1')) . '">' . __('Clear Debug Messages', 'editing-time-tracker') . '</a></p>';
            echo '</div>';
            
            // Handle clear debug action
            if (isset($_GET['ett_clear_debug'])) {
                delete_transient('ett_debug_messages_' . $user_id);
                echo '<script>window.location.href = window.location.href.replace(/&ett_clear_debug=1/, "");</script>';
            }
        }
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
     * Register the stylesheets for the admin area.
     *
     * @since    1.0.0
     */
    public function enqueue_styles() {
        wp_enqueue_style(
            $this->plugin_name,
            plugin_dir_url(__FILE__) . 'css/editing-time-tracker-admin.css',
            array(),
            $this->version,
            'all'
        );
    }

    /**
     * Register the JavaScript for the admin area.
     *
     * @since    1.0.0
     */
    public function enqueue_scripts() {
        wp_enqueue_script(
            $this->plugin_name,
            plugin_dir_url(__FILE__) . 'js/editing-time-tracker-admin.js',
            array('jquery'),
            $this->version,
            false
        );
        
        // Localize script with data
        wp_localize_script(
            $this->plugin_name,
            'ettAdminData',
            array(
                'nonce' => wp_create_nonce('ett_admin_nonce'),
                'reportsUrl' => admin_url('admin.php?page=editing-time-tracker'),
                'strings' => array(
                    'loading' => __('Loading...', 'editing-time-tracker'),
                    'error' => __('Error loading data.', 'editing-time-tracker'),
                    'noData' => __('No data available.', 'editing-time-tracker'),
                    'totalTime' => __('Total Editing Time', 'editing-time-tracker'),
                    'totalSessions' => __('Total Sessions', 'editing-time-tracker'),
                    'lastEdited' => __('Last Edited', 'editing-time-tracker'),
                    'lastEditedBy' => __('Last Edited By', 'editing-time-tracker'),
                    'viewReport' => __('View Detailed Report', 'editing-time-tracker')
                )
            )
        );
    }
}
