/**
 * Reports page JavaScript
 *
 * Handles the client-side functionality for the reports page.
 *
 * @since      1.0.0
 */

(function($) {
    'use strict';

    // Chart instances
    let overviewChart = null;
    let userChart = null;
    let postChart = null;

    // Initialize the reports page
    $(document).ready(function() {
        // Initialize date pickers
        $('.ett-datepicker').datepicker({
            dateFormat: ettReportsData.strings.dateFormat,
            maxDate: 0, // Can't select future dates
            changeMonth: true,
            changeYear: true
        });

        // Show/hide filters based on report type
        $('#ett-report-type').on('change', function() {
            const reportType = $(this).val();
            
            // Hide all filter sections first
            $('.ett-user-filter, .ett-post-filter').hide();
            
            // Show relevant filter sections
            if (reportType === 'user') {
                $('.ett-user-filter').show();
            } else if (reportType === 'post') {
                $('.ett-post-filter').show();
            }
        });

        // Handle form submission
        $('#ett-report-filters').on('submit', function(e) {
            e.preventDefault();
            loadReportData();
        });

        // Load default report (overview)
        loadReportData();
    });

    /**
     * Load report data via AJAX
     */
    function loadReportData() {
        // Show loading indicator
        $('#ett-report-loading').show();
        $('#ett-report-error').hide();
        $('.ett-report-section').hide();
        $('#ett-initial-message').hide();
        $('#ett-no-data-message').hide();

        // Get form data
        const formData = $('#ett-report-filters').serialize();
        const reportType = $('#ett-report-type').val();

        // Add nonce to form data
        const data = formData + '&nonce=' + ettReportsData.nonce + '&action=ett_get_report_data';

        // Send AJAX request
        $.post(ettReportsData.ajaxurl, data, function(response) {
            // Hide loading indicator
            $('#ett-report-loading').hide();

            if (response.success) {
                // Process the response data
                processReportData(reportType, response.data);
            } else {
                // Show error message
                $('#ett-report-error').show().find('p').text(response.data.message || ettReportsData.strings.error);
            }
        }).fail(function() {
            // Hide loading indicator
            $('#ett-report-loading').hide();
            
            // Show error message
            $('#ett-report-error').show().find('p').text(ettReportsData.strings.error);
        });
    }

    /**
     * Process and display report data
     *
     * @param {string} reportType The report type
     * @param {object} data       The report data
     */
    function processReportData(reportType, data) {
        // Check if we have data
        if (!data || (reportType === 'overview' && !data.total_duration) || 
            (reportType !== 'overview' && (!data.sessions || data.sessions.length === 0))) {
            $('#ett-no-data-message').show();
            return;
        }

        // Process data based on report type
        switch (reportType) {
            case 'overview':
                displayOverviewReport(data);
                break;
                
            case 'user':
                displayUserReport(data);
                break;
                
            case 'post':
                displayPostReport(data);
                break;
        }
    }

    /**
     * Display overview report
     *
     * @param {object} data The report data
     */
    function displayOverviewReport(data) {
        // Show overview report section
        $('#ett-overview-report').show();
        
        // Display summary data
        $('#ett-overview-total-time').text(data.total_duration_formatted);
        $('#ett-overview-total-sessions').text(data.total_sessions);
        
        // Display chart
        displayChart('ett-overview-chart', overviewChart, data.chart_data);
        
        // Update chart reference
        overviewChart = getChartInstance('ett-overview-chart');
        
        // Display top users
        const usersTable = $('#ett-overview-users');
        usersTable.empty();
        
        if (data.user_durations && data.user_durations.length > 0) {
            data.user_durations.forEach(function(user) {
                usersTable.append(`
                    <tr>
                        <td>${escapeHtml(user.user_name)}</td>
                        <td>${formatDuration(user.duration)}</td>
                        <td>${user.session_count}</td>
                    </tr>
                `);
            });
        } else {
            usersTable.append(`<tr><td colspan="3">${ettReportsData.strings.noData}</td></tr>`);
        }
        
        // Display top posts
        const postsTable = $('#ett-overview-posts');
        postsTable.empty();
        
        if (data.post_durations && data.post_durations.length > 0) {
            data.post_durations.forEach(function(post) {
                postsTable.append(`
                    <tr>
                        <td>${escapeHtml(post.post_title)}</td>
                        <td>${escapeHtml(post.post_type)}</td>
                        <td>${formatDuration(post.duration)}</td>
                        <td>${post.session_count}</td>
                    </tr>
                `);
            });
        } else {
            postsTable.append(`<tr><td colspan="4">${ettReportsData.strings.noData}</td></tr>`);
        }
        
        // Display post types
        const postTypesTable = $('#ett-overview-post-types');
        postTypesTable.empty();
        
        if (data.post_type_durations && data.post_type_durations.length > 0) {
            data.post_type_durations.forEach(function(type) {
                postTypesTable.append(`
                    <tr>
                        <td>${escapeHtml(type.post_type_label)}</td>
                        <td>${formatDuration(type.duration)}</td>
                        <td>${type.post_count}</td>
                        <td>${type.session_count}</td>
                    </tr>
                `);
            });
        } else {
            postTypesTable.append(`<tr><td colspan="4">${ettReportsData.strings.noData}</td></tr>`);
        }
    }

    /**
     * Display user report
     *
     * @param {object} data The report data
     */
    function displayUserReport(data) {
        // Show user report section
        $('#ett-user-report').show();
        
        // Set report title
        const userId = $('#ett-user-id').val();
        const userName = userId > 0 ? $('#ett-user-id option:selected').text() : 'All Users';
        $('#ett-user-report-title').text(userName);
        
        // Display summary data
        $('#ett-user-total-time').text(data.total_duration_formatted);
        $('#ett-user-total-sessions').text(data.total_sessions);
        
        // Display chart
        displayChart('ett-user-chart', userChart, data.chart_data);
        
        // Update chart reference
        userChart = getChartInstance('ett-user-chart');
        
        // Display top posts
        const postsTable = $('#ett-user-posts');
        postsTable.empty();
        
        if (data.post_durations && data.post_durations.length > 0) {
            data.post_durations.forEach(function(post) {
                postsTable.append(`
                    <tr>
                        <td>${escapeHtml(post.post_title)}</td>
                        <td>${formatDuration(post.duration)}</td>
                    </tr>
                `);
            });
        } else {
            postsTable.append(`<tr><td colspan="2">${ettReportsData.strings.noData}</td></tr>`);
        }
        
        // Display sessions
        const sessionsTable = $('#ett-user-sessions');
        sessionsTable.empty();
        
        if (data.sessions && data.sessions.length > 0) {
            data.sessions.forEach(function(session) {
                sessionsTable.append(`
                    <tr>
                        <td>${escapeHtml(session.post_title)}</td>
                        <td>${formatDateTime(session.start_time)}</td>
                        <td>${session.duration_formatted}</td>
                        <td>${session.word_change > 0 ? '+' : ''}${session.word_change}</td>
                        <td>${escapeHtml(session.activity_summary)}</td>
                    </tr>
                `);
            });
        } else {
            sessionsTable.append(`<tr><td colspan="5">${ettReportsData.strings.noData}</td></tr>`);
        }
    }

    /**
     * Display post report
     *
     * @param {object} data The report data
     */
    function displayPostReport(data) {
        // Show post report section
        $('#ett-post-report').show();
        
        // Set report title
        const postId = $('#ett-post-id').val();
        const postTitle = postId > 0 ? $('#ett-post-id option:selected').text() : 'All Posts';
        $('#ett-post-report-title').text(postTitle);
        
        // Display summary data
        $('#ett-post-total-time').text(data.total_duration_formatted);
        $('#ett-post-total-sessions').text(data.total_sessions);
        
        // Display chart
        displayChart('ett-post-chart', postChart, data.chart_data);
        
        // Update chart reference
        postChart = getChartInstance('ett-post-chart');
        
        // Display top users
        const usersTable = $('#ett-post-users');
        usersTable.empty();
        
        if (data.user_durations && data.user_durations.length > 0) {
            data.user_durations.forEach(function(user) {
                usersTable.append(`
                    <tr>
                        <td>${escapeHtml(user.user_name)}</td>
                        <td>${formatDuration(user.duration)}</td>
                    </tr>
                `);
            });
        } else {
            usersTable.append(`<tr><td colspan="2">${ettReportsData.strings.noData}</td></tr>`);
        }
        
        // Display sessions
        const sessionsTable = $('#ett-post-sessions');
        sessionsTable.empty();
        
        if (data.sessions && data.sessions.length > 0) {
            data.sessions.forEach(function(session) {
                sessionsTable.append(`
                    <tr>
                        <td>${escapeHtml(session.user_name)}</td>
                        <td>${formatDateTime(session.start_time)}</td>
                        <td>${session.duration_formatted}</td>
                        <td>${session.word_change > 0 ? '+' : ''}${session.word_change}</td>
                        <td>${escapeHtml(session.activity_summary)}</td>
                    </tr>
                `);
            });
        } else {
            sessionsTable.append(`<tr><td colspan="5">${ettReportsData.strings.noData}</td></tr>`);
        }
    }

    /**
     * Display a chart
     *
     * @param {string} canvasId    The canvas ID
     * @param {object} chartInstance The chart instance (if it exists)
     * @param {object} chartData   The chart data
     */
    function displayChart(canvasId, chartInstance, chartData) {
        // Destroy existing chart if it exists
        if (chartInstance) {
            chartInstance.destroy();
        }
        
        // Get canvas context
        const ctx = document.getElementById(canvasId).getContext('2d');
        
        // Create new chart
        return new Chart(ctx, {
            type: 'bar',
            data: chartData,
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: {
                        beginAtZero: true,
                        title: {
                            display: true,
                            text: 'Minutes'
                        }
                    },
                    x: {
                        title: {
                            display: true,
                            text: 'Date'
                        }
                    }
                }
            }
        });
    }

    /**
     * Get chart instance by canvas ID
     *
     * @param {string} canvasId The canvas ID
     * @return {object}         The chart instance
     */
    function getChartInstance(canvasId) {
        return Chart.getChart(canvasId);
    }

    /**
     * Format duration in seconds to human-readable string
     *
     * @param {number} seconds The duration in seconds
     * @return {string}        The formatted duration
     */
    function formatDuration(seconds) {
        const hours = Math.floor(seconds / 3600);
        const minutes = Math.floor((seconds % 3600) / 60);
        const secs = seconds % 60;
        
        const parts = [];
        if (hours > 0) parts.push(hours + 'h');
        if (minutes > 0) parts.push(minutes + 'm');
        if (secs > 0 || parts.length === 0) parts.push(secs + 's');
        
        return parts.join(' ');
    }

    /**
     * Format date and time
     *
     * @param {string} dateTimeString The date/time string
     * @return {string}               The formatted date/time
     */
    function formatDateTime(dateTimeString) {
        const date = new Date(dateTimeString);
        
        // Format date: YYYY-MM-DD HH:MM
        const year = date.getFullYear();
        const month = String(date.getMonth() + 1).padStart(2, '0');
        const day = String(date.getDate()).padStart(2, '0');
        const hours = String(date.getHours()).padStart(2, '0');
        const minutes = String(date.getMinutes()).padStart(2, '0');
        
        return `${year}-${month}-${day} ${hours}:${minutes}`;
    }

    /**
     * Escape HTML special characters
     *
     * @param {string} text The text to escape
     * @return {string}     The escaped text
     */
    function escapeHtml(text) {
        if (!text) return '';
        
        const map = {
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#039;'
        };
        
        return text.toString().replace(/[&<>"']/g, function(m) { return map[m]; });
    }

})(jQuery);
