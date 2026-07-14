// /js/eap-profile-editor.js

jQuery(document).ready(function($) {
    
    // ========================================
    // 1. PROGRESS BAR FUNCTIONALITY
    // ========================================
    
    function calculateProfileCompletion() {
        var totalFields = 0;
        var completedFields = 0;
        
        // Count all input fields that should be tracked
        $('.eap-profile-form input[type="text"], .eap-profile-form input[type="email"], .eap-profile-form input[type="tel"], .eap-profile-form textarea, .eap-profile-form select').each(function() {
            var $field = $(this);
            var fieldName = $field.attr('name');
            
            // Skip read-only and nonce fields
            if (fieldName && fieldName !== 'eap_profile_nonce' && fieldName !== '_wpnonce' && !$field.is(':disabled') && !$field.closest('.eap-readonly-section').length) {
                totalFields++;
                var value = $field.val();
                if (value && value.trim() !== '' && value !== '-- Select --') {
                    completedFields++;
                }
            }
        });
        
        var percentage = totalFields > 0 ? Math.round((completedFields / totalFields) * 100) : 0;
        return { percentage: percentage, completed: completedFields, total: totalFields };
    }
    
    function updateProgressBar() {
        var progress = calculateProfileCompletion();
        var $progressBar = $('.eap-progress-bar-fill');
        var $progressText = $('.eap-progress-text');
        var $progressPercentage = $('.eap-progress-percentage');
        
        $progressBar.css('width', progress.percentage + '%');
        $progressPercentage.text(progress.percentage + '%');
        $progressText.text(progress.completed + ' of ' + progress.total + ' fields completed');
        
        // Change color based on completion
        $progressBar.removeClass('low medium high complete');
        if (progress.percentage === 100) {
            $progressBar.addClass('complete');
        } else if (progress.percentage >= 75) {
            $progressBar.addClass('high');
        } else if (progress.percentage >= 50) {
            $progressBar.addClass('medium');
        } else {
            $progressBar.addClass('low');
        }
    }
    
    // Update progress on field changes
    $(document).on('input change', '.eap-profile-form input, .eap-profile-form textarea, .eap-profile-form select', function() {
        updateProgressBar();
    });
    
    // Initial progress calculation
    if ($('.eap-profile-form').length) {
        updateProgressBar();
    }
    
    
    // ========================================
    // 2. COLLAPSIBLE SECTIONS
    // ========================================
    
    // Initialize sections on page load to ensure jQuery knows their state
    $('.eap-profile-section .eap-section-content').each(function() {
        // Set explicit display style so slideToggle knows initial state
        $(this).css('display', 'block');
    });
    
    $(document).on('click', '.eap-section-header', function() {
        var $section = $(this).closest('.eap-profile-section');
        var $content = $section.find('.eap-section-content');
        var $icon = $(this).find('.eap-toggle-icon');
        
        $section.toggleClass('collapsed');
        $content.slideToggle(300);
        
        // Rotate icon
        if ($section.hasClass('collapsed')) {
            $icon.css('transform', 'rotate(0deg)');
        } else {
            $icon.css('transform', 'rotate(90deg)');
        }
    });
    
    
    // ========================================
    // 3. INLINE VALIDATION
    // ========================================
    
    function validateEmail(email) {
        var re = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        return re.test(email);
    }
    
    function validatePhone(phone) {
        // Allow various phone formats
        var re = /^[\d\s\+\-\(\)]+$/;
        return phone === '' || re.test(phone);
    }
    
    function validateRequired(value) {
        return value.trim() !== '';
    }
    
    function showValidationFeedback($field, isValid, message) {
        var $wrapper = $field.closest('td, .eap-field-wrapper');
        var $feedback = $wrapper.find('.eap-validation-feedback');
        
        // Remove existing feedback
        $feedback.remove();
        $field.removeClass('eap-field-valid eap-field-invalid');
        
        if (message) {
            // Add validation feedback
            $field.addClass(isValid ? 'eap-field-valid' : 'eap-field-invalid');
            $wrapper.append('<div class="eap-validation-feedback eap-' + (isValid ? 'valid' : 'invalid') + '">' + 
                '<span class="eap-feedback-icon">' + (isValid ? '✓' : '✕') + '</span> ' + 
                message + '</div>');
        }
    }
    
    // Email validation
    $(document).on('blur', 'input[type="email"]', function() {
        var $field = $(this);
        var value = $field.val().trim();
        var fieldName = $field.attr('name');
        
        if (value === '') {
            if (fieldName === 'email') {
                showValidationFeedback($field, false, 'Email is required');
            } else {
                showValidationFeedback($field, true, '');
            }
        } else if (validateEmail(value)) {
            showValidationFeedback($field, true, 'Valid email format');
        } else {
            showValidationFeedback($field, false, 'Invalid email format');
        }
    });
    
    // Phone validation
    $(document).on('blur', 'input[type="tel"]', function() {
        var $field = $(this);
        var value = $field.val().trim();
        
        if (value === '') {
            showValidationFeedback($field, true, '');
        } else if (validatePhone(value)) {
            showValidationFeedback($field, true, 'Valid phone format');
        } else {
            showValidationFeedback($field, false, 'Invalid phone format (use digits, spaces, +, -, parentheses)');
        }
    });
    
    // Required field validation for key fields
    $(document).on('blur', 'input#first_name, input#last_name', function() {
        var $field = $(this);
        var value = $field.val().trim();
        var label = $field.closest('tr').find('label').text().replace('*', '').trim();
        
        if (value === '') {
            showValidationFeedback($field, false, label + ' is recommended');
        } else {
            showValidationFeedback($field, true, 'Looks good!');
        }
    });
    
    // Clear validation on input
    $(document).on('input', '.eap-profile-form input, .eap-profile-form textarea, .eap-profile-form select', function() {
        var $field = $(this);
        if ($field.hasClass('eap-field-invalid')) {
            $field.removeClass('eap-field-invalid');
            $field.closest('td, .eap-field-wrapper').find('.eap-validation-feedback').fadeOut(200, function() {
                $(this).remove();
            });
        }
    });
    
    
    // ========================================
    // 4. DRAG & DROP FOR PHOTO UPLOAD WITH CROPPING
    // ========================================
    
    var $photoDropZone = $('.eap-photo-drop-zone');
    var $photoInput = $('#photo_url');
    var $photoPreview = $('.eap-image-preview');
    var cropperInstance = null;
    var currentFile = null;
    
    // Create cropper modal if it doesn't exist
    if ($photoDropZone.length && !$('#eap-cropper-modal').length) {
        var $modal = $('<div id="eap-cropper-modal" class="eap-cropper-modal">' +
            '<div class="eap-cropper-modal-content">' +
                '<div class="eap-cropper-header">' +
                    '<h3>Crop Your Profile Photo</h3>' +
                    '<span class="eap-cropper-close">&times;</span>' +
                '</div>' +
                '<div class="eap-cropper-body">' +
                    '<div class="eap-cropper-container">' +
                        '<img id="eap-crop-image" src="" alt="Image to crop">' +
                    '</div>' +
                '</div>' +
                '<div class="eap-cropper-footer">' +
                    '<button type="button" class="eap-btn-cancel">Cancel</button>' +
                    '<button type="button" class="eap-btn-crop">Crop & Upload</button>' +
                '</div>' +
            '</div>' +
        '</div>');
        $('body').append($modal);
    }
    
    // Create a hidden file input for the photo uploader
    if ($photoDropZone.length && !$('#eap-photo-file-input').length) {
        var $fileInput = $('<input type="file" id="eap-photo-file-input" accept="image/jpeg,image/jpg,image/png,image/gif,image/webp" style="display:none;" />');
        $photoDropZone.after($fileInput);
        
        // Handle file selection from input
        $fileInput.on('change', function() {
            if (this.files && this.files[0]) {
                handlePhotoFile(this.files[0]);
            }
        });
    }
    
    if ($photoDropZone.length) {
        
        // Prevent default drag behaviors
        $(document).on('drag dragstart dragend dragover dragenter dragleave drop', '.eap-photo-drop-zone', function(e) {
            e.preventDefault();
            e.stopPropagation();
        });
        
        // Add visual feedback on drag over
        $photoDropZone.on('dragover dragenter', function() {
            $(this).addClass('eap-drag-over');
        });
        
        $photoDropZone.on('dragleave dragend drop', function() {
            $(this).removeClass('eap-drag-over');
        });
        
        // Handle dropped files
        $photoDropZone.on('drop', function(e) {
            var files = e.originalEvent.dataTransfer.files;
            if (files.length > 0) {
                handlePhotoFile(files[0]);
            }
        });
        
        // Handle click to select file
        $photoDropZone.on('click', function(e) {
            e.preventDefault();
            $('#eap-photo-file-input').trigger('click');
        });
    }
    
    // Handle URL input changes
    $photoInput.on('blur', function() {
        var url = $(this).val().trim();
        if (url && isValidImageUrl(url)) {
            $photoPreview.html('<img src="' + url + '" alt="Profile photo" />');
            updateProgressBar();
            showValidationFeedback($photoInput, true, 'Image URL set successfully!');
        } else if (url) {
            showValidationFeedback($photoInput, false, 'Please enter a valid image URL');
        }
    });
    
    function isValidImageUrl(url) {
        if (!url) return false;
        try {
            new URL(url);
            return /\.(jpg|jpeg|png|gif|webp)(\?.*)?$/i.test(url);
        } catch (e) {
            return false;
        }
    }
    
    function handlePhotoFile(file) {
        // Validate file type
        var validTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp'];
        if (validTypes.indexOf(file.type) === -1) {
            alert('Please upload a valid image file (JPG, PNG, GIF, or WebP)');
            return;
        }
        
        // Validate file size (max 5MB)
        if (file.size > 5 * 1024 * 1024) {
            alert('File size must be less than 5MB');
            return;
        }
        
        // Store the file for later upload
        currentFile = file;
        
        // Create a URL for the image and show cropper modal
        var reader = new FileReader();
        reader.onload = function(e) {
            openCropperModal(e.target.result);
        };
        reader.readAsDataURL(file);
    }
    
    function openCropperModal(imageSrc) {
        var $modal = $('#eap-cropper-modal');
        var $cropImage = $('#eap-crop-image');
        
        // Set the image source
        $cropImage.attr('src', imageSrc);
        
        // Show the modal
        $modal.fadeIn(300);
        
        // Initialize Cropper.js
        setTimeout(function() {
            if (cropperInstance) {
                cropperInstance.destroy();
            }
            
            if (typeof Cropper !== 'undefined') {
                cropperInstance = new Cropper($cropImage[0], {
                    aspectRatio: 1, // Square crop for profile photos
                    viewMode: 2,
                    autoCropArea: 0.8,
                    responsive: true,
                    guides: true,
                    center: true,
                    highlight: true,
                    background: true,
                    minCropBoxWidth: 150,
                    minCropBoxHeight: 150
                });
            } else {
                console.error('Cropper.js library not loaded');
                alert('Image cropping library not available. Please try again.');
                $modal.hide();
            }
        }, 100);
    }
    
    function closeCropperModal() {
        var $modal = $('#eap-cropper-modal');
        $modal.fadeOut(300);
        
        if (cropperInstance) {
            cropperInstance.destroy();
            cropperInstance = null;
        }
        
        currentFile = null;
        $('#eap-photo-file-input').val(''); // Reset file input
    }
    
    // Modal close handlers
    $(document).on('click', '.eap-cropper-close, .eap-btn-cancel', function() {
        closeCropperModal();
    });
    
    // Click outside modal to close
    $(document).on('click', '#eap-cropper-modal', function(e) {
        if (e.target.id === 'eap-cropper-modal') {
            closeCropperModal();
        }
    });
    
    // Crop and upload handler
    $(document).on('click', '.eap-btn-crop', function() {
        if (!cropperInstance || !currentFile) {
            alert('No image to crop');
            return;
        }
        
        var $btn = $(this);
        $btn.prop('disabled', true).text('Processing...');
        
        // Get cropped canvas
        var canvas = cropperInstance.getCroppedCanvas({
            width: 400,
            height: 400,
            imageSmoothingQuality: 'high'
        });
        
        if (!canvas) {
            alert('Failed to crop image');
            $btn.prop('disabled', false).text('Crop & Upload');
            return;
        }
        
        // Convert canvas to blob
        canvas.toBlob(function(blob) {
            if (!blob) {
                alert('Failed to process cropped image');
                $btn.prop('disabled', false).text('Crop & Upload');
                return;
            }
            
            // Create a new file from the blob
            var fileName = currentFile.name;
            var croppedFile = new File([blob], fileName, {
                type: currentFile.type,
                lastModified: Date.now()
            });
            
            // Close the modal
            closeCropperModal();
            
            // Upload the cropped image
            uploadCroppedPhoto(croppedFile);
            
        }, currentFile.type, 0.95);
    });
    
    function uploadCroppedPhoto(file) {
        // Show loading state
        $photoDropZone.addClass('eap-uploading');
        $photoDropZone.find('.eap-drop-zone-text').html('<span class="eap-loading-spinner"></span> Uploading...');
        
        // Create form data
        var formData = new FormData();
        formData.append('file', file);
        formData.append('action', 'eap_upload_profile_photo');
        formData.append('nonce', eapProfileEditor.uploadNonce);
        formData.append('user_id', eapProfileEditor.userId || '');
        
        // Upload via custom AJAX handler
        $.ajax({
            url: eapProfileEditor.ajaxUrl,
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            success: function(response) {
                if (response.success && response.data && response.data.url) {
                    var imageUrl = response.data.url;
                    $photoInput.val(imageUrl).trigger('change');
                    $photoPreview.html('<img src="' + imageUrl + '" alt="Profile photo" />');
                    $photoDropZone.removeClass('eap-uploading');
                    $photoDropZone.find('.eap-drop-zone-text').html('Drag & drop your photo here<br>or click to browse<br><small>JPG, PNG, GIF, WebP (max 5MB)</small>');
                    updateProgressBar();
                    showValidationFeedback($photoInput, true, response.data.message || 'Photo uploaded successfully!');
                } else {
                    handleUploadError(response.data && response.data.message ? response.data.message : 'Upload failed');
                }
            },
            error: function(xhr) {
                var errorMsg = 'Upload failed';
                if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
                    errorMsg = xhr.responseJSON.data.message;
                }
                handleUploadError(errorMsg);
            }
        });
    }
    
    function handleUploadError(message) {
        $photoDropZone.removeClass('eap-uploading');
        $photoDropZone.find('.eap-drop-zone-text').html('Drag & drop your photo here<br>or click to browse<br><small>JPG, PNG, GIF, WebP (max 5MB)</small>');
        alert(message || 'Upload failed. Please try again.');
        showValidationFeedback($photoInput, false, message || 'Upload failed');
    }
    
    
    // ========================================
    // 5. PHOTO UPLOAD - NO MEDIA LIBRARY BUTTON NEEDED
    // ========================================
    // The .eap-upload-button has been removed in favor of direct
    // drag-and-drop and click-to-browse functionality above.
    
    
    // ========================================
    // 6. FORM SUBMISSION VALIDATION
    // ========================================
    
    $('.eap-profile-form form').on('submit', function(e) {
    // Set submitting flag to prevent unsaved changes indicator
        isSubmitting = true;
        
        // Ensure language tags are properly synced to hidden input before submission
        var $languageInput = $('#languages');
        if ($languageInput.length) {
            var $tagsContainer = $('.eap-language-tags-container');
            if ($tagsContainer.length) {
                updateHiddenInput($tagsContainer, $languageInput, true);
            }
        }
        
        var isValid = true;
        var firstInvalidField = null;
        
        // Validate email
        var $emailField = $(this).find('input[name="email"]');
        if ($emailField.length) {
            var email = $emailField.val().trim();
            if (!email || !validateEmail(email)) {
                showValidationFeedback($emailField, false, 'Valid email is required');
                isValid = false;
                if (!firstInvalidField) firstInvalidField = $emailField;
            }
        }
        
        // Validate phone if provided
        var $phoneField = $(this).find('input[name="phone"]');
        if ($phoneField.length) {
            var phone = $phoneField.val().trim();
            if (phone && !validatePhone(phone)) {
                showValidationFeedback($phoneField, false, 'Invalid phone format');
                isValid = false;
                if (!firstInvalidField) firstInvalidField = $phoneField;
            }
        }

        // Validate WhatsApp number if provided
        var $whatsappField = $(this).find('input[name="whatsapp_number"]');
        if ($whatsappField.length) {
            var whatsapp = $whatsappField.val().trim();
            if (whatsapp && !validatePhone(whatsapp)) {
                showValidationFeedback($whatsappField, false, 'Invalid WhatsApp number format');
                isValid = false;
                if (!firstInvalidField) firstInvalidField = $whatsappField;
            }
        }
        
        // Validate preferred email if provided
        var $preferredEmailField = $(this).find('input[name="preferred_email"]');
        if ($preferredEmailField.length) {
            var preferredEmail = $preferredEmailField.val().trim();
            if (preferredEmail && !validateEmail(preferredEmail)) {
                showValidationFeedback($preferredEmailField, false, 'Invalid email format');
                isValid = false;
                if (!firstInvalidField) firstInvalidField = $preferredEmailField;
            }
        }
        
        if (!isValid) {
            e.preventDefault();
            
            // Reset submitting flag since we're not actually submitting
            isSubmitting = false;
            
            // Scroll to first invalid field
            if (firstInvalidField) {
                $('html, body').animate({
                    scrollTop: firstInvalidField.offset().top - 100
                }, 500);
                firstInvalidField.focus();
            }
            
            // Show error message
            var $errorNotice = $('.eap-validation-error-notice');
            if ($errorNotice.length) {
                $errorNotice.fadeIn();
            } else {
                $(this).prepend('<div class="eap-notice eap-error eap-validation-error-notice" style="display:none;"><p><strong>Validation Error:</strong> Please correct the errors below before submitting.</p></div>');
                $('.eap-validation-error-notice').fadeIn();
            }
            
            return false;
        }
        
        // Remove error notice if present
        $('.eap-validation-error-notice').fadeOut();
        
        // Show loading state
        var $submitButton = $(this).find('input[type="submit"]');
        $submitButton.prop('disabled', true).val('Saving...');
    });
    
    
    // ========================================
    // 7. HELPFUL TOOLTIPS
    // ========================================
    
    // Add tooltip functionality
    $(document).on('mouseenter', '[data-tooltip]', function() {
        var tooltipText = $(this).data('tooltip');
        var $tooltip = $('<div class="eap-tooltip">' + tooltipText + '</div>');
        
        $('body').append($tooltip);
        
        var offset = $(this).offset();
        var elementHeight = $(this).outerHeight();
        
        $tooltip.css({
            top: offset.top + elementHeight + 10,
            left: offset.left
        }).fadeIn(200);
        
        $(this).data('tooltip-element', $tooltip);
    });
    
    $(document).on('mouseleave', '[data-tooltip]', function() {
        var $tooltip = $(this).data('tooltip-element');
        if ($tooltip) {
            $tooltip.fadeOut(200, function() {
                $(this).remove();
            });
        }
    });
    
    
    // ========================================
    // 8. SMOOTH SCROLL FOR NAVIGATION
    // ========================================
    
    $(document).on('click', '.eap-section-nav a', function(e) {
        e.preventDefault();
        var targetId = $(this).attr('href');
        var $target = $(targetId);
        
        if ($target.length) {
            // Expand section if collapsed
            if ($target.hasClass('collapsed')) {
                $target.find('.eap-section-header').trigger('click');
            }
            
            // Scroll to section
            $('html, body').animate({
                scrollTop: $target.offset().top - 100
            }, 500);
        }
    });
    
    
    // ========================================
    // 9. AUTO-SAVE INDICATOR (Optional Feature)
    // ========================================
    
    var autoSaveTimer = null;
    var hasUnsavedChanges = false;
    var isSubmitting = false;
    
    $(document).on('input change', '.eap-profile-form input, .eap-profile-form textarea, .eap-profile-form select', function() {
        // Don't show unsaved changes indicator during form submission
        if (isSubmitting) {
            return;
        }
        hasUnsavedChanges = true;
        showAutoSaveIndicator();
    });
    
    function showAutoSaveIndicator() {
        var $indicator = $('.eap-autosave-indicator');
        if (!$indicator.length) {
            $('.eap-profile-form h2').after('<div class="eap-autosave-indicator">Unsaved changes</div>');
            $indicator = $('.eap-autosave-indicator');
        }
        $indicator.fadeIn();
    }
    
    // Remove indicator on form submit
    $('.eap-profile-form form').on('submit', function() {
        hasUnsavedChanges = false;
        $('.eap-autosave-indicator').fadeOut();
    });
    
    // Warn before leaving page with unsaved changes
    $(window).on('beforeunload', function() {
        // If we are in the process of submitting, don't show the warning
        if (isSubmitting) {
            return; // A return value of undefined allows the navigation
        }
    
        if (hasUnsavedChanges) {
            return 'You have unsaved changes. Are you sure you want to leave?';
        }
    });
    
    
    // ========================================
    // 10. ACCESSIBILITY ENHANCEMENTS
    // ========================================
    
    // Add keyboard navigation for collapsible sections
    $(document).on('keydown', '.eap-section-header', function(e) {
        if (e.key === 'Enter' || e.key === ' ') {
            e.preventDefault();
            $(this).trigger('click');
        }
    });
    
    // Ensure collapsible headers are keyboard accessible
    $('.eap-section-header').attr('tabindex', '0').attr('role', 'button');
    
    
    // ========================================
    // 11. LANGUAGE TAGS FUNCTIONALITY
    // ========================================
    
    // High contrast color palette for language tags
    var languageTagColors = [
        '#e74c3c', // Red
        '#3498db', // Blue
        '#2ecc71', // Green
        '#9b59b6', // Purple
        '#f39c12', // Orange
        '#1abc9c', // Turquoise
        '#e67e22', // Dark Orange
        '#16a085', // Dark Turquoise
        '#c0392b', // Dark Red
        '#8e44ad', // Dark Purple
        '#27ae60', // Dark Green
        '#2980b9', // Dark Blue
        '#d35400', // Pumpkin
        '#c92a2a', // Crimson
        '#2f9e44', // Forest Green
        '#1864ab', // Ocean Blue
        '#862e9c', // Grape
        '#d9480f'  // Burnt Orange
    ];
    
    // Store used colors for each tag to maintain consistency
    var tagColorMap = {};
    
    function getColorForTag(tagText) {
        var normalizedTag = tagText.toLowerCase().trim();
        if (!tagColorMap[normalizedTag]) {
            // Assign a random color from the palette
            tagColorMap[normalizedTag] = languageTagColors[Math.floor(Math.random() * languageTagColors.length)];
        }
        return tagColorMap[normalizedTag];
    }
    
    function initializeLanguageTags() {
        var $languageInput = $('#languages');
        if ($languageInput.length === 0) {
            return;
        }
        
        // Create the tags container structure
        var $wrapper = $('<div class="eap-language-tags-wrapper"></div>');
        var $tagsContainer = $('<div class="eap-language-tags-container"></div>');
        var $input = $('<input type="text" class="eap-language-tag-input" placeholder="Type a language and press comma or click outside" />');
        var $hint = $('<div class="eap-language-tags-hint">Press comma or click outside to add a language tag</div>');
        
        $wrapper.append($tagsContainer);
        $wrapper.append($input);
        $wrapper.append($hint);
        
        // Hide the original input and insert the new structure
        $languageInput.hide();
        $languageInput.after($wrapper);
        
        // Parse existing languages from the hidden input
        var existingLanguages = $languageInput.val();
        if (existingLanguages) {
            var languages = existingLanguages.split(',').map(function(lang) {
                return lang.trim();
            }).filter(function(lang) {
                return lang !== '';
            });
            
            languages.forEach(function(lang) {
                addLanguageTag(lang, $tagsContainer, $languageInput, true);
            });
        }
        
        // Handle input on blur (focus off)
        $input.on('blur', function() {
            var value = $(this).val().trim();
            if (value !== '') {
                addLanguageTag(value, $tagsContainer, $languageInput);
                $(this).val('');
            }
        });
        
        // Handle comma key press
        $input.on('keydown', function(e) {
            if (e.key === ',' || e.keyCode === 188) {
                e.preventDefault();
                var value = $(this).val().trim();
                if (value !== '') {
                    addLanguageTag(value, $tagsContainer, $languageInput);
                    $(this).val('');
                }
            }
            // Also handle Enter key as an alternative
            else if (e.key === 'Enter' || e.keyCode === 13) {
                e.preventDefault();
                var value = $(this).val().trim();
                if (value !== '') {
                    addLanguageTag(value, $tagsContainer, $languageInput);
                    $(this).val('');
                }
            }
        });
        
        // Update progress bar when tags change
        $languageInput.on('change', function() {
            updateProgressBar();
        });
    }
    
    function addLanguageTag(language, $container, $hiddenInput, silent = false) {
        // Don't add empty or duplicate tags
        if (!language || language === '') {
            return;
        }
        
        // Check if tag already exists
        var exists = false;
        $container.find('.eap-language-tag').each(function() {
            if ($(this).data('language').toLowerCase() === language.toLowerCase()) {
                exists = true;
                return false;
            }
        });
        
        if (exists) {
            return;
        }
        
        var color = getColorForTag(language);
        
        var $tag = $('<div class="eap-language-tag"></div>');
        $tag.css('background-color', color);
        $tag.data('language', language);
        $tag.text(language);
        
        var $removeBtn = $('<span class="eap-language-tag-remove">×</span>');
        $removeBtn.on('click', function() {
            $tag.remove();
            updateHiddenInput($container, $hiddenInput, silent);
        });
        
        $tag.append($removeBtn);
        $container.append($tag);
        
        updateHiddenInput($container, $hiddenInput, silent);
    }
    
    function updateHiddenInput($container, $hiddenInput, silent = false) {
        var languages = [];
        $container.find('.eap-language-tag').each(function() {
            languages.push($(this).data('language'));
        });
        $hiddenInput.val(languages.join(', '));
        if (!silent) {
            $hiddenInput.trigger('change');
        }
    }
    
    function initializeAccountSecurity() {
        if (typeof eapProfileEditor === 'undefined') {
            return;
        }

        var $modal = $('#eap-account-security-modal');
        if (!$modal.length) {
            return;
        }

        var securityNonce = eapProfileEditor.securityNonce || '';
        var i18n = eapProfileEditor.securityI18n || {};
        var $body = $('body');
        var $passwordForm = $('#eap-account-security-password-form');
        var $totpForm = $('#eap-account-security-2fa-form');
        var $totpCodeInput = $totpForm.find('input[name="totp_code"]');
        var $secretField = $totpForm.find('[data-eap-account-security-secret-field]');
        var $secretValue = $totpForm.find('[data-eap-account-security-secret-value]');
        var $badge = $modal.find('[data-eap-account-security-badge]');
        var $instructionsDisabled = $modal.find('[data-eap-account-security-instructions="disabled"]');
        var $instructionsEnabled = $modal.find('[data-eap-account-security-instructions="enabled"]');
        var $qrContainer = $totpForm.find('[data-eap-account-security-qr]');
        var $enableBtn = $totpForm.find('[data-security-action="enable"]');
        var $disableBtn = $totpForm.find('[data-security-action="disable"]');
        var $refreshBtn = $totpForm.find('[data-security-action="refresh"]');
        var $secretContainer = $totpForm.find('[data-visible-when="disabled"]');
        var currentTotpAction = null;

        var accountSecurityState = {
            totpEnabled: $modal.data('totp-enabled') === 1 || $modal.data('totp-enabled') === '1',
            proposedSecret: ($modal.data('proposed-secret') || '').toString(),
            otpauth: ($modal.data('otpauth') || '').toString()
        };

        function setLoading($button, isLoading, labelKey) {
            if (!$button || !$button.length) {
                return;
            }
            if (isLoading) {
                if (!$button.data('original-text')) {
                    $button.data('original-text', $button.text());
                }
                var fallback = i18n[labelKey] || i18n.working || 'Working...';
                $button.prop('disabled', true).text(fallback);
            } else {
                var original = $button.data('original-text');
                if (original) {
                    $button.text(original);
                }
                $button.prop('disabled', false);
            }
        }

        function showFeedback(target, message, type) {
            var $feedback = $modal.find('[data-feedback="' + target + '"]');
            if (!$feedback.length) {
                return;
            }
            if (!message) {
                $feedback.removeClass('is-success is-error').hide();
                return;
            }
            $feedback
                .removeClass('is-success is-error')
                .addClass(type === 'success' ? 'is-success' : 'is-error')
                .text(message)
                .show();
        }

        function renderQr(payload) {
            if (!$qrContainer.length) {
                return;
            }
            $qrContainer.empty();
            if (!payload) {
                $qrContainer.attr('aria-hidden', 'true');
                return;
            }
            if (typeof QRCode === 'undefined') {
                var fallback = $('<p/>', { text: i18n.qrFallback || 'Unable to render the QR code. Enter the setup key manually.' });
                $qrContainer.append(fallback);
                return;
            }
            try {
                new QRCode($qrContainer[0], {
                    text: payload,
                    width: 200,
                    height: 200,
                    correctLevel: QRCode.CorrectLevel ? QRCode.CorrectLevel.M : 0
                });
                $qrContainer.attr('aria-hidden', 'false');
            } catch (error) {
                var fallbackText = i18n.qrFallback || 'Unable to render the QR code. Enter the setup key manually.';
                $qrContainer.append($('<p/>', { text: fallbackText }));
            }
        }

        function syncTotpUi() {
            if (accountSecurityState.totpEnabled) {
                $badge.removeClass('is-disabled').addClass('is-enabled').text(i18n.badgeOn || '2FA enabled');
                $instructionsDisabled.attr('hidden', true);
                $instructionsEnabled.removeAttr('hidden');
                $enableBtn.attr('hidden', true);
                $disableBtn.removeAttr('hidden');
                $refreshBtn.attr('hidden', true);
                $secretContainer.attr('hidden', true);
                $secretField.val('');
                $secretValue.text('');
                $qrContainer.empty().attr('aria-hidden', 'true');
            } else {
                $badge.removeClass('is-enabled').addClass('is-disabled').text(i18n.badgeOff || '2FA off');
                $instructionsDisabled.removeAttr('hidden');
                $instructionsEnabled.attr('hidden', true);
                $enableBtn.removeAttr('hidden');
                $disableBtn.attr('hidden', true);
                $refreshBtn.removeAttr('hidden');
                $secretContainer.removeAttr('hidden');
                $secretField.val(accountSecurityState.proposedSecret || '');
                $secretValue.text(accountSecurityState.proposedSecret || '');
                if (accountSecurityState.otpauth) {
                    renderQr(accountSecurityState.otpauth);
                } else {
                    $qrContainer.empty().attr('aria-hidden', 'true');
                }
            }
        }

        function openModal() {
            $modal.addClass('is-open').attr('aria-hidden', 'false');
            $body.addClass('eap-account-security-open');
            setTimeout(function() {
                $('#eap-current-password').trigger('focus');
            }, 50);
        }

        function closeModal() {
            $modal.removeClass('is-open').attr('aria-hidden', 'true');
            $body.removeClass('eap-account-security-open');
        }

        function getErrorMessage(response) {
            if (response && response.data && response.data.message) {
                return response.data.message;
            }
            return i18n.unknownError || 'Something went wrong. Please try again.';
        }

        function requestNewSecret() {
            if (!securityNonce) {
                showFeedback('totp', i18n.unknownError || 'Unable to verify request. Refresh and try again.', 'error');
                return;
            }
            setLoading($refreshBtn, true, 'working');
            $.ajax({
                url: eapProfileEditor.ajaxUrl,
                method: 'POST',
                dataType: 'json',
                data: {
                    action: 'eap_generate_account_totp_secret',
                    securityNonce: securityNonce
                }
            }).done(function(response) {
                if (response.success && response.data) {
                    accountSecurityState.totpEnabled = false;
                    accountSecurityState.proposedSecret = response.data.secret || '';
                    accountSecurityState.otpauth = response.data.otpauth || '';
                    showFeedback('totp', response.data.message || '', 'success');
                    $totpCodeInput.val('');
                    syncTotpUi();
                } else {
                    showFeedback('totp', getErrorMessage(response), 'error');
                }
            }).fail(function(xhr) {
                showFeedback('totp', getErrorMessage(xhr.responseJSON), 'error');
            }).always(function() {
                setLoading($refreshBtn, false);
            });
        }

        $('[data-eap-account-security-open]').on('click', function(e) {
            e.preventDefault();
            openModal();
        });

        $modal.on('click', '[data-eap-account-security-close]', function(e) {
            e.preventDefault();
            closeModal();
        });

        $(document).on('keyup', function(e) {
            if (e.key === 'Escape' && $modal.hasClass('is-open')) {
                closeModal();
            }
        });

        $passwordForm.on('submit', function(e) {
            e.preventDefault();
            showFeedback('password', '', 'success');

            var current = ($passwordForm.find('input[name="current_password"]').val() || '').trim();
            var newPass = ($passwordForm.find('input[name="new_password"]').val() || '').trim();
            var confirm = ($passwordForm.find('input[name="confirm_password"]').val() || '').trim();
            var $submit = $passwordForm.find('button[type="submit"]');

            if (!newPass && !confirm) {
                showFeedback('password', i18n.passwordBlank || 'Enter and confirm a new password to continue.', 'error');
                return;
            }
            if (newPass !== confirm) {
                showFeedback('password', i18n.passwordMismatch || 'New password entries do not match.', 'error');
                return;
            }
            if (newPass.length < 12) {
                showFeedback('password', i18n.passwordTooShort || 'Use at least 12 characters for your new password.', 'error');
                return;
            }
            if (!current) {
                showFeedback('password', i18n.currentPasswordRequired || 'Enter your current password to continue.', 'error');
                return;
            }
            if (!securityNonce) {
                showFeedback('password', i18n.unknownError || 'Unable to verify request. Refresh and try again.', 'error');
                return;
            }

            setLoading($submit, true, 'saving');
            $.ajax({
                url: eapProfileEditor.ajaxUrl,
                method: 'POST',
                dataType: 'json',
                data: {
                    action: 'eap_update_account_security',
                    securityNonce: securityNonce,
                    security_action: 'password',
                    current_password: current,
                    new_password: newPass,
                    confirm_password: confirm
                }
            }).done(function(response) {
                if (response.success) {
                    showFeedback('password', response.data && response.data.message ? response.data.message : '', 'success');
                    $passwordForm[0].reset();
                } else {
                    showFeedback('password', getErrorMessage(response), 'error');
                }
            }).fail(function(xhr) {
                showFeedback('password', getErrorMessage(xhr.responseJSON), 'error');
            }).always(function() {
                setLoading($submit, false);
            });
        });

        $totpForm.on('click', '[data-security-action="refresh"]', function(e) {
            e.preventDefault();
            requestNewSecret();
        });

        $totpForm.on('click', '[data-security-action="enable"]', function() {
            currentTotpAction = 'enable';
        });

        $totpForm.on('click', '[data-security-action="disable"]', function() {
            currentTotpAction = 'disable';
        });

        $totpForm.on('submit', function(e) {
            e.preventDefault();
            showFeedback('totp', '', 'success');

            var action = currentTotpAction || (accountSecurityState.totpEnabled ? 'disable' : 'enable');
            var $submitBtn = action === 'disable' ? $disableBtn : $enableBtn;
            var payload = {
                action: 'eap_update_account_security',
                securityNonce: securityNonce,
                security_action: action === 'disable' ? 'disable_totp' : 'enable_totp'
            };

            if (!securityNonce) {
                showFeedback('totp', i18n.unknownError || 'Unable to verify request. Refresh and try again.', 'error');
                return;
            }

            if (action === 'enable') {
                var secret = ($secretField.val() || '').trim().toUpperCase();
                var code = ($totpCodeInput.val() || '').trim();

                if (!secret) {
                    showFeedback('totp', i18n.secretRequired || 'Generate a setup key before enabling 2FA.', 'error');
                    return;
                }
                if (!code) {
                    showFeedback('totp', i18n.codeRequired || 'Enter the 6-digit code from your authenticator app.', 'error');
                    return;
                }
                payload.totp_secret = secret;
                payload.totp_code = code;
            } else {
                var disableCode = ($totpCodeInput.val() || '').trim();
                if (!disableCode) {
                    showFeedback('totp', i18n.codeRequired || 'Enter the 6-digit code from your authenticator app.', 'error');
                    return;
                }
                payload.totp_code = disableCode;
            }

            setLoading($submitBtn, true, 'working');
            $.ajax({
                url: eapProfileEditor.ajaxUrl,
                method: 'POST',
                dataType: 'json',
                data: payload
            }).done(function(response) {
                if (response.success && response.data) {
                    showFeedback('totp', response.data.message || '', 'success');
                    if (response.data.state) {
                        accountSecurityState.totpEnabled = !!response.data.state.totpEnabled;
                        accountSecurityState.proposedSecret = response.data.state.proposedSecret || '';
                        accountSecurityState.otpauth = response.data.state.otpauth || '';
                        $totpCodeInput.val('');
                        syncTotpUi();
                    }
                } else {
                    showFeedback('totp', getErrorMessage(response), 'error');
                }
            }).fail(function(xhr) {
                showFeedback('totp', getErrorMessage(xhr.responseJSON), 'error');
            }).always(function() {
                setLoading($submitBtn, false);
                currentTotpAction = null;
            });
        });

        syncTotpUi();
    }
    
    // Initialize language tags on page load
    if ($('.eap-profile-form').length) {
        initializeLanguageTags();
    }

    initializeAccountSecurity();
    
});