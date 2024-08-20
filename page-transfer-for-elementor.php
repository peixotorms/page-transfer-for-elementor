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
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * @package page-transfer-for-elementor
 */

if (!defined('ABSPATH')) {
		exit; // Exit if accessed directly.
}

class Page_Transfer_For_Elementor {

		public function __construct() {
				add_filter('page_row_actions', [$this, 'add_export_import_links'], 10, 2);
				add_filter('post_row_actions', [$this, 'add_export_import_links'], 10, 2);
				add_action('admin_post_ptfe_export_page', [$this, 'export_page']);
				add_action('wp_ajax_ptfe_import_page', [$this, 'import_page']);
				add_action('admin_enqueue_scripts', [$this, 'enqueue_scripts']);
				add_action('admin_footer', [$this, 'add_modal_html']);
		}

		public function add_export_import_links($actions, $post) {
				if (is_user_logged_in() && current_user_can('administrator')) {
					$nonce = wp_create_nonce('ptfe_export_page_' . $post->ID);
					$actions['export'] = '<a href="' . esc_url(admin_url('admin-post.php?action=ptfe_export_page&post_id=' . $post->ID . '&_wpnonce=' . $nonce)) . '">Export</a>';
					$actions['import'] = '<a href="#" class="ptfe-import-link" data-post-id="' . esc_attr($post->ID) . '">Import</a>';
				}
				return $actions;
		}

		public function export_page() {
				if (!is_user_logged_in() || !current_user_can('administrator')) {
						wp_die('You do not have sufficient permissions to access this page.');
				}

				$post_id = isset($_GET['post_id']) ? intval($_GET['post_id']) : 0;
				$nonce = isset($_GET['_wpnonce']) ? sanitize_text_field($_GET['_wpnonce']) : '';

				if (!$post_id || !wp_verify_nonce($nonce, 'ptfe_export_page_' . $post_id)) {
						wp_die('Invalid request.');
				}

				$all_meta = get_post_meta($post_id);
				$export_data = array();

				foreach ($all_meta as $meta_key => $meta_values) {
					$export_data[$meta_key] = $meta_values[0];
				}

				if (empty($export_data)) {
						wp_die('No data found to export.');
				}

				header('Content-Type: application/json');
				header('Content-Disposition: attachment; filename="elementor-content-' . $post_id . '.json"');
				echo wp_json_encode($export_data);
				exit;
		}

		public function import_page() {
				if (!is_user_logged_in() || !current_user_can('administrator')) {
						wp_send_json_error('You do not have sufficient permissions to perform this action.');
				}

				$post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;
				$nonce = isset($_POST['security']) ? sanitize_text_field($_POST['security']) : '';

				if (!$post_id || !check_ajax_referer('ptfe_nonce', 'security', false)) {
						wp_send_json_error('Invalid request.');
				}

				if (!isset($_FILES['ptfe_import_file']) || $_FILES['ptfe_import_file']['error'] !== UPLOAD_ERR_OK) {
						wp_send_json_error('File upload error.');
				}

				$file = $_FILES['ptfe_import_file'];
				$file_type = wp_check_filetype($file['name'], array('json' => 'application/json'));

				if ($file_type['ext'] !== 'json' || $file_type['type'] !== 'application/json') {
						wp_send_json_error('Invalid file type. Please upload a JSON file.');
				}
		
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- including local file
				$content = file_get_contents($file['tmp_name']);
				$content = json_decode($content, true);

				if (json_last_error() !== JSON_ERROR_NONE) {
						wp_send_json_error('Invalid JSON file.');
				}

				
		// Process _elementor_data separately
		if (isset($content['_elementor_data'])) {
			$elementor_data = $content['_elementor_data'];
			if (is_string($elementor_data) && !empty($elementor_data)) {
				$decoded_data = json_decode($elementor_data, true);
				if (json_last_error() === JSON_ERROR_NONE) {
					$elementor_data = $decoded_data;
					$elementor_data = $this->extract_and_replace_static_files($elementor_data);
					$content['_elementor_data'] = wp_json_encode($elementor_data);
				}
			}
		}

		// Extract and replace static file URLs in the content
		foreach ($content as $meta_key => &$meta_value) {
			if (is_serialized($meta_value)) {
				$unserialized_value = maybe_unserialize($meta_value);
				$unserialized_value = $this->extract_and_replace_static_files($unserialized_value);
				$meta_value = maybe_serialize($unserialized_value);
			} elseif (is_string($meta_value) && $this->is_json($meta_value)) {
				$decoded_value = json_decode($meta_value, true);
				$decoded_value = $this->extract_and_replace_static_files($decoded_value);
				$meta_value = wp_json_encode($decoded_value);
			} else {
				$meta_value = $this->extract_and_replace_static_files($meta_value);
			}
		}

		// Update post meta with the new content, preserving serialization format
		if (is_countable($content)) {
			foreach ($content as $meta_key => $meta_value) {
				delete_post_meta($post_id, '_elementor_css');
				if (is_serialized($meta_value)) { $meta_value = maybe_unserialize($meta_value); }
				update_post_meta($post_id, $meta_key, $meta_value);
			}
			
			// Clear Elementor cache for the imported post and return
			$this->clear_elementor_cache_for_post($post_id);
			wp_send_json_success('Content imported successfully.');
		}		
		
	}
	
	// allowed mime types + extra common files
	private function is_allowed_file_type($url) {
		$allowed_mime_types = get_allowed_mime_types();		
		$allowed_mime_types['svg'] = 'image/svg+xml';
		$allowed_mime_types['webp'] = 'image/webp';
		$path = wp_parse_url($url, PHP_URL_PATH);
		$file_info = wp_check_filetype_and_ext($path, basename($path), null);
		return in_array($file_info['type'], $allowed_mime_types);
	}
		
	// Helper function to check if a string is JSON
	private function is_json($string) {
		json_decode($string);
		return (json_last_error() === JSON_ERROR_NONE);
	}

	private function extract_and_replace_static_files($data) {
		
		if (is_array($data)) {
			foreach ($data as $key => &$value) {
				$value = $this->extract_and_replace_static_files($value);
			}
		} elseif (is_string($data) && filter_var($data, FILTER_VALIDATE_URL) && $this->is_allowed_file_type($data)) {
			
			$upload_dir = wp_upload_dir();
			$parsed_url = wp_parse_url($data);
			$path_parts = pathinfo($parsed_url['path']);
			$relative_path = preg_replace('/^\/wp-content\/uploads\//', '', $parsed_url['path']);
			
			// add a unique identifier based on the download url
			if(isset($path_parts['filename']) && isset($path_parts['extension'])) {
				$filename = $path_parts['filename'];
				$extension = $path_parts['extension'];
				$uid = hash('adler32', $data);
				$local_path = $upload_dir['basedir'] . '/' . dirname($relative_path).'/'.$filename.'_'.$uid.'.'.$extension;
			} else {
				$local_path = $upload_dir['basedir'] . '/' . $relative_path;
			}
				
			// skip duplicates
			if (!file_exists($local_path)) {
				
				 // Create the directory if it doesn't exist
				$dir_path = dirname($local_path);
				if (!file_exists($dir_path)) {
					wp_mkdir_p($dir_path);
				}
				
				// Use the original URL when downloading the file
				$original_url = $parsed_url['scheme'] . '://' . $parsed_url['host'] . $parsed_url['path'];
				$tmp_file = download_url($original_url, 30);
				
				// error check, else copy and cleanup
				if (is_wp_error($tmp_file)) {
					error_log('Failed to download file within 30 seconds: ' . $original_url);
					return $data;
				} else {
					if (file_exists($tmp_file)) {
						if (!file_exists($local_path)) {
							copy($tmp_file, $local_path);
							$data = str_replace($upload_dir['basedir'], $upload_dir['baseurl'], $local_path);
						}
						wp_delete_file($tmp_file);
					}
				}
								
			}

		}
		
		# debug
		if (is_string($data) && filter_var($data, FILTER_VALIDATE_URL)) {
			if ($this->is_allowed_file_type($data)) {
				error_log('File type allowed: '.$data);
			} else {
				error_log('File type not allowed: '.$data);
			}
		}
		
		
		return $data;
	}


		public function enqueue_scripts($hook) {
				if ($hook !== 'edit.php') {
						return;
				}

				wp_enqueue_script('ptfe-script', plugin_dir_url(__FILE__) . 'js/ptfe-script.js', array('jquery'), '1.0', true);
				wp_localize_script('ptfe-script', 'ptfe_ajax', array(
						'ajax_url' => esc_url(admin_url('admin-ajax.php')),
						'nonce' => wp_create_nonce('ptfe_nonce')
				));

				wp_enqueue_style('ptfe-style', plugin_dir_url(__FILE__) . 'css/ptfe-style.css', array(), '1.0', 'all');
		}

		public function add_modal_html() {
				?>
				<div id="ptfe-import-modal" style="display:none;">
						<div class="modal-content">
								<div class="modal-title-bar">Import Elementor Page <span class="close">&times;</span></div>
								<form id="ptfe-import-form" method="post" enctype="multipart/form-data">
										<div class="ptfe-file-upload"><input type="file" name="ptfe_import_file" /></div>
										<div class="ptfe-file-import"><input type="submit" value="Import" /></div>
										<span class="ptfe-file-feedback"></span>
								</form>
						</div>
				</div>
				<?php
		}

		private function clear_elementor_cache_for_post($post_id) {
				// Ensure Elementor is active
				if (!did_action('elementor/loaded')) {
						return;
				}

				// Ensure the post exists
				if (get_post_status($post_id) === false) {
						return;
				}

				// Ensure the post is built with Elementor
				if (!\Elementor\Plugin::$instance->db->is_built_with_elementor($post_id)) {
						return;
				}

		// Clear the CSS cache for the post
		\Elementor\Plugin::$instance->posts_css_manager->clear_cache($post_id);

		// Regenerate the CSS file
		$css_file = new \Elementor\Core\Files\CSS\Post($post_id);
		$css_file->update();
		}
}

new Page_Transfer_For_Elementor();


// Add SVG and WEBP to the allowed mime types
function ptfe_add_custom_mime_types($mimes) {
    $mimes['svg'] = 'image/svg+xml';
    $mimes['webp'] = 'image/webp';
    return $mimes;
}
add_filter('upload_mimes', 'ptfe_add_custom_mime_types');