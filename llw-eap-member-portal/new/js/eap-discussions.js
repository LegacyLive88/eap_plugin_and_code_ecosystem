// js/eap-discussions.js
/**
 * EAP Discussions - AJAX Handler for Forum Functionality
 * Handles threaded discussions on working group pages.
 */

(function($) {
    'use strict';

    var secureViewerState = {
        initialized: false,
        $modal: null,
        $content: null,
        $filename: null,
        $downloadBtn: null,
        $googleBtn: null,
        lastTrigger: null,
        currentDownloadUrl: null,
        currentGoogleUrl: null,
        currentFileType: null,
        currentPreviewUrl: null,
        activeCsvRequest: null,
        currentCsvUrl: null,
        activeXlsxRequest: null,
        currentRequestId: null,
        requestCounter: 0
    };

    /**
     * Generate a unique request ID
     */
    function generateRequestId() {
        secureViewerState.requestCounter++;
        return secureViewerState.requestCounter + '_' + Date.now();
    }

    /**
     * Add cache-busting parameter to URL
     */
    function addCacheBuster(url) {
        if (!url) {
            return url;
        }
        var separator = url.indexOf('?') === -1 ? '?' : '&';
        return url + separator + '_cb=' + Date.now() + '_' + Math.random().toString(36).substr(2, 9);
    }

    var CSV_PREVIEW_ROW_LIMIT = 500;

    // Initialize on document ready
    $(document).ready(function() {
        initDiscussions();
    });

    function initDiscussions() {
        // Handle file input display
        $(document).on('change', '.eap-discussion-file-input', handleFileSelect);
        
        // Handle discussion form submission
        $(document).on('submit', '.eap-discussion-form', handleDiscussionSubmit);
        
        // Handle reply button click
        $(document).on('click', '.eap-discussion-reply-btn', handleReplyClick);
        
        // Handle reply cancel button
        $(document).on('click', '.eap-reply-cancel-btn', handleReplyCancel);
        
        // Handle vote button click
        $(document).on('click', '.eap-discussion-vote-btn', handleVote);
        
        // Handle pin button click
        $(document).on('click', '.eap-discussion-pin-btn', handlePin);
        
        // Handle delete button click
        $(document).on('click', '.eap-discussion-delete-btn', handleDelete);

        initSecureFileViewer();
    }

    function handleFileSelect(e) {
        var $input = $(this);
        var $form = $input.closest('.eap-discussion-form');
        var $fileName = $form.find('.eap-file-name');
        
        if (this.files && this.files[0]) {
            $fileName.text(this.files[0].name);
        } else {
            $fileName.text('');
        }
    }

    function hydrateCsvPreview(url, downloadUrl) {
        if (!secureViewerState.$content || !url) {
            showCsvError('CSV preview is not available for this file.', downloadUrl);
            return;
        }

        var $csvContainer = secureViewerState.$content.find('.eap-secure-viewer__csv');
        if (!$csvContainer.length) {
            return;
        }

        cancelCsvPreviewRequest();
        
        // Generate unique request ID
        var requestId = generateRequestId();
        secureViewerState.currentCsvUrl = url;

        $csvContainer
            .removeClass('has-error has-content')
            .empty()
            .html(
                '<div class="eap-secure-viewer__csv-loading">' +
                    '<span class="eap-secure-viewer__spinner" aria-hidden="true"></span>' +
                    'Loading CSV preview...' +
                '</div>'
            );

        // Add cache-busting to the URL
        var cacheBustedUrl = addCacheBuster(url);

        secureViewerState.activeCsvRequest = $.ajax({
            url: cacheBustedUrl,
            method: 'GET',
            dataType: 'text',
            cache: false,
            headers: {
                'Cache-Control': 'no-cache, no-store, must-revalidate',
                'Pragma': 'no-cache'
            },
            xhrFields: {
                withCredentials: true
            }
        });

        secureViewerState.activeCsvRequest
            .done(function(responseText) {
                if (secureViewerState.currentCsvUrl !== url) {
                    return;
                }

                var tableHtml = buildCsvTableHtml(responseText);
                if (!tableHtml) {
                    showCsvError('No data to display in this CSV file.', downloadUrl, $csvContainer);
                    return;
                }

                $csvContainer
                    .addClass('has-content')
                    .empty()
                    .html(tableHtml);
            })
            .fail(function() {
                if (secureViewerState.currentCsvUrl !== url) {
                    return;
                }
                showCsvError('Unable to load the CSV preview right now.', downloadUrl, $csvContainer);
            })
            .always(function() {
                secureViewerState.activeCsvRequest = null;
            });
    }

    function cancelCsvPreviewRequest() {
        secureViewerState.currentCsvUrl = null;
        if (secureViewerState.activeCsvRequest && secureViewerState.activeCsvRequest.abort) {
            secureViewerState.activeCsvRequest.abort();
        }
        secureViewerState.activeCsvRequest = null;
    }

    function cancelXlsxPreviewRequest() {
        secureViewerState.currentPreviewUrl = null;
        secureViewerState.currentRequestId = null;
        if (secureViewerState.activeXlsxRequest && secureViewerState.activeXlsxRequest.abort) {
            secureViewerState.activeXlsxRequest.abort();
        }
        secureViewerState.activeXlsxRequest = null;
    }

    function getSpreadsheetPreviewConfig() {
        if (window.eapDiscussions && window.eapDiscussions.preview) {
            return window.eapDiscussions.preview;
        }
        return null;
    }

    function tryServerSpreadsheetPreview(previewUrl, downloadUrl, $container, onFailure, requestId) {
        var config = getSpreadsheetPreviewConfig();

        if (!config || !config.ajaxUrl || !config.nonce) {
            return false;
        }

        if (!$container || !$container.length || !downloadUrl) {
            return false;
        }

        var effectiveRequestId = requestId || generateRequestId();

        cancelXlsxPreviewRequest();
        
        secureViewerState.currentRequestId = effectiveRequestId;
        secureViewerState.currentPreviewUrl = previewUrl;

        // Clear and show loading state
        $container
            .removeClass('has-error has-content')
            .empty()
            .html(
                '<div class="eap-office-loading">' +
                    '<span class="eap-secure-viewer__spinner" aria-hidden="true"></span>' +
                    'Loading spreadsheet...' +
                '</div>'
            );

        secureViewerState.activeXlsxRequest = $.ajax({
            url: config.ajaxUrl,
            method: 'POST',
            dataType: 'json',
            cache: false,
            data: {
                action: config.action || 'eap_preview_spreadsheet',
                nonce: config.nonce,
                download_url: downloadUrl,
                _cb: Date.now() // Cache-busting parameter
            }
        })
        .done(function(response) {
            // Check if this is still the current request
            if (secureViewerState.currentRequestId !== effectiveRequestId) {
                console.log('Server preview request superseded, ignoring response');
                return;
            }

            if (response && response.success && response.data && response.data.html) {
                $container
                    .removeClass('has-error')
                    .addClass('has-content')
                    .empty()
                    .html(response.data.html);
            } else if (typeof onFailure === 'function') {
                var message = response && response.data && response.data.message ? response.data.message : '';
                onFailure(message);
            }
        })
        .fail(function(xhr) {
            if (secureViewerState.currentRequestId !== effectiveRequestId) {
                return;
            }
            if (typeof onFailure === 'function') {
                var errorMessage = '';
                if (xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
                    errorMessage = xhr.responseJSON.data.message;
                }
                onFailure(errorMessage);
            }
        })
        .always(function() {
            secureViewerState.activeXlsxRequest = null;
        });

        return true;
    }

    function showCsvError(message, downloadUrl, $target) {
        var $container = $target && $target.length ? $target : secureViewerState.$content;
        if (!$container || !$container.length) {
            return;
        }

        var safeMessage = escapeHtml(message || 'Unable to load the CSV preview.');
        var fallbackLink = '';

        if (downloadUrl) {
            fallbackLink = ' <a href="' + escapeHtml(downloadUrl) + '" target="_blank" rel="noopener noreferrer">Download the file</a>.';
        }

        $container
            .addClass('has-error')
            .html('<div class="eap-secure-viewer__csv-error">' + safeMessage + fallbackLink + '</div>');
    }

    function buildCsvTableHtml(csvText) {
        if (typeof csvText !== 'string') {
            return '';
        }

        var rows = parseCsv(csvText);
        if (!rows.length) {
            return '';
        }

        var truncated = false;
        if (rows.length > CSV_PREVIEW_ROW_LIMIT) {
            rows = rows.slice(0, CSV_PREVIEW_ROW_LIMIT);
            truncated = true;
        }

        var columnCount = 0;
        rows.forEach(function(row) {
            if (row.length > columnCount) {
                columnCount = row.length;
            }
        });
        if (columnCount === 0) {
            columnCount = 1;
        }

        var header = rows[0];
        if (!header.length) {
            header = [];
            for (var h = 0; h < columnCount; h++) {
                header.push('Column ' + (h + 1));
            }
        } else if (header.length < columnCount) {
            for (var pad = header.length; pad < columnCount; pad++) {
                header.push('Column ' + (pad + 1));
            }
        }

        var bodyRows = rows.slice(1);
        var html = [
            '<div class="eap-secure-viewer__csv-shell">',
                '<div class="eap-secure-viewer__csv-scroll" role="table" aria-label="CSV preview table">',
                    '<table>',
                        '<thead>',
                            '<tr>'
        ];

        for (var i = 0; i < columnCount; i++) {
            html.push('<th scope="col">' + escapeHtml(header[i] || ('Column ' + (i + 1))) + '</th>');
        }

        html.push('</tr></thead><tbody>');

        if (bodyRows.length === 0) {
            html.push('<tr><td colspan="' + columnCount + '">No data rows available.</td></tr>');
        } else {
            bodyRows.forEach(function(row) {
                html.push('<tr>');
                for (var j = 0; j < columnCount; j++) {
                    html.push('<td>' + escapeHtml(row[j] || '') + '</td>');
                }
                html.push('</tr>');
            });
        }

        html.push('</tbody></table></div>');

        if (truncated) {
            html.push('<p class="eap-secure-viewer__csv-note">Showing the first ' + CSV_PREVIEW_ROW_LIMIT + ' rows. Download to view the full dataset.</p>');
        }

        html.push('</div>');

        return html.join('');
    }

    function parseCsv(text) {
        if (typeof text !== 'string' || !text.length) {
            return [];
        }

        var rows = [];
        var current = '';
        var row = [];
        var inQuotes = false;

        for (var i = 0; i < text.length; i++) {
            var char = text[i];

            if (char === '"') {
                if (inQuotes && text[i + 1] === '"') {
                    current += '"';
                    i++;
                } else {
                    inQuotes = !inQuotes;
                }
            } else if (char === ',' && !inQuotes) {
                row.push(current);
                current = '';
            } else if ((char === '\n' || char === '\r') && !inQuotes) {
                if (char === '\r' && text[i + 1] === '\n') {
                    i++;
                }
                row.push(current);
                rows.push(row);
                row = [];
                current = '';
            } else {
                current += char;
            }
        }

        if (current !== '' || row.length) {
            row.push(current);
            rows.push(row);
        }

        return rows.filter(function(columns) {
            if (!columns.length) {
                return false;
            }
            if (columns.length === 1) {
                return columns[0].trim() !== '';
            }
            return true;
        });
    }

    /**
     * Hydrate Office file previews (XLSX, DOCX, PPTX)
     */
    function hydrateOfficePreview(type, url, downloadUrl) {
        if (!secureViewerState.$content || !url) {
            console.error('hydrateOfficePreview: Missing content or URL', { type: type, hasContent: !!secureViewerState.$content, url: url });
            return;
        }

        if (typeof window.EapOfficeViewer === 'undefined') {
            showOfficeError(type, 'Office viewer not loaded. Please download the file to view.', downloadUrl);
            return;
        }

        var $container;
        var containerClass;
        switch (type) {
            case 'xlsx':
                containerClass = '.eap-secure-viewer__xlsx';
                $container = secureViewerState.$content.find(containerClass);
                // Fallback: if not found, try direct child
                if (!$container.length) {
                    $container = secureViewerState.$content.children(containerClass);
                }
                if ($container.length) {
                    // Generate a request ID to track this specific preview request
                    var xlsxRequestId = generateRequestId();
                    secureViewerState.currentRequestId = xlsxRequestId;
                    
                    var fallbackXlsx = function(message) {
                        // Check if this request is still current
                        if (secureViewerState.currentRequestId !== xlsxRequestId) {
                            console.log('Fallback skipped - request superseded');
                            return;
                        }
                        
                        if (message) {
                            console.warn('Spreadsheet preview fallback:', message);
                        }
                        if (window.EapOfficeViewer && window.EapOfficeViewer.renderXlsx) {
                            // Clear container before fallback
                            $container.empty();
                            window.EapOfficeViewer.renderXlsx(url, $container, downloadUrl);
                        } else {
                            showOfficeError(type, message || 'Unable to initialize Excel viewer.', downloadUrl);
                        }
                    };

                    var usedServerPreview = tryServerSpreadsheetPreview(
                        url,
                        downloadUrl,
                        $container,
                        fallbackXlsx,
                        xlsxRequestId
                    );

                    if (!usedServerPreview) {
                        fallbackXlsx();
                    }
                } else {
                    console.error('hydrateOfficePreview: Container not found for xlsx', containerClass);
                    showOfficeError(type, 'Unable to initialize Excel viewer.', downloadUrl);
                }
                break;
            case 'docx':
                containerClass = '.eap-secure-viewer__docx';
                $container = secureViewerState.$content.find(containerClass);
                if (!$container.length) {
                    $container = secureViewerState.$content.children(containerClass);
                }
                if ($container.length) {
                    window.EapOfficeViewer.renderDocx(url, $container, downloadUrl);
                } else {
                    console.error('hydrateOfficePreview: Container not found for docx', containerClass);
                    showOfficeError(type, 'Unable to initialize Word viewer.', downloadUrl);
                }
                break;
            case 'pptx':
                containerClass = '.eap-secure-viewer__pptx';
                $container = secureViewerState.$content.find(containerClass);
                if (!$container.length) {
                    $container = secureViewerState.$content.children(containerClass);
                }
                if ($container.length) {
                    window.EapOfficeViewer.renderPptx(url, $container, downloadUrl);
                } else {
                    console.error('hydrateOfficePreview: Container not found for pptx', containerClass);
                    showOfficeError(type, 'Unable to initialize PowerPoint viewer.', downloadUrl);
                }
                break;
        }
    }

    /**
     * Show error for Office preview
     */
    function showOfficeError(type, message, downloadUrl) {
        var $container;
        switch (type) {
            case 'xlsx':
                $container = secureViewerState.$content.find('.eap-secure-viewer__xlsx');
                break;
            case 'docx':
                $container = secureViewerState.$content.find('.eap-secure-viewer__docx');
                break;
            case 'pptx':
                $container = secureViewerState.$content.find('.eap-secure-viewer__pptx');
                break;
        }

        if (!$container || !$container.length) {
            return;
        }

        var fallbackLink = '';
        if (downloadUrl) {
            fallbackLink = ' <a href="' + escapeHtml(downloadUrl) + '" target="_blank" rel="noopener noreferrer">Download the file</a>.';
        }

        $container
            .addClass('has-error')
            .html(
                '<div class="eap-office-error">' +
                '<span class="eap-office-error-icon">⚠️</span>' +
                '<p>' + escapeHtml(message) + fallbackLink + '</p>' +
                '</div>'
            );
    }

    function setPreviewType(type) {
        var previewType = type || '';
        if (secureViewerState.$modal && secureViewerState.$modal.length) {
            secureViewerState.$modal.attr('data-preview-type', previewType);
        }
        if (secureViewerState.$content && secureViewerState.$content.length) {
            secureViewerState.$content.attr('data-preview-type', previewType);
        }
    }

    function handleDiscussionSubmit(e) {
        e.preventDefault();
        
        var $form = $(this);
        var $section = $form.closest('.eap-discussion-section');
        var $message = $form.find('.eap-discussion-form-message');
        var $submitBtn = $form.find('.eap-discussion-submit-btn');
        
        var postId = $section.data('post-id');
        var postType = $section.data('post-type');
        var content = $form.find('.eap-discussion-textarea').val();
        var parentId = $form.data('parent-id') || 0;
        
        if (!content.trim()) {
            showMessage($message, 'error', 'Please enter a message.');
            return;
        }
        
        // Disable submit button
        $submitBtn.prop('disabled', true).text('Posting...');
        
        // Prepare form data
        var formData = new FormData();
        formData.append('action', 'eap_create_discussion');
        formData.append('nonce', eapDiscussions.nonce);
        formData.append('post_id', postId);
        formData.append('post_type', postType);
        formData.append('content', content);
        formData.append('parent_id', parentId);
        
        // Add file if present
        var fileInput = $form.find('.eap-discussion-file-input')[0];
        if (fileInput && fileInput.files && fileInput.files[0]) {
            formData.append('attachment', fileInput.files[0]);
        }
        
        // Submit via AJAX
        $.ajax({
            url: eapDiscussions.ajaxUrl,
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            success: function(response) {
                if (response.success) {
                    // Clear form
                    $form.find('.eap-discussion-textarea').val('');
                    $form.find('.eap-file-name').text('');
                    if (fileInput) fileInput.value = '';
                    
                    // Add new discussion/reply to the appropriate container
                    if (parentId > 0) {
                        // It's a reply - add to the replies container
                        var $parentItem = $('.eap-discussion-item[data-discussion-id="' + parentId + '"]');
                        var $repliesContainer = $parentItem.find('> .eap-discussion-content-wrapper > .eap-discussion-content > .eap-discussion-replies').first();
                        $repliesContainer.append(response.data.html);
                        
                        // Hide reply form
                        $form.closest('.eap-reply-form-container').slideUp();
                    } else {
                        // It's a top-level discussion - add to discussions list
                        var $list = $section.find('.eap-discussions-list');
                        $list.find('.eap-no-discussions').remove();
                        $list.append(response.data.html);
                    }
                    
                    // Update count
                    $section.find('.eap-discussion-count').text('(' + response.data.count + ')');
                    
                    // Show success message
                    showMessage($message, 'success', response.data.message);
                    
                    // Scroll to new item
                    var $newItem = $('.eap-discussion-item[data-discussion-id]:last');
                    if ($newItem.length) {
                        $('html, body').animate({
                            scrollTop: $newItem.offset().top - 100
                        }, 500);
                    }
                } else {
                    showMessage($message, 'error', response.data.message || 'Failed to post discussion.');
                }
            },
            error: function() {
                showMessage($message, 'error', 'An error occurred. Please try again.');
            },
            complete: function() {
                $submitBtn.prop('disabled', false).text(parentId > 0 ? 'Reply' : 'Post Discussion');
            }
        });
    }

    function handleReplyClick(e) {
        e.preventDefault();
        
        var $btn = $(this);
        var $item = $btn.closest('.eap-discussion-item');
        var $replyForm = $item.find('> .eap-discussion-content-wrapper > .eap-discussion-content > .eap-reply-form-container').first();
        
        // Hide all other reply forms
        $('.eap-reply-form-container').not($replyForm).slideUp();
        
        // Toggle this reply form
        $replyForm.slideToggle();
        
        // Focus on textarea
        if ($replyForm.is(':visible')) {
            $replyForm.find('.eap-discussion-textarea').focus();
        }
    }

    function handleReplyCancel(e) {
        e.preventDefault();
        
        var $btn = $(this);
        var $form = $btn.closest('.eap-reply-form-container');
        
        // Clear and hide form
        $form.find('.eap-discussion-textarea').val('');
        $form.find('.eap-file-name').text('');
        $form.find('.eap-discussion-file-input').val('');
        $form.slideUp();
    }

    function handleVote(e) {
        e.preventDefault();
        
        var $btn = $(this);
        var discussionId = $btn.data('discussion-id');
        
        // Disable button temporarily
        $btn.prop('disabled', true);
        
        $.ajax({
            url: eapDiscussions.ajaxUrl,
            type: 'POST',
            data: {
                action: 'eap_vote_discussion',
                nonce: eapDiscussions.nonce,
                discussion_id: discussionId
            },
            success: function(response) {
                if (response.success) {
                    // Update vote count
                    $btn.find('.eap-vote-count').text(response.data.count);
                    
                    // Toggle voted class
                    if (response.data.added) {
                        $btn.addClass('voted');
                    } else {
                        $btn.removeClass('voted');
                    }
                }
            },
            complete: function() {
                $btn.prop('disabled', false);
            }
        });
    }

    function handlePin(e) {
        e.preventDefault();
        
        var $btn = $(this);
        var discussionId = $btn.data('discussion-id');
        var $item = $btn.closest('.eap-discussion-item');
        
        // Disable button temporarily
        $btn.prop('disabled', true);
        
        $.ajax({
            url: eapDiscussions.ajaxUrl,
            type: 'POST',
            data: {
                action: 'eap_pin_discussion',
                nonce: eapDiscussions.nonce,
                discussion_id: discussionId
            },
            success: function(response) {
                if (response.success) {
                    if (response.data.is_pinned) {
                        $item.addClass('is-pinned');
                        $btn.html('<span class="eap-pin-icon">📌</span> Unpin');
                        
                        // Add pinned badge if not exists
                        if (!$item.find('.eap-discussion-pinned-badge').length) {
                            $item.find('.eap-discussion-meta').append('<span class="eap-discussion-pinned-badge">📌 Pinned</span>');
                        }
                        
                        // Move to top
                        var $list = $item.closest('.eap-discussions-list');
                        $item.prependTo($list);
                    } else {
                        $item.removeClass('is-pinned');
                        $btn.html('<span class="eap-pin-icon">📌</span> Pin');
                        $item.find('.eap-discussion-pinned-badge').remove();
                    }
                }
            },
            complete: function() {
                $btn.prop('disabled', false);
            }
        });
    }

    function handleDelete(e) {
        e.preventDefault();
        
        if (!confirm('Are you sure you want to delete this discussion? This will also delete all nested replies. This action cannot be undone.')) {
            return;
        }
        
        var $btn = $(this);
        var discussionId = $btn.data('discussion-id');
        var $item = $btn.closest('.eap-discussion-item');
        var $section = $item.closest('.eap-discussion-section');
        
        // Disable button temporarily
        $btn.prop('disabled', true).text('Deleting...');
        
        $.ajax({
            url: eapDiscussions.ajaxUrl,
            type: 'POST',
            data: {
                action: 'eap_delete_discussion',
                nonce: eapDiscussions.nonce,
                discussion_id: discussionId
            },
            success: function(response) {
                if (response.success) {
                    // Remove item with animation
                    $item.fadeOut(300, function() {
                        $item.remove();
                        
                        // Update count
                        $section.find('.eap-discussion-count').text('(' + response.data.count + ')');
                        
                        // Show "no discussions" message if empty
                        if ($section.find('.eap-discussion-item').length === 0) {
                            $section.find('.eap-discussions-list').html(
                                '<div class="eap-no-discussions">' +
                                '<div class="eap-no-discussions-icon">💬</div>' +
                                '<p>No discussions yet. Be the first to start a conversation!</p>' +
                                '</div>'
                            );
                        }
                    });
                } else {
                    alert(response.data.message || 'Failed to delete discussion.');
                    $btn.prop('disabled', false).html('<span class="eap-delete-icon">🗑️</span> Delete');
                }
            },
            error: function() {
                alert('An error occurred. Please try again.');
                $btn.prop('disabled', false).html('<span class="eap-delete-icon">🗑️</span> Delete');
            }
        });
    }

    function showMessage($container, type, message) {
        $container
            .removeClass('success error')
            .addClass(type)
            .text(message)
            .fadeIn()
            .delay(3000)
            .fadeOut();
    }

    function initSecureFileViewer() {
        if (secureViewerState.initialized) {
            return;
        }

        secureViewerState.$modal = buildSecureViewer();
        secureViewerState.$content = secureViewerState.$modal.find('.eap-secure-viewer__content');
        secureViewerState.$filename = secureViewerState.$modal.find('.eap-secure-viewer__filename');
        secureViewerState.$downloadBtn = secureViewerState.$modal.find('.eap-secure-viewer__download');
        secureViewerState.$googleBtn = secureViewerState.$modal.find('.eap-secure-viewer__google');
        secureViewerState.$googleBtn.hide();
        secureViewerState.initialized = true;
        setPreviewType('');

        var previewSelectors = '.eap-file-download-btn, .eap-download-button, .eap-attachment-link';

        $(document).on('click', previewSelectors, function(e) {
            var $link = $(this);
            var previewUrl = $link.attr('data-preview-url');
            var previewType = $link.attr('data-preview-type');
            var downloadUrl = $link.attr('data-download-url') || $link.attr('href'); // Get download URL from data attribute first
            var googleUrl = $link.attr('data-google-url');

            if (!previewUrl || !previewType) {
                // If href is '#' or similar, prevent default anyway
                if ($link.attr('href') === '#_') {
                    e.preventDefault();
                }
                return;
            }

            e.preventDefault();
            e.stopPropagation();
            e.stopImmediatePropagation();

            secureViewerState.lastTrigger = $link;

            openSecureViewer({
                url: previewUrl,
                type: previewType,
                name: $link.attr('data-file-name') || $.trim($link.text()),
                downloadUrl: downloadUrl, // Pass the correct download URL
                googleUrl: googleUrl
            });
        });

        $(document).on('click', '.eap-secure-viewer__close, .eap-secure-viewer__backdrop', function(e) {
            e.preventDefault();
            closeSecureViewer();
        });

        $(document).on('keyup', function(e) {
            if (e.key === 'Escape' && secureViewerState.$modal.hasClass('is-open')) {
                closeSecureViewer();
            }
        });
    }

    function buildSecureViewer() {
        var existing = $('.eap-secure-viewer');
        if (existing.length) {
            return existing;
        }

        var template = [
            '<div class="eap-secure-viewer" aria-hidden="true">',
                '<div class="eap-secure-viewer__backdrop" tabindex="-1"></div>',
                '<div class="eap-secure-viewer__dialog" role="dialog" aria-modal="true" aria-label="Secure file preview">',
                    '<button type="button" class="eap-secure-viewer__close" aria-label="Close preview">&times;</button>',
                    '<div class="eap-secure-viewer__body">',
                        '<div class="eap-secure-viewer__content"></div>',
                    '</div>',
                    '<div class="eap-secure-viewer__footer">',
                        '<div class="eap-secure-viewer__filename"></div>',
                        '<div class="eap-secure-viewer__actions">',
                            '<a class="eap-secure-viewer__google button button-secondary" href="#" target="_blank" rel="noopener noreferrer">View in Google</a>',
                            '<a class="eap-secure-viewer__download button" href="#" target="_blank" rel="noopener noreferrer">Download</a>',
                        '</div>',
                    '</div>',
                '</div>',
            '</div>'
        ].join('');

        var $modal = $(template);
        $('body').append($modal);
        return $modal;
    }

    function openSecureViewer(fileData) {
        if (!fileData || !fileData.url || !fileData.type) {
            return;
        }

        // Cancel all pending requests and reset state before opening new file
        cancelCsvPreviewRequest();
        cancelXlsxPreviewRequest();
        
        // Reset office viewer state
        if (typeof window.EapOfficeViewer !== 'undefined' && window.EapOfficeViewer.reset) {
            window.EapOfficeViewer.reset();
        }

        // Clear existing content
        if (secureViewerState.$content) {
            secureViewerState.$content.empty();
        }

        var safeName = fileData.name || 'Secure file';
        var previewUrl = fileData.url;
        var safeUrl = escapeHtml(previewUrl);
        var downloadUrl = fileData.downloadUrl || fileData.url || '';
        var googleUrl = fileData.googleUrl || '';
        var previewType = fileData.type || '';

        secureViewerState.$filename.text(safeName);
        secureViewerState.currentDownloadUrl = downloadUrl;
        secureViewerState.currentFileType = previewType;
        secureViewerState.currentPreviewUrl = previewUrl;
        secureViewerState.currentRequestId = null;
        secureViewerState.$downloadBtn
            .attr('href', downloadUrl || '#')
            .attr('aria-disabled', downloadUrl ? 'false' : 'true')
            .toggleClass('is-disabled', !downloadUrl);
        secureViewerState.$downloadBtn.removeAttr('download');

        secureViewerState.currentGoogleUrl = googleUrl || null;
        if (secureViewerState.$googleBtn && secureViewerState.$googleBtn.length) {
            var hasGoogleUrl = !!googleUrl;
            secureViewerState.$googleBtn
                .attr('href', hasGoogleUrl ? googleUrl : '#')
                .attr('aria-disabled', hasGoogleUrl ? 'false' : 'true')
                .toggleClass('is-disabled', !hasGoogleUrl)
                .toggle(hasGoogleUrl);
        }

        setPreviewType(previewType);

        var contentHtml = renderSecureViewerContent({
            type: previewType,
            url: safeUrl,
            name: safeName
        });

        secureViewerState.$content.html(contentHtml);
        if (previewType === 'csv') {
            hydrateCsvPreview(previewUrl, downloadUrl);
        } else if (previewType === 'xlsx') {
            cancelCsvPreviewRequest();
            hydrateOfficePreview('xlsx', previewUrl, downloadUrl);
        } else if (previewType === 'docx') {
            cancelCsvPreviewRequest();
            hydrateOfficePreview('docx', previewUrl, downloadUrl);
        } else if (previewType === 'pptx') {
            cancelCsvPreviewRequest();
            hydrateOfficePreview('pptx', previewUrl, downloadUrl);
        } else {
            cancelCsvPreviewRequest();
        }

        secureViewerState.$modal.addClass('is-open').attr('aria-hidden', 'false');
        $('body').addClass('eap-secure-viewer-open');
        secureViewerState.$modal.find('.eap-secure-viewer__close').focus();
    }

    function renderSecureViewerContent(fileData) {
        var safeName = escapeHtml(fileData.name || '');
        var url = fileData.url;

        switch (fileData.type) {
            case 'image':
                return '<img class="eap-secure-viewer__image" src="' + url + '" alt="' + safeName + '" loading="lazy">';
            case 'pdf':
                return '<iframe class="eap-secure-viewer__frame" src="' + url + '" title="' + safeName + '" loading="lazy"></iframe>';
            case 'audio':
                return '' +
                    '<audio controls preload="metadata" src="' + url + '">' +
                        'Your browser does not support the audio element.' +
                    '</audio>';
            case 'video':
                return '' +
                    '<video controls preload="metadata" playsinline src="' + url + '">' +
                        'Your browser does not support the video element.' +
                    '</video>';
            case 'csv':
                return '' +
                    '<div class="eap-secure-viewer__csv" role="region" aria-live="polite">' +
                        '<div class="eap-secure-viewer__csv-loading">' +
                            '<span class="eap-secure-viewer__spinner" aria-hidden="true"></span>' +
                            'Loading CSV preview...' +
                        '</div>' +
                    '</div>';
            case 'xlsx':
                return '' +
                    '<div class="eap-secure-viewer__xlsx" role="region" aria-live="polite">' +
                        '<div class="eap-office-loading">' +
                            '<span class="eap-secure-viewer__spinner" aria-hidden="true"></span>' +
                            'Loading spreadsheet...' +
                        '</div>' +
                    '</div>';
            case 'docx':
                return '' +
                    '<div class="eap-secure-viewer__docx" role="region" aria-live="polite">' +
                        '<div class="eap-office-loading">' +
                            '<span class="eap-secure-viewer__spinner" aria-hidden="true"></span>' +
                            'Loading document...' +
                        '</div>' +
                    '</div>';
            case 'pptx':
                return '' +
                    '<div class="eap-secure-viewer__pptx" role="region" aria-live="polite">' +
                        '<div class="eap-office-loading">' +
                            '<span class="eap-secure-viewer__spinner" aria-hidden="true"></span>' +
                            'Loading presentation...' +
                        '</div>' +
                    '</div>';
            default:
                return '<p>Preview not available for this file type.</p>';
        }
    }

    function closeSecureViewer() {
        if (!secureViewerState.$modal || !secureViewerState.$modal.hasClass('is-open')) {
            return;
        }

        secureViewerState.$modal.removeClass('is-open').attr('aria-hidden', 'true');
        secureViewerState.$content.empty();
        $('body').removeClass('eap-secure-viewer-open');

        if (secureViewerState.lastTrigger && secureViewerState.lastTrigger.length) {
            secureViewerState.lastTrigger.focus();
        }

        // Reset all state
        secureViewerState.lastTrigger = null;
        secureViewerState.currentDownloadUrl = null;
        secureViewerState.currentGoogleUrl = null;
        secureViewerState.currentFileType = null;
        secureViewerState.currentPreviewUrl = null;
        secureViewerState.currentRequestId = null;
        
        cancelCsvPreviewRequest();
        cancelXlsxPreviewRequest();
        
        // Reset office viewer state
        if (typeof window.EapOfficeViewer !== 'undefined' && window.EapOfficeViewer.reset) {
            window.EapOfficeViewer.reset();
        }
        
        setPreviewType('');

        if (secureViewerState.$googleBtn && secureViewerState.$googleBtn.length) {
            secureViewerState.$googleBtn
                .attr('href', '#')
                .attr('aria-disabled', 'true')
                .addClass('is-disabled')
                .hide();
        }
    }

    function escapeHtml(str) {
        return $('<div>').text(str || '').html();
    }

})(jQuery);