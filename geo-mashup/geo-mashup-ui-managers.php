<?php
/** 
 * Geo Mashup "core" implementation of location management user interfaces.
 *
 * Theoretically, everything done here could be done in a separate plugin.
 *
 * @package GeoMashup
 */

/**
 * A base class for managing user interfaces for collecting and storing location.
 *
 * This could be extended to make the existing editor work for new objects in a separate plugin.
 *
 * @since 1.3beta1
 */
class GeoMashupUIManager {
	/**
	 * Retrieve a single instaniated manager by name.
	 *
	 * @param string $name The class name of the manager.
	 * @return GeoMashupUIManager The singleton object.
	 */
	function &get_instance( $name ) {
		static $instances = array();

		if ( ! isset( $instances[$name] ) ) {
			$instances[$name] = new $name();
		}
		return $instances[$name];
	}

	function enqueue_jquery_styles() {
		if ( get_bloginfo( 'version' ) < '2.8' ) {
			$smoothness_css = 'jquery-ui.1.6.smoothness.css'; 
		} else {
			$smoothness_css = 'jquery-ui.1.7.smoothness.css'; 
		}

		wp_enqueue_style( 'jquery-smoothness', trailingslashit( GEO_MASHUP_URL_PATH ) . $smoothness_css, false, get_bloginfo( 'version' ), 'screen' );
	}

	function enqueue_form_client_items() {	

		wp_enqueue_style( 'geo-mashup-edit-form', trailingslashit( GEO_MASHUP_URL_PATH ) . 'edit-form.css', false, '1.0.0', 'screen' );

		wp_enqueue_script( 'google-jsapi' );
		wp_enqueue_script( 'geo-mashup-location-editor', 
			GEO_MASHUP_URL_PATH . '/geo-mashup-location-editor.js', 
			array( 'jquery', 'google-jsapi' ), 
			GEO_MASHUP_VERSION );
	}

	function get_submit_action() {

		$action = null;

		if ( isset( $_POST['geo_mashup_add_location'] ) or isset( $_POST['geo_mashup_update_location'] ) ) {

			// Clients without javascript may need server side geocoding
			if ( ! empty( $_POST['geo_mashup_search'] ) and isset( $_POST['geo_mashup_no_js'] ) and 'true' == $_POST['geo_mashup_no_js'] ) {

				$action = 'geocode';

			} else {

				$action = 'save';

			}

		} else if ( isset( $_POST['geo_mashup_changed'] ) and 'true' == $_POST['geo_mashup_changed'] and ! empty( $_POST['geo_mashup_location'] ) ) {

			// The geo mashup submit button wasn't used, but a change was made and the post saved
			$action = 'save';
				 
		} else if ( isset( $_POST['geo_mashup_delete_location'] ) ) {

			$action = 'delete';

		} else if ( ! empty( $_POST['geo_mashup_location_id'] ) and empty( $_POST['geo_mashup_location'] ) ) {

			// There was a location, but it was cleared before this save
			$action = 'delete';

		}
		return $action;
	}

	function save_posted_object_location( $object_name, $object_id ) {

		// Check the nonce
		if ( empty( $_POST['geo_mashup_nonce'] ) || !wp_verify_nonce( $_POST['geo_mashup_nonce'], 'geo-mashup-edit' ) ) {
			return $object_id;
		}
		
		$action = $this->get_submit_action();

		if ( 'save' == $action or 'geocode' == $action ) {

			$date_string = $_POST['geo_mashup_date'] . ' ' . $_POST['geo_mashup_hour'] . ':' . 
				$_POST['geo_mashup_minute'] . ':00';
			$geo_date = date( 'Y-m-d H:i:s', strtotime( $date_string ) );

			$post_location = array();
			$post_location['saved_name'] = $_POST['geo_mashup_location_name'];

			if ( 'geocode' == $action ) {

				$status = GeoMashupDB::geocode( $_POST['geo_mashup_search'], $post_location );
				if ( $status != 200 ) {
					$post_location = array();
				}

			} else {

				if ( ! empty( $_POST['geo_mashup_select'] ) ) {
					$selected_items = explode( '|', $_POST['geo_mashup_select'] );
					$post_location = intval( $selected_items[0] );
				} else { 
					$post_location['id'] = $_POST['geo_mashup_location_id'];
					list( $lat, $lng ) = split( ',', $_POST['geo_mashup_location'] );
					$post_location['lat'] = trim( $lat );
					$post_location['lng'] = trim( $lng );
					$post_location['geoname'] = $_POST['geo_mashup_geoname'];
					$post_location['address'] = $_POST['geo_mashup_address'];
					$post_location['postal_code'] = $_POST['geo_mashup_postal_code'];
					$post_location['country_code'] = $_POST['geo_mashup_country_code'];
					$post_location['admin_code'] = $_POST['geo_mashup_admin_code'];
					$post_location['sub_admin_code'] = $_POST['geo_mashup_sub_admin_code'];
					$post_location['locality_name'] = $_POST['geo_mashup_locality_name'];
				}
			}
			
			if ( ! empty( $post_location ) ) {
				GeoMashupDB::set_object_location( $object_name, $object_id, $post_location, true, $geo_date );
			}

		} else if ( 'delete' == $action ) {

			GeoMashupDB::delete_object_location( $object_name, $object_id );

		} 

		return $object_id;
	}
}

/**
 * A manager for user location user interfaces.
 *
 * Singleton instantiated immediately.
 *
 * @package GeoMashup
 * @since 1.3beta1
 */
class GeoMashupUserUIManager extends GeoMashupUIManager {
	/**
	 * Static method to get the single instance of this class.
	 * 
	 * @return GeoMashupPostUIManager The instance.
	 */
	function get_instance() {
		return parent::get_instance( 'GeoMashupUserUIManager' );
	}

	/**
	 * PHP4 Constructor
	 */
	function GeoMashupUserUIManager() {
		// Global $geo_mashup_options is available, but a better pattern might
		// be to wait until init to be sure
		add_action( 'init', array( &$this, 'init' ) );
	}

	function init() {
		global $geo_mashup_options;

		// Enable this interface when the option is set and we're on a destination page
		$enabled = is_admin() &&
			$geo_mashup_options->get( 'overall', 'located_object_name', 'user' ) == 'true' &&
			preg_match( '/(user-edit|user-new|profile).php/', $_SERVER['REQUEST_URI'] );

		// If enabled, register all the interface elements
		if ( $enabled ) { 

			// Form generation
			add_action( 'show_user_profile', array( &$this, 'print_form' ) );
			add_action( 'edit_user_profile', array( &$this, 'print_form' ) );
			// MAYBEDO: add location to registration page?

			// Form processing
			add_action( 'personal_options_update', array( &$this, 'save_user'));
			add_action( 'edit_user_profile_update', array( &$this, 'save_user'));

			$this->enqueue_jquery_styles();
			$this->enqueue_form_client_items();
		}
	}

	function print_form()
	{
		global $user_id;

		include_once( GEO_MASHUP_DIR_PATH . '/edit-form.php');
		if ( isset( $_GET['user_id'] ) ) {
			$object_id = $_GET['user_id'];
		} else {
			$object_id = $user_id;
		}
		echo '<h3>' . __( 'Location', 'GeoMashup' ) . '</h3>';
		geo_mashup_edit_form( 'user', $object_id, get_class( $this ) );
	}

	function save_posted_object_location( $user_id ) {
		return parent::save_posted_object_location( 'user', $user_id );
	}

	function save_user() {
		if ( empty( $_POST['user_id'] ) ) {
			return false;
		}

		$user_id = $_POST['user_id'];

		if ( !is_numeric( $user_id ) ) {
			return $user_id;
		}

		if ( !current_user_can( 'edit_user', $user_id ) ) {
			return $user_id;
		}

		return $this->save_posted_object_location( $user_id );
	}
}

// Instantiate
GeoMashupUserUIManager::get_instance();

/**
 * A manager for post/page location user interfaces.
 *
 * Singleton instantiated immediately.
 *
 * @package GeoMashup
 * @since 1.3beta1
 */
class GeoMashupPostUIManager extends GeoMashupUIManager {
	var $inline_location;

	/**
	 * Static method to get the single instance of this class.
	 * 
	 * @return GeoMashupPostUIManager The instance.
	 */
	function get_instance() {
		return parent::get_instance( 'GeoMashupPostUIManager' );
	}

	/**
	 * PHP4 Constructor
	 */
	function GeoMashupPostUIManager() {
		// Global $geo_mashup_options is available, but a better pattern might
		// be to wait until init to be sure
		add_action( 'init', array( &$this, 'init' ) );
	}

	function init() {
		global $geo_mashup_options;

		// Uploadable geo content type expansion always enabled
		add_filter( 'upload_mimes', array( &$this, 'upload_mimes' ) );

		// Enable this interface when the option is set 
		$enabled = $geo_mashup_options->get( 'overall', 'located_object_name', 'post' ) == 'true';

		if ( $enabled ) { 

			// Queue inline location handlers
			$this->enqueue_jquery_styles();

			// Pre-save filter checks saved content for inline location tags
			add_filter( 'content_save_pre', array( &$this, 'content_save_pre') );

			// Save post handles both inline and form processing
			add_action( 'save_post', array( &$this, 'save_post'), 10, 2 );

			// Browser upload processing
			add_filter( 'wp_handle_upload', array( &$this, 'wp_handle_upload' ) );

			// If we're on a post editing page, queue up the form interface elements
			if ( is_admin() && preg_match( '/(post|page)(-new|).php/', $_SERVER['REQUEST_URI'] ) ) {

				// Form generation
				add_action( 'admin_menu', array( &$this, 'admin_menu' ) );

				$this->enqueue_form_client_items();

				// Add the appropriate datepicker for the WP version
				$datepicker_js_path = trailingslashit( GEO_MASHUP_URL_PATH ); 
				if ( get_bloginfo( 'version' ) < '2.8' ) {
					$datepicker_js_path .= 'jquery-ui.1.6.datepicker.min.js';
					$datepicker_js_version = '1.6';
				} else {
					$datepicker_js_path .= 'jquery-ui.1.7.datepicker.min.js';
					$datepicker_js_version = '1.7';
				}

				wp_enqueue_script( 'jquery-ui-datepicker', $datepicker_js_path, array( 'jquery', 'jquery-ui-core'), $datepicker_js_version );

			} else if ( strpos( $_SERVER['REQUEST_URI'], 'async-upload.php' ) > 0 ) {

				// Flash upload display
				add_filter( 'media_meta', array( &$this, 'media_meta' ), 10, 2 );

			} else if ( strpos( $_SERVER['REQUEST_URI'], 'upload.php' ) > 0 ) {

				// Browser upload display
				add_action( 'admin_print_scripts', array( &$this, 'admin_print_scripts' ) );

			} 
		} // end if enabled
	}

	function admin_menu() {
		// Not adding a menu, but at this stage add_meta_box is defined, so we can add the location form
		add_meta_box( 'geo_mashup_post_edit', __( 'Location', 'GeoMashup' ), array( &$this, 'print_form' ), 'post', 'advanced' );
		add_meta_box( 'geo_mashup_post_edit', __( 'Location', 'GeoMashup' ), array( &$this, 'print_form' ), 'page', 'advanced' );
	}

	function print_form() {
		global $post_ID;

		include_once( GEO_MASHUP_DIR_PATH . '/edit-form.php');
		geo_mashup_edit_form( 'post', $post_ID, get_class( $this ) );
	}

	function save_posted_object_location( $post_id ) {
		return parent::save_posted_object_location( 'post', $post_id );
	}

	function save_post($post_id, $post) {
		if ( 'revision' == $post->post_type ) {
			return;
		}

		// WP has already saved the post - allow location saving without added capability checks

		if ( !empty( $this->inline_location ) ) {
			GeoMashupDB::set_object_location( 'post', $post_id, $this->inline_location );
			$this->inline_location = null;
		}

		update_option('geo_mashup_temp_kml_url','');

		return $this->save_posted_object_location( $post_id );
	}

	function content_save_pre( $content ) {
		// Piggyback on the shortcode interface to find inline tags [geo_mashup_save_location ...] 
		add_shortcode( 'geo_mashup_save_location', 'is_null' );
		$pattern = get_shortcode_regex( );
		return preg_replace_callback('/'.$pattern.'/s', array( &$this, 'replace_save_pre_shortcode' ), $content);
	}

	function replace_save_pre_shortcode( $shortcode_match ) {
		$tag_index = array_search( 'geo_mashup_save_location',  $shortcode_match ); 
		if ( $tag_index !== false ) {
			// There is an inline location - save the attributes
			$this->inline_location = shortcode_parse_atts( stripslashes( $shortcode_match[$tag_index+1] ) );
			// Remove the tag
			$content = '';
		} else {
			// Whatever was matched, leave it be
			$content = $shortcode_match[0];
		}
		return $content;
	}

	function media_meta( $content, $post ) {
		// Only chance to run some javascript after a flash upload?
		if (strlen($post->guid) > 0) {
			$content .= '<script type="text/javascript"> ' .
				'if (parent.GeoMashupAdmin) parent.GeoMashupAdmin.loadKml(\''.$post->guid.'\');' .
				'</script>';
		}
		return $content;
	}

	function admin_print_scripts( $not_used ) {
		// Load any uploaded KML into the search map - only works with browser uploader
		
		// See if wp_upload_handler found uploaded KML
		$kml_url = get_option( 'geo_mashup_temp_kml_url' );
		if (strlen($kml_url) > 0) {
			// Load the KML in the location editor
			echo '
				<script type="text/javascript"> 
					if (parent.GeoMashupAdmin) parent.GeoMashupAdmin.loadKml(\'' . $kml_url . '\');
				</script>';
			update_option( 'geo_mashup_temp_kml_url', '' );
		}
	}

	function upload_mimes( $mimes ) {
		$mimes['kml'] = 'application/vnd.google-earth.kml+xml';
		$mimes['gpx'] = 'application/octet-stream';
		return $mimes;
	}

	function wp_handle_upload( $args ) {
		// If an upload is KML, put the URL in an option to be loaded in the response
		update_option( 'geo_mashup_temp_kml_url', '' );
		if ( is_array( $args ) && isset( $args['file'] ) ) {
			if ( stripos( $args['file'], '.kml' ) == strlen( $args['file'] ) - 4 ) {
				update_option( 'geo_mashup_temp_kml_url', $args['url'] );
			}
		}
		return $args;
	}
}

// Instantiate
GeoMashupPostUIManager::get_instance();

/**
 * A manager for comment location user interfaces.
 *
 * Singleton instantiated immediately.
 *
 * @package GeoMashup
 * @since 1.3beta1
 */
class GeoMashupCommentUIManager {
	/**
	 * Static method to get the single instance of this class.
	 * 
	 * @return GeoMashupPostUIManager The instance.
	 */
	function get_instance() {
		static $instance = null;
		if ( is_null( $instance ) ) {
			$instance = new GeoMashupCommentUIManager();
		}
		return $instance;
	}

	/**
	 * PHP4 Constructor
	 */
	function GeoMashupCommentUIManager() {
		// Global $geo_mashup_options is available, but a better pattern might
		// be to wait until init to be sure
		add_action( 'init', array( &$this, 'init' ) );
	}

	function init() {
		global $geo_mashup_options;


		// If enabled, register all the interface elements
		if ( !is_admin() && $geo_mashup_options->get( 'overall', 'located_object_name', 'comment' ) == 'true' ) { 

			// Form generation
			add_action( 'comment_form', array( &$this, 'print_form' ) );

			// Form processing
			add_action( 'comment_post', array( &$this, 'save_comment'), 10, 2 );

			// Google JSAPI provides client location by IP
			wp_enqueue_script( 'google-jsapi' );
			wp_enqueue_script( 'geo-mashup-loader' );
		}
	}

	function print_form()
	{
		// If there's a logged in user with a location, use that as a default.
		// The client-side location will override it if available
		$default_lat = $default_lng = '';
		$user = wp_get_current_user();
		if ( $user ) {
			$location = GeoMashupDB::get_object_location( 'user', $user->ID );
			if ( $location ) {
				$default_lat = $location->lat;
				$default_lng = $location->lng;
			}
		}

		// Print the form
		$input_format = '<input id="geo_mashup_%s_input" name="comment_location[%s]" type="hidden" value="%s" />';
		printf( $input_format, 'lat', 'lat', $default_lat );
		printf( $input_format, 'lng', 'lng', $default_lng );
		printf( $input_format, 'country_code', 'country_code', '' );
		printf( $input_format, 'locality_name', 'locality_name', '' );
		printf( $input_format, 'address', 'address', '' );
	}

	function save_comment( $comment_id = 0, $approval = '' ) {
		if ( !$comment_id || 'spam' === $approval || empty( $_POST['comment_location'] ) || !is_array( $_POST['comment_location'] ) ) {
			return false;
		}

		GeoMashupDB::set_object_location( 'comment', $comment_id, $_POST['comment_location'] );
	}
}

// Instantiate
GeoMashupCommentUIManager::get_instance();

?>