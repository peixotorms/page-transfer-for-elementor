<?php
/**
 * Plugin Name: Page Transfer for Elementor
 * Description: Seamlessly export and import Elementor pages between development and production environments, or across different domains. This plugin ensures that all associated media files uploaded via Elementor are copied and preserved with the same relative path, while simultaneously updating the domain name during import and maintaining the integrity of your page layouts.
 * Version: 1.0
 * Author: Raul Peixoto
 * Author URI: https://www.upwork.com/fl/raulpeixoto
 * Text Domain: page-transfer-for-elementor
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * License: GPL2 / https://wordpress.org/about/license/
 * @package page-transfer-for-elementor
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

// Add import/export links under the title
add_filter('page_row_actions', 'eei_add_export_import_links', 10, 2);
function eei_add_export_import_links($actions, $post) {
    if ($post->post_type == 'page') {
        $nonce = wp_create_nonce('eei_export_page_' . $post->ID);
        $actions['export'] = '<a href="' . admin_url('admin-post.php?action=eei_export_page&post_id=' . $post->ID . '&_wpnonce=' . $nonce) . '">Export</a>';
        $actions['import'] = '<a href="#" class="eei-import-link" data-post-id="' . $post->ID . '">Import</a>';
    }
    return $actions;
}

// Handle export action
add_action('admin_post_eei_export_page', 'eei_export_page');
function eei_export_page() {
    if (!current_user_can('edit_pages')) {
        wp_die('You do not have sufficient permissions to access this page.');
    }

    if (!isset($_GET['post_id']) || !isset($_GET['_wpnonce']) || !wp_verify_nonce($_GET['_wpnonce'], 'eei_export_page_' . $_GET['post_id'])) {
        wp_die('Invalid request.');
    }

    $post_id = intval($_GET['post_id']);

    // Get all meta fields for the post
    $all_meta = get_post_meta($post_id);

    $export_data = array();
    foreach ($all_meta as $meta_key => $meta_values) {
        if (strpos($meta_key, '_elementor_') === 0 || strpos($meta_key, '_wp_page_template') === 0) {
           $export_data[$meta_key] = $meta_values[0];
        }
    }

    if (empty($export_data)) {
        wp_die('No Elementor content found.');
    }

    header('Content-Type: application/json');
    header('Content-Disposition: attachment; filename="elementor-content-' . $post_id . '.json"');
    echo wp_json_encode($export_data);
    exit;
}

// Handle AJAX import action
add_action('wp_ajax_eei_import_page', 'eei_import_page');
function eei_import_page() {
    if (!current_user_can('edit_pages')) {
        wp_send_json_error('You do not have sufficient permissions to perform this action.');
    }

    if (!isset($_POST['post_id']) || !isset($_FILES['eei_import_file']) || !check_ajax_referer('eei_nonce', 'security', false)) {
        wp_send_json_error('Invalid request.');
    }

    $post_id = intval($_POST['post_id']);
    $file = $_FILES['eei_import_file'];

    if ($file['error'] !== UPLOAD_ERR_OK) {
        wp_send_json_error('File upload error.');
    }

    // Check if the uploaded file is a JSON file
    $file_type = wp_check_filetype($file['name'], array('json' => 'application/json'));
    if ($file_type['ext'] !== 'json' || $file_type['type'] !== 'application/json') {
        wp_send_json_error('Invalid file type. Please upload a JSON file.');
    }

    $content = file_get_contents($file['tmp_name']);
    $content = json_decode($content, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        wp_send_json_error('Invalid JSON file.');
    }

    // Function to check if a URL points to an allowed file type
    function is_allowed_file_type($url) {
        $allowed_mime_types = get_allowed_mime_types();
        $path = wp_parse_url($url, PHP_URL_PATH);
        $file_info = wp_check_filetype_and_ext($path, basename($path));
        return in_array($file_info['type'], $allowed_mime_types);
    }

    // Function to extract and replace static file URLs in the content
    function extract_and_replace_static_files($data) {
        if (is_array($data)) {
            foreach ($data as $key => &$value) {
                $value = extract_and_replace_static_files($value);
            }
        } elseif (is_string($data) && filter_var($data, FILTER_VALIDATE_URL) && is_allowed_file_type($data)) {
            $upload_dir = wp_upload_dir();
            $parsed_url = wp_parse_url($data);
            $path_parts = pathinfo($parsed_url['path']);
            
            // Preserve the original directory structure
            $relative_path = preg_replace('/^\/wp-content\/uploads\//', '', $parsed_url['path']);
            $local_path = $upload_dir['basedir'] . '/' . $relative_path;

            if (!file_exists($local_path)) {
                // Ensure the directory exists
                wp_mkdir_p(dirname($local_path));

                // Download the file with a 15-second timeout
                $tmp_file = download_url($data, 15);
                if (is_wp_error($tmp_file)) {
                    error_log('Failed to download file: ' . $data);
                    return $data; // Return original URL if download fails
                }

                // Move the file to the correct location
                $file_array = array(
                    'name'     => basename($local_path),
                    'tmp_name' => $tmp_file,
                );

                $file_info = wp_handle_sideload($file_array, array('test_form' => false));
                if (isset($file_info['error'])) {
                    error_log('Failed to save file: ' . $local_path);
                    return $data; // Return original URL if save fails
                }
            }

            // Update the URL in the JSON data
            $data = $upload_dir['baseurl'] . '/' . $relative_path;
        }
        return $data;
    }

    // Process _elementor_data separately
    if (isset($content['_elementor_data'])) {
        $elementor_data = $content['_elementor_data'];
        if (is_string($elementor_data) && !empty($elementor_data)) {
            $decoded_data = json_decode($elementor_data, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $elementor_data = $decoded_data;
                $elementor_data = extract_and_replace_static_files($elementor_data);
                $content['_elementor_data'] = wp_json_encode($elementor_data);
            }
        }
    }

    // Extract and replace static file URLs in the content
    foreach ($content as $meta_key => &$meta_value) {
        if (is_serialized($meta_value)) {
            $unserialized_value = maybe_unserialize($meta_value);
            $unserialized_value = extract_and_replace_static_files($unserialized_value);
            $meta_value = maybe_serialize($unserialized_value);
        } elseif (is_string($meta_value) && is_json($meta_value)) {
            $decoded_value = json_decode($meta_value, true);
            $decoded_value = extract_and_replace_static_files($decoded_value);
            $meta_value = wp_json_encode($decoded_value);
        } else {
            $meta_value = extract_and_replace_static_files($meta_value);
        }
    }

    // Update post meta with the new content, preserving serialization format
    foreach ($content as $meta_key => $meta_value) {
        if (is_serialized($meta_value)) {
            $meta_value = maybe_unserialize($meta_value);
        }
        update_post_meta($post_id, $meta_key, $meta_value);
    }

    wp_send_json_success('Content imported successfully.');
}

// Helper function to check if a string is JSON
function is_json($string) {
    json_decode($string);
    return (json_last_error() === JSON_ERROR_NONE);
}

// Enqueue the JavaScript and CSS
add_action('admin_enqueue_scripts', 'eei_enqueue_scripts');
function eei_enqueue_scripts($hook) {
    if ($hook !== 'edit.php') {
        return;
    }

    wp_enqueue_media();
    wp_enqueue_script('eei-script', plugin_dir_url(__FILE__) . 'js/eei-script.js', array('jquery'), '1.0', true);
    wp_localize_script('eei-script', 'eei_ajax', array(
        'ajax_url' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('eei_nonce')
    ));

    wp_enqueue_style('eei-style', plugin_dir_url(__FILE__) . 'css/eei-style.css');
}

// Add modal HTML to the admin footer
add_action('admin_footer', 'eei_add_modal_html');
function eei_add_modal_html() {
    ?>
    <div id="eei-import-modal" style="display:none;">
        <div class="modal-content">
            <div class="modal-title-bar">Import Elementor Page <span class="close">&times;</span></div>
            <form id="eei-import-form" method="post" enctype="multipart/form-data">
                <div class="eei-file-upload"><input type="file" name="eei_import_file" /></div>
                <div class="eei-file-import"><input type="submit" value="Import" /></div>
                <span class="eei-file-feedback"></span>
            </form>
        </div>
    </div>
    <?php
}
