<?php
/**
 * The settings-specific functionality of the plugin.
 *
 * @since      1.1.0
 */

// If this file is called directly, abort.
if (!defined('ABSPATH')) {
    exit;
}

/**
 * The settings-specific functionality of the plugin.
 */
class Editing_Time_Tracker_Settings {

    /**
     * The ID of this plugin.
     *
     * @since    1.1.0
     * @access   private
     * @var      string    $plugin_name    The ID of this plugin.
     */
    private $plugin_name;

    /**
     * The version of this plugin.
     *
     * @since    1.1.0
     * @access   private
     * @var      string    $version    The current version of this plugin.
     */
    private $version;

    /**
     * Initialize the class and set its properties.
     *
     * @since    1.1.0
     * @param    string    $plugin_name       The name of this plugin.
     * @param    string    $version           The version of this plugin.
     */
    public function __construct($plugin_name, $version) {
        $this->plugin_name = $plugin_name;
        $this->version = $version;
        
        // Register settings
        add_action('admin_init', array($this, 'register_settings'));
    }

    /**
     * Register the settings page
     *
     * @since    1.1.0
     */
    public function register_settings_page() {
        add_submenu_page(
            'editing-time-tracker',
            __('Settings', 'editing-time-tracker'),
            __('Settings', 'editing-time-tracker'),
            'manage_options',
            'editing-time-tracker-settings',
            array($this, 'display_settings_page')
        );
    }

    /**
     * Register the settings
     *
     * @since    1.1.0
     */
    public function register_settings() {
        // Register a new setting for the settings page
        register_setting('editing_time_tracker_settings', 'ett_inactivity_timeout', array(
            'type' => 'integer',
            'description' => 'Inactivity timeout in seconds',
            'sanitize_callback' => array($this, 'sanitize_timeout'),
            'default' => 60
        ));
        
        register_setting('editing_time_tracker_settings', 'ett_min_duration_threshold', array(
            'type' => 'integer',
            'description' => 'Minimum duration threshold in seconds',
            'sanitize_callback' => array($this, 'sanitize_timeout'),
            'default' => 3
        ));
        
        // Add a section for general settings
        add_settings_section(
            'ett_general_settings',
            __('General Settings', 'editing-time-tracker'),
            array($this, 'general_settings_section_callback'),
            'editing-time-tracker-settings'
        );
        
        // Add a field for inactivity timeout
        add_settings_field(
            'ett_inactivity_timeout',
            __('Inactivity Timeout (seconds)', 'editing-time-tracker'),
            array($this, 'inactivity_timeout_callback'),
            'editing-time-tracker-settings',
            'ett_general_settings'
        );
        
        // Add a field for minimum duration threshold
        add_settings_field(
            'ett_min_duration_threshold',
            __('Minimum Duration Threshold (seconds)', 'editing-time-tracker'),
            array($this, 'min_duration_threshold_callback'),
            'editing-time-tracker-settings',
            'ett_general_settings'
        );
    }

    /**
     * Sanitize the timeout value
     *
     * @since    1.1.0
     * @param    mixed    $value    The value to sanitize.
     * @return   int                The sanitized value.
     */
    public function sanitize_timeout($value) {
        $value = absint($value);
        return $value > 0 ? $value : 60;
    }

    /**
     * General settings section callback
     *
     * @since    1.1.0
     */
    public function general_settings_section_callback() {
        echo '<p>' . __('Configure general settings for the Editing Time Tracker plugin.', 'editing-time-tracker') . '</p>';
    }

    /**
     * Inactivity timeout field callback
     *
     * @since    1.1.0
     */
    public function inactivity_timeout_callback() {
        $value = get_option('ett_inactivity_timeout', 60);
        echo '<input type="number" id="ett_inactivity_timeout" name="ett_inactivity_timeout" value="' . esc_attr($value) . '" min="5" max="3600" step="1" />';
        echo '<p class="description">' . __('Number of seconds of inactivity before the timer pauses. Minimum 5 seconds, maximum 3600 seconds (1 hour).', 'editing-time-tracker') . '</p>';
    }

    /**
     * Minimum duration threshold field callback
     *
     * @since    1.1.0
     */
    public function min_duration_threshold_callback() {
        $value = get_option('ett_min_duration_threshold', 3);
        echo '<input type="number" id="ett_min_duration_threshold" name="ett_min_duration_threshold" value="' . esc_attr($value) . '" min="1" max="60" step="1" />';
        echo '<p class="description">' . __('Minimum duration in seconds required to record a session. Sessions shorter than this will be ignored.', 'editing-time-tracker') . '</p>';
    }

    /**
     * Display the settings page
     *
     * @since    1.1.0
     */
    public function display_settings_page() {
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            <form method="post" action="options.php">
                <?php
                settings_fields('editing_time_tracker_settings');
                do_settings_sections('editing-time-tracker-settings');
                submit_button();
                ?>
            </form>
        </div>
        <?php
    }

    /**
     * Get the inactivity timeout
     *
     * @since    1.1.0
     * @return   int    The inactivity timeout in seconds.
     */
    public static function get_inactivity_timeout() {
        return (int) get_option('ett_inactivity_timeout', 60);
    }

    /**
     * Get the minimum duration threshold
     *
     * @since    1.1.0
     * @return   int    The minimum duration threshold in seconds.
     */
    public static function get_min_duration_threshold() {
        return (int) get_option('ett_min_duration_threshold', 3);
    }
}
