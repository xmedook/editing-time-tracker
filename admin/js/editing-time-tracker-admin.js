/**
 * Admin-specific JavaScript
 *
 * Handles the admin-specific functionality.
 *
 * @since      1.0.0
 */

(function($) {
    'use strict';

    // Initialize admin functionality
    $(document).ready(function() {
        // Make admin notices dismissible
        $('.ett-admin-notice').each(function() {
            const $notice = $(this);
            
            // Add dismiss button if not already present
            if (!$notice.find('.notice-dismiss').length) {
                $notice.append('<button type="button" class="notice-dismiss"><span class="screen-reader-text">Dismiss this notice.</span></button>');
            }
            
            // Handle dismiss button click
            $notice.on('click', '.notice-dismiss', function() {
                $notice.fadeOut(300, function() {
                    $notice.remove();
                });
                
                // If the notice has a data-notice-id attribute, remember the dismissal
                const noticeId = $notice.data('notice-id');
                if (noticeId) {
                    // Store the dismissal in user meta via AJAX
                    $.post(ajaxurl, {
                        action: 'ett_dismiss_notice',
                        notice_id: noticeId,
                        nonce: ettAdminData.nonce
                    });
                }
            });
        });
        
        // Toggle debug information
        $('.ett-toggle-debug').on('click', function(e) {
            e.preventDefault();
            const $debugInfo = $(this).closest('.notice').find('.ett-debug-info');
            $debugInfo.toggle();
        });
        
        // Initialize tooltips
        if (typeof $.fn.tipTip === 'function') {
            $('.ett-help-tip').tipTip({
                'attribute': 'data-tip',
                'fadeIn': 50,
                'fadeOut': 50,
                'delay': 200
            });
        }
        
        // Handle post edit screen functionality
        if ($('body').hasClass('post-php') || $('body').hasClass('post-new-php')) {
            initPostEditScreen();
        }
    });

    /**
     * Initialize post edit screen functionality
     */
    function initPostEditScreen() {
        // Get post ID
        const postId = $('#post_ID').val();
        
        if (!postId) {
            return;
        }
        
        // Load post stats if we have the container
        const $statsContainer = $('#ett-post-stats-container');
        if ($statsContainer.length) {
            loadPostStats(postId, $statsContainer);
        }
    }

    /**
     * Load post stats via AJAX
     *
     * @param {number} postId         The post ID
     * @param {object} $statsContainer The stats container element
     */
    function loadPostStats(postId, $statsContainer) {
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'ett_get_post_stats',
                post_id: postId,
                nonce: ettAdminData.nonce
            },
            beforeSend: function() {
                $statsContainer.html('<p><span class="spinner is-active"></span> ' + ettAdminData.strings.loading + '</p>');
            },
            success: function(response) {
                if (response.success) {
                    displayPostStats(response.data, $statsContainer);
                } else {
                    $statsContainer.html('<p>' + ettAdminData.strings.error + '</p>');
                }
            },
            error: function() {
                $statsContainer.html('<p>' + ettAdminData.strings.error + '</p>');
            }
        });
    }

    /**
     * Display post stats
     *
     * @param {object} data           The stats data
     * @param {object} $statsContainer The stats container element
     */
    function displayPostStats(data, $statsContainer) {
        if (!data || !data.total_duration) {
            $statsContainer.html('<p>' + ettAdminData.strings.noData + '</p>');
            return;
        }
        
        let html = '<div class="ett-post-stats">';
        
        // Total editing time
        html += '<div class="ett-post-stats-item">';
        html += '<span class="ett-post-stats-label">' + ettAdminData.strings.totalTime + ':</span> ';
        html += '<span class="ett-post-stats-value">' + data.total_duration_formatted + '</span>';
        html += '</div>';
        
        // Total sessions
        html += '<div class="ett-post-stats-item">';
        html += '<span class="ett-post-stats-label">' + ettAdminData.strings.totalSessions + ':</span> ';
        html += '<span class="ett-post-stats-value">' + data.total_sessions + '</span>';
        html += '</div>';
        
        // Last edited
        if (data.last_session) {
            html += '<div class="ett-post-stats-item">';
            html += '<span class="ett-post-stats-label">' + ettAdminData.strings.lastEdited + ':</span> ';
            html += '<span class="ett-post-stats-value">' + data.last_session.formatted_time + '</span>';
            html += '</div>';
            
            html += '<div class="ett-post-stats-item">';
            html += '<span class="ett-post-stats-label">' + ettAdminData.strings.lastEditedBy + ':</span> ';
            html += '<span class="ett-post-stats-value">' + data.last_session.user_name + '</span>';
            html += '</div>';
        }
        
        // View detailed report link
        html += '<div class="ett-post-stats-item">';
        html += '<a href="' + ettAdminData.reportsUrl + '&report_type=post&post_id=' + data.post_id + '" class="button button-secondary">';
        html += ettAdminData.strings.viewReport;
        html += '</a>';
        html += '</div>';
        
        html += '</div>';
        
        $statsContainer.html(html);
    }

})(jQuery);
