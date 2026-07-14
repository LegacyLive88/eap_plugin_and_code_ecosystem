<?php
/**
 * Google Drive sync for Working Group resources.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class EAP_Workgroup_Drive_Sync {

    private static $instance;

    private $base_folder_id = '';

    private $token_transient = 'eap_drive_access_token';

    private $lock_key = 'eap_drive_sync_lock';

    private $full_sync_option = 'eap_drive_last_full_sync';

    private $default_full_sync_interval = 15 * MINUTE_IN_SECONDS;

    private $folder_cache = null;

    /**
     * Initialize the service if constants are present.
     */
    public static function bootstrap() {
        if ( ! self::is_configured() ) {
            return;
        }

        self::instance();
    }

    /**
     * Whether Drive syncing can run with the available config.
     *
     * @return bool
     */
    public static function is_configured() {
        return defined( 'EAP_DRIVE_ROOT_FOLDER_ID' ) && EAP_DRIVE_ROOT_FOLDER_ID
            && defined( 'EAP_DRIVE_SERVICE_ACCOUNT_JSON' ) && EAP_DRIVE_SERVICE_ACCOUNT_JSON;
    }

    /**
     * Get the singleton instance.
     *
     * @return self
     */
    public static function instance() {
        if ( ! self::$instance ) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    private function __construct() {
        // Use the new Google Drive folder ID for workgroups
        $this->base_folder_id = defined( 'EAP_DRIVE_ROOT_FOLDER_ID' )
            ? EAP_DRIVE_ROOT_FOLDER_ID
            : '1jLYIXkdj79pByFP7E5m8is1uGYNo80Om';

        add_action( 'init', [ $this, 'maybe_schedule_cron' ] );
        add_action( 'eap_run_drive_sync', [ $this, 'handle_scheduled_sync' ] );

        if ( defined( 'WP_CLI' ) && WP_CLI ) {
            \WP_CLI::add_command( 'eap drive-sync', [ $this, 'cli_sync' ] );
        }
    }

    /**
     * Schedule the cron event unless disabled.
     */
    public function maybe_schedule_cron() {
        if ( defined( 'EAP_DRIVE_DISABLE_CRON' ) && true === EAP_DRIVE_DISABLE_CRON ) {
            return;
        }

        $requested_interval = defined( 'EAP_DRIVE_SYNC_CRON_INTERVAL' )
            ? EAP_DRIVE_SYNC_CRON_INTERVAL
            : 'hourly';

        $requested_interval = apply_filters( 'eap_drive_sync_cron_interval', $requested_interval );
        $schedules          = wp_get_schedules();

        if ( ! isset( $schedules[ $requested_interval ] ) ) {
            $requested_interval = 'hourly';
        }

        if ( ! wp_next_scheduled( 'eap_run_drive_sync' ) ) {
            wp_schedule_event(
                time() + ( 5 * MINUTE_IN_SECONDS ),
                $requested_interval,
                'eap_run_drive_sync'
            );
        }
    }

    /**
     * Cron entry point.
     */
    public function handle_scheduled_sync() {
        $this->run_sync( 'cron' );
    }

    /**
     * WP-CLI hook.
     */
    public function cli_sync() {
        $result = $this->run_sync( 'cli' );

        if ( is_wp_error( $result ) ) {
            \WP_CLI::error( $result->get_error_message() );
        }

        \WP_CLI::success(
            sprintf(
                'Synced %d folder(s), downloaded %d file(s).',
                $result['folders'],
                $result['files_downloaded']
            )
        );
    }

    /**
     * Run the sync process.
     *
     * @param string $context
     * @return array|\WP_Error
     */
    public function run_sync( $context = 'manual' ) {
        if ( ! self::is_configured() ) {
            return new \WP_Error( 'eap_drive_unconfigured', 'Drive sync is not configured.' );
        }

        if ( ! $this->acquire_lock() ) {
            return new \WP_Error( 'eap_drive_locked', 'A Drive sync is already running.' );
        }

        $stats = [
            'folders'          => 0,
            'files_downloaded' => 0,
        ];

        try {
            $folders = $this->fetch_workgroup_folders();

            foreach ( $folders as $folder ) {
                $result = $this->sync_folder( $folder );
                if ( ! $result ) {
                    continue;
                }

                $stats['folders']++;
                $stats['files_downloaded'] += $result['files_downloaded'];
            }

            if ( function_exists( 'eap_log_event' ) ) {
                eap_log_event(
                    sprintf( 'Google Drive sync completed (%s)', $context ),
                    $stats,
                    'file'
                );
            }
        } catch ( \Throwable $exception ) {
            error_log( '[EAP] Drive sync failed: ' . $exception->getMessage() );

            if ( function_exists( 'eap_log_event' ) ) {
                eap_log_event(
                    'Google Drive sync failed',
                    [
                        'context' => $context,
                        'error'   => $exception->getMessage(),
                    ],
                    'error'
                );
            }

            $this->release_lock();

            return new \WP_Error( 'eap_drive_sync_failed', $exception->getMessage() );
        }

        $this->release_lock();

        update_option( $this->full_sync_option, time() );

        return $stats;
    }

    /**
     * Maybe run a full sync (throttled).
     *
     * @param bool   $force
     * @param string $context
     *
     * @return array|bool|\WP_Error
     */
    public function maybe_full_sync( $force = false, $context = 'frontend' ) {
        if ( ! self::is_configured() ) {
            return new \WP_Error( 'eap_drive_unconfigured', 'Drive sync is not configured.' );
        }

        if ( ! $force ) {
            $counts = wp_count_posts( 'eap_working_group' );
            if ( ! $counts || (int) $counts->publish === 0 ) {
                $force = true;
            }
        }

        $interval = (int) apply_filters(
            'eap_workgroup_drive_full_sync_interval',
            $this->default_full_sync_interval
        );

        $last_run = (int) get_option( $this->full_sync_option, 0 );

        if ( ! $force && $interval > 0 && ( $last_run + $interval ) > time() ) {
            return false;
        }

        return $this->run_sync( $context );
    }

    /**
     * Sync a single workgroup on demand.
     *
     * @param int  $post_id
     * @param bool $force
     *
     * @return array|bool|\WP_Error
     */
    public function sync_single_workgroup( $post_id, $force = false ) {
        if ( ! self::is_configured() ) {
            return new \WP_Error( 'eap_drive_unconfigured', 'Drive sync is not configured.' );
        }

        $post = get_post( $post_id );

        if ( ! $post || 'eap_working_group' !== $post->post_type ) {
            return new \WP_Error( 'eap_drive_invalid_post', 'Invalid working group.' );
        }

        if ( ! $force && $this->should_skip_single_sync( $post_id ) ) {
            return false;
        }

        if ( ! $this->acquire_lock() ) {
            return new \WP_Error( 'eap_drive_locked', 'A Drive sync is already running.' );
        }

        try {
            $folder = $this->resolve_drive_folder_for_post( $post );

            if ( ! $folder || empty( $folder['id'] ) ) {
                throw new \RuntimeException( 'Unable to locate the Google Drive folder for this workgroup.' );
            }

            if ( ! get_post_meta( $post->ID, '_eap_drive_folder_id', true ) ) {
                update_post_meta( $post->ID, '_eap_drive_folder_id', sanitize_text_field( $folder['id'] ) );
            }

            $result = $this->sync_folder( $folder );
        } catch ( \Throwable $exception ) {
            $this->release_lock();
            error_log( '[EAP] Workgroup sync failed: ' . $exception->getMessage() );

            return new \WP_Error( 'eap_drive_sync_failed', $exception->getMessage() );
        }

        $this->release_lock();

        return $result;
    }

    /**
     * Prevent overlapping syncs.
     *
     * @return bool
     */
    private function acquire_lock() {
        if ( get_transient( $this->lock_key ) ) {
            return false;
        }

        return set_transient( $this->lock_key, time(), 20 * MINUTE_IN_SECONDS );
    }

    /**
     * Release the sync lock.
     */
    private function release_lock() {
        delete_transient( $this->lock_key );
    }

    /**
     * Get all Drive folders representing working groups.
     *
     * @return array
     */
    private function fetch_workgroup_folders() {
        if ( null !== $this->folder_cache ) {
            return $this->folder_cache;
        }

        $escaped_id = $this->escape_value_for_query( $this->base_folder_id );

        $folders = $this->list_drive_items(
            [
                'q'       => sprintf(
                    "'%s' in parents and mimeType = 'application/vnd.google-apps.folder' and trashed = false",
                    $escaped_id
                ),
                'orderBy' => 'name_natural asc',
            ]
        );

        $this->folder_cache = $folders;

        return $folders;
    }

    /**
     * Sync a single folder record.
     *
     * @param array $folder
     * @return array|null
     */
    private function sync_folder( array $folder ) {
        if ( empty( $folder['id'] ) || empty( $folder['name'] ) ) {
            return null;
        }

        $post_id = $this->ensure_workgroup_post( $folder['name'], $folder['id'] );

        if ( ! $post_id ) {
            return null;
        }

        // Find the "Open Resources" folder inside this workgroup folder
        $open_resources_folder_id = $this->find_open_resources_folder( $folder['id'] );

        if ( ! $open_resources_folder_id ) {
            // If no "Open Resources" folder found, skip syncing
            return null;
        }

        $this->sync_about_section( $post_id, $open_resources_folder_id );
        $files_downloaded = $this->sync_files_for_post( $post_id, $open_resources_folder_id );

        update_post_meta( $post_id, '_eap_drive_last_sync', current_time( 'mysql' ) );

        return [
            'post_id'          => $post_id,
            'files_downloaded' => $files_downloaded,
        ];
    }

    /**
     * Ensure a WP post exists for the Drive folder.
     *
     * @param string $folder_name
     * @param string $folder_id
     * @return int
     */
    private function ensure_workgroup_post( $folder_name, $folder_id ) {
        $folder_id = sanitize_text_field( $folder_id );
        $post_id   = $this->find_post_by_folder( $folder_id );

        if ( ! $post_id ) {
            $existing = get_page_by_title( $folder_name, OBJECT, 'eap_working_group' );
            if ( $existing ) {
                $post_id = $existing->ID;
            }
        }

        $clean_name = sanitize_text_field( $folder_name );

        if ( ! $post_id ) {
            $post_id = wp_insert_post(
                [
                    'post_type'   => 'eap_working_group',
                    'post_status' => 'publish',
                    'post_title'  => $clean_name,
                    'post_name'   => sanitize_title( $clean_name ),
                ],
                true
            );

            if ( is_wp_error( $post_id ) ) {
                throw new \RuntimeException( 'Unable to create working group: ' . $post_id->get_error_message() );
            }
        } else {
            $current_title = get_the_title( $post_id );
            if ( $current_title !== $clean_name ) {
                wp_update_post(
                    [
                        'ID'         => $post_id,
                        'post_title' => $clean_name,
                    ]
                );
            }
        }

        update_post_meta( $post_id, '_eap_drive_folder_id', $folder_id );
        update_post_meta( $post_id, '_eap_drive_last_remote_name', $clean_name );

        return (int) $post_id;
    }

    /**
     * Find the "Open Resources" folder inside a workgroup folder.
     *
     * @param string $workgroup_folder_id
     * @return string|null
     */
    private function find_open_resources_folder( $workgroup_folder_id ) {
        $escaped_id = $this->escape_value_for_query( $workgroup_folder_id );

        $folders = $this->list_drive_items(
            [
                'q'       => sprintf(
                    "'%s' in parents and mimeType = 'application/vnd.google-apps.folder' and name = 'Open Resources' and trashed = false",
                    $escaped_id
                ),
                'orderBy' => 'name_natural asc',
                'pageSize' => 1,
            ]
        );

        if ( empty( $folders ) ) {
            return null;
        }

        return $folders[0]['id'] ?? null;
    }

    /**
     * Find a post that already maps to the Drive folder.
     *
     * @param string $folder_id
     * @return int
     */
    private function find_post_by_folder( $folder_id ) {
        $posts = get_posts(
            [
                'post_type'      => 'eap_working_group',
                'posts_per_page' => 1,
                'post_status'    => 'any',
                'fields'         => 'ids',
                'meta_query'     => [
                    [
                        'key'   => '_eap_drive_folder_id',
                        'value' => $folder_id,
                    ],
                ],
            ]
        );

        if ( empty( $posts ) ) {
            return 0;
        }

        return (int) $posts[0];
    }

    /**
     * Sync the intro.txt content into post_content.
     *
     * @param int    $post_id
     * @param string $open_resources_folder_id
     */
    private function sync_about_section( $post_id, $open_resources_folder_id ) {
        $about_contents = $this->fetch_about_text( $open_resources_folder_id );

        if ( null === $about_contents ) {
            return;
        }

        $about_contents = trim( $about_contents );

        if ( '' === $about_contents ) {
            return;
        }

        $formatted = $this->prepare_about_html( $about_contents );
        $post      = get_post( $post_id );

        if ( ! $post || $post->post_content === $formatted ) {
            update_post_meta( $post_id, '_eap_drive_about_raw', $about_contents );
            return;
        }

        wp_update_post(
            [
                'ID'           => $post_id,
                'post_content' => $formatted,
            ]
        );

        update_post_meta( $post_id, '_eap_drive_about_raw', $about_contents );
    }

    /**
     * Convert the intro text into safe HTML with linked URLs.
     *
     * @param string $about_contents
     * @return string
     */
    private function prepare_about_html( $about_contents ) {
        $linkified = $this->convert_urls_to_secure_links( $about_contents );

        return wpautop( $linkified );
    }

    /**
     * Convert plain-text URLs to https anchor tags that open in a new tab.
     *
     * @param string $text
     * @return string
     */
    private function convert_urls_to_secure_links( $text ) {
        if ( function_exists( 'eap_convert_text_urls_to_secure_links' ) ) {
            return eap_convert_text_urls_to_secure_links( $text );
        }

        return esc_html( (string) $text );
    }

    /**
     * Pull the intro.txt file contents from Open Resources folder.
     *
     * @param string $open_resources_folder_id
     * @return string|null
     */
    private function fetch_about_text( $open_resources_folder_id ) {
        $escaped_id = $this->escape_value_for_query( $open_resources_folder_id );
        $files      = $this->list_drive_items(
            [
                'q'        => sprintf(
                    "'%s' in parents and name = 'intro.txt' and trashed = false",
                    $escaped_id
                ),
                'orderBy'  => 'modifiedTime desc',
                'pageSize' => 1,
                'fields'   => 'files(id,name),nextPageToken',
            ]
        );

        if ( empty( $files ) ) {
            return null;
        }

        $file_id = $files[0]['id'];
        $url     = sprintf( 'https://www.googleapis.com/drive/v3/files/%s', rawurlencode( $file_id ) );
        $url     = $this->with_query_args(
            $url,
            [
                'alt' => 'media',
            ]
        );

        $response = $this->request_drive(
            $url,
            [
                'timeout' => 20,
            ]
        );

        return wp_remote_retrieve_body( $response );
    }

    /**
     * Sync file copies for a post.
     *
     * @param int    $post_id
     * @param string $open_resources_folder_id
     * @return int Number of files downloaded.
     */
    private function sync_files_for_post( $post_id, $open_resources_folder_id ) {
        $remote_files = $this->list_folder_files_recursive( $open_resources_folder_id );
        $existing     = get_post_meta( $post_id, '_eap_secure_files', true );

        if ( ! is_array( $existing ) ) {
            $existing = [];
        }

        $existing_map = [];
        foreach ( $existing as $file ) {
            if ( empty( $file['drive_id'] ) ) {
                continue;
            }
            $existing_map[ $file['drive_id'] ] = $file;
        }

        $new_records      = [];
        $downloaded_count = 0;

        foreach ( $remote_files as $file ) {
            $drive_id = $file['id'];
            $needs_download = true;

            if ( isset( $existing_map[ $drive_id ] ) ) {
                $local_record   = $existing_map[ $drive_id ];
                $remote_fingerprint = $file['md5Checksum'] ?: $file['modifiedTime'];
                $local_fingerprint  = ! empty( $local_record['drive_checksum'] )
                    ? $local_record['drive_checksum']
                    : ( $local_record['drive_modified'] ?? '' );

                if ( $remote_fingerprint && $remote_fingerprint === $local_fingerprint && ! empty( $local_record['path'] ) && file_exists( $local_record['path'] ) ) {
                    $local_record['name']           = $file['name'];
                    $local_record['drive_modified'] = $file['modifiedTime'];
                    $local_record['drive_checksum'] = $file['md5Checksum'] ?? '';
                    $local_record['drive_size']     = isset( $file['size'] ) ? (int) $file['size'] : 0;
                    $local_record['mime_type']      = $file['mimeType'];
                    // Preserve relative_path if it exists (for nested files)
                    if ( ! empty( $file['relative_path'] ) ) {
                        $local_record['relative_path'] = $file['relative_path'];
                    }
                    $new_records[]                  = $local_record;
                    $needs_download                 = false;
                }

                unset( $existing_map[ $drive_id ] );
            }

            if ( $needs_download ) {
                $downloaded = $this->download_drive_file( $file, $post_id );
                if ( $downloaded ) {
                    $new_records[] = $downloaded;
                    $downloaded_count++;
                }
            }
        }

        // Remove any files that no longer exist remotely.
        foreach ( $existing_map as $stale ) {
            if ( ! empty( $stale['path'] ) && file_exists( $stale['path'] ) ) {
                @unlink( $stale['path'] );
            }
        }

        usort(
            $new_records,
            static function ( $a, $b ) {
                return strcasecmp( $a['name'], $b['name'] );
            }
        );

        update_post_meta( $post_id, '_eap_secure_files', array_values( $new_records ) );

        return $downloaded_count;
    }

    /**
     * Whether a single workgroup sync should be throttled.
     *
     * @param int $post_id
     * @return bool
     */
    private function should_skip_single_sync( $post_id ) {
        $interval = (int) apply_filters( 'eap_workgroup_drive_single_sync_interval', 0 );

        if ( $interval <= 0 ) {
            return false;
        }

        $last_sync = get_post_meta( $post_id, '_eap_drive_last_sync', true );

        if ( ! $last_sync ) {
            return false;
        }

        $timestamp = strtotime( $last_sync );

        if ( ! $timestamp ) {
            return false;
        }

        return ( $timestamp + $interval ) > current_time( 'timestamp' );
    }

    /**
     * Resolve the Drive folder information for the given post.
     *
     * @param \WP_Post $post
     * @return array|null
     */
    private function resolve_drive_folder_for_post( \WP_Post $post ) {
        $folder_id   = get_post_meta( $post->ID, '_eap_drive_folder_id', true );
        $remote_name = get_post_meta( $post->ID, '_eap_drive_last_remote_name', true );

        if ( $folder_id ) {
            if ( ! $remote_name ) {
                $remote_name = $post->post_title;
            }

            return [
                'id'   => $folder_id,
                'name' => $remote_name,
            ];
        }

        $folders = $this->fetch_workgroup_folders();
        $needle  = $this->normalize_drive_name( $post->post_title );

        foreach ( $folders as $folder ) {
            if ( $this->normalize_drive_name( $folder['name'] ?? '' ) === $needle ) {
                return $folder;
            }
        }

        return null;
    }

    /**
     * Produce a normalized token for name comparisons.
     *
     * @param string $value
     * @return string
     */
    private function normalize_drive_name( $value ) {
        if ( ! $value ) {
            return '';
        }

        $value = sanitize_text_field( $value );

        return sanitize_title( $value );
    }

    /**
     * List all files recursively from a Drive folder (excluding intro.txt).
     *
     * @param string $folder_id
     * @return array
     */
    private function list_folder_files_recursive( $folder_id ) {
        $all_files = [];
        $this->list_folder_files_recursive_helper( $folder_id, $all_files );

        // Filter out intro.txt files
        return array_filter(
            $all_files,
            static function ( $file ) {
                return strtolower( $file['name'] ?? '' ) !== 'intro.txt';
            }
        );
    }

    /**
     * Recursive helper to list all files in a folder and its subfolders.
     *
     * @param string $folder_id
     * @param array  $files Reference to array to populate
     * @param string $path_prefix Optional path prefix for nested files
     */
    private function list_folder_files_recursive_helper( $folder_id, &$files, $path_prefix = '' ) {
        $escaped_id = $this->escape_value_for_query( $folder_id );

        // Get all files in this folder
        $folder_files = $this->list_drive_items(
            [
                'q'       => sprintf(
                    "'%s' in parents and mimeType != 'application/vnd.google-apps.folder' and trashed = false",
                    $escaped_id
                ),
                'orderBy' => 'name_natural asc',
            ]
        );

        // Add files with path prefix if needed
        foreach ( $folder_files as $file ) {
            if ( ! empty( $path_prefix ) ) {
                $file['relative_path'] = $path_prefix . '/' . $file['name'];
            } else {
                $file['relative_path'] = $file['name'];
            }
            $files[] = $file;
        }

        // Get all subfolders
        $subfolders = $this->list_drive_items(
            [
                'q'       => sprintf(
                    "'%s' in parents and mimeType = 'application/vnd.google-apps.folder' and trashed = false",
                    $escaped_id
                ),
                'orderBy' => 'name_natural asc',
            ]
        );

        // Recursively process subfolders
        foreach ( $subfolders as $subfolder ) {
            $new_prefix = ! empty( $path_prefix )
                ? $path_prefix . '/' . $subfolder['name']
                : $subfolder['name'];
            $this->list_folder_files_recursive_helper( $subfolder['id'], $files, $new_prefix );
        }
    }

    /**
     * List all non-about files for a Drive folder (legacy method, kept for compatibility).
     *
     * @param string $folder_id
     * @return array
     */
    private function list_folder_files( $folder_id ) {
        $escaped_id = $this->escape_value_for_query( $folder_id );

        $files = $this->list_drive_items(
            [
                'q'       => sprintf(
                    "'%s' in parents and mimeType != 'application/vnd.google-apps.folder' and trashed = false",
                    $escaped_id
                ),
                'orderBy' => 'name_natural asc',
            ]
        );

        if ( empty( $files ) ) {
            return [];
        }

        return array_filter(
            $files,
            static function ( $file ) {
                return strtolower( $file['name'] ?? '' ) !== 'about.txt';
            }
        );
    }

    /**
     * Download a Drive file to the secure directory.
     *
     * @param array $file
     * @param int   $post_id
     * @return array|null
     */
    private function download_drive_file( array $file, $post_id ) {
        if ( empty( $file['id'] ) || empty( $file['name'] ) ) {
            return null;
        }

        if ( isset( $file['mimeType'] ) && strpos( $file['mimeType'], 'application/vnd.google-apps' ) === 0 ) {
            // Native Google Docs formats cannot be downloaded directly without export rules.
            return null;
        }

        $storage_dir = $this->get_storage_dir( $post_id );
        $extension   = pathinfo( $file['name'], PATHINFO_EXTENSION );
        $base_name   = sanitize_file_name( pathinfo( $file['name'], PATHINFO_FILENAME ) );

        if ( '' === $base_name ) {
            $base_name = 'wg-file';
        }

        $unique_fragment = substr( $file['id'], 0, 8 );
        $local_filename  = sprintf(
            '%d-%s-%s%s',
            $post_id,
            $base_name,
            $unique_fragment,
            $extension ? '.' . strtolower( $extension ) : ''
        );

        $target_path = trailingslashit( $storage_dir ) . $local_filename;
        $temp_path   = $target_path . '.tmp';

        $url = sprintf( 'https://www.googleapis.com/drive/v3/files/%s', rawurlencode( $file['id'] ) );
        $url = $this->with_query_args(
            $url,
            [
                'alt' => 'media',
            ]
        );

        $response = wp_remote_get(
            $url,
            [
                'headers'  => $this->get_auth_headers(),
                'timeout'  => 60,
                'stream'   => true,
                'filename' => $temp_path,
            ]
        );

        if ( is_wp_error( $response ) ) {
            if ( file_exists( $temp_path ) ) {
                @unlink( $temp_path );
            }
            throw new \RuntimeException( 'Drive download failed: ' . $response->get_error_message() );
        }

        $code = wp_remote_retrieve_response_code( $response );
        if ( $code < 200 || $code >= 300 ) {
            if ( file_exists( $temp_path ) ) {
                @unlink( $temp_path );
            }
            throw new \RuntimeException( 'Drive download failed with HTTP ' . $code );
        }

        if ( file_exists( $target_path ) ) {
            @unlink( $target_path );
        }

        if ( ! @rename( $temp_path, $target_path ) ) {
            @unlink( $temp_path );
            throw new \RuntimeException( 'Unable to move downloaded file into place.' );
        }

        $result = [
            'name'           => $file['name'],
            'path'           => $target_path,
            'unique_name'    => basename( $target_path ),
            'uploaded'       => $this->format_remote_time( $file['modifiedTime'] ?? null ),
            'drive_id'       => $file['id'],
            'drive_modified' => $file['modifiedTime'] ?? '',
            'drive_checksum' => $file['md5Checksum'] ?? '',
            'drive_size'     => isset( $file['size'] ) ? (int) $file['size'] : 0,
            'mime_type'      => $file['mimeType'] ?? '',
        ];

        // Preserve relative_path if it exists (for nested files)
        if ( ! empty( $file['relative_path'] ) ) {
            $result['relative_path'] = $file['relative_path'];
        }

        return $result;
    }

    /**
     * Ensure the storage path exists.
     *
     * @param int $post_id
     * @return string
     */
    private function get_storage_dir( $post_id ) {
        $uploads = wp_upload_dir();
        $path    = trailingslashit( $uploads['basedir'] ) . 'eap_secure_files/workgroups/' . absint( $post_id );

        $path = apply_filters( 'eap_drive_sync_storage_path', $path, $post_id );

        if ( ! file_exists( $path ) ) {
            wp_mkdir_p( $path );
        }

        return $path;
    }

    /**
     * Convert an ISO timestamp to the site's timezone.
     *
     * @param string|null $remote_time
     * @return string
     */
    private function format_remote_time( $remote_time ) {
        if ( empty( $remote_time ) ) {
            return current_time( 'mysql' );
        }

        $timestamp = strtotime( $remote_time );

        if ( ! $timestamp ) {
            return current_time( 'mysql' );
        }

        $gmt = gmdate( 'Y-m-d H:i:s', $timestamp );

        return get_date_from_gmt( $gmt, 'Y-m-d H:i:s' );
    }

    /**
     * List Drive files with pagination support.
     *
     * @param array $params
     * @return array
     */
    private function list_drive_items( array $params ) {
        $items     = [];
        $pageToken = null;

        do {
            $query = array_merge(
                [
                    'pageSize'                   => 100,
                    'fields'                     => 'files(id,name,mimeType,modifiedTime,size,md5Checksum),nextPageToken',
                    'supportsAllDrives'          => 'true',
                    'includeItemsFromAllDrives'  => 'true',
                    'spaces'                     => 'drive',
                ],
                $params
            );

            if ( $pageToken ) {
                $query['pageToken'] = $pageToken;
            }

            $url      = $this->with_query_args( 'https://www.googleapis.com/drive/v3/files', $query );
            $response = $this->request_drive(
                $url,
                [
                    'timeout' => 30,
                ]
            );

            $body = json_decode( wp_remote_retrieve_body( $response ), true );

            if ( json_last_error() !== JSON_ERROR_NONE ) {
                throw new \RuntimeException( 'Unable to decode Drive response.' );
            }

            if ( ! empty( $body['files'] ) ) {
                $items = array_merge( $items, $body['files'] );
            }

            $pageToken = $body['nextPageToken'] ?? null;
        } while ( $pageToken );

        return $items;
    }

    /**
     * Perform an authorized GET request.
     *
     * @param string $url
     * @param array  $args
     * @return array|WP_Error
     */
    private function request_drive( $url, array $args = [] ) {
        $headers = $this->get_auth_headers();
        if ( isset( $args['headers'] ) ) {
            $headers = array_merge( $headers, $args['headers'] );
        }

        $args['headers'] = $headers;

        $response = wp_remote_get( $url, $args );

        if ( is_wp_error( $response ) ) {
            throw new \RuntimeException( $response->get_error_message() );
        }

        $code = wp_remote_retrieve_response_code( $response );

        if ( $code < 200 || $code >= 300 ) {
            throw new \RuntimeException( 'Drive API error (HTTP ' . $code . ')' );
        }

        return $response;
    }

    /**
     * Authorization headers for API calls.
     *
     * @return array
     */
    private function get_auth_headers() {
        $token = $this->get_access_token();

        return [
            'Authorization' => 'Bearer ' . $token,
            'Accept'        => 'application/json',
        ];
    }

    /**
     * Retrieve or mint an access token.
     *
     * @return string
     */
    private function get_access_token() {
        $cached = get_transient( $this->token_transient );

        if ( $cached ) {
            return $cached;
        }

        $creds = $this->get_credentials();

        $jwt      = $this->build_jwt( $creds );
        $tokenUri = ! empty( $creds['token_uri'] ) ? $creds['token_uri'] : 'https://oauth2.googleapis.com/token';

        $response = wp_remote_post(
            $tokenUri,
            [
                'headers' => [
                    'Content-Type' => 'application/x-www-form-urlencoded',
                ],
                'body'    => [
                    'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                    'assertion'  => $jwt,
                ],
                'timeout' => 20,
            ]
        );

        if ( is_wp_error( $response ) ) {
            throw new \RuntimeException( 'Unable to fetch Drive token: ' . $response->get_error_message() );
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( empty( $body['access_token'] ) ) {
            throw new \RuntimeException( 'Drive token response is missing access_token.' );
        }

        $expires_in = isset( $body['expires_in'] ) ? (int) $body['expires_in'] : 3600;
        $ttl        = max( 60, $expires_in - 60 );

        set_transient( $this->token_transient, $body['access_token'], $ttl );

        return $body['access_token'];
    }

    /**
     * Load service account credentials.
     *
     * @return array
     */
    private function get_credentials() {
        static $memoized = null;

        if ( null !== $memoized ) {
            return $memoized;
        }

        $source = EAP_DRIVE_SERVICE_ACCOUNT_JSON;
        $json   = '';

        if ( file_exists( $source ) && is_readable( $source ) ) {
            $json = file_get_contents( $source );
        } else {
            $json = $source;
        }

        $data = json_decode( $json, true );

        if ( ! is_array( $data ) || empty( $data['client_email'] ) || empty( $data['private_key'] ) ) {
            throw new \RuntimeException( 'Invalid Google service account JSON.' );
        }

        $data['private_key'] = $this->normalize_private_key( $data['private_key'] );

        $memoized = $data;

        return $data;
    }

    /**
     * Build a signed JWT for the OAuth token exchange.
     *
     * @param array $creds
     * @return string
     */
    private function build_jwt( array $creds ) {
        $now     = time();
        $header  = $this->base64url_encode(
            wp_json_encode(
                [
                    'alg' => 'RS256',
                    'typ' => 'JWT',
                ]
            )
        );
        $payload = [
            'iss'   => $creds['client_email'],
            'scope' => 'https://www.googleapis.com/auth/drive.readonly',
            'aud'   => ! empty( $creds['token_uri'] ) ? $creds['token_uri'] : 'https://oauth2.googleapis.com/token',
            'iat'   => $now,
            'exp'   => $now + 3600,
        ];

        if ( defined( 'EAP_DRIVE_IMPERSONATED_USER' ) && EAP_DRIVE_IMPERSONATED_USER ) {
            $payload['sub'] = EAP_DRIVE_IMPERSONATED_USER;
        }

        $payload = $this->base64url_encode( wp_json_encode( $payload ) );
        $input   = $header . '.' . $payload;

        $signature = '';
        $signed    = openssl_sign( $input, $signature, $creds['private_key'], 'sha256' );

        if ( ! $signed ) {
            throw new \RuntimeException( 'Unable to sign JWT for Drive authentication.' );
        }

        return $input . '.' . $this->base64url_encode( $signature );
    }

    /**
     * Normalize private key formatting.
     *
     * @param string $key
     * @return string
     */
    private function normalize_private_key( $key ) {
        if ( false !== strpos( $key, '\\n' ) ) {
            $key = str_replace( '\\n', "\n", $key );
        }

        return $key;
    }

    /**
     * Encode in base64url.
     *
     * @param string $value
     * @return string
     */
    private function base64url_encode( $value ) {
        return rtrim( strtr( base64_encode( $value ), '+/', '-_' ), '=' );
    }

    /**
     * Append query parameters to a URL.
     *
     * @param string $url
     * @param array  $params
     * @return string
     */
    private function with_query_args( $url, array $params ) {
        if ( empty( $params ) ) {
            return $url;
        }

        $query = http_build_query( $params, '', '&', PHP_QUERY_RFC3986 );

        return $url . ( strpos( $url, '?' ) === false ? '?' : '&' ) . $query;
    }

    /**
     * Escape a value for Drive search queries.
     *
     * @param string $value
     * @return string
     */
    private function escape_value_for_query( $value ) {
        return str_replace( "'", "\\'", trim( $value ) );
    }
}