// /js/eap-file-repeater.js

/**
 * EAP File Repeater - Admin interface for multiple file uploads
 */
(function($) {
    'use strict';

    $(document).ready(function() {
        var fileIndex = $('.eap-file-row').length;

        // Add new file row
        $(document).on('click', '.eap-add-file-btn', function(e) {
            e.preventDefault();
            
            var newRow = `
                <div class="eap-file-row" style="margin-bottom: 15px; padding: 10px; border: 1px solid #ddd; border-radius: 4px; background: #f9f9f9;">
                    <label><strong>File ${fileIndex + 1}:</strong></label>
                    <input type="file" name="eap_file_uploads[]" class="eap-file-input" style="width: 100%; margin-bottom: 5px;">
                    <button type="button" class="eap-remove-file-btn button button-small" style="color: #b32d2e;">Remove</button>
                </div>
            `;
            
            $('#eap-file-repeater-container').append(newRow);
            fileIndex++;
        });

        // Remove file row
        $(document).on('click', '.eap-remove-file-btn', function(e) {
            e.preventDefault();
            $(this).closest('.eap-file-row').remove();
            
            // Renumber remaining rows
            $('.eap-file-row').each(function(index) {
                $(this).find('label strong').text('File ' + (index + 1) + ':');
            });
        });

        // Remove existing file
        $(document).on('click', '.eap-remove-existing-file', function(e) {
            e.preventDefault();
            
            if (!confirm('Are you sure you want to remove this file?')) {
                return;
            }
            
            var $container = $(this).closest('.eap-existing-file');
            var fileIndex = $(this).data('file-index');
            
            // Add hidden input to mark file for deletion
            $('#eap-file-repeater-container').append(
                '<input type="hidden" name="eap_files_to_remove[]" value="' + fileIndex + '">'
            );
            
            $container.fadeOut(300, function() {
                $(this).remove();
            });
        });
    });

})(jQuery);