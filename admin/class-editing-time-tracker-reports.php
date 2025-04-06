<?php
/**
 * The reports functionality of the plugin.
 *
 * @since      1.0.0
 */

// If this file is called directly, abort.
if (!defined('ABSPATH')) {
    exit;
}

/**
 * The reports functionality of the plugin.
 */
class Editing_Time_Tracker_Reports {

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
        
        // Register AJAX handlers
        add_action('wp_ajax_ett_get_report_data', array($this, 'ajax_get_report_data'));
        
        // Add hooks for the reports page
        add_action('admin_enqueue_scripts', array($this, 'enqueue_reports_assets'));
    }

    /**
     * Enqueue assets for the reports page
     *
     * @since    1.0.0
     * @param    string    $hook    The current admin page
     */
    public function enqueue_reports_assets($hook) {
        if ('tools_page_editing-time-reports' !== $hook) {
            return;
        }
        
        // Enqueue Chart.js
        wp_enqueue_script(
            'chartjs',
            'https://cdn.jsdelivr.net/npm/chart.js@3.7.1/dist/chart.min.js',
            array(),
            '3.7.1',
            true
        );
        
        // Enqueue Date Range Picker dependencies
        wp_enqueue_script('jquery-ui-datepicker');
        wp_enqueue_style(
            'jquery-ui-datepicker-style',
            'https://code.jquery.com/ui/1.13.1/themes/base/jquery-ui.css',
            array(),
            '1.13.1'
        );
        
        // Enqueue our custom scripts and styles
        wp_enqueue_script(
            $this->plugin_name . '-reports',
            plugin_dir_url(__FILE__) . 'js/editing-time-tracker-reports.js',
            array('jquery', 'chartjs', 'jquery-ui-datepicker'),
            $this->version,
            true
        );
        
        wp_enqueue_style(
            $this->plugin_name . '-reports',
            plugin_dir_url(__FILE__) . 'css/editing-time-tracker-reports.css',
            array(),
            $this->version,
            'all'
        );
        
        // Localize script with data and nonce
        wp_localize_script(
            $this->plugin_name . '-reports',
            'ettReportsData',
            array(
                'ajaxurl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('ett_reports_nonce'),
                'strings' => array(
                    'noData' => __('No data available for the selected period.', 'editing-time-tracker'),
                    'error' => __('Error loading data. Please try again.', 'editing-time-tracker'),
                    'loading' => __('Loading...', 'editing-time-tracker'),
                    'dateFormat' => _x('yy-mm-dd', 'jQuery UI datepicker date format', 'editing-time-tracker')
                )
            )
        );
    }

    /**
     * AJAX handler for getting report data
     *
     * @since    1.0.0
     */
    public function ajax_get_report_data() {
        // Check nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'ett_reports_nonce')) {
            wp_send_json_error(array('message' => 'Invalid security token.'));
        }
        
        // Check user capabilities
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'You do not have permission to access this data.'));
        }
        
        // Get parameters
        $report_type = isset($_POST['report_type']) ? sanitize_text_field($_POST['report_type']) : 'user';
        $start_date = isset($_POST['start_date']) ? sanitize_text_field($_POST['start_date']) : '';
        $end_date = isset($_POST['end_date']) ? sanitize_text_field($_POST['end_date']) : '';
        $user_id = isset($_POST['user_id']) ? intval($_POST['user_id']) : 0;
        $post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;
        
        // Validate and normalize dates
        $start_date = $this->normalize_date_format($start_date);
        if (empty($start_date)) {
            $start_date = date('Y-m-d', strtotime('-30 days'));
        }
        
        $end_date = $this->normalize_date_format($end_date);
        if (empty($end_date)) {
            $end_date = date('Y-m-d');
        }
        
        // Get report data based on type
        $data = array();
        
        switch ($report_type) {
            case 'user':
                $data = $this->get_user_report_data($user_id, $start_date, $end_date);
                break;
                
            case 'post':
                $data = $this->get_post_report_data($post_id, $start_date, $end_date);
                break;
                
            case 'overview':
                $data = $this->get_overview_report_data($start_date, $end_date);
                break;
                
            default:
                wp_send_json_error(array('message' => 'Invalid report type.'));
                break;
        }
        
        wp_send_json_success($data);
    }

    /**
     * Get report data for a specific user
     *
     * @since    1.0.0
     * @param    int       $user_id      The user ID (0 for all users)
     * @param    string    $start_date   The start date (Y-m-d)
     * @param    string    $end_date     The end date (Y-m-d)
     * @return   array                   The report data
     */
    private function get_user_report_data($user_id, $start_date, $end_date) {
        global $wpdb;
        
        $args = array(
            'user_id' => $user_id,
            'start_date' => $start_date,
            'end_date' => $end_date,
            'orderby' => 'start_time',
            'order' => 'ASC'
        );
        
        $sessions = $this->db->get_sessions($args);
        
        if (empty($sessions)) {
            return array(
                'sessions' => array(),
                'total_duration' => 0,
                'total_sessions' => 0,
                'chart_data' => array(
                    'labels' => array(),
                    'datasets' => array()
                )
            );
        }
        
        // Process sessions for display
        $processed_sessions = array();
        $daily_durations = array();
        $post_durations = array();
        
        foreach ($sessions as $session) {
            // Format session for display
            $processed_session = array(
                'id' => $session->id,
                'user_id' => $session->user_id,
                'user_name' => get_user_by('id', $session->user_id) ? get_user_by('id', $session->user_id)->display_name : 'Unknown',
                'post_id' => $session->post_id,
                'post_title' => get_the_title($session->post_id),
                'post_type' => get_post_type($session->post_id),
                'start_time' => $session->start_time,
                'end_time' => $session->end_time,
                'duration' => $session->duration,
                'duration_formatted' => $this->format_duration($session->duration),
                'initial_content_length' => $session->initial_content_length,
                'final_content_length' => $session->final_content_length,
                'content_change' => $session->final_content_length - $session->initial_content_length,
                'initial_word_count' => $session->initial_word_count,
                'final_word_count' => $session->final_word_count,
                'word_change' => $session->final_word_count - $session->initial_word_count,
                'activity_summary' => $session->activity_summary
            );
            
            $processed_sessions[] = $processed_session;
            
            // Aggregate data by day for chart
            $day = date('Y-m-d', strtotime($session->start_time));
            if (!isset($daily_durations[$day])) {
                $daily_durations[$day] = 0;
            }
            $daily_durations[$day] += $session->duration;
            
            // Aggregate data by post
            if (!isset($post_durations[$session->post_id])) {
                $post_durations[$session->post_id] = array(
                    'post_id' => $session->post_id,
                    'post_title' => get_the_title($session->post_id),
                    'duration' => 0
                );
            }
            $post_durations[$session->post_id]['duration'] += $session->duration;
        }
        
        // Sort post durations by total duration (descending)
        usort($post_durations, function($a, $b) {
            return $b['duration'] - $a['duration'];
        });
        
        // Prepare chart data
        $chart_labels = array();
        $chart_data = array();
        
        // Fill in missing days with zero values
        $current_date = new DateTime($start_date);
        $end_date_obj = new DateTime($end_date);
        $end_date_obj->setTime(23, 59, 59); // Include the entire end day
        
        while ($current_date <= $end_date_obj) {
            $day = $current_date->format('Y-m-d');
            $chart_labels[] = $day;
            $chart_data[] = isset($daily_durations[$day]) ? round($daily_durations[$day] / 60, 1) : 0; // Convert to minutes
            $current_date->modify('+1 day');
        }
        
        // Calculate totals
        $total_duration = $this->db->get_total_duration($args);
        $total_sessions = count($sessions);
        
        return array(
            'sessions' => $processed_sessions,
            'total_duration' => $total_duration,
            'total_duration_formatted' => $this->format_duration($total_duration),
            'total_sessions' => $total_sessions,
            'post_durations' => array_values($post_durations), // Convert to indexed array
            'chart_data' => array(
                'labels' => $chart_labels,
                'datasets' => array(
                    array(
                        'label' => __('Editing Time (minutes)', 'editing-time-tracker'),
                        'data' => $chart_data,
                        'backgroundColor' => 'rgba(54, 162, 235, 0.2)',
                        'borderColor' => 'rgba(54, 162, 235, 1)',
                        'borderWidth' => 1
                    )
                )
            )
        );
    }

    /**
     * Get report data for a specific post
     *
     * @since    1.0.0
     * @param    int       $post_id      The post ID (0 for all posts)
     * @param    string    $start_date   The start date (Y-m-d)
     * @param    string    $end_date     The end date (Y-m-d)
     * @return   array                   The report data
     */
    private function get_post_report_data($post_id, $start_date, $end_date) {
        global $wpdb;
        
        $args = array(
            'post_id' => $post_id,
            'start_date' => $start_date,
            'end_date' => $end_date,
            'orderby' => 'start_time',
            'order' => 'ASC'
        );
        
        $sessions = $this->db->get_sessions($args);
        
        if (empty($sessions)) {
            return array(
                'sessions' => array(),
                'total_duration' => 0,
                'total_sessions' => 0,
                'chart_data' => array(
                    'labels' => array(),
                    'datasets' => array()
                )
            );
        }
        
        // Process sessions for display
        $processed_sessions = array();
        $daily_durations = array();
        $user_durations = array();
        
        foreach ($sessions as $session) {
            // Format session for display
            $processed_session = array(
                'id' => $session->id,
                'user_id' => $session->user_id,
                'user_name' => get_user_by('id', $session->user_id) ? get_user_by('id', $session->user_id)->display_name : 'Unknown',
                'post_id' => $session->post_id,
                'post_title' => get_the_title($session->post_id),
                'post_type' => get_post_type($session->post_id),
                'start_time' => $session->start_time,
                'end_time' => $session->end_time,
                'duration' => $session->duration,
                'duration_formatted' => $this->format_duration($session->duration),
                'initial_content_length' => $session->initial_content_length,
                'final_content_length' => $session->final_content_length,
                'content_change' => $session->final_content_length - $session->initial_content_length,
                'initial_word_count' => $session->initial_word_count,
                'final_word_count' => $session->final_word_count,
                'word_change' => $session->final_word_count - $session->initial_word_count,
                'activity_summary' => $session->activity_summary
            );
            
            $processed_sessions[] = $processed_session;
            
            // Aggregate data by day for chart
            $day = date('Y-m-d', strtotime($session->start_time));
            if (!isset($daily_durations[$day])) {
                $daily_durations[$day] = 0;
            }
            $daily_durations[$day] += $session->duration;
            
            // Aggregate data by user
            if (!isset($user_durations[$session->user_id])) {
                $user = get_user_by('id', $session->user_id);
                $user_durations[$session->user_id] = array(
                    'user_id' => $session->user_id,
                    'user_name' => $user ? $user->display_name : 'Unknown',
                    'duration' => 0
                );
            }
            $user_durations[$session->user_id]['duration'] += $session->duration;
        }
        
        // Sort user durations by total duration (descending)
        usort($user_durations, function($a, $b) {
            return $b['duration'] - $a['duration'];
        });
        
        // Prepare chart data
        $chart_labels = array();
        $chart_data = array();
        
        // Fill in missing days with zero values
        $current_date = new DateTime($start_date);
        $end_date_obj = new DateTime($end_date);
        $end_date_obj->setTime(23, 59, 59); // Include the entire end day
        
        while ($current_date <= $end_date_obj) {
            $day = $current_date->format('Y-m-d');
            $chart_labels[] = $day;
            $chart_data[] = isset($daily_durations[$day]) ? round($daily_durations[$day] / 60, 1) : 0; // Convert to minutes
            $current_date->modify('+1 day');
        }
        
        // Calculate totals
        $total_duration = $this->db->get_total_duration($args);
        $total_sessions = count($sessions);
        
        // Get post details
        $post_title = $post_id ? get_the_title($post_id) : __('All Posts', 'editing-time-tracker');
        $post_type = $post_id ? get_post_type($post_id) : '';
        $post_type_label = $post_id ? get_post_type_object($post_type)->labels->singular_name : '';
        
        return array(
            'sessions' => $processed_sessions,
            'post_title' => $post_title,
            'post_type' => $post_type,
            'post_type_label' => $post_type_label,
            'total_duration' => $total_duration,
            'total_duration_formatted' => $this->format_duration($total_duration),
            'total_sessions' => $total_sessions,
            'user_durations' => array_values($user_durations), // Convert to indexed array
            'chart_data' => array(
                'labels' => $chart_labels,
                'datasets' => array(
                    array(
                        'label' => __('Editing Time (minutes)', 'editing-time-tracker'),
                        'data' => $chart_data,
                        'backgroundColor' => 'rgba(75, 192, 192, 0.2)',
                        'borderColor' => 'rgba(75, 192, 192, 1)',
                        'borderWidth' => 1
                    )
                )
            )
        );
    }

    /**
     * Get overview report data
     *
     * @since    1.0.0
     * @param    string    $start_date   The start date (Y-m-d)
     * @param    string    $end_date     The end date (Y-m-d)
     * @return   array                   The report data
     */
    private function get_overview_report_data($start_date, $end_date) {
        global $wpdb;
        
        $args = array(
            'start_date' => $start_date,
            'end_date' => $end_date,
            'orderby' => 'start_time',
            'order' => 'ASC'
        );
        
        $sessions = $this->db->get_sessions($args);
        
        if (empty($sessions)) {
            return array(
                'total_duration' => 0,
                'total_sessions' => 0,
                'chart_data' => array(
                    'labels' => array(),
                    'datasets' => array()
                )
            );
        }
        
        // Aggregate data
        $daily_durations = array();
        $user_durations = array();
        $post_durations = array();
        $post_type_durations = array();
        
        foreach ($sessions as $session) {
            // Aggregate data by day for chart
            $day = date('Y-m-d', strtotime($session->start_time));
            if (!isset($daily_durations[$day])) {
                $daily_durations[$day] = 0;
            }
            $daily_durations[$day] += $session->duration;
            
            // Aggregate data by user
            if (!isset($user_durations[$session->user_id])) {
                $user = get_user_by('id', $session->user_id);
                $user_durations[$session->user_id] = array(
                    'user_id' => $session->user_id,
                    'user_name' => $user ? $user->display_name : 'Unknown',
                    'duration' => 0,
                    'session_count' => 0
                );
            }
            $user_durations[$session->user_id]['duration'] += $session->duration;
            $user_durations[$session->user_id]['session_count']++;
            
            // Aggregate data by post
            if (!isset($post_durations[$session->post_id])) {
                $post_title = get_the_title($session->post_id);
                $post_type = get_post_type($session->post_id);
                $post_durations[$session->post_id] = array(
                    'post_id' => $session->post_id,
                    'post_title' => $post_title ? $post_title : 'Unknown',
                    'post_type' => $post_type ? $post_type : 'Unknown',
                    'duration' => 0,
                    'session_count' => 0
                );
            }
            $post_durations[$session->post_id]['duration'] += $session->duration;
            $post_durations[$session->post_id]['session_count']++;
            
            // Aggregate data by post type
            $post_type = get_post_type($session->post_id);
            if (!$post_type) {
                $post_type = 'unknown';
            }
            
            if (!isset($post_type_durations[$post_type])) {
                $post_type_obj = get_post_type_object($post_type);
                $post_type_durations[$post_type] = array(
                    'post_type' => $post_type,
                    'post_type_label' => $post_type_obj ? $post_type_obj->labels->name : ucfirst($post_type),
                    'duration' => 0,
                    'session_count' => 0,
                    'post_count' => 0
                );
            }
            $post_type_durations[$post_type]['duration'] += $session->duration;
            $post_type_durations[$post_type]['session_count']++;
            
            // Count unique posts per type
            if (!isset($post_type_durations[$post_type]['posts'])) {
                $post_type_durations[$post_type]['posts'] = array();
            }
            if (!in_array($session->post_id, $post_type_durations[$post_type]['posts'])) {
                $post_type_durations[$post_type]['posts'][] = $session->post_id;
                $post_type_durations[$post_type]['post_count']++;
            }
        }
        
        // Sort by duration (descending)
        usort($user_durations, function($a, $b) {
            return $b['duration'] - $a['duration'];
        });
        
        usort($post_durations, function($a, $b) {
            return $b['duration'] - $a['duration'];
        });
        
        usort($post_type_durations, function($a, $b) {
            return $b['duration'] - $a['duration'];
        });
        
        // Prepare chart data
        $chart_labels = array();
        $chart_data = array();
        
        // Fill in missing days with zero values
        $current_date = new DateTime($start_date);
        $end_date_obj = new DateTime($end_date);
        $end_date_obj->setTime(23, 59, 59); // Include the entire end day
        
        while ($current_date <= $end_date_obj) {
            $day = $current_date->format('Y-m-d');
            $chart_labels[] = $day;
            $chart_data[] = isset($daily_durations[$day]) ? round($daily_durations[$day] / 60, 1) : 0; // Convert to minutes
            $current_date->modify('+1 day');
        }
        
        // Calculate totals
        $total_duration = $this->db->get_total_duration($args);
        $total_sessions = count($sessions);
        
        // Clean up post type data
        foreach ($post_type_durations as &$type_data) {
            unset($type_data['posts']); // Remove the posts array, we only needed it for counting
        }
        
        return array(
            'total_duration' => $total_duration,
            'total_duration_formatted' => $this->format_duration($total_duration),
            'total_sessions' => $total_sessions,
            'user_durations' => array_values($user_durations), // Convert to indexed array
            'post_durations' => array_values($post_durations), // Convert to indexed array
            'post_type_durations' => array_values($post_type_durations), // Convert to indexed array
            'chart_data' => array(
                'labels' => $chart_labels,
                'datasets' => array(
                    array(
                        'label' => __('Editing Time (minutes)', 'editing-time-tracker'),
                        'data' => $chart_data,
                        'backgroundColor' => 'rgba(153, 102, 255, 0.2)',
                        'borderColor' => 'rgba(153, 102, 255, 1)',
                        'borderWidth' => 1
                    )
                )
            )
        );
    }

    /**
     * Normalize date format to Y-m-d
     * 
     * @since    1.0.0
     * @param    string    $date_string    The date string to normalize
     * @return   string                    Normalized date in Y-m-d format or empty string if invalid
     */
    private function normalize_date_format($date_string) {
        if (empty($date_string)) {
            return '';
        }
        
        // Check if the date is already in Y-m-d format
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_string)) {
            return $date_string;
        }
        
        // Try to parse the date using strtotime
        $timestamp = strtotime($date_string);
        if ($timestamp === false) {
            return '';
        }
        
        // Return the date in Y-m-d format
        return date('Y-m-d', $timestamp);
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
}
