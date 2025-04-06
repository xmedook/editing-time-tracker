# Editing Time Tracker

A WordPress plugin that tracks time spent editing posts and pages, providing detailed reports on editing activity.

## Description

Editing Time Tracker is a comprehensive solution for monitoring and analyzing the time spent editing content in WordPress. It works with both the standard WordPress editor and Elementor, providing accurate tracking of editing sessions.

### Key Features

- **Automatic Session Tracking**: Automatically tracks editing sessions when users edit posts or pages
- **Elementor Integration**: Seamlessly works with Elementor editor
- **Detailed Reports**: Provides comprehensive reports on editing activity
- **User-specific Reports**: View reports filtered by user
- **Post-specific Reports**: View reports filtered by post
- **Overview Reports**: Get a bird's-eye view of all editing activity
- **Visual Data Representation**: Charts and graphs for easy data interpretation
- **Session Details**: View detailed information about each editing session
- **Admin Notices**: Receive notifications about tracked editing sessions

## Installation

1. Upload the `editing-time-tracker` folder to the `/wp-content/plugins/` directory
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Access reports from the 'Tools' menu under 'Editing Time Reports'

## Usage

### Viewing Reports

1. Navigate to 'Tools' > 'Editing Time Reports' in the WordPress admin
2. Select the report type (Overview, User, or Post)
3. Apply filters as needed (date range, specific user, specific post)
4. Click 'Generate Report' to view the data

### Understanding the Data

- **Total Editing Time**: The cumulative time spent editing
- **Total Sessions**: The number of editing sessions recorded
- **Daily Editing Time**: A chart showing editing time by day
- **Top Users**: Users who have spent the most time editing
- **Top Posts**: Posts that have received the most editing time
- **Post Types**: Breakdown of editing time by post type
- **Editing Sessions**: Detailed list of individual editing sessions

## Technical Details

### Tracking Methodology

The plugin tracks editing sessions using the following approach:

1. When a user starts editing a post, the plugin records the start time and initial content state
2. When the user saves the post, the plugin records the end time and final content state
3. The plugin calculates the duration and changes made during the session
4. Sessions with significant changes or sufficient duration are recorded in the database

### Elementor Integration

For Elementor, the plugin:

1. Hooks into Elementor's editor loading and saving events
2. Tracks changes to Elementor data structures
3. Combines standard content changes with Elementor-specific changes for accurate reporting

### Data Storage

The plugin stores session data in a custom database table with the following information:

- User ID
- Post ID
- Start time
- End time
- Duration
- Initial content length
- Final content length
- Initial word count
- Final word count
- Activity summary

## Requirements

- WordPress 5.0 or higher
- PHP 7.0 or higher
- MySQL 5.6 or higher

## Frequently Asked Questions

### Does this plugin slow down my site?

No, the plugin is designed to be lightweight and only runs when users are actively editing content in the admin area. It has no impact on front-end performance.

### Does it work with page builders other than Elementor?

Currently, the plugin has specific integration with Elementor. While basic tracking works with any editor that uses standard WordPress hooks, optimized tracking is only available for the standard WordPress editor and Elementor.

### Can I export the report data?

The current version does not include export functionality, but this feature is planned for a future release.

## Changelog

### 1.0.0
- Initial release

## Credits

Developed by koode.mx
