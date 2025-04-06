<?php
/**
 * Database operations for the plugin.
 *
 * Handles database table creation and upgrades.
 *
 * @since      1.0.0
 */

// If this file is called directly, abort.
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Database operations for the plugin.
 */
class Editing_Time_Tracker_DB {

    /**
     * Database table name
     *
     * @var string
     */
    private $table_name;

    /**
     * Initialize the class and set its properties.
     *
     * @since    1.0.0
     */
    public function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'editing_sessions';
    }

    /**
     * Plugin activation
     * 
     * Creates the database table if it doesn't exist
     *
     * @since    1.0.0
     */
    public function activate() {
        global $wpdb;
        
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE {$this->table_name} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint(20) unsigned NOT NULL,
            post_id bigint(20) unsigned NOT NULL,
            start_time datetime NOT NULL,
            end_time datetime NOT NULL,
            duration int(11) unsigned NOT NULL,
            initial_content_length int(11) unsigned NOT NULL DEFAULT 0,
            final_content_length int(11) unsigned NOT NULL DEFAULT 0,
            initial_word_count int(11) unsigned NOT NULL DEFAULT 0,
            final_word_count int(11) unsigned NOT NULL DEFAULT 0,
            activity_summary text NOT NULL,
            PRIMARY KEY  (id),
            KEY user_id (user_id),
            KEY post_id (post_id)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }

    /**
     * Get the table name
     *
     * @since    1.0.0
     * @return   string    The table name
     */
    public function get_table_name() {
        return $this->table_name;
    }

    /**
     * Insert a new session record
     *
     * @since    1.0.0
     * @param    array    $data    The session data
     * @return   int|false         The inserted ID or false on failure
     */
    public function insert_session($data) {
        global $wpdb;
        
        $result = $wpdb->insert($this->table_name, $data);
        
        if (false === $result) {
            $this->log_error('Database error when inserting session', $wpdb->last_error);
            return false;
        }
        
        return $wpdb->insert_id;
    }

    /**
     * Get sessions with optional filtering
     *
     * @since    1.0.0
     * @param    array    $args    Query arguments
     * @return   array              Array of session objects
     */
    public function get_sessions($args = array()) {
        global $wpdb;
        
        $defaults = array(
            'user_id' => 0,
            'post_id' => 0,
            'start_date' => '',
            'end_date' => '',
            'orderby' => 'start_time',
            'order' => 'DESC',
            'limit' => 100,
            'offset' => 0
        );
        
        $args = wp_parse_args($args, $defaults);
        
        $where = array();
        if ($args['user_id']) $where[] = $wpdb->prepare("user_id = %d", $args['user_id']);
        if ($args['post_id']) $where[] = $wpdb->prepare("post_id = %d", $args['post_id']);
        if ($args['start_date']) $where[] = $wpdb->prepare("start_time >= %s", $args['start_date']);
        if ($args['end_date']) $where[] = $wpdb->prepare("start_time <= %s", $args['end_date'] . ' 23:59:59');
        
        $query = "SELECT * FROM {$this->table_name}";
        if (!empty($where)) $query .= " WHERE " . implode(" AND ", $where);
        
        $orderby = sanitize_sql_orderby($args['orderby'] . ' ' . $args['order']);
        $query .= " ORDER BY {$orderby}";
        
        if ($args['limit'] > 0) {
            $query .= $wpdb->prepare(" LIMIT %d, %d", $args['offset'], $args['limit']);
        }
        
        return $wpdb->get_results($query);
    }

    /**
     * Get total duration with optional filtering
     *
     * @since    1.0.0
     * @param    array    $args    Query arguments
     * @return   int               Total duration in seconds
     */
    public function get_total_duration($args = array()) {
        global $wpdb;
        
        $defaults = array(
            'user_id' => 0,
            'post_id' => 0,
            'start_date' => '',
            'end_date' => ''
        );
        
        $args = wp_parse_args($args, $defaults);
        
        $where = array();
        if ($args['user_id']) $where[] = $wpdb->prepare("user_id = %d", $args['user_id']);
        if ($args['post_id']) $where[] = $wpdb->prepare("post_id = %d", $args['post_id']);
        if ($args['start_date']) $where[] = $wpdb->prepare("start_time >= %s", $args['start_date']);
        if ($args['end_date']) $where[] = $wpdb->prepare("start_time <= %s", $args['end_date'] . ' 23:59:59');
        
        $query = "SELECT SUM(duration) FROM {$this->table_name}";
        if (!empty($where)) $query .= " WHERE " . implode(" AND ", $where);
        
        return (int) $wpdb->get_var($query);
    }

    /**
     * Log error message
     *
     * @since    1.0.0
     * @param    string    $message    The error message
     * @param    string    $data       Additional error data
     */
    private function log_error($message, $data = '') {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('Editing Time Tracker: ' . $message . ' - ' . $data);
        }
    }
}
