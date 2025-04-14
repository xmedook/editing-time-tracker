<?php
/**
 * The core plugin class.
 *
 * This is used to define internationalization, admin-specific hooks, and
 * public-facing site hooks.
 *
 * @since      1.0.0
 */

// If this file is called directly, abort.
if (!defined('ABSPATH')) {
    exit;
}

/**
 * The core plugin class.
 */
class Editing_Time_Tracker {

    /**
     * The loader that's responsible for maintaining and registering all hooks that power
     * the plugin.
     *
     * @since    1.0.0
     * @access   protected
     * @var      Editing_Time_Tracker_Loader    $loader    Maintains and registers all hooks for the plugin.
     */
    protected $loader;

    /**
     * The unique identifier of this plugin.
     *
     * @since    1.0.0
     * @access   protected
     * @var      string    $plugin_name    The string used to uniquely identify this plugin.
     */
    protected $plugin_name;

    /**
     * The current version of the plugin.
     *
     * @since    1.0.0
     * @access   protected
     * @var      string    $version    The current version of the plugin.
     */
    protected $version;

    /**
     * Define the core functionality of the plugin.
     *
     * Set the plugin name and the plugin version that can be used throughout the plugin.
     * Load the dependencies, define the locale, and set the hooks for the admin area and
     * the public-facing side of the site.
     *
     * @since    1.0.0
     */
    public function __construct() {
        $this->version = ETT_VERSION;
        $this->plugin_name = 'editing-time-tracker';

        $this->load_dependencies();
        $this->set_locale();
        $this->define_admin_hooks();
    }

    /**
     * Load the required dependencies for this plugin.
     *
     * Include the following files that make up the plugin:
     *
     * - Editing_Time_Tracker_Loader. Orchestrates the hooks of the plugin.
     * - Editing_Time_Tracker_i18n. Defines internationalization functionality.
     * - Editing_Time_Tracker_Admin. Defines all hooks for the admin area.
     * - Editing_Time_Tracker_Session_Manager. Manages editing sessions.
     * - Editing_Time_Tracker_Elementor_Tracker. Handles Elementor integration.
     * - Editing_Time_Tracker_Reports. Handles reporting functionality.
     * - Editing_Time_Tracker_Integrations. Handles integration with external services.
     *
     * @since    1.0.0
     * @access   private
     */
    private function load_dependencies() {
        /**
         * The class responsible for orchestrating the actions and filters of the
         * core plugin.
         */
        require_once ETT_PLUGIN_DIR . 'includes/class-editing-time-tracker-loader.php';

        /**
         * The class responsible for defining internationalization functionality
         * of the plugin.
         */
        require_once ETT_PLUGIN_DIR . 'includes/class-editing-time-tracker-i18n.php';

        /**
         * The class responsible for database operations.
         */
        require_once ETT_PLUGIN_DIR . 'includes/class-editing-time-tracker-db.php';

        /**
         * The class responsible for managing editing sessions.
         */
        require_once ETT_PLUGIN_DIR . 'includes/class-editing-time-tracker-session-manager.php';

        /**
         * The class responsible for Elementor integration.
         */
        require_once ETT_PLUGIN_DIR . 'includes/class-editing-time-tracker-elementor.php';
        
        /**
         * The class responsible for direct script loading.
         */
        require_once ETT_PLUGIN_DIR . 'includes/class-editing-time-tracker-direct-loader.php';
        
        /**
         * The class responsible for AJAX handling.
         */
        require_once ETT_PLUGIN_DIR . 'includes/class-editing-time-tracker-ajax-handler.php';

        /**
         * The class responsible for defining all actions that occur in the admin area.
         */
        require_once ETT_PLUGIN_DIR . 'admin/class-editing-time-tracker-admin.php';

        /**
         * The class responsible for reporting functionality.
         */
        require_once ETT_PLUGIN_DIR . 'admin/class-editing-time-tracker-reports.php';
        
        /**
         * The class responsible for integrations with external services.
         */
        require_once ETT_PLUGIN_DIR . 'includes/class-editing-time-tracker-integrations.php';

        $this->loader = new Editing_Time_Tracker_Loader();
    }

    /**
     * Define the locale for this plugin for internationalization.
     *
     * Uses the Editing_Time_Tracker_i18n class in order to set the domain and to register the hook
     * with WordPress.
     *
     * @since    1.0.0
     * @access   private
     */
    private function set_locale() {
        $plugin_i18n = new Editing_Time_Tracker_i18n();
        $this->loader->add_action('plugins_loaded', $plugin_i18n, 'load_plugin_textdomain');
    }

    /**
     * Register all of the hooks related to the admin area functionality
     * of the plugin.
     *
     * @since    1.0.0
     * @access   private
     */
    private function define_admin_hooks() {
        // Initialize the session manager
        $session_manager = new Editing_Time_Tracker_Session_Manager();
        
        // Initialize the admin class
        $plugin_admin = new Editing_Time_Tracker_Admin($this->get_plugin_name(), $this->get_version());
        
        // Initialize integrations
        $integrations = new Editing_Time_Tracker_Integrations();
        $integrations->init();
        
        // Initialize the direct loader
        $direct_loader = new Editing_Time_Tracker_Direct_Loader($session_manager);
        
        // Initialize the AJAX handler
        $ajax_handler = new Editing_Time_Tracker_AJAX_Handler($session_manager);
        
        // Admin menu and settings
        $this->loader->add_action('admin_menu', $plugin_admin, 'register_admin_menu');
        $this->loader->add_action('admin_notices', $plugin_admin, 'show_tracking_notices');
        
        // Enqueue admin styles and scripts
        $this->loader->add_action('admin_enqueue_scripts', $plugin_admin, 'enqueue_styles');
        $this->loader->add_action('admin_enqueue_scripts', $plugin_admin, 'enqueue_scripts');
        
        // Standard WordPress editor hooks
        $this->loader->add_action('load-post.php', $session_manager, 'track_edit_start');
        $this->loader->add_action('save_post', $session_manager, 'track_edit_end', 10, 3);
        
        // Initialize Elementor tracker if Elementor is active
        if (did_action('elementor/loaded') || class_exists('\\Elementor\\Plugin')) {
            $elementor_tracker = new Editing_Time_Tracker_Elementor($session_manager);
            
            // Register Elementor hooks at the earliest possible point
            $this->loader->add_action('init', $elementor_tracker, 'register_elementor_hooks', 5);
            $this->loader->add_action('elementor/loaded', $elementor_tracker, 'register_elementor_hooks', 5);
            $this->loader->add_action('elementor/init', $elementor_tracker, 'register_elementor_hooks', 5);
        }
        
        // Initialize the reports class
        $reports = new Editing_Time_Tracker_Reports($this->get_plugin_name(), $this->get_version());
    }

    /**
     * Run the loader to execute all of the hooks with WordPress.
     *
     * @since    1.0.0
     */
    public function run() {
        $this->loader->run();
    }

    /**
     * The name of the plugin used to uniquely identify it within the context of
     * WordPress and to define internationalization functionality.
     *
     * @since     1.0.0
     * @return    string    The name of the plugin.
     */
    public function get_plugin_name() {
        return $this->plugin_name;
    }

    /**
     * The reference to the class that orchestrates the hooks with the plugin.
     *
     * @since     1.0.0
     * @return    Editing_Time_Tracker_Loader    Orchestrates the hooks of the plugin.
     */
    public function get_loader() {
        return $this->loader;
    }

    /**
     * Retrieve the version number of the plugin.
     *
     * @since     1.0.0
     * @return    string    The version number of the plugin.
     */
    public function get_version() {
        return $this->version;
    }
}
