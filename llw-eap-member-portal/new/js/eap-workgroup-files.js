(function($) {
    'use strict';

    var config = window.eapWorkgroupFiles || {};

    function debounce(fn, delay) {
        var timer;
        return function() {
            var context = this;
            var args = arguments;
            clearTimeout(timer);
            timer = setTimeout(function() {
                fn.apply(context, args);
            }, delay);
        };
    }

    function initFilters() {
        config = window.eapWorkgroupFiles || config;
        $('.eap-wg-files-section').each(function() {
            var $section = $(this);

            if ($section.data('filtersInitialized')) {
                return;
            }
            $section.data('filtersInitialized', true);

            var postId = parseInt($section.data('wgId'), 10);
            if (!postId) {
                return;
            }

            var $search = $section.find('.eap-wg-file-search');
            var $type = $section.find('.eap-wg-file-type');
            var $folder = $section.find('.eap-wg-file-folder');
            var $includeSubfolders = $section.find('.eap-wg-include-subfolders');
            var $subfolderField = $section.find('.eap-wg-subfolder-field');
            var $grid = $section.find('.eap-wg-files-grid');
            var $status = $section.find('.eap-wg-files-status');
            var currentRequest = null;

            // Parse folder info from data attribute
            var folderInfo = {};
            try {
                folderInfo = JSON.parse($section.attr('data-folder-info') || '{}');
            } catch (e) {
                folderInfo = {};
            }

            if (!$search.length && !$type.length && !$folder.length) {
                return;
            }

            var showStatus = function(text) {
                if ($status.length) {
                    $status.text(text);
                }
            };

            var setLoading = function(isLoading) {
                if (!$grid.length) {
                    return;
                }

                if (isLoading) {
                    $section.addClass('is-loading');
                    var loadingText = (config.strings && config.strings.loading) ? config.strings.loading : 'Filtering files...';
                    $grid.attr('data-loading-text', loadingText);
                } else {
                    $section.removeClass('is-loading');
                    $grid.removeAttr('data-loading-text');
                }
            };

            var handleError = function(message) {
                var fallback = (config.strings && config.strings.error) ? config.strings.error : 'Unable to filter files. Please try again.';
                showStatus(message || fallback);
            };

            // Update visibility of the "include subfolders" checkbox based on selected folder
            var updateSubfolderVisibility = function() {
                if (!$folder.length || !$subfolderField.length) {
                    return;
                }

                var selectedFolder = $folder.val();
                var info = folderInfo[selectedFolder];

                if (selectedFolder === 'all') {
                    // "All folders" doesn't need subfolder option
                    $subfolderField.hide();
                } else if (info && info.hasSubfolders) {
                    // Show checkbox if the selected folder has subfolders
                    $subfolderField.show();
                } else {
                    // Hide checkbox if no subfolders
                    $subfolderField.hide();
                }
            };

            var fetchFiles = function() {
                if (!config.ajaxUrl || !config.nonce) {
                    return;
                }

                if (currentRequest && currentRequest.readyState !== 4) {
                    currentRequest.abort();
                }

                setLoading(true);

                var requestData = {
                    action: 'eap_filter_workgroup_files',
                    nonce: config.nonce,
                    post_id: postId,
                    search: $search.length ? $search.val() : '',
                    file_type: $type.length ? $type.val() : 'all',
                    folder: $folder.length ? $folder.val() : 'all',
                    include_subfolders: $includeSubfolders.length ? ($includeSubfolders.is(':checked') ? 'true' : 'false') : 'true'
                };

                currentRequest = $.ajax({
                    url: config.ajaxUrl,
                    method: 'POST',
                    data: requestData
                }).done(function(response) {
                    if (response && response.success) {
                        if ($grid.length) {
                            $grid.html(response.data.html);
                        }
                        if (response.data && response.data.statusText) {
                            showStatus(response.data.statusText);
                        }
                    } else {
                        var message = response && response.data ? response.data.message : '';
                        handleError(message);
                    }
                }).fail(function(xhr, status) {
                    if (status === 'abort') {
                        return;
                    }
                    handleError();
                }).always(function() {
                    setLoading(false);
                    currentRequest = null;
                });
            };

            if ($search.length) {
                $search.on('input', debounce(fetchFiles, 300));
            }

            if ($type.length) {
                $type.on('change', fetchFiles);
            }

            if ($folder.length) {
                $folder.on('change', function() {
                    updateSubfolderVisibility();
                    fetchFiles();
                });
                // Initialize visibility on load
                updateSubfolderVisibility();
            }

            if ($includeSubfolders.length) {
                $includeSubfolders.on('change', fetchFiles);
            }
        });
    }

    $(document).ready(function() {
        initFilters();
    });

})(jQuery);