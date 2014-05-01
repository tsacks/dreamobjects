<?php

/*
    This file is part of DreamObjects, a plugin for WordPress.

    DreamObjects is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License v 3 for more details.

    https://www.gnu.org/licenses/gpl-3.0.html

*/

use Aws\S3\S3Client as AwsS3CDN;

class DreamObjects_Services extends DreamObjects_Plugin_Base {
	private $dos, $doclient;

	const SETTINGS_KEY = 'dreamobjects_cdn';

	function __construct( $plugin_file_path, $dos ) {

		parent::__construct( $plugin_file_path );

		$this->aws = $dos;
		add_action( 'aws_admin_menu', array( $this, 'admin_menu' ) );
	
		$this->plugin_title = __( 'DreamObjects Configuration', 'dreamobjects' );
		$this->plugin_menu_title = __( 'Backup', 'dreamobjects' );
		$this->plugin_slug = 'dreamobjects-backup';
	
		add_action( 'wp_ajax_dreamobjects-create-bucket', array( $this, 'ajax_create_bucket' ) );
		}	
	}

	function get_setting( $key ) {
		$settings = $this->get_settings();

		// If legacy setting set, migrate settings
		
		if ( get_option('dh-do-key') && !isset( $settings['key'] ) {
			update_option( 'dh-do-key', '' );
		}
		if ( get_option('dh-do-secretkey')) {update_option( 'dh-do-secretkey', '' );}
		if ( get_option('dh-do-bucket')) {update_option( 'dh-do-bucket', 'XXXX' );}
		if ( get_option('dh-do-schedule')) {update_option( 'dh-do-schedule', 'disabled' );}
		if ( get_option('dh-do-backupsection')) {update_option( 'dh-do-backupsection', '' );}
		if ( get_option('dh-do-retain')) {update_option( 'dh-do-retain', '15' );}
		if ( get_option('dh-do-logging')) {update_option( 'dh-do-logging', 'off' );}
		if ( get_option('dh-do-debugging')) {update_option( 'dh-do-debugging', 'off' );}

		
		
		if ( isset( $settings['wp-uploads'] ) && $settings['wp-uploads'] && in_array( $key, array( 'copy-to-s3', 'serve-from-s3' ) ) ) {
			return '1';
		}

		// Default object prefix
		if ( 'object-prefix' == $key && !isset( $settings['object-prefix'] ) ) {
			$uploads = wp_upload_dir();
			$parts = parse_url( $uploads['baseurl'] );
			$path = $parts['path'];
			return substr( $path, 1 ) . '/';
		}

		return parent::get_setting( $key );
	}

	function delete_attachment( $post_id ) {
		if ( !$this->is_plugin_setup() ) {
			return;
		}

		$backup_sizes = get_post_meta( $post_id, '_wp_attachment_backup_sizes', true );

		$intermediate_sizes = array();
		foreach ( get_intermediate_image_sizes() as $size ) {
			if ( $intermediate = image_get_intermediate_size( $post_id, $size ) )
				$intermediate_sizes[] = $intermediate;
		}

		if ( !( $dsobject = $this->get_attachment_dreamobjects_info( $post_id ) ) ) {
			return;
		}

		$amazon_path = dirname( $dsobject['key'] );
		$objects = array();

		// remove intermediate and backup images if there are any
		foreach ( $intermediate_sizes as $intermediate ) {
			$objects[] = array(
				'Key' => path_join( $amazon_path, $intermediate['file'] )
			);
		}

		if ( is_array( $backup_sizes ) ) {
			foreach ( $backup_sizes as $size ) {
				$objects[] = array(
					'Key' => path_join( $amazon_path, $del_file )
				);
			}
		}

		// Try removing any @2x images but ignore any errors
		if ( $objects ) {
			$hidpi_images = array();
			foreach ( $objects as $object ) {
				$hidpi_images[] = array(
					'Key' => $this->get_hidpi_file_path( $object['Key'] )
				);
			}

			try {
				$this->get_doclient()->deleteObjects( array( 
					'Bucket' => $dsobject['bucket'],
					'Objects' => $hidpi_images
				) );
			}
			catch ( Exception $e ) {}
		}

		$objects[] = array(
			'Key' => $dsobject['key']
		);

		try {
			$this->get_doclient()->deleteObjects( array( 
				'Bucket' => $dsobject['bucket'],
				'Objects' => $objects
			) );
		}
		catch ( Exception $e ) {
			error_log( 'Error removing files from DreamObjects: ' . $e->getMessage() );
			return;
		}

		delete_post_meta( $post_id, 'amazonS3_info' );
	}

	function wp_generate_attachment_metadata( $data, $post_id ) {
		if ( !$this->get_setting( 'copy-to-s3' ) || !$this->is_plugin_setup() ) {
			return $data;
		}

		$time = $this->get_attachment_folder_time( $post_id );
		$time = date( 'Y/m', $time );

		$prefix = ltrim( trailingslashit( $this->get_setting( 'object-prefix' ) ), '/' );
		$prefix .= ltrim( trailingslashit( $this->get_dynamic_prefix( $time ) ), '/' );

		if ( $this->get_setting( 'object-versioning' ) ) {
			$prefix .= $this->get_object_version_string( $post_id );
		}

		$type = get_post_mime_type( $post_id );

		$file_path = get_attached_file( $post_id, true );

		$acl = apply_filters( 'wps3_upload_acl', 'public-read', $type, $data, $post_id, $this ); // Old naming convention, will be deprecated soon
		$acl = apply_filters( 'dreamobjects_upload_acl', $acl, $data, $post_id );

		if ( !file_exists( $file_path ) ) {
			return $data;
		}

		$file_name = basename( $file_path );
		$files_to_remove = array( $file_path );

		$doclient = $this->get_doclient();

		$bucket = $this->get_setting( 'bucket' );

		$args = array(
			'Bucket'	 => $bucket,
			'Key'		=> $prefix . $file_name,
			'SourceFile' => $file_path,
			'ACL'		=> $acl
		);

		// If far future expiration checked (10 years)
		if ( $this->get_setting( 'expires' ) ) {
			$args['Expires'] = date( 'D, d M Y H:i:s O', time()+315360000 );
		}

		try {
			$doclient->putObject( $args );
		}
		catch ( Exception $e ) {
			error_log( 'Error uploading ' . $file_path . ' to S3: ' . $e->getMessage() );
			return $data;
		}

		delete_post_meta( $post_id, 'amazonS3_info' );

		add_post_meta( $post_id, 'amazonS3_info', array(
			'bucket' => $bucket,
			'key'	=> $prefix . $file_name
		) );

		$additional_images = array();

		if ( isset( $data['thumb'] ) && $data['thumb'] ) {
			$path = str_replace( $file_name, $data['thumb'], $file_path );
			$additional_images[] = array(
				'Key'		=> $prefix . $data['thumb'],
				'SourceFile' => $path
			);
			$files_to_remove[] = $path;
		} 
		elseif ( !empty( $data['sizes'] ) ) {
			foreach ( $data['sizes'] as $size ) {
				$path = str_replace( $file_name, $size['file'], $file_path );
				$additional_images[] = array(
					'Key'		=> $prefix . $size['file'],
					'SourceFile' => $path
				);
				$files_to_remove[] = $path;
			}
		}

		// Because we're just looking at the filesystem for files with @2x
		// this should work with most HiDPI plugins
		if ( $this->get_setting( 'hidpi-images' ) ) {
			$hidpi_images = array();

			foreach ( $additional_images as $image ) {
				$hidpi_path = $this->get_hidpi_file_path( $image['SourceFile'] );
				if ( file_exists( $hidpi_path ) ) {
					$hidpi_images[] = array(
						'Key'		=> $this->get_hidpi_file_path( $image['Key'] ),
						'SourceFile' => $hidpi_path
					);
					$files_to_remove[] = $hidpi_path;
				}
			}

			$additional_images = array_merge( $additional_images, $hidpi_images );
		}

		foreach ( $additional_images as $image ) {
			try {
				$args = array_merge( $args, $image );
				$doclient->putObject( $args );
			}
			catch ( Exception $e ) {
				error_log( 'Error uploading ' . $args['SourceFile'] . ' to DreamObjects: ' . $e->getMessage() );
			}
		}

		if ( $this->get_setting( 'remove-local-file' ) ) {
			$this->remove_local_files( $files_to_remove );
		}

		return $data;
	}

	function remove_local_files( $file_paths ) {
		foreach ( $file_paths as $path ) {
			if ( !@unlink( $path ) ) {
				error_log( 'Error removing local file ' . $path );
			}
		}
	}

	function get_hidpi_file_path( $orig_path ) {
		$hidpi_suffix = apply_filters( 'dreamobjects_hidpi_suffix', '@2x' );
		$pathinfo = pathinfo( $orig_path );
		return $pathinfo['dirname'] . '/' . $pathinfo['filename'] . $hidpi_suffix . '.' . $pathinfo['extension'];
	}

	function get_object_version_string( $post_id ) {
		if ( get_option( 'uploads_use_yearmonth_folders' ) ) {
			$date_format = 'dHis';
		}
		else {
			$date_format = 'YmdHis';
		}

		$time = $this->get_attachment_folder_time( $post_id );

		$object_version = date( $date_format, $time ) . '/';
		$object_version = apply_filters( 'dreamobjects_get_object_version_string', $object_version );
		
		return $object_version;
	}

	// Media files attached to a post use the post's date 
	// to determine the folder path they are placed in
	function get_attachment_folder_time( $post_id ) {
		$time = current_time( 'timestamp' );

		if ( !( $attach = get_post( $post_id ) ) ) {
			return $time;
		}

		if ( !$attach->post_parent ) {
			return $time;
		}

		if ( !( $post = get_post( $attach->post_parent ) ) ) {
			return $time;
		}

		if ( substr( $post->post_date_gmt, 0, 4 ) > 0 ) {
			return strtotime( $post->post_date_gmt . ' +0000' );
		}

		return $time;
	}

	function wp_get_attachment_url( $url, $post_id ) {
		$new_url = $this->get_attachment_url( $post_id );
		if ( false === $new_url ) {
			return $url;
		}
		
		$new_url = apply_filters( 'dreamobjects_wp_get_attachment_url', $new_url, $post_id );

		return $new_url;
	}

	function get_attachment_dreamobjects_info( $post_id ) {
		return get_post_meta( $post_id, 'amazonS3_info', true );
	}

	function is_plugin_setup() {
		return (bool) $this->get_setting( 'bucket' ) && !is_wp_error( $this->aws->get_client() );
	}

	/**
	 * Generate a link to download a file from Amazon S3 using query string
	 * authentication. This link is only valid for a limited amount of time.
	 *
	 * @param mixed $post_id Post ID of the attachment or null to use the loop
	 * @param int $expires Seconds for the link to live
	 */
	function get_secure_attachment_url( $post_id, $expires = 900 ) {
		return $this->get_attachment_url( $post_id, $expires );
	}

	function get_attachment_url( $post_id, $expires = null ) {
		if ( !$this->get_setting( 'serve-from-s3' ) || !( $dsobject = $this->get_attachment_dreamobjects_info( $post_id ) ) ) {
			return false;
		}

		if ( is_ssl() || $this->get_setting( 'force-ssl' ) ) {
			$scheme = 'https';
		}
		else {
			$scheme = 'http';
		}

		if ( is_null( $expires ) && $this->get_setting( 'cloudfront' ) ) {
			$domain_bucket = $this->get_setting( 'cloudfront' );
		}
		elseif ( $this->get_setting( 'virtual-host' ) ) {
			$domain_bucket = $dsobject['bucket'];
		}
		elseif ( is_ssl() || $this->get_setting( 'force-ssl' ) ) {
			$domain_bucket = 'objects.dreamhost.com/' . $dsobject['bucket'];
		}
		else {
			$domain_bucket = $dsobject['bucket'] . '.objects.dreamhost.com';
		}

		$url = $scheme . '://' . $domain_bucket . '/' . $dsobject['key'];

		if ( !is_null( $expires ) ) {
			try {
				$expires = time() + $expires;
				$secure_url = $this->get_doclient()->getObjectUrl( $dsobject['bucket'], $dsobject['key'], $expires );
				$url .= substr( $secure_url, strpos( $secure_url, '?' ) );
			}
			catch ( Exception $e ) {
				return new WP_Error( 'exception', $e->getMessage() );
			}
		}

		return apply_filters( 'dreamobjects_get_attachment_url', $url, $dsobject, $post_id, $expires );
	}

	function verify_ajax_request() {
		if ( !is_admin() || !wp_verify_nonce( $_POST['_nonce'], $_POST['action'] ) ) {
			wp_die( __( 'Cheatin&#8217; eh?', 'dreamobjects' ) );
		}

		if ( !current_user_can( 'manage_options' ) ) {
			wp_die( __( 'You do not have sufficient permissions to access this page.', 'dreamobjects' ) );
		}
	}

	function ajax_create_bucket() {
		$this->verify_ajax_request();

		if ( !isset( $_POST['bucket_name'] ) || !$_POST['bucket_name'] ) {
			wp_die( __( 'No bucket name provided.', 'dreamobjects' ) );
		}

		$result = $this->create_bucket( $_POST['bucket_name'] );
		if ( is_wp_error( $result ) ) {
			$out = array( 'error' => $result->get_error_message() );
		}
		else {
			$out = array( 'success' => '1', '_nonce' => wp_create_nonce( 'dreamobjects-create-bucket' ) );
		}

		echo json_encode( $out );
		exit;		
	}

	function create_bucket( $bucket_name ) {
		try {
			$this->get_doclient()->createBucket( array( 'Bucket' => $bucket_name ) );
		}
		catch ( Exception $e ) {
			return new WP_Error( 'exception', $e->getMessage() );
		}

		return true;
	}

	function admin_menu( $dos ) {
		$hook_suffix = $dos->add_page( $this->plugin_title, $this->plugin_menu_title, 'manage_options', $this->plugin_slug, array( $this, 'render_page' ) );
		add_action( 'load-' . $hook_suffix , array( $this, 'plugin_load' ) );
	}

	function get_doclient() {
		if ( is_null( $this->doclient ) ) {
			$this->doclient = $this->aws->get_client()->get( 's3' );
		}

		return $this->doclient;
	}

	function get_buckets() {
		try {
			$result = $this->get_doclient()->listBuckets();
		}
		catch ( Exception $e ) {
			return new WP_Error( 'exception', $e->getMessage() );
		}

		return $result['Buckets'];
	}

	function plugin_load() {
		$src = plugins_url( 'tools/js/script.js', $this->plugin_file_path );
		wp_enqueue_script( 'dreamobjects-script', $src, array( 'jquery' ), $this->get_installed_version(), true );
		
		wp_localize_script( 'dreamobjects-script', 'dreamobjects_i18n', array(
			'create_bucket_prompt'  => __( 'Bucket Name:', 'dreamobjects' ),
			'create_bucket_error'	=> __( 'Error creating bucket: ', 'dreamobjects' ),
			'create_bucket_nonce'	=> wp_create_nonce( 'dreamobjects-create-bucket' )
		) );

		$this->handle_post_request();
	}

	function handle_post_request() {
		if ( empty( $_POST['action'] ) || !in_array($_POST['action'], array('save', 'migrate')) ) {
			return;
		}

		if ( empty( $_POST['_wpnonce'] ) || !wp_verify_nonce( $_POST['_wpnonce'], 'dreamobjects-save-settings' ) ) {
			die( __( "Cheatin' eh?", 'dreamobjects' ) );
		}

		if ( 'migrate' == $_POST['action'] && ( 1 == $_POST['migrate-to-dreamobjects'] ) ) {
			//$this->bulk_upload_to_dreamobjects();
			wp_redirect( 'admin.php?page=' . $this->plugin_slug . '&migrated=1' );
		} elseif ( 'save' == $_POST['action'] ) {

			$this->set_settings( array() );

			$post_vars = array( 'bucket', 'virtual-host', 'expires', 'permissions', 'cloudfront', 'object-prefix', 'copy-to-s3', 'serve-from-s3', 'remove-local-file', 'force-ssl', 'hidpi-images', 'object-versioning' );
			foreach ( $post_vars as $var ) {
				if ( !isset( $_POST[$var] ) ) {
					continue;
				}		
				$cleanvar =  esc_html( $_POST[$var] );

				$this->set_setting( $var, $cleanvar );
			}

			$this->save_settings();

			wp_redirect( 'admin.php?page=' . $this->plugin_slug . '&updated=1' );
		} else {
			wp_redirect( 'admin.php?page=' . $this->plugin_slug . '&error=1' );
		}
		
		exit;
	}

	function render_page() {
		$this->aws->render_view( 'header', array( 'page_title' => $this->plugin_title ) );
		
		$dos_client = $this->aws->get_client();

		if ( is_wp_error( $dos_client ) ) {
			$this->render_view( 'error', array( 'error' => $dos_client ) );
		}
		else {
			$this->render_view( 'settings' );
		}
		
		$this->aws->render_view( 'footer' );
	}

}