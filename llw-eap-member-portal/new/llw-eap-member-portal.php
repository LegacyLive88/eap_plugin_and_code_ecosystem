<?php
/**
 * Plugin Name:       LLW-EAP Member Portal
 * Description:       Custom functionality for the EAP Delegates and Young EAP member area.
 * Version:           1.0.4
 * Author:            Legacy Live Web (Pty) Ltd
 */

// Stop direct access
if ( !defined( 'ABSPATH' ) ) {
    exit;
}

// Define plugin constants
define( 'EAP_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'EAP_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

if ( ! defined( 'EAP_ENABLE_WG_EDITOR' ) ) {
    define( 'EAP_ENABLE_WG_EDITOR', false );
}

if ( ! function_exists( 'eap_convert_text_urls_to_secure_links' ) ) {
    /**
     * Convert plain-text URLs into https anchor tags that open in a new tab.
     *
     * @param string $text
     * @return string
     */
    function eap_convert_text_urls_to_secure_links( $text ) {
        if ( ! is_string( $text ) || '' === $text ) {
            return esc_html( (string) $text );
        }

        $pattern  = '/(https?:\/\/[^\s<>"\'\)\]]+)/i';
        $segments = preg_split( $pattern, $text, -1, PREG_SPLIT_DELIM_CAPTURE );

        if ( false === $segments || empty( $segments ) ) {
            return esc_html( $text );
        }

        $result = '';

        foreach ( $segments as $index => $segment ) {
            if ( 0 === $index % 2 ) {
                $result .= esc_html( $segment );
                continue;
            }

            $normalized = preg_replace( '/^http:\/\//i', 'https://', $segment );
            if ( ! is_string( $normalized ) || '' === $normalized ) {
                $result .= esc_html( $segment );
                continue;
            }

            $trailing = '';
            while ( '' !== $normalized ) {
                $last_char = substr( $normalized, -1 );
                if ( false === strpos( '.,;:!?', $last_char ) ) {
                    break;
                }
                $trailing  = $last_char . $trailing;
                $normalized = substr( $normalized, 0, -1 );
            }

            if ( '' === $normalized ) {
                $result .= esc_html( $segment );
                $result .= esc_html( $trailing );
                continue;
            }

            $href = esc_url( $normalized );

            if ( '' === $href ) {
                $result .= esc_html( $segment );
                $result .= esc_html( $trailing );
                continue;
            }

            $result .= sprintf(
                '<a href="%1$s" target="_blank" rel="noopener noreferrer">%2$s</a>',
                $href,
                esc_html( $normalized )
            );

            if ( '' !== $trailing ) {
                $result .= esc_html( $trailing );
            }
        }

        return $result;
    }
}

// Load Composer autoloader for PhpSpreadsheet if available
$autoload_path = EAP_PLUGIN_DIR . 'vendor/autoload.php';
if (file_exists($autoload_path)) {
    require_once $autoload_path;
}

require_once EAP_PLUGIN_DIR . 'includes/class-eap-workgroup-drive-sync.php';
EAP_Workgroup_Drive_Sync::bootstrap();


// === 0. PLUGIN ACTIVATION & UNINSTALL ===

/**
 * Main plugin activation function.
 */
function eap_plugin_activate() {
    // 1. Install the roles
    eap_install_roles();
    
    // 2. Create the secure file storage directory
    eap_create_secure_storage();

    // 3. Create custom audit log database table
    eap_create_audit_log_table();
    
    // 4. Create custom avatars database table
    eap_create_avatars_table();
    
    // 5. Discussions database table is now handled by the EAP Discussions addon plugin
    // The addon will create its own tables when activated

    // 6. Flush rewrite rules for CPTs
    flush_rewrite_rules();
    
    // 7. Set flag to show migration notice
    update_option('eap_show_country_migration_notice', true);
}
register_activation_hook( __FILE__, 'eap_plugin_activate' );

/**
 * Clear scheduled Drive sync events on plugin deactivation.
 */
function eap_plugin_deactivate() {
    $timestamp = wp_next_scheduled('eap_run_drive_sync');
    if ($timestamp) {
        wp_unschedule_event($timestamp, 'eap_run_drive_sync');
    }
}
register_deactivation_hook(__FILE__, 'eap_plugin_deactivate');

/**
 * Create custom database table for audit logs.
 * This provides better performance and scalability compared to wp_options.
 */
function eap_create_audit_log_table() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'eap_audit_log';
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE IF NOT EXISTS $table_name (
        id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        event_id VARCHAR(50) NOT NULL,
        message TEXT NOT NULL,
        event_type VARCHAR(50) NOT NULL DEFAULT 'general',
        timestamp BIGINT(20) UNSIGNED NOT NULL,
        time DATETIME NOT NULL,
        user_id BIGINT(20) UNSIGNED DEFAULT NULL,
        user_login VARCHAR(255) DEFAULT NULL,
        user_email VARCHAR(255) DEFAULT NULL,
        ip_address VARCHAR(50) DEFAULT NULL,
        user_agent TEXT DEFAULT NULL,
        request_uri TEXT DEFAULT NULL,
        data LONGTEXT DEFAULT NULL,
        PRIMARY KEY (id),
        KEY event_type (event_type),
        KEY timestamp (timestamp),
        KEY user_id (user_id),
        KEY event_id (event_id)
    ) $charset_collate;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
    
    // Store the database version for future upgrades
    update_option('eap_audit_log_db_version', '1.0');
}

/**
 * Create custom database table for avatars.
 * Stores avatar images separately from user meta to avoid conflicts with WP gravatar/avatar systems.
 */
function eap_create_avatars_table() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'eap_avatars';
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE IF NOT EXISTS $table_name (
        id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id BIGINT(20) UNSIGNED NOT NULL,
        avatar_url VARCHAR(500) NOT NULL,
        attachment_id BIGINT(20) UNSIGNED DEFAULT NULL,
        uploaded_date DATETIME NOT NULL,
        file_size INT UNSIGNED DEFAULT NULL,
        mime_type VARCHAR(100) DEFAULT NULL,
        is_active TINYINT(1) DEFAULT 1,
        PRIMARY KEY (id),
        KEY user_id (user_id),
        KEY is_active (is_active)
    ) $charset_collate;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
    
    // Store the database version for future upgrades
    update_option('eap_avatars_db_version', '1.0');
}

/**
 * Discussion database tables have been moved to the EAP Discussions addon plugin.
 * The addon creates wp_eap_discussions and wp_eap_discussion_votes tables on activation.
 * See: discussions_plugin/eap-discussions.php
 */

/**
 * Clean up roles when the plugin is uninstalled.
 */
function eap_plugin_uninstall() {
    $eap_roles = eap_get_member_roles();
    foreach ($eap_roles as $role) {
        remove_role($role);
    }
    // Note: We intentionally DO NOT delete the secure files, audit logs, or avatars,
    // as that is user-generated data. Database tables and data will remain.
}
register_uninstall_hook(__FILE__, 'eap_plugin_uninstall');


// === 1. ROLE CREATION & HELPERS ===

function eap_install_roles() {
    $subscriber = get_role('subscriber');
    $capabilities = $subscriber ? $subscriber->capabilities : ['read' => true];

    // National Delegate
    add_role('national_delegate', 'National Delegate', $capabilities);
    // Young EAP Member
    add_role('young_eap_member', 'Young EAP Member', $capabilities);
    // Rep. of Affiliated or related society 
    add_role('rep_affiliated_society', 'Rep. of Affiliated Society', $capabilities);
    // Rep. of UEMS Subspecialty Society
    add_role('rep_uems_subspecialty', 'Rep. of UEMS Subspecialty', $capabilities);
    // Read-only Staff
    add_role('read_only_staff', 'Read-only Staff', ['read' => true]);
}

function eap_get_member_roles() {
    return [
        'national_delegate',
        'young_eap_member',
        'rep_affiliated_society',
        'rep_uems_subspecialty',
        'read_only_staff'
    ];
}

/**
 * Get human-friendly labels for known member roles.
 *
 * @return array
 */
function eap_get_role_labels() {
    return [
        'national_delegate' => 'National Delegate',
        'young_eap_member' => 'Young EAP Member',
        'rep_affiliated_society' => 'Rep. Affiliated Society',
        'rep_uems_subspecialty' => 'Rep. UEMS Subspecialty',
        'read_only_staff' => 'Read Only Staff',
    ];
}

function eap_is_portal_member($user = null) {
    if (!$user) {
        $user = wp_get_current_user();
    }
    if (!$user->ID) {
        return false;
    }
    $member_roles = eap_get_member_roles();
    $member_roles[] = 'administrator'; // Admins are always members
    
    $user_roles = (array) $user->roles;
    $has_member_role = !empty(array_intersect($member_roles, $user_roles));
    
    // If user doesn't have a member role, return false
    if (!$has_member_role) {
        return false;
    }
    
    // Check if account is active (treat empty as active for backwards compatibility)
    $is_active = get_user_meta($user->ID, 'is_active', true);
    if ($is_active === '0' || $is_active === false) {
        return false;
    }
    
    return true;
}


// === 1B. COUNTRY REFERENCE TABLE HELPERS ===

/**
 * Get all countries from the reference table
 * Returns array of country objects with id, country_name, country_code_2, country_code_3, economic_class
 * 
 * @return array Array of country objects
 */
function eap_get_countries() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'ref_countries';
    
    $countries = wp_cache_get('eap_countries_list', 'eap');
    if (false === $countries) {
        $countries = $wpdb->get_results("SELECT id, country_name, country_code_2, country_code_3, economic_class FROM {$table_name} ORDER BY country_name ASC");
        wp_cache_set('eap_countries_list', $countries, 'eap', 3600); // Cache for 1 hour
    }
    
    return $countries;
}

/**
 * Normalize arbitrary date strings to Y-m-d.
 *
 * @param string $value
 * @return string
 */
function eap_normalize_date_value($value) {
    if (!is_string($value)) {
        $value = (string) $value;
    }
    $value = trim($value);
    if ($value === '') {
        return '';
    }
    $timestamp = strtotime($value);
    if ($timestamp) {
        return date('Y-m-d', $timestamp);
    }
    return sanitize_text_field($value);
}

/**
 * Get country name by ID
 * 
 * @param int $country_id The country ID
 * @return string The country name, or empty string if not found
 */
function eap_get_country_name($country_id) {
    if (empty($country_id)) {
        return '';
    }
    
    global $wpdb;
    $table_name = $wpdb->prefix . 'ref_countries';
    
    $cache_key = 'eap_country_name_' . $country_id;
    $country_name = wp_cache_get($cache_key, 'eap');
    
    if (false === $country_name) {
        $country_name = $wpdb->get_var($wpdb->prepare("SELECT country_name FROM {$table_name} WHERE id = %d", $country_id));
        if ($country_name === null) {
            $country_name = '';
        }
        wp_cache_set($cache_key, $country_name, 'eap', 3600); // Cache for 1 hour
    }
    
    return $country_name;
}

/**
 * Get country ID by name (case-insensitive, for migration purposes)
 * 
 * @param string $country_name The country name
 * @return int|null The country ID, or null if not found
 */
function eap_get_country_id_by_name($country_name) {
    if (empty($country_name)) {
        return null;
    }
    
    global $wpdb;
    $table_name = $wpdb->prefix . 'ref_countries';
    
    $cache_key = 'eap_country_id_' . md5(strtolower($country_name));
    $country_id = wp_cache_get($cache_key, 'eap');
    
    if (false === $country_id) {
        $country_id = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$table_name} WHERE LOWER(country_name) = LOWER(%s)", $country_name));
        if ($country_id === null) {
            $country_id = 0; // Cache 0 for not found
        }
        wp_cache_set($cache_key, $country_id, 'eap', 3600); // Cache for 1 hour
    }
    
    return $country_id > 0 ? (int)$country_id : null;
}

/**
 * Get 2-character ISO country code by ID
 * 
 * @param int $country_id The country ID
 * @return string The 2-character country code (e.g., 'US', 'GB'), or empty string if not found
 */
function eap_get_country_code($country_id) {
    if (empty($country_id)) {
        return '';
    }
    
    global $wpdb;
    $table_name = $wpdb->prefix . 'ref_countries';
    
    $cache_key = 'eap_country_code_' . $country_id;
    $country_code = wp_cache_get($cache_key, 'eap');
    
    if (false === $country_code) {
        $country_code = $wpdb->get_var($wpdb->prepare("SELECT country_code_2 FROM {$table_name} WHERE id = %d", $country_id));
        if ($country_code === null) {
            $country_code = '';
        }
        wp_cache_set($cache_key, $country_code, 'eap', 3600); // Cache for 1 hour
    }
    
    return $country_code;
}

/**
 * Get country ID by 2-character ISO code (case-insensitive)
 * 
 * @param string $country_code The 2-character country code (e.g., 'US', 'GB')
 * @return int|null The country ID, or null if not found
 */
function eap_get_country_id_by_code($country_code) {
    if (empty($country_code)) {
        return null;
    }
    
    // Normalize to uppercase
    $country_code = strtoupper(trim($country_code));
    
    // Validate it's 2 characters
    if (strlen($country_code) !== 2) {
        return null;
    }
    
    global $wpdb;
    $table_name = $wpdb->prefix . 'ref_countries';
    
    $cache_key = 'eap_country_id_code_' . $country_code;
    $country_id = wp_cache_get($cache_key, 'eap');
    
    if (false === $country_id) {
        $country_id = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$table_name} WHERE UPPER(country_code_2) = %s", $country_code));
        if ($country_id === null) {
            $country_id = 0; // Cache 0 for not found
        }
        wp_cache_set($cache_key, $country_id, 'eap', 3600); // Cache for 1 hour
    }
    
    return $country_id > 0 ? (int)$country_id : null;
}

/**
 * Get the list of available UEMS country status options.
 *
 * @return array<string,string>
 */
function eap_get_country_status_options() {
    return [
        'full_member' => 'Full Member Country',
        'associate_member' => 'Associate Member Country',
        'observer_country' => 'Observer Country',
    ];
}

/**
 * Persist the country status map and prime cache.
 *
 * @param array<int,string> $map
 */
function eap_set_country_status_map($map) {
    update_option('eap_country_status_map', $map);
    wp_cache_set('eap_country_status_map', $map, 'eap', 3600);
}

/**
 * Retrieve the saved country status map.
 *
 * @return array<int,string>
 */
function eap_get_country_status_map() {
    $map = wp_cache_get('eap_country_status_map', 'eap');
    
    if (false === $map) {
        $raw_map = get_option('eap_country_status_map', []);
        $options = eap_get_country_status_options();
        $map = [];
        
        if (is_array($raw_map)) {
            foreach ($raw_map as $country_id => $status_key) {
                $country_id = absint($country_id);
                $status_key = sanitize_key($status_key);
                
                if (!$country_id || !isset($options[$status_key])) {
                    continue;
                }
                
                $map[$country_id] = $status_key;
            }
        }
        
        wp_cache_set('eap_country_status_map', $map, 'eap', 3600);
    }
    
    return $map;
}

/**
 * Return the normalized country status key for a given country ID.
 *
 * @param int $country_id
 * @return string
 */
function eap_get_country_status_key($country_id) {
    $country_id = absint($country_id);
    
    if (!$country_id) {
        return '';
    }
    
    $map = eap_get_country_status_map();
    
    return $map[$country_id] ?? '';
}

/**
 * Return the display label for a country's status.
 *
 * @param int $country_id
 * @return string
 */
function eap_get_country_status_label($country_id) {
    $status_key = eap_get_country_status_key($country_id);
    $options = eap_get_country_status_options();
    
    return $status_key && isset($options[$status_key]) ? $options[$status_key] : '';
}

/**
 * Helper to retrieve the country status label for a user.
 *
 * @param int $user_id
 * @return string
 */
function eap_get_user_country_status_label($user_id) {
    $country_id = absint(get_user_meta($user_id, 'country', true));
    
    if (!$country_id) {
        return '';
    }
    
    return eap_get_country_status_label($country_id);
}

/**
 * Migrate existing country names to country IDs
 * This function should be run once to convert all existing country names to IDs
 * 
 * @return array Array with 'success', 'updated', 'skipped', and 'errors' counts
 */
function eap_migrate_country_names_to_ids() {
    global $wpdb;
    
    $result = [
        'success' => false,
        'updated' => 0,
        'skipped' => 0,
        'errors' => 0,
        'message' => ''
    ];
    
    // Get all users with country meta that is not numeric (i.e., still a name)
    $meta_entries = $wpdb->get_results("
        SELECT umeta_id, user_id, meta_value 
        FROM {$wpdb->usermeta} 
        WHERE meta_key = 'country' 
        AND meta_value != '' 
        AND meta_value NOT REGEXP '^[0-9]+$'
    ");
    
    if (empty($meta_entries)) {
        $result['success'] = true;
        $result['message'] = 'No country names to migrate. All countries are already stored as IDs.';
        return $result;
    }
    
    foreach ($meta_entries as $entry) {
        $country_name = $entry->meta_value;
        $country_id = eap_get_country_id_by_name($country_name);
        
        if ($country_id) {
            // Update the meta value with the country ID
            update_user_meta($entry->user_id, 'country', $country_id);
            $result['updated']++;
        } else {
            // Country name not found in reference table
            $result['errors']++;
            error_log("EAP Migration: Could not find country ID for name: {$country_name} (User ID: {$entry->user_id})");
        }
    }
    
    $result['success'] = true;
    $result['message'] = sprintf(
        'Migration complete. Updated: %d, Errors: %d',
        $result['updated'],
        $result['errors']
    );
    
    return $result;
}

/**
 * Render country select dropdown HTML
 * 
 * @param int $selected_id The currently selected country ID
 * @param string $name The name attribute for the select element
 * @param string $id The id attribute for the select element
 * @param string $class Additional CSS classes
 * @param bool $include_empty Whether to include an empty option
 * @return string The HTML for the select dropdown
 */
function eap_render_country_select($selected_id = 0, $name = 'country', $id = 'country', $class = 'regular-text', $include_empty = true) {
    $countries = eap_get_countries();
    
    $html = '<select name="' . esc_attr($name) . '" id="' . esc_attr($id) . '" class="' . esc_attr($class) . '">';
    
    if ($include_empty) {
        $html .= '<option value="">-- Select Country --</option>';
    }
    
    foreach ($countries as $country) {
        $selected = ($country->id == $selected_id) ? ' selected="selected"' : '';
        $html .= '<option value="' . esc_attr($country->id) . '"' . $selected . '>' . esc_html($country->country_name) . '</option>';
    }
    
    $html .= '</select>';
    
    return $html;
}


// === 2. ENQUEUE SCRIPTS & STYLES ===

function eap_enqueue_scripts() {
    // Register the main stylesheet
    wp_register_style(
        'eap-portal-styles',
        EAP_PLUGIN_URL . 'css/eap-portal-styles.css',
        [],
        '20.1.2'
    );

    // Register reusable QRCode library (used for account security overlays)
    wp_register_script(
        'eap-qrcode',
        'https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js',
        [],
        '1.0.0',
        true
    );

    // Register the profile editor script
    wp_register_script(
        'eap-profile-editor',
        EAP_PLUGIN_URL . 'js/eap-profile-editor.js',
        ['jquery', 'eap-qrcode'],
        '14.5.0',
        true
    );
    
    // Register the discussions script (only if EAP Discussions addon is not active)
    // When the addon is active, it registers its own version of this script
    if (!defined('EAP_DISCUSSIONS_VERSION')) {
        wp_register_script(
            'eap-discussions',
            EAP_PLUGIN_URL . 'js/eap-discussions.js',
            ['jquery'],
            '20.1.2',
            true
        );
    }

    // Register workgroup files script
    wp_register_script(
        'eap-workgroup-files',
        EAP_PLUGIN_URL . 'js/eap-workgroup-files.js',
        ['jquery'],
        '1.0.0',
        true
    );

    // Register Office file viewer libraries (XLSX, DOCX, PPTX support)
    wp_register_script(
        'eap-xlsx-lib',
        'https://cdn.sheetjs.com/xlsx-0.20.1/package/dist/xlsx.full.min.js',
        [],
        '0.20.1',
        true
    );

    wp_register_script(
        'eap-mammoth-lib',
        'https://unpkg.com/mammoth@1.6.0/mammoth.browser.min.js',
        [],
        '1.6.0',
        true
    );

    wp_register_script(
        'eap-jszip-lib',
        'https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js',
        [],
        '3.10.1',
        true
    );

    // Register the Office file viewer script
    wp_register_script(
        'eap-office-viewer',
        EAP_PLUGIN_URL . 'js/eap-office-viewer.js',
        ['jquery', 'eap-xlsx-lib', 'eap-mammoth-lib', 'eap-jszip-lib'],
        '20.1.2',
        true
    );

    global $post;
    $has_post = ($post instanceof WP_Post);

    $needs_portal_styles = false;
    $needs_discussions_script = false;
    $needs_workgroup_files_script = false;

    if ( $has_post ) {
        $has_portal_shortcode = has_shortcode( $post->post_content, 'eap_my_profile_editor' ) ||
            has_shortcode( $post->post_content, 'DelegatesDirectory' ) ||
            has_shortcode( $post->post_content, 'eap_member_content' ) ||
            has_shortcode( $post->post_content, 'eap_working_groups_list' ) ||
            has_shortcode( $post->post_content, 'eap_secure_login' );

        if ( $has_portal_shortcode ) {
            $needs_portal_styles = true;
        }

        if ( has_shortcode( $post->post_content, 'eap_single_working_group' ) ) {
            $needs_portal_styles = true;
            $needs_discussions_script = true;
            $needs_workgroup_files_script = true;
        }
    }

    if ( is_singular( 'eap_working_group' ) ) {
        $needs_portal_styles = true;
        $needs_discussions_script = true;
        $needs_workgroup_files_script = true;
    }

    if ( $needs_portal_styles ) {
        wp_enqueue_style('eap-portal-styles');
    }

    if ( $has_post && has_shortcode( $post->post_content, 'eap_my_profile_editor' ) ) {
        // Enqueue Cropper.js for image cropping
        wp_enqueue_style('cropperjs-css', 'https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.6.1/cropper.min.css', [], '1.6.1');
        wp_enqueue_script('cropperjs', 'https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.6.1/cropper.min.js', [], '1.6.1', true);
        
        wp_enqueue_script('eap-profile-editor');
        
        // Localize script with AJAX URL and nonce for photo uploads
        wp_localize_script('eap-profile-editor', 'eapProfileEditor', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'uploadNonce' => wp_create_nonce('eap_profile_photo_upload'),
            'userId' => get_current_user_id(),
            'securityNonce' => wp_create_nonce('eap_account_security'),
            'securityI18n' => [
                'passwordBlank' => __('Enter and confirm a new password to continue.', 'llw-eap-member-portal'),
                'passwordMismatch' => __('Your new password entries do not match.', 'llw-eap-member-portal'),
                'passwordTooShort' => __('Use at least 12 characters for your new password.', 'llw-eap-member-portal'),
                'currentPasswordRequired' => __('Enter your current password to set a new one.', 'llw-eap-member-portal'),
                'codeRequired' => __('Enter the 6-digit code from your authenticator app.', 'llw-eap-member-portal'),
                'secretRequired' => __('Generate a setup key before enabling 2FA.', 'llw-eap-member-portal'),
                'badgeOn' => __('Two-factor authentication is ON', 'llw-eap-member-portal'),
                'badgeOff' => __('Two-factor authentication is OFF', 'llw-eap-member-portal'),
                'qrFallback' => __('Unable to render the QR code. Enter the setup key manually.', 'llw-eap-member-portal'),
                'unknownError' => __('Something went wrong. Please try again.', 'llw-eap-member-portal'),
                'saving' => __('Saving...', 'llw-eap-member-portal'),
                'working' => __('Working...', 'llw-eap-member-portal'),
            ],
        ]);
    }

    // Discussions script is now handled by the EAP Discussions addon plugin
    // Only enqueue from main plugin if addon is NOT active (legacy fallback)
    if ($needs_discussions_script && !defined('EAP_DISCUSSIONS_VERSION')) {
        wp_enqueue_script('eap-discussions');
        
        // Enqueue Office file viewer for XLSX, DOCX, PPTX preview support
        wp_enqueue_script('eap-office-viewer');
        
        // Localize script with AJAX URL and nonce for discussions
        wp_localize_script('eap-discussions', 'eapDiscussions', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('eap_discussion_nonce'),
            'preview' => [
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('eap_preview_spreadsheet'),
                'action' => 'eap_preview_spreadsheet'
            ]
        ]);
    }

    if ( $needs_workgroup_files_script ) {
        wp_enqueue_script('eap-workgroup-files');
        wp_localize_script('eap-workgroup-files', 'eapWorkgroupFiles', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('eap_filter_wg_files'),
            'strings' => [
                'loading' => __('Filtering files...', 'llw-eap-member-portal'),
                'error' => __('Unable to filter files. Please try again.', 'llw-eap-member-portal'),
            ],
        ]);
    }
}
add_action('wp_enqueue_scripts', 'eap_enqueue_scripts');

// Enqueue scripts for the backend profile page
function eap_enqueue_admin_scripts($hook_suffix) {
    // Enqueue for user profile pages
    if ($hook_suffix == 'profile.php' || $hook_suffix == 'user-edit.php') {
        wp_enqueue_media();
        
        // Enqueue Cropper.js for image cropping
        wp_enqueue_style('cropperjs-css', 'https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.6.1/cropper.min.css', [], '3.7.1');
        wp_enqueue_script('cropperjs', 'https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.6.1/cropper.min.js', [], '3.7.1', true);
        
        wp_enqueue_script(
            'eap-admin-profile',
            EAP_PLUGIN_URL . 'js/eap-profile-editor.js', // Reuse the same script
            ['jquery', 'cropperjs'],
            '14.9.8',
            true
        );
        
        // Localize script with AJAX URL and nonce for photo uploads (admin context)
        $user_id = isset($_GET['user_id']) ? intval($_GET['user_id']) : get_current_user_id();
        wp_localize_script('eap-admin-profile', 'eapProfileEditor', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'uploadNonce' => wp_create_nonce('eap_profile_photo_upload'),
            'userId' => $user_id
        ]);
    }
    
    // Enqueue file repeater script for CPT edit pages
    if (EAP_ENABLE_WG_EDITOR) {
        global $post;
        if (($hook_suffix == 'post.php' || $hook_suffix == 'post-new.php') && 
            $post && $post->post_type === 'eap_working_group') {
            wp_enqueue_script(
                'eap-file-repeater',
                EAP_PLUGIN_URL . 'js/eap-file-repeater.js',
                ['jquery'],
                '14.9.8',
                true
            );
        }
    }
    
    // Enqueue styles for audit log page
    if ($hook_suffix == 'membership-system_page_eap-audit-log') {
        wp_enqueue_style(
            'eap-audit-log-styles',
            EAP_PLUGIN_URL . 'css/eap-portal-styles.css',
            [],
            '17.2.0'
        );
    }
    
    if ($hook_suffix == 'membership-system_page_eap-country-statuses') {
        wp_enqueue_script(
            'eap-country-statuses-admin',
            EAP_PLUGIN_URL . 'js/eap-country-statuses.js',
            [],
            '1.0.0',
            true
        );
        
        wp_localize_script('eap-country-statuses-admin', 'eapCountryStatuses', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('eap_country_status_manager'),
            'strings' => [
                'duplicate' => __('Each country can only be assigned once.', 'llw-eap-member-portal'),
                'saving' => __('Saving changes...', 'llw-eap-member-portal'),
                'saved' => __('All changes saved.', 'llw-eap-member-portal'),
                'error' => __('Unable to save changes. Please try again.', 'llw-eap-member-portal'),
                'empty' => __('No countries have been assigned yet. Click "Add Country" to create the first mapping.', 'llw-eap-member-portal'),
            ],
        ]);
    }
}
add_action('admin_enqueue_scripts', 'eap_enqueue_admin_scripts');


// === 3. HELPER: PRIVACY CONTROLS ===

function eap_get_privacy_options_html( $user_id, $field_key, $is_frontend = false ) {
    $visibility_key = 'visibility_' . $field_key;
    $current_visibility = get_user_meta( $user_id, $visibility_key, true ) ?: eap_get_default_field_privacy($field_key); // Use configured default

    ob_start();
    ?>
    <fieldset class="eap-privacy-controls">
        <legend>Visibility:</legend>
        <label>
            <input type="radio" name="<?php echo esc_attr($visibility_key); ?>" value="only_me" <?php checked( $current_visibility, 'only_me' ); ?>>
            Only me
        </label>
        <label>
            <input type="radio" name="<?php echo esc_attr($visibility_key); ?>" value="delegates_only" <?php checked( $current_visibility, 'delegates_only' ); ?>>
            Delegates only
        </label>
        <label>
            <input type="radio" name="<?php echo esc_attr($visibility_key); ?>" value="all_members" <?php checked( $current_visibility, 'all_members' ); ?>>
            Everyone in portal
        </label>
        <?php // 'Admin only' is a valid option, but members shouldn't set this themselves.?>
        <?php if ( !$is_frontend && current_user_can('manage_options') ): ?>
        <label>
            <input type="radio" name="<?php echo esc_attr($visibility_key); ?>" value="admin_only" <?php checked( $current_visibility, 'admin_only' ); ?>>
            Admin only
        </label>
        <?php endif; ?>
    </fieldset>
    <?php
    return ob_get_clean();
}


// === 4. ADMIN BACKEND PROFILE FIELDS ===

add_action( 'show_user_profile', 'eap_add_custom_profile_fields' );
add_action( 'edit_user_profile', 'eap_add_custom_profile_fields' );

function eap_add_custom_profile_fields( $user ) {
    if ( !eap_is_portal_member($user) && !current_user_can('manage_options') ) {
        return;
    }
    ?>
    <h2>EAP Member Details</h2>
    <table class="form-table">
        <tr>
            <th><label for="photo_url">Photo</label></th>
            <td>
                <?php 
                $photo_filename = get_user_meta( $user->ID, 'photo_url', true );
                $photo_url_display = $photo_filename ? eap_get_secure_image_url($user->ID, $photo_filename) : '';
                ?>
                <div class="eap-image-uploader">
                    <input type="text" name="photo_url" id="photo_url" value="<?php echo esc_attr( $photo_url_display ); ?>" class="regular-text" />
                    <button type="button" class="button eap-upload-button">Upload Image</button>
                    <div class="eap-image-preview">
                        <?php if ($photo_url_display): ?>
                            <img src="<?php echo esc_url($photo_url_display); ?>" style="max-width: 100px; height: auto;" />
                        <?php endif; ?>
                    </div>
                </div>
                <?php echo eap_get_privacy_options_html($user->ID, 'photo_url'); ?>
            </td>
        </tr>
         <tr>
            <th><label for="title_prefix">Title (e.g., Prof, Dr)</label></th>
            <td>
                <input type="text" name="title_prefix" id="title_prefix" value="<?php echo esc_attr( get_user_meta( $user->ID, 'title_prefix', true ) ); ?>" class="regular-text" />
                <?php echo eap_get_privacy_options_html($user->ID, 'title_prefix'); ?>
            </td>
        </tr>
        <tr>
            <th><label for="institution">Institution</label></th>
            <td>
                <input type="text" name="institution" id="institution" value="<?php echo esc_attr( get_user_meta( $user->ID, 'institution', true ) ); ?>" class="regular-text" />
                <?php echo eap_get_privacy_options_html($user->ID, 'institution'); ?>
            </td>
        </tr>
        <tr>
            <th><label for="city">City</label></th>
            <td>
                <input type="text" name="city" id="city" value="<?php echo esc_attr( get_user_meta( $user->ID, 'city', true ) ); ?>" class="regular-text" />
                <?php echo eap_get_privacy_options_html($user->ID, 'city'); ?>
            </td>
        </tr>
        <tr>
            <th><label for="country">Country</label></th>
            <td>
                <?php echo eap_render_country_select( get_user_meta( $user->ID, 'country', true ), 'country', 'country', 'regular-text', true ); ?>
                <?php echo eap_get_privacy_options_html($user->ID, 'country'); ?>
            </td>
        </tr>
        <tr>
            <th><label for="preferred_email">Preferred Email</label></th>
            <td>
                <input type="email" name="preferred_email" id="preferred_email" value="<?php echo esc_attr( get_user_meta( $user->ID, 'preferred_email', true ) ); ?>" class="regular-text" />
                <p class="description">Optional. If blank, the main account email will be used.</p>
                <?php echo eap_get_privacy_options_html($user->ID, 'preferred_email'); ?>
            </td>
        </tr>
        <tr>
            <th><label for="phone">Phone</label></th>
            <td>
                <input type="tel" name="phone" id="phone" value="<?php echo esc_attr( get_user_meta( $user->ID, 'phone', true ) ); ?>" class="regular-text" />
                <?php echo eap_get_privacy_options_html($user->ID, 'phone'); ?>
            </td>
        </tr>
        <tr>
            <th><label for="whatsapp_number">WhatsApp Number</label></th>
            <td>
                <input type="tel" name="whatsapp_number" id="whatsapp_number" value="<?php echo esc_attr( get_user_meta( $user->ID, 'whatsapp_number', true ) ); ?>" class="regular-text" placeholder="+1 234 567 8900" />
                <?php echo eap_get_privacy_options_html($user->ID, 'whatsapp_number'); ?>
            </td>
        </tr>
        <tr>
            <th><label for="term_start">Term Start (Month/Year)</label></th>
            <td>
                <input type="text" name="term_start" id="term_start" value="<?php echo esc_attr( get_user_meta( $user->ID, 'term_start', true ) ); ?>" class="regular-text" placeholder="e.g., 06/2024" />
                <?php echo eap_get_privacy_options_html($user->ID, 'term_start'); ?>
            </td>
        </tr>
        <tr>
            <th><label for="term_end">Term End (Month/Year)</label></th>
            <td>
                <input type="text" name="term_end" id="term_end" value="<?php echo esc_attr( get_user_meta( $user->ID, 'term_end', true ) ); ?>" class="regular-text" placeholder="e.g., 06/2027" />
                <?php echo eap_get_privacy_options_html($user->ID, 'term_end'); ?>
            </td>
        </tr>
        <tr>
            <th><label for="society">Society</label></th>
            <td>
                <input type="text" name="society" id="society" value="<?php echo esc_attr( get_user_meta( $user->ID, 'society', true ) ); ?>" class="regular-text" />
                <?php echo eap_get_privacy_options_html($user->ID, 'society'); ?>
            </td>
        </tr>
         <tr>
            <th><label for="specialty">Specialty</label></th>
            <td>
                <input type="text" name="specialty" id="specialty" value="<?php echo esc_attr( get_user_meta( $user->ID, 'specialty', true ) ); ?>" class="regular-text" />
                <?php echo eap_get_privacy_options_html($user->ID, 'specialty'); ?>
            </td>
        </tr>
        <tr>
            <th><label for="eap_council">EAP Permanent Council</label></th>
            <td>
                <?php $council = get_user_meta( $user->ID, 'eap_council', true ); ?>
                <select name="eap_council" id="eap_council">
                    <option value="" <?php selected($council, ''); ?>>-- Select --</option>
                    <option value="pcc" <?php selected($council, 'pcc'); ?>>Primary Care Council</option>
                    <option value="stcc" <?php selected($council, 'stcc'); ?>>Secondary-Tertiary Care Council</option>
                    <option value="both" <?php selected($council, 'both'); ?>>I represent both PCC and STCC</option>
                </select>
                <?php echo eap_get_privacy_options_html($user->ID, 'eap_council'); ?>
            </td>
        </tr>
         <tr>
            <th><label for="biography">Biography</label></th>
            <td>
                <textarea name="biography" id="biography" rows="5" class="regular-text"><?php echo esc_textarea( get_user_meta( $user->ID, 'biography', true ) ); ?></textarea>
                <?php echo eap_get_privacy_options_html($user->ID, 'biography'); ?>
            </td>
        </tr>
         <tr>
            <th><label for="languages">Languages</label></th>
            <td>
                <input type="text" name="languages" id="languages" value="<?php echo esc_attr( get_user_meta( $user->ID, 'languages', true ) ); ?>" class="regular-text" placeholder="e.g., English, French" />
                <?php echo eap_get_privacy_options_html($user->ID, 'languages'); ?>
            </td>
        </tr>
    </table>

    <h3>Working Groups</h3>
    <table class="form-table">
        <tr>
            <th><label for="eap_working_groups">Working Groups</label></th>
            <td>
                <?php
                // Get all Working Group CPTs
                $wg_query = new WP_Query([
                    'post_type' => 'eap_working_group',
                    'posts_per_page' => -1,
                    'orderby' => 'title',
                    'order' => 'ASC'
                ]);
                
                $user_wgs = get_user_meta( $user->ID, 'eap_working_groups', true );
                if (!is_array($user_wgs)) {
                    $user_wgs = [];
                }
                
                if ($wg_query->have_posts()) {
                    echo '<div style="max-height: 200px; overflow-y: auto; border: 1px solid #ddd; padding: 10px;">';
                    while ($wg_query->have_posts()) {
                        $wg_query->the_post();
                        $wg_id = get_the_ID();
                        $checked = in_array($wg_id, $user_wgs) ? 'checked' : '';
                        ?>
                        <label style="display: block; margin-bottom: 5px;">
                            <input type="checkbox" name="eap_working_groups[]" value="<?php echo $wg_id; ?>" <?php echo $checked; ?>>
                            <?php echo esc_html(get_the_title()); ?>
                        </label>
                        <?php
                    }
                    echo '</div>';
                    wp_reset_postdata();
                } else {
                    echo '<p class="description">No working groups available. Create working groups first.</p>';
                }
                ?>
                <p class="description">Select the working groups this member belongs to.</p>
            </td>
        </tr>
    </table>

    <h3>UEMS Delegate Status (Read-Only for Member)</h3>
    <table class="form-table">
        <tr>
            <th><label>UEMS Delegate Status</label></th>
            <td>
                <p><strong><?php echo esc_html( get_user_meta( $user->ID, 'uems_status', true ) ?: 'N/A' ); ?></strong></p>
                <?php $uems_notes = get_user_meta( $user->ID, 'uems_notes', true ); ?>
                <?php if ($uems_notes): ?>
                    <p><em>Notes: <?php echo esc_html($uems_notes); ?></em></p>
                <?php endif; ?>
                <?php $uems_country_status = eap_get_user_country_status_label($user->ID); ?>
                <p><strong><?php esc_html_e('UEMS Country Status', 'llw-eap-member-portal'); ?>:</strong> <?php echo esc_html($uems_country_status ?: 'N/A'); ?></p>
            </td>
        </tr>
    </table>

    <?php
    // Only show UEMS edit fields to the Admin
    if ( current_user_can( 'manage_options' ) ) {
        ?>
        <h3>Admin: Edit UEMS Delegate Status</h3>
        <table class="form-table">
            <tr>
                <th><label for="uems_status_admin">UEMS Delegate Status</label></th>
                <td>
                    <?php 
                    $current_uems_status = get_user_meta( $user->ID, 'uems_status', true );
                    $uems_status_options = [
                        '' => 'Not Set',
                        'Confirmed – National Delegate' => 'Confirmed – National Delegate',
                        'Not yet confirmed – National Delegate' => 'Not yet confirmed – National Delegate',
                        'Confirmed – Associate' => 'Confirmed – Associate',
                        'Confirmed – Observer' => 'Confirmed – Observer',
                        'N/A' => 'N/A',
                    ];
                    $is_custom = !empty($current_uems_status) && !array_key_exists($current_uems_status, $uems_status_options);
                    ?>
                    <select name="uems_status_admin" id="uems_status_admin" class="regular-text" onchange="document.getElementById('uems_status_custom_container').style.display = this.value === '__custom' ? 'block' : 'none';">
                        <?php foreach ($uems_status_options as $value => $label): ?>
                            <option value="<?php echo esc_attr($value); ?>" <?php selected($is_custom ? '' : $current_uems_status, $value); ?>><?php echo esc_html($label); ?></option>
                        <?php endforeach; ?>
                        <option value="__custom" <?php selected($is_custom, true); ?>>Other (specify below)</option>
                    </select>
                    <div id="uems_status_custom_container" style="margin-top: 8px; <?php echo $is_custom ? '' : 'display:none;'; ?>">
                        <input type="text" name="uems_status_custom" id="uems_status_custom" value="<?php echo esc_attr($is_custom ? $current_uems_status : ''); ?>" class="regular-text" placeholder="Enter custom UEMS delegate status" />
                    </div>
                </td>
            </tr>
            <tr>
                <th><label for="uems_email_admin">UEMS Email</label></th>
                <td>
                    <input type="email" name="uems_email_admin" id="uems_email_admin" value="<?php echo esc_attr( get_user_meta( $user->ID, 'uems_email', true ) ); ?>" class="regular-text" />
                    <p class="description">Optional email associated with UEMS records.</p>
                </td>
            </tr>
            <tr>
                <th><label for="uems_notes_admin">UEMS Notes</label></th>
                <td>
                    <textarea name="uems_notes_admin" id="uems_notes_admin" rows="3" class="regular-text"><?php echo esc_textarea( get_user_meta( $user->ID, 'uems_notes', true ) ); ?></textarea>
                </td>
            </tr>
        </table>
        
        <h3>Admin: Account Management</h3>
        <table class="form-table">
            <tr>
                <th><label for="is_active_admin">Account Status</label></th>
                <td>
                    <?php $is_active = get_user_meta( $user->ID, 'is_active', true ); ?>
                    <?php 
                    // Treat empty as active for backwards compatibility
                    $is_active_bool = !($is_active === '0' || $is_active === false);
                    ?>
                    <select name="is_active_admin" id="is_active_admin">
                        <option value="1" <?php selected($is_active_bool, true); ?>>Active</option>
                        <option value="0" <?php selected($is_active_bool, false); ?>>Inactive</option>
                    </select>
                    <p class="description">Inactive members cannot access the portal.</p>
                </td>
            </tr>
        </table>
        <?php
    }
}

/**
 * Sanitize photo URL/filename
 * Extracts filename from secure URLs or keeps legacy URLs intact
 */
function eap_sanitize_photo_url($value) {
    $value = trim($value);
    
    if (empty($value)) {
        return '';
    }
    
    // Check if this is our secure URL format
    if (strpos($value, 'eap_secure_image=1') !== false) {
        // Extract the filename from the query string
        $parsed = parse_url($value);
        if (isset($parsed['query'])) {
            parse_str($parsed['query'], $query_params);
            if (isset($query_params['file'])) {
                return sanitize_file_name($query_params['file']);
            }
        }
    }
    
    // Check if it's a full URL (backwards compatibility with old uploads)
    if (filter_var($value, FILTER_VALIDATE_URL)) {
        return esc_url_raw($value);
    }
    
    // Otherwise treat as filename
    return sanitize_file_name($value);
}

// Hook to save the custom fields
add_action( 'personal_options_update', 'eap_save_custom_profile_fields' );
add_action( 'edit_user_profile_update', 'eap_save_custom_profile_fields' );

function eap_save_custom_profile_fields( $user_id ) {
    if ( !current_user_can( 'edit_user', $user_id ) ) {
        return false;
    }

    // A list of all self-editable fields and their sanitization function
    $self_edit_fields = [
        'photo_url'        => 'eap_sanitize_photo_url', // Custom sanitization for photo URLs/filenames
        'title_prefix'     => 'sanitize_text_field',
        'institution'      => 'sanitize_text_field',
        'city'             => 'sanitize_text_field',
        'country'          => 'absint',
        'preferred_email'  => 'sanitize_email',
        'phone'            => 'sanitize_text_field',
        'whatsapp_number'  => 'sanitize_text_field',
        'term_start'       => 'sanitize_text_field',
        'term_end'         => 'sanitize_text_field',
        'society'          => 'sanitize_text_field',
        'specialty'        => 'sanitize_text_field',
        'eap_council'      => 'sanitize_key',
        'biography'        => 'sanitize_textarea_field',
        'languages'        => 'sanitize_text_field',
    ];

    $changed_data = [];

    foreach ($self_edit_fields as $key => $sanitize_callback) {
        if ( isset($_POST[$key]) ) {
            $new_value = call_user_func( $sanitize_callback, $_POST[$key] );
            $old_value = get_user_meta( $user_id, $key, true );
            
            if ($new_value != $old_value) {
                $changed_data[$key] = ['old' => $old_value, 'new' => $new_value];
                update_user_meta( $user_id, $key, $new_value );
            }
        }
        
        // Also save the corresponding privacy setting
        $visibility_key = 'visibility_' . $key;
        if ( isset($_POST[$visibility_key]) ) {
            $new_vis = sanitize_key( $_POST[$visibility_key] );
            $old_vis = get_user_meta( $user_id, $visibility_key, true ) ?: eap_get_default_field_privacy($key);
            
            if ($new_vis != $old_vis) {
                 $changed_data[$visibility_key] = ['old' => $old_vis, 'new' => $new_vis];
                update_user_meta( $user_id, $visibility_key, $new_vis );
            }
        }
    }

    // Save Admin-only fields
    if ( current_user_can( 'manage_options' ) ) {
        if ( isset($_POST['uems_status_admin']) ) {
            $selected_val = sanitize_text_field( $_POST['uems_status_admin'] );
            // If "Other" was selected, use the custom value
            if ($selected_val === '__custom' && isset($_POST['uems_status_custom'])) {
                $new_val = sanitize_text_field( $_POST['uems_status_custom'] );
            } else {
                $new_val = $selected_val;
            }
            $old_val = get_user_meta( $user_id, 'uems_status', true );
            if ($new_val != $old_val) {
                $changed_data['uems_status'] = ['old' => $old_val, 'new' => $new_val];
                update_user_meta( $user_id, 'uems_status', $new_val );
            }
        }
        if ( isset($_POST['uems_email_admin']) ) {
            $new_val = sanitize_email( $_POST['uems_email_admin'] );
            $old_val = get_user_meta( $user_id, 'uems_email', true );
            if ($new_val != $old_val) {
                $changed_data['uems_email'] = ['old' => $old_val, 'new' => $new_val];
                update_user_meta( $user_id, 'uems_email', $new_val );
            }
        }
        if ( isset($_POST['uems_notes_admin']) ) {
            $new_val = sanitize_textarea_field( $_POST['uems_notes_admin'] );
            $old_val = get_user_meta( $user_id, 'uems_notes', true );
             if ($new_val != $old_val) {
                $changed_data['uems_notes'] = ['old' => $old_val, 'new' => $new_val];
                update_user_meta( $user_id, 'uems_notes', $new_val );
            }
        }
        if ( isset($_POST['is_active_admin']) ) {
            $new_val = sanitize_text_field( $_POST['is_active_admin'] );
            $old_val = get_user_meta( $user_id, 'is_active', true );
            if ($new_val != $old_val) {
                $old_status = ($old_val === '0' || $old_val === false) ? 'Inactive' : 'Active';
                $new_status = ($new_val === '0') ? 'Inactive' : 'Active';
                $changed_data['is_active'] = ['old' => $old_status, 'new' => $new_status];
                update_user_meta( $user_id, 'is_active', $new_val );
            }
        }
        
        // Save Working Groups (array of post IDs)
        if ( isset($_POST['eap_working_groups']) && is_array($_POST['eap_working_groups']) ) {
            $new_wgs = array_map('intval', $_POST['eap_working_groups']);
            $old_wgs = get_user_meta( $user_id, 'eap_working_groups', true );
            if (!is_array($old_wgs)) {
                $old_wgs = [];
            }
            
            // Sort for comparison
            sort($new_wgs);
            sort($old_wgs);
            
            if ($new_wgs != $old_wgs) {
                $changed_data['eap_working_groups'] = ['old' => $old_wgs, 'new' => $new_wgs];
                update_user_meta( $user_id, 'eap_working_groups', $new_wgs );
            }
        } else {
            // If no working groups selected, clear the meta
            $old_wgs = get_user_meta( $user_id, 'eap_working_groups', true );
            if (!empty($old_wgs)) {
                $changed_data['eap_working_groups'] = ['old' => $old_wgs, 'new' => []];
                delete_user_meta( $user_id, 'eap_working_groups' );
            }
        }
    }

    // --- Audit Log Hook ---
    // Log changes if any were made
    if ( !empty($changed_data) ) {
        $editor_id = get_current_user_id();
        $target_user_info = get_userdata($user_id);
        $editor_info = get_userdata($editor_id);

        if ($editor_id == $user_id) {
             eap_log_event(
                sprintf('User "%s" (ID: %d) updated their own profile.', $target_user_info->user_login, $user_id), 
                $changed_data,
                'profile'
             );
        } else {
             eap_log_event(
                sprintf('Admin "%s" (ID: %d) updated the profile of "%s" (ID: %d).', $editor_info->user_login, $editor_id, $target_user_info->user_login, $user_id), 
                $changed_data,
                'profile'
             );
        }
    }
}


// === 5. FRONTEND "MY PROFILE" EDITOR ===

/**
 * Shortcode [eap_my_profile_editor]
 * Provides a frontend form for members to edit their own profile.
 */
function eap_my_profile_editor_shortcode() {
    $user = wp_get_current_user();
    
    // 1. Access Check
    if ( !is_user_logged_in() || !eap_is_portal_member($user) ) {
        return '<p>You must be a logged-in member to view this content. Please ' . wp_loginout( get_permalink(), false ) . '</p>';
    }

    $user_id = $user->ID;
    $existing_totp_secret = get_user_meta($user_id, 'eap_totp_secret', true);
    $site_label = get_bloginfo('name');
    $totp_label = rawurlencode($site_label . ':' . $user->user_email);
    $totp_issuer = rawurlencode($site_label);
    $proposed_totp_secret = $existing_totp_secret ? '' : eap_generate_totp_secret();
    $initial_totp_otpauth = $proposed_totp_secret
        ? sprintf('otpauth://totp/%s?secret=%s&issuer=%s', $totp_label, $proposed_totp_secret, $totp_issuer)
        : '';
    $update_success = false;

    // 2. Handle Form Submission
    if ( isset($_POST['eap_profile_nonce']) && check_admin_referer('eap_update_profile_' . $user_id, 'eap_profile_nonce') ) {        
        // We can re-use the admin save function
        eap_save_custom_profile_fields( $user_id );
        
        // Save standard WP fields
        if ( isset($_POST['first_name']) ) {
            $old_val = $user->first_name;
            $new_val = sanitize_text_field( $_POST['first_name'] );
            if ($old_val != $new_val) {
                update_user_meta( $user_id, 'first_name', $new_val );
            }
        }
        if ( isset($_POST['last_name']) ) {
            $old_val = $user->last_name;
            $new_val = sanitize_text_field( $_POST['last_name'] );
            if ($old_val != $new_val) {
                update_user_meta( $user_id, 'last_name', $new_val );
            }
        }
        if ( isset($_POST['email']) ) {
            $email = sanitize_email($_POST['email']);
            if ( $email != $user->user_email && (!email_exists($email) || email_exists($email) == $user_id) ) {
                wp_update_user( ['ID' => $user_id, 'user_email' => $email] );
            }
        }
        
        $update_success = true;
    }

    // 3. Display The Form
    // Get all user meta
    $meta = get_user_meta($user_id);
    $get_meta = function($key) use ($meta) {
        return isset($meta[$key][0]) ? $meta[$key][0] : '';
    };

    ob_start();

    if ($update_success) {
        echo '<div class="eap-notice eap-success"><p>Profile updated successfully!</p></div>';
    }

    ?>
    <div class="eap-profile-form">
        <!-- Profile Editor Header -->
        <div class="eap-profile-editor-header">
            <h2>Edit Your Profile</h2>
            <p>Complete your profile to help other members connect with you. All fields are optional unless marked as required.</p>
        </div>

        <div class="eap-profile-highlights" role="list">
            <div class="eap-profile-highlight" role="listitem">
                <span class="eap-profile-highlight__icon" aria-hidden="true">🔐</span>
                <div>
                    <p class="eap-profile-highlight__title"><?php esc_html_e('Privacy controls', 'llw-eap-member-portal'); ?></p>
                    <p class="eap-profile-highlight__text"><?php esc_html_e('Choose who can see each field before it appears in the directory.', 'llw-eap-member-portal'); ?></p>
                </div>
            </div>
            <div class="eap-profile-highlight" role="listitem">
                <span class="eap-profile-highlight__icon" aria-hidden="true">✨</span>
                <div>
                    <p class="eap-profile-highlight__title"><?php esc_html_e('Directory ready', 'llw-eap-member-portal'); ?></p>
                    <p class="eap-profile-highlight__text"><?php esc_html_e('A complete profile helps delegates discover collaborators faster.', 'llw-eap-member-portal'); ?></p>
                </div>
            </div>
            <div class="eap-profile-highlight" role="listitem">
                <span class="eap-profile-highlight__icon" aria-hidden="true">🤝</span>
                <div>
                    <p class="eap-profile-highlight__title"><?php esc_html_e('Need assistance?', 'llw-eap-member-portal'); ?></p>
                    <p class="eap-profile-highlight__text"><?php esc_html_e('Save anytime—support is here if you need help updating details.', 'llw-eap-member-portal'); ?></p>
                </div>
            </div>
        </div>
        
        <div class="eap-account-security-cta">
            <div class="eap-account-security-cta__text">
                <p class="eap-account-security-cta__title"><?php esc_html_e('Keep your login protected', 'llw-eap-member-portal'); ?></p>
                <p class="eap-account-security-cta__body">
                    <?php esc_html_e('Open the account security panel to update your password or turn on authenticator-based 2FA without leaving this page.', 'llw-eap-member-portal'); ?>
                </p>
            </div>
            <button type="button" class="eap-account-security-button" data-eap-account-security-open>
                <span class="eap-account-security-button__icon" aria-hidden="true">🔒</span>
                <?php esc_html_e('Account Security', 'llw-eap-member-portal'); ?>
            </button>
        </div>

        <!-- Progress Bar Section -->
        <div class="eap-progress-section">
            <h3>Profile Completion</h3>
            <div class="eap-progress-bar-container">
                <div class="eap-progress-bar-fill low">
                    <span class="eap-progress-percentage">0%</span>
                </div>
            </div>
            <div class="eap-progress-text">0 of 0 fields completed</div>
        </div>
        
        <form method="post" action="">
            <?php wp_nonce_field( 'eap_update_profile_' . $user_id, 'eap_profile_nonce' ); ?>
            
            <!-- Section 1: Basic Information -->
            <div class="eap-profile-section" id="section-basic">
                <div class="eap-section-header" tabindex="0" role="button" aria-expanded="true">
                    <div class="eap-section-title-wrapper">
                        <div class="eap-section-icon">👤</div>
                        <div>
                            <h3 class="eap-section-title">Basic Information</h3>
                            <p class="eap-section-description">Your name, email, and contact details</p>
                        </div>
                    </div>
                    <span class="eap-toggle-icon">›</span>
                </div>
                <div class="eap-section-content">
                    <table class="form-table">
                        <tr>
                            <th><label for="first_name">First Name <span class="eap-required">*</span></label></th>
                            <td><input type="text" name="first_name" id="first_name" value="<?php echo esc_attr( $user->first_name ); ?>" class="regular-text" /></td>
                        </tr>
                        <tr>
                            <th><label for="last_name">Last Name <span class="eap-required">*</span></label></th>
                            <td><input type="text" name="last_name" id="last_name" value="<?php echo esc_attr( $user->last_name ); ?>" class="regular-text" /></td>
                        </tr>
                        <tr>
                            <th><label for="email">Email (Login) <span class="eap-required">*</span></label></th>
                            <td>
                                <input type="email" name="email" id="email" value="<?php echo esc_attr( $user->user_email ); ?>" class="regular-text" />
                                <p class="description">This is your primary login email</p>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="preferred_email">Preferred Email</label></th>
                            <td>
                                <input type="email" name="preferred_email" id="preferred_email" value="<?php echo esc_attr( $get_meta('preferred_email') ); ?>" class="regular-text" placeholder="If different from login email" />
                                <p class="description">Email shown in the member directory</p>
                                <?php echo eap_get_privacy_options_html($user_id, 'preferred_email', true); ?>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="phone">Phone Number</label></th>
                            <td>
                                <input type="tel" name="phone" id="phone" value="<?php echo esc_attr( $get_meta('phone') ); ?>" class="regular-text" placeholder="+1 234 567 8900" />
                                <?php echo eap_get_privacy_options_html($user_id, 'phone', true); ?>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="whatsapp_number">WhatsApp Number</label></th>
                            <td>
                                <input type="tel" name="whatsapp_number" id="whatsapp_number" value="<?php echo esc_attr( $get_meta('whatsapp_number') ); ?>" class="regular-text" placeholder="+1 234 567 8900" />
                                <?php echo eap_get_privacy_options_html($user_id, 'whatsapp_number', true); ?>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="title_prefix">Title</label></th>
                            <td>
                                <input type="text" name="title_prefix" id="title_prefix" value="<?php echo esc_attr( $get_meta('title_prefix') ); ?>" class="regular-text" placeholder="e.g., Prof, Dr, Mr, Ms" />
                                <?php echo eap_get_privacy_options_html($user_id, 'title_prefix', true); ?>
                            </td>
                        </tr>
                    </table>
                </div>
            </div>
            
            <!-- Section 2: Profile Photo -->
            <div class="eap-profile-section" id="section-photo">
                <div class="eap-section-header" tabindex="0" role="button" aria-expanded="true">
                    <div class="eap-section-title-wrapper">
                        <div class="eap-section-icon">📷</div>
                        <div>
                            <h3 class="eap-section-title">Profile Photo</h3>
                            <p class="eap-section-description">Upload a professional photo (optional)</p>
                        </div>
                    </div>
                    <span class="eap-toggle-icon">›</span>
                </div>
                <div class="eap-section-content">
                    <div class="eap-photo-upload-section">
                        <?php 
                        $photo_filename = $get_meta('photo_url');
                        $photo_url_display = $photo_filename ? eap_get_secure_image_url($user_id, $photo_filename) : '';
                        ?>
                        
                        <div class="eap-photo-drop-zone">
                            <div class="eap-drop-zone-icon">⬆️</div>
                            <div class="eap-drop-zone-text">
                                Drag & drop your photo here<br>or click to browse<br>
                                <small>JPG, PNG, GIF, WebP (max 5MB)</small>
                            </div>
                        </div>
                        
                        <div class="eap-image-uploader">
                            <input type="text" name="photo_url" id="photo_url" value="<?php echo esc_attr( $photo_url_display ); ?>" class="regular-text" placeholder="Or paste image URL here..." />
                            <div class="eap-image-preview">
                                <?php if ($photo_url_display): ?>
                                    <img src="<?php echo esc_url($photo_url_display); ?>" alt="Profile photo preview" />
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <?php echo eap_get_privacy_options_html($user_id, 'photo_url', true); ?>
                    </div>
                </div>
            </div>
            
            <!-- Section 3: Professional Information -->
            <div class="eap-profile-section" id="section-professional">
                <div class="eap-section-header" tabindex="0" role="button" aria-expanded="true">
                    <div class="eap-section-title-wrapper">
                        <div class="eap-section-icon">🏢</div>
                        <div>
                            <h3 class="eap-section-title">Professional Information</h3>
                            <p class="eap-section-description">Your institution, location, and professional details</p>
                        </div>
                    </div>
                    <span class="eap-toggle-icon">›</span>
                </div>
                <div class="eap-section-content">
                    <table class="form-table">
                        <tr>
                            <th><label for="institution">Institution</label></th>
                            <td>
                                <input type="text" name="institution" id="institution" value="<?php echo esc_attr( $get_meta('institution') ); ?>" class="regular-text" placeholder="e.g., University Hospital of Copenhagen" />
                                <?php echo eap_get_privacy_options_html($user_id, 'institution', true); ?>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="city">City</label></th>
                            <td>
                                <input type="text" name="city" id="city" value="<?php echo esc_attr( $get_meta('city') ); ?>" class="regular-text" placeholder="e.g., Copenhagen" />
                                <?php echo eap_get_privacy_options_html($user_id, 'city', true); ?>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="country">Country</label></th>
                            <td>
                                <?php echo eap_render_country_select( $get_meta('country'), 'country', 'country', 'regular-text', true ); ?>
                                <?php echo eap_get_privacy_options_html($user_id, 'country', true); ?>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="society">Society</label></th>
                            <td>
                                <input type="text" name="society" id="society" value="<?php echo esc_attr( $get_meta('society') ); ?>" class="regular-text" placeholder="e.g., National Paediatric Society" />
                                <?php echo eap_get_privacy_options_html($user_id, 'society', true); ?>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="specialty">Specialty</label></th>
                            <td>
                                <input type="text" name="specialty" id="specialty" value="<?php echo esc_attr( $get_meta('specialty') ); ?>" class="regular-text" placeholder="e.g., Child & Adolescent Paediatrics" />
                                <?php echo eap_get_privacy_options_html($user_id, 'specialty', true); ?>
                            </td>
                        </tr>
                    </table>
                </div>
            </div>
            
            <!-- Section 4: EAP Membership Details -->
            <div class="eap-profile-section" id="section-eap">
                <div class="eap-section-header" tabindex="0" role="button" aria-expanded="true">
                    <div class="eap-section-title-wrapper">
                        <div class="eap-section-icon">🎯</div>
                        <div>
                            <h3 class="eap-section-title">EAP Membership Details</h3>
                            <p class="eap-section-description">Your role and term information</p>
                        </div>
                    </div>
                    <span class="eap-toggle-icon">›</span>
                </div>
                <div class="eap-section-content">
                    <table class="form-table">
                        <tr>
                            <th><label for="eap_council">EAP Permanent Council</label></th>
                            <td>
                                <?php $council = $get_meta('eap_council'); ?>
                                <select name="eap_council" id="eap_council">
                                    <option value="" <?php selected($council, ''); ?>>-- Select --</option>
                                    <option value="pcc" <?php selected($council, 'pcc'); ?>>Primary Care Council (PCC)</option>
                                    <option value="stcc" <?php selected($council, 'stcc'); ?>>Secondary-Tertiary Care Council (STCC)</option>
                                    <option value="both" <?php selected($council, 'both'); ?>>I represent both PCC and STCC</option>
                                </select>
                                <?php echo eap_get_privacy_options_html($user_id, 'eap_council', true); ?>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="term_start">Term Start Date</label></th>
                            <td>
                                <input type="text" name="term_start" id="term_start" value="<?php echo esc_attr( $get_meta('term_start') ); ?>" class="regular-text" placeholder="e.g., 06/2024 or June 2024" />
                                <?php echo eap_get_privacy_options_html($user_id, 'term_start', true); ?>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="term_end">Term End Date</label></th>
                            <td>
                                <input type="text" name="term_end" id="term_end" value="<?php echo esc_attr( $get_meta('term_end') ); ?>" class="regular-text" placeholder="e.g., 06/2027 or June 2027" />
                                <?php echo eap_get_privacy_options_html($user_id, 'term_end', true); ?>
                            </td>
                        </tr>
                        <tr>
                            <th>UEMS Delegate Status</th>
                            <td>
                                <div class="eap-readonly-section">
                                    <p><strong>Status:</strong> <?php echo esc_html( $get_meta('uems_status') ?: 'N/A' ); ?></p>
                                    <?php $notes = $get_meta('notes'); ?>
                                    <?php if ($notes): ?>
                                        <p><strong>Notes:</strong> <em><?php echo esc_html($notes); ?></em></p>
                                    <?php endif; ?>
                                    <?php $profile_country_status = eap_get_user_country_status_label($user_id); ?>
                                    <p><strong><?php esc_html_e('UEMS Country Status', 'llw-eap-member-portal'); ?>:</strong> <?php echo esc_html($profile_country_status ?: 'N/A'); ?></p>
                                    <p><small><em>This field is read-only and managed by administrators.</em></small></p>
                                </div>
                            </td>
                        </tr>
                    </table>
                </div>
            </div>
            
            <!-- Section 5: About You -->
            <div class="eap-profile-section" id="section-about">
                <div class="eap-section-header" tabindex="0" role="button" aria-expanded="true">
                    <div class="eap-section-title-wrapper">
                        <div class="eap-section-icon">✍️</div>
                        <div>
                            <h3 class="eap-section-title">About You</h3>
                            <p class="eap-section-description">Share more about yourself and your expertise</p>
                        </div>
                    </div>
                    <span class="eap-toggle-icon">›</span>
                </div>
                <div class="eap-section-content">
                    <table class="form-table">
                        <tr>
                            <th><label for="biography">Biography</label></th>
                            <td>
                                <textarea name="biography" id="biography" rows="6" class="regular-text" placeholder="Tell us about your background, experience, and interests in paediatrics..."><?php echo esc_textarea( $get_meta('biography') ); ?></textarea>
                                <p class="description">This will be displayed on your public profile</p>
                                <?php echo eap_get_privacy_options_html($user_id, 'biography', true); ?>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="languages">Languages</label></th>
                            <td>
                                <input type="text" name="languages" id="languages" value="<?php echo esc_attr( $get_meta('languages') ); ?>" class="regular-text" placeholder="e.g., English, French, German" />
                                <p class="description">List languages you speak (comma-separated)</p>
                                <?php echo eap_get_privacy_options_html($user_id, 'languages', true); ?>
                            </td>
                        </tr>
                    </table>
                </div>
            </div>
            
            <!-- Privacy Notice -->
            <div class="eap-privacy-tip">
                <strong>📋 Privacy Notice:</strong> By choosing a broader visibility for any field, you consent to sharing that information with other portal members according to your selected privacy level. 
                Please review the <a href="/privacy-policy" target="_blank">Portal Privacy Notice</a> for details on how your data is collected, used, and protected.
            </div>
            
            <!-- Submit Button -->
            <p class="submit">
                <input type="submit" name="eap_profile_submit" class="button-primary" value="💾 Update Profile">
            </p>
        </form>

    <div class="eap-data-download-section">
        <h3>Your Data</h3>
        <p>You have the right to data portability. You can download a copy of your profile data in a machine-readable format.</p>
        <a href="<?php echo esc_url( add_query_arg('eap_action', 'download_profile_data') ); ?>" class="button">Download My Data (JSON)</a>
    </div>

    </div>

      <div
          id="eap-account-security-modal"
          class="eap-account-security-modal"
          data-totp-enabled="<?php echo $existing_totp_secret ? '1' : '0'; ?>"
          data-proposed-secret="<?php echo esc_attr($proposed_totp_secret); ?>"
          data-otpauth="<?php echo esc_attr($initial_totp_otpauth); ?>"
          hidden
          aria-hidden="true">
          <div class="eap-account-security-overlay" data-eap-account-security-close></div>
          <div class="eap-account-security-dialog" role="dialog" aria-modal="true" aria-labelledby="eap-account-security-title">
              <button type="button" class="eap-account-security-close" data-eap-account-security-close aria-label="<?php esc_attr_e('Close account security', 'llw-eap-member-portal'); ?>">&times;</button>
              
              <header class="eap-account-security-header">
                  <p class="eap-account-security-eyebrow"><?php esc_html_e('Quick safety check', 'llw-eap-member-portal'); ?></p>
                  <h3 id="eap-account-security-title"><?php esc_html_e('Account Security', 'llw-eap-member-portal'); ?></h3>
                  <p class="eap-account-security-description">
                      <?php esc_html_e('Update your password and manage authenticator-based two-factor authentication without leaving this page.', 'llw-eap-member-portal'); ?>
                  </p>
              </header>

              <section class="eap-account-security-section">
                  <h4><?php esc_html_e('Change Password', 'llw-eap-member-portal'); ?></h4>
                  <p class="eap-account-security-note">
                      <?php esc_html_e('Leave the "New password" and "Confirm new password" fields blank to keep your current password.', 'llw-eap-member-portal'); ?>
                  </p>
                  <form id="eap-account-security-password-form" class="eap-account-security-form" novalidate>
                      <div class="eap-account-security-field">
                          <label for="eap-current-password"><?php esc_html_e('Current password', 'llw-eap-member-portal'); ?></label>
                          <input type="password" id="eap-current-password" name="current_password" autocomplete="current-password" />
                      </div>
                      <div class="eap-account-security-field">
                          <label for="eap-new-password"><?php esc_html_e('New password', 'llw-eap-member-portal'); ?></label>
                          <input type="password" id="eap-new-password" name="new_password" autocomplete="new-password" />
                      </div>
                      <div class="eap-account-security-field">
                          <label for="eap-confirm-password"><?php esc_html_e('Confirm new password', 'llw-eap-member-portal'); ?></label>
                          <input type="password" id="eap-confirm-password" name="confirm_password" autocomplete="new-password" />
                      </div>
                      <div class="eap-account-security-feedback" data-feedback="password" role="alert" aria-live="polite"></div>
                      <div class="eap-account-security-actions">
                          <button type="submit" class="eap-account-security-primary"><?php esc_html_e('Save Password', 'llw-eap-member-portal'); ?></button>
                      </div>
                  </form>
              </section>

              <section class="eap-account-security-section" data-account-security-panel>
                  <div class="eap-account-security-section-heading">
                      <div>
                          <h4><?php esc_html_e('Authenticator (2FA)', 'llw-eap-member-portal'); ?></h4>
                          <p class="eap-account-security-status-text">
                              <?php esc_html_e('Use Google Authenticator, 1Password, Authy, or any other TOTP app.', 'llw-eap-member-portal'); ?>
                          </p>
                      </div>
                      <span class="eap-account-security-badge <?php echo $existing_totp_secret ? 'is-enabled' : 'is-disabled'; ?>" data-eap-account-security-badge>
                          <?php echo $existing_totp_secret
                              ? esc_html__('2FA enabled', 'llw-eap-member-portal')
                              : esc_html__('2FA off', 'llw-eap-member-portal'); ?>
                      </span>
                  </div>
                  <form id="eap-account-security-2fa-form" class="eap-account-security-form" novalidate>
                      <input type="hidden" name="totp_secret" value="<?php echo esc_attr($proposed_totp_secret); ?>" data-eap-account-security-secret-field>
                      
                      <div class="eap-account-security-instructions">
                          <p data-eap-account-security-instructions="disabled" <?php echo $existing_totp_secret ? 'hidden' : ''; ?>>
                              <?php esc_html_e('Scan the QR code or enter the setup key below, then type a 6-digit code from your authenticator app to finish.', 'llw-eap-member-portal'); ?>
                          </p>
                          <p data-eap-account-security-instructions="enabled" <?php echo $existing_totp_secret ? '' : 'hidden'; ?>>
                              <?php esc_html_e('Enter a current 6-digit authenticator code to turn 2FA off if you need to move it to a new device.', 'llw-eap-member-portal'); ?>
                          </p>
                      </div>

                      <div class="eap-account-security-qr" data-eap-account-security-qr aria-hidden="<?php echo $existing_totp_secret ? 'true' : 'false'; ?>">
                          <?php if ( !$existing_totp_secret && $initial_totp_otpauth ) : ?>
                              <noscript>
                                  <p><?php esc_html_e('Enable JavaScript to view the QR code, or enter the setup key shown below in your authenticator app.', 'llw-eap-member-portal'); ?></p>
                              </noscript>
                          <?php endif; ?>
                      </div>

                      <div class="eap-account-security-secret" data-visible-when="disabled" <?php echo $existing_totp_secret ? 'hidden' : ''; ?>>
                          <span class="eap-account-security-secret-label"><?php esc_html_e('Setup key', 'llw-eap-member-portal'); ?></span>
                          <code class="eap-account-security-secret-value" data-eap-account-security-secret-value><?php echo esc_html($proposed_totp_secret); ?></code>
                          <button type="button" class="eap-account-security-refresh" data-security-action="refresh">
                              <?php esc_html_e('Generate a new setup key', 'llw-eap-member-portal'); ?>
                          </button>
                      </div>

                      <div class="eap-account-security-field">
                          <label for="eap-totp-code"><?php esc_html_e('Authenticator code', 'llw-eap-member-portal'); ?></label>
                          <input type="text" id="eap-totp-code" name="totp_code" inputmode="numeric" pattern="[0-9]*" autocomplete="one-time-code" placeholder="123456" />
                          <p class="eap-account-security-help">
                              <?php esc_html_e('Use the latest 6-digit code from your authenticator app.', 'llw-eap-member-portal'); ?>
                          </p>
                      </div>

                      <div class="eap-account-security-feedback" data-feedback="totp" role="alert" aria-live="polite"></div>

                      <div class="eap-account-security-actions">
                          <button type="submit" class="eap-account-security-primary" data-security-action="enable" <?php echo $existing_totp_secret ? 'hidden' : ''; ?>>
                              <?php esc_html_e('Enable 2FA', 'llw-eap-member-portal'); ?>
                          </button>
                          <button type="submit" class="eap-account-security-secondary" data-security-action="disable" <?php echo $existing_totp_secret ? '' : 'hidden'; ?>>
                              <?php esc_html_e('Disable 2FA', 'llw-eap-member-portal'); ?>
                          </button>
                      </div>
                  </form>
              </section>
          </div>
      </div>
    <?php
    return ob_get_clean();
}
add_shortcode('eap_my_profile_editor', 'eap_my_profile_editor_shortcode');


// === 6. GDPR DATA DOWNLOAD HANDLER ===

function eap_profile_download_handler() {
    if ( isset($_GET['eap_action']) && $_GET['eap_action'] == 'download_profile_data' ) {
        
        if ( !is_user_logged_in() || !eap_is_portal_member() ) {
            wp_die('You must be logged in to download your data.', 'Access Denied', 403);
        }

        $user = wp_get_current_user();
        $user_id = $user->ID;
        $data = [];

        // Get standard user data
        $data['user_login'] = $user->user_login;
        $data['user_email'] = $user->user_email;
        $data['first_name'] = $user->first_name;
        $data['last_name'] = $user->last_name;
        $data['display_name'] = $user->display_name;
        $data['registered_date'] = $user->user_registered;

        // Get all custom meta data
        $custom_fields = [
            'photo_url', 'title_prefix', 'institution', 'city', 'country', 
            'preferred_email', 'phone', 'whatsapp_number', 'term_start', 'term_end', 'society', 
            'specialty', 'eap_council', 'biography', 'languages',
            'uems_status', 'uems_notes'
        ];

        $data['custom_profile'] = [];
        foreach ($custom_fields as $key) {
            $data['custom_profile'][$key] = get_user_meta($user_id, $key, true);
        }

        // Get privacy settings
        $data['privacy_settings'] = [];
        foreach (array_keys($data['custom_profile']) as $key) {
             $data['privacy_settings'][$key] = get_user_meta($user_id, 'visibility_' . $key, true) ?: eap_get_default_field_privacy($key);
        }

        // Serve the file
        $filename = 'eap-profile-' . $user->user_login . '-' . date('Y-m-d') . '.json';
        
        header('Content-Type: application/json');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        
        if (ob_get_level()) {
            ob_end_clean();
        }
        
        echo json_encode($data, JSON_PRETTY_PRINT);
        exit;
    }
}
add_action('init', 'eap_profile_download_handler');


// === 7. DELEGATES DIRECTORY ===

/**
 * Checks if a field is visible based on privacy settings.
 */
function eap_can_view_field( $target_user_id, $field_key ) {
    $current_user = wp_get_current_user();

    // Users can always see their own fields
    if ( $current_user->ID == $target_user_id ) {
        return true;
    }
    // Admins can see everything
    if ( current_user_can('manage_options') ) {
        return true;
    }
    
    $visibility = get_user_meta( $target_user_id, 'visibility_' . $field_key, true ) ?: eap_get_default_field_privacy($field_key); // Use configured default

    switch( $visibility ) {
        case 'only_me':
        case 'admin_only':
            return false;

        case 'delegates_only':
        case 'all_members': // 'all_members' and 'delegates_only' are functionally the same
            $member_roles = eap_get_member_roles();
            return !empty( array_intersect( $member_roles, $current_user->roles ) );
    }
    return false;
}

/**
 * Shortcode [DelegatesDirectory]
 */
function eap_directory_shortcode() {
    
    // 1. Access Check
    if ( !is_user_logged_in() || !eap_is_portal_member() ) {
        return '<p>You must be a logged-in member to view this content. Please ' . wp_loginout( get_permalink(), false ) . '</p>';
    }

    ob_start();

    // 2. Get filter values
    $filter_country = isset($_GET['filter_country']) ? absint($_GET['filter_country']) : 0;
    $filter_society = isset($_GET['filter_society']) ? sanitize_text_field($_GET['filter_society']) : '';
    $filter_wg = isset($_GET['filter_wg']) ? intval($_GET['filter_wg']) : 0;
    $filter_term_status = isset($_GET['filter_term_status']) ? sanitize_key($_GET['filter_term_status']) : 'all';
    $paged = ( get_query_var( 'paged' ) ) ? get_query_var( 'paged' ) : 1;

    // 3. Display Filter Form 
    ?>
    <form class="eap-directory-filters" method="get" action="">
        <div class="eap-filter-item">
            <label for="filter_country">Country:</label>
            <select id="filter_country" name="filter_country">
                <option value="0">-- All Countries --</option>
                <?php
                $countries = eap_get_countries();
                foreach ($countries as $country) {
                    $selected = ($filter_country == $country->id) ? 'selected' : '';
                    echo '<option value="' . esc_attr($country->id) . '" ' . $selected . '>' . esc_html($country->country_name) . '</option>';
                }
                ?>
            </select>
        </div>
        <div class="eap-filter-item">
            <label for="filter_society">National Society:</label>
            <input type="text" id="filter_society" name="filter_society" value="<?php echo esc_attr($filter_society); ?>" placeholder="Filter by society...">
        </div>
        <div class="eap-filter-item">
            <label for="filter_wg">Working Group:</label>
            <select id="filter_wg" name="filter_wg">
                <option value="0">-- All --</option>
                <?php
                // Get all Working Groups
                $wg_query = new WP_Query([
                    'post_type' => 'eap_working_group',
                    'posts_per_page' => -1,
                    'orderby' => 'title',
                    'order' => 'ASC'
                ]);
                
                if ($wg_query->have_posts()) {
                    while ($wg_query->have_posts()) {
                        $wg_query->the_post();
                        $wg_id = get_the_ID();
                        $selected = ($filter_wg == $wg_id) ? 'selected' : '';
                        echo '<option value="' . $wg_id . '" ' . $selected . '>' . esc_html(get_the_title()) . '</option>';
                    }
                    wp_reset_postdata();
                }
                ?>
            </select>
        </div>
<!--        <div class="eap-filter-item">
            <label for="filter_term_status">Term Status:</label>
            <select id="filter_term_status" name="filter_term_status">
                <option value="all" <?php selected($filter_term_status, 'all'); ?>>All</option>
                <option value="active" <?php selected($filter_term_status, 'active'); ?>>Active</option>
                <option value="expired" <?php selected($filter_term_status, 'expired'); ?>>Expired</option>
            </select>
        </div>-->
        <div class="eap-filter-item">
            <button type="submit" class="button">Filter</button>
            <a href="<?php echo esc_url(get_permalink()); ?>" class="button">Clear</a>
        </div>
    </form>
    
    <div class="directory-container">
        <?php
        $delegate_roles = eap_get_member_roles();
        $delegate_roles = array_diff($delegate_roles, ['read_only_staff']); // Don't show staff
        
        $args = array(
            'role__in' => $delegate_roles,
            'orderby' => 'display_name',
            'number' => 20, // Pagination
            'paged' => $paged,
            'meta_query' => ['relation' => 'AND']
        );

        // Add filters to meta query
        if (!empty($filter_country)) {
            $args['meta_query'][] = [
                'key' => 'country',
                'value' => $filter_country,
                'compare' => '='
            ];
        }
        if (!empty($filter_society)) {
            $args['meta_query'][] = [
                'key' => 'society',
                'value' => $filter_society,
                'compare' => 'LIKE'
            ];
        }
        
        // Working Group Filter
        // Note: Since eap_working_groups is stored as a serialized array, we need to use LIKE comparison
        if (!empty($filter_wg)) {
            $args['meta_query'][] = [
                'key' => 'eap_working_groups',
                'value' => sprintf(':"%d";', $filter_wg), // Serialized array format contains :"ID";
                'compare' => 'LIKE'
            ];
        }
        
        // For term status, we'll need to do post-filtering since WP_User_Query doesn't handle date comparisons well
        // We'll get all users first, then filter by term status
        $user_query = new WP_User_Query( $args );
        $users = $user_query->get_results();
        
        // Apply Term Status filter if needed
        if ($filter_term_status !== 'all' && !empty($users)) {
            $current_date = current_time('Y-m'); // Format: YYYY-MM
            $filtered_users = [];
            
            foreach ($users as $user) {
                $term_end = get_user_meta($user->ID, 'term_end', true);
                
                // Parse term_end (expected format: MM/YYYY or YYYY-MM)
                $term_end_date = '';
                if (!empty($term_end)) {
                    // Try to parse MM/YYYY format
                    if (preg_match('/^(\d{2})\/(\d{4})$/', $term_end, $matches)) {
                        $term_end_date = $matches[2] . '-' . $matches[1]; // Convert to YYYY-MM
                    }
                    // Try to parse YYYY-MM format
                    elseif (preg_match('/^(\d{4})-(\d{2})$/', $term_end, $matches)) {
                        $term_end_date = $term_end; // Already in YYYY-MM format
                    }
                }
                
                // Filter based on term status
                if ($filter_term_status === 'active') {
                    // Include users with no term_end or term_end >= current date
                    if (empty($term_end_date) || $term_end_date >= $current_date) {
                        $filtered_users[] = $user;
                    }
                } elseif ($filter_term_status === 'expired') {
                    // Include users with term_end < current date
                    if (!empty($term_end_date) && $term_end_date < $current_date) {
                        $filtered_users[] = $user;
                    }
                }
            }
            
            $users = $filtered_users;
        }
        
        if ( ! empty( $users ) ) {
            foreach ( $users as $user ) {
                ?>
                <div class="member-card">
                    <!-- Card Header with Avatar and Name -->
                    <div class="member-card-header">
                        <?php 
                        // --- PHOTO ---
                        if ( eap_can_view_field( $user->ID, 'photo_url' ) ) {
                            $photo_filename = get_user_meta( $user->ID, 'photo_url', true );
                            if ($photo_filename) {
                                $secure_url = eap_get_secure_image_url($user->ID, $photo_filename);
                                echo '<img src="' . esc_url($secure_url) . '" alt="' . esc_attr($user->display_name) . '" class="eap-avatar">';
                            } else {
                                // Placeholder avatar
                                echo '<img src="' . esc_url(get_avatar_url($user->ID)) . '" alt="Placeholder" class="eap-avatar">';
                            }
                        } else {
                            // Default avatar if photo field is hidden
                            echo '<img src="' . esc_url(get_avatar_url($user->ID)) . '" alt="' . esc_attr($user->display_name) . '" class="eap-avatar">';
                        }
                        ?>
                        
                        <h4>
                            <?php 
                            // --- TITLE ---
                            if ( eap_can_view_field( $user->ID, 'title_prefix' ) ) {
                                $title = get_user_meta( $user->ID, 'title_prefix', true );
                                if ($title) echo esc_html( $title ) . ' ';
                            }
                            echo esc_html( $user->display_name ); 
                            ?>
                        </h4>
                    </div>
                    
                    <!-- Card Body with Member Details -->
                    <div class="member-card-body">
                        <?php // --- INSTITUTION ---
                        if ( eap_can_view_field( $user->ID, 'institution' ) ) {
                            $value = get_user_meta( $user->ID, 'institution', true );
                            if ($value) echo '<p><strong>Institution:</strong> ' . esc_html( $value ) . '</p>';
                        }
                        ?>
                        
                        <?php // --- CITY / COUNTRY ---
                        $city = eap_can_view_field( $user->ID, 'city' ) ? get_user_meta( $user->ID, 'city', true ) : '';
                        $country_id = eap_can_view_field( $user->ID, 'country' ) ? get_user_meta( $user->ID, 'country', true ) : '';
                        $country = $country_id ? eap_get_country_name($country_id) : '';
                        
                        if ( $city || $country ) {
                            echo '<p><strong>Location:</strong> ' . esc_html( implode(', ', array_filter([$city, $country])) ) . '</p>';
                        }
                        ?>
                        
                        <?php // --- SOCIETY ---
                        if ( eap_can_view_field( $user->ID, 'society' ) ) {
                             $value = get_user_meta( $user->ID, 'society', true );
                             if ($value) echo '<p><strong>Society:</strong> ' . esc_html( $value ) . '</p>';
                        }
                        ?>

                        <?php // --- UEMS STATUS --- 
                        if ( eap_can_view_field( $user->ID, 'uems_status' ) ) {
                             $value = get_user_meta( $user->ID, 'uems_status', true );
                             if ($value) echo '<p><strong>UEMS Delegate Status:</strong> ' . esc_html( $value ) . '</p>';
                        }
                        
                        $card_country_status = eap_get_user_country_status_label($user->ID) ?: 'N/A';
                        $card_country_label = esc_html__('UEMS Country Status', 'llw-eap-member-portal');
                        echo '<p><strong>' . $card_country_label . ':</strong> ' . esc_html( $card_country_status ) . '</p>';
                        ?>
                    </div>
                    
                    <!-- Card Footer with Action Button -->
                    <?php 
                    // --- VIEW PROFILE LINK ---
                    $profile_page_slug = apply_filters('eap_profile_page_slug', 'delegate');
                    $profile_url = home_url('/' . $profile_page_slug . '/?user_id=' . $user->ID);
                    ?>
                    <div class="eap-view-profile-link">
                        <a href="<?php echo esc_url($profile_url); ?>">View Full Profile</a>
                    </div>
                </div>
                <?php
            }
        } else {
            // Enhanced empty state
            ?>
            <div class="eap-no-members">
                <div class="eap-no-members-icon">🔍</div>
                <p>No members found matching your criteria.</p>
            </div>
            <?php
        }
        ?>
    </div>

    <?php
    // 4. Add Pagination Links
    // Note: With term status filtering, pagination count may not be accurate
    // For a more accurate implementation, consider getting all users without pagination first
    if ($filter_term_status === 'all') {
        // Use WP_User_Query's built-in pagination when no term filtering
        $total_users = $user_query->get_total();
        $total_pages = ceil($total_users / $args['number']);

        if ($total_pages > 1) {
            echo '<div class="eap-pagination">';
            echo paginate_links( array(
                'base' => add_query_arg('paged', '%#%'),
                'format' => '?paged=%#%',
                'current' => $paged,
                'total' => $total_pages,
                'prev_text' => '← Previous',
                'next_text' => 'Next →',
            ) );
            echo '</div>';
        }
    } else {
        // When term filtering is active, pagination is approximate
        $total_users = count($users);
        if ($total_users >= $args['number']) {
            echo '<div class="eap-results-counter"><em>Results are filtered by term status. Use more specific filters to narrow results.</em></div>';
        }
    }

    return ob_get_clean();
}
add_shortcode('DelegatesDirectory', 'eap_directory_shortcode');

/**
 * Shortcode [eap_single_member_profile]
 * Displays a single member's full profile respecting privacy settings
 * 
 * Usage: [eap_single_member_profile]
 * Expects user_id parameter in URL: ?user_id=123
 */
function eap_single_member_profile_shortcode($atts) {
    // 1. Access Check
    if ( !is_user_logged_in() || !eap_is_portal_member() ) {
        return '<p>You must be a logged-in member to view this content. Please ' . wp_loginout( get_permalink(), false ) . '</p>';
    }

    // 2. Get target user ID from URL parameter
    $target_user_id = isset($_GET['user_id']) ? intval($_GET['user_id']) : 0;
    
    if (!$target_user_id) {
        return '<p>No member specified.</p>';
    }

    // 3. Get the target user
    $target_user = get_user_by('ID', $target_user_id);
    
    if (!$target_user || !eap_is_portal_member($target_user)) {
        return '<p>Member not found.</p>';
    }

    // 4. Helper function to get meta value
    $get_meta = function($key) use ($target_user_id) {
        return get_user_meta($target_user_id, $key, true);
    };

    // 5. Helper function to display a field if viewable
    $display_field = function($label, $field_key, $is_textarea = false) use ($target_user_id, $get_meta) {
        if (eap_can_view_field($target_user_id, $field_key)) {
            $value = $get_meta($field_key);
            if (!empty($value)) {
                if ($is_textarea) {
                    return '<div class="eap-profile-field"><strong>' . esc_html($label) . ':</strong><div class="eap-profile-value">' . nl2br(esc_html($value)) . '</div></div>';
                } else {
                    return '<div class="eap-profile-field"><strong>' . esc_html($label) . ':</strong> <span class="eap-profile-value">' . esc_html($value) . '</span></div>';
                }
            }
        }
        return '';
    };

    ob_start();
    
    // Get the directory page URL for the back button
    $directory_page_slug = apply_filters('eap_directory_page_slug', 'delegates-directory');
    $directory_url = home_url('/' . $directory_page_slug . '/');
    
    // Check if there's a referrer to go back to, otherwise use directory
    $back_url = wp_get_referer() ? wp_get_referer() : $directory_url;
    
    // If referrer is not from the same site, use directory URL
    if (strpos($back_url, home_url()) === false) {
        $back_url = $directory_url;
    }
    ?>
    
    <div class="eap-single-profile">
        
        <!-- Back Button at Top -->
        <div class="eap-profile-back-button">
            <a href="<?php echo esc_url($back_url); ?>">Back to Directory</a>
        </div>
        
        <!-- Profile Header Card -->
        <div class="eap-profile-header">
            <?php 
            // Photo
            if (eap_can_view_field($target_user_id, 'photo_url')) {
                $photo_filename = $get_meta('photo_url');
                if ($photo_filename) {
                    $secure_url = eap_get_secure_image_url($target_user_id, $photo_filename);
                    echo '<div class="eap-profile-photo"><img src="' . esc_url($secure_url) . '" alt="' . esc_attr($target_user->display_name) . '"></div>';
                } else {
                    echo '<div class="eap-profile-photo"><img src="' . esc_url(get_avatar_url($target_user_id, ['size' => 200])) . '" alt="Placeholder"></div>';
                }
            }
            ?>
            
            <div class="eap-profile-header-info">
                <h2 class="eap-profile-name">
                    <?php 
                    if (eap_can_view_field($target_user_id, 'title_prefix')) {
                        $title = $get_meta('title_prefix');
                        if ($title) {
                            echo esc_html($title) . ' ';
                        }
                    }
                    echo esc_html($target_user->display_name);
                    ?>
                </h2>
                
                <div class="eap-profile-role">
                    <?php 
                    $roles = $target_user->roles;
                    if (!empty($roles)) {
                        // Convert role slug to readable name
                        $role_names = [
                            'national_delegate' => 'National Delegate',
                            'young_eap_member' => 'Young EAP Member',
                            'affiliated_society_rep' => 'Rep. of Affiliated or Related Society',
                            'subspecialty_society_rep' => 'Rep. of UEMS Subspecialty Society',
                            'read_only_staff' => 'Read-Only Staff'
                        ];
                        $role = $roles[0];
                        echo '<span class="eap-role-badge">' . esc_html($role_names[$role] ?? ucwords(str_replace('_', ' ', $role))) . '</span>';
                    }
                    ?>
                </div>
            </div>
        </div>

        <div class="eap-profile-sections">
            
            <!-- Contact Information -->
            <div class="eap-profile-section">
                <h3>Contact Information</h3>
                <div class="eap-profile-fields">
                    <?php 
                    echo $display_field('Institution', 'institution');
                    
                    // City and Country
                    $city = eap_can_view_field($target_user_id, 'city') ? $get_meta('city') : '';
                    $country_id = eap_can_view_field($target_user_id, 'country') ? $get_meta('country') : '';
                    $country = $country_id ? eap_get_country_name($country_id) : '';
                    if ($city || $country) {
                        $location = implode(', ', array_filter([$city, $country]));
                        echo '<div class="eap-profile-field"><strong>Location:</strong> <span class="eap-profile-value">' . esc_html($location) . '</span></div>';
                    }
                    
                    echo $display_field('Preferred Email', 'preferred_email');
                    echo $display_field('Phone', 'phone');
                    echo $display_field('WhatsApp', 'whatsapp_number');
                    ?>
                </div>
            </div>

            <!-- Professional Information -->
            <div class="eap-profile-section">
                <h3>Professional Information</h3>
                <div class="eap-profile-fields">
                    <?php 
                    echo $display_field('Society', 'society');
                    echo $display_field('Specialty', 'specialty');
                    
                    // EAP Council
                    if (eap_can_view_field($target_user_id, 'eap_council')) {
                        $council = $get_meta('eap_council');
                        if (!empty($council)) {
                            $council_labels = [
                                'pcc' => 'Primary Care Council',
                                'stcc' => 'Secondary-Tertiary Care Council',
                                'both' => 'Both PCC and STCC'
                            ];
                            $council_label = $council_labels[$council] ?? $council;
                            echo '<div class="eap-profile-field"><strong>EAP Permanent Council:</strong> <span class="eap-profile-value">' . esc_html($council_label) . '</span></div>';
                        }
                    }
                    
                    echo $display_field('Term Start', 'term_start');
                    echo $display_field('Term End', 'term_end');
                    
                    // UEMS Delegate Status
                    if (eap_can_view_field($target_user_id, 'uems_status')) {
                        $uems = $get_meta('uems_status');
                        if (!empty($uems)) {
                            echo '<div class="eap-profile-field"><strong>UEMS Delegate Status:</strong> <span class="eap-profile-value">' . esc_html($uems) . '</span></div>';
                        }
                    }
                    
                    $profile_country_status = eap_get_user_country_status_label($target_user_id) ?: 'N/A';
                    $country_status_label = esc_html__('UEMS Country Status', 'llw-eap-member-portal');
                    echo '<div class="eap-profile-field"><strong>' . $country_status_label . ':</strong> <span class="eap-profile-value">' . esc_html($profile_country_status) . '</span></div>';
                    ?>
                </div>
            </div>

            <!-- Biography & Languages -->
            <?php 
            $has_bio = eap_can_view_field($target_user_id, 'biography') && !empty($get_meta('biography'));
            $has_languages = eap_can_view_field($target_user_id, 'languages') && !empty($get_meta('languages'));
            
            if ($has_bio || $has_languages) : 
            ?>
            <div class="eap-profile-section">
                <h3>About</h3>
                <div class="eap-profile-fields">
                    <?php 
                    echo $display_field('Biography', 'biography', true);
                    
                    // Custom display for languages as tags
                    if ($has_languages) {
                        $languages_value = $get_meta('languages');
                        $languages_array = array_map('trim', explode(',', $languages_value));
                        $languages_array = array_filter($languages_array); // Remove empty values
                        
                        // Define the same color palette as in JavaScript
                        $tag_colors = [
                            '#e74c3c', '#3498db', '#2ecc71', '#9b59b6', '#f39c12', '#1abc9c',
                            '#e67e22', '#16a085', '#c0392b', '#8e44ad', '#27ae60', '#2980b9',
                            '#d35400', '#c92a2a', '#2f9e44', '#1864ab', '#862e9c', '#d9480f'
                        ];
                        
                        echo '<div class="eap-profile-field">';
                        echo '<strong>Languages:</strong>';
                        echo '<div class="eap-profile-value">';
                        echo '<div class="eap-language-tags-display">';
                        
                        foreach ($languages_array as $language) {
                            // Get a consistent random color for each language (based on hash)
                            $color_index = abs(crc32(strtolower($language))) % count($tag_colors);
                            $color = $tag_colors[$color_index];
                            
                            echo '<div class="eap-language-tag" style="background-color: ' . esc_attr($color) . ';">';
                            echo esc_html($language);
                            echo '</div>';
                        }
                        
                        echo '</div>'; // .eap-language-tags-display
                        echo '</div>'; // .eap-profile-value
                        echo '</div>'; // .eap-profile-field
                    }
                    ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- Working Groups -->
            <?php 
            $wg_ids = $get_meta('eap_working_groups');
            if (!empty($wg_ids) && is_array($wg_ids)) : 
            ?>
            <div class="eap-profile-section">
                <h3>Working Groups</h3>
                <div class="eap-profile-fields">
                    <ul class="eap-working-groups-list">
                        <?php 
                        foreach ($wg_ids as $wg_id) {
                            $wg_post = get_post($wg_id);
                            if ($wg_post) {
                                echo '<li>' . esc_html($wg_post->post_title) . '</li>';
                            }
                        }
                        ?>
                    </ul>
                </div>
            </div>
            <?php endif; ?>

        </div>

        <!-- Bottom Action Button -->
        <div class="eap-profile-actions">
            <a href="<?php echo esc_url($back_url); ?>" class="button">Back to Directory</a>
        </div>

    </div>

    <?php
    return ob_get_clean();
}
add_shortcode('eap_single_member_profile', 'eap_single_member_profile_shortcode');


// === 8. LOCAL FILE ARCHIVES (CPTs) ===

function eap_register_cpts() {
    // Working Groups
    register_post_type('eap_working_group', [
        'labels' => ['name' => 'Working Groups', 'singular_name' => 'Working Group'],
        'public' => true,
        'has_archive' => true,
        'menu_icon' => 'dashicons-groups',
        'supports' => ['title', 'editor', 'custom-fields'],
        'rewrite' => ['slug' => 'working-groups'],
        'show_ui' => EAP_ENABLE_WG_EDITOR,
        'show_in_menu' => EAP_ENABLE_WG_EDITOR,
        'show_in_admin_bar' => EAP_ENABLE_WG_EDITOR,
        'show_in_nav_menus' => false,
    ]);
    
}
add_action('init', 'eap_register_cpts');

// Shortcode to list Working Groups [eap_working_groups_list]
function eap_working_groups_shortcode() {
    if ( !is_user_logged_in() || !eap_is_portal_member() ) {
        return '<p>You must be a logged-in member to view this content. Please ' . wp_loginout( get_permalink(), false ) . '</p>';
    }
    
    // Check if we're displaying a single working group
    if ( isset($_REQUEST['wg_id']) && !empty($_REQUEST['wg_id']) ) {
        return eap_single_working_group_display();
    }

    $drive_sync_notice = '';
    if ( class_exists( 'EAP_Workgroup_Drive_Sync' ) && EAP_Workgroup_Drive_Sync::is_configured() ) {
        $maybe_sync = EAP_Workgroup_Drive_Sync::instance()->maybe_full_sync( false, 'frontend' );
        if ( is_wp_error( $maybe_sync ) ) {
            $error_code = $maybe_sync->get_error_code();
            error_log( '[EAP] Working group directory sync failed: ' . $maybe_sync->get_error_message() );
            if ( 'eap_drive_locked' !== $error_code && current_user_can( 'manage_options' ) ) {
                $drive_sync_notice = '<div class="eap-wg-sync-notice" style="margin-bottom:20px;padding:12px;border-left:4px solid #d63638;background:#fff4f4;">' .
                    esc_html( sprintf( __( 'Drive sync failed: %s', 'llw-eap-member-portal' ), $maybe_sync->get_error_message() ) ) .
                    '</div>';
            }
        }
    }
    
    $query = new WP_Query([
        'post_type' => 'eap_working_group',
        'posts_per_page' => -1,
        'orderby' => 'title',
        'order' => 'ASC'
    ]);
    
    if ( !$query->have_posts() ) {
        return '<div class="eap-no-items">' .
               '<div class="eap-no-items-icon">📂</div>' .
               '<p>No working groups found.</p>' .
               '</div>';
    }
    
    $output = '<div class="eap-wg-directory">';
    $output .= '<div class="eap-wg-header">';
    $output .= '<h1>Reference Hub</h1>';
    $output .= '<p>Resources for Permanent Councils, Networks, SAGs and General Assembly</p>';
    $output .= '</div>';
    
    if ( $drive_sync_notice ) {
        $output .= $drive_sync_notice;
    }
    
    $output .= '<div class="eap-wg-grid">';
    
    while ($query->have_posts()) {
        $query->the_post();
        $wg_id = get_the_ID();
        $wg_link = add_query_arg('wg_id', $wg_id, "https://eapaediatrics.eu/working-group-page");
        $description = get_the_excerpt();
        if (empty($description)) {
            $description = wp_trim_words(get_the_content(), 20, '...');
        }
        
        // Get file count
        $files = get_post_meta($wg_id, '_eap_secure_files', true);
        $file_count = is_array($files) ? count($files) : 0;
        
        $output .= '<div class="eap-wg-card">';
        $output .= '<div class="eap-wg-card-header">';
        $output .= '<div class="eap-wg-icon">📁</div>';
        $output .= '<h3>' . esc_html(get_the_title()) . '</h3>';
        $output .= '</div>';
        
        if (!empty($description)) {
            $output .= '<div class="eap-wg-card-body">';
            $output .= '<p class="eap-wg-description">' . eap_convert_text_urls_to_secure_links( $description ) . '</p>';
            $output .= '</div>';
        }
        
        $output .= '<div class="eap-wg-card-footer">';
        if ($file_count > 0) {
            $output .= '<div class="eap-wg-file-count">';
            $output .= '<span class="eap-file-icon">📄</span>';
            $output .= '<span>' . $file_count . ' file' . ($file_count > 1 ? 's' : '') . ' available</span>';
            $output .= '</div>';
        }
        $output .= '<a href="' . esc_url($wg_link) . '" class="eap-wg-view-btn">View Details</a>';
        $output .= '</div>';
        
        $output .= '</div>';
    }
    
    $output .= '</div></div>';
    
    wp_reset_postdata();
    return $output;
}
add_shortcode('eap_working_groups_list', 'eap_working_groups_shortcode');

// Display single working group content
function eap_single_working_group_display() {
    if ( !is_user_logged_in() || !eap_is_portal_member() ) {
        return '<p>You must be a logged-in member to view this content. Please ' . wp_loginout( get_permalink(), false ) . '</p>';
    }
    
    $wg_id = intval($_REQUEST['wg_id']);
    
    if ( $wg_id <= 0 ) {
        return '<p>Invalid working group ID.</p>';
    }
    
    $post = get_post($wg_id);
    
    if ( !$post || $post->post_type !== 'eap_working_group' ) {
        return '<p>Working group not found.</p>';
    }

    $drive_sync_notice = '';
    if ( class_exists( 'EAP_Workgroup_Drive_Sync' ) && EAP_Workgroup_Drive_Sync::is_configured() ) {
        $single_sync = EAP_Workgroup_Drive_Sync::instance()->sync_single_workgroup( $wg_id );
        if ( is_wp_error( $single_sync ) ) {
            $error_code = $single_sync->get_error_code();
            error_log( '[EAP] Working group sync failed: ' . $single_sync->get_error_message() );
            if ( 'eap_drive_locked' !== $error_code && current_user_can( 'manage_options' ) ) {
                $drive_sync_notice = '<div class="eap-wg-sync-notice" style="margin:20px 0;padding:12px;border-left:4px solid #d63638;background:#fff4f4;">' .
                    esc_html( sprintf( __( 'Drive sync failed: %s', 'llw-eap-member-portal' ), $single_sync->get_error_message() ) ) .
                    '</div>';
            }
        }
    }
    
    // Build the output
    $output = '<div class="eap-single-working-group">';
    
    // Back link
    $back_url = "https://eapaediatrics.eu/working-groups-directory/";
    $output .= '<div class="eap-wg-back-button">';
    $output .= '<a href="' . esc_url($back_url) . '">Back to Reference Hub</a>';
    $output .= '</div>';
    
    // Header Card
    $output .= '<div class="eap-wg-header-card">';
    $output .= '<div class="eap-wg-header-icon">📁</div>';
    $output .= '<h1>' . esc_html($post->post_title) . '</h1>';
    $output .= '</div>';

    if ( $drive_sync_notice ) {
        $output .= $drive_sync_notice;
    }
    
    // Content Section
    if ( !empty($post->post_content) ) {
        $output .= '<div class="eap-wg-content-section">';
        $output .= '<h3>About This Working Group</h3>';
        $output .= '<div class="eap-wg-content">' . apply_filters('the_content', $post->post_content) . '</div>';
        $output .= '</div>';
    }
    
    // Migrate old single-file format if needed
    eap_migrate_single_file_to_multi($wg_id);
    
    // Display attached files
    $files = get_post_meta($wg_id, '_eap_secure_files', true);
    
    $file_count = is_array($files) ? count($files) : 0;
    
    if ($file_count > 0) {
        $search_id = 'eap-wg-file-search-' . $wg_id;
        $type_id = 'eap-wg-file-type-' . $wg_id;
        $folder_id = 'eap-wg-file-folder-' . $wg_id;
        $subfolder_id = 'eap-wg-include-subfolders-' . $wg_id;
        $type_options = eap_get_workgroup_file_type_options();
        $folder_options = eap_get_workgroup_folder_options($files);
        $folder_data_json = eap_get_folder_data_json($files);
        $status_text = eap_get_workgroup_file_status_text($file_count);
        $file_cards = eap_render_workgroup_file_cards($wg_id, $files);
        
        // Check if there are any subfolders (more than just 'all' and 'root' options)
        $has_folders = count($folder_options) > 2;
        
        $output .= '<div class="eap-wg-files-section" data-wg-id="' . esc_attr($wg_id) . '" data-folder-info="' . esc_attr($folder_data_json) . '">';
        $output .= '<h3>Available Resources</h3>';
        $output .= '<div class="eap-wg-file-filters" role="search">';
        $output .= '  <div class="eap-wg-file-filter-field">';
        $output .= '      <label for="' . esc_attr($search_id) . '">Search files</label>';
        $output .= '      <input type="text" id="' . esc_attr($search_id) . '" class="eap-wg-file-search" placeholder="Type to search..." autocomplete="off">';
        $output .= '  </div>';
        $output .= '  <div class="eap-wg-file-filter-field">';
        $output .= '      <label for="' . esc_attr($type_id) . '">Filter by type</label>';
        $output .= '      <select id="' . esc_attr($type_id) . '" class="eap-wg-file-type">';
        foreach ($type_options as $value => $label) {
            $output .= '<option value="' . esc_attr($value) . '">' . esc_html($label) . '</option>';
        }
        $output .= '      </select>';
        $output .= '  </div>';
        
        // Only show folder filter if there are subfolders
        if ($has_folders) {
            $output .= '  <div class="eap-wg-file-filter-field">';
            $output .= '      <label for="' . esc_attr($folder_id) . '">Filter by folder</label>';
            $output .= '      <select id="' . esc_attr($folder_id) . '" class="eap-wg-file-folder">';
            foreach ($folder_options as $value => $label) {
                $output .= '<option value="' . esc_attr($value) . '">' . esc_html($label) . '</option>';
            }
            $output .= '      </select>';
            $output .= '  </div>';
            $output .= '  <div class="eap-wg-file-filter-field eap-wg-subfolder-field" style="display: none;">';
            $output .= '      <label class="eap-wg-checkbox-label">';
            $output .= '          <input type="checkbox" id="' . esc_attr($subfolder_id) . '" class="eap-wg-include-subfolders" checked>';
            $output .= '          <span>Include subfolders</span>';
            $output .= '      </label>';
            $output .= '  </div>';
        }
        
        $output .= '</div>';
        $output .= '<div class="eap-wg-files-status" aria-live="polite">' . esc_html($status_text) . '</div>';
        $output .= '<div class="eap-wg-files-grid">';
        $output .= $file_cards !== '' 
            ? $file_cards 
            : eap_render_workgroup_no_files_message(__('No files found.', 'llw-eap-member-portal'));
        $output .= '</div>';
        $output .= '</div>';
    } else {
        $output .= '<div class="eap-wg-files-section">';
        $output .= eap_render_workgroup_no_files_message(__('No files available for this working group yet.', 'llw-eap-member-portal'));
        $output .= '</div>';
    }
    
    // Discussion Section - only rendered if EAP Discussions addon is active
    if (function_exists('eap_render_discussion_section')) {
        $output .= eap_render_discussion_section($wg_id, 'eap_working_group', 'Working Group Discussion');
    }
    
    $output .= '</div>';
    
    return $output;
}
add_shortcode('eap_single_working_group', 'eap_single_working_group_display');

// === 9. SECURE FILE STORAGE & UPLOAD ===

function eap_create_secure_storage() {
    $upload_dir = wp_upload_dir();
    $secure_path = $upload_dir['basedir'] . '/eap_secure_files';
    $confidential_path = $upload_dir['basedir'] . '/LLW_CONFIDENTIAL';

    // Create main secure files directory
    if ( ! file_exists( $secure_path ) ) wp_mkdir_p( $secure_path );
    $htaccess_file = $secure_path . '/.htaccess';
    if ( ! file_exists( $htaccess_file ) ) @file_put_contents( $htaccess_file, "Deny from all" . PHP_EOL );
    $index_file = $secure_path . '/index.php';
    if ( ! file_exists( $index_file ) ) @file_put_contents( $index_file, "<?php // Silence is golden." );
    
    // Create LLW_CONFIDENTIAL directory for profile images
    if ( ! file_exists( $confidential_path ) ) wp_mkdir_p( $confidential_path );
    $htaccess_file_conf = $confidential_path . '/.htaccess';
    if ( ! file_exists( $htaccess_file_conf ) ) @file_put_contents( $htaccess_file_conf, "Deny from all" . PHP_EOL );
    $index_file_conf = $confidential_path . '/index.php';
    if ( ! file_exists( $index_file_conf ) ) @file_put_contents( $index_file_conf, "<?php // Silence is golden." );
}

function eap_add_form_enctype() {
    echo ' enctype="multipart/form-data"';
}
if (EAP_ENABLE_WG_EDITOR) {
    add_action('post_edit_form_tag', 'eap_add_form_enctype');
}

function eap_add_file_upload_metabox() {
    $post_types = ['eap_working_group'];
    add_meta_box(
        'eap_secure_file_metabox',
        'Secure File Attachments',
        'eap_file_upload_metabox_html',
        $post_types, 'side', 'high'
    );
}
if (EAP_ENABLE_WG_EDITOR) {
    add_action('add_meta_boxes', 'eap_add_file_upload_metabox');
}

function eap_file_upload_metabox_html($post) {
    // Migrate old single-file format if needed
    eap_migrate_single_file_to_multi($post->ID);
    
    wp_nonce_field('eap_save_secure_file', 'eap_file_nonce');
    $files = get_post_meta($post->ID, '_eap_secure_files', true);
    
    if (!is_array($files)) {
        $files = [];
    }
    
    echo '<div id="eap-existing-files">';
    if (!empty($files)) {
        echo '<div style="margin-bottom: 15px;"><strong>Current Files:</strong></div>';
        foreach ($files as $index => $file) {
            echo '<div class="eap-existing-file" style="margin-bottom: 10px; padding: 8px; background: #f0f0f0; border-radius: 3px;">';
            echo '<div style="display: flex; justify-content: space-between; align-items: center;">';
            echo '<span style="font-size: 13px;">' . esc_html($file['name']) . '</span>';
            echo '<button type="button" class="eap-remove-existing-file button button-small" data-file-index="' . $index . '" style="color: #b32d2e;">Remove</button>';
            echo '</div></div>';
        }
    } else {
        echo '<p style="margin-bottom: 15px;">No files attached.</p>';
    }
    echo '</div>';
    
    ?>
    <div id="eap-file-repeater-container">
        <!-- New file upload rows will be added here -->
    </div>
    
    <button type="button" class="eap-add-file-btn button button-primary" style="margin-top: 10px;">
        + Add File
    </button>
    
    <p class="description" style="margin-top: 10px;">Files will be saved in the secure directory.</p>
    <?php
}

function eap_save_cpt_file_upload($post_id) {
    if ( !isset($_POST['eap_file_nonce']) || !wp_verify_nonce($_POST['eap_file_nonce'], 'eap_save_secure_file') ) return;
    if ( defined('DOING_AUTOSAVE') && DOING_AUTOSAVE ) return;
    if ( !current_user_can('edit_post', $post_id) ) return;

    $upload_dir = wp_upload_dir();
    $secure_path = $upload_dir['basedir'] . '/eap_secure_files/';
    
    // Get existing files
    $existing_files = get_post_meta($post_id, '_eap_secure_files', true);
    if (!is_array($existing_files)) {
        $existing_files = [];
    }
    
    // Handle file removals
    if (isset($_POST['eap_files_to_remove']) && is_array($_POST['eap_files_to_remove'])) {
        foreach ($_POST['eap_files_to_remove'] as $index) {
            $index = intval($index);
            if (isset($existing_files[$index])) {
                // Delete the physical file
                if (file_exists($existing_files[$index]['path'])) {
                    @unlink($existing_files[$index]['path']);
                }
                
                // Log file removal
                $post = get_post($post_id);
                eap_log_event(
                    sprintf('File removed from "%s" (ID: %d): %s', $post->post_title, $post_id, $existing_files[$index]['name']),
                    [
                        'post_id' => $post_id,
                        'post_type' => $post->post_type,
                        'post_title' => $post->post_title,
                        'file_name' => $existing_files[$index]['name']
                    ],
                    'file'
                );
                
                // Remove from array
                unset($existing_files[$index]);
            }
        }
        // Reindex array
        $existing_files = array_values($existing_files);
    }
    
    // Handle new file uploads
    if (isset($_FILES['eap_file_uploads']) && is_array($_FILES['eap_file_uploads']['name'])) {
        $files = $_FILES['eap_file_uploads'];
        
        foreach ($files['name'] as $key => $file_name) {
            // Skip if no file or error
            if (empty($file_name) || $files['error'][$key] != UPLOAD_ERR_OK) {
                continue;
            }
            
            $file_name = basename($file_name);
            $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
            $safe_name = sanitize_file_name(pathinfo($file_name, PATHINFO_FILENAME));
            
            // Store original name for download
            $original_name = $file_name;
            
            // Create unique name with timestamp to avoid conflicts
            $timestamp = time();
            $final_name = $post_id . '-' . $safe_name . '-' . $timestamp . '.' . $file_ext;
            $target_path = $secure_path . $final_name;
            
            if (move_uploaded_file($files['tmp_name'][$key], $target_path)) {
                // Add to files array
                $existing_files[] = [
                    'name' => $original_name,
                    'path' => $target_path,
                    'unique_name' => $final_name,
                    'uploaded' => current_time('mysql')
                ];
                
                // Log file upload
                $post = get_post($post_id);
                eap_log_event(
                    sprintf('File uploaded for "%s" (ID: %d): %s', $post->post_title, $post_id, $original_name),
                    [
                        'post_id' => $post_id,
                        'post_type' => $post->post_type,
                        'post_title' => $post->post_title,
                        'file_name' => $original_name,
                        'file_size' => filesize($target_path)
                    ],
                    'file'
                );
            }
        }
    }
    
    // Save updated files array
    update_post_meta($post_id, '_eap_secure_files', $existing_files);
    
    // Clean up old single-file meta keys (migration support)
    delete_post_meta($post_id, '_eap_secure_file_path');
    delete_post_meta($post_id, '_eap_secure_file_name');
    delete_post_meta($post_id, '_eap_secure_file_unique_name');
}
if (EAP_ENABLE_WG_EDITOR) {
    add_action('save_post_eap_working_group', 'eap_save_cpt_file_upload');
}

// Migration helper: Convert old single-file format to new multi-file format
function eap_migrate_single_file_to_multi($post_id) {
    // Check if already using new format
    $files = get_post_meta($post_id, '_eap_secure_files', true);
    if (is_array($files) && !empty($files)) {
        return; // Already migrated
    }
    
    // Check for old format
    $old_file_path = get_post_meta($post_id, '_eap_secure_file_path', true);
    $old_file_name = get_post_meta($post_id, '_eap_secure_file_name', true);
    $old_unique_name = get_post_meta($post_id, '_eap_secure_file_unique_name', true);
    
    if ($old_file_path && $old_file_name) {
        // Migrate to new format
        $new_files = [[
            'name' => $old_file_name,
            'path' => $old_file_path,
            'unique_name' => $old_unique_name,
            'uploaded' => get_post_modified_time('Y-m-d H:i:s', false, $post_id)
        ]];
        
        update_post_meta($post_id, '_eap_secure_files', $new_files);
        
        // Clean up old meta
        delete_post_meta($post_id, '_eap_secure_file_path');
        delete_post_meta($post_id, '_eap_secure_file_name');
        delete_post_meta($post_id, '_eap_secure_file_unique_name');
    }
}

/**
 * Map of previewable file extensions grouped by media type.
 *
 * @return array
 */
function eap_get_previewable_file_types() {
    return [
        'image'       => ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'],
        'pdf'         => ['pdf'],
        'audio'       => ['mp3'],
        'video'       => ['mp4'],
        'csv'         => ['csv'],
        'xlsx'        => ['xlsx', 'xls'],
        'docx'        => ['docx', 'doc'],
        'pptx'        => ['pptx'],
    ];
}

/**
 * Determine the preview type for a given file extension.
 *
 * @param string $extension
 * @return string Either image, pdf, audio, video or empty string if unsupported.
 */
function eap_get_file_preview_type($extension) {
    if (empty($extension)) {
        return '';
    }

    $extension = strtolower($extension);
    $map = eap_get_previewable_file_types();

    foreach ($map as $type => $extensions) {
        if (in_array($extension, $extensions, true)) {
            return $type;
        }
    }

    return '';
}

/**
 * Whether a file extension can be previewed inline.
 *
 * @param string $extension
 * @return bool
 */
function eap_is_previewable_file($extension) {
    return '' !== eap_get_file_preview_type($extension);
}

/**
 * Map file extensions to human-friendly categories for filtering.
 *
 * @return array
 */
function eap_get_file_category_map() {
    return [
        'audio' => ['mp3', 'wav', 'm4a', 'aac', 'ogg'],
        'video' => ['mp4', 'mov', 'avi', 'mkv', 'webm'],
        'document' => ['pdf', 'doc', 'docx', 'txt', 'rtf'],
        'powerpoint' => ['ppt', 'pptx', 'pps', 'ppsx', 'key'],
        'spreadsheet' => ['xls', 'xlsx', 'csv', 'ods'],
        'image' => ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg'],
    ];
}

/**
 * Resolve a file extension to a filter category.
 *
 * @param string $extension
 * @return string
 */
function eap_get_file_category($extension) {
    if (empty($extension)) {
        return 'document';
    }

    $extension = strtolower($extension);
    $map = eap_get_file_category_map();

    foreach ($map as $category => $extensions) {
        if (in_array($extension, $extensions, true)) {
            return $category;
        }
    }

    return 'document';
}

/**
 * Available filter options for the workgroup file search UI.
 *
 * @return array
 */
function eap_get_workgroup_file_type_options() {
    return [
        'all' => __('All types', 'llw-eap-member-portal'),
        'audio' => __('Audio', 'llw-eap-member-portal'),
        'video' => __('Video', 'llw-eap-member-portal'),
        'document' => __('Document', 'llw-eap-member-portal'),
        'powerpoint' => __('PowerPoint', 'llw-eap-member-portal'),
        'spreadsheet' => __('Spreadsheet', 'llw-eap-member-portal'),
        'image' => __('Image', 'llw-eap-member-portal'),
    ];
}

/**
 * Extract unique folder paths from files based on their relative_path.
 * Returns a nested array structure for building hierarchical folder options.
 *
 * @param array $files Array of file data from workgroup.
 * @return array Associative array with folder paths as keys.
 */
function eap_get_workgroup_folder_structure($files) {
    if (!is_array($files) || empty($files)) {
        return [];
    }

    $folders = [];
    
    foreach ($files as $file) {
        if (empty($file['relative_path'])) {
            continue;
        }
        
        // Get the folder path (everything except the filename)
        $path_parts = explode('/', $file['relative_path']);
        array_pop($path_parts); // Remove filename
        
        if (empty($path_parts)) {
            continue;
        }
        
        // Build up folder paths incrementally
        $current_path = '';
        foreach ($path_parts as $part) {
            $current_path = $current_path === '' ? $part : $current_path . '/' . $part;
            if (!isset($folders[$current_path])) {
                $folders[$current_path] = [
                    'path' => $current_path,
                    'name' => $part,
                    'depth' => substr_count($current_path, '/'),
                ];
            }
        }
    }
    
    // Sort by path (alphabetically)
    ksort($folders);
    
    return $folders;
}

/**
 * Get folder filter options formatted for a select dropdown.
 *
 * @param array $files Array of file data from workgroup.
 * @return array Associative array with folder path => display label.
 */
function eap_get_workgroup_folder_options($files) {
    $folders = eap_get_workgroup_folder_structure($files);
    
    $options = [
        'all' => __('All folders', 'llw-eap-member-portal'),
        'root' => __('Root folder only', 'llw-eap-member-portal'),
    ];
    
    foreach ($folders as $path => $folder) {
        // Add indentation based on depth for visual hierarchy
        $indent = str_repeat('— ', $folder['depth']);
        $options[$path] = $indent . $folder['name'];
    }
    
    return $options;
}

/**
 * Check if a folder has subfolders.
 *
 * @param string $folder_path The folder path to check.
 * @param array $all_folders All folder paths from eap_get_workgroup_folder_structure.
 * @return bool True if the folder has subfolders.
 */
function eap_folder_has_subfolders($folder_path, $all_folders) {
    if ($folder_path === 'all' || $folder_path === 'root') {
        // 'all' doesn't need subfolders option, 'root' does if there are any folders
        return $folder_path === 'root' && !empty($all_folders);
    }
    
    foreach ($all_folders as $path => $folder) {
        // Check if this folder is a direct child of the given folder
        if (strpos($path, $folder_path . '/') === 0) {
            return true;
        }
    }
    
    return false;
}

/**
 * Get folder data as JSON for JavaScript.
 *
 * @param array $files Array of file data from workgroup.
 * @return string JSON-encoded folder data.
 */
function eap_get_folder_data_json($files) {
    $folders = eap_get_workgroup_folder_structure($files);
    
    $folder_data = [
        'all' => ['hasSubfolders' => false],
        'root' => ['hasSubfolders' => !empty($folders)],
    ];
    
    foreach ($folders as $path => $folder) {
        $folder_data[$path] = [
            'hasSubfolders' => eap_folder_has_subfolders($path, $folders),
        ];
    }
    
    return wp_json_encode($folder_data);
}

/**
 * Human-friendly status text for the file filter UI.
 *
 * @param int $count
 * @return string
 */
function eap_get_workgroup_file_status_text($count) {
    if ($count <= 0) {
        return __('No files available yet.', 'llw-eap-member-portal');
    }

    return sprintf(
        _n('Showing %d file', 'Showing %d files', $count, 'llw-eap-member-portal'),
        $count
    );
}

/**
 * Render the shared "no files" notice.
 *
 * @param string $message
 * @return string
 */
function eap_render_workgroup_no_files_message($message) {
    return '<div class="eap-no-files">' .
        '<div class="eap-no-files-icon">📭</div>' .
        '<p>' . esc_html($message) . '</p>' .
    '</div>';
}

/**
 * Render secure file cards for a working group.
 *
 * @param int   $post_id
 * @param array $files
 * @return string
 */
function eap_render_workgroup_file_cards($post_id, $files) {
    if (!is_array($files) || empty($files)) {
        return '';
    }

    $output = '';

    foreach ($files as $index => $file) {
        if (empty($file['name'])) {
            continue;
        }

        $download_url = add_query_arg([
            'eap_download' => $post_id,
            'file_index' => $index,
            '_wpnonce' => wp_create_nonce('eap_download_file_' . $post_id . '_' . $index)
        ], home_url());

        $file_ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $icon = '📄';
        if (in_array($file_ext, ['pdf'])) $icon = '📕';
        if (in_array($file_ext, ['doc', 'docx'])) $icon = '📘';
        if (in_array($file_ext, ['xls', 'xlsx'])) $icon = '📊';
        if (in_array($file_ext, ['ppt', 'pptx'])) $icon = '📽️';
        if (in_array($file_ext, ['zip', 'rar', '7z'])) $icon = '📦';
        if (in_array($file_ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'])) $icon = '🖼️';

        $preview_type = eap_get_file_preview_type($file_ext);
        $google_url = eap_get_google_drive_view_url($file);
        $preview_attrs = '';
        $link_href = esc_url($download_url);

        if ($preview_type) {
            $view_url = add_query_arg('eap_inline', '1', $download_url);
            $preview_attrs = sprintf(
                ' data-preview-url="%s" data-preview-type="%s" data-file-name="%s" data-download-url="%s" data-google-url="%s"',
                esc_url($view_url),
                esc_attr($preview_type),
                esc_attr($file['name']),
                esc_url($download_url),
                esc_url($google_url)
            );
            $link_href = '#_';
        }

        $category = eap_get_file_category($file_ext);
        
        // For unrecognized file types without a preview, open download in new tab
        $target_attr = $preview_type ? '' : ' target="_blank" rel="noopener noreferrer"';

        $output .= '<div class="eap-file-card" data-file-type="' . esc_attr($category) . '">';
        $output .= '<div class="eap-file-icon-large">' . $icon . '</div>';
        $output .= '<div class="eap-file-name">' . esc_html($file['name']) . '</div>';
        $output .= '<a href="' . $link_href . '" class="eap-file-download-btn"' . $preview_attrs . $target_attr . '>View</a>';
        $output .= '</div>';
    }

    if ($output === '') {
        return '';
    }

    return $output;
}

/**
 * Build the Google Drive web URL for a synced file, if available.
 *
 * @param array $file
 * @return string
 */
function eap_get_google_drive_view_url($file) {
    if (!is_array($file) || empty($file['drive_id'])) {
        return '';
    }

    $drive_id = preg_replace('/[^a-zA-Z0-9_-]/', '', (string) $file['drive_id']);

    if ('' === $drive_id) {
        return '';
    }

    return esc_url_raw(sprintf(
        'https://drive.google.com/file/d/%s/view',
        rawurlencode($drive_id)
    ));
}

/**
 * Get the mime type for a secure file.
 *
 * @param string $file_path
 * @param string $default
 * @return string
 */
function eap_get_file_mime_type($file_path, $default = 'application/octet-stream') {
    if (!file_exists($file_path)) {
        return $default;
    }

    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo) {
            $mime = finfo_file($finfo, $file_path);
            finfo_close($finfo);
            if ($mime) {
                return $mime;
            }
        }
    }

    $filetype = wp_check_filetype(basename($file_path));
    if (!empty($filetype['type'])) {
        return $filetype['type'];
    }

    return $default;
}


// === 10. SECURE FILE DOWNLOAD HANDLER & CPT CONTENT ===

function eap_file_download_handler() {
    // Handle discussion attachment downloads (requires EAP Discussions addon)
    // This handler works when addon is active, providing backward compatibility for existing discussion files
    if ( isset($_GET['eap_discussion_download']) && isset($_GET['_wpnonce']) && function_exists('eap_get_discussion') ) {
        $discussion_id = intval($_GET['eap_discussion_download']);
        $nonce = $_GET['_wpnonce'];
        
        if ( !wp_verify_nonce($nonce, 'eap_download_discussion_file_' . $discussion_id) ) {
            eap_log_event(
                'Failed discussion file download attempt - invalid nonce',
                ['discussion_id' => $discussion_id],
                'security'
            );
            wp_die('Invalid request (nonce failure).', 'Access Denied', 403);
        }

        if ( !eap_is_portal_member() ) {
            eap_log_event(
                'Unauthorized discussion file download attempt',
                ['discussion_id' => $discussion_id],
                'security'
            );
            wp_die('You do not have permission to access this file. Please log in.', 'Access Denied', 403);
        }
        
        // Get discussion (function provided by EAP Discussions addon)
        $discussion = eap_get_discussion($discussion_id);
        
        if (!$discussion) {
            wp_die('File not found.', 'Error', 404);
        }
        
        $file_path = eap_get_discussion_attachment_path($discussion);
        $file_name = !empty($discussion->attachment_name) ? $discussion->attachment_name : '';
        
        if ( !$file_path || !file_exists($file_path) ) {
            wp_die('File not found.', 'Error', 404);
        }
        
        if (empty($file_name)) {
            $file_name = basename($file_path);
        }
        
        $serve_inline = isset($_GET['eap_inline']) && $_GET['eap_inline'] === '1';
        $mime_type = eap_get_file_mime_type($file_path);
        $action_label = $serve_inline ? 'previewed' : 'downloaded';

        eap_log_event(
            sprintf('User %s discussion file "%s" (Discussion ID: %d)', $action_label, $file_name, $discussion_id),
            [
                'discussion_id' => $discussion_id,
                'post_id' => $discussion->post_id,
                'post_type' => $discussion->post_type,
                'file_name' => $file_name
            ],
            'file'
        );
        
        if ($serve_inline) {
            header('Content-Type: ' . $mime_type);
            header('Content-Disposition: inline; filename="' . basename($file_name) . '"');
            // Use no-cache for inline previews to prevent stale content issues
            header('Cache-Control: no-cache, no-store, must-revalidate');
            header('Pragma: no-cache');
            header('Expires: 0');
        } else {
            header('Content-Description: File Transfer');
            header('Content-Type: ' . $mime_type);
            header('Content-Disposition: attachment; filename="' . basename($file_name) . '"');
            header('Cache-Control: private, max-age=3600');
            header('Pragma: public');
        }
        header('Content-Length: ' . filesize($file_path));
        
        // Add ETag based on file modification time and size for proper cache validation
        $etag = '"' . md5($file_path . filemtime($file_path) . filesize($file_path)) . '"';
        header('ETag: ' . $etag);
        
        if (ob_get_level()) {
            ob_end_clean();
        }
        readfile($file_path);
        exit;
    }
    
    // Handle regular post attachment downloads
    if ( isset($_GET['eap_download']) && isset($_GET['_wpnonce']) ) {
        
        $post_id = intval($_GET['eap_download']);
        $file_index = isset($_GET['file_index']) ? intval($_GET['file_index']) : 0;
        $nonce = $_GET['_wpnonce'];
        
        if ( !wp_verify_nonce($nonce, 'eap_download_file_' . $post_id . '_' . $file_index) ) {
            eap_log_event(
                'Failed file download attempt - invalid nonce',
                ['post_id' => $post_id, 'file_index' => $file_index],
                'security'
            );
            wp_die('Invalid request (nonce failure).', 'Access Denied', 403);
        }

        if ( !eap_is_portal_member() ) {
            eap_log_event(
                'Unauthorized file download attempt',
                ['post_id' => $post_id, 'file_index' => $file_index],
                'security'
            );
            wp_die('You do not have permission to access this file. Please log in.', 'Access Denied', 403);
        }
        
        // Get files array
        $files = get_post_meta($post_id, '_eap_secure_files', true);
        
        if (!is_array($files) || !isset($files[$file_index])) {
            wp_die('File not found.', 'Error', 404);
        }
        
        $file_data = $files[$file_index];
        $file_path = $file_data['path'];
        $file_name = $file_data['name'];
        
        if ( !file_exists($file_path) ) {
            wp_die('File not found.', 'Error', 404);
        }
        
        $serve_inline = isset($_GET['eap_inline']) && $_GET['eap_inline'] === '1';
        $mime_type = eap_get_file_mime_type($file_path);
        
        $post = get_post($post_id);
        $action_label = $serve_inline ? 'previewed' : 'downloaded';

        eap_log_event(
            sprintf('User %s file "%s" from "%s" (ID: %d)', $action_label, $file_name, $post->post_title, $post_id),
            [
                'post_id' => $post_id,
                'post_type' => $post->post_type,
                'post_title' => $post->post_title,
                'file_name' => $file_name,
                'file_index' => $file_index
            ],
            'file'
        );
        
        if ($serve_inline) {
            header('Content-Type: ' . $mime_type);
            header('Content-Disposition: inline; filename="' . basename($file_name) . '"');
            // Use no-cache for inline previews to prevent stale content issues
            header('Cache-Control: no-cache, no-store, must-revalidate');
            header('Pragma: no-cache');
            header('Expires: 0');
        } else {
            header('Content-Description: File Transfer');
            header('Content-Type: ' . $mime_type);
            header('Content-Disposition: attachment; filename="' . basename($file_name) . '"');
            header('Expires: 0');
            header('Cache-Control: private, max-age=3600, must-revalidate');
            header('Pragma: public');
        }
        header('Content-Length: ' . filesize($file_path));
        
        // Add ETag based on file modification time and size for proper cache validation
        $etag = '"' . md5($file_path . filemtime($file_path) . filesize($file_path)) . '"';
        header('ETag: ' . $etag);
        
        if (ob_get_level()) {
            ob_end_clean();
        }
        
        readfile($file_path);
        exit;
    }
}
add_action('init', 'eap_file_download_handler');

/**
 * Resolve a secure file reference from a signed download URL.
 *
 * @param string $download_url
 * @return array|\WP_Error
 */
function eap_resolve_secure_file_reference($download_url) {
    if (empty($download_url)) {
        return new \WP_Error('invalid_request', __('Invalid file reference supplied.', 'llw-eap-member-portal'));
    }

    $parsed = wp_parse_url($download_url);
    if (!$parsed) {
        return new \WP_Error('invalid_request', __('Unable to parse file reference.', 'llw-eap-member-portal'));
    }

    $site_host = wp_parse_url(home_url(), PHP_URL_HOST);
    if (!empty($parsed['host']) && $site_host && !hash_equals($site_host, $parsed['host'])) {
        return new \WP_Error('invalid_host', __('File reference does not belong to this site.', 'llw-eap-member-portal'));
    }

    $query_vars = [];
    if (!empty($parsed['query'])) {
        parse_str($parsed['query'], $query_vars);
    }

    if (isset($query_vars['eap_download'])) {
        $post_id = intval($query_vars['eap_download']);
        $file_index = isset($query_vars['file_index']) ? intval($query_vars['file_index']) : 0;
        $nonce = isset($query_vars['_wpnonce']) ? $query_vars['_wpnonce'] : '';

        if (!$post_id || !wp_verify_nonce($nonce, 'eap_download_file_' . $post_id . '_' . $file_index)) {
            return new \WP_Error('invalid_nonce', __('Your session has expired. Please refresh and try again.', 'llw-eap-member-portal'));
        }

        $files = get_post_meta($post_id, '_eap_secure_files', true);
        if (!is_array($files) || !isset($files[$file_index])) {
            return new \WP_Error('file_missing', __('File not found for preview.', 'llw-eap-member-portal'));
        }

        $file = $files[$file_index];
        if (empty($file['path']) || !file_exists($file['path'])) {
            return new \WP_Error('file_missing', __('File is no longer available.', 'llw-eap-member-portal'));
        }

        return [
            'context' => 'workgroup',
            'path'    => $file['path'],
            'name'    => $file['name'],
            'post_id' => $post_id,
            'file_index' => $file_index,
        ];
    }

    // Discussion downloads require EAP Discussions addon to be active
    if (isset($query_vars['eap_discussion_download']) && function_exists('eap_get_discussion')) {
        $discussion_id = intval($query_vars['eap_discussion_download']);
        $nonce = isset($query_vars['_wpnonce']) ? $query_vars['_wpnonce'] : '';

        if (!$discussion_id || !wp_verify_nonce($nonce, 'eap_download_discussion_file_' . $discussion_id)) {
            return new \WP_Error('invalid_nonce', __('Your session has expired. Please refresh and try again.', 'llw-eap-member-portal'));
        }

        $discussion = eap_get_discussion($discussion_id);
        if (!$discussion) {
            return new \WP_Error('file_missing', __('Discussion attachment not found.', 'llw-eap-member-portal'));
        }

        $file_path = eap_get_discussion_attachment_path($discussion);
        if (!$file_path || !file_exists($file_path)) {
            return new \WP_Error('file_missing', __('Attachment is no longer available.', 'llw-eap-member-portal'));
        }

        $file_name = !empty($discussion->attachment_name) ? $discussion->attachment_name : basename($file_path);

        return [
            'context' => 'discussion',
            'path'    => $file_path,
            'name'    => $file_name,
            'discussion_id' => $discussion_id,
        ];
    }

    return new \WP_Error('invalid_request', __('Unsupported file reference.', 'llw-eap-member-portal'));
}

/**
 * AJAX: Render spreadsheet preview HTML using PhpSpreadsheet.
 */
function eap_ajax_preview_spreadsheet() {
    // Set no-cache headers for AJAX response
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');
    
    check_ajax_referer('eap_preview_spreadsheet', 'nonce');

    if (!is_user_logged_in() || !eap_is_portal_member()) {
        wp_send_json_error([
            'message' => __('You must be logged in to preview this file.', 'llw-eap-member-portal'),
            'code' => 'not_logged_in',
        ], 403);
    }

    $download_url = isset($_POST['download_url']) ? esc_url_raw(wp_unslash($_POST['download_url'])) : '';
    if (!$download_url) {
        wp_send_json_error([
            'message' => __('Invalid file reference supplied.', 'llw-eap-member-portal'),
            'code' => 'invalid_reference',
        ], 400);
    }

    $file_ref = eap_resolve_secure_file_reference($download_url);
    if (is_wp_error($file_ref)) {
        wp_send_json_error([
            'message' => $file_ref->get_error_message(),
            'code' => $file_ref->get_error_code(),
        ], 400);
    }

    $extension = strtolower(pathinfo($file_ref['path'], PATHINFO_EXTENSION));
    $allowed_extensions = ['xls', 'xlsx', 'xlsm', 'xltx', 'xltm'];
    if (!in_array($extension, $allowed_extensions, true)) {
        wp_send_json_error([
            'message' => __('This file type cannot be previewed.', 'llw-eap-member-portal'),
            'code' => 'invalid_file_type',
        ], 400);
    }

    if (!class_exists('\PhpOffice\PhpSpreadsheet\IOFactory')) {
        // Return a specific error code so the client knows to use the fallback
        wp_send_json_error([
            'message' => __('Server-side Excel preview is not available. Using client-side preview.', 'llw-eap-member-portal'),
            'code' => 'library_not_available',
            'fallback' => true,
        ], 500);
    }

    $preview = eap_build_spreadsheet_preview_html($file_ref['path'], [
        'max_rows' => 200,
        'max_cols' => 60,
    ]);

    if (is_wp_error($preview)) {
        wp_send_json_error([
            'message' => $preview->get_error_message(),
            'code' => $preview->get_error_code(),
        ], 500);
    }

    wp_send_json_success([
        'html' => $preview['html'],
        'sheetCount' => $preview['sheet_count'],
        'fileName' => $file_ref['name'],
        'limits' => $preview['limits'],
        'filePath' => basename($file_ref['path']), // For debugging
    ]);
}
add_action('wp_ajax_eap_preview_spreadsheet', 'eap_ajax_preview_spreadsheet');

/**
 * Build spreadsheet preview HTML limited to a subset of rows/columns.
 *
 * @param string $file_path
 * @param array  $args
 * @return array|\WP_Error
 */
function eap_build_spreadsheet_preview_html($file_path, $args = []) {
    if (!file_exists($file_path) || !is_readable($file_path)) {
        return new \WP_Error('file_missing', __('File is no longer available for preview.', 'llw-eap-member-portal'));
    }

    $defaults = [
        'max_rows' => 200,
        'max_cols' => 60,
    ];
    $args = wp_parse_args($args, $defaults);

    try {
        $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($file_path);
        $reader->setReadDataOnly(true);
        $spreadsheet = $reader->load($file_path);
    } catch (\Throwable $e) {
        return new \WP_Error('preview_failed', __('Unable to open this spreadsheet for preview.', 'llw-eap-member-portal'));
    }

    $sheet_names = $spreadsheet->getSheetNames();
    if (empty($sheet_names)) {
        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet);

        return [
            'html' => '<p class="eap-xlsx-empty">' . esc_html__('This spreadsheet appears to be empty.', 'llw-eap-member-portal') . '</p>',
            'sheet_count' => 0,
            'limits' => [
                'rows' => (int) $args['max_rows'],
                'cols' => (int) $args['max_cols'],
            ],
        ];
    }

    $html = ['<div class="eap-xlsx-viewer">'];

    if (count($sheet_names) > 1) {
        $html[] = '<div class="eap-xlsx-tabs" role="tablist">';
        foreach ($sheet_names as $index => $sheet_name) {
            $is_active = $index === 0 ? ' is-active' : '';
            $aria_selected = $index === 0 ? 'true' : 'false';
            $html[] = sprintf(
                '<button id="tab-%2$d" class="eap-xlsx-tab%1$s" data-sheet="%3$s" role="tab" aria-selected="%4$s" aria-controls="sheet-%2$d">%5$s</button>',
                $is_active,
                $index,
                esc_attr($sheet_name),
                esc_attr($aria_selected),
                esc_html($sheet_name)
            );
        }
        $html[] = '</div>';
    }

    $html[] = '<div class="eap-xlsx-sheets">';

    foreach ($sheet_names as $index => $sheet_name) {
        $sheet = $spreadsheet->getSheet($index);
        $sheet_html = eap_render_spreadsheet_sheet_html($sheet, (int) $args['max_rows'], (int) $args['max_cols']);
        $is_visible = $index === 0 ? ' is-visible' : '';
        $html[] = sprintf(
            '<div class="eap-xlsx-sheet%s" id="sheet-%d" data-sheet="%s" data-sheet-index="%d" role="tabpanel" aria-labelledby="tab-%d"><div class="eap-xlsx-scroll">%s</div></div>',
            $is_visible,
            $index,
            esc_attr($sheet_name),
            $index,
            $index,
            $sheet_html
        );
    }

    $html[] = '</div>';
    $html[] = '</div>';

    $spreadsheet->disconnectWorksheets();
    unset($spreadsheet);

    return [
        'html' => implode('', $html),
        'sheet_count' => count($sheet_names),
        'limits' => [
            'rows' => (int) $args['max_rows'],
            'cols' => (int) $args['max_cols'],
        ],
    ];
}

/**
 * Render a worksheet segment as HTML table with truncation notes.
 *
 * @param \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet
 * @param int $row_limit
 * @param int $col_limit
 * @return string
 */
function eap_render_spreadsheet_sheet_html($sheet, $row_limit, $col_limit) {
    $row_limit = max(10, (int) $row_limit);
    $col_limit = max(5, (int) $col_limit);

    $dimension = strtoupper($sheet->calculateWorksheetDimension());
    if (!$dimension) {
        $dimension = 'A1:A1';
    }

    if (strpos($dimension, ':') === false) {
        $dimension = $dimension . ':' . $dimension;
    }

    list($start_cell, $end_cell) = explode(':', $dimension);
    list($start_col_letter, $start_row) = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::coordinateFromString($start_cell);
    list($end_col_letter, $end_row) = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::coordinateFromString($end_cell);

    $start_row = max(1, (int) $start_row);
    $end_row = max($start_row, (int) $end_row);
    $start_col_index = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($start_col_letter);
    $end_col_index = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($end_col_letter);

    // Trim leading empty rows within a reasonable window to surface content faster.
    $max_scan_rows = min($row_limit * 2, $end_row - $start_row + 1);
    $trimmed_row = null;
    for ($row = $start_row; $row <= $end_row && $row < $start_row + $max_scan_rows; $row++) {
        if (eap_sheet_row_has_visible_data($sheet, $row, $start_col_index, $end_col_index)) {
            $trimmed_row = $row;
            break;
        }
    }
    if (null !== $trimmed_row) {
        $start_row = $trimmed_row;
    }

    $display_end_row = min($end_row, $start_row + $row_limit - 1);
    $display_end_col_index = min($end_col_index, $start_col_index + $col_limit - 1);

    $has_visible_content = false;
    $table = ['<table class="eap-xlsx-table">'];

    for ($row = $start_row; $row <= $display_end_row; $row++) {
        $table[] = '<tr>';
        for ($col = $start_col_index; $col <= $display_end_col_index; $col++) {
            $cell = $sheet->getCellByColumnAndRow($col, $row);
            $value = eap_sheet_cell_display_value($cell);
            if ($value !== '') {
                $has_visible_content = true;
            }
            $tag = ($row === $start_row) ? 'th' : 'td';
            $table[] = '<' . $tag . '>' . esc_html($value) . '</' . $tag . '>';
        }
        $table[] = '</tr>';
    }

    $table[] = '</table>';

    if (!$has_visible_content) {
        return '<p class="eap-xlsx-empty">' . esc_html__('This sheet contains no previewable values. Download the file to view its full contents.', 'llw-eap-member-portal') . '</p>';
    }

    $html = implode('', $table);

    if ($display_end_row < $end_row || $display_end_col_index < $end_col_index) {
        $notes = [];
        if ($display_end_row < $end_row) {
            $notes[] = sprintf(
                esc_html__('first %d rows', 'llw-eap-member-portal'),
                max(1, $display_end_row - $start_row + 1)
            );
        }
        if ($display_end_col_index < $end_col_index) {
            $notes[] = sprintf(
                esc_html__('first %d columns', 'llw-eap-member-portal'),
                max(1, $display_end_col_index - $start_col_index + 1)
            );
        }

        if (!empty($notes)) {
            $note_text = implode(' ' . esc_html__('and', 'llw-eap-member-portal') . ' ', $notes);
            $html .= '<p class="eap-xlsx-note">' . sprintf(
                esc_html__('Showing %s. Download to view all data.', 'llw-eap-member-portal'),
                esc_html($note_text)
            ) . '</p>';
        }
    }

    return $html;
}

/**
 * Determine whether a worksheet row has any visible data.
 *
 * @param \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet
 * @param int $row_index
 * @param int $start_col
 * @param int $end_col
 * @return bool
 */
function eap_sheet_row_has_visible_data($sheet, $row_index, $start_col, $end_col) {
    for ($col = $start_col; $col <= $end_col; $col++) {
        $cell = $sheet->getCellByColumnAndRow($col, $row_index);
        if (eap_sheet_cell_display_value($cell) !== '') {
            return true;
        }
    }
    return false;
}

/**
 * Normalize a cell's display value for preview output.
 *
 * @param \PhpOffice\PhpSpreadsheet\Cell\Cell|null $cell
 * @return string
 */
function eap_sheet_cell_display_value($cell) {
    if (!$cell) {
        return '';
    }

    $value = $cell->getFormattedValue();

    if (is_bool($value)) {
        return $value ? 'TRUE' : 'FALSE';
    }

    if (is_numeric($value) || is_string($value)) {
        return trim((string) $value);
    }

    if ($value instanceof \DateTimeInterface) {
        return $value->format('Y-m-d H:i:s');
    }

    if ($value === null) {
        return '';
    }

    return trim((string) $value);
}

// Protect CPT content and add download links
function eap_protect_cpt_content($content) {
    if ( is_singular('eap_working_group') && in_the_loop() && is_main_query() ) {
        
        if ( !eap_is_portal_member() ) {
            return '<p>You must be a logged-in member to view this content. Please ' . wp_loginout( get_permalink(), false ) . '</p>';
        }

        $post_id = get_the_ID();
        
        // Migrate old single-file format if needed
        eap_migrate_single_file_to_multi($post_id);
        
        $files = get_post_meta($post_id, '_eap_secure_files', true);
        
        if (is_array($files) && !empty($files)) {
            $content .= '<div class="eap-download-box">';
            $content .= '<h4>Attached Files:</h4>';
            $content .= '<ul class="eap-file-list" style="list-style: none; padding: 0;">';
            
            foreach ($files as $index => $file) {
                $download_url = add_query_arg([
                    'eap_download' => $post_id,
                    'file_index' => $index,
                    '_wpnonce' => wp_create_nonce('eap_download_file_' . $post_id . '_' . $index)
                ], home_url());
                
                // Determine file icon based on extension
                $file_ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                $icon = '📄'; // Default document icon
                if (in_array($file_ext, ['pdf'])) $icon = '📕';
                if (in_array($file_ext, ['doc', 'docx'])) $icon = '📘';
                if (in_array($file_ext, ['xls', 'xlsx'])) $icon = '📊';
                if (in_array($file_ext, ['ppt', 'pptx'])) $icon = '📽️';
                if (in_array($file_ext, ['zip', 'rar', '7z'])) $icon = '📦';
                if (in_array($file_ext, ['jpg', 'jpeg', 'png', 'gif'])) $icon = '🖼️';
                
                $preview_type = eap_get_file_preview_type($file_ext);
                $google_url = eap_get_google_drive_view_url($file);
                $preview_attrs = '';
                $link_href = esc_url($download_url); // Default

                if ($preview_type) {
                    $view_url = add_query_arg('eap_inline', '1', $download_url);
                    $preview_attrs = sprintf(
                        ' data-preview-url="%s" data-preview-type="%s" data-file-name="%s" data-download-url="%s" data-google-url="%s"',
                        esc_url($view_url),
                        esc_attr($preview_type),
                        esc_attr($file['name']),
                        esc_url($download_url),
                        esc_url($google_url)
                    );
                    $link_href = '#_'; // Change this
                }
                
                // For unrecognized file types without a preview, open download in new tab
                $target_attr = $preview_type ? '' : ' target="_blank" rel="noopener noreferrer"';

                $content .= '<li style="margin-bottom: 10px;">';
                $content .= '<a href="' . $link_href . '" class="eap-download-button" style="text-decoration: none;"' . $preview_attrs . $target_attr . '>'; // Use $link_href
                $content .= '<span style="margin-right: 5px;">' . $icon . '</span>';
            }
            
            $content .= '</ul>';
            $content .= '</div>';
        }
        
    }
    
    return $content;
}
add_filter('the_content', 'eap_protect_cpt_content');

/**
 * AJAX handler for filtering working group files.
 */
function eap_ajax_filter_workgroup_files() {
    check_ajax_referer('eap_filter_wg_files', 'nonce');

    if (!is_user_logged_in() || !eap_is_portal_member()) {
        wp_send_json_error([
            'message' => __('You must be logged in to filter files.', 'llw-eap-member-portal'),
        ], 403);
    }

    $post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;

    if (!$post_id || get_post_type($post_id) !== 'eap_working_group') {
        wp_send_json_error([
            'message' => __('Invalid working group.', 'llw-eap-member-portal'),
        ], 400);
    }

    $search = isset($_POST['search']) ? sanitize_text_field(wp_unslash($_POST['search'])) : '';
    $file_type = isset($_POST['file_type']) ? sanitize_key($_POST['file_type']) : 'all';
    $folder = isset($_POST['folder']) ? sanitize_text_field(wp_unslash($_POST['folder'])) : 'all';
    $include_subfolders = isset($_POST['include_subfolders']) && $_POST['include_subfolders'] === 'true';

    eap_migrate_single_file_to_multi($post_id);
    $files = get_post_meta($post_id, '_eap_secure_files', true);
    if (!is_array($files)) {
        $files = [];
    }

    $filtered = $files;

    // Filter by folder
    if ($folder !== 'all') {
        $filtered = array_filter($filtered, function($file) use ($folder, $include_subfolders) {
            $relative_path = isset($file['relative_path']) ? $file['relative_path'] : '';
            
            if ($folder === 'root') {
                // Root folder: files without any folder prefix (no slash in path except for filename)
                if (empty($relative_path)) {
                    return true; // Files without relative_path are in root
                }
                // Check if the file is directly in root (no directory separator before filename)
                $path_parts = explode('/', $relative_path);
                if (count($path_parts) === 1) {
                    return true; // File is in root
                }
                // If include_subfolders is checked for root, include all files
                return $include_subfolders;
            }
            
            if (empty($relative_path)) {
                return false; // No path means file is in root, not in a subfolder
            }
            
            // Get the folder part of the path (everything except the filename)
            $path_parts = explode('/', $relative_path);
            array_pop($path_parts); // Remove filename
            $file_folder = implode('/', $path_parts);
            
            if ($include_subfolders) {
                // Match exact folder or any subfolder
                return $file_folder === $folder || strpos($file_folder, $folder . '/') === 0;
            } else {
                // Match exact folder only
                return $file_folder === $folder;
            }
        });
    }

    if ($search !== '') {
        $search_lower = strtolower($search);
        $filtered = array_filter($filtered, function($file) use ($search_lower) {
            $name = isset($file['name']) ? strtolower($file['name']) : '';
            return strpos($name, $search_lower) !== false;
        });
    }

    $type_options = array_keys(eap_get_workgroup_file_type_options());
    if ($file_type && $file_type !== 'all' && in_array($file_type, $type_options, true)) {
        $filtered = array_filter($filtered, function($file) use ($file_type) {
            $extension = isset($file['name']) ? pathinfo($file['name'], PATHINFO_EXTENSION) : '';
            return eap_get_file_category($extension) === $file_type;
        });
    }

    $count = count($filtered);

    if ($count === 0) {
        $html = eap_render_workgroup_no_files_message(__('No files match your filters.', 'llw-eap-member-portal'));
    } else {
        $html = eap_render_workgroup_file_cards($post_id, $filtered);
    }

    $status_text = $count === 0
        ? __('No files match your filters.', 'llw-eap-member-portal')
        : eap_get_workgroup_file_status_text($count);

    wp_send_json_success([
        'html' => $html,
        'count' => $count,
        'statusText' => $status_text,
    ]);
}
add_action('wp_ajax_eap_filter_workgroup_files', 'eap_ajax_filter_workgroup_files');


// === 11. "BELLS & WHISTLES" SHORTCODES ===

// [eap_member_content]...[/eap_member_content]
function eap_member_content_shortcode($atts, $content = null) {
    if ( eap_is_portal_member() && !is_null($content) ) {
        return do_shortcode($content);
    } else {
        return '<div class="eap-restricted-notice"><p>This content is available to portal members only. Please log in.</p></div>';
    }
}
add_shortcode('eap_member_content', 'eap_member_content_shortcode');

/**
 * Redirect target after successful secure login.
 */
function eap_secure_login_redirect_url() {
    return home_url('/delegates-directory/');
}

/**
 * Normalize user identifier to a username when possible.
 */
function eap_secure_login_normalize_username($identifier) {
    $identifier = trim($identifier);
    if ('' === $identifier) {
        return '';
    }

    if (is_email($identifier)) {
        $user = get_user_by('email', $identifier);
        if ($user) {
            return $user->user_login;
        }
    }

    return $identifier;
}

/**
 * Find a user by login or email.
 */
function eap_secure_login_find_user($identifier) {
    $identifier = trim($identifier);
    if ('' === $identifier) {
        return null;
    }

    if (is_email($identifier)) {
        $user = get_user_by('email', $identifier);
        if ($user) {
            return $user;
        }
    }

    return get_user_by('login', $identifier);
}

/**
 * Rate limiting helpers.
 */
function eap_secure_login_rate_limit_key($identifier, $context = 'login') {
    $identifier = strtolower(trim($identifier) ?: 'anonymous');
    $ip = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : 'unknown';
    return 'eap_login_' . $context . '_' . md5($identifier . '|' . $ip);
}

function eap_secure_login_is_rate_limited($identifier, $context = 'login') {
    $key = eap_secure_login_rate_limit_key($identifier, $context);
    $attempts = get_transient($key);
    if (!is_array($attempts)) {
        return false;
    }

    $threshold = time() - HOUR_IN_SECONDS;
    $attempts = array_filter($attempts, function($timestamp) use ($threshold) {
        return $timestamp >= $threshold;
    });
    set_transient($key, $attempts, HOUR_IN_SECONDS);

    return count($attempts) >= 3;
}

function eap_secure_login_register_failure($identifier, $context = 'login') {
    $key = eap_secure_login_rate_limit_key($identifier, $context);
    $attempts = get_transient($key);
    if (!is_array($attempts)) {
        $attempts = [];
    }
    $threshold = time() - HOUR_IN_SECONDS;
    $attempts = array_filter($attempts, function($timestamp) use ($threshold) {
        return $timestamp >= $threshold;
    });
    $attempts[] = time();
    set_transient($key, $attempts, HOUR_IN_SECONDS);
}

function eap_secure_login_reset_failures($identifier, $context = 'login') {
    delete_transient(eap_secure_login_rate_limit_key($identifier, $context));
}

/**
 * Magic code utilities.
 */
function eap_secure_login_issue_magic_code($user_id) {
    if (!$user_id) {
        return new WP_Error('invalid_user', 'Unable to find that account.');
    }

    $code = wp_rand(100000, 999999);
    $payload = [
        'hash'    => wp_hash_password((string) $code),
        'expires' => time() + (15 * MINUTE_IN_SECONDS),
        'attempts'=> 0,
    ];

    update_user_meta($user_id, 'eap_login_magic_code', $payload);

    return $code;
}

function eap_secure_login_validate_magic_code($user_id, $code) {
    $code = trim($code);
    if (!preg_match('/^\d{6}$/', $code)) {
        return new WP_Error('invalid_code', 'Please enter the 6-digit code that was emailed to you.');
    }

    $record = get_user_meta($user_id, 'eap_login_magic_code', true);
    if (!is_array($record) || empty($record['hash'])) {
        return new WP_Error('code_missing', 'No valid login code was found. Please request a new one.');
    }

    if (!empty($record['expires']) && time() > (int) $record['expires']) {
        delete_user_meta($user_id, 'eap_login_magic_code');
        return new WP_Error('code_expired', 'That login code has expired. Please request a new one.');
    }

    $attempts = isset($record['attempts']) ? (int) $record['attempts'] : 0;
    if ($attempts >= 5) {
        delete_user_meta($user_id, 'eap_login_magic_code');
        return new WP_Error('code_locked', 'Too many incorrect code attempts. Please request a new code.');
    }

    if (!wp_check_password($code, $record['hash'])) {
        $record['attempts'] = $attempts + 1;
        update_user_meta($user_id, 'eap_login_magic_code', $record);
        return new WP_Error('code_invalid', 'That login code was not recognized. Please try again.');
    }

    delete_user_meta($user_id, 'eap_login_magic_code');
    return true;
}

/**
 * Two-factor authentication helpers.
 */
function eap_generate_totp_secret($length = 16) {
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $secret = '';
    $max = strlen($alphabet) - 1;

    for ($i = 0; $i < $length; $i++) {
        $secret .= $alphabet[ wp_rand(0, $max) ];
    }

    return $secret;
}

function eap_totp_base32_decode($secret) {
    $secret = strtoupper(preg_replace('/[^A-Z2-7]/', '', $secret));
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $binary = '';

    $buffer = 0;
    $bitsLeft = 0;

    $length = strlen($secret);
    for ($i = 0; $i < $length; $i++) {
        $current = strpos($alphabet, $secret[$i]);
        if ($current === false) {
            continue;
        }

        $buffer = ($buffer << 5) | $current;
        $bitsLeft += 5;

        if ($bitsLeft >= 8) {
            $bitsLeft -= 8;
            $binary .= chr(($buffer >> $bitsLeft) & 0xFF);
        }
    }

    return $binary;
}

function eap_totp_calculate_code($secret, $timeSlice) {
    $binarySecret = eap_totp_base32_decode($secret);
    if ('' === $binarySecret) {
        return false;
    }

    $time = pack('N*', 0) . pack('N*', $timeSlice);
    $hash = hash_hmac('sha1', $time, $binarySecret, true);
    $offset = ord($hash[19]) & 0x0F;

    $value = (
        ((ord($hash[$offset]) & 0x7F) << 24) |
        ((ord($hash[$offset + 1]) & 0xFF) << 16) |
        ((ord($hash[$offset + 2]) & 0xFF) << 8) |
        (ord($hash[$offset + 3]) & 0xFF)
    );

    $code = $value % 1000000;
    return str_pad((string) $code, 6, '0', STR_PAD_LEFT);
}

function eap_totp_verify_code($secret, $code, $window = 1) {
    $secret = trim($secret);
    $code = trim($code);

    if ('' === $secret) {
        return false;
    }

    if (!preg_match('/^\d{6}$/', $code)) {
        return false;
    }

    $timeSlice = floor(time() / 30);

    for ($i = -$window; $i <= $window; $i++) {
        $calculated = eap_totp_calculate_code($secret, $timeSlice + $i);
        if ($calculated && hash_equals($calculated, $code)) {
            return true;
        }
    }

    return false;
}

function eap_secure_login_validate_custom_totp($user_id, $code) {
    $secret = get_user_meta($user_id, 'eap_totp_secret', true);
    if (!$secret) {
        return true;
    }

    if ('' === trim($code)) {
        return new WP_Error('missing_totp', 'A 2FA code from your authenticator app is required.');
    }

    if (!eap_totp_verify_code($secret, $code)) {
        return new WP_Error('invalid_totp', 'The 2FA code you entered is not correct.');
    }

    return true;
}

function eap_build_totp_otpauth_uri($secret, $user) {
    if (!$secret || !($user instanceof WP_User)) {
        return '';
    }

    $site_label = get_bloginfo('name');
    $label = rawurlencode($site_label . ':' . $user->user_email);
    $issuer = rawurlencode($site_label);

    return sprintf('otpauth://totp/%s?secret=%s&issuer=%s', $label, $secret, $issuer);
}

function eap_get_account_security_state_payload($user) {
    $payload = [
        'totpEnabled'    => false,
        'proposedSecret' => '',
        'otpauth'        => '',
    ];

    if (!($user instanceof WP_User)) {
        return $payload;
    }

    $existing_secret = get_user_meta($user->ID, 'eap_totp_secret', true);
    if ($existing_secret) {
        $payload['totpEnabled'] = true;
        return $payload;
    }

    $secret = eap_generate_totp_secret();
    $payload['proposedSecret'] = $secret;
    $payload['otpauth'] = eap_build_totp_otpauth_uri($secret, $user);

    return $payload;
}

function eap_update_account_security() {
    if (!is_user_logged_in()) {
        wp_send_json_error(['message' => __('You need to be logged in to update security settings.', 'llw-eap-member-portal')], 403);
    }

    $user = wp_get_current_user();
    if (!eap_is_portal_member($user)) {
        wp_send_json_error(['message' => __('Only active members can update these settings.', 'llw-eap-member-portal')], 403);
    }

    $nonce = isset($_POST['securityNonce']) ? sanitize_text_field(wp_unslash($_POST['securityNonce'])) : '';
    if (!$nonce || !wp_verify_nonce($nonce, 'eap_account_security')) {
        wp_send_json_error(['message' => __('Security verification failed. Please refresh the page and try again.', 'llw-eap-member-portal')], 403);
    }

    $action_type = isset($_POST['security_action']) ? sanitize_text_field(wp_unslash($_POST['security_action'])) : '';
    $user_id = $user->ID;
    $message = '';

    if ('password' === $action_type) {
        $current = isset($_POST['current_password']) ? trim(wp_unslash($_POST['current_password'])) : '';
        $new     = isset($_POST['new_password']) ? trim(wp_unslash($_POST['new_password'])) : '';
        $confirm = isset($_POST['confirm_password']) ? trim(wp_unslash($_POST['confirm_password'])) : '';

        if ('' === $new && '' === $confirm) {
            wp_send_json_error(['message' => __('Enter and confirm a new password to continue.', 'llw-eap-member-portal')]);
        }

        if ($new !== $confirm) {
            wp_send_json_error(['message' => __('Your new password entries do not match.', 'llw-eap-member-portal')]);
        }

        if (strlen($new) < 12) {
            wp_send_json_error(['message' => __('Please choose a password with at least 12 characters.', 'llw-eap-member-portal')]);
        }

        if (!wp_check_password($current, $user->user_pass, $user_id)) {
            wp_send_json_error(['message' => __('The current password you entered is incorrect.', 'llw-eap-member-portal')]);
        }

        $updated = wp_update_user([
            'ID'        => $user_id,
            'user_pass' => $new,
        ]);

        if (is_wp_error($updated)) {
            wp_send_json_error(['message' => __('Unable to update your password. Please try again.', 'llw-eap-member-portal')]);
        }

        eap_log_event('Member updated their password via the account security popup.', [], 'security');
        $message = __('Your password has been updated.', 'llw-eap-member-portal');

    } elseif ('enable_totp' === $action_type) {
        $existing_secret = get_user_meta($user_id, 'eap_totp_secret', true);
        if ($existing_secret) {
            wp_send_json_error(['message' => __('Two-factor authentication is already enabled on this account.', 'llw-eap-member-portal')]);
        }

        $secret = isset($_POST['totp_secret']) ? sanitize_text_field(wp_unslash($_POST['totp_secret'])) : '';
        $secret = strtoupper(preg_replace('/[^A-Z2-7]/', '', $secret));
        if (!$secret) {
            wp_send_json_error(['message' => __('Generate a setup key before enabling 2FA.', 'llw-eap-member-portal')]);
        }

        $code = isset($_POST['totp_code']) ? preg_replace('/\s+/', '', sanitize_text_field(wp_unslash($_POST['totp_code']))) : '';
        if (!preg_match('/^\d{6}$/', $code)) {
            wp_send_json_error(['message' => __('Enter the 6-digit code from your authenticator app.', 'llw-eap-member-portal')]);
        }

        if (!eap_totp_verify_code($secret, $code)) {
            wp_send_json_error(['message' => __('That code was not recognized. Please try again.', 'llw-eap-member-portal')]);
        }

        update_user_meta($user_id, 'eap_totp_secret', $secret);
        eap_log_event('Member enabled authenticator 2FA via the account security popup.', [], 'security');
        $message = __('Two-factor authentication is now enabled.', 'llw-eap-member-portal');

    } elseif ('disable_totp' === $action_type) {
        $existing_secret = get_user_meta($user_id, 'eap_totp_secret', true);
        if (!$existing_secret) {
            wp_send_json_error(['message' => __('Two-factor authentication is already disabled.', 'llw-eap-member-portal')]);
        }

        $code = isset($_POST['totp_code']) ? preg_replace('/\s+/', '', sanitize_text_field(wp_unslash($_POST['totp_code']))) : '';
        if (!preg_match('/^\d{6}$/', $code)) {
            wp_send_json_error(['message' => __('Enter the 6-digit code from your authenticator app.', 'llw-eap-member-portal')]);
        }

        if (!eap_totp_verify_code($existing_secret, $code)) {
            wp_send_json_error(['message' => __('That code was not recognized. Please try again.', 'llw-eap-member-portal')]);
        }

        delete_user_meta($user_id, 'eap_totp_secret');
        eap_log_event('Member disabled authenticator 2FA via the account security popup.', [], 'security');
        $message = __('Two-factor authentication has been disabled.', 'llw-eap-member-portal');

    } else {
        wp_send_json_error(['message' => __('Please choose a valid security action.', 'llw-eap-member-portal')]);
    }

    $state = eap_get_account_security_state_payload($user);

    wp_send_json_success([
        'message' => $message,
        'state'   => $state,
    ]);
}
add_action('wp_ajax_eap_update_account_security', 'eap_update_account_security');

function eap_generate_account_totp_secret() {
    if (!is_user_logged_in()) {
        wp_send_json_error(['message' => __('You need to be logged in to generate a setup key.', 'llw-eap-member-portal')], 403);
    }

    $user = wp_get_current_user();
    if (!eap_is_portal_member($user)) {
        wp_send_json_error(['message' => __('Only active members can generate setup keys.', 'llw-eap-member-portal')], 403);
    }

    $nonce = isset($_POST['securityNonce']) ? sanitize_text_field(wp_unslash($_POST['securityNonce'])) : '';
    if (!$nonce || !wp_verify_nonce($nonce, 'eap_account_security')) {
        wp_send_json_error(['message' => __('Security verification failed. Refresh and try again.', 'llw-eap-member-portal')], 403);
    }

    $existing_secret = get_user_meta($user->ID, 'eap_totp_secret', true);
    if ($existing_secret) {
        wp_send_json_error(['message' => __('Disable 2FA before generating a new setup key.', 'llw-eap-member-portal')]);
    }

    $state = eap_get_account_security_state_payload($user);

    wp_send_json_success([
        'message' => __('New setup key generated. Scan the QR code or enter the key to continue.', 'llw-eap-member-portal'),
        'secret'  => $state['proposedSecret'],
        'otpauth' => $state['otpauth'],
    ]);
}
add_action('wp_ajax_eap_generate_account_totp_secret', 'eap_generate_account_totp_secret');

/**
 * Complete a login by setting the current user and cookies.
 */
function eap_secure_login_finalize($user, $remember = false) {
    if (!($user instanceof WP_User)) {
        return;
    }

    wp_set_current_user($user->ID);
    wp_set_auth_cookie($user->ID, $remember);
    do_action('wp_login', $user->user_login, $user);
}

/**
 * Process shortcode requests (once per request).
 */
function eap_secure_login_process_request() {
    static $messages = null;

    if (!is_null($messages)) {
        return $messages;
    }

    $messages = [
        'errors'  => [],
        'success' => [],
    ];

    $method = isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET';
    if ('POST' !== $method || empty($_POST['eap_secure_login_action'])) {
        return $messages;
    }

    $nonce = isset($_POST['eap_secure_login_nonce']) ? sanitize_text_field(wp_unslash($_POST['eap_secure_login_nonce'])) : '';
    if (!wp_verify_nonce($nonce, 'eap_secure_login')) {
        $messages['errors'][] = 'Security validation failed. Please refresh and try again.';
        return $messages;
    }

    $action = sanitize_text_field(wp_unslash($_POST['eap_secure_login_action']));

    switch ($action) {
        case 'password_login':
            $result = eap_secure_login_attempt_password();
            break;
        case 'request_code':
            $result = eap_secure_login_handle_code_request();
            break;
        case 'code_login':
            $result = eap_secure_login_attempt_code_login();
            break;
        case 'forgot_password':
            $result = eap_secure_login_handle_forgot_password();
            break;
        default:
            $result = new WP_Error('invalid_action', 'Unknown request.');
            break;
    }

    if (is_wp_error($result)) {
        $messages['errors'][] = $result->get_error_message();
    } elseif (is_array($result) && isset($result['success'], $result['message'])) {
        $bucket = $result['success'] ? 'success' : 'errors';
        $messages[$bucket][] = $result['message'];
    }

    return $messages;
}

function eap_secure_login_attempt_password() {
    $identifier = isset($_POST['login_identifier']) ? sanitize_text_field(wp_unslash($_POST['login_identifier'])) : '';
    $password = isset($_POST['login_password']) ? wp_unslash($_POST['login_password']) : '';
    $two_factor = isset($_POST['two_factor_code']) ? sanitize_text_field(wp_unslash($_POST['two_factor_code'])) : '';
    $remember = !empty($_POST['remember_me']);

    if ('' === $identifier || '' === $password) {
        return new WP_Error('missing_fields', 'Please provide both your email (or username) and password.');
    }

    if (eap_secure_login_is_rate_limited($identifier, 'login')) {
        return new WP_Error('rate_limited', 'Too many failed attempts. Please wait an hour before trying again.');
    }

    if ($two_factor) {
        $_POST['wfTwoFactorCode'] = $two_factor;
    } else {
        unset($_POST['wfTwoFactorCode']);
    }

    $username = eap_secure_login_normalize_username($identifier);

    $_POST['log'] = $username;
    $user = wp_authenticate($username, $password);

    if (is_wp_error($user)) {
        eap_secure_login_register_failure($identifier, 'login');
        return $user;
    }

    $totp_check = eap_secure_login_validate_custom_totp($user->ID, $two_factor);
    if (is_wp_error($totp_check)) {
        eap_secure_login_register_failure($identifier, 'login');
        return $totp_check;
    }

    eap_secure_login_finalize($user, $remember);
    eap_secure_login_reset_failures($identifier, 'login');
    wp_safe_redirect(eap_secure_login_redirect_url());
    exit;
}

function eap_secure_login_handle_code_request() {
    $identifier = isset($_POST['code_identifier']) ? sanitize_text_field(wp_unslash($_POST['code_identifier'])) : '';

    if ('' === $identifier) {
        return new WP_Error('missing_identifier', 'Please enter the email address associated with your account.');
    }

    if (eap_secure_login_is_rate_limited($identifier, 'code')) {
        return new WP_Error('code_rate_limited', 'The maximum number of code requests has been reached. Please try again later.');
    }

    $user = eap_secure_login_find_user($identifier);

    if ($user) {
        $code = eap_secure_login_issue_magic_code($user->ID);
        if (is_wp_error($code)) {
            return $code;
        }

        $subject = '[' . get_bloginfo('name') . '] Your secure login code';
        $message  = "Hello " . $user->display_name . ",\n\n";
        $message .= "Here is your secure login code: " . $code . "\n\n";
        $message .= "This code will expire in 15 minutes and can only be used once. ";
        $message .= "If you did not request this code, you can safely ignore this email.\n\n";
        $message .= "Requested from IP: " . (isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : 'unknown') . "\n\n";
        $message .= "Thank you,\n" . get_bloginfo('name');

        wp_mail($user->user_email, $subject, $message);
    }

    eap_secure_login_register_failure($identifier, 'code'); // counts toward limit even if user not found

    return [
        'success' => true,
        'message' => 'If the account exists, an email with a login code has been sent.',
    ];
}

function eap_secure_login_attempt_code_login() {
    $identifier = isset($_POST['code_identifier']) ? sanitize_text_field(wp_unslash($_POST['code_identifier'])) : '';
    $code = isset($_POST['login_code']) ? sanitize_text_field(wp_unslash($_POST['login_code'])) : '';
    $two_factor = isset($_POST['two_factor_code']) ? sanitize_text_field(wp_unslash($_POST['two_factor_code'])) : '';
    $remember = !empty($_POST['code_remember_me']);

    if ('' === $identifier || '' === $code) {
        return new WP_Error('missing_code_fields', 'Enter both your email (or username) and the login code that was sent to you.');
    }

    if (eap_secure_login_is_rate_limited($identifier, 'login')) {
        return new WP_Error('rate_limited', 'Too many failed attempts. Please wait an hour before trying again.');
    }

    $user = eap_secure_login_find_user($identifier);
    if (!$user) {
        eap_secure_login_register_failure($identifier, 'login');
        return new WP_Error('invalid_user', 'We could not find an account with those details.');
    }

    $code_check = eap_secure_login_validate_magic_code($user->ID, $code);
    if (is_wp_error($code_check)) {
        eap_secure_login_register_failure($identifier, 'login');
        return $code_check;
    }

    $totp_check = eap_secure_login_validate_custom_totp($user->ID, $two_factor);
    if (is_wp_error($totp_check)) {
        eap_secure_login_register_failure($identifier, 'login');
        return $totp_check;
    }

    eap_secure_login_finalize($user, $remember);
    eap_secure_login_reset_failures($identifier, 'login');
    wp_safe_redirect(eap_secure_login_redirect_url());
    exit;
}

function eap_secure_login_handle_forgot_password() {
    $identifier = isset($_POST['forgot_identifier']) ? sanitize_text_field(wp_unslash($_POST['forgot_identifier'])) : '';

    if ('' === $identifier) {
        return new WP_Error('missing_forgot_identifier', 'Please enter your email or username.');
    }

    $result = retrieve_password($identifier);

    if (true === $result) {
        return [
            'success' => true,
            'message' => 'If the account exists, a password reset email has been sent.',
        ];
    }

    if (is_wp_error($result)) {
        return $result;
    }

    return [
        'success' => true,
        'message' => 'If the account exists, a password reset email has been sent.',
    ];
}

// [eap_secure_login]
function eap_secure_login_shortcode() {
    $site_name = get_bloginfo('name');

    if (is_user_logged_in()) {
        $redirect = esc_url(eap_secure_login_redirect_url());
        ob_start();
        ?>
        <div class="eap-secure-login eap-secure-login--logged-in">
            <div class="eap-secure-login__surface">
                <p class="eap-secure-login__status-heading"><?php esc_html_e('You are already signed in', 'llw-eap-member-portal'); ?></p>
                <p class="eap-secure-login__status-text">
                    <?php
                    printf(
                        esc_html__('Welcome back to %s. Continue to the portal to access members-only content.', 'llw-eap-member-portal'),
                        esc_html($site_name)
                    );
                    ?>
                </p>
                <a class="eap-secure-login__primary-link" href="<?php echo $redirect; ?>">
                    <?php esc_html_e('Open the Directory of Delegates', 'llw-eap-member-portal'); ?>
                </a>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    $messages = eap_secure_login_process_request();
    $action_url = esc_url(get_permalink());
    $instance_id = uniqid('eap-secure-login-');
    $last_action = isset($_POST['eap_secure_login_action']) ? sanitize_text_field(wp_unslash($_POST['eap_secure_login_action'])) : '';
    $default_code_state = in_array($last_action, ['request_code', 'code_login'], true) ? 'login' : 'request';

    ob_start();

    static $assets_rendered = false;
    if (!$assets_rendered) {
        $assets_rendered = true;
        ?>
        <script>
            document.addEventListener('DOMContentLoaded',function(){
                document.querySelectorAll('.eap-secure-login').forEach(function(wrapper){
                    var tabs=wrapper.querySelectorAll('[data-eap-login-tab]');
                    tabs.forEach(function(btn){
                        btn.addEventListener('click',function(){
                            var target=this.getAttribute('data-eap-login-tab');
                            wrapper.querySelectorAll('.eap-secure-login__panel').forEach(function(panel){
                                panel.classList.toggle('is-active',panel.getAttribute('data-eap-login-panel')===target);
                            });
                            tabs.forEach(function(b){b.classList.toggle('is-active',b===btn);});
                        });
                    });

                    wrapper.querySelectorAll('[data-password-toggle]').forEach(function(toggle){
                        toggle.addEventListener('click',function(){
                            var input=wrapper.querySelector('#'+this.getAttribute('data-password-toggle'));
                            if(!input){return;}
                            if(input.type==='password'){input.type='text';this.textContent='Hide';this.setAttribute('aria-pressed','true');}
                            else{input.type='password';this.textContent='Show';this.setAttribute('aria-pressed','false');}
                        });
                    });

                    var forgotModal=wrapper.querySelector('[data-eap-forgot-modal]');
                    if(forgotModal){
                        var forgotInput=forgotModal.querySelector('input[name="forgot_identifier"]');
                        var lastFocused=null;

                        function openForgotModal(){
                            lastFocused=document.activeElement;
                            forgotModal.removeAttribute('hidden');
                            forgotModal.setAttribute('aria-hidden','false');
                            document.body.classList.add('eap-secure-login--modal-open');
                            if(forgotInput){forgotInput.focus();}
                        }

                        function closeForgotModal(){
                            forgotModal.setAttribute('hidden','');
                            forgotModal.setAttribute('aria-hidden','true');
                            document.body.classList.remove('eap-secure-login--modal-open');
                            if(lastFocused&&typeof lastFocused.focus==='function'){lastFocused.focus();}
                        }

                        wrapper.querySelectorAll('[data-eap-forgot-trigger]').forEach(function(trigger){
                            trigger.addEventListener('click',function(event){
                                event.preventDefault();
                                openForgotModal();
                            });
                        });

                        forgotModal.querySelectorAll('[data-eap-forgot-close]').forEach(function(btn){
                            btn.addEventListener('click',function(event){
                                event.preventDefault();
                                closeForgotModal();
                            });
                        });

                        forgotModal.addEventListener('click',function(event){
                            if(event.target===forgotModal){
                                closeForgotModal();
                            }
                        });

                        wrapper.addEventListener('keydown',function(event){
                            if(event.key==='Escape'&&!forgotModal.hasAttribute('hidden')){
                                closeForgotModal();
                            }
                        });
                    }

                      var codePanel=wrapper.querySelector('[data-eap-code-panel]');
                      if(codePanel){
                          var requestSection=codePanel.querySelector('[data-eap-code-form="request"]');
                          var loginSection=codePanel.querySelector('[data-eap-code-form="login"]');
                          var defaultCodeState=(codePanel.getAttribute('data-eap-default-code-state')||'request').toLowerCase();
                          if(defaultCodeState!=='login'){defaultCodeState='request';}
                          var storageKey=wrapper.id?'eapSecureLoginCodeState-'+wrapper.id:null;
                          var currentCodeState=defaultCodeState;

                          function applyCodeState(nextState){
                              currentCodeState=nextState==='login'?'login':'request';
                              if(requestSection){
                                  if(currentCodeState==='request'){requestSection.removeAttribute('hidden');}
                                  else{requestSection.setAttribute('hidden','');}
                              }
                              if(loginSection){
                                  if(currentCodeState==='login'){loginSection.removeAttribute('hidden');}
                                  else{loginSection.setAttribute('hidden','');}
                              }
                              codePanel.setAttribute('data-eap-code-state',currentCodeState);
                          }

                          function persistCodeState(state){
                              if(!storageKey){return;}
                              try{
                                  window.sessionStorage.setItem(storageKey,state);
                              }catch(error){}
                          }

                          function readStoredCodeState(){
                              if(!storageKey){return null;}
                              try{
                                  return window.sessionStorage.getItem(storageKey);
                              }catch(error){
                                  return null;
                              }
                          }

                          var storedState=readStoredCodeState();
                          if(storedState){applyCodeState(storedState);}
                          else{applyCodeState(defaultCodeState);}

                          codePanel.querySelectorAll('[data-eap-code-toggle]').forEach(function(toggle){
                              toggle.addEventListener('click',function(event){
                                  event.preventDefault();
                                  applyCodeState(this.getAttribute('data-eap-code-toggle'));
                                  persistCodeState(currentCodeState);
                              });
                          });

                          var requestForm=codePanel.querySelector('form[data-eap-code-request]');
                          if(requestForm){
                              requestForm.addEventListener('submit',function(){
                                  applyCodeState('login');
                                  persistCodeState(currentCodeState);
                              });
                          }

                          var loginForm=codePanel.querySelector('form[data-eap-code-login]');
                          if(loginForm){
                              loginForm.addEventListener('submit',function(){
                                  persistCodeState('request');
                              });
                          }
                      }
                });
            });
        </script>
        <?php
    }
    ?>
    <div class="eap-secure-login" id="<?php echo esc_attr($instance_id); ?>">
        <div class="eap-secure-login__hero">
            <p class="eap-secure-login__eyebrow"><?php esc_html_e('Members-only access', 'llw-eap-member-portal'); ?></p>
            <h2 class="eap-secure-login__title"><?php esc_html_e('Secure Sign In', 'llw-eap-member-portal'); ?></h2>
            <p class="eap-secure-login__subtitle">
                <?php esc_html_e('Choose the method that works best for you—password or a one-time email code, both protected by multi-factor security.', 'llw-eap-member-portal'); ?>
            </p>
            <ul class="eap-secure-login__hero-list" role="list">
                <li class="eap-secure-login__hero-item" role="listitem">
                    <span class="eap-secure-login__hero-icon" aria-hidden="true">🔐</span>
                    <div>
                        <p class="eap-secure-login__hero-label"><?php esc_html_e('Two-factor ready', 'llw-eap-member-portal'); ?></p>
                        <p class="eap-secure-login__hero-copy"><?php esc_html_e('Add your authenticator code for an extra verification step.', 'llw-eap-member-portal'); ?></p>
                    </div>
                </li>
                <li class="eap-secure-login__hero-item" role="listitem">
                    <span class="eap-secure-login__hero-icon" aria-hidden="true">✉️</span>
                    <div>
                        <p class="eap-secure-login__hero-label"><?php esc_html_e('Magic email links', 'llw-eap-member-portal'); ?></p>
                        <p class="eap-secure-login__hero-copy"><?php esc_html_e('Request a one-time code if you prefer not to use your password.', 'llw-eap-member-portal'); ?></p>
                    </div>
                </li>
                <li class="eap-secure-login__hero-item" role="listitem">
                    <span class="eap-secure-login__hero-icon" aria-hidden="true">⚡</span>
                    <div>
                        <p class="eap-secure-login__hero-label"><?php esc_html_e('Instant access', 'llw-eap-member-portal'); ?></p>
                        <p class="eap-secure-login__hero-copy"><?php esc_html_e('Fast redirects to the Directory of Delegates after signing in.', 'llw-eap-member-portal'); ?></p>
                    </div>
                </li>
            </ul>
            <p class="eap-secure-login__hero-chip">
                <?php
                printf(
                    esc_html__('Powered by %s member services', 'llw-eap-member-portal'),
                    esc_html($site_name)
                );
                ?>
            </p>
        </div>

        <div class="eap-secure-login__surface">
            <?php foreach ($messages['errors'] as $message): ?>
                <div class="eap-secure-login__message eap-secure-login__message--error"><?php echo esc_html($message); ?></div>
            <?php endforeach; ?>

            <?php foreach ($messages['success'] as $message): ?>
                <div class="eap-secure-login__message eap-secure-login__message--success"><?php echo esc_html($message); ?></div>
            <?php endforeach; ?>

            <div class="eap-secure-login__tabs">
                <button type="button" class="is-active" data-eap-login-tab="password"><?php esc_html_e('Password Login', 'llw-eap-member-portal'); ?></button>
                <button type="button" data-eap-login-tab="code"><?php esc_html_e('Email Code Login', 'llw-eap-member-portal'); ?></button>
            </div>

            <div class="eap-secure-login__panel is-active" data-eap-login-panel="password">
                <form method="post" action="<?php echo $action_url; ?>">
                    <?php wp_nonce_field('eap_secure_login', 'eap_secure_login_nonce'); ?>
                    <input type="hidden" name="eap_secure_login_action" value="password_login" />

                    <label for="<?php echo esc_attr($instance_id); ?>-identifier"><?php esc_html_e('Email or Username', 'llw-eap-member-portal'); ?></label>
                    <input id="<?php echo esc_attr($instance_id); ?>-identifier" name="login_identifier" type="text" autocomplete="username" required />

                    <label for="<?php echo esc_attr($instance_id); ?>-password"><?php esc_html_e('Password', 'llw-eap-member-portal'); ?></label>
                    <div class="eap-secure-login__password-field">
                        <input id="<?php echo esc_attr($instance_id); ?>-password" name="login_password" type="password" autocomplete="current-password" required />
                        <button type="button" class="eap-secure-login__password-toggle" data-password-toggle="<?php echo esc_attr($instance_id); ?>-password" aria-pressed="false">
                            <?php esc_html_e('Show', 'llw-eap-member-portal'); ?>
                        </button>
                    </div>

                    <label for="<?php echo esc_attr($instance_id); ?>-totp"><?php esc_html_e('Authenticator Code', 'llw-eap-member-portal'); ?></label>
                    <input id="<?php echo esc_attr($instance_id); ?>-totp" name="two_factor_code" type="text" inputmode="numeric" autocomplete="one-time-code" placeholder="<?php esc_attr_e('6-digit code', 'llw-eap-member-portal'); ?>" />
                    <p class="eap-secure-login__note"><?php esc_html_e('If you have 2FA enabled, enter the 6-digit code from your authenticator app.', 'llw-eap-member-portal'); ?></p>

                    <label class="eap-secure-login__remember">
                        <input type="checkbox" name="remember_me" value="1" />
                        <?php esc_html_e('Keep me logged in on this device', 'llw-eap-member-portal'); ?>
                    </label>

                    <div class="eap-secure-login__actions">
                        <button type="submit"><?php esc_html_e('Log In Securely', 'llw-eap-member-portal'); ?></button>
                    </div>
                </form>
                <div class="eap-secure-login__forgot">
                    <button type="button" class="eap-secure-login__forgot-link" data-eap-forgot-trigger aria-controls="<?php echo esc_attr($instance_id); ?>-forgot-modal">
                        <?php esc_html_e('Forgot password?', 'llw-eap-member-portal'); ?>
                    </button>
                </div>
            </div>

            <div class="eap-secure-login__panel" data-eap-login-panel="code" data-eap-code-panel data-eap-default-code-state="<?php echo esc_attr($default_code_state); ?>">
                <div data-eap-code-form="request"<?php echo ('login' === $default_code_state) ? ' hidden' : ''; ?>>
                    <form method="post" action="<?php echo $action_url; ?>" data-eap-code-request>
                        <?php wp_nonce_field('eap_secure_login', 'eap_secure_login_nonce'); ?>
                        <input type="hidden" name="eap_secure_login_action" value="request_code" />

                        <label for="<?php echo esc_attr($instance_id); ?>-code-email"><?php esc_html_e('Email', 'llw-eap-member-portal'); ?></label>
                        <input id="<?php echo esc_attr($instance_id); ?>-code-email" name="code_identifier" type="email" autocomplete="email" required />

                        <div class="eap-secure-login__actions">
                            <button type="submit"><?php esc_html_e('Send Login Code', 'llw-eap-member-portal'); ?></button>
                            <button type="button" class="eap-secure-login__button-secondary" data-eap-code-toggle="login">
                                <?php esc_html_e('Already Have Code', 'llw-eap-member-portal'); ?>
                            </button>
                        </div>
                    </form>
                </div>

                <div data-eap-code-form="login"<?php echo ('login' === $default_code_state) ? '' : ' hidden'; ?>>
                    <form method="post" action="<?php echo $action_url; ?>" data-eap-code-login>
                        <?php wp_nonce_field('eap_secure_login', 'eap_secure_login_nonce'); ?>
                        <input type="hidden" name="eap_secure_login_action" value="code_login" />

                        <label for="<?php echo esc_attr($instance_id); ?>-code-identifier"><?php esc_html_e('Email or Username', 'llw-eap-member-portal'); ?></label>
                        <input id="<?php echo esc_attr($instance_id); ?>-code-identifier" name="code_identifier" type="text" autocomplete="username" required />

                        <label for="<?php echo esc_attr($instance_id); ?>-login-code"><?php esc_html_e('Login Code', 'llw-eap-member-portal'); ?></label>
                        <input id="<?php echo esc_attr($instance_id); ?>-login-code" name="login_code" type="text" inputmode="numeric" pattern="[0-9]*" placeholder="<?php esc_attr_e('6-digit code', 'llw-eap-member-portal'); ?>" required />

                        <label for="<?php echo esc_attr($instance_id); ?>-code-totp"><?php esc_html_e('Authenticator Code', 'llw-eap-member-portal'); ?></label>
                        <input id="<?php echo esc_attr($instance_id); ?>-code-totp" name="two_factor_code" type="text" inputmode="numeric" autocomplete="one-time-code" placeholder="<?php esc_attr_e('6-digit code', 'llw-eap-member-portal'); ?>" />

                        <label class="eap-secure-login__remember">
                            <input type="checkbox" name="code_remember_me" value="1" />
                            <?php esc_html_e('Keep me logged in on this device', 'llw-eap-member-portal'); ?>
                        </label>

                        <div class="eap-secure-login__actions">
                            <button type="submit"><?php esc_html_e('Log In With Code', 'llw-eap-member-portal'); ?></button>
                            <button type="button" class="eap-secure-login__button-secondary" data-eap-code-toggle="request">
                                <?php esc_html_e('Get a New Code', 'llw-eap-member-portal'); ?>
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="eap-secure-login__forgot-modal" id="<?php echo esc_attr($instance_id); ?>-forgot-modal" data-eap-forgot-modal hidden aria-hidden="true">
            <div class="eap-secure-login__forgot-overlay" data-eap-forgot-close></div>
            <div class="eap-secure-login__forgot-dialog" role="dialog" aria-modal="true" aria-labelledby="<?php echo esc_attr($instance_id); ?>-forgot-title">
                <button type="button" class="eap-secure-login__forgot-close" data-eap-forgot-close aria-label="<?php esc_attr_e('Close', 'llw-eap-member-portal'); ?>">&times;</button>
                <h3 id="<?php echo esc_attr($instance_id); ?>-forgot-title"><?php esc_html_e('Forgot your password?', 'llw-eap-member-portal'); ?></h3>
                <p><?php esc_html_e('Enter your email or username and we will send you a reset link.', 'llw-eap-member-portal'); ?></p>
                <form method="post" action="<?php echo $action_url; ?>">
                    <?php wp_nonce_field('eap_secure_login', 'eap_secure_login_nonce'); ?>
                    <input type="hidden" name="eap_secure_login_action" value="forgot_password" />
                    <label class="screen-reader-text" for="<?php echo esc_attr($instance_id); ?>-forgot-input"><?php esc_html_e('Email or username', 'llw-eap-member-portal'); ?></label>
                    <input id="<?php echo esc_attr($instance_id); ?>-forgot-input" name="forgot_identifier" type="text" placeholder="<?php esc_attr_e('Email or username', 'llw-eap-member-portal'); ?>" required />
                    <div class="eap-secure-login__actions">
                        <button type="submit"><?php esc_html_e('Send reset link', 'llw-eap-member-portal'); ?></button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php

    return ob_get_clean();
}
add_shortcode('eap_secure_login', 'eap_secure_login_shortcode');

// [eap_login_logout_link]
function eap_login_logout_link_shortcode() {
    if (is_user_logged_in()) {
        return '<a href="' . wp_logout_url( get_permalink() ) . '">Logout</a>';
    } else {
        return '<a href="' . wp_login_url( get_permalink() ) . '">Login</a>';
    }
}
add_shortcode('eap_login_logout_link', 'eap_login_logout_link_shortcode');


// === 12. ADMIN UI & FUNCTIONALITY ===

/**
 * Render 2FA (authenticator) settings on the user profile screen.
 */
function eap_render_totp_profile_settings($user) {
    if (!current_user_can('edit_user', $user->ID)) {
        return;
    }

    $existing_secret = get_user_meta($user->ID, 'eap_totp_secret', true);
    $proposed_secret = '';

    if (!$existing_secret) {
        $proposed_secret = isset($_POST['eap_totp_proposed_secret'])
            ? sanitize_text_field(wp_unslash($_POST['eap_totp_proposed_secret']))
            : eap_generate_totp_secret();
    }

    $active_secret = $existing_secret ?: $proposed_secret;
    $site_label = get_bloginfo('name');
    $issuer = rawurlencode($site_label);
    $label = rawurlencode($site_label . ':' . $user->user_email);
    $otpauth = $active_secret ? sprintf('otpauth://totp/%s?secret=%s&issuer=%s', $label, $active_secret, $issuer) : '';
    ?>
    <h2>Secure Login (2FA)</h2>
    <table class="form-table" role="presentation">
        <tr>
            <th><label>Authenticator App</label></th>
            <td>
                <?php wp_nonce_field('eap_totp_settings', 'eap_totp_nonce'); ?>
                <input type="hidden" name="eap_totp_present" value="1" />
                <?php if ($proposed_secret): ?>
                    <input type="hidden" name="eap_totp_proposed_secret" value="<?php echo esc_attr($proposed_secret); ?>" />
                <?php endif; ?>

            <?php if ($existing_secret): ?>
                <p><strong>Status:</strong> Enabled</p>
                <p>Secret: <code><?php echo esc_html($existing_secret); ?></code></p>
                <?php if ($otpauth): ?>
                    <div class="eap-login-security__qr" data-eap-otpauth="<?php echo esc_attr($otpauth); ?>" data-eap-qr-size="220" aria-live="polite"></div>
                    <noscript><p class="description"><?php esc_html_e('Enable JavaScript to render the QR code, or enter the secret above manually.', 'llw-eap-member-portal'); ?></p></noscript>
                    <p class="description"><?php esc_html_e('Scan the QR code above with the authenticator app or enter the secret manually.', 'llw-eap-member-portal'); ?></p>
                <?php endif; ?>
                <p>
                    <label>
                        <input type="checkbox" name="eap_totp_regenerate" value="1" />
                        Generate a new secret on save (you will need to reconfigure your authenticator app)
                    </label>
                </p>
                <p>
                    <label>
                        <input type="checkbox" name="eap_totp_disable" value="1" />
                        Disable 2FA for this user
                    </label>
                </p>
            <?php else: ?>
                <p><strong>Status:</strong> Disabled</p>
                <?php if ($proposed_secret): ?>
                    <p>Secret: <code><?php echo esc_html($proposed_secret); ?></code></p>
                <?php endif; ?>
                <?php if ($otpauth): ?>
                    <div class="eap-login-security__qr" data-eap-otpauth="<?php echo esc_attr($otpauth); ?>" data-eap-qr-size="220" aria-live="polite"></div>
                    <noscript><p class="description"><?php esc_html_e('Enable JavaScript to render the QR code, or enter the secret above manually.', 'llw-eap-member-portal'); ?></p></noscript>
                    <p class="description"><?php esc_html_e('Scan the QR code above with the authenticator app or enter the secret manually.', 'llw-eap-member-portal'); ?></p>
                <?php endif; ?>
                <p>
                    <label>
                        <input type="checkbox" name="eap_totp_enable" value="1" />
                        Enable authenticator-based 2FA for this account
                    </label>
                </p>
                <p class="description"><?php esc_html_e('Scan the QR code (or enter the secret) above in your authenticator app, then save the login security settings to activate 2FA.', 'llw-eap-member-portal'); ?></p>
            <?php endif; ?>
            </td>
        </tr>
    </table>
    <?php
}

function eap_save_totp_profile_settings($user_id) {
    if (!current_user_can('edit_user', $user_id)) {
        return;
    }

    if (empty($_POST['eap_totp_present'])) {
        return;
    }

    if (!isset($_POST['eap_totp_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['eap_totp_nonce'])), 'eap_totp_settings')) {
        return;
    }

    $existing_secret = get_user_meta($user_id, 'eap_totp_secret', true);

    if (!empty($_POST['eap_totp_disable'])) {
        delete_user_meta($user_id, 'eap_totp_secret');
        return;
    }

    if (!empty($_POST['eap_totp_regenerate']) && $existing_secret) {
        update_user_meta($user_id, 'eap_totp_secret', eap_generate_totp_secret());
        return;
    }

    if (!$existing_secret && !empty($_POST['eap_totp_enable'])) {
        $proposed = isset($_POST['eap_totp_proposed_secret']) ? sanitize_text_field(wp_unslash($_POST['eap_totp_proposed_secret'])) : '';
        $proposed = strtoupper(preg_replace('/[^A-Z2-7]/', '', $proposed));
        if ($proposed) {
            update_user_meta($user_id, 'eap_totp_secret', $proposed);
        }
    }
}

// Add custom column to Users list
function eap_add_custom_user_columns($columns) {
    $columns['eap_member_role'] = 'EAP Member Role';
    return $columns;
}
add_filter('manage_users_columns', 'eap_add_custom_user_columns');

function eap_show_custom_user_column_data($value, $column_name, $user_id) {
    if ('eap_member_role' == $column_name) {
        $user = get_userdata($user_id);
        $eap_roles = eap_get_member_roles();
        $roles = array_intersect($eap_roles, $user->roles);
        
        if (!empty($roles)) {
            $role_name = reset($roles);
            $role_details = get_role($role_name);
            return $role_details ? $role_details->name : $role_name;
        }
        return '—';
    }
    return $value;
}
add_action('manage_users_custom_column', 'eap_show_custom_user_column_data', 10, 3);

// --- Admin Menu Pages ---

function eap_register_admin_menu() {
    // Create main "Membership System" menu
    add_menu_page(
        'Membership System',           // Page title
        'Membership System',           // Menu title
        'manage_options',              // Capability
        'membership-system',           // Menu slug
        'eap_render_dashboard_page',   // Callback function
        'dashicons-groups',            // Icon (group of people)
        30                             // Position (after Comments)
    );
    
    // Add Dashboard submenu (same as parent)
    add_submenu_page(
        'membership-system',
        'Dashboard',
        'Dashboard',
        'manage_options',
        'membership-system',
        'eap_render_dashboard_page'
    );
    
    // Add Member Import submenu
    add_submenu_page(
        'membership-system',
        'Member Import',
        'Member Import',
        'manage_options',
        'eap-member-import',
        'eap_render_import_page'
    );
    
    // Add Member Export submenu
    add_submenu_page(
        'membership-system',
        'Member Export',
        'Member Export',
        'manage_options',
        'eap-member-export',
        'eap_render_export_page'
    );
    
    // Add Audit Log submenu
    add_submenu_page(
        'membership-system',
        'Audit Log',
        'Audit Log',
        'manage_options',
        'eap-audit-log',
        'eap_render_audit_log_page'
    );

    // Add Login Security submenu
    add_submenu_page(
        'membership-system',
        'Login Security',
        'Login Security',
        'manage_options',
        'eap-login-security',
        'eap_render_login_security_page'
    );
    
    // Add Delegate Administration submenu
    add_submenu_page(
        'membership-system',
        'Delegate Administration',
        'Delegate Administration',
        'manage_options',
        'eap-delegate-admin',
        'eap_render_delegate_admin_page'
    );

    // Add Meeting Events submenu
    add_submenu_page(
        'membership-system',
        'Meeting Events',
        'Meeting Events',
        'manage_options',
        'eap-meeting-events',
        'eap_render_meeting_events_page'
    );
    
    // Add Working Groups submenu
    add_submenu_page(
        'membership-system',
        'Working Groups',
        'Working Groups',
        'manage_options',
        'eap-working-groups',
        'eap_render_workgroups_admin_page'
    );
    
    // Add Privacy Settings submenu
    add_submenu_page(
        'membership-system',
        'Privacy Settings',
        'Privacy Settings',
        'manage_options',
        'eap-privacy-settings',
        'eap_render_privacy_settings_page'
    );
    
    // Add Country Statuses submenu
    add_submenu_page(
        'membership-system',
        'Country Statuses',
        'Country Statuses',
        'manage_options',
        'eap-country-statuses',
        'eap_render_country_statuses_page'
    );
    
    // Add Country Migration submenu (Tools)
    add_submenu_page(
        'membership-system',
        'Country Migration',
        'Country Migration',
        'manage_options',
        'eap-country-migration',
        'eap_render_country_migration_page'
    );
}
add_action('admin_menu', 'eap_register_admin_menu');

/**
 * Validate meeting event date strings.
 */
function eap_is_valid_meeting_date($date) {
    if ('' === $date) {
        return true;
    }

    $date_obj = DateTime::createFromFormat('Y-m-d', $date);

    return $date_obj && $date_obj->format('Y-m-d') === $date;
}

/**
 * Get stored meeting events ordered by start date.
 *
 * @return array[]
 */
function eap_get_meeting_events() {
    $events = get_option('eap_meeting_events', []);

    if (!is_array($events)) {
        return [];
    }

    $normalized = [];

    foreach ($events as $event) {
        if (!is_array($event)) {
            continue;
        }

        $name       = isset($event['name']) ? sanitize_text_field($event['name']) : '';
        $start_date = isset($event['start_date']) ? sanitize_text_field($event['start_date']) : '';

        if ('' === $name || '' === $start_date || !eap_is_valid_meeting_date($start_date)) {
            continue;
        }

        $link     = isset($event['link']) ? esc_url_raw($event['link']) : '';
        $end_date = isset($event['end_date']) ? sanitize_text_field($event['end_date']) : '';

        if ($end_date && !eap_is_valid_meeting_date($end_date)) {
            $end_date = '';
        }

        if ($end_date && $end_date < $start_date) {
            $end_date = $start_date;
        }

        $normalized[] = [
            'name'       => $name,
            'link'       => $link,
            'start_date' => $start_date,
            'end_date'   => $end_date,
        ];
    }

    usort(
        $normalized,
        function ($a, $b) {
            return strcmp($a['start_date'], $b['start_date']);
        }
    );

    return $normalized;
}

/**
 * Generate the markup for a single meeting event row.
 */
function eap_get_meeting_event_row_html($event = []) {
    $defaults = [
        'name'       => '',
        'link'       => '',
        'start_date' => '',
        'end_date'   => '',
    ];

    $event = wp_parse_args($event, $defaults);

    ob_start();
    ?>
    <tr class="eap-meeting-event-row">
        <td data-col="name">
            <label class="screen-reader-text"><?php esc_html_e('Event name', 'llw-eap-member-portal'); ?></label>
            <input type="text"
                   name="event_name[]"
                   class="regular-text"
                   placeholder="<?php esc_attr_e('Event name', 'llw-eap-member-portal'); ?>"
                   value="<?php echo esc_attr($event['name']); ?>"
            />
        </td>
        <td data-col="link">
            <label class="screen-reader-text"><?php esc_html_e('Event link', 'llw-eap-member-portal'); ?></label>
            <input type="url"
                   name="event_link[]"
                   class="regular-text"
                   placeholder="<?php esc_attr_e('https://example.com/meeting', 'llw-eap-member-portal'); ?>"
                   value="<?php echo esc_attr($event['link']); ?>"
            />
        </td>
        <td data-col="start">
            <label class="screen-reader-text"><?php esc_html_e('Event start date', 'llw-eap-member-portal'); ?></label>
            <input type="date"
                   name="event_start_date[]"
                   value="<?php echo esc_attr($event['start_date']); ?>"
            />
        </td>
        <td data-col="end">
            <label class="screen-reader-text"><?php esc_html_e('Event end date', 'llw-eap-member-portal'); ?></label>
            <input type="date"
                   name="event_end_date[]"
                   value="<?php echo esc_attr($event['end_date']); ?>"
            />
        </td>
        <td class="eap-meeting-event-actions">
            <button type="button"
                    class="button-link-delete eap-remove-event"
                    aria-label="<?php esc_attr_e('Remove event', 'llw-eap-member-portal'); ?>">
                &times;
            </button>
        </td>
    </tr>
    <?php
    return trim(ob_get_clean());
}

/**
 * Render the meeting events admin page.
 */
function eap_render_meeting_events_page() {
    if (!current_user_can('manage_options')) {
        wp_die(__('You do not have permission to access this page.', 'llw-eap-member-portal'));
    }

    $notice      = '';
    $error_html  = '';
    $stored_list = eap_get_meeting_events();
    $events      = $stored_list;

    if ('POST' === $_SERVER['REQUEST_METHOD']) {
        $action = isset($_POST['eap_meeting_events_action']) ? sanitize_text_field(wp_unslash($_POST['eap_meeting_events_action'])) : '';

        if ('save' === $action) {
            if (!isset($_POST['eap_meeting_events_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['eap_meeting_events_nonce'])), 'eap_save_meeting_events')) {
                $error_html = '<p>' . esc_html__('Security check failed. Please try again.', 'llw-eap-member-portal') . '</p>';
            } else {
                $names       = isset($_POST['event_name']) ? wp_unslash((array) $_POST['event_name']) : [];
                $links       = isset($_POST['event_link']) ? wp_unslash((array) $_POST['event_link']) : [];
                $starts      = isset($_POST['event_start_date']) ? wp_unslash((array) $_POST['event_start_date']) : [];
                $ends        = isset($_POST['event_end_date']) ? wp_unslash((array) $_POST['event_end_date']) : [];
                $max_rows    = max(count($names), count($links), count($starts), count($ends));
                $prepared    = [];
                $valid_items = [];
                $errors      = [];

                for ($i = 0; $i < $max_rows; $i++) {
                    $name       = isset($names[$i]) ? sanitize_text_field($names[$i]) : '';
                    $link       = isset($links[$i]) ? esc_url_raw($links[$i]) : '';
                    $start_date = isset($starts[$i]) ? sanitize_text_field($starts[$i]) : '';
                    $end_date   = isset($ends[$i]) ? sanitize_text_field($ends[$i]) : '';

                    $row_has_content = '' !== $name || '' !== $link || '' !== $start_date || '' !== $end_date;

                    if ($row_has_content) {
                        $prepared[] = [
                            'name'       => $name,
                            'link'       => $link,
                            'start_date' => $start_date,
                            'end_date'   => $end_date,
                        ];
                    }

                    if (!$row_has_content) {
                        continue;
                    }

                    $row_number = $i + 1;

                    if ('' === $name) {
                        $errors[] = sprintf(__('Row %d is missing an event name.', 'llw-eap-member-portal'), $row_number);
                        continue;
                    }

                    if ('' === $start_date) {
                        $errors[] = sprintf(__('Row %d is missing a start date.', 'llw-eap-member-portal'), $row_number);
                        continue;
                    }

                    if (!eap_is_valid_meeting_date($start_date)) {
                        $errors[] = sprintf(__('Row %d has an invalid start date. Use YYYY-MM-DD.', 'llw-eap-member-portal'), $row_number);
                        continue;
                    }

                    if ($end_date && !eap_is_valid_meeting_date($end_date)) {
                        $errors[] = sprintf(__('Row %d has an invalid end date. Use YYYY-MM-DD.', 'llw-eap-member-portal'), $row_number);
                        continue;
                    }

                    if ($end_date && $end_date < $start_date) {
                        $errors[] = sprintf(__('Row %d has an end date before its start date.', 'llw-eap-member-portal'), $row_number);
                        continue;
                    }

                    $valid_items[] = [
                        'name'       => $name,
                        'link'       => $link,
                        'start_date' => $start_date,
                        'end_date'   => $end_date,
                    ];
                }

                if (empty($errors)) {
                    update_option('eap_meeting_events', $valid_items);
                    $events = $valid_items;

                    $count  = count($valid_items);
                    $notice = $count
                        ? sprintf(_n('Saved %d meeting event.', 'Saved %d meeting events.', $count, 'llw-eap-member-portal'), $count)
                        : esc_html__('Cleared all meeting events.', 'llw-eap-member-portal');

                    eap_log_event(
                        'Meeting events updated',
                        [
                            'events' => $count,
                        ],
                        'content'
                    );
                } else {
                    $events = $prepared ?: [];
                    $error_html = '<ul><li>' . implode('</li><li>', array_map('esc_html', $errors)) . '</li></ul>';
                }
            }
        }
    }

    if (empty($events)) {
        $events = [
            [
                'name'       => '',
                'link'       => '',
                'start_date' => '',
                'end_date'   => '',
            ],
        ];
    }

    $row_template = eap_get_meeting_event_row_html();
    ?>
    <div class="wrap eap-meeting-events-admin">
        <h1>
            <span class="dashicons dashicons-calendar-alt" style="font-size: 32px; vertical-align: middle;"></span>
            <?php esc_html_e('Meeting Events', 'llw-eap-member-portal'); ?>
        </h1>

        <p class="description"><?php esc_html_e('Manage the meetings that appear on the public-facing calendar shortcode.', 'llw-eap-member-portal'); ?></p>

        <style>
            .eap-meeting-events-table input[type="text"],
            .eap-meeting-events-table input[type="url"],
            .eap-meeting-events-table input[type="date"] {
                width: 100%;
                box-sizing: border-box;
            }
            .eap-meeting-event-actions {
                text-align: center;
                width: 60px;
            }
            .eap-meeting-event-row button.eap-remove-event {
                font-size: 20px;
                line-height: 1;
                height: auto;
                color: #b32d2e;
            }
            .eap-meeting-events-admin #eap-add-event-row .dashicons {
                vertical-align: middle;
                margin-right: 4px;
            }
            @media (max-width: 782px) {
                .eap-meeting-events-table thead {
                    display: none;
                }
                .eap-meeting-events-table tr {
                    display: block;
                    margin-bottom: 12px;
                    border: 1px solid #dcdcde;
                    border-radius: 6px;
                    padding: 12px;
                }
                .eap-meeting-events-table td {
                    display: flex;
                    flex-direction: column;
                    padding: 6px 0;
                }
                .eap-meeting-events-table td::before {
                    content: attr(data-col);
                    text-transform: capitalize;
                    font-weight: 600;
                    margin-bottom: 4px;
                }
                .eap-meeting-event-actions {
                    text-align: right;
                }
            }
        </style>

        <?php if ($notice) : ?>
            <div class="notice notice-success is-dismissible"><p><?php echo esc_html($notice); ?></p></div>
        <?php endif; ?>

        <?php if ($error_html) : ?>
            <div class="notice notice-error"><?php echo wp_kses_post($error_html); ?></div>
        <?php endif; ?>

        <form method="post">
            <?php wp_nonce_field('eap_save_meeting_events', 'eap_meeting_events_nonce'); ?>
            <input type="hidden" name="eap_meeting_events_action" value="save" />

            <table class="wp-list-table widefat fixed striped eap-meeting-events-table">
                <thead>
                    <tr>
                        <th scope="col"><?php esc_html_e('Event Name', 'llw-eap-member-portal'); ?></th>
                        <th scope="col"><?php esc_html_e('Event Link', 'llw-eap-member-portal'); ?></th>
                        <th scope="col"><?php esc_html_e('Start Date', 'llw-eap-member-portal'); ?></th>
                        <th scope="col"><?php esc_html_e('End Date', 'llw-eap-member-portal'); ?></th>
                        <th scope="col" class="eap-meeting-event-actions"><?php esc_html_e('Delete', 'llw-eap-member-portal'); ?></th>
                    </tr>
                </thead>
                <tbody id="eap-meeting-events-rows">
                    <?php foreach ($events as $event) : ?>
                        <?php echo eap_get_meeting_event_row_html($event); // phpcs:ignore WordPress.Security.EscapeOutput ?>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <p>
                <button type="button" class="button" id="eap-add-event-row">
                    <span class="dashicons dashicons-plus-alt"></span>
                    <?php esc_html_e('Add Event', 'llw-eap-member-portal'); ?>
                </button>
            </p>

            <p class="submit">
                <button type="submit" class="button button-primary button-large">
                    <?php esc_html_e('Save Meeting Events', 'llw-eap-member-portal'); ?>
                </button>
            </p>
        </form>

        <div class="card" style="max-width: 700px; margin-top: 20px;">
            <h2><?php esc_html_e('Shortcode', 'llw-eap-member-portal'); ?></h2>
            <p>
                <?php
                printf(
                    /* translators: %s: shortcode */
                    esc_html__('Use the %s shortcode to show the mobile-friendly calendar on any page.', 'llw-eap-member-portal'),
                    '<code>[eap_meeting_calendar]</code>'
                );
                ?>
            </p>
            <p class="description"><?php esc_html_e('Dates with multi-day events only need their start and end dates—leave the end date blank for single-day meetings.', 'llw-eap-member-portal'); ?></p>
        </div>
    </div>

    <template id="eap-meeting-event-row-template">
        <?php echo eap_get_meeting_event_row_html(); // phpcs:ignore WordPress.Security.EscapeOutput ?>
    </template>
    <?php
}

/**
 * Admin assets for the meeting events page.
 */
function eap_meeting_events_admin_assets($hook_suffix) {
    if ('membership-system_page_eap-meeting-events' !== $hook_suffix) {
        return;
    }

    $base_dir = plugin_dir_path(__FILE__);
    $base_url = plugin_dir_url(__FILE__);
    $script   = $base_dir . 'js/eap-meeting-events-admin.js';

    if (file_exists($script)) {
        wp_enqueue_script(
            'eap-meeting-events-admin',
            $base_url . 'js/eap-meeting-events-admin.js',
            [],
            filemtime($script),
            true
        );
    }
}
add_action('admin_enqueue_scripts', 'eap_meeting_events_admin_assets');

/**
 * Enqueue assets for the meeting calendar shortcode.
 */
function eap_enqueue_meeting_calendar_assets() {
    static $enqueued = false;

    if ($enqueued) {
        return;
    }

    $base_dir = plugin_dir_path(__FILE__);
    $base_url = plugin_dir_url(__FILE__);

    $style_path = $base_dir . 'css/eap-meeting-calendar.css';
    if (file_exists($style_path)) {
        wp_enqueue_style(
            'eap-meeting-calendar',
            $base_url . 'css/eap-meeting-calendar.css',
            [],
            filemtime($style_path)
        );
    }

    $script_path = $base_dir . 'js/eap-meeting-calendar.js';
    if (file_exists($script_path)) {
        wp_enqueue_script(
            'eap-meeting-calendar',
            $base_url . 'js/eap-meeting-calendar.js',
            [],
            filemtime($script_path),
            true
        );

        wp_localize_script(
            'eap-meeting-calendar',
            'eapMeetingCalendar',
            [
                'strings' => [
                    'noEvents'       => __('No events scheduled yet.', 'llw-eap-member-portal'),
                    'viewEvent'      => __('View Event', 'llw-eap-member-portal'),
                    'listTitle'      => __('Upcoming Events', 'llw-eap-member-portal'),
                    'multipleEvents' => __('%d events', 'llw-eap-member-portal'),
                    'columnEvent'    => __('Event', 'llw-eap-member-portal'),
                    'columnDate'     => __('Date', 'llw-eap-member-portal'),
                    'columnAction'   => __('Action', 'llw-eap-member-portal'),
                    'modalTitle'     => __('Event details', 'llw-eap-member-portal'),
                    'close'          => __('Close', 'llw-eap-member-portal'),
                ],
                'weekStartsOn' => (int) get_option('start_of_week', 1),
            ]
        );
    }

    $enqueued = true;
}

/**
 * Render the public meeting calendar.
 */
function eap_render_meeting_calendar_shortcode($atts) {
    $events = eap_get_meeting_events();
    eap_enqueue_meeting_calendar_assets();

    $calendar_id = uniqid('eap-meeting-calendar-');
    $events_json = wp_json_encode($events);
    if (false === $events_json) {
        $events_json = '[]';
    }

    $views = [
        'month' => __('Month', 'llw-eap-member-portal'),
        'year'  => __('Year', 'llw-eap-member-portal'),
        'list'  => __('List', 'llw-eap-member-portal'),
    ];

    ob_start();
    ?>
    <div class="eap-meeting-calendar is-loading" id="<?php echo esc_attr($calendar_id); ?>" data-events='<?php echo esc_attr($events_json); ?>'>
        <div class="eap-meeting-calendar__loading" role="status" aria-live="polite" aria-busy="true">
            <div class="eap-meeting-calendar__spinner" aria-hidden="true"></div>
            <span class="eap-meeting-calendar__loading-text"><?php esc_html_e('Loading calendar...', 'llw-eap-member-portal'); ?></span>
        </div>
        <div class="eap-meeting-calendar__toolbar">
            <div class="eap-meeting-calendar__range">
                <button type="button" class="eap-meeting-calendar__nav" data-action="prev" style="padding: 2px;" aria-label="<?php esc_attr_e('Previous period', 'llw-eap-member-portal'); ?>"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 640 640" style="fill: #fffa;height: 24px;"><path d="M199.7 299.8C189.4 312.4 190.2 330.9 201.9 342.6L329.9 470.6C339.1 479.8 352.8 482.5 364.8 477.5C376.8 472.5 384.6 460.9 384.6 447.9L384.6 191.9C384.6 179 376.8 167.3 364.8 162.3C352.8 157.3 339.1 160.1 329.9 169.2L201.9 297.2L199.7 299.6z"/></svg></button>
                <div class="eap-meeting-calendar__label" data-role="range-label"></div>
                <button type="button" class="eap-meeting-calendar__nav" data-action="next" style="padding: 2px;" aria-label="<?php esc_attr_e('Next period', 'llw-eap-member-portal'); ?>"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 640 640" style="fill: #fffa;height: 24px;"><path d="M441.3 299.8C451.5 312.4 450.8 330.9 439.1 342.6L311.1 470.6C301.9 479.8 288.2 482.5 276.2 477.5C264.2 472.5 256.5 460.9 256.5 448L256.5 192C256.5 179.1 264.3 167.4 276.3 162.4C288.3 157.4 302 160.2 311.2 169.3L439.2 297.3L441.4 299.7z"/></svg></button>
            </div>
            <div class="eap-meeting-calendar__view-switch" role="tablist">
                <?php foreach ($views as $view_key => $label) : ?>
                    <?php $is_active = ('month' === $view_key); ?>
                    <button type="button"
                            class="<?php echo $is_active ? 'is-active' : ''; ?>"
                            data-calendar-view-toggle="<?php echo esc_attr($view_key); ?>"
                            role="tab"
                            aria-selected="<?php echo $is_active ? 'true' : 'false'; ?>">
                        <?php echo esc_html($label); ?>
                    </button>
                <?php endforeach; ?>
            </div>
        </div>
        <div class="eap-meeting-calendar__body">
            <div class="eap-meeting-calendar__grid" data-calendar-view="month"></div>
            <div class="eap-meeting-calendar__year" data-calendar-view="year" hidden></div>
            <div class="eap-meeting-calendar__list" data-calendar-view="list" hidden></div>
        </div>
        <div class="eap-meeting-calendar__modal" hidden aria-hidden="true">
            <div class="eap-meeting-calendar__modal-backdrop" data-modal-close></div>
            <div class="eap-meeting-calendar__modal-dialog" role="dialog" aria-modal="true" aria-label="<?php esc_attr_e('Event details', 'llw-eap-member-portal'); ?>">
                <button type="button" class="eap-meeting-calendar__modal-close" data-modal-close aria-label="<?php esc_attr_e('Close', 'llw-eap-member-portal'); ?>">&times;</button>
                <div class="eap-meeting-calendar__modal-body">
                    <div class="eap-meeting-calendar__modal-events" aria-live="polite"></div>
                </div>
            </div>
        </div>
    </div>
    <?php
    return ob_get_clean();
}
add_shortcode('eap_meeting_calendar', 'eap_render_meeting_calendar_shortcode');

function eap_render_login_security_page() {
    if (!current_user_can('manage_options')) {
        wp_die(__('You do not have permission to access this page.', 'llw-eap-member-portal'));
    }

    global $wpdb;

    $selected_user_id = isset($_REQUEST['selected_user']) ? absint($_REQUEST['selected_user']) : 0;
    $notice = '';
    $error = '';

    if ('POST' === $_SERVER['REQUEST_METHOD']) {
        $action = isset($_POST['eap_login_security_action']) ? sanitize_text_field(wp_unslash($_POST['eap_login_security_action'])) : '';

        if ('update_totp' === $action) {
            $selected_user_id = isset($_POST['eap_login_security_user']) ? absint($_POST['eap_login_security_user']) : 0;

            if (!$selected_user_id) {
                $error = __('Please select a user before saving login security settings.', 'llw-eap-member-portal');
            } elseif (!isset($_POST['eap_totp_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['eap_totp_nonce'])), 'eap_totp_settings')) {
                $error = __('Security validation failed. Please refresh and try again.', 'llw-eap-member-portal');
            } elseif (!current_user_can('edit_user', $selected_user_id)) {
                $error = __('You are not allowed to edit that user.', 'llw-eap-member-portal');
            } else {
                eap_save_totp_profile_settings($selected_user_id);
                $target_user = get_userdata($selected_user_id);
                $notice = $target_user
                    ? sprintf(__('Login security settings updated for %s.', 'llw-eap-member-portal'), $target_user->display_name)
                    : __('Login security settings updated.', 'llw-eap-member-portal');
            }
        }
    }

    $selected_user = $selected_user_id ? get_userdata($selected_user_id) : null;
    if ($selected_user_id && !$selected_user && !$error) {
        $error = __('The selected user could not be found.', 'llw-eap-member-portal');
    }

    $enabled_totp = (int) $wpdb->get_var(
        $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE meta_key = %s AND meta_value <> ''",
            'eap_totp_secret'
        )
    );

    $user_counts = count_users();
    $total_users = isset($user_counts['total_users']) ? (int) $user_counts['total_users'] : 0;
    $pending_totp = max($total_users - $enabled_totp, 0);

    ?>
    <div class="wrap eap-login-security">
        <h1>
            <span class="dashicons dashicons-lock" style="font-size: 32px; vertical-align: middle;"></span>
            Login Security
        </h1>
        <p><?php esc_html_e('Manage authenticator-based two-factor authentication (2FA) for members from one place.', 'llw-eap-member-portal'); ?></p>

        <style>
            .eap-login-security .card {
                max-width: 100%;
                margin-top: 20px;
            }
            .eap-login-security__stats {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
                gap: 16px;
                margin-top: 20px;
            }
            .eap-login-security__stat {
                background: #fff;
                border: 1px solid #d7dce3;
                border-radius: 8px;
                padding: 20px;
            }
            .eap-login-security__stat-number {
                font-size: 2rem;
                line-height: 1.2;
                margin: 0 0 6px;
            }
            .eap-login-security__qr {
                margin: 12px 0;
            }
            .eap-login-security__qr canvas,
            .eap-login-security__qr img {
                width: 100% !important;
                max-width: 220px;
                height: auto !important;
                border: 1px solid #d7dce3;
                border-radius: 6px;
                background: #fff;
                padding: 8px;
                box-sizing: border-box;
            }
            .eap-login-security__user-meta {
                background: #f8fafc;
                border: 1px solid #dce3ed;
                border-radius: 6px;
                padding: 12px 16px;
                margin-bottom: 16px;
            }
        </style>

        <?php if ($notice): ?>
            <div class="notice notice-success is-dismissible"><p><?php echo esc_html($notice); ?></p></div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="notice notice-error"><p><?php echo esc_html($error); ?></p></div>
        <?php endif; ?>

        <div class="eap-login-security__stats">
            <div class="eap-login-security__stat">
                <p class="eap-login-security__stat-number"><?php echo number_format_i18n($enabled_totp); ?></p>
                <p><strong><?php esc_html_e('Accounts with authenticator 2FA', 'llw-eap-member-portal'); ?></strong></p>
            </div>
            <div class="eap-login-security__stat">
                <p class="eap-login-security__stat-number"><?php echo number_format_i18n($pending_totp); ?></p>
                <p><strong><?php esc_html_e('Accounts pending enrollment', 'llw-eap-member-portal'); ?></strong></p>
            </div>
        </div>

        <div class="card">
            <h2><?php esc_html_e('Select a user', 'llw-eap-member-portal'); ?></h2>
            <form method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>" style="display:flex; gap:12px; align-items:center; flex-wrap:wrap;">
                <input type="hidden" name="page" value="eap-login-security" />
                <?php
                wp_dropdown_users([
                    'name' => 'selected_user',
                    'id' => 'eap-login-security-user',
                    'selected' => $selected_user_id,
                    'show_option_none' => __('Select a user', 'llw-eap-member-portal'),
                    'option_none_value' => '',
                    'include_selected' => true,
                    'class' => 'regular-text',
                    'orderby' => 'display_name',
                    'order' => 'ASC',
                ]);
                ?>
                <button type="submit" class="button button-primary"><?php esc_html_e('Load settings', 'llw-eap-member-portal'); ?></button>
            </form>
            <p class="description"><?php esc_html_e('Type a few characters to quickly jump to a member inside the dropdown list.', 'llw-eap-member-portal'); ?></p>
        </div>

        <?php if ($selected_user): ?>
            <div class="card">
                <h2><?php esc_html_e('Authenticator settings', 'llw-eap-member-portal'); ?></h2>
                <div class="eap-login-security__user-meta">
                    <strong><?php echo esc_html($selected_user->display_name); ?></strong><br />
                    <span><?php echo esc_html($selected_user->user_email); ?></span><br />
                    <?php
                    $role_names = [];
                    foreach ((array) $selected_user->roles as $role_slug) {
                        $role_obj = get_role($role_slug);
                        $role_names[] = $role_obj ? $role_obj->name : ucwords(str_replace('_', ' ', $role_slug));
                    }
                    ?>
                    <span><?php echo esc_html(implode(', ', $role_names)); ?></span>
                    <p style="margin-top: 8px;">
                        <a href="<?php echo esc_url(get_edit_user_link($selected_user->ID)); ?>"><?php esc_html_e('Open user profile', 'llw-eap-member-portal'); ?></a>
                    </p>
                </div>
                <form method="post" action="<?php echo esc_url(add_query_arg(['page' => 'eap-login-security', 'selected_user' => $selected_user->ID], admin_url('admin.php'))); ?>">
                    <input type="hidden" name="eap_login_security_action" value="update_totp" />
                    <input type="hidden" name="eap_login_security_user" value="<?php echo esc_attr($selected_user->ID); ?>" />
                    <?php eap_render_totp_profile_settings($selected_user); ?>
                    <p class="submit">
                        <button type="submit" class="button button-primary"><?php esc_html_e('Save Login Security', 'llw-eap-member-portal'); ?></button>
                    </p>
                </form>
            </div>
        <?php else: ?>
            <div class="card">
                <p><em><?php esc_html_e('Select a user above to view and update their 2FA configuration.', 'llw-eap-member-portal'); ?></em></p>
            </div>
        <?php endif; ?>
    </div>
    <?php
}

function eap_login_security_enqueue_assets($hook_suffix) {
    // Only load on the login security page
    if ('membership-system_page_eap-login-security' !== $hook_suffix) {
        return;
    }

    $base_dir = plugin_dir_path(__FILE__);
    $base_url = plugin_dir_url(__FILE__);
    $login_path = $base_dir . 'js/eap-login-security.js';

    // 1. Load QRCode library from CDN (More reliable than local file copy-paste)
    wp_enqueue_script(
        'eap-qrcode',
        'https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js',
        [],
        '1.0.0',
        true
    );

    // 2. Load our custom security script
    if (file_exists($login_path)) {
        wp_enqueue_script(
            'eap-login-security',
            $base_url . 'js/eap-login-security.js',
            ['eap-qrcode'], // Dependent on the CDN script above
            filemtime($login_path),
            true
        );

        // Localize variables for JS
        wp_localize_script(
            'eap-login-security',
            'eapLoginSecurity',
            [
                'qrError' => __('Unable to render the QR code. Please enter the secret manually.', 'llw-eap-member-portal'),
            ]
        );
    }
}
add_action('admin_enqueue_scripts', 'eap_login_security_enqueue_assets');

/**
 * Show admin notice for country migration
 */
function eap_country_migration_admin_notice() {
    if (!current_user_can('manage_options')) {
        return;
    }
    
    if (get_option('eap_show_country_migration_notice', false)) {
        ?>
        <div class="notice notice-warning is-dismissible">
            <p><strong>EAP Member Portal:</strong> Country fields have been updated to use IDs. Please <a href="<?php echo admin_url('admin.php?page=eap-country-migration'); ?>">run the migration</a> to convert existing country names to IDs.</p>
        </div>
        <?php
    }
}
add_action('admin_notices', 'eap_country_migration_admin_notice');

/**
 * Render a single row for the country status table.
 *
 * @param array $countries
 * @param array $status_options
 * @param int   $selected_country
 * @param string $selected_status
 * @return string
 */
function eap_country_status_row_markup($countries, $status_options, $selected_country = 0, $selected_status = '') {
    ob_start();
    ?>
    <tr class="eap-country-status-row">
        <td>
            <label class="screen-reader-text"><?php esc_html_e('Country', 'llw-eap-member-portal'); ?></label>
            <select class="eap-country-status-country">
                <option value=""><?php esc_html_e('Select a country', 'llw-eap-member-portal'); ?></option>
                <?php foreach ($countries as $country): ?>
                    <option value="<?php echo esc_attr($country->id); ?>" <?php selected($selected_country, $country->id); ?>>
                        <?php echo esc_html($country->country_name); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </td>
        <td>
            <label class="screen-reader-text"><?php esc_html_e('UEMS status', 'llw-eap-member-portal'); ?></label>
            <select class="eap-country-status-status">
                <option value=""><?php esc_html_e('Select a status', 'llw-eap-member-portal'); ?></option>
                <?php foreach ($status_options as $status_key => $status_label): ?>
                    <option value="<?php echo esc_attr($status_key); ?>" <?php selected($selected_status, $status_key); ?>>
                        <?php echo esc_html($status_label); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </td>
        <td class="eap-country-status-actions">
            <button type="button" class="button-link-delete eap-country-status-remove" aria-label="<?php esc_attr_e('Remove row', 'llw-eap-member-portal'); ?>">
                <span class="dashicons dashicons-trash"></span>
                <?php esc_html_e('Remove', 'llw-eap-member-portal'); ?>
            </button>
        </td>
    </tr>
    <?php
    return ob_get_clean();
}

/**
 * Render the Country Statuses admin page.
 */
function eap_render_country_statuses_page() {
    if (!current_user_can('manage_options')) {
        return;
    }
    
    $countries = eap_get_countries();
    $status_options = eap_get_country_status_options();
    $status_map = eap_get_country_status_map();
    ?>
    <div class="wrap">
        <h1>
            <span class="dashicons dashicons-flag" style="font-size: 32px; vertical-align: middle;"></span>
            <?php esc_html_e('Country Statuses', 'llw-eap-member-portal'); ?>
        </h1>
        
        <p class="description" style="max-width: 760px;">
            <?php esc_html_e('Assign each country an official UEMS status. Any country not listed below is treated as N/A automatically.', 'llw-eap-member-portal'); ?>
        </p>
        
        <div id="eap-country-status-manager" class="eap-country-status-manager card" style="max-width: 960px; margin-top: 20px; padding: 24px;">
            <?php wp_nonce_field('eap_country_status_manager', 'eap_country_status_nonce'); ?>
            
            <div class="eap-country-status-toolbar" style="display:flex; justify-content: space-between; align-items:center; margin-bottom: 15px; flex-wrap: wrap; gap: 10px;">
                <p style="margin:0;">
                    <strong><?php esc_html_e('Changes save automatically.', 'llw-eap-member-portal'); ?></strong>
                </p>
                <button type="button" class="button button-secondary eap-country-status-add">
                    <span class="dashicons dashicons-plus-alt"></span>
                    <?php esc_html_e('Add Country', 'llw-eap-member-portal'); ?>
                </button>
            </div>
            
            <div class="eap-country-status-messages" aria-live="polite"></div>
            
            <div class="eap-country-status-table-wrapper" style="overflow-x:auto;">
                <table class="widefat fixed striped">
                    <thead>
                        <tr>
                            <th style="width:45%;"><?php esc_html_e('Country', 'llw-eap-member-portal'); ?></th>
                            <th style="width:45%;"><?php esc_html_e('UEMS Country Status', 'llw-eap-member-portal'); ?></th>
                            <th style="width:10%;"><?php esc_html_e('Actions', 'llw-eap-member-portal'); ?></th>
                        </tr>
                    </thead>
                    <tbody class="eap-country-status-rows">
                        <?php
                        if (!empty($status_map)) {
                            foreach ($status_map as $country_id => $status_key) {
                                echo eap_country_status_row_markup($countries, $status_options, $country_id, $status_key);
                            }
                        } else {
                            ?>
                            <tr class="no-country-status-rows">
                                <td colspan="3">
                                    <?php esc_html_e('No countries have been assigned yet. Click "Add Country" to create the first mapping.', 'llw-eap-member-portal'); ?>
                                </td>
                            </tr>
                            <?php
                        }
                        ?>
                    </tbody>
                </table>
            </div>
        </div>
        
        <template id="eap-country-status-row-template">
            <?php echo eap_country_status_row_markup($countries, $status_options); ?>
        </template>
        
        <style>
            #eap-country-status-manager select {
                min-width: 220px;
            }
            
            .eap-country-status-row.has-duplicate select.eap-country-status-country {
                border-color: #d63638;
                box-shadow: 0 0 0 1px rgba(214, 54, 56, 0.4);
            }
            
            .eap-country-status-messages .notice {
                margin: 0 0 15px;
            }
        </style>
    </div>
    <?php
}

/**
 * AJAX handler for saving country statuses.
 */
function eap_save_country_statuses_ajax() {
    if (!current_user_can('manage_options')) {
        wp_send_json_error([
            'message' => __('You do not have permission to perform this action.', 'llw-eap-member-portal')
        ], 403);
    }
    
    $nonce = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';
    
    if (!wp_verify_nonce($nonce, 'eap_country_status_manager')) {
        wp_send_json_error([
            'message' => __('Security check failed. Please refresh and try again.', 'llw-eap-member-portal')
        ], 400);
    }
    
    $raw_rows = isset($_POST['rows']) ? wp_unslash($_POST['rows']) : '[]';
    $decoded = json_decode($raw_rows, true);
    
    if (!is_array($decoded)) {
        wp_send_json_error([
            'message' => __('Invalid data payload.', 'llw-eap-member-portal')
        ], 400);
    }
    
    $status_options = eap_get_country_status_options();
    $normalized = [];
    
    foreach ($decoded as $row) {
        if (!is_array($row)) {
            continue;
        }
        
        $country_id = isset($row['countryId']) ? absint($row['countryId']) : 0;
        $status_key = isset($row['status']) ? sanitize_key($row['status']) : '';
        
        if (!$country_id || !isset($status_options[$status_key])) {
            continue;
        }
        
        $normalized[$country_id] = $status_key;
    }
    
    eap_set_country_status_map($normalized);
    
    wp_send_json_success([
        'message' => __('Country statuses saved.', 'llw-eap-member-portal'),
        'map' => $normalized,
    ]);
}
add_action('wp_ajax_eap_save_country_statuses', 'eap_save_country_statuses_ajax');

/**
 * Render the Country Migration page
 */
function eap_render_country_migration_page() {
    // Handle migration action
    if (isset($_POST['eap_run_migration']) && check_admin_referer('eap_country_migration', 'eap_country_migration_nonce')) {
        $result = eap_migrate_country_names_to_ids();
        
        if ($result['success']) {
            // Remove the notice flag
            delete_option('eap_show_country_migration_notice');
            
            echo '<div class="notice notice-success"><p>' . esc_html($result['message']) . '</p></div>';
            
            // Log the migration
            eap_log_event(
                'Country migration completed',
                [
                    'updated' => $result['updated'],
                    'errors' => $result['errors']
                ],
                'system'
            );
        } else {
            echo '<div class="notice notice-error"><p>Migration failed: ' . esc_html($result['message']) . '</p></div>';
        }
    }
    
    // Check if migration is needed
    global $wpdb;
    $countries_to_migrate = $wpdb->get_var("
        SELECT COUNT(*) 
        FROM {$wpdb->usermeta} 
        WHERE meta_key = 'country' 
        AND meta_value != '' 
        AND meta_value NOT REGEXP '^[0-9]+$'
    ");
    
    ?>
    <div class="wrap">
        <h1>
            <span class="dashicons dashicons-database-import" style="font-size: 32px; vertical-align: middle;"></span>
            Country Migration
        </h1>
        
        <div class="card" style="max-width: 800px; margin-top: 20px;">
            <h2>Migrate Country Names to IDs</h2>
            <p>This tool will convert all existing country names stored in user profiles to country IDs from the reference table.</p>
            
            <?php if ($countries_to_migrate > 0): ?>
                <div class="notice notice-info inline">
                    <p><strong>Found <?php echo number_format($countries_to_migrate); ?> user(s) with country names that need to be migrated.</strong></p>
                </div>
                
                <h3>What this migration does:</h3>
                <ul>
                    <li>Searches for all user profiles with country names (non-numeric values)</li>
                    <li>Looks up each country name in the reference table</li>
                    <li>Replaces the country name with the corresponding country ID</li>
                    <li>Logs any country names that cannot be matched</li>
                </ul>
                
                <form method="post" action="">
                    <?php wp_nonce_field('eap_country_migration', 'eap_country_migration_nonce'); ?>
                    <p>
                        <button type="submit" name="eap_run_migration" class="button button-primary button-large">
                            <span class="dashicons dashicons-database-import" style="vertical-align: middle; margin-top: 4px;"></span>
                            Run Migration Now
                        </button>
                    </p>
                </form>
                
                <p class="description"><strong>Note:</strong> This operation is safe to run multiple times. It will only migrate country values that are still stored as names.</p>
            <?php else: ?>
                <div class="notice notice-success inline">
                    <p><strong>✓ All country fields are already using IDs. No migration needed.</strong></p>
                </div>
            <?php endif; ?>
        </div>
        
        <div class="card" style="max-width: 800px; margin-top: 20px;">
            <h2>About Country IDs</h2>
            <p>As of this version, country fields now store country IDs instead of country names. This provides several benefits:</p>
            <ul>
                <li><strong>Easy Updates:</strong> Country names can be updated in the reference table without affecting user data (e.g., Turkey → Türkiye)</li>
                <li><strong>Data Consistency:</strong> Eliminates typos and variations in country names</li>
                <li><strong>Better Performance:</strong> More efficient filtering and searching</li>
                <li><strong>Localization Ready:</strong> Country names can be translated without changing the underlying data</li>
            </ul>
            
            <p>The reference table (<code><?php echo esc_html($wpdb->prefix); ?>ref_countries</code>) contains all available countries with their IDs, names, 2-letter codes, 3-letter codes, and economic classifications.</p>
        </div>
    </div>
    <?php
}

/**
 * Render the Membership System dashboard page
 */
function eap_render_dashboard_page() {
    global $wpdb;
    
    // Get some statistics
    $member_roles = eap_get_member_roles();
    $total_members = 0;
    $role_counts = [];
    
    foreach ($member_roles as $role) {
        $count = count(get_users(['role' => $role]));
        $role_counts[$role] = $count;
        $total_members += $count;
    }
    
    // Get audit log stats
    $table_name = $wpdb->prefix . 'eap_audit_log';
    $total_logs = $wpdb->get_var("SELECT COUNT(*) FROM $table_name");
    $recent_logs = $wpdb->get_results(
        "SELECT * FROM $table_name ORDER BY id DESC LIMIT 5",
        ARRAY_A
    );
    
    // Get CPT counts
    $working_groups_count = wp_count_posts('eap_working_group')->publish;
    
    ?>
    <div class="wrap">
        <h1>
            <span class="dashicons dashicons-groups" style="font-size: 32px; vertical-align: middle;"></span>
            Membership System Dashboard
        </h1>
        
        <div class="eap-dashboard-grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 20px; margin-top: 20px;">
            
            <!-- Member Statistics -->
            <div class="card">
                <h2><span class="dashicons dashicons-admin-users"></span> Member Statistics</h2>
                <table class="widefat" style="margin-top: 15px;">
                    <tbody>
                        <tr>
                            <td><strong>Total Members</strong></td>
                            <td><strong><?php echo number_format($total_members); ?></strong></td>
                        </tr>
                        <?php foreach ($role_counts as $role => $count): ?>
                            <tr>
                                <td><?php echo esc_html(ucwords(str_replace('_', ' ', $role))); ?></td>
                                <td><?php echo number_format($count); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <p style="margin-top: 15px;">
                    <a href="<?php echo admin_url('admin.php?page=eap-member-import'); ?>" class="button button-primary">
                        <span class="dashicons dashicons-upload" style="vertical-align: middle;"></span> Import Members
                    </a>
                </p>
            </div>
            
            <!-- Content Statistics -->
            <div class="card">
                <h2><span class="dashicons dashicons-archive"></span> Content Statistics</h2>
                <table class="widefat" style="margin-top: 15px;">
                    <tbody>
                        <tr>
                            <td><strong>Working Groups</strong></td>
                            <td><strong><?php echo number_format($working_groups_count); ?></strong></td>
                            <td><a href="<?php echo admin_url('admin.php?page=eap-working-groups'); ?>" class="button button-small">View</a></td>
                        </tr>
                    </tbody>
                </table>
            </div>
            
            <!-- System Status -->
            <div class="card">
                <h2><span class="dashicons dashicons-shield"></span> System Status</h2>
                <table class="widefat" style="margin-top: 15px;">
                    <tbody>
                        <tr>
                            <td><strong>Audit Log Entries</strong></td>
                            <td><strong><?php echo number_format($total_logs); ?></strong></td>
                        </tr>
                        <tr>
                            <td><strong>Database Table</strong></td>
                            <td><code><?php echo esc_html($table_name); ?></code></td>
                        </tr>
                        <tr>
                            <td><strong>Plugin Version</strong></td>
                            <td>1.0.1</td>
                        </tr>
                    </tbody>
                </table>
                <p style="margin-top: 15px;">
                    <a href="<?php echo admin_url('admin.php?page=eap-audit-log'); ?>" class="button button-primary">
                        <span class="dashicons dashicons-shield" style="vertical-align: middle;"></span> View Audit Log
                    </a>
                </p>
            </div>
        </div>
        
        <!-- Recent Activity -->
        <div class="card" style="margin-top: 20px;">
            <h2><span class="dashicons dashicons-clock"></span> Recent Activity</h2>
            <?php if (!empty($recent_logs)): ?>
                <table class="wp-list-table widefat striped" style="margin-top: 15px;">
                    <thead>
                        <tr>
                            <th>Time</th>
                            <th>Event Type</th>
                            <th>Message</th>
                            <th>User</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recent_logs as $log): ?>
                            <tr>
                                <td><?php echo esc_html(date('Y-m-d H:i', $log['timestamp'])); ?></td>
                                <td>
                                    <span class="eap-event-badge eap-event-<?php echo esc_attr($log['event_type']); ?>">
                                        <?php echo esc_html(ucfirst($log['event_type'])); ?>
                                    </span>
                                </td>
                                <td><?php echo esc_html($log['message']); ?></td>
                                <td><?php echo esc_html($log['user_login']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <p><em>No recent activity.</em></p>
            <?php endif; ?>
        </div>
        
        <!-- Quick Links -->
        <div class="card" style="margin-top: 20px;">
            <h2><span class="dashicons dashicons-admin-links"></span> Quick Links</h2>
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 10px; margin-top: 15px;">
                <a href="<?php echo admin_url('users.php'); ?>" class="button button-large">
                    <span class="dashicons dashicons-admin-users"></span> All Users
                </a>
                <a href="<?php echo admin_url('admin.php?page=eap-member-import'); ?>" class="button button-large">
                    <span class="dashicons dashicons-upload"></span> Import Members
                </a>
                <a href="<?php echo admin_url('admin.php?page=eap-member-export'); ?>" class="button button-large">
                    <span class="dashicons dashicons-download"></span> Export Members
                </a>
                <a href="<?php echo admin_url('admin.php?page=eap-working-groups'); ?>" class="button button-large">
                    <span class="dashicons dashicons-groups"></span> Working Groups
                </a>
                <a href="<?php echo admin_url('admin.php?page=eap-audit-log'); ?>" class="button button-large">
                    <span class="dashicons dashicons-shield"></span> Audit Log
                </a>
            </div>
        </div>
    </div>
    <?php
}

/**
 * Render the Working Groups admin page.
 */
function eap_render_workgroups_admin_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'You do not have permission to access this page.', 'llw-eap-member-portal' ) );
    }

    $drive_ready = class_exists( 'EAP_Workgroup_Drive_Sync' ) && EAP_Workgroup_Drive_Sync::is_configured();
    $last_full_sync = (int) get_option( 'eap_drive_last_full_sync', 0 );
    $last_full_sync_display = $last_full_sync
        ? date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $last_full_sync )
        : esc_html__( 'Never', 'llw-eap-member-portal' );
    $root_folder_id = defined( 'EAP_DRIVE_ROOT_FOLDER_ID' ) ? EAP_DRIVE_ROOT_FOLDER_ID : '';

    $workgroups = get_posts(
        [
            'post_type'      => 'eap_working_group',
            'post_status'    => [ 'publish', 'draft', 'pending', 'private' ],
            'posts_per_page' => -1,
            'orderby'        => 'title',
            'order'          => 'ASC',
        ]
    );

    $sync_action = admin_url( 'admin-post.php' );
    ?>
    <div class="wrap">
        <h1 class="wp-heading-inline"><?php esc_html_e( 'Working Groups', 'llw-eap-member-portal' ); ?></h1>
        <p><?php esc_html_e( 'This page mirrors Google Drive folders into Working Group posts and their resources.', 'llw-eap-member-portal' ); ?></p>

        <?php if ( ! $drive_ready ) : ?>
            <div class="notice notice-warning">
                <p><?php esc_html_e( 'Google Drive syncing is not configured. Please ensure EAP_DRIVE_ROOT_FOLDER_ID and EAP_DRIVE_SERVICE_ACCOUNT_JSON are defined in wp-config.php.', 'llw-eap-member-portal' ); ?></p>
            </div>
        <?php endif; ?>

        <div class="card" style="margin-top:20px;padding:20px;">
            <form method="post" action="<?php echo esc_url( $sync_action ); ?>">
                <?php wp_nonce_field( 'eap_drive_sync_all' ); ?>
                <input type="hidden" name="action" value="eap_drive_sync_all">
                <button type="submit" class="button button-primary" <?php disabled( ! $drive_ready ); ?>>
                    <span class="dashicons dashicons-update" style="vertical-align:middle;"></span>
                    <?php esc_html_e( 'Sync all workgroups', 'llw-eap-member-portal' ); ?>
                </button>
            </form>
            <p class="description" style="margin-top:10px;">
                <?php esc_html_e( 'Root folder ID:', 'llw-eap-member-portal' ); ?>
                <code><?php echo $root_folder_id ? esc_html( $root_folder_id ) : esc_html__( 'Not set', 'llw-eap-member-portal' ); ?></code>
            </p>
            <p class="description">
                <?php esc_html_e( 'Last full sync:', 'llw-eap-member-portal' ); ?>
                <strong><?php echo esc_html( $last_full_sync_display ); ?></strong>
            </p>
        </div>

        <h2 style="margin-top:30px;"><?php esc_html_e( 'Workgroup Directory', 'llw-eap-member-portal' ); ?></h2>
        <table class="wp-list-table widefat striped">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'Name', 'llw-eap-member-portal' ); ?></th>
                    <th><?php esc_html_e( 'Files', 'llw-eap-member-portal' ); ?></th>
                    <th><?php esc_html_e( 'Last Sync', 'llw-eap-member-portal' ); ?></th>
                    <th><?php esc_html_e( 'Drive Folder ID', 'llw-eap-member-portal' ); ?></th>
                    <th><?php esc_html_e( 'Actions', 'llw-eap-member-portal' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if ( empty( $workgroups ) ) : ?>
                    <tr>
                        <td colspan="5"><?php esc_html_e( 'No working groups found. Run a sync to import folders from Google Drive.', 'llw-eap-member-portal' ); ?></td>
                    </tr>
                <?php else : ?>
                    <?php foreach ( $workgroups as $workgroup ) : ?>
                        <?php
                        $files             = get_post_meta( $workgroup->ID, '_eap_secure_files', true );
                        $file_count        = is_array( $files ) ? count( $files ) : 0;
                        $last_sync_meta    = get_post_meta( $workgroup->ID, '_eap_drive_last_sync', true );
                        $last_sync_display = $last_sync_meta
                            ? date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $last_sync_meta ) )
                            : esc_html__( 'Never', 'llw-eap-member-portal' );
                        $folder_id  = get_post_meta( $workgroup->ID, '_eap_drive_folder_id', true );
                        $status_label = ucfirst( str_replace( '_', ' ', $workgroup->post_status ) );
                        ?>
                        <tr>
                            <td>
                                <strong><?php echo esc_html( get_the_title( $workgroup ) ); ?></strong><br>
                                <span class="description"><?php echo esc_html( $status_label ); ?></span>
                            </td>
                            <td><?php echo esc_html( $file_count ); ?></td>
                            <td><?php echo esc_html( $last_sync_display ); ?></td>
                            <td><code><?php echo $folder_id ? esc_html( $folder_id ) : '—'; ?></code></td>
                            <td>
                                <?php if ( 'publish' === $workgroup->post_status ) : ?>
                                    <a href="<?php echo esc_url( get_permalink( $workgroup ) ); ?>" class="button button-small" target="_blank" rel="noopener noreferrer">
                                        <?php esc_html_e( 'View', 'llw-eap-member-portal' ); ?>
                                    </a>
                                <?php endif; ?>
                                <?php if ( $drive_ready ) : ?>
                                    <form method="post" action="<?php echo esc_url( $sync_action ); ?>" style="display:inline-block; margin-left:6px;">
                                        <?php wp_nonce_field( 'eap_drive_sync_single_' . $workgroup->ID ); ?>
                                        <input type="hidden" name="action" value="eap_drive_sync_single">
                                        <input type="hidden" name="post_id" value="<?php echo esc_attr( $workgroup->ID ); ?>">
                                        <button type="submit" class="button button-secondary"><?php esc_html_e( 'Sync now', 'llw-eap-member-portal' ); ?></button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php
}

/**
 * Store an admin notice for Drive sync actions.
 *
 * @param string $type
 * @param string $message
 */
function eap_set_drive_sync_notice( $type, $message ) {
    $key = 'eap_drive_sync_notice_' . get_current_user_id();
    set_transient(
        $key,
        [
            'type'    => $type,
            'message' => $message,
        ],
        MINUTE_IN_SECONDS
    );
}

/**
 * Render Drive sync admin notices on the Working Groups page.
 */
function eap_render_drive_sync_notice() {
    if ( empty( $_GET['page'] ) || 'eap-working-groups' !== $_GET['page'] ) { // phpcs:ignore WordPress.Security.NonceVerification
        return;
    }

    $key    = 'eap_drive_sync_notice_' . get_current_user_id();
    $notice = get_transient( $key );

    if ( ! $notice ) {
        return;
    }

    delete_transient( $key );

    $type = in_array( $notice['type'], [ 'success', 'warning', 'error', 'info' ], true )
        ? $notice['type']
        : 'info';

    printf(
        '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
        esc_attr( $type ),
        esc_html( $notice['message'] )
    );
}
add_action( 'admin_notices', 'eap_render_drive_sync_notice' );

/**
 * Handle "sync all" requests from the admin page.
 */
function eap_handle_drive_sync_all_request() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'Unauthorized request.', 'llw-eap-member-portal' ) );
    }

    check_admin_referer( 'eap_drive_sync_all' );

    $redirect = admin_url( 'admin.php?page=eap-working-groups' );

    if ( ! class_exists( 'EAP_Workgroup_Drive_Sync' ) || ! EAP_Workgroup_Drive_Sync::is_configured() ) {
        eap_set_drive_sync_notice( 'error', __( 'Drive sync is not configured.', 'llw-eap-member-portal' ) );
        wp_safe_redirect( $redirect );
        exit;
    }

    $result = EAP_Workgroup_Drive_Sync::instance()->maybe_full_sync( true, 'manual' );

    if ( is_wp_error( $result ) ) {
        eap_set_drive_sync_notice(
            'error',
            sprintf(
                __( 'Drive sync failed: %s', 'llw-eap-member-portal' ),
                $result->get_error_message()
            )
        );
    } elseif ( is_array( $result ) ) {
        eap_set_drive_sync_notice(
            'success',
            sprintf(
                __( 'Synced %1$d workgroups and downloaded %2$d file(s).', 'llw-eap-member-portal' ),
                absint( $result['folders'] ),
                absint( $result['files_downloaded'] )
            )
        );
    } else {
        eap_set_drive_sync_notice( 'info', __( 'Drive sync was skipped because another sync is already running.', 'llw-eap-member-portal' ) );
    }

    wp_safe_redirect( $redirect );
    exit;
}
add_action( 'admin_post_eap_drive_sync_all', 'eap_handle_drive_sync_all_request' );

/**
 * Handle per-workgroup sync requests from the admin page.
 */
function eap_handle_drive_sync_single_request() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'Unauthorized request.', 'llw-eap-member-portal' ) );
    }

    $post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing

    if ( ! $post_id ) {
        wp_safe_redirect( admin_url( 'admin.php?page=eap-working-groups' ) );
        exit;
    }

    check_admin_referer( 'eap_drive_sync_single_' . $post_id );

    $redirect = admin_url( 'admin.php?page=eap-working-groups' );

    if ( ! class_exists( 'EAP_Workgroup_Drive_Sync' ) || ! EAP_Workgroup_Drive_Sync::is_configured() ) {
        eap_set_drive_sync_notice( 'error', __( 'Drive sync is not configured.', 'llw-eap-member-portal' ) );
        wp_safe_redirect( $redirect );
        exit;
    }

    $result = EAP_Workgroup_Drive_Sync::instance()->sync_single_workgroup( $post_id, true );
    $title  = get_the_title( $post_id );
    $safe_title = $title ? wp_strip_all_tags( $title ) : '#' . $post_id;

    if ( is_wp_error( $result ) ) {
        eap_set_drive_sync_notice(
            'error',
            sprintf(
                __( 'Failed to sync "%1$s": %2$s', 'llw-eap-member-portal' ),
                $safe_title,
                $result->get_error_message()
            )
        );
    } else {
        eap_set_drive_sync_notice(
            'success',
            sprintf(
                __( 'Synced "%s".', 'llw-eap-member-portal' ),
                $safe_title
            )
        );
    }

    wp_safe_redirect( $redirect );
    exit;
}
add_action( 'admin_post_eap_drive_sync_single', 'eap_handle_drive_sync_single_request' );

// Hide the CPT menu items since they're now under Membership System
function eap_hide_cpt_menus() {
    remove_menu_page('edit.php?post_type=eap_working_group');
}
add_action('admin_menu', 'eap_hide_cpt_menus', 999);

/**
 * Redirect default CPT list views to the custom Working Groups page.
 */
function eap_redirect_workgroup_cpt_screen() {
    if ( EAP_ENABLE_WG_EDITOR ) {
        return;
    }

    if ( ! is_admin() ) {
        return;
    }

    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    if ( empty( $_GET['post_type'] ) || 'eap_working_group' !== $_GET['post_type'] ) { // phpcs:ignore WordPress.Security.NonceVerification
        return;
    }

    wp_safe_redirect( admin_url( 'admin.php?page=eap-working-groups' ) );
    exit;
}
add_action( 'admin_init', 'eap_redirect_workgroup_cpt_screen' );

/**
 * Handle CSV template download before any output is sent
 */
function eap_handle_csv_download() {
    // Check if we're on the import page and download action is requested
    if (isset($_GET['page']) && $_GET['page'] === 'eap-member-import' 
        && isset($_GET['action']) && $_GET['action'] === 'download_template') {
        
        // Verify user permissions
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        
        // Define the template headers
        $headers = [
            'email', 'first_name', 'last_name', 'role', 'title_prefix', 
            'institution', 'city', 'country', 'preferred_email', 'phone', 'whatsapp_number',
            'term_start', 'term_end', 'society', 'specialty', 'eap_council',
            'biography', 'languages', 'uems_status', 'uems_email', 'uems_notes',
            'uems_date_confirmed', 'internal_notes', 'is_active'
        ];
        
        // Sample row
        $sample = [
            'john.doe@example.com',
            'John',
            'Doe',
            'national_delegate',
            'Dr',
            'University Hospital',
            'Brussels',
            'BE',
            'j.doe@hospital.be',
            '+32 2 123 4567',
            '+32 470 123 456',
            '06/2024',
            '06/2027',
            'Belgian Society of Paediatrics',
            'General Paediatrics',
            'pcc',
            'John Doe is a paediatrician with 15 years of experience.',
            'English, French, Dutch',
            'Full Member',
            'john.doe@uems.eu',
            'Active member since 2024',
            '2024-05-15',
            'Internal notes go here',
            'Active'
        ];
        
        // Clear any output buffers
        if (ob_get_level()) {
            ob_end_clean();
        }
        
        // Set headers for file download
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="eap-member-import-template.csv"');
        header('Pragma: no-cache');
        header('Expires: 0');
        
        // Create file pointer connected to output stream
        $output = fopen('php://output', 'w');
        
        // Output the column headings
        fputcsv($output, $headers);
        
        // Output the sample row
        fputcsv($output, $sample);
        
        fclose($output);
        exit;
    }
}
add_action('admin_init', 'eap_handle_csv_download');

function eap_handle_xlsx_export() {
    // Check if we are on the right page and the action is set
    if (isset($_GET['page']) && $_GET['page'] === 'eap-member-export' &&
        isset($_GET['action']) && $_GET['action'] === 'export_xlsx' && 
        isset($_GET['nonce']) && wp_verify_nonce($_GET['nonce'], 'eap_export_members')) {
        
        // Run the export function
        eap_export_members_xlsx();
        
        // Stop any further output
        exit;
    }
}
add_action('admin_init', 'eap_handle_xlsx_export');


/**
 * Process CSV import
 */
function eap_process_csv_import() {
    $result = [
        'success' => false,
        'message' => '',
        'details' => []
    ];
    
    // Validate file upload
    if (!isset($_FILES['eap_csv_file']) || $_FILES['eap_csv_file']['error'] !== UPLOAD_ERR_OK) {
        $result['message'] = 'File upload failed. Please try again.';
        return $result;
    }
    
    $file = $_FILES['eap_csv_file']['tmp_name'];
    
    // Validate file type
    $file_info = finfo_open(FILEINFO_MIME_TYPE);
    $mime_type = finfo_file($file_info, $file);
    finfo_close($file_info);
    
    if (!in_array($mime_type, ['text/plain', 'text/csv', 'application/csv', 'application/vnd.ms-excel'])) {
        $result['message'] = 'Invalid file type. Please upload a CSV file.';
        return $result;
    }
    
    // Get options
    $send_emails = isset($_POST['eap_send_emails']) && $_POST['eap_send_emails'] === '1';
    $update_existing = isset($_POST['eap_update_existing']) && $_POST['eap_update_existing'] === '1';
    
    // Parse CSV
    $handle = fopen($file, 'r');
    if (!$handle) {
        $result['message'] = 'Failed to read CSV file.';
        return $result;
    }
    
    // Get headers from first row
    $headers = fgetcsv($handle);
    if (!$headers) {
        $result['message'] = 'CSV file is empty or invalid.';
        fclose($handle);
        return $result;
    }
    
    // Trim headers and convert to lowercase
    $headers = array_map(function($h) { return trim(strtolower($h)); }, $headers);
    
    // Validate required columns
    $required_columns = ['email', 'role'];
    $missing_columns = array_diff($required_columns, $headers);
    if (!empty($missing_columns)) {
        $result['message'] = 'Missing required columns: ' . implode(', ', $missing_columns);
        fclose($handle);
        return $result;
    }
    
    // Process rows
    $row_number = 1; // Start at 1 (header is row 0)
    $created_count = 0;
    $updated_count = 0;
    $skipped_count = 0;
    $error_count = 0;
    
    while (($data = fgetcsv($handle)) !== false) {
        $row_number++;
        
        // Skip empty rows
        if (empty(array_filter($data))) {
            continue;
        }
        
        // Combine headers with data
        $row = array_combine($headers, $data);
        
        // Process this row
        $row_result = eap_import_single_member($row, $send_emails, $update_existing);
        
        if ($row_result['success']) {
            if ($row_result['action'] === 'created') {
                $created_count++;
                $result['details'][] = [
                    'type' => 'SUCCESS',
                    'message' => "Row {$row_number}: Created user '{$row_result['email']}'"
                ];
            } elseif ($row_result['action'] === 'updated') {
                $updated_count++;
                $result['details'][] = [
                    'type' => 'SUCCESS',
                    'message' => "Row {$row_number}: Updated user '{$row_result['email']}'"
                ];
            } else {
                $skipped_count++;
                $result['details'][] = [
                    'type' => 'SKIPPED',
                    'message' => "Row {$row_number}: Skipped user '{$row_result['email']}' (already exists)"
                ];
            }
        } else {
            $error_count++;
            $result['details'][] = [
                'type' => 'ERROR',
                'message' => "Row {$row_number}: {$row_result['message']}"
            ];
        }
    }
    
    fclose($handle);
    
    // Build summary message
    $result['success'] = true;
    $summary_parts = [];
    if ($created_count > 0) $summary_parts[] = "{$created_count} created";
    if ($updated_count > 0) $summary_parts[] = "{$updated_count} updated";
    if ($skipped_count > 0) $summary_parts[] = "{$skipped_count} skipped";
    if ($error_count > 0) $summary_parts[] = "{$error_count} errors";
    
    $result['message'] = 'Import completed: ' . implode(', ', $summary_parts);
    
    // Log the import action
    eap_log_event(
        sprintf('Admin imported members via CSV: %s', $result['message']),
        ['details' => $result['details']],
        'import'
    );
    
    return $result;
}

/**
 * Import a single member from CSV row
 */
function eap_import_single_member($row, $send_email, $update_existing) {
    $result = [
        'success' => false,
        'action' => '',
        'email' => '',
        'message' => ''
    ];
    
    // Validate email
    $email = isset($row['email']) ? sanitize_email(trim($row['email'])) : '';
    if (empty($email) || !is_email($email)) {
        $result['message'] = 'Invalid or missing email address';
        return $result;
    }
    $result['email'] = $email;
    
    // Validate role
    $role = isset($row['role']) ? sanitize_key(trim($row['role'])) : '';
    $valid_roles = eap_get_member_roles();
    if (!in_array($role, $valid_roles)) {
        $result['message'] = "Invalid role '{$role}'. Must be one of: " . implode(', ', $valid_roles);
        return $result;
    }
    
    // Check if user exists
    $user_id = email_exists($email);
    
    if ($user_id) {
        // User exists
        if (!$update_existing) {
            $result['action'] = 'skipped';
            return $result;
        }
        
        // Update existing user
        $user_data = ['ID' => $user_id];
        
        // Update standard fields if provided
        if (!empty($row['first_name'])) {
            $user_data['first_name'] = sanitize_text_field($row['first_name']);
        }
        if (!empty($row['last_name'])) {
            $user_data['last_name'] = sanitize_text_field($row['last_name']);
        }
        
        $user_id = wp_update_user($user_data);
        
        if (is_wp_error($user_id)) {
            $result['message'] = 'Failed to update user: ' . $user_id->get_error_message();
            return $result;
        }
        
        // Update role
        $user = get_userdata($user_id);
        $user->set_role($role);
        
        $result['action'] = 'updated';
        
    } else {
        // Create new user
        $username = $email; // Use email as username
        $password = wp_generate_password(12, true, true);
        
        $user_id = wp_create_user($username, $password, $email);
        
        if (is_wp_error($user_id)) {
            $result['message'] = 'Failed to create user: ' . $user_id->get_error_message();
            return $result;
        }
        
        // Set name fields
        if (!empty($row['first_name'])) {
            update_user_meta($user_id, 'first_name', sanitize_text_field($row['first_name']));
        }
        if (!empty($row['last_name'])) {
            update_user_meta($user_id, 'last_name', sanitize_text_field($row['last_name']));
        }
        
        // Set role
        $user = get_userdata($user_id);
        $user->set_role($role);
        
        // Send activation email
        if ($send_email) {
            wp_new_user_notification($user_id, null, 'user');
            // Also send password reset link
            $reset_key = get_password_reset_key($user);
            if (!is_wp_error($reset_key)) {
                eap_send_activation_email($user_id, $reset_key);
            }
        }
        
        $result['action'] = 'created';
    }
    
    // Update custom meta fields
    $meta_fields = [
        'title_prefix' => 'sanitize_text_field',
        'institution' => 'sanitize_text_field',
        'city' => 'sanitize_text_field',
        'country' => 'absint',
        'preferred_email' => 'sanitize_email',
        'phone' => 'sanitize_text_field',
        'whatsapp_number' => 'sanitize_text_field',
        'term_start' => 'sanitize_text_field',
        'term_end' => 'sanitize_text_field',
        'society' => 'sanitize_text_field',
        'specialty' => 'sanitize_text_field',
        'eap_council' => 'sanitize_key',
        'biography' => 'sanitize_textarea_field',
        'languages' => 'sanitize_text_field',
        'uems_status' => 'sanitize_text_field',
        'uems_email' => 'sanitize_email',
        'uems_notes' => 'sanitize_textarea_field',
        'uems_date_confirmed' => 'sanitize_text_field',
        'internal_notes' => 'sanitize_textarea_field'
    ];
    
    foreach ($meta_fields as $field => $sanitize_func) {
        if (isset($row[$field]) && !empty(trim($row[$field]))) {
            $raw_value = trim($row[$field]);
            
            // Special handling for country field: convert code/name to ID if it's not already an ID
            if ($field === 'country') {
                // Check if the value is numeric (already an ID)
                if (is_numeric($raw_value)) {
                    $value = absint($raw_value);
                } else {
                    // Check if it's a 2-character country code (prioritize this format)
                    if (strlen(trim($raw_value)) === 2) {
                        $country_id = eap_get_country_id_by_code($raw_value);
                        $value = $country_id ? $country_id : 0;
                    } else {
                        // It's a country name (legacy support), convert to ID
                        $country_id = eap_get_country_id_by_name($raw_value);
                        $value = $country_id ? $country_id : 0;
                    }
                }
            } elseif ($field === 'uems_date_confirmed') {
                $value = eap_normalize_date_value($raw_value);
            } else {
                $value = call_user_func($sanitize_func, $raw_value);
            }
            
            if ($value !== 0 || $field !== 'country') { // Don't save 0 for country (invalid)
                update_user_meta($user_id, $field, $value);
            }
        }
    }
    
    // Handle is_active status (Active/Inactive)
    if (isset($row['is_active']) && !empty(trim($row['is_active']))) {
        $status_value = strtolower(trim($row['is_active']));
        // Accept: 'active', 'inactive', '1', '0', 'yes', 'no'
        if (in_array($status_value, ['inactive', '0', 'no'])) {
            update_user_meta($user_id, 'is_active', '0');
        } else {
            update_user_meta($user_id, 'is_active', '1');
        }
    } else {
        // Default to active if not specified
        update_user_meta($user_id, 'is_active', '1');
    }
    
    $result['success'] = true;
    return $result;
}

/**
 * Send activation email to new user
 */
function eap_send_activation_email($user_id, $reset_key) {
    $user = get_userdata($user_id);
    if (!$user) {
        return false;
    }
    
    $first_name = $user->first_name ?: $user->display_name;
    $reset_url = network_site_url("wp-login.php?action=rp&key=$reset_key&login=" . rawurlencode($user->user_login), 'login');
    
    $message = eap_get_activation_email_html($first_name, $reset_url);
    
    $subject = 'EAP National Delegate Profile - Set Your Password';
    $headers = array('Content-Type: text/html; charset=UTF-8');
    
    return wp_mail($user->user_email, $subject, $message, $headers);
}

/**
 * Generate the HTML content for activation emails
 * @param string $first_name The user's first name
 * @param string $reset_url The password reset URL
 * @return string The HTML email content
 */
function eap_get_activation_email_html($first_name, $reset_url) {
    $first_name = esc_html($first_name);
    $reset_url_escaped = esc_url($reset_url);
    $reset_url_display = esc_html($reset_url);
    
    return '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>
<body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333;">
<p>Dear <span>' . $first_name . '</span>,</p>
<p>You are receiving this message because a request was made to set or reset the password for your European Academy of Paediatrics (EAP) National Delegate Profile.</p>
<p>The Member Area provides secure access to your profile, Council and Strategic Advisory Group documents, General Assembly documents, and other resources relevant to your role within EAP. Once your password is created, you will be able to log in to access these materials at any time.</p>
<p>Please use the link below to set a new password:</p>
<p><b><a href="' . $reset_url_escaped . '">' . $reset_url_display . '</a></b></p>
<p>By activating your account, you agree to the Privacy Policy and Terms of Use.</p>
<p>If you did not request this change or if you encounter any difficulties, kindly contact us for assistance at <a href="mailto:secretariat@eapaediatrics.eu">secretariat@eapaediatrics.eu</a>.</p>
<p>With warm regards,<br>
The European Academy of Paediatrics (EAP)</p>
</body>
</html>';
}

/**
 * Get the default privacy setting for a specific field
 * @param string $field_key The field key (e.g., 'country', 'city', etc.)
 * @return string The default privacy setting ('only_me', 'delegates_only', 'all_members', 'admin_only')
 */
function eap_get_default_field_privacy($field_key) {
    $defaults = get_option('eap_privacy_defaults', []);
    return isset($defaults[$field_key]) ? $defaults[$field_key] : 'only_me';
}

/**
 * Render the Privacy Settings admin page
 */
function eap_render_privacy_settings_page() {
    // Handle form submission
    if (isset($_POST['eap_save_privacy_defaults']) && check_admin_referer('eap_privacy_defaults_action', 'eap_privacy_defaults_nonce')) {
        eap_save_privacy_defaults();
        echo '<div class="notice notice-success is-dismissible"><p>Privacy defaults saved successfully.</p></div>';
    }
    
    // Define all fields that have privacy settings
    $fields = [
        'photo_url' => 'Profile Photo',
        'title_prefix' => 'Title/Prefix',
        'institution' => 'Institution',
        'city' => 'City',
        'country' => 'Country',
        'preferred_email' => 'Preferred Email',
        'phone' => 'Phone',
        'whatsapp_number' => 'WhatsApp Number',
        'term_start' => 'Term Start',
        'term_end' => 'Term End',
        'society' => 'Society',
        'specialty' => 'Specialty',
        'eap_council' => 'EAP Council',
        'biography' => 'Biography',
        'languages' => 'Languages'
    ];
    
    // Privacy options
    $privacy_options = [
        'only_me' => 'Only me',
        'delegates_only' => 'Delegates only',
        'all_members' => 'Everyone in portal',
        'admin_only' => 'Admin only'
    ];
    
    $current_defaults = get_option('eap_privacy_defaults', []);
    
    ?>
    <div class="wrap">
        <h1>
            <span class="dashicons dashicons-shield" style="font-size: 32px; vertical-align: middle;"></span>
            Privacy Settings
        </h1>
        
        <p>Configure the default privacy settings for profile fields. These defaults will be applied to new users and when existing users have not yet set their own preferences.</p>
        
        <div class="card" style="max-width: 100%;">
            <h2>Default Field Privacy</h2>
            <p>Choose the default visibility for each profile field. In accordance with GDPR "privacy by default" (Art. 25), it is recommended to keep most fields set to "Only me".</p>
            
            <form method="post" action="">
                <?php wp_nonce_field('eap_privacy_defaults_action', 'eap_privacy_defaults_nonce'); ?>
                
                <table class="wp-list-table widefat fixed striped" style="margin-top: 15px;">
                    <thead>
                        <tr>
                            <th style="width: 30%;">Field</th>
                            <th>Default Visibility</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($fields as $field_key => $field_label): 
                            $current_value = eap_get_default_field_privacy($field_key);
                        ?>
                            <tr>
                                <td><strong><?php echo esc_html($field_label); ?></strong></td>
                                <td>
                                    <select name="eap_privacy_defaults[<?php echo esc_attr($field_key); ?>]" style="width: 300px;">
                                        <?php foreach ($privacy_options as $option_value => $option_label): ?>
                                            <option value="<?php echo esc_attr($option_value); ?>" <?php selected($current_value, $option_value); ?>>
                                                <?php echo esc_html($option_label); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                
                <p style="margin-top: 20px;">
                    <input type="submit" name="eap_save_privacy_defaults" class="button button-primary" value="Save Privacy Defaults">
                </p>
            </form>
        </div>
        
        <div class="card" style="margin-top: 20px;">
            <h3>Privacy Options Explained</h3>
            <ul>
                <li><strong>Only me:</strong> Only the user and administrators can see this field.</li>
                <li><strong>Delegates only:</strong> Visible to National Delegates, Young EAP members, and other member roles (not staff).</li>
                <li><strong>Everyone in portal:</strong> Visible to all portal users including read-only staff.</li>
                <li><strong>Admin only:</strong> Only administrators can see this field (hidden from the member themselves).</li>
            </ul>
            <p><strong>Note:</strong> These are default settings. Individual users can override these defaults for their own profiles through the profile editor.</p>
        </div>
    </div>
    <?php
}

/**
 * Save privacy defaults from the settings form
 */
function eap_save_privacy_defaults() {
    if (!current_user_can('manage_options')) {
        return;
    }
    
    if (isset($_POST['eap_privacy_defaults']) && is_array($_POST['eap_privacy_defaults'])) {
        $defaults = [];
        $valid_options = ['only_me', 'delegates_only', 'all_members', 'admin_only'];
        
        foreach ($_POST['eap_privacy_defaults'] as $field_key => $privacy_value) {
            $field_key = sanitize_key($field_key);
            $privacy_value = sanitize_key($privacy_value);
            
            if (in_array($privacy_value, $valid_options, true)) {
                $defaults[$field_key] = $privacy_value;
            }
        }
        
        update_option('eap_privacy_defaults', $defaults);
        
        // Log the change
        eap_log_event(
            'settings',
            'Privacy defaults updated by ' . wp_get_current_user()->user_login,
            get_current_user_id()
        );
    }
}

function eap_render_import_page() {
    $import_result = null;
    
    // Handle CSV import
    if (isset($_POST['eap_import_submit']) && check_admin_referer('eap_import_members', 'eap_import_nonce')) {
        $import_result = eap_process_csv_import();
    }
    
    ?>
    <div class="wrap">
        <h1>EAP Member Import</h1>
        
        <?php if ($import_result): ?>
            <div class="notice notice-<?php echo $import_result['success'] ? 'success' : 'error'; ?>">
                <h3><?php echo esc_html($import_result['message']); ?></h3>
                <?php if (!empty($import_result['details'])): ?>
                    <div style="max-height: 400px; overflow-y: auto; background: #fff; padding: 10px; margin-top: 10px; border: 1px solid #ccc;">
                        <?php foreach ($import_result['details'] as $detail): ?>
                            <p style="margin: 5px 0;">
                                <strong><?php echo esc_html($detail['type']); ?>:</strong> 
                                <?php echo esc_html($detail['message']); ?>
                            </p>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
        
        <div class="card" style="max-width: 800px; margin-top: 20px;">
            <h2>Step 1: Download CSV Template</h2>
            <p>Download the CSV template to see the required format and field names.</p>
            <a href="<?php echo admin_url('admin.php?page=eap-member-import&action=download_template'); ?>" class="button button-secondary">
                Download CSV Template
            </a>
        </div>
        
        <div class="card" style="max-width: 800px; margin-top: 20px;">
            <h2>Step 2: Upload CSV File</h2>
            <p>Upload a CSV file with member data. The system will create new users or update existing ones based on email address.</p>
            
            <form method="post" enctype="multipart/form-data" style="margin-top: 15px;">
                <?php wp_nonce_field('eap_import_members', 'eap_import_nonce'); ?>
                
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="eap_csv_file">CSV File</label>
                        </th>
                        <td>
                            <input type="file" name="eap_csv_file" id="eap_csv_file" accept=".csv" required>
                            <p class="description">Select a CSV file containing member data.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="eap_send_emails">Send Activation Emails</label>
                        </th>
                        <td>
                            <input type="checkbox" name="eap_send_emails" id="eap_send_emails" value="1" checked>
                            <label for="eap_send_emails">Send password reset emails to new users</label>
                            <p class="description">New users will receive an email to set their password.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="eap_update_existing">Update Existing Users</label>
                        </th>
                        <td>
                            <input type="checkbox" name="eap_update_existing" id="eap_update_existing" value="1" checked>
                            <label for="eap_update_existing">Update existing users if email matches</label>
                            <p class="description">If unchecked, existing users will be skipped.</p>
                        </td>
                    </tr>
                </table>
                
                <p class="submit">
                    <input type="submit" name="eap_import_submit" class="button button-primary" value="Import Members">
                </p>
            </form>
        </div>
        
        <div class="card" style="max-width: 800px; margin-top: 20px;">
            <h2>CSV Format Requirements</h2>
            <p>Your CSV file must include the following columns:</p>
            <ul style="list-style-type: disc; margin-left: 20px;">
                <li><strong>email</strong> (required) - User's email address (used as username)</li>
                <li><strong>first_name</strong> - First name</li>
                <li><strong>last_name</strong> - Last name</li>
                <li><strong>role</strong> (required) - One of: national_delegate, young_eap_member, rep_affiliated_society, rep_uems_subspecialty, read_only_staff</li>
                <li><strong>title_prefix</strong> - Title (e.g., Prof, Dr)</li>
                <li><strong>institution</strong> - Institution name</li>
                <li><strong>city</strong> - City</li>
                <li><strong>country</strong> - Country</li>
                <li><strong>preferred_email</strong> - Alternative email for directory</li>
                <li><strong>phone</strong> - Phone number</li>
                <li><strong>whatsapp_number</strong> - WhatsApp number (optional)</li>
                <li><strong>term_start</strong> - Term start (e.g., 06/2024)</li>
                <li><strong>term_end</strong> - Term end (e.g., 06/2027)</li>
                <li><strong>society</strong> - Society name</li>
                <li><strong>specialty</strong> - Specialty</li>
                <li><strong>eap_council</strong> - One of: pcc, stcc, both</li>
                <li><strong>biography</strong> - Biography text</li>
                <li><strong>languages</strong> - Languages (comma-separated)</li>
                <li><strong>uems_status</strong> - UEMS status (Confirmed – National Delegate, Not yet confirmed – National Delegate, Confirmed – Associate, Confirmed – Observer, N/A, or custom value)</li>
                <li><strong>uems_notes</strong> - UEMS notes</li>
                <li><strong>uems_date_confirmed</strong> - Date (YYYY-MM-DD) when UEMS status was confirmed</li>
                <li><strong>internal_notes</strong> - Internal-only notes for administrators</li>
            </ul>
        </div>
    </div>
    <?php
}

/**
 * Render the Member Export page
 */
function eap_render_export_page() {
    // Get all member roles for filtering
    $member_roles = eap_get_member_roles();
    
    // Get countries from reference table
    $countries = eap_get_countries();
    
    // Count members by role
    $member_counts = [];
    $total_members = 0;
    foreach ($member_roles as $role) {
        $count = count(get_users(['role' => $role]));
        $member_counts[$role] = $count;
        $total_members += $count;
    }
    
    ?>
    <div class="wrap">
        <h1>
            <span class="dashicons dashicons-download" style="font-size: 32px; vertical-align: middle;"></span>
            EAP Member Export
        </h1>
        
        <div class="card" style="max-width: 800px; margin-top: 20px;">
            <h2>Export Members to XLSX</h2>
            <p>Export all members (or filtered members) and their custom meta fields to an Excel (.xlsx) file.</p>
            <p><strong>Total members in system: <?php echo number_format($total_members); ?></strong></p>
            
            <form method="get" style="margin-top: 20px;">
                <input type="hidden" name="page" value="eap-member-export">
                <input type="hidden" name="action" value="export_xlsx">
                <?php wp_nonce_field('eap_export_members', 'nonce'); ?>
                
                <h3>Filter Options</h3>
                <p class="description">Select filters to export specific member groups. Leave all filters empty to export all members.</p>
                
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="filter_role">Filter by Role</label>
                        </th>
                        <td>
                            <select name="filter_role" id="filter_role">
                                <option value="">All Roles</option>
                                <?php foreach ($member_roles as $role): ?>
                                    <option value="<?php echo esc_attr($role); ?>">
                                        <?php echo esc_html(ucwords(str_replace('_', ' ', $role))); ?>
                                        (<?php echo $member_counts[$role]; ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description">Filter by member role</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="filter_country">Filter by Country</label>
                        </th>
                        <td>
                            <select name="filter_country" id="filter_country">
                                <option value="">All Countries</option>
                                <?php foreach ($countries as $country): ?>
                                    <option value="<?php echo esc_attr($country->id); ?>">
                                        <?php echo esc_html($country->country_name); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description">Filter by country</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="filter_active">Filter by Status</label>
                        </th>
                        <td>
                            <select name="filter_active" id="filter_active">
                                <option value="">All Members</option>
                                <option value="active">Active Members Only</option>
                                <option value="inactive">Inactive Members Only</option>
                            </select>
                            <p class="description">Filter by account status</p>
                        </td>
                    </tr>
                </table>
                
                <p class="submit">
                    <button type="submit" class="button button-primary" style="font-size: 14px; padding: 8px 20px;">
                        <span class="dashicons dashicons-download" style="vertical-align: middle; margin-top: 4px;"></span>
                        Export to XLSX
                    </button>
                </p>
            </form>
        </div>
        
        <div class="card" style="max-width: 800px; margin-top: 20px;">
            <h2>Export Details</h2>
            <p>The exported XLSX file will include the following data for each member:</p>
            <ul style="list-style-type: disc; margin-left: 20px;">
                <li><strong>Email</strong> - User's email address (login)</li>
                <li><strong>First Name</strong> - First name</li>
                <li><strong>Last Name</strong> - Last name</li>
                <li><strong>Role</strong> - Member role</li>
                <li><strong>Title Prefix</strong> - Title (e.g., Prof, Dr)</li>
                <li><strong>Institution</strong> - Institution name</li>
                <li><strong>City</strong> - City</li>
                <li><strong>Country</strong> - Country</li>
                <li><strong>Preferred Email</strong> - Alternative email</li>
                <li><strong>Phone</strong> - Phone number</li>
                <li><strong>WhatsApp Number</strong> - WhatsApp contact</li>
                <li><strong>Term Start</strong> - Term start date</li>
                <li><strong>Term End</strong> - Term end date</li>
                <li><strong>Society</strong> - Society name</li>
                <li><strong>Specialty</strong> - Specialty</li>
                <li><strong>EAP Council</strong> - Council type</li>
                <li><strong>Biography</strong> - Biography text</li>
                <li><strong>Languages</strong> - Languages spoken</li>
                <li><strong>UEMS Delegate Status</strong> - UEMS status (Confirmed – National Delegate, Not yet confirmed – National Delegate, Confirmed – Associate, Confirmed – Observer, N/A, or custom value)</li>
                <li><strong>UEMS Email</strong> - UEMS email address</li>
                <li><strong>UEMS Notes</strong> - UEMS notes</li>
                <li><strong>UEMS Date Confirmed</strong> - Confirmation date stored as YYYY-MM-DD</li>
                <li><strong>Internal Notes</strong> - Private admin-only notes</li>
                <li><strong>Account Status</strong> - Active/Inactive</li>
                <li><strong>Last Login</strong> - Last login date</li>
                <li><strong>Registered Date</strong> - Account creation date</li>
            </ul>
            <p><strong>Note:</strong> The exported file can be re-imported using the Member Import feature if needed.</p>
        </div>
        
        <div class="card" style="max-width: 800px; margin-top: 20px;">
            <h2>Privacy & GDPR Compliance</h2>
            <p><strong>⚠️ Important:</strong> The exported file contains personal data. Please handle it securely and in compliance with GDPR regulations:</p>
            <ul style="list-style-type: disc; margin-left: 20px;">
                <li>Only export data when necessary for legitimate purposes</li>
                <li>Store exported files securely (encrypted storage recommended)</li>
                <li>Delete exported files when no longer needed</li>
                <li>Do not share exported files with unauthorized parties</li>
                <li>Export actions are logged in the Audit Log for compliance tracking</li>
            </ul>
        </div>
    </div>
    <?php
}

/**
 * Export members to XLSX file
 * Requires PhpSpreadsheet library
 */
function eap_export_members_xlsx() {
    // Check if PhpSpreadsheet is available
    if (!class_exists('\PhpOffice\PhpSpreadsheet\Spreadsheet')) {
        wp_die('PhpSpreadsheet library is required. Please install it via Composer: composer require phpoffice/phpspreadsheet');
    }
    
    // Get filter parameters
    $filter_role = isset($_GET['filter_role']) ? sanitize_key($_GET['filter_role']) : '';
    $filter_country = isset($_GET['filter_country']) ? absint($_GET['filter_country']) : 0;
    $filter_active = isset($_GET['filter_active']) ? sanitize_key($_GET['filter_active']) : '';
    
    // Build user query arguments
    $args = [
        'role__in' => eap_get_member_roles(),
        'orderby' => 'registered',
        'order' => 'DESC',
        'number' => -1 // Get all users
    ];
    
    // Apply role filter
    if (!empty($filter_role)) {
        $args['role__in'] = [$filter_role];
    }
    
    // Apply country filter (requires meta_query)
    if (!empty($filter_country)) {
        $args['meta_query'] = [
            [
                'key' => 'country',
                'value' => $filter_country,
                'compare' => '='
            ]
        ];
    }
    
    // Get users
    $users = get_users($args);
    
    // Filter by active status if specified
    if (!empty($filter_active)) {
        $users = array_filter($users, function($user) use ($filter_active) {
            $is_active = get_user_meta($user->ID, 'is_active', true);
            if ($filter_active === 'active') {
                return $is_active === '1' || $is_active === true || $is_active === '';
            } else {
                return $is_active === '0' || $is_active === false;
            }
        });
    }
    
    // Create new Spreadsheet
    $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('EAP Members');
    
    // Define headers
    $headers = [
        'Email', 'First Name', 'Last Name', 'Role', 'Title Prefix', 
        'Institution', 'City', 'Country', 'Preferred Email', 'Phone', 'WhatsApp Number',
        'Term Start', 'Term End', 'Society', 'Specialty', 'EAP Council',
        'Biography', 'Languages', 'UEMS Delegate Status', 'UEMS Email', 'UEMS Notes',
        'UEMS Date Confirmed', 'Internal Notes', 'Account Status', 'Last Login', 'Registered Date'
    ];
    
    // Write headers
    $col = 1;
    foreach ($headers as $header) {
        $colLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col);
        $sheet->setCellValue($colLetter . '1', $header);
        $col++;
    }
    
    // Style headers
    $headerStyle = [
        'font' => [
            'bold' => true,
            'color' => ['rgb' => 'FFFFFF']
        ],
        'fill' => [
            'fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
            'startColor' => ['rgb' => '4472C4']
        ]
    ];
    $lastColLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(count($headers));
    $sheet->getStyle('A1:' . $lastColLetter . '1')->applyFromArray($headerStyle);
    
    // Write data rows
    $row = 2;
    foreach ($users as $user) {
        $col = 1;
        $sheet->setCellValue(\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col++) . $row, $user->user_email);
        $sheet->setCellValue(\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col++) . $row, get_user_meta($user->ID, 'first_name', true));
        $sheet->setCellValue(\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col++) . $row, get_user_meta($user->ID, 'last_name', true));
        $sheet->setCellValue(\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col++) . $row, implode(', ', $user->roles));
        $sheet->setCellValue(\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col++) . $row, get_user_meta($user->ID, 'title_prefix', true));
        $sheet->setCellValue(\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col++) . $row, get_user_meta($user->ID, 'institution', true));
        $sheet->setCellValue(\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col++) . $row, get_user_meta($user->ID, 'city', true));
        $country_id = get_user_meta($user->ID, 'country', true);
        $sheet->setCellValue(\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col++) . $row, $country_id ? eap_get_country_code($country_id) : '');
        $sheet->setCellValue(\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col++) . $row, get_user_meta($user->ID, 'preferred_email', true));
        $sheet->setCellValue(\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col++) . $row, get_user_meta($user->ID, 'phone', true));
        $sheet->setCellValue(\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col++) . $row, get_user_meta($user->ID, 'whatsapp_number', true));
        $sheet->setCellValue(\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col++) . $row, get_user_meta($user->ID, 'term_start', true));
        $sheet->setCellValue(\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col++) . $row, get_user_meta($user->ID, 'term_end', true));
        $sheet->setCellValue(\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col++) . $row, get_user_meta($user->ID, 'society', true));
        $sheet->setCellValue(\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col++) . $row, get_user_meta($user->ID, 'specialty', true));
        $sheet->setCellValue(\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col++) . $row, get_user_meta($user->ID, 'eap_council', true));
        $sheet->setCellValue(\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col++) . $row, get_user_meta($user->ID, 'biography', true));
        $sheet->setCellValue(\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col++) . $row, get_user_meta($user->ID, 'languages', true));
        $sheet->setCellValue(\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col++) . $row, get_user_meta($user->ID, 'uems_status', true));
        $sheet->setCellValue(\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col++) . $row, get_user_meta($user->ID, 'uems_email', true));
        $sheet->setCellValue(\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col++) . $row, get_user_meta($user->ID, 'uems_notes', true));
        $sheet->setCellValue(\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col++) . $row, get_user_meta($user->ID, 'uems_date_confirmed', true));
        $sheet->setCellValue(\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col++) . $row, get_user_meta($user->ID, 'internal_notes', true));
        
        $is_active = get_user_meta($user->ID, 'is_active', true);
        $sheet->setCellValue(\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col++) . $row, ($is_active === '0' || $is_active === false) ? 'Inactive' : 'Active');
        
        $last_login = get_user_meta($user->ID, 'last_login', true);
        $sheet->setCellValue(\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col++) . $row, $last_login ? date('Y-m-d H:i:s', $last_login) : '');
        
        $sheet->setCellValue(\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col++) . $row, $user->user_registered);
        
        $row++;
    }
    
    // Auto-size columns
    foreach (range('A', $sheet->getHighestColumn()) as $col) {
        $sheet->getColumnDimension($col)->setAutoSize(true);
    }
    
    // Prepare filename with filters
    $filename_parts = ['eap-members-export'];
    if (!empty($filter_role)) {
        $filename_parts[] = $filter_role;
    }
    if (!empty($filter_country)) {
        $country_name = eap_get_country_name($filter_country);
        $filename_parts[] = sanitize_file_name($country_name);
    }
    if (!empty($filter_active)) {
        $filename_parts[] = $filter_active;
    }
    $filename_parts[] = date('Y-m-d-His');
    $filename = implode('-', $filename_parts) . '.xlsx';
    
    // Log the export action
    $filter_description = [];
    if (!empty($filter_role)) {
        $filter_description[] = "role: $filter_role";
    }
    if (!empty($filter_country)) {
        $country_name = eap_get_country_name($filter_country);
        $filter_description[] = "country: $country_name";
    }
    if (!empty($filter_active)) {
        $filter_description[] = "status: $filter_active";
    }
    $filter_text = !empty($filter_description) ? ' (Filters: ' . implode(', ', $filter_description) . ')' : '';
    
    eap_log_event(
        'Exported ' . count($users) . ' members to XLSX' . $filter_text,
        [
            'filters' => $filter_description,
            'count' => count($users),
            'filename' => $filename
        ],
        'export'
    );
    
    // Set headers for file download
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: max-age=0');
    
    // Write file to output
    $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
    $writer->save('php://output');
    
    // Clean up
    $spreadsheet->disconnectWorksheets();
    unset($spreadsheet);
}

/**
 * Render the comprehensive Audit Log admin page
 */
function eap_render_audit_log_page() {
    // Handle actions (export, clear)
    if (isset($_GET['action'])) {
        if ($_GET['action'] === 'export_csv' && check_admin_referer('eap_export_logs', 'nonce')) {
            eap_export_audit_logs_csv();
            exit;
        }
        if ($_GET['action'] === 'clear_logs' && check_admin_referer('eap_clear_logs', 'nonce')) {
            eap_clear_audit_logs();
            wp_redirect(admin_url('admin.php?page=eap-audit-log&cleared=1'));
            exit;
        }
    }
    
    global $wpdb;
    $table_name = $wpdb->prefix . 'eap_audit_log';
    
    // Get filter parameters
    $filter_user = isset($_GET['filter_user']) ? sanitize_text_field($_GET['filter_user']) : '';
    $filter_type = isset($_GET['filter_type']) ? sanitize_key($_GET['filter_type']) : '';
    $filter_date_from = isset($_GET['filter_date_from']) ? sanitize_text_field($_GET['filter_date_from']) : '';
    $filter_date_to = isset($_GET['filter_date_to']) ? sanitize_text_field($_GET['filter_date_to']) : '';
    $search_query = isset($_GET['search']) ? sanitize_text_field($_GET['search']) : '';
    
    // Build WHERE clause for filters
    $where_clauses = ['1=1'];
    $where_values = [];
    
    if (!empty($filter_user)) {
        $where_clauses[] = '(user_login LIKE %s OR user_email LIKE %s OR user_id = %d)';
        $where_values[] = '%' . $wpdb->esc_like($filter_user) . '%';
        $where_values[] = '%' . $wpdb->esc_like($filter_user) . '%';
        $where_values[] = intval($filter_user);
    }
    
    if (!empty($filter_type)) {
        $where_clauses[] = 'event_type = %s';
        $where_values[] = $filter_type;
    }
    
    if (!empty($filter_date_from)) {
        $where_clauses[] = 'timestamp >= %d';
        $where_values[] = strtotime($filter_date_from . ' 00:00:00');
    }
    
    if (!empty($filter_date_to)) {
        $where_clauses[] = 'timestamp <= %d';
        $where_values[] = strtotime($filter_date_to . ' 23:59:59');
    }
    
    if (!empty($search_query)) {
        $where_clauses[] = '(message LIKE %s OR user_login LIKE %s OR ip_address LIKE %s)';
        $where_values[] = '%' . $wpdb->esc_like($search_query) . '%';
        $where_values[] = '%' . $wpdb->esc_like($search_query) . '%';
        $where_values[] = '%' . $wpdb->esc_like($search_query) . '%';
    }
    
    $where_sql = implode(' AND ', $where_clauses);
    
    // Get total count
    if (!empty($where_values)) {
        $total_logs = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table_name WHERE $where_sql",
            $where_values
        ));
    } else {
        $total_logs = $wpdb->get_var("SELECT COUNT(*) FROM $table_name WHERE $where_sql");
    }
    
    // Get all logs count (for stats)
    $all_logs_count = $wpdb->get_var("SELECT COUNT(*) FROM $table_name");
    
    // Pagination
    $per_page = 25;
    $current_page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
    $total_pages = ceil($total_logs / $per_page);
    $offset = ($current_page - 1) * $per_page;
    
    // Get paginated logs
    if (!empty($where_values)) {
        $paginated_logs = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table_name WHERE $where_sql ORDER BY id DESC LIMIT %d OFFSET %d",
            array_merge($where_values, [$per_page, $offset])
        ), ARRAY_A);
    } else {
        $paginated_logs = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table_name WHERE $where_sql ORDER BY id DESC LIMIT %d OFFSET %d",
            [$per_page, $offset]
        ), ARRAY_A);
    }
    
    // Decode JSON data for display
    foreach ($paginated_logs as &$log) {
        if (!empty($log['data'])) {
            $log['data'] = json_decode($log['data'], true);
        }
    }
    
    // Get unique event types for filter dropdown
    $event_types = $wpdb->get_col("SELECT DISTINCT event_type FROM $table_name ORDER BY event_type ASC");
    
    ?>
    <div class="wrap eap-audit-log-page">
        <h1>
            <span class="dashicons dashicons-shield" style="font-size: 32px; vertical-align: middle;"></span>
            EAP Audit Log
        </h1>
        
        <?php if (isset($_GET['cleared'])): ?>
            <div class="notice notice-success is-dismissible">
                <p><strong>Success:</strong> Audit logs have been cleared.</p>
            </div>
        <?php endif; ?>
        
        <div class="notice notice-success">
            <p>
                <strong>Storage Method:</strong> Using dedicated database table <code><?php echo esc_html($table_name); ?></code> for optimal performance.
                <br><strong>Total Entries:</strong> <?php echo number_format($all_logs_count); ?> | 
                <strong>Displayed:</strong> <?php echo number_format($total_logs); ?>
                <?php if ($all_logs_count != $total_logs): ?>
                    (filtered)
                <?php endif; ?>
            </p>
        </div>
        
        <!-- Action Buttons -->
        <div class="eap-audit-actions" style="margin: 20px 0;">
            <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=eap-audit-log&action=export_csv'), 'eap_export_logs', 'nonce'); ?>" 
               class="button button-secondary">
                <span class="dashicons dashicons-download" style="vertical-align: middle;"></span> Export to CSV
            </a>
            
            <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=eap-audit-log&action=clear_logs'), 'eap_clear_logs', 'nonce'); ?>" 
               class="button button-secondary" 
               onclick="return confirm('Are you sure you want to clear all audit logs? This action cannot be undone.');"
               style="margin-left: 10px;">
                <span class="dashicons dashicons-trash" style="vertical-align: middle;"></span> Clear All Logs
            </a>
        </div>
        
        <!-- Filters -->
        <div class="eap-audit-filters">
            <h3>Filter Logs</h3>
            <form method="get" action="" class="eap-filter-form">
                <input type="hidden" name="page" value="eap-audit-log">
                
                <div class="eap-filter-row">
                    <div class="eap-filter-field">
                        <label for="search">Search:</label>
                        <input type="text" id="search" name="search" value="<?php echo esc_attr($search_query); ?>" 
                               placeholder="Search message, user, IP..." style="width: 250px;">
                    </div>
                    
                    <div class="eap-filter-field">
                        <label for="filter_type">Event Type:</label>
                        <select id="filter_type" name="filter_type">
                            <option value="">All Types</option>
                            <?php foreach ($event_types as $type): ?>
                                <option value="<?php echo esc_attr($type); ?>" <?php selected($filter_type, $type); ?>>
                                    <?php echo esc_html(ucfirst($type)); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="eap-filter-field">
                        <label for="filter_user">User:</label>
                        <input type="text" id="filter_user" name="filter_user" value="<?php echo esc_attr($filter_user); ?>" 
                               placeholder="Username, email or ID">
                    </div>
                </div>
                
                <div class="eap-filter-row">
                    <div class="eap-filter-field">
                        <label for="filter_date_from">Date From:</label>
                        <input type="date" id="filter_date_from" name="filter_date_from" 
                               value="<?php echo esc_attr($filter_date_from); ?>">
                    </div>
                    
                    <div class="eap-filter-field">
                        <label for="filter_date_to">Date To:</label>
                        <input type="date" id="filter_date_to" name="filter_date_to" 
                               value="<?php echo esc_attr($filter_date_to); ?>">
                    </div>
                    
                    <div class="eap-filter-field">
                        <button type="submit" class="button button-primary">Apply Filters</button>
                        <a href="<?php echo admin_url('admin.php?page=eap-audit-log'); ?>" class="button">Clear Filters</a>
                    </div>
                </div>
            </form>
        </div>
        
        <!-- Logs Table -->
        <?php if (empty($paginated_logs)): ?>
            <div class="eap-no-logs">
                <p><em>No audit log entries found matching your criteria.</em></p>
            </div>
        <?php else: ?>
            <table class="wp-list-table widefat fixed striped eap-audit-table">
                <thead>
                    <tr>
                        <th style="width: 150px;">Date/Time</th>
                        <th style="width: 100px;">Event Type</th>
                        <th>Message</th>
                        <th style="width: 150px;">User</th>
                        <th style="width: 120px;">IP Address</th>
                        <th style="width: 80px;">Details</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($paginated_logs as $log): ?>
                        <tr>
                            <td><?php echo esc_html(date('Y-m-d H:i:s', $log['timestamp'])); ?></td>
                            <td>
                                <span class="eap-event-badge eap-event-<?php echo esc_attr($log['event_type']); ?>">
                                    <?php echo esc_html(ucfirst($log['event_type'])); ?>
                                </span>
                            </td>
                            <td><?php echo esc_html($log['message']); ?></td>
                            <td>
                                <?php if ($log['user_id']): ?>
                                    <a href="<?php echo admin_url('user-edit.php?user_id=' . $log['user_id']); ?>" target="_blank">
                                        <?php echo esc_html($log['user_login']); ?>
                                    </a>
                                    <br><small><?php echo esc_html($log['user_email']); ?></small>
                                <?php else: ?>
                                    <em>Guest</em>
                                <?php endif; ?>
                            </td>
                            <td>
                                <code><?php echo esc_html($log['ip_address']); ?></code>
                            </td>
                            <td>
                                <button type="button" class="button button-small eap-view-details" 
                                        data-log-id="<?php echo esc_attr($log['id']); ?>">
                                    View
                                </button>
                                
                                <!-- Hidden details panel -->
                                <div id="eap-details-<?php echo esc_attr($log['id']); ?>" class="eap-log-details" style="display:none;">
                                    <div class="eap-details-content">
                                        <h4>Log Entry Details</h4>
                                        <table class="eap-details-table">
                                                <tr>
                                                    <th>Entry ID:</th>
                                                    <td><code><?php echo esc_html($log['event_id']); ?></code></td>
                                                </tr>
                                            <tr>
                                                <th>User Agent:</th>
                                                <td><code><?php echo esc_html($log['user_agent']); ?></code></td>
                                            </tr>
                                            <tr>
                                                <th>Request URI:</th>
                                                <td><code><?php echo esc_html($log['request_uri']); ?></code></td>
                                            </tr>
                                            <?php if (!empty($log['data'])): ?>
                                                <tr>
                                                    <th>Additional Data:</th>
                                                    <td>
                                                        <pre style="background: #f5f5f5; padding: 10px; overflow-x: auto; max-width: 600px;"><?php 
                                                            echo esc_html(print_r($log['data'], true)); 
                                                        ?></pre>
                                                    </td>
                                                </tr>
                                            <?php endif; ?>
                                        </table>
                                        <button type="button" class="button eap-close-details">Close</button>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            
            <!-- Pagination -->
            <?php if ($total_pages > 1): ?>
                <div class="eap-audit-pagination">
                    <?php
                    $base_url = admin_url('admin.php?page=eap-audit-log');
                    $query_args = [];
                    if ($filter_user) $query_args['filter_user'] = $filter_user;
                    if ($filter_type) $query_args['filter_type'] = $filter_type;
                    if ($filter_date_from) $query_args['filter_date_from'] = $filter_date_from;
                    if ($filter_date_to) $query_args['filter_date_to'] = $filter_date_to;
                    if ($search_query) $query_args['search'] = $search_query;
                    
                    echo '<div class="tablenav-pages">';
                    echo '<span class="displaying-num">' . sprintf('%d items', $total_logs) . '</span>';
                    
                    echo paginate_links([
                        'base' => add_query_arg(array_merge($query_args, ['paged' => '%#%']), $base_url),
                        'format' => '',
                        'current' => $current_page,
                        'total' => $total_pages,
                        'prev_text' => '&laquo; Previous',
                        'next_text' => 'Next &raquo;',
                    ]);
                    echo '</div>';
                    ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>
        
        <!-- JavaScript for details toggle -->
        <script type="text/javascript">
        jQuery(document).ready(function($) {
            $('.eap-view-details').on('click', function() {
                var logId = $(this).data('log-id');
                var detailsPanel = $('#eap-details-' + logId);
                
                // Hide all other panels
                $('.eap-log-details').not(detailsPanel).hide();
                
                // Toggle this panel
                detailsPanel.toggle();
                
                // Scroll to the panel
                if (detailsPanel.is(':visible')) {
                    $('html, body').animate({
                        scrollTop: detailsPanel.offset().top - 100
                    }, 300);
                }
            });
            
            $('.eap-close-details').on('click', function() {
                $(this).closest('.eap-log-details').hide();
            });
        });
        </script>
    </div>
    <?php
}

/**
 * Enhanced Audit Log Function
 * Logs events to custom database table with comprehensive context information.
 * 
 * This uses a dedicated database table for better performance and scalability:
 * - Suitable for high-traffic sites
 * - Unlimited storage capacity (database constraints only)
 * - Advanced querying capabilities with indexes
 * - Better performance than wp_options
 * 
 * @param string $message The log message describing the event
 * @param array $data Additional context data (changes, metadata, etc.)
 * @param string $event_type Optional event type for categorization (default: 'general')
 * @return bool|int Returns insert ID on success, false on failure
 */
function eap_log_event($message, $data = [], $event_type = 'general') {
    global $wpdb;
    $table_name = $wpdb->prefix . 'eap_audit_log';
    
    // Get current user information
    $user_id = get_current_user_id();
    $user = $user_id ? get_userdata($user_id) : null;
    
    // Capture request context
    $ip_address = eap_get_client_ip();
    $user_agent = isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field($_SERVER['HTTP_USER_AGENT']) : '';
    $request_uri = isset($_SERVER['REQUEST_URI']) ? sanitize_text_field($_SERVER['REQUEST_URI']) : '';
    
    // Prepare data for insertion
    $insert_data = [
        'event_id' => uniqid('eap_', true),
        'message' => sanitize_text_field($message),
        'event_type' => sanitize_key($event_type),
        'timestamp' => current_time('timestamp'),
        'time' => current_time('mysql'),
        'user_id' => $user_id ?: null,
        'user_login' => $user ? $user->user_login : 'guest',
        'user_email' => $user ? $user->user_email : '',
        'ip_address' => $ip_address,
        'user_agent' => $user_agent,
        'request_uri' => $request_uri,
        'data' => !empty($data) ? json_encode($data) : null
    ];
    
    // Insert into database
    $result = $wpdb->insert($table_name, $insert_data, [
        '%s', // event_id
        '%s', // message
        '%s', // event_type
        '%d', // timestamp
        '%s', // time
        '%d', // user_id
        '%s', // user_login
        '%s', // user_email
        '%s', // ip_address
        '%s', // user_agent
        '%s', // request_uri
        '%s'  // data
    ]);
    
    return $result ? $wpdb->insert_id : false;
}

/**
 * Get client IP address with proxy support
 */
function eap_get_client_ip() {
    $ip = '';
    
    if (isset($_SERVER['HTTP_CLIENT_IP'])) {
        $ip = $_SERVER['HTTP_CLIENT_IP'];
    } elseif (isset($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
    } elseif (isset($_SERVER['HTTP_X_FORWARDED'])) {
        $ip = $_SERVER['HTTP_X_FORWARDED'];
    } elseif (isset($_SERVER['HTTP_FORWARDED_FOR'])) {
        $ip = $_SERVER['HTTP_FORWARDED_FOR'];
    } elseif (isset($_SERVER['HTTP_FORWARDED'])) {
        $ip = $_SERVER['HTTP_FORWARDED'];
    } elseif (isset($_SERVER['REMOTE_ADDR'])) {
        $ip = $_SERVER['REMOTE_ADDR'];
    }
    
    // Sanitize and validate
    $ip = sanitize_text_field($ip);
    
    // If comma-separated (proxies), get the first one
    if (strpos($ip, ',') !== false) {
        $ip_array = explode(',', $ip);
        $ip = trim($ip_array[0]);
    }
    
    return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : 'unknown';
}

/**
 * Clear all audit logs (admin function)
 */
function eap_clear_audit_logs() {
    if (!current_user_can('manage_options')) {
        return false;
    }
    
    global $wpdb;
    $table_name = $wpdb->prefix . 'eap_audit_log';
    
    // Log the clearing action before deleting
    eap_log_event('Audit logs cleared by administrator', [], 'system');
    
    // Delete all logs except the one we just created
    $latest_id = $wpdb->get_var("SELECT id FROM $table_name ORDER BY id DESC LIMIT 1");
    
    if ($latest_id) {
        return $wpdb->query($wpdb->prepare(
            "DELETE FROM $table_name WHERE id < %d",
            $latest_id
        ));
    }
    
    return false;
}

/**
 * Export audit logs to CSV
 */
function eap_export_audit_logs_csv() {
    if (!current_user_can('manage_options')) {
        wp_die('Unauthorized access', 'Access Denied', 403);
    }
    
    global $wpdb;
    $table_name = $wpdb->prefix . 'eap_audit_log';
    
    // Get all logs
    $logs = $wpdb->get_results("SELECT * FROM $table_name ORDER BY id DESC", ARRAY_A);
    
    if (empty($logs)) {
        wp_die('No logs to export', 'No Data', 404);
    }
    
    // Set headers for CSV download
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="eap-audit-log-' . date('Y-m-d-His') . '.csv"');
    
    // Create file pointer
    $output = fopen('php://output', 'w');
    
    // Output column headings
    fputcsv($output, [
        'ID',
        'Time',
        'Event Type',
        'Message',
        'User ID',
        'User Login',
        'User Email',
        'IP Address',
        'User Agent',
        'Request URI',
        'Additional Data'
    ]);
    
    // Output data rows
    foreach ($logs as $log) {
        fputcsv($output, [
            $log['id'],
            $log['time'],
            $log['event_type'],
            $log['message'],
            $log['user_id'],
            $log['user_login'],
            $log['user_email'],
            $log['ip_address'],
            $log['user_agent'],
            $log['request_uri'],
            $log['data']
        ]);
    }
    
    fclose($output);
    exit;
}

// Hook into login to log it
function eap_log_user_login( $user_login, $user ) {
    eap_log_event(
        sprintf('User "%s" (ID: %d) logged in.', $user_login, $user->ID),
        ['user_roles' => $user->roles],
        'login'
    );
}
add_action('wp_login', 'eap_log_user_login', 10, 2);

// Log failed login attempts
function eap_log_failed_login( $username ) {
    eap_log_event(
        sprintf('Failed login attempt for username: %s', $username),
        ['attempted_username' => $username],
        'security'
    );
}
add_action('wp_login_failed', 'eap_log_failed_login');

// Log user logout
function eap_log_user_logout() {
    $user = wp_get_current_user();
    if ($user->ID) {
        eap_log_event(
            sprintf('User "%s" (ID: %d) logged out.', $user->user_login, $user->ID),
            [],
            'login'
        );
    }
}
add_action('wp_logout', 'eap_log_user_logout');

// Log new user creation
function eap_log_user_register( $user_id ) {
    $user = get_userdata($user_id);
    if ($user) {
        eap_log_event(
            sprintf('New user registered: "%s" (ID: %d)', $user->user_login, $user_id),
            [
                'user_email' => $user->user_email,
                'user_roles' => $user->roles
            ],
            'user'
        );
    }
}
add_action('user_register', 'eap_log_user_register');

// Log user deletion
function eap_log_user_delete( $user_id, $reassign ) {
    $user = get_userdata($user_id);
    if ($user) {
        eap_log_event(
            sprintf('User deleted: "%s" (ID: %d)', $user->user_login, $user_id),
            [
                'user_email' => $user->user_email,
                'user_roles' => $user->roles,
                'reassign_to' => $reassign
            ],
            'user'
        );
    }
}
add_action('delete_user', 'eap_log_user_delete', 10, 2);

// Log role changes
function eap_log_role_change( $user_id, $role, $old_roles ) {
    $user = get_userdata($user_id);
    if ($user) {
        eap_log_event(
            sprintf('User role changed for "%s" (ID: %d)', $user->user_login, $user_id),
            [
                'old_roles' => $old_roles,
                'new_role' => $role,
                'user_email' => $user->user_email
            ],
            'user'
        );
    }
}
add_action('set_user_role', 'eap_log_role_change', 10, 3);

// Log post/archive creation
function eap_log_post_save( $post_id, $post, $update ) {
    // Only log our custom post type
    if ($post->post_type !== 'eap_working_group') {
        return;
    }
    
    // Don't log autosaves
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
        return;
    }
    
    $action = $update ? 'updated' : 'created';
    eap_log_event(
        sprintf('%s %s: "%s" (ID: %d)', 
            ucfirst($action),
            'Working Group',
            $post->post_title,
            $post_id
        ),
        [
            'post_id' => $post_id,
            'post_type' => $post->post_type,
            'post_status' => $post->post_status,
            'action' => $action
        ],
        'general'
    );
}
add_action('save_post', 'eap_log_post_save', 10, 3);

// Log post deletion
function eap_log_post_delete( $post_id ) {
    $post = get_post($post_id);
    
    // Only log our custom post type
    if (!$post || $post->post_type !== 'eap_working_group') {
        return;
    }
    
    eap_log_event(
        sprintf('Deleted %s: "%s" (ID: %d)', 
            'Working Group',
            $post->post_title,
            $post_id
        ),
        [
            'post_id' => $post_id,
            'post_type' => $post->post_type,
            'post_title' => $post->post_title
        ],
        'general'
    );
}
add_action('before_delete_post', 'eap_log_post_delete');


// === DELEGATE ADMINISTRATION PAGE ===

/**
 * Render the Delegate Administration page
 */
function eap_render_delegate_admin_page() {
    // Get filter parameters
    $filter_council = isset($_GET['filter_council']) ? sanitize_key($_GET['filter_council']) : '';
    $filter_active_term = isset($_GET['filter_active_term']) ? sanitize_key($_GET['filter_active_term']) : '';
    $filter_active_status = isset($_GET['filter_active_status']) ? sanitize_key($_GET['filter_active_status']) : '';
    $search_query = isset($_GET['search']) ? sanitize_text_field($_GET['search']) : '';
    
    // Get all delegate members (all roles except staff)
    $delegate_roles = eap_get_member_roles();
    $delegate_roles = array_diff($delegate_roles, ['read_only_staff']);
    
    $args = [
        'role__in' => $delegate_roles,
        'orderby' => 'display_name',
        'order' => 'ASC',
        'number' => -1, // Get all users for filtering
    ];
    
    $users = get_users($args);
    
    // Apply filters
    $filtered_users = [];
    
    foreach ($users as $user) {
        // Filter by council (PCC/STCC/PCC & STCC)
        if (!empty($filter_council)) {
            $council = get_user_meta($user->ID, 'eap_council', true);
            if ($council !== $filter_council) {
                continue;
            }
        }
        
        // Filter by active term
        if (!empty($filter_active_term)) {
            $term_start = get_user_meta($user->ID, 'term_start', true);
            $term_end = get_user_meta($user->ID, 'term_end', true);
            $has_active_term = eap_has_active_term($term_start, $term_end);
            
            if ($filter_active_term === 'yes' && !$has_active_term) {
                continue;
            }
            if ($filter_active_term === 'no' && $has_active_term) {
                continue;
            }
        }
        
        // Filter by active status
        if (!empty($filter_active_status)) {
            $is_active = get_user_meta($user->ID, 'is_active', true);
            $is_active = ($is_active === '0' || $is_active === false) ? 'no' : 'yes';
            
            if ($filter_active_status !== $is_active) {
                continue;
            }
        }
        
        // Search filter
        if (!empty($search_query)) {
            $first_name = get_user_meta($user->ID, 'first_name', true);
            $last_name = get_user_meta($user->ID, 'last_name', true);
            $institution = get_user_meta($user->ID, 'institution', true);
            $society = get_user_meta($user->ID, 'society', true);
            $country_id = get_user_meta($user->ID, 'country', true);
            $country = $country_id ? eap_get_country_name($country_id) : '';
            
            $search_string = strtolower($search_query);
            $match = false;
            
            if (stripos($first_name, $search_string) !== false ||
                stripos($last_name, $search_string) !== false ||
                stripos($user->user_email, $search_string) !== false ||
                stripos($institution, $search_string) !== false ||
                stripos($society, $search_string) !== false ||
                stripos($country, $search_string) !== false) {
                $match = true;
            }
            
            if (!$match) {
                continue;
            }
        }
        
        $filtered_users[] = $user;
    }
    
    // Pagination
    $per_page = 50;
    $current_page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
    $total_users = count($filtered_users);
    $total_pages = ceil($total_users / $per_page);
    $offset = ($current_page - 1) * $per_page;
    $paginated_users = array_slice($filtered_users, $offset, $per_page);
    
    // Build pagination URL
    $base_url = admin_url('admin.php?page=eap-delegate-admin');
    $query_params = [];
    if (!empty($filter_council)) $query_params['filter_council'] = $filter_council;
    if (!empty($filter_active_term)) $query_params['filter_active_term'] = $filter_active_term;
    if (!empty($filter_active_status)) $query_params['filter_active_status'] = $filter_active_status;
    if (!empty($search_query)) $query_params['search'] = $search_query;

    $countries = eap_get_countries();
    $country_options = [];
    foreach ($countries as $country) {
        $country_options[$country->id] = $country->country_name;
    }

    $role_labels = eap_get_role_labels();
    $uems_status_options = [
        '' => 'Not Set',
        'Confirmed – National Delegate' => 'Confirmed – National Delegate',
        'Not yet confirmed – National Delegate' => 'Not yet confirmed – National Delegate',
        'Confirmed – Associate' => 'Confirmed – Associate',
        'Confirmed – Observer' => 'Confirmed – Observer',
        'N/A' => 'N/A',
    ];
    $uems_notes_options = [
        '' => 'Not Set',
        'Non-UEMS Country' => 'Non-UEMS Country',
        'Confirmed' => 'Confirmed',
        'Not Confirmed' => 'Not Confirmed',
        'Not on Latest UEMS List' => 'Not on Latest UEMS List',
        'Not Confirmed - Associate' => 'Not Confirmed - Associate',
        'Not on Latest UEMS List - Associate' => 'Not on Latest UEMS List - Associate',
    ];

    $council_options = [
        '' => 'Not Set',
        'pcc' => 'PCC',
        'stcc' => 'STCC',
        'both' => 'PCC & STCC',
    ];

    $inline_nonce = wp_create_nonce('eap_delegate_inline_edit');
    $delete_nonce = wp_create_nonce('eap_delegate_delete_user');
    $add_delegate_nonce = wp_create_nonce('eap_add_delegate');
    
    ?>
    <div class="wrap eap-delegate-admin-page">
        <h1 class="wp-heading-inline">
            <span class="dashicons dashicons-groups" style="font-size: 32px; vertical-align: middle;"></span>
            Delegate Administration
        </h1>
        <a href="#" class="page-title-action eap-add-delegate-btn" id="eap-add-delegate-btn">
            <span class="dashicons dashicons-plus-alt" style="vertical-align: middle; margin-right: 3px;"></span>
            Add New Delegate
        </a>
        <hr class="wp-header-end">
        
        <!-- Add New Delegate Modal -->
        <div id="eap-add-delegate-modal" class="eap-modal" style="display: none;">
            <div class="eap-modal-overlay"></div>
            <div class="eap-modal-container">
                <div class="eap-modal-header">
                    <h2 style="color:white;"><span class="dashicons dashicons-admin-users"></span> Add New Delegate</h2>
                    <button type="button" class="eap-modal-close">&times;</button>
                </div>
                <form id="eap-add-delegate-form" class="eap-modal-form">
                    <div class="eap-modal-body">
                        <div class="eap-form-section">
                            <h3>Basic Information</h3>
                            <div class="eap-form-row">
                                <div class="eap-form-field">
                                    <label for="new_delegate_first_name">First Name <span class="required">*</span></label>
                                    <input type="text" id="new_delegate_first_name" name="first_name" required>
                                </div>
                                <div class="eap-form-field">
                                    <label for="new_delegate_last_name">Last Name <span class="required">*</span></label>
                                    <input type="text" id="new_delegate_last_name" name="last_name" required>
                                </div>
                            </div>
                            <div class="eap-form-row">
                                <div class="eap-form-field">
                                    <label for="new_delegate_email">Email Address <span class="required">*</span></label>
                                    <input type="email" id="new_delegate_email" name="email" required>
                                    <span class="eap-field-help">This will be used for login credentials.</span>
                                </div>
                                <div class="eap-form-field">
                                    <label for="new_delegate_role">Role <span class="required">*</span></label>
                                    <select id="new_delegate_role" name="role" required>
                                        <option value="">Select a role...</option>
                                        <?php foreach ($role_labels as $role_slug => $role_label): ?>
                                            <?php if ($role_slug !== 'read_only_staff'): ?>
                                            <option value="<?php echo esc_attr($role_slug); ?>"><?php echo esc_html($role_label); ?></option>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                        </div>
                        
                        <div class="eap-form-section">
                            <h3>Professional Details</h3>
                            <div class="eap-form-row">
                                <div class="eap-form-field">
                                    <label for="new_delegate_country">Country</label>
                                    <select id="new_delegate_country" name="country">
                                        <option value="">Select a country...</option>
                                        <?php foreach ($country_options as $option_id => $option_name): ?>
                                            <option value="<?php echo esc_attr($option_id); ?>"><?php echo esc_html($option_name); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="eap-form-field">
                                    <label for="new_delegate_council">EAP Council</label>
                                    <select id="new_delegate_council" name="eap_council">
                                        <?php foreach ($council_options as $council_key => $council_label): ?>
                                            <option value="<?php echo esc_attr($council_key); ?>"><?php echo esc_html($council_label); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <div class="eap-form-row">
                                <div class="eap-form-field">
                                    <label for="new_delegate_society">National Society</label>
                                    <input type="text" id="new_delegate_society" name="society">
                                </div>
                                <div class="eap-form-field">
                                    <label for="new_delegate_institution">Institution</label>
                                    <input type="text" id="new_delegate_institution" name="institution">
                                </div>
                            </div>
                            <div class="eap-form-row">
                                <div class="eap-form-field">
                                    <label for="new_delegate_term_start">Term Start (MM/YYYY)</label>
                                    <input type="text" id="new_delegate_term_start" name="term_start" placeholder="e.g., 01/2024">
                                </div>
                                <div class="eap-form-field">
                                    <label for="new_delegate_term_end">Term End (MM/YYYY)</label>
                                    <input type="text" id="new_delegate_term_end" name="term_end" placeholder="e.g., 12/2027">
                                </div>
                            </div>
                        </div>
                        
                        <div class="eap-form-section">
                            <h3>Notification Settings</h3>
                            <div class="eap-form-row single">
                                <div class="eap-form-field checkbox-field">
                                    <label>
                                        <input type="checkbox" id="new_delegate_send_email" name="send_welcome_email" value="1" checked>
                                        Send welcome email with login credentials
                                    </label>
                                    <span class="eap-field-help">The delegate will receive an email with their username and a link to set their password.</span>
                                </div>
                            </div>
                        </div>
                        
                        <div class="eap-modal-error" id="eap-add-delegate-error" style="display: none;"></div>
                    </div>
                    <div class="eap-modal-footer">
                        <button type="button" class="button eap-modal-cancel">Cancel</button>
                        <button type="submit" class="button button-primary" id="eap-add-delegate-submit">
                            <span class="dashicons dashicons-plus-alt" style="vertical-align: middle;"></span>
                            Create Delegate
                        </button>
                    </div>
                </form>
            </div>
        </div>
        
        <div class="notice notice-info">
            <p>
                <strong>Total Delegates:</strong> <?php echo number_format(count($users)); ?> | 
                <strong>Displayed:</strong> <?php echo number_format($total_users); ?>
                <?php if (count($users) != $total_users): ?>
                    (filtered)
                <?php endif; ?>
            </p>
        </div>
        
        <!-- Filters -->
        <div class="eap-delegate-filters">
            <h3>Filter Delegates</h3>
            <form method="get" action="" class="eap-filter-form">
                <input type="hidden" name="page" value="eap-delegate-admin">
                
                <div class="eap-filter-row" style="display: flex; gap: 15px; align-items: flex-end; margin-bottom: 15px;">
                    <div class="eap-filter-field">
                        <label for="filter_council">PCC/STCC:</label>
                        <select id="filter_council" name="filter_council">
                            <option value="">All</option>
                            <option value="pcc" <?php selected($filter_council, 'pcc'); ?>>PCC</option>
                            <option value="stcc" <?php selected($filter_council, 'stcc'); ?>>STCC</option>
                            <option value="both" <?php selected($filter_council, 'both'); ?>>PCC & STCC</option>
                        </select>
                    </div>
                    
                    <div class="eap-filter-field">
                        <label for="filter_active_term">Active Term:</label>
                        <select id="filter_active_term" name="filter_active_term">
                            <option value="">All</option>
                            <option value="yes" <?php selected($filter_active_term, 'yes'); ?>>Active Term</option>
                            <option value="no" <?php selected($filter_active_term, 'no'); ?>>Inactive Term</option>
                        </select>
                    </div>
                    
                    <div class="eap-filter-field">
                        <label for="filter_active_status">Active Status:</label>
                        <select id="filter_active_status" name="filter_active_status">
                            <option value="">All</option>
                            <option value="yes" <?php selected($filter_active_status, 'yes'); ?>>Active</option>
                            <option value="no" <?php selected($filter_active_status, 'no'); ?>>Inactive</option>
                        </select>
                    </div>
                    
                    <div class="eap-filter-field" style="flex-grow: 1;">
                        <label for="search">Search:</label>
                        <input type="text" id="search" name="search" value="<?php echo esc_attr($search_query); ?>" 
                               placeholder="Search name, email, institution, society, country..." style="width: 100%; min-width: 300px;">
                    </div>
                    
                    <div class="eap-filter-field">
                        <button type="submit" class="button button-primary">Apply Filters</button>
                        <a href="<?php echo admin_url('admin.php?page=eap-delegate-admin'); ?>" class="button">Clear</a>
                    </div>
                </div>
            </form>
        </div>
        
        <!-- Delegates Table -->
        <?php if (empty($paginated_users)): ?>
            <div class="eap-no-delegates">
                <p><em>No delegates found matching your criteria.</em></p>
            </div>
        <?php else: ?>
            <div class="notice notice-info inline">
                <p>
                    <strong>Tip:</strong> Use the arrow beside each user to expand their full profile. Changes save automatically after you stop typing.
                </p>
            </div>
            <style>
                /* Add New Delegate Modal Styles */
                .eap-modal {
                    position: fixed;
                    top: 0;
                    left: 0;
                    right: 0;
                    bottom: 0;
                    z-index: 100000;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                }
                .eap-modal-overlay {
                    position: absolute;
                    top: 0;
                    left: 0;
                    right: 0;
                    bottom: 0;
                    background: rgba(0, 0, 0, 0.6);
                    backdrop-filter: blur(3px);
                }
                .eap-modal-container {
                    position: relative;
                    background: #fff;
                    border-radius: 8px;
                    box-shadow: 0 10px 40px rgba(0, 0, 0, 0.3);
                    max-width: 680px;
                    width: 90%;
                    max-height: 90vh;
                    overflow: hidden;
                    display: flex;
                    flex-direction: column;
                }
                .eap-modal-header {
                    display: flex;
                    justify-content: space-between;
                    align-items: center;
                    padding: 18px 24px;
                    background: linear-gradient(135deg, #1d2327 0%, #2c3338 100%);
                    color: #fff;
                }
                .eap-modal-header h2 {
                    margin: 0;
                    font-size: 18px;
                    font-weight: 600;
                    display: flex;
                    align-items: center;
                    gap: 10px;
                }
                .eap-modal-header h2 .dashicons {
                    font-size: 24px;
                    width: 24px;
                    height: 24px;
                }
                .eap-modal-close {
                    background: none;
                    border: none;
                    color: #fff;
                    font-size: 28px;
                    cursor: pointer;
                    padding: 0;
                    line-height: 1;
                    opacity: 0.8;
                    transition: opacity 0.2s;
                }
                .eap-modal-close:hover {
                    opacity: 1;
                }
                .eap-modal-body {
                    padding: 24px;
                    overflow-y: auto;
                    flex: 1;
                }
                .eap-form-section {
                    margin-bottom: 24px;
                }
                .eap-form-section:last-child {
                    margin-bottom: 0;
                }
                .eap-form-section h3 {
                    margin: 0 0 16px 0;
                    font-size: 14px;
                    font-weight: 600;
                    color: #1d2327;
                    padding-bottom: 8px;
                    border-bottom: 1px solid #dcdcde;
                }
                .eap-form-row {
                    display: grid;
                    grid-template-columns: repeat(2, 1fr);
                    gap: 16px;
                    margin-bottom: 16px;
                }
                .eap-form-row.single {
                    grid-template-columns: 1fr;
                }
                .eap-form-row:last-child {
                    margin-bottom: 0;
                }
                .eap-form-field label {
                    display: block;
                    font-size: 13px;
                    font-weight: 600;
                    color: #1d2327;
                    margin-bottom: 6px;
                }
                .eap-form-field label .required {
                    color: #d63638;
                }
                .eap-form-field input[type="text"],
                .eap-form-field input[type="email"],
                .eap-form-field select {
                    width: 100%;
                    padding: 8px 12px;
                    border: 1px solid #8c8f94;
                    border-radius: 4px;
                    font-size: 14px;
                    transition: border-color 0.2s, box-shadow 0.2s;
                }
                .eap-form-field input:focus,
                .eap-form-field select:focus {
                    border-color: #2271b1;
                    box-shadow: 0 0 0 1px #2271b1;
                    outline: none;
                }
                .eap-form-field.checkbox-field label {
                    display: flex;
                    align-items: flex-start;
                    gap: 8px;
                    font-weight: 400;
                    cursor: pointer;
                }
                .eap-form-field.checkbox-field input[type="checkbox"] {
                    margin-top: 2px;
                }
                .eap-field-help {
                    display: block;
                    font-size: 12px;
                    color: #646970;
                    margin-top: 4px;
                }
                .eap-modal-error {
                    background: #fcf0f1;
                    border-left: 4px solid #d63638;
                    padding: 12px 16px;
                    margin-top: 16px;
                    color: #d63638;
                    font-size: 13px;
                }
                .eap-modal-footer {
                    display: flex;
                    justify-content: flex-end;
                    gap: 12px;
                    padding: 16px 24px;
                    background: #f6f7f7;
                    border-top: 1px solid #dcdcde;
                }
                .eap-modal-footer .button-primary {
                    display: flex;
                    align-items: center;
                    gap: 6px;
                }
                .eap-modal-footer .button-primary .dashicons {
                    font-size: 16px;
                    width: 16px;
                    height: 16px;
                }
                #eap-add-delegate-submit:disabled {
                    opacity: 0.6;
                    cursor: not-allowed;
                }
                .eap-add-delegate-btn {
                    display: inline-flex !important;
                    align-items: center;
                }
                @media screen and (max-width: 782px) {
                    .eap-form-row {
                        grid-template-columns: 1fr;
                    }
                    .eap-modal-container {
                        width: 95%;
                        max-height: 95vh;
                    }
                }
                
                /* Existing delegate table styles */
                .eap-delegates-table .eap-row-toggle {
                    background: none;
                    border: 0;
                    cursor: pointer;
                    padding: 0;
                    margin-right: 8px;
                    color: #2271b1;
                }
                .eap-delegates-table .eap-row-toggle .dashicons {
                    transition: transform 0.2s ease;
                }
                .eap-delegates-table .eap-delegate-row.is-open .eap-row-toggle .dashicons {
                    transform: rotate(90deg);
                }
                .eap-delegates-table .eap-user-id {
                    font-weight: 600;
                }
                .eap-delegates-table td.eap-saved {
                    background: #e6f4ea;
                    transition: background 0.3s ease;
                }
                .eap-delegate-details-row {
                    display: none;
                }
                .eap-delegate-details-row.is-visible {
                    display: table-row;
                }
                .eap-detail-wrapper {
                    background: #f9fafc;
                    border: 1px solid #dce2ec;
                    padding: 20px;
                }
                .eap-detail-wrapper {
                    max-width:calc(100VW - 300px);
                }
                .eap-delegate-row:hover, .eap-delegate-row:hover>td {
                    background: #ddf !important;
                }
                .eap-delegate-row {
                    cursor:pointer !important;
                }
                .eap-detail-header {
                    display: flex;
                    justify-content: space-between;
                    flex-wrap: wrap;
                    gap: 15px;
                    margin-bottom: 20px;
                }
                .eap-detail-header h3 {
                    margin: 0;
                }
                .eap-detail-meta {
                    margin: 6px 0 0;
                    display: flex;
                    gap: 20px;
                    flex-wrap: wrap;
                    font-size: 13px;
                    color: #555;
                }
                .eap-detail-actions {
                    display: flex;
                    gap: 10px;
                    align-items: center;
                }
                .eap-detail-panel {
                    margin-bottom: 25px;
                }
                .eap-detail-panel h4 {
                    margin-bottom: 10px;
                }
                .eap-form-grid {
                    display: grid;
                    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
                    gap: 15px 20px;
                }
                .eap-form-grid label {
                    display: flex;
                    flex-direction: column;
                    font-weight: 600;
                    font-size: 13px;
                    color: #1d2327;
                }
                .eap-form-grid label input,
                .eap-form-grid label select,
                .eap-form-grid label textarea {
                    margin-top: 4px;
                }
                .eap-form-grid .full-width {
                    grid-column: 1 / -1;
                }
                .eap-inline-field {
                    width: 100%;
                }
                .eap-inline-field.eap-saving {
                    background: #fffbe6;
                    border-color: #dba617;
                }
                .eap-inline-field.eap-saved {
                    background: #e6f4ea;
                    border-color: #46b450;
                }
                .eap-inline-help {
                    font-weight: 400;
                    font-size: 12px;
                    color: #5c5f66;
                    margin-top: 3px;
                }
                .eap-term-status {
                    display: inline-block;
                    padding: 2px 8px;
                    border-radius: 999px;
                    font-size: 11px;
                    margin-top: 4px;
                }
                .eap-term-status.is-active {
                    background: #d4f8dd;
                    color: #096b24;
                }
                .eap-term-status.is-inactive {
                    background: #fde2e1;
                    color: #8a1c1c;
                }
                .eap-col-uems-status .value {
                    font-weight: 600;
                }
                .eap-custom-value {
                    margin-top: 10px;
                }
            </style>
            <table class="wp-list-table widefat fixed striped eap-delegates-table">
                <thead>
                    <tr>
                        <th style="width: 110px;">User ID</th>
                        <th style="width: 170px;">Name</th>
                        <th style="width: 170px;">Surname</th>
                        <th style="width: 220px;">User Email</th>
                        <th style="width: 150px;">Country</th>
                        <th style="width: 200px;">Society</th>
                        <th style="width: 180px;">Active Term</th>
                        <th style="width: 90px;">Active</th>
                        <th style="width: 160px;">UEMS Delegate Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($paginated_users as $user): 
                        $first_name = get_user_meta($user->ID, 'first_name', true);
                        $last_name = get_user_meta($user->ID, 'last_name', true);
                        $title_prefix = get_user_meta($user->ID, 'title_prefix', true);
                        $country_id = get_user_meta($user->ID, 'country', true);
                        $country = $country_id && isset($country_options[$country_id]) ? $country_options[$country_id] : '';
                        $institution = get_user_meta($user->ID, 'institution', true);
                        $society = get_user_meta($user->ID, 'society', true);
                        $term_start = get_user_meta($user->ID, 'term_start', true);
                        $term_end = get_user_meta($user->ID, 'term_end', true);
                        $preferred_email = get_user_meta($user->ID, 'preferred_email', true);
                        $city = get_user_meta($user->ID, 'city', true);
                        $phone = get_user_meta($user->ID, 'phone', true);
                        $whatsapp = get_user_meta($user->ID, 'whatsapp_number', true);
                        $eap_council = get_user_meta($user->ID, 'eap_council', true);
                        $biography = get_user_meta($user->ID, 'biography', true);
                        $languages = get_user_meta($user->ID, 'languages', true);
                        $uems_status = get_user_meta($user->ID, 'uems_status', true);
                        $uems_notes = get_user_meta($user->ID, 'uems_notes', true);
                        $uems_date_confirmed_raw = get_user_meta($user->ID, 'uems_date_confirmed', true);
                        $uems_date_confirmed_value = $uems_date_confirmed_raw ? eap_normalize_date_value($uems_date_confirmed_raw) : '';
                        $internal_notes = get_user_meta($user->ID, 'internal_notes', true);
                        $role_custom_label = get_user_meta($user->ID, 'role_custom_label', true);
                        $is_active = get_user_meta($user->ID, 'is_active', true);
                        $is_active_bool = !($is_active === '0' || $is_active === false);
                        $full_name = trim($title_prefix . ' ' . $first_name . ' ' . $last_name);
                        $term_display = ($term_start || $term_end) ? (($term_start ?: '?') . ' - ' . ($term_end ?: '?')) : 'Not set';
                        $has_active_term = eap_has_active_term($term_start, $term_end);
                        $primary_role = '';
                        if (!empty($user->roles)) {
                            foreach ((array) $user->roles as $role_slug) {
                                $primary_role = $role_slug;
                                break;
                            }
                        }
                        $role_select_value = isset($role_labels[$primary_role]) ? $primary_role : 'other';
                        $uems_status_select_value = array_key_exists($uems_status, $uems_status_options) ? $uems_status : '__custom';
                        $uems_notes_select_value = array_key_exists($uems_notes, $uems_notes_options) ? $uems_notes : '__custom';
                    ?>
                        <tr class="eap-delegate-row" data-user-id="<?php echo esc_attr($user->ID); ?>">
                            <td class="eap-col-user-id">
                                <button type="button" class="eap-row-toggle button-link" aria-expanded="false" aria-controls="eap-delegate-details-<?php echo esc_attr($user->ID); ?>">
                                    <span class="dashicons dashicons-arrow-right-alt2"></span>
                                    <span class="screen-reader-text">Toggle details for <?php echo esc_html($full_name ?: $user->user_email); ?></span>
                                </button>
                                <span class="eap-user-id">#<?php echo esc_html($user->ID); ?></span>
                            </td>
                            <td class="eap-col-first-name"><span class="value"><?php echo $first_name ? esc_html($first_name) : 'Not set'; ?></span></td>
                            <td class="eap-col-last-name"><span class="value"><?php echo $last_name ? esc_html($last_name) : 'Not set'; ?></span></td>
                            <td class="eap-col-email">
                                <a href="mailto:<?php echo esc_attr($user->user_email); ?>" class="eap-email-link"><span class="value"><?php echo esc_html($user->user_email); ?></span></a>
                            </td>
                            <td class="eap-col-country"><span class="value"><?php echo $country ? esc_html($country) : 'Not set'; ?></span></td>
                            <td class="eap-col-society"><span class="value"><?php echo $society ? esc_html($society) : 'Not set'; ?></span></td>
                            <td class="eap-col-term" data-term-start="<?php echo esc_attr($term_start); ?>" data-term-end="<?php echo esc_attr($term_end); ?>">
                                <span class="eap-term-range"><?php echo esc_html($term_display); ?></span>
                                <span class="eap-term-status <?php echo $has_active_term ? 'is-active' : 'is-inactive'; ?>">
                                    <?php echo $has_active_term ? 'Active' : 'Inactive'; ?>
                                </span>
                            </td>
                            <td class="eap-col-active">
                                <label class="eap-toggle-switch">
                                    <input type="checkbox"
                                           class="eap-active-toggle"
                                           data-user-id="<?php echo esc_attr($user->ID); ?>"
                                           <?php checked($is_active_bool, true); ?>>
                                    <span class="eap-toggle-slider"></span>
                                </label>
                            </td>
                            <td class="eap-col-uems-status"><span class="value"><?php echo $uems_status ? esc_html($uems_status) : 'Not set'; ?></span></td>
                        </tr>
                        <tr id="eap-delegate-details-<?php echo esc_attr($user->ID); ?>" class="eap-delegate-details-row" aria-hidden="true">
                            <td colspan="9">
                                <div class="eap-detail-wrapper">
                                    <div class="eap-detail-header">
                                        <div>
                                            <h3><?php echo esc_html($full_name ?: $user->display_name ?: 'Member #' . $user->ID); ?></h3>
                                            <div class="eap-detail-meta">
                                                <span><strong>User Email:</strong> <span class="eap-detail-email"><?php echo esc_html($user->user_email); ?></span></span>
                                                <span><strong>Role:</strong> <?php echo esc_html($role_labels[$primary_role] ?? ucwords(str_replace('_', ' ', $primary_role))); ?></span>
                                                <span><strong>WP User ID:</strong> #<?php echo esc_html($user->ID); ?></span>
                                            </div>
                                        </div>
                                        <div class="eap-detail-actions">
                                            <a href="<?php echo admin_url('user-edit.php?user_id=' . $user->ID); ?>" target="_blank" class="button button-secondary">Open WP Profile</a>
                                            <button type="button" class="button button-link-delete eap-delete-user" data-user-id="<?php echo esc_attr($user->ID); ?>" data-user-name="<?php echo esc_attr($full_name ?: $user->user_email); ?>">Delete User</button>
                                        </div>
                                    </div>
                                    
                                    <div class="eap-detail-panel">
                                        <h4>Identity & Access</h4>
                                        <div class="eap-form-grid">
                                            <label>Name
                                                <input type="text" class="regular-text eap-inline-field" data-field="first_name" data-user-id="<?php echo esc_attr($user->ID); ?>" value="<?php echo esc_attr($first_name); ?>">
                                            </label>
                                            <label>Surname
                                                <input type="text" class="regular-text eap-inline-field" data-field="last_name" data-user-id="<?php echo esc_attr($user->ID); ?>" value="<?php echo esc_attr($last_name); ?>">
                                            </label>
                                            <label>Preferred Email
                                                <input type="email" class="regular-text eap-inline-field" data-field="preferred_email" data-user-id="<?php echo esc_attr($user->ID); ?>" value="<?php echo esc_attr($preferred_email); ?>">
                                            </label>
                                            <label>User Email
                                                <input type="email" class="regular-text eap-inline-field" data-field="user_email" data-user-id="<?php echo esc_attr($user->ID); ?>" value="<?php echo esc_attr($user->user_email); ?>">
                                                <span class="eap-inline-help">Also updates the WordPress login.</span>
                                            </label>
                                            <label>Role
                                                <select class="eap-inline-field eap-role-select" data-field="role" data-user-id="<?php echo esc_attr($user->ID); ?>">
                                                    <option value="">Select role</option>
                                                    <?php foreach ($role_labels as $role_slug => $role_label): ?>
                                                        <option value="<?php echo esc_attr($role_slug); ?>" <?php selected($role_select_value, $role_slug); ?>><?php echo esc_html($role_label); ?></option>
                                                    <?php endforeach; ?>
                                                    <option value="other" <?php selected($role_select_value, 'other'); ?>>Other (describe below)</option>
                                                </select>
                                            </label>
                                            <div class="eap-custom-value eap-role-custom" data-user-id="<?php echo esc_attr($user->ID); ?>" style="<?php echo ($role_select_value === 'other' || !empty($role_custom_label)) ? '' : 'display:none;'; ?>">
                                                <label>Other Role Label
                                                    <input type="text" class="regular-text eap-inline-field" data-field="role_custom_label" data-user-id="<?php echo esc_attr($user->ID); ?>" value="<?php echo esc_attr($role_custom_label); ?>">
                                                </label>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="eap-detail-panel">
                                        <h4>Professional Profile</h4>
                                        <div class="eap-form-grid">
                                            <label>Country
                                                <select class="eap-inline-field" data-field="country" data-user-id="<?php echo esc_attr($user->ID); ?>">
                                                    <option value="">Select country</option>
                                                    <?php foreach ($country_options as $option_id => $option_name): ?>
                                                        <option value="<?php echo esc_attr($option_id); ?>" <?php selected((int) $country_id, (int) $option_id); ?>>
                                                            <?php echo esc_html($option_name); ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </label>
                                            <label>City
                                                <input type="text" class="regular-text eap-inline-field" data-field="city" data-user-id="<?php echo esc_attr($user->ID); ?>" value="<?php echo esc_attr($city); ?>">
                                            </label>
                                            <label>Phone
                                                <input type="text" class="regular-text eap-inline-field" data-field="phone" data-user-id="<?php echo esc_attr($user->ID); ?>" value="<?php echo esc_attr($phone); ?>">
                                            </label>
                                            <label>Whatsapp Phone
                                                <input type="text" class="regular-text eap-inline-field" data-field="whatsapp_number" data-user-id="<?php echo esc_attr($user->ID); ?>" value="<?php echo esc_attr($whatsapp); ?>">
                                            </label>
                                            <label>National Society
                                                <input type="text" class="regular-text eap-inline-field" data-field="society" data-user-id="<?php echo esc_attr($user->ID); ?>" value="<?php echo esc_attr($society); ?>">
                                            </label>
                                            <label>Institution
                                                <input type="text" class="regular-text eap-inline-field" data-field="institution" data-user-id="<?php echo esc_attr($user->ID); ?>" value="<?php echo esc_attr($institution); ?>">
                                            </label>
                                            <label>Term Start (MM/YYYY)
                                                <input type="text" class="regular-text eap-inline-field" data-field="term_start" data-user-id="<?php echo esc_attr($user->ID); ?>" value="<?php echo esc_attr($term_start); ?>">
                                            </label>
                                            <label>Term End (MM/YYYY)
                                                <input type="text" class="regular-text eap-inline-field" data-field="term_end" data-user-id="<?php echo esc_attr($user->ID); ?>" value="<?php echo esc_attr($term_end); ?>">
                                            </label>
                                            <label>EAP Council
                                                <select class="eap-inline-field" data-field="eap_council" data-user-id="<?php echo esc_attr($user->ID); ?>">
                                                    <?php foreach ($council_options as $council_key => $council_label): ?>
                                                        <option value="<?php echo esc_attr($council_key); ?>" <?php selected($eap_council, $council_key); ?>>
                                                            <?php echo esc_html($council_label); ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </label>
                                            <label class="full-width">Biography
                                                <textarea rows="4" class="large-text eap-inline-field" data-field="biography" data-user-id="<?php echo esc_attr($user->ID); ?>"><?php echo esc_textarea($biography); ?></textarea>
                                            </label>
                                            <label>Languages
                                                <input type="text" class="regular-text eap-inline-field" data-field="languages" data-user-id="<?php echo esc_attr($user->ID); ?>" value="<?php echo esc_attr($languages); ?>">
                                            </label>
                                        </div>
                                    </div>
                                    
                                    <div class="eap-detail-panel">
                                        <h4>UEMS & Internal Notes</h4>
                                        <div class="eap-form-grid">
                                            <label>UEMS Delegate Status
                                                <select class="eap-inline-field eap-select-custom" data-field="uems_status" data-user-id="<?php echo esc_attr($user->ID); ?>" data-custom-target="#uems-status-custom-<?php echo esc_attr($user->ID); ?>">
                                                    <?php foreach ($uems_status_options as $status_value => $status_label): ?>
                                                        <option value="<?php echo esc_attr($status_value); ?>" <?php selected($uems_status_select_value, $status_value); ?>>
                                                            <?php echo esc_html($status_label); ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                    <option value="__custom" <?php selected($uems_status_select_value, '__custom'); ?>>Other (type below)</option>
                                                </select>
                                            </label>
                                            <div id="uems-status-custom-<?php echo esc_attr($user->ID); ?>" class="eap-custom-value" style="<?php echo $uems_status_select_value === '__custom' ? '' : 'display:none;'; ?>">
                                                <label>Custom UEMS Delegate Status
                                                    <input type="text" class="regular-text eap-inline-field" data-field="uems_status" data-user-id="<?php echo esc_attr($user->ID); ?>" value="<?php echo esc_attr($uems_status_select_value === '__custom' ? $uems_status : ''); ?>">
                                                </label>
                                            </div>
                                            <label>UEMS Notes
                                                <select class="eap-inline-field eap-select-custom" data-field="uems_notes" data-user-id="<?php echo esc_attr($user->ID); ?>" data-custom-target="#uems-notes-custom-<?php echo esc_attr($user->ID); ?>">
                                                    <?php foreach ($uems_notes_options as $note_value => $note_label): ?>
                                                        <option value="<?php echo esc_attr($note_value); ?>" <?php selected($uems_notes_select_value, $note_value); ?>>
                                                            <?php echo esc_html($note_label); ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                    <option value="__custom" <?php selected($uems_notes_select_value, '__custom'); ?>>Other (type below)</option>
                                                </select>
                                            </label>
                                            <div id="uems-notes-custom-<?php echo esc_attr($user->ID); ?>" class="eap-custom-value" style="<?php echo $uems_notes_select_value === '__custom' ? '' : 'display:none;'; ?>">
                                                <label>Custom UEMS Notes
                                                    <input type="text" class="regular-text eap-inline-field" data-field="uems_notes" data-user-id="<?php echo esc_attr($user->ID); ?>" value="<?php echo esc_attr($uems_notes_select_value === '__custom' ? $uems_notes : ''); ?>">
                                                </label>
                                            </div>
                                            <label>UEMS Date Confirmed
                                                <input type="date" class="regular-text eap-inline-field" data-field="uems_date_confirmed" data-user-id="<?php echo esc_attr($user->ID); ?>" value="<?php echo esc_attr($uems_date_confirmed_value); ?>">
                                            </label>
                                            <label class="full-width">Internal Notes
                                                <textarea rows="3" class="large-text eap-inline-field" data-field="internal_notes" data-user-id="<?php echo esc_attr($user->ID); ?>"><?php echo esc_textarea($internal_notes); ?></textarea>
                                            </label>
                                        </div>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            
            <!-- Pagination -->
            <?php if ($total_pages > 1): ?>
                <div class="tablenav bottom">
                    <div class="tablenav-pages">
                        <span class="displaying-num"><?php echo number_format($total_users); ?> items</span>
                        <span class="pagination-links">
                            <?php
                            if ($current_page > 1) {
                                $first_url = add_query_arg(array_merge($query_params, ['paged' => 1]), $base_url);
                                echo '<a class="first-page button" href="' . esc_url($first_url) . '"><span aria-hidden="true">«</span></a> ';
                                
                                $prev_url = add_query_arg(array_merge($query_params, ['paged' => $current_page - 1]), $base_url);
                                echo '<a class="prev-page button" href="' . esc_url($prev_url) . '"><span aria-hidden="true">‹</span></a> ';
                            } else {
                                echo '<span class="tablenav-pages-navspan button disabled" aria-hidden="true">«</span> ';
                                echo '<span class="tablenav-pages-navspan button disabled" aria-hidden="true">‹</span> ';
                            }
                            ?>
                            
                            <span class="paging-input">
                                <label for="current-page-selector" class="screen-reader-text">Current Page</label>
                                <input class="current-page" id="current-page-selector" type="text" 
                                       name="paged" value="<?php echo $current_page; ?>" size="<?php echo strlen($total_pages); ?>" 
                                       aria-describedby="table-paging">
                                <span class="tablenav-paging-text"> of <span class="total-pages"><?php echo $total_pages; ?></span></span>
                            </span>
                            
                            <?php
                            if ($current_page < $total_pages) {
                                $next_url = add_query_arg(array_merge($query_params, ['paged' => $current_page + 1]), $base_url);
                                echo '<a class="next-page button" href="' . esc_url($next_url) . '"><span aria-hidden="true">›</span></a> ';
                                
                                $last_url = add_query_arg(array_merge($query_params, ['paged' => $total_pages]), $base_url);
                                echo '<a class="last-page button" href="' . esc_url($last_url) . '"><span aria-hidden="true">»</span></a>';
                            } else {
                                echo '<span class="tablenav-pages-navspan button disabled" aria-hidden="true">›</span> ';
                                echo '<span class="tablenav-pages-navspan button disabled" aria-hidden="true">»</span>';
                            }
                            ?>
                        </span>
                    </div>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
    
    <script type="text/javascript">
    jQuery(document).ready(function($) {
        var inlineNonce = '<?php echo esc_js($inline_nonce); ?>';
        var deleteNonce = '<?php echo esc_js($delete_nonce); ?>';
        var toggleNonce = '<?php echo esc_js(wp_create_nonce('eap_toggle_active_' . get_current_user_id())); ?>';
        var countryMap = <?php echo wp_json_encode($country_options); ?>;
        var saveTimers = {};
        var SAVE_DELAY = 700;
        
        function refreshTermDisplay(userId) {
            var $row = $('.eap-delegate-row[data-user-id="' + userId + '"]');
            var termStart = $('.eap-inline-field[data-field="term_start"][data-user-id="' + userId + '"]').val();
            var termEnd = $('.eap-inline-field[data-field="term_end"][data-user-id="' + userId + '"]').val();
            var display = (termStart || termEnd) ? ((termStart || '?') + ' - ' + (termEnd || '?')) : 'Not set';
            var $termRange = $row.find('.eap-term-range');
            var $termStatus = $row.find('.eap-term-status');
            $termRange.text(display);
            var isActive = false;
            if (termStart && termEnd) {
                var startParts = termStart.split('/');
                var endParts = termEnd.split('/');
                if (startParts.length === 2 && endParts.length === 2) {
                    var startDate = new Date(parseInt(startParts[1], 10), parseInt(startParts[0], 10) - 1, 1);
                    var endDate = new Date(parseInt(endParts[1], 10), parseInt(endParts[0], 10), 0);
                    var now = new Date();
                    if (!isNaN(startDate) && !isNaN(endDate)) {
                        isActive = now >= startDate && now <= endDate;
                    }
                }
            }
            $termStatus
                .text(isActive ? 'Active' : 'Inactive')
                .toggleClass('is-active', isActive)
                .toggleClass('is-inactive', !isActive);
        }
        
        function updateHeader(userId, field, value) {
            var $row = $('.eap-delegate-row[data-user-id="' + userId + '"]');
            if (!$row.length) {
                return;
            }
            switch (field) {
                case 'first_name':
                    $row.find('.eap-col-first-name .value').text(value || 'Not set');
                    break;
                case 'last_name':
                    $row.find('.eap-col-last-name .value').text(value || 'Not set');
                    break;
                case 'user_email':
                    var email = value || '';
                    $row.find('.eap-col-email .value').text(email || 'Not set');
                    $row.find('.eap-email-link').attr('href', email ? 'mailto:' + email : '#');
                    $row.next('.eap-delegate-details-row').find('.eap-detail-email').text(email || 'Not set');
                    break;
                case 'country':
                    var countryText = (value && countryMap[value]) ? countryMap[value] : '';
                    $row.find('.eap-col-country .value').text(countryText || 'Not set');
                    break;
                case 'society':
                    $row.find('.eap-col-society .value').text(value || 'Not set');
                    break;
                case 'uems_status':
                    $row.find('.eap-col-uems-status .value').text(value || 'Not set');
                    break;
                case 'term_start':
                case 'term_end':
                    refreshTermDisplay(userId);
                    break;
            }
        }
        
        function sendFieldSave($field, userId, fieldName) {
            var value = $field.val();
            $field.addClass('eap-saving').removeClass('eap-saved');
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                dataType: 'json',
                data: {
                    action: 'eap_delegate_inline_update',
                    nonce: inlineNonce,
                    user_id: userId,
                    field: fieldName,
                    value: value
                }
            }).done(function(response) {
                if (response.success) {
                    var savedValue = response.data && response.data.value !== undefined ? response.data.value : value;
                    updateHeader(userId, fieldName, savedValue);
                    $field.addClass('eap-saved');
                } else {
                    alert(response.data || 'Unable to save field.');
                }
            }).fail(function() {
                alert('Unable to save field.');
            }).always(function() {
                $field.removeClass('eap-saving');
                setTimeout(function() {
                    $field.removeClass('eap-saved');
                }, 1500);
            });
        }
        
        function queueFieldSave($field, immediate) {
            var userId = $field.data('user-id');
            var fieldName = $field.data('field');
            if (!userId || !fieldName) {
                return;
            }
            var key = userId + '-' + fieldName;
            if (saveTimers[key]) {
                clearTimeout(saveTimers[key]);
            }
            var delay = immediate ? 0 : SAVE_DELAY;
            saveTimers[key] = setTimeout(function() {
                sendFieldSave($field, userId, fieldName);
            }, delay);
        }
        
        $('.eap-inline-field').on('input', function() {
            var $field = $(this);
            if ($field.is('select') || $field.hasClass('eap-select-custom') || $field.hasClass('eap-role-select')) {
                return;
            }
            queueFieldSave($field, false);
        });
        
        $('.eap-inline-field').on('change', function() {
            var $field = $(this);
            if ($field.hasClass('eap-select-custom') || $field.hasClass('eap-role-select')) {
                return;
            }
            if ($field.is('select')) {
                queueFieldSave($field, true);
            }
        });
        
        function toggleRoleCustom(userId) {
            var $select = $('.eap-role-select[data-user-id="' + userId + '"]');
            var $custom = $('.eap-role-custom[data-user-id="' + userId + '"]');
            if ($select.val() === 'other') {
                $custom.show();
            } else {
                $custom.hide();
            }
        }
        
        $('.eap-role-select').on('change', function() {
            var userId = $(this).data('user-id');
            toggleRoleCustom(userId);
            if ($(this).val() !== 'other') {
                queueFieldSave($(this), true);
            }
        }).each(function() {
            toggleRoleCustom($(this).data('user-id'));
        });
        
        $('.eap-select-custom').on('change', function() {
            var $select = $(this);
            var target = $select.data('custom-target');
            var $custom = target ? $(target) : null;
            if ($select.val() === '__custom') {
                if ($custom && $custom.length) {
                    $custom.show();
                    $custom.find('.eap-inline-field').first().focus();
                }
            } else {
                if ($custom && $custom.length) {
                    $custom.hide();
                    $custom.find('.eap-inline-field').val('');
                }
                queueFieldSave($select, true);
            }
        }).each(function() {
            var $select = $(this);
            if ($select.val() !== '__custom') {
                var target = $select.data('custom-target');
                if (target) {
                    $(target).hide();
                }
            }
        });
        
        $('.eap-row-toggle').on('click', function(event) {
            event.preventDefault();
            event.stopPropagation();
            var $btn = $(this);
            var targetId = $btn.attr('aria-controls');
            var $row = $btn.closest('.eap-delegate-row');
            var $details = $('#' + targetId);
            var isOpen = $row.hasClass('is-open');
            
            $('.eap-delegate-row.is-open').not($row).removeClass('is-open').find('.eap-row-toggle').attr('aria-expanded', 'false');
            $('.eap-delegate-details-row.is-visible').not($details).removeClass('is-visible').attr('aria-hidden', 'true');
            
            if (isOpen) {
                $row.removeClass('is-open');
                $btn.attr('aria-expanded', 'false');
                $details.removeClass('is-visible').attr('aria-hidden', 'true');
            } else {
                $row.addClass('is-open');
                $btn.attr('aria-expanded', 'true');
                $details.addClass('is-visible').attr('aria-hidden', 'false');
            }
        });
        
        $('.eap-delegate-row').on('click', function(e) {
            if ($(e.target).closest('button, a, input, label, select, textarea').length) {
                return;
            }
            $(this).find('.eap-row-toggle').trigger('click');
        });
        
        $('.eap-active-toggle').on('click', function(e) {
            e.stopPropagation();
        });
        
        $('.eap-active-toggle').on('change', function() {
            var $toggle = $(this);
            var userId = $toggle.data('user-id');
            var isActive = $toggle.is(':checked');
            $toggle.prop('disabled', true);
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                dataType: 'json',
                data: {
                    action: 'eap_toggle_delegate_active',
                    user_id: userId,
                    is_active: isActive ? '1' : '0',
                    nonce: toggleNonce
                }
            }).done(function(response) {
                if (!response.success) {
                    alert(response.data || 'Error updating active status');
                    $toggle.prop('checked', !isActive);
                } else {
                    $toggle.closest('td').addClass('eap-saved');
                    setTimeout(function() {
                        $toggle.closest('td').removeClass('eap-saved');
                    }, 1000);
                }
            }).fail(function() {
                alert('Error updating active status');
                $toggle.prop('checked', !isActive);
            }).always(function() {
                $toggle.prop('disabled', false);
            });
        });
        
        $('.eap-delete-user').on('click', function(e) {
            e.preventDefault();
            var $button = $(this);
            var userId = $button.data('user-id');
            var userName = $button.data('user-name') || 'this user';
            if (!confirm('Delete ' + userName + '? This action cannot be undone.')) {
                return;
            }
            $button.prop('disabled', true);
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                dataType: 'json',
                data: {
                    action: 'eap_delete_delegate_user',
                    nonce: deleteNonce,
                    user_id: userId
                }
            }).done(function(response) {
                if (response.success) {
                    var $row = $('.eap-delegate-row[data-user-id="' + userId + '"]');
                    var $details = $('#eap-delegate-details-' + userId);
                    $details.fadeOut(150, function() { $(this).remove(); });
                    $row.fadeOut(150, function() { $(this).remove(); });
                } else {
                    alert(response.data || 'Unable to delete user.');
                }
            }).fail(function() {
                alert('Unable to delete user.');
            }).always(function() {
                $button.prop('disabled', false);
            });
        });
        
        // === ADD NEW DELEGATE MODAL HANDLERS ===
        var addDelegateNonce = '<?php echo esc_js($add_delegate_nonce); ?>';
        var $modal = $('#eap-add-delegate-modal');
        var $form = $('#eap-add-delegate-form');
        var $error = $('#eap-add-delegate-error');
        var $submitBtn = $('#eap-add-delegate-submit');
        
        function openAddDelegateModal() {
            $form[0].reset();
            $error.hide().text('');
            $modal.fadeIn(200);
            $('body').addClass('modal-open');
            $('#new_delegate_first_name').focus();
        }
        
        function closeAddDelegateModal() {
            $modal.fadeOut(200);
            $('body').removeClass('modal-open');
        }
        
        // Open modal
        $('#eap-add-delegate-btn').on('click', function(e) {
            e.preventDefault();
            openAddDelegateModal();
        });
        
        // Close modal handlers
        $('.eap-modal-close, .eap-modal-cancel').on('click', function() {
            closeAddDelegateModal();
        });
        
        $('.eap-modal-overlay').on('click', function() {
            closeAddDelegateModal();
        });
        
        // Close on Escape key
        $(document).on('keydown', function(e) {
            if (e.key === 'Escape' && $modal.is(':visible')) {
                closeAddDelegateModal();
            }
        });
        
        // Prevent closing when clicking inside modal container
        $('.eap-modal-container').on('click', function(e) {
            e.stopPropagation();
        });
        
        // Form submission
        $form.on('submit', function(e) {
            e.preventDefault();
            
            var firstName = $('#new_delegate_first_name').val().trim();
            var lastName = $('#new_delegate_last_name').val().trim();
            var email = $('#new_delegate_email').val().trim();
            var role = $('#new_delegate_role').val();
            
            // Basic validation
            if (!firstName || !lastName || !email || !role) {
                $error.text('Please fill in all required fields.').show();
                return;
            }
            
            // Email validation
            var emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            if (!emailRegex.test(email)) {
                $error.text('Please enter a valid email address.').show();
                return;
            }
            
            $error.hide();
            $submitBtn.prop('disabled', true).html('<span class="dashicons dashicons-update spin"></span> Creating...');
            
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                dataType: 'json',
                data: {
                    action: 'eap_add_delegate',
                    nonce: addDelegateNonce,
                    first_name: firstName,
                    last_name: lastName,
                    email: email,
                    role: role,
                    country: $('#new_delegate_country').val(),
                    eap_council: $('#new_delegate_council').val(),
                    society: $('#new_delegate_society').val(),
                    institution: $('#new_delegate_institution').val(),
                    term_start: $('#new_delegate_term_start').val(),
                    term_end: $('#new_delegate_term_end').val(),
                    send_welcome_email: $('#new_delegate_send_email').is(':checked') ? '1' : '0'
                }
            }).done(function(response) {
                if (response.success) {
                    closeAddDelegateModal();
                    // Reload the page to show the new delegate
                    window.location.reload();
                } else {
                    $error.text(response.data || 'Failed to create delegate.').show();
                }
            }).fail(function() {
                $error.text('An error occurred. Please try again.').show();
            }).always(function() {
                $submitBtn.prop('disabled', false).html('<span class="dashicons dashicons-plus-alt" style="vertical-align: middle;"></span> Create Delegate');
            });
        });
    });
    </script>
    <style>
        .dashicons.spin {
            animation: spin 1s linear infinite;
        }
        @keyframes spin {
            100% { transform: rotate(360deg); }
        }
    </style>
    <?php
}

/**
 * Helper function to check if a delegate has an active term
 */
function eap_has_active_term($term_start, $term_end) {
    if (empty($term_start) || empty($term_end)) {
        return false;
    }
    
    $current_date = time();
    
    // Parse term dates (format: MM/YYYY)
    $start_parts = explode('/', $term_start);
    $end_parts = explode('/', $term_end);
    
    if (count($start_parts) !== 2 || count($end_parts) !== 2) {
        return false;
    }
    
    $start_timestamp = mktime(0, 0, 0, intval($start_parts[0]), 1, intval($start_parts[1]));
    $end_timestamp = mktime(23, 59, 59, intval($end_parts[0]), cal_days_in_month(CAL_GREGORIAN, intval($end_parts[0]), intval($end_parts[1])), intval($end_parts[1]));
    
    return ($current_date >= $start_timestamp && $current_date <= $end_timestamp);
}

/**
 * AJAX handler to toggle delegate active status
 */
function eap_ajax_toggle_delegate_active() {
    // Verify nonce
    $nonce = isset($_POST['nonce']) ? $_POST['nonce'] : '';
    if (!wp_verify_nonce($nonce, 'eap_toggle_active_' . get_current_user_id())) {
        wp_send_json_error('Invalid nonce');
        return;
    }
    
    // Check permissions
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Insufficient permissions');
        return;
    }
    
    $user_id = isset($_POST['user_id']) ? intval($_POST['user_id']) : 0;
    $is_active = isset($_POST['is_active']) ? sanitize_text_field($_POST['is_active']) : '1';
    
    if (!$user_id) {
        wp_send_json_error('Invalid user ID');
        return;
    }
    
    $user = get_userdata($user_id);
    if (!$user) {
        wp_send_json_error('User not found');
        return;
    }
    
    // Get old value for audit log
    $old_value = get_user_meta($user_id, 'is_active', true);
    $old_status = ($old_value === '0' || $old_value === false) ? 'Inactive' : 'Active';
    $new_status = ($is_active === '0') ? 'Inactive' : 'Active';
    
    // Update the meta
    update_user_meta($user_id, 'is_active', $is_active);
    
    // Log the change
    eap_log_event(
        sprintf('Updated active status for %s (%s): %s → %s',
            $user->display_name,
            $user->user_email,
            $old_status,
            $new_status
        ),
        [
            'user_id' => $user_id,
            'field' => 'is_active',
            'old_value' => $old_status,
            'new_value' => $new_status
        ],
        'profile_update'
    );
    
    wp_send_json_success([
        'message' => 'Active status updated',
        'user_id' => $user_id,
        'is_active' => $is_active
    ]);
}
add_action('wp_ajax_eap_toggle_delegate_active', 'eap_ajax_toggle_delegate_active');

/**
 * Handle inline updates from the Delegate Administration accordion.
 */
function eap_ajax_delegate_inline_update() {
    check_ajax_referer('eap_delegate_inline_edit', 'nonce');

    if (!current_user_can('manage_options')) {
        wp_send_json_error('Insufficient permissions');
    }

    $user_id = isset($_POST['user_id']) ? absint($_POST['user_id']) : 0;
    $field = isset($_POST['field']) ? sanitize_key($_POST['field']) : '';
    $value = isset($_POST['value']) ? wp_unslash($_POST['value']) : '';

    if (!$user_id || empty($field)) {
        wp_send_json_error('Invalid request.');
    }

    $user = get_userdata($user_id);
    if (!$user) {
        wp_send_json_error('User not found.');
    }

    $meta_fields = [
        'first_name' => 'sanitize_text_field',
        'last_name' => 'sanitize_text_field',
        'preferred_email' => 'sanitize_email',
        'city' => 'sanitize_text_field',
        'phone' => 'sanitize_text_field',
        'whatsapp_number' => 'sanitize_text_field',
        'society' => 'sanitize_text_field',
        'institution' => 'sanitize_text_field',
        'term_start' => 'sanitize_text_field',
        'term_end' => 'sanitize_text_field',
        'eap_council' => 'sanitize_key',
        'biography' => 'sanitize_textarea_field',
        'languages' => 'sanitize_text_field',
        'uems_status' => 'sanitize_text_field',
        'uems_notes' => 'sanitize_textarea_field',
        'uems_date_confirmed' => 'eap_normalize_date_value',
        'internal_notes' => 'sanitize_textarea_field',
        'country' => 'absint',
        'role_custom_label' => 'sanitize_text_field',
    ];

    $response_value = '';

    if ($field === 'user_email') {
        $sanitized_email = sanitize_email($value);
        if (empty($sanitized_email) || !is_email($sanitized_email)) {
            wp_send_json_error('Please enter a valid email address.');
        }
        if (email_exists($sanitized_email) && strtolower($sanitized_email) !== strtolower($user->user_email)) {
            wp_send_json_error('Email address already in use.');
        }

        $result = wp_update_user([
            'ID' => $user_id,
            'user_email' => $sanitized_email,
        ]);

        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        }

        $response_value = $sanitized_email;
        eap_log_event(
            'Updated delegate email via inline editor',
            ['user_id' => $user_id, 'email' => $sanitized_email],
            'profile_update'
        );
        wp_send_json_success(['value' => $response_value]);
    } elseif ($field === 'role') {
        $role = sanitize_key($value);
        $allowed_roles = eap_get_member_roles();
        if (!in_array($role, $allowed_roles, true)) {
            wp_send_json_error('Invalid role selection.');
        }
        $user->set_role($role);
        eap_log_event(
            'Updated delegate role via inline editor',
            ['user_id' => $user_id, 'role' => $role],
            'profile_update'
        );
        wp_send_json_success(['value' => $role]);
    } elseif ($field === 'preferred_email') {
        $sanitized_email = sanitize_email($value);
        if (!empty($value) && (empty($sanitized_email) || !is_email($sanitized_email))) {
            wp_send_json_error('Please enter a valid preferred email.');
        }
        if ($sanitized_email) {
            update_user_meta($user_id, 'preferred_email', $sanitized_email);
        } else {
            delete_user_meta($user_id, 'preferred_email');
        }
        wp_send_json_success(['value' => $sanitized_email]);
    } elseif ($field === 'country') {
        $country_id = absint($value);
        if ($country_id > 0) {
            update_user_meta($user_id, 'country', $country_id);
            $response_value = (string) $country_id;
        } else {
            delete_user_meta($user_id, 'country');
        }
        wp_send_json_success(['value' => $response_value]);
    } elseif ($field === 'eap_council') {
        $allowed_councils = ['', 'pcc', 'stcc', 'both'];
        $council_value = in_array($value, $allowed_councils, true) ? $value : '';
        if ($council_value) {
            update_user_meta($user_id, 'eap_council', $council_value);
        } else {
            delete_user_meta($user_id, 'eap_council');
        }
        wp_send_json_success(['value' => $council_value]);
    } elseif (array_key_exists($field, $meta_fields)) {
        $callback = $meta_fields[$field];
        $sanitized = is_callable($callback) ? call_user_func($callback, $value) : '';

        if ($field === 'biography') {
            $sanitized = sanitize_textarea_field($value);
        }

        if ($field === 'uems_date_confirmed') {
            $sanitized = eap_normalize_date_value($value);
        }

        if ($field === 'uems_notes') {
            $sanitized = sanitize_textarea_field($value);
        }

        if ($field === 'languages') {
            $sanitized = sanitize_text_field($value);
        }

        if ($field === 'internal_notes') {
            $sanitized = sanitize_textarea_field($value);
        }

        if ($field === 'uems_status') {
            $sanitized = sanitize_text_field($value);
        }

        if ($field === 'role_custom_label') {
            $sanitized = sanitize_text_field($value);
        }

        if ($sanitized === '') {
            delete_user_meta($user_id, $field);
            $response_value = '';
        } else {
            update_user_meta($user_id, $field, $sanitized);
            $response_value = $sanitized;
        }

        if (in_array($field, ['term_start', 'term_end'], true)) {
            $response_value = $sanitized;
        }

        wp_send_json_success(['value' => $response_value]);
    }

    wp_send_json_error('Unsupported field update.');
}
add_action('wp_ajax_eap_delegate_inline_update', 'eap_ajax_delegate_inline_update');

/**
 * Delete a delegate from the inline administration UI.
 */
function eap_ajax_delete_delegate_user() {
    check_ajax_referer('eap_delegate_delete_user', 'nonce');

    if (!current_user_can('delete_users')) {
        wp_send_json_error('You are not allowed to delete users.');
    }

    $user_id = isset($_POST['user_id']) ? absint($_POST['user_id']) : 0;
    if (!$user_id) {
        wp_send_json_error('Invalid user.');
    }

    if ($user_id === get_current_user_id()) {
        wp_send_json_error('You cannot delete your own account.');
    }

    $user = get_userdata($user_id);
    if (!$user) {
        wp_send_json_error('User not found.');
    }

    require_once ABSPATH . 'wp-admin/includes/user.php';
    $deleted = wp_delete_user($user_id);

    if (!$deleted) {
        wp_send_json_error('Failed to delete user.');
    }

    eap_log_event(
        sprintf('Deleted delegate "%s" (%s)', $user->display_name, $user->user_email),
        ['user_id' => $user_id],
        'user'
    );

    wp_send_json_success();
}
add_action('wp_ajax_eap_delete_delegate_user', 'eap_ajax_delete_delegate_user');

/**
 * AJAX handler to add a new delegate from the Delegate Administration page.
 */
function eap_ajax_add_delegate() {
    check_ajax_referer('eap_add_delegate', 'nonce');

    if (!current_user_can('create_users')) {
        wp_send_json_error('You do not have permission to create users.');
    }

    // Required fields
    $first_name = isset($_POST['first_name']) ? sanitize_text_field($_POST['first_name']) : '';
    $last_name = isset($_POST['last_name']) ? sanitize_text_field($_POST['last_name']) : '';
    $email = isset($_POST['email']) ? sanitize_email($_POST['email']) : '';
    $role = isset($_POST['role']) ? sanitize_key($_POST['role']) : '';

    // Validate required fields
    if (empty($first_name)) {
        wp_send_json_error('First name is required.');
    }
    if (empty($last_name)) {
        wp_send_json_error('Last name is required.');
    }
    if (empty($email) || !is_email($email)) {
        wp_send_json_error('A valid email address is required.');
    }
    if (empty($role)) {
        wp_send_json_error('Please select a role.');
    }

    // Check if email already exists
    if (email_exists($email)) {
        wp_send_json_error('A user with this email address already exists.');
    }

    // Validate role
    $allowed_roles = eap_get_member_roles();
    if (!in_array($role, $allowed_roles, true)) {
        wp_send_json_error('Invalid role selected.');
    }

    // Generate a username from the email
    $username = sanitize_user(strtolower(explode('@', $email)[0]), true);
    $original_username = $username;
    $counter = 1;
    while (username_exists($username)) {
        $username = $original_username . $counter;
        $counter++;
    }

    // Generate a random password
    $password = wp_generate_password(12, true, false);

    // Create the user
    $user_id = wp_insert_user([
        'user_login' => $username,
        'user_email' => $email,
        'user_pass' => $password,
        'first_name' => $first_name,
        'last_name' => $last_name,
        'display_name' => $first_name . ' ' . $last_name,
        'role' => $role,
    ]);

    if (is_wp_error($user_id)) {
        wp_send_json_error($user_id->get_error_message());
    }

    // Set the user as active by default
    update_user_meta($user_id, 'is_active', '1');

    // Optional fields
    $country = isset($_POST['country']) ? absint($_POST['country']) : 0;
    $eap_council = isset($_POST['eap_council']) ? sanitize_key($_POST['eap_council']) : '';
    $society = isset($_POST['society']) ? sanitize_text_field($_POST['society']) : '';
    $institution = isset($_POST['institution']) ? sanitize_text_field($_POST['institution']) : '';
    $term_start = isset($_POST['term_start']) ? sanitize_text_field($_POST['term_start']) : '';
    $term_end = isset($_POST['term_end']) ? sanitize_text_field($_POST['term_end']) : '';
    $send_welcome_email = isset($_POST['send_welcome_email']) && $_POST['send_welcome_email'] === '1';

    // Save optional meta fields
    if ($country > 0) {
        update_user_meta($user_id, 'country', $country);
    }
    if (!empty($eap_council)) {
        update_user_meta($user_id, 'eap_council', $eap_council);
    }
    if (!empty($society)) {
        update_user_meta($user_id, 'society', $society);
    }
    if (!empty($institution)) {
        update_user_meta($user_id, 'institution', $institution);
    }
    if (!empty($term_start)) {
        update_user_meta($user_id, 'term_start', $term_start);
    }
    if (!empty($term_end)) {
        update_user_meta($user_id, 'term_end', $term_end);
    }

    // Send welcome email if requested
    if ($send_welcome_email) {
        // Get the password reset link
        $reset_key = get_password_reset_key(get_userdata($user_id));
        if (!is_wp_error($reset_key)) {
            $reset_url = network_site_url("wp-login.php?action=rp&key=$reset_key&login=" . rawurlencode($username), 'login');
            
            $subject = 'EAP National Delegate Profile - Set Your Password';
            $message = eap_get_activation_email_html($first_name, $reset_url);
            $headers = array('Content-Type: text/html; charset=UTF-8');
            
            wp_mail($email, $subject, $message, $headers);
        }
    }

    // Log the event
    eap_log_event(
        sprintf('Created new delegate: %s %s (%s)', $first_name, $last_name, $email),
        [
            'user_id' => $user_id,
            'email' => $email,
            'role' => $role,
            'welcome_email_sent' => $send_welcome_email,
        ],
        'user'
    );

    wp_send_json_success([
        'user_id' => $user_id,
        'message' => sprintf('Delegate "%s %s" created successfully.', $first_name, $last_name),
    ]);
}
add_action('wp_ajax_eap_add_delegate', 'eap_ajax_add_delegate');

/**
 * AJAX handler for profile photo uploads on the front-end.
 * Allows logged-in members to upload their profile photos directly
 * without needing access to WordPress media library.
 */
function eap_ajax_upload_profile_photo() {
    // Verify user is logged in
    if (!is_user_logged_in()) {
        wp_send_json_error(['message' => 'You must be logged in to upload photos.']);
        return;
    }
    
    $current_user_id = get_current_user_id();
    $target_user_id = isset($_POST['user_id']) ? intval($_POST['user_id']) : $current_user_id;
    
    // Verify permissions - users can only upload their own photos unless they're an admin
    if ($target_user_id !== $current_user_id && !current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'You do not have permission to upload photos for other users.']);
        return;
    }
    
    // Verify nonce
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'eap_profile_photo_upload')) {
        wp_send_json_error(['message' => 'Security check failed.']);
        return;
    }
    
    // Check if file was uploaded
    if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        wp_send_json_error(['message' => 'No file uploaded or upload error occurred.']);
        return;
    }
    
    $file = $_FILES['file'];
    
    // Validate file type
    $allowed_types = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp'];
    $file_type = wp_check_filetype($file['name']);
    
    if (!in_array($file['type'], $allowed_types)) {
        wp_send_json_error(['message' => 'Invalid file type. Only JPG, PNG, GIF, and WebP images are allowed.']);
        return;
    }
    
    // Validate file size (5MB max)
    if ($file['size'] > 5 * 1024 * 1024) {
        wp_send_json_error(['message' => 'File size exceeds 5MB limit.']);
        return;
    }
    
    // Save to LLW_CONFIDENTIAL folder instead of regular uploads
    $upload_dir = wp_upload_dir();
    $confidential_path = $upload_dir['basedir'] . '/LLW_CONFIDENTIAL';
    
    // Ensure the confidential directory exists
    if (!file_exists($confidential_path)) {
        wp_mkdir_p($confidential_path);
        // Add .htaccess to deny direct access
        @file_put_contents($confidential_path . '/.htaccess', "Deny from all" . PHP_EOL);
        @file_put_contents($confidential_path . '/index.php', "<?php // Silence is golden.");
    }
    
    // Generate a unique filename
    $file_ext = pathinfo($file['name'], PATHINFO_EXTENSION);
    $unique_filename = 'profile_' . $target_user_id . '_' . time() . '.' . $file_ext;
    $destination_path = $confidential_path . '/' . $unique_filename;
    
    // Move the uploaded file to the confidential directory
    if (!move_uploaded_file($file['tmp_name'], $destination_path)) {
        wp_send_json_error(['message' => 'Failed to save file to secure storage.']);
        return;
    }
    
    // Store only the filename (not full path) for security
    $stored_filename = $unique_filename;
    
    // Save avatar to custom table (store filename instead of URL)
    eap_save_avatar($target_user_id, $stored_filename, null, $file['size'], $file['type']);
    
    // Also save to user meta for backwards compatibility (store filename, not URL)
    update_user_meta($target_user_id, 'photo_url', $stored_filename);
    
    // Log the upload
    $target_user = get_userdata($target_user_id);
    eap_log_event(
        sprintf('Profile photo uploaded to secure storage for user "%s" (ID: %d): %s', $target_user->user_login, $target_user_id, $stored_filename),
        [
            'user_id' => $target_user_id,
            'user_login' => $target_user->user_login,
            'file_name' => $stored_filename,
            'file_size' => $file['size'],
            'storage' => 'LLW_CONFIDENTIAL'
        ],
        'file'
    );
    
    // Return success with a secure URL that goes through our endpoint
    $secure_url = add_query_arg([
        'eap_secure_image' => '1',
        'user_id' => $target_user_id,
        'file' => $stored_filename,
        'nonce' => wp_create_nonce('eap_secure_image_' . $target_user_id)
    ], home_url());
    
    wp_send_json_success([
        'url' => $secure_url,
        'message' => 'Photo uploaded and stored securely!'
    ]);
}
add_action('wp_ajax_eap_upload_profile_photo', 'eap_ajax_upload_profile_photo');

/**
 * Secure image serving endpoint
 * Serves profile images from LLW_CONFIDENTIAL with permission checks
 */
function eap_serve_secure_profile_image() {
    // Check if this is a secure image request
    if (!isset($_GET['eap_secure_image']) || $_GET['eap_secure_image'] !== '1') {
        return;
    }
    
    // Get parameters
    $user_id = isset($_GET['user_id']) ? intval($_GET['user_id']) : 0;
    $filename = isset($_GET['file']) ? sanitize_file_name($_GET['file']) : '';
    $nonce = isset($_GET['nonce']) ? $_GET['nonce'] : '';
    
    // Validate parameters
    if (!$user_id || !$filename) {
        status_header(400);
        die('Invalid request');
    }
    
    // Verify nonce
    if (!wp_verify_nonce($nonce, 'eap_secure_image_' . $user_id)) {
        status_header(403);
        die('Security check failed');
    }
    
    // Check if user is logged in
    if (!is_user_logged_in()) {
        status_header(403);
        die('You must be logged in to view this image');
    }
    
    // Check if current user has permission to view this profile
    $current_user = wp_get_current_user();
    $can_view = false;
    
    // User can view their own image
    if ($current_user->ID === $user_id) {
        $can_view = true;
    }
    // Admins can view all images
    elseif (current_user_can('manage_options')) {
        $can_view = true;
    }
    // Portal members can view other members' images (unless privacy is set to private)
    elseif (eap_is_portal_member($current_user)) {
        // Check privacy setting
        $visibility = get_user_meta($user_id, 'visibility_photo_url', true);
        if ($visibility !== 'private') {
            $can_view = true;
        }
    }
    
    if (!$can_view) {
        status_header(403);
        die('You do not have permission to view this image');
    }
    
    // Construct file path
    $upload_dir = wp_upload_dir();
    $file_path = $upload_dir['basedir'] . '/LLW_CONFIDENTIAL/' . $filename;
    
    // Validate file exists and is within the confidential directory
    if (!file_exists($file_path) || strpos(realpath($file_path), realpath($upload_dir['basedir'] . '/LLW_CONFIDENTIAL')) !== 0) {
        status_header(404);
        die('Image not found');
    }
    
    // Get mime type
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime_type = finfo_file($finfo, $file_path);
    finfo_close($finfo);
    
    // Serve the file
    header('Content-Type: ' . $mime_type);
    header('Content-Length: ' . filesize($file_path));
    header('Cache-Control: private, max-age=3600');
    header('Expires: ' . gmdate('D, d M Y H:i:s', time() + 3600) . ' GMT');
    
    readfile($file_path);
    exit;
}
add_action('template_redirect', 'eap_serve_secure_profile_image', 1);

/**
 * Save avatar to custom table
 */
function eap_save_avatar($user_id, $avatar_url, $attachment_id = null, $file_size = null, $mime_type = null) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'eap_avatars';
    
    // Deactivate any existing avatars for this user
    $wpdb->update(
        $table_name,
        ['is_active' => 0],
        ['user_id' => $user_id],
        ['%d'],
        ['%d']
    );
    
    // Insert new avatar
    $wpdb->insert(
        $table_name,
        [
            'user_id' => $user_id,
            'avatar_url' => $avatar_url,
            'attachment_id' => $attachment_id,
            'uploaded_date' => current_time('mysql'),
            'file_size' => $file_size,
            'mime_type' => $mime_type,
            'is_active' => 1
        ],
        ['%d', '%s', '%d', '%s', '%d', '%s', '%d']
    );
    
    return $wpdb->insert_id;
}

/**
 * Get user's active avatar from custom table
 */
function eap_get_user_avatar($user_id) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'eap_avatars';
    
    $avatar = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM $table_name WHERE user_id = %d AND is_active = 1 ORDER BY id DESC LIMIT 1",
        $user_id
    ));
    
    return $avatar;
}

/**
 * Convert a profile image filename to a secure URL
 * @param int $user_id The user ID
 * @param string $filename The filename stored in the database
 * @return string The secure URL to access the image
 */
function eap_get_secure_image_url($user_id, $filename) {
    if (empty($filename)) {
        return '';
    }
    
    // Check if this is already a full URL (backwards compatibility)
    if (filter_var($filename, FILTER_VALIDATE_URL)) {
        return $filename;
    }
    
    // Generate secure URL
    $secure_url = add_query_arg([
        'eap_secure_image' => '1',
        'user_id' => $user_id,
        'file' => $filename,
        'nonce' => wp_create_nonce('eap_secure_image_' . $user_id)
    ], home_url());
    
    return $secure_url;
}

/**
 * Filter WordPress get_avatar to use custom EAP avatars
 * This integrates our custom avatar system with WordPress's avatar functionality
 * and prevents conflicts with gravatar settings
 */
function eap_custom_avatar($avatar, $id_or_email, $size, $default, $alt) {
    $user_id = null;
    
    // Get user ID from various input types
    if (is_numeric($id_or_email)) {
        $user_id = (int) $id_or_email;
    } elseif (is_string($id_or_email) && is_email($id_or_email)) {
        $user = get_user_by('email', $id_or_email);
        if ($user) {
            $user_id = $user->ID;
        }
    } elseif ($id_or_email instanceof WP_User) {
        $user_id = $id_or_email->ID;
    } elseif ($id_or_email instanceof WP_Post) {
        $user_id = $id_or_email->post_author;
    } elseif ($id_or_email instanceof WP_Comment) {
        if (!empty($id_or_email->user_id)) {
            $user_id = $id_or_email->user_id;
        }
    }
    
    if (!$user_id) {
        return $avatar;
    }
    
    // Check if user is a portal member
    $user = get_userdata($user_id);
    if (!$user || !eap_is_portal_member($user)) {
        return $avatar;
    }
    
    // Try to get avatar from custom table first
    $custom_avatar = eap_get_user_avatar($user_id);
    
    if ($custom_avatar && !empty($custom_avatar->avatar_url)) {
        $avatar_filename = $custom_avatar->avatar_url;
    } else {
        // Fallback to user meta (backwards compatibility)
        $avatar_filename = get_user_meta($user_id, 'photo_url', true);
    }
    
    if (!$avatar_filename) {
        return $avatar; // No custom avatar, use default WordPress avatar/gravatar
    }
    
    // Check field privacy
    if (!eap_can_view_field($user_id, 'photo_url')) {
        return $avatar; // User has set photo to private
    }
    
    // Convert filename to secure URL
    $avatar_url = eap_get_secure_image_url($user_id, $avatar_filename);
    
    if (!$avatar_url) {
        return $avatar;
    }
    
    // Build custom avatar HTML
    $avatar = sprintf(
        '<img alt="%s" src="%s" class="avatar avatar-%d photo eap-custom-avatar" height="%d" width="%d" loading="lazy" decoding="async" />',
        esc_attr($alt),
        esc_url($avatar_url),
        (int) $size,
        (int) $size,
        (int) $size
    );
    
    return $avatar;
}
add_filter('get_avatar', 'eap_custom_avatar', 10, 5);

/**
 * Add option to use custom avatar in user profile
 * This adds a checkbox in WordPress user profile settings
 */
function eap_avatar_profile_field($user) {
    if (!eap_is_portal_member($user)) {
        return;
    }
    
    $custom_avatar = eap_get_user_avatar($user->ID);
    $photo_url = get_user_meta($user->ID, 'photo_url', true);
    
    ?>
    <h3>EAP Profile Photo</h3>
    <table class="form-table">
        <tr>
            <th><label>Custom Avatar</label></th>
            <td>
                <?php if ($custom_avatar && $custom_avatar->avatar_url): 
                    $secure_avatar_url = eap_get_secure_image_url($user->ID, $custom_avatar->avatar_url);
                ?>
                    <img src="<?php echo esc_url($secure_avatar_url); ?>" alt="<?php echo esc_attr($user->display_name); ?>" style="max-width: 150px; height: auto; border-radius: 50%;" />
                    <p class="description">
                        This custom profile photo is used across the EAP portal and replaces your gravatar.
                        <br>Uploaded: <?php echo esc_html($custom_avatar->uploaded_date); ?>
                    </p>
                <?php elseif ($photo_url): 
                    $secure_photo_url = eap_get_secure_image_url($user->ID, $photo_url);
                ?>
                    <img src="<?php echo esc_url($secure_photo_url); ?>" alt="<?php echo esc_attr($user->display_name); ?>" style="max-width: 150px; height: auto; border-radius: 50%;" />
                    <p class="description">
                        This custom profile photo is used across the EAP portal and replaces your gravatar.
                    </p>
                <?php else: ?>
                    <p class="description">No custom avatar set. Edit your profile to upload one.</p>
                <?php endif; ?>
            </td>
        </tr>
    </table>
    <?php
}
add_action('show_user_profile', 'eap_avatar_profile_field');
add_action('edit_user_profile', 'eap_avatar_profile_field');


// === 13. DISCUSSIONS & FORUM FUNCTIONALITY ===
// 
// IMPORTANT: Discussion/forum functionality has been moved to a separate addon plugin.
// The EAP Discussions addon plugin is located in: discussions_plugin/eap-discussions.php
// 
// When the addon is activated, it provides:
// - eap_render_discussion_section() - Renders the discussion section on working groups
// - eap_get_discussion() - Retrieves discussion data from database
// - eap_get_discussion_attachment_path() - Resolves file paths for attachments
// - eap_create_discussion(), eap_delete_discussion(), etc. - CRUD operations
// - All AJAX handlers for discussions
// 
// When the addon is NOT active:
// - No discussion sections are displayed on working group pages
// - The file download handler (above) gracefully handles missing addon
// - No errors are thrown
// 
// To enable discussions, install and activate the EAP Discussions addon plugin.
// The addon folder should be moved outside this plugin directory on production.

?>