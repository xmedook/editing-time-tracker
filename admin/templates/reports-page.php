<?php
/**
 * Template for the reports page
 *
 * @since      1.0.0
 */

// If this file is called directly, abort.
if (!defined('ABSPATH')) {
    exit;
}

// Get all users for the user dropdown
$users = get_users(array(
    'orderby' => 'display_name',
    'order' => 'ASC'
));

// Get all posts for the post dropdown (limit to 100 most recent)
$posts = get_posts(array(
    'post_type' => apply_filters('ett_tracked_post_types', array('post', 'page')),
    'posts_per_page' => 100,
    'orderby' => 'date',
    'order' => 'DESC'
));

// Default date range (last 30 days)
$end_date = date('Y-m-d');
$start_date = date('Y-m-d', strtotime('-30 days'));
?>

<div class="wrap">
    <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
    
    <div class="ett-reports-container">
        <div class="ett-reports-sidebar">
            <div class="ett-reports-filters">
                <h2><?php _e('Report Filters', 'editing-time-tracker'); ?></h2>
                
                <form id="ett-report-filters">
                    <div class="ett-filter-section">
                        <label for="ett-report-type"><?php _e('Report Type', 'editing-time-tracker'); ?></label>
                        <select id="ett-report-type" name="report_type">
                            <option value="overview"><?php _e('Overview', 'editing-time-tracker'); ?></option>
                            <option value="user"><?php _e('User Report', 'editing-time-tracker'); ?></option>
                            <option value="post"><?php _e('Post Report', 'editing-time-tracker'); ?></option>
                        </select>
                    </div>
                    
                    <div class="ett-filter-section ett-user-filter" style="display: none;">
                        <label for="ett-user-id"><?php _e('User', 'editing-time-tracker'); ?></label>
                        <select id="ett-user-id" name="user_id">
                            <option value="0"><?php _e('All Users', 'editing-time-tracker'); ?></option>
                            <?php foreach ($users as $user) : ?>
                                <option value="<?php echo esc_attr($user->ID); ?>">
                                    <?php echo esc_html($user->display_name); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="ett-filter-section ett-post-filter" style="display: none;">
                        <label for="ett-post-id"><?php _e('Post', 'editing-time-tracker'); ?></label>
                        <select id="ett-post-id" name="post_id">
                            <option value="0"><?php _e('All Posts', 'editing-time-tracker'); ?></option>
                            <?php foreach ($posts as $post) : ?>
                                <option value="<?php echo esc_attr($post->ID); ?>">
                                    <?php echo esc_html($post->post_title); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="ett-filter-section">
                        <label for="ett-start-date"><?php _e('Start Date', 'editing-time-tracker'); ?></label>
                        <input type="text" id="ett-start-date" name="start_date" value="<?php echo esc_attr($start_date); ?>" class="ett-datepicker" />
                    </div>
                    
                    <div class="ett-filter-section">
                        <label for="ett-end-date"><?php _e('End Date', 'editing-time-tracker'); ?></label>
                        <input type="text" id="ett-end-date" name="end_date" value="<?php echo esc_attr($end_date); ?>" class="ett-datepicker" />
                    </div>
                    
                    <div class="ett-filter-section">
                        <button type="submit" class="button button-primary"><?php _e('Generate Report', 'editing-time-tracker'); ?></button>
                    </div>
                </form>
            </div>
        </div>
        
        <div class="ett-reports-content">
            <div id="ett-report-loading" style="display: none;">
                <span class="spinner is-active"></span>
                <p><?php _e('Loading report data...', 'editing-time-tracker'); ?></p>
            </div>
            
            <div id="ett-report-error" style="display: none;" class="notice notice-error">
                <p></p>
            </div>
            
            <div id="ett-report-container">
                <!-- Overview Report Template -->
                <div id="ett-overview-report" class="ett-report-section" style="display: none;">
                    <h2><?php _e('Overview Report', 'editing-time-tracker'); ?></h2>
                    
                    <div class="ett-report-summary">
                        <div class="ett-summary-box">
                            <h3><?php _e('Total Editing Time', 'editing-time-tracker'); ?></h3>
                            <div class="ett-summary-value" id="ett-overview-total-time"></div>
                        </div>
                        
                        <div class="ett-summary-box">
                            <h3><?php _e('Total Sessions', 'editing-time-tracker'); ?></h3>
                            <div class="ett-summary-value" id="ett-overview-total-sessions"></div>
                        </div>
                    </div>
                    
                    <div class="ett-chart-container">
                        <h3><?php _e('Daily Editing Time', 'editing-time-tracker'); ?></h3>
                        <canvas id="ett-overview-chart"></canvas>
                    </div>
                    
                    <div class="ett-report-tables">
                        <div class="ett-report-table-section">
                            <h3><?php _e('Top Users', 'editing-time-tracker'); ?></h3>
                            <table class="wp-list-table widefat fixed striped">
                                <thead>
                                    <tr>
                                        <th><?php _e('User', 'editing-time-tracker'); ?></th>
                                        <th><?php _e('Total Time', 'editing-time-tracker'); ?></th>
                                        <th><?php _e('Sessions', 'editing-time-tracker'); ?></th>
                                    </tr>
                                </thead>
                                <tbody id="ett-overview-users">
                                    <!-- User data will be inserted here -->
                                </tbody>
                            </table>
                        </div>
                        
                        <div class="ett-report-table-section">
                            <h3><?php _e('Top Posts', 'editing-time-tracker'); ?></h3>
                            <table class="wp-list-table widefat fixed striped">
                                <thead>
                                    <tr>
                                        <th><?php _e('Post', 'editing-time-tracker'); ?></th>
                                        <th><?php _e('Type', 'editing-time-tracker'); ?></th>
                                        <th><?php _e('Total Time', 'editing-time-tracker'); ?></th>
                                        <th><?php _e('Sessions', 'editing-time-tracker'); ?></th>
                                    </tr>
                                </thead>
                                <tbody id="ett-overview-posts">
                                    <!-- Post data will be inserted here -->
                                </tbody>
                            </table>
                        </div>
                        
                        <div class="ett-report-table-section">
                            <h3><?php _e('Post Types', 'editing-time-tracker'); ?></h3>
                            <table class="wp-list-table widefat fixed striped">
                                <thead>
                                    <tr>
                                        <th><?php _e('Post Type', 'editing-time-tracker'); ?></th>
                                        <th><?php _e('Total Time', 'editing-time-tracker'); ?></th>
                                        <th><?php _e('Posts', 'editing-time-tracker'); ?></th>
                                        <th><?php _e('Sessions', 'editing-time-tracker'); ?></th>
                                    </tr>
                                </thead>
                                <tbody id="ett-overview-post-types">
                                    <!-- Post type data will be inserted here -->
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                
                <!-- User Report Template -->
                <div id="ett-user-report" class="ett-report-section" style="display: none;">
                    <h2><?php _e('User Report', 'editing-time-tracker'); ?></h2>
                    <h3 id="ett-user-report-title"></h3>
                    
                    <div class="ett-report-summary">
                        <div class="ett-summary-box">
                            <h3><?php _e('Total Editing Time', 'editing-time-tracker'); ?></h3>
                            <div class="ett-summary-value" id="ett-user-total-time"></div>
                        </div>
                        
                        <div class="ett-summary-box">
                            <h3><?php _e('Total Sessions', 'editing-time-tracker'); ?></h3>
                            <div class="ett-summary-value" id="ett-user-total-sessions"></div>
                        </div>
                    </div>
                    
                    <div class="ett-chart-container">
                        <h3><?php _e('Daily Editing Time', 'editing-time-tracker'); ?></h3>
                        <canvas id="ett-user-chart"></canvas>
                    </div>
                    
                    <div class="ett-report-tables">
                        <div class="ett-report-table-section">
                            <h3><?php _e('Top Posts', 'editing-time-tracker'); ?></h3>
                            <table class="wp-list-table widefat fixed striped">
                                <thead>
                                    <tr>
                                        <th><?php _e('Post', 'editing-time-tracker'); ?></th>
                                        <th><?php _e('Total Time', 'editing-time-tracker'); ?></th>
                                    </tr>
                                </thead>
                                <tbody id="ett-user-posts">
                                    <!-- Post data will be inserted here -->
                                </tbody>
                            </table>
                        </div>
                        
                        <div class="ett-report-table-section">
                            <h3><?php _e('Editing Sessions', 'editing-time-tracker'); ?></h3>
                            <table class="wp-list-table widefat fixed striped">
                                <thead>
                                    <tr>
                                        <th><?php _e('Post', 'editing-time-tracker'); ?></th>
                                        <th><?php _e('Start Time', 'editing-time-tracker'); ?></th>
                                        <th><?php _e('Duration', 'editing-time-tracker'); ?></th>
                                        <th><?php _e('Word Change', 'editing-time-tracker'); ?></th>
                                        <th><?php _e('Activity', 'editing-time-tracker'); ?></th>
                                    </tr>
                                </thead>
                                <tbody id="ett-user-sessions">
                                    <!-- Session data will be inserted here -->
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                
                <!-- Post Report Template -->
                <div id="ett-post-report" class="ett-report-section" style="display: none;">
                    <h2><?php _e('Post Report', 'editing-time-tracker'); ?></h2>
                    <h3 id="ett-post-report-title"></h3>
                    
                    <div class="ett-report-summary">
                        <div class="ett-summary-box">
                            <h3><?php _e('Total Editing Time', 'editing-time-tracker'); ?></h3>
                            <div class="ett-summary-value" id="ett-post-total-time"></div>
                        </div>
                        
                        <div class="ett-summary-box">
                            <h3><?php _e('Total Sessions', 'editing-time-tracker'); ?></h3>
                            <div class="ett-summary-value" id="ett-post-total-sessions"></div>
                        </div>
                    </div>
                    
                    <div class="ett-chart-container">
                        <h3><?php _e('Daily Editing Time', 'editing-time-tracker'); ?></h3>
                        <canvas id="ett-post-chart"></canvas>
                    </div>
                    
                    <div class="ett-report-tables">
                        <div class="ett-report-table-section">
                            <h3><?php _e('Top Users', 'editing-time-tracker'); ?></h3>
                            <table class="wp-list-table widefat fixed striped">
                                <thead>
                                    <tr>
                                        <th><?php _e('User', 'editing-time-tracker'); ?></th>
                                        <th><?php _e('Total Time', 'editing-time-tracker'); ?></th>
                                    </tr>
                                </thead>
                                <tbody id="ett-post-users">
                                    <!-- User data will be inserted here -->
                                </tbody>
                            </table>
                        </div>
                        
                        <div class="ett-report-table-section">
                            <h3><?php _e('Editing Sessions', 'editing-time-tracker'); ?></h3>
                            <table class="wp-list-table widefat fixed striped">
                                <thead>
                                    <tr>
                                        <th><?php _e('User', 'editing-time-tracker'); ?></th>
                                        <th><?php _e('Start Time', 'editing-time-tracker'); ?></th>
                                        <th><?php _e('Duration', 'editing-time-tracker'); ?></th>
                                        <th><?php _e('Word Change', 'editing-time-tracker'); ?></th>
                                        <th><?php _e('Activity', 'editing-time-tracker'); ?></th>
                                    </tr>
                                </thead>
                                <tbody id="ett-post-sessions">
                                    <!-- Session data will be inserted here -->
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                
                <!-- Initial message -->
                <div id="ett-initial-message">
                    <p><?php _e('Select report options and click "Generate Report" to view editing time data.', 'editing-time-tracker'); ?></p>
                </div>
                
                <!-- No data message -->
                <div id="ett-no-data-message" style="display: none;">
                    <p><?php _e('No editing data available for the selected criteria.', 'editing-time-tracker'); ?></p>
                </div>
            </div>
        </div>
    </div>
</div>
