<?php
/**
 * Plugin Name: External Image Importer
 * Description: Downloads external images on post save and replaces URLs with local media URLs. Configurable settings + manual bulk import.
 * Version: 1.0
 * Author: Patrik Šokman
 * License: GPL2
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class ExternalImageImporter {
    private static $processing = false;

    const OPTION_ENABLED = 'eii_enabled';
    const OPTION_POST_TYPES = 'eii_post_types';
    const OPTION_WHITELIST = 'eii_domain_whitelist';

    public function __construct() {
        add_action('save_post', [ $this, 'import_external_images' ], 20, 2);
        add_action('admin_menu', [ $this, 'add_settings_page' ]);
        add_action('admin_init', [ $this, 'register_settings' ]);
        add_action('admin_post_eii_manual_import', [ $this, 'handle_manual_import' ]);
        add_action('admin_notices', [ $this, 'admin_notices' ]);
    }

    public function import_external_images($post_id, $post) {
        if ( ! get_option(self::OPTION_ENABLED, 1) ) return;
        if (self::$processing) return;
        if ( wp_is_post_revision($post_id) || wp_is_post_autosave($post_id) ) return;
        if ( defined('DOING_AUTOSAVE') && DOING_AUTOSAVE ) return;

        $allowed_post_types = get_option(self::OPTION_POST_TYPES, ['post', 'page']);
        if ( ! in_array($post->post_type, $allowed_post_types) ) return;

        $content = $post->post_content;
        if ( empty($content) ) return;

        $domain_whitelist = array_filter(array_map('trim', explode(',', get_option(self::OPTION_WHITELIST, ''))));
        $updated = false;

        $new_content = $this->process_content_images($content, $post->ID, $domain_whitelist, $updated);

        if ( $updated ) {
            self::$processing = true;
            wp_update_post([
                'ID' => $post_id,
                'post_content' => $new_content,
            ]);
            self::$processing = false;
        }
    }

    private function process_content_images($content, $post_id, $domain_whitelist, &$updated) {
        $home_url = home_url();

        libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        $loaded = $dom->loadHTML('<?xml encoding="utf-8" ?>' . $content, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        if ( ! $loaded ) {
            error_log("ExternalImageImporter: Failed to parse post content for post ID $post_id");
            return $content;
        }

        $imgs = $dom->getElementsByTagName('img');
        if ( $imgs->length === 0 ) return $content;

        foreach ($imgs as $img) {
            $src = $img->getAttribute('src');
            if ( ! $src ) continue;
            if ( strpos($src, $home_url) !== false ) continue; // already local

            $domain = parse_url($src, PHP_URL_HOST);
            if ( $domain_whitelist && ! in_array($domain, $domain_whitelist) ) continue;

            // Download image
            $response = wp_remote_get($src);
            if ( is_wp_error($response) ) {
                error_log('ExternalImageImporter: Failed to download ' . $src . ' - ' . $response->get_error_message());
                continue;
            }

            $code = wp_remote_retrieve_response_code($response);
            if ( $code !== 200 ) {
                error_log('ExternalImageImporter: Non-200 response for ' . $src . ': ' . $code);
                continue;
            }

            $body = wp_remote_retrieve_body($response);
            if ( ! $body ) {
                error_log('ExternalImageImporter: Empty body for ' . $src);
                continue;
            }

            $filename = basename(parse_url($src, PHP_URL_PATH));
            $upload_dir = wp_upload_dir();

            if ( ! wp_mkdir_p($upload_dir['path']) ) {
                error_log('ExternalImageImporter: Upload directory does not exist and cannot be created.');
                continue;
            }

            $unique_file = wp_unique_filename($upload_dir['path'], $filename);
            $file_path = $upload_dir['path'] . '/' . $unique_file;

            $saved = file_put_contents($file_path, $body);
            if ( ! $saved ) {
                error_log('ExternalImageImporter: Failed to save image to ' . $file_path);
                continue;
            }

            $filetype = wp_check_filetype($unique_file, null);
            if ( ! $filetype['type'] ) {
                error_log('ExternalImageImporter: Unsupported filetype for ' . $unique_file);
                @unlink($file_path);
                continue;
            }

            $attachment = [
                'post_mime_type' => $filetype['type'],
                'post_title' => sanitize_file_name($unique_file),
                'post_content' => '',
                'post_status' => 'inherit',
            ];

            require_once( ABSPATH . 'wp-admin/includes/image.php' );
            require_once( ABSPATH . 'wp-admin/includes/file.php' );
            require_once( ABSPATH . 'wp-admin/includes/media.php' );

            $attach_id = wp_insert_attachment($attachment, $file_path, $post_id);
            if ( is_wp_error($attach_id) || ! $attach_id ) {
                error_log('ExternalImageImporter: Failed to create attachment for ' . $file_path);
                @unlink($file_path);
                continue;
            }

            $attach_data = wp_generate_attachment_metadata($attach_id, $file_path);
            wp_update_attachment_metadata($attach_id, $attach_data);

            $local_url = wp_get_attachment_url($attach_id);
            if ( $local_url ) {
                $content = str_replace($src, $local_url, $content);
                $updated = true;
            }
        }

        return $content;
    }

    // Settings page
    public function add_settings_page() {
        add_options_page(
            'External Image Importer Settings',
            'External Image Importer',
            'manage_options',
            'external-image-importer',
            [ $this, 'render_settings_page' ]
        );
    }

    public function register_settings() {
        register_setting('eii_settings_group', self::OPTION_ENABLED, ['type' => 'boolean', 'default' => 1]);
        register_setting('eii_settings_group', self::OPTION_POST_TYPES, ['type' => 'array', 'default' => ['post', 'page']]);
        register_setting('eii_settings_group', self::OPTION_WHITELIST, ['type' => 'string', 'default' => '']);
    }

    public function render_settings_page() {
        if ( ! current_user_can('manage_options') ) {
            wp_die('Access denied');
        }

        $enabled = get_option(self::OPTION_ENABLED, 1);
        $post_types = get_option(self::OPTION_POST_TYPES, ['post', 'page']);
        $whitelist = get_option(self::OPTION_WHITELIST, '');

        $all_post_types = get_post_types(['public' => true], 'objects');

        $import_url = admin_url('admin-post.php?action=eii_manual_import');
        ?>
        <div class="wrap">
            <h1>External Image Importer Settings</h1>
            <form method="post" action="options.php" style="margin-bottom: 2em;">
                <?php settings_fields('eii_settings_group'); ?>
                <?php do_settings_sections('eii_settings_group'); ?>

                <table class="form-table">
                    <tr valign="top">
                        <th scope="row">Enable Import</th>
                        <td>
                            <input type="checkbox" name="<?php echo esc_attr(self::OPTION_ENABLED); ?>" value="1" <?php checked($enabled, 1); ?> />
                            <label>Enable automatic external image import on post save</label>
                        </td>
                    </tr>

                    <tr valign="top">
                        <th scope="row">Allowed Post Types</th>
                        <td>
                            <?php foreach ( $all_post_types as $ptype ) : ?>
                                <label>
                                    <input type="checkbox" name="<?php echo esc_attr(self::OPTION_POST_TYPES); ?>[]" value="<?php echo esc_attr($ptype->name); ?>" <?php checked(in_array($ptype->name, $post_types)); ?> />
                                    <?php echo esc_html($ptype->label); ?>
                                </label><br>
                            <?php endforeach; ?>
                        </td>
                    </tr>

                    <tr valign="top">
                        <th scope="row">Domain Whitelist</th>
                        <td>
                            <input type="text" name="<?php echo esc_attr(self::OPTION_WHITELIST); ?>" value="<?php echo esc_attr($whitelist); ?>" class="regular-text" />
                            <p class="description">Comma-separated list of allowed external domains. Leave empty to allow all domains.</p>
                        </td>
                    </tr>
                </table>

                <?php submit_button(); ?>
            </form>

            <form method="post" action="<?php echo esc_url($import_url); ?>">
                <?php wp_nonce_field('eii_manual_import_nonce'); ?>
                <input type="submit" class="button button-primary" value="Import External Images Now" />
            </form>
        </div>
        <?php
    }

    public function handle_manual_import() {
        if ( ! current_user_can('manage_options') ) {
            wp_die('Access denied');
        }

        check_admin_referer('eii_manual_import_nonce');

        $allowed_post_types = get_option(self::OPTION_POST_TYPES, ['post', 'page']);
        $domain_whitelist = array_filter(array_map('trim', explode(',', get_option(self::OPTION_WHITELIST, ''))));

        $args = [
            'post_type' => $allowed_post_types,
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'fields' => 'ids',
        ];

        $posts = get_posts($args);

        $total_images_imported = 0;
        foreach ( $posts as $post_id ) {
            $post = get_post($post_id);
            if ( ! $post ) continue;
            $content = $post->post_content;
            $updated = false;
            $new_content = $this->process_content_images($content, $post_id, $domain_whitelist, $updated);
            if ( $updated ) {
                wp_update_post([
                    'ID' => $post_id,
                    'post_content' => $new_content,
                ]);
                $total_images_imported++;
            }
        }

        // Redirect back with message
        $redirect_url = add_query_arg('eii_imported', $total_images_imported, admin_url('options-general.php?page=external-image-importer'));
        wp_safe_redirect($redirect_url);
        exit;
    }

    public function admin_notices() {
        if ( isset($_GET['eii_imported']) ) {
            $count = intval($_GET['eii_imported']);
            ?>
            <div class="notice notice-success is-dismissible">
                <p><?php echo esc_html("External Image Importer: Imported images in $count post(s)."); ?></p>
            </div>
            <?php
        }
    }
}

new ExternalImageImporter();
