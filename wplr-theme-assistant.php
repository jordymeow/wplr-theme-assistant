<?php
/*
	Plugin Name: WP/LR Theme Assistant
	Plugin URI: https://meowapps.com/wplr-sync-theme-assistant/
	Description: Extension for WP/LR Sync which allows creating mappings between your Lightroom data and the Post Types (often called Collection, Album, Portfolio...) and/or Taxonomies (Folder, Set...) of your theme.
	Version: 0.5.0
	Author: Jordy Meow
	Author URI: https://meowapps.com
*/

require_once __DIR__ .'/lib/MappingsManager.php';
require_once __DIR__ .'/lib/MappingsAPI.php';

class WPLR_Theme_Assistant {

	private $base_dir, $base_url;

	public function __construct() {
		$this->base_dir = __DIR__;
		$this->base_url = plugin_dir_url( __FILE__ );

		// REST
		WPLR_MappingsAPI::instance();

		// Init
		add_filter( 'wplr_extensions', array( $this, 'extensions' ), 10, 1 );
		add_action( 'init', array( $this, 'init' ), 10, 0 );

		// Collection
		add_action( 'wplr_create_collection', array( $this, 'create_collection' ), 10, 3 );
		add_action( 'wplr_update_collection', array( $this, 'update_collection' ), 10, 2 );
		add_action( "wplr_remove_collection", array( $this, 'remove_collection' ), 10, 1 );
		add_action( "wplr_move_collection", array( $this, 'move_collection' ), 10, 3 );
		add_action( "wplr_order_collection", array( $this, 'order_collection' ), 10, 2 );

		// Folder
		add_action( 'wplr_create_folder', array( $this, 'create_folder' ), 10, 3 );
		add_action( 'wplr_update_folder', array( $this, 'update_folder' ), 10, 2 );
		add_action( "wplr_move_folder", array( $this, 'move_folder' ), 10, 3 );
		add_action( "wplr_remove_folder", array( $this, 'remove_folder' ), 10, 1 );

		add_action( "wplr_add_tag", array( $this, 'add_tag' ), 10, 3 );
		add_action( "wplr_update_tag", array( $this, 'update_tag' ), 10, 3 );
		add_action( "wplr_move_tag", array( $this, 'move_tag' ), 10, 3 );
		add_action( "wplr_remove_tag", array( $this, 'remove_tag' ), 10, 1 );
		add_action( "wplr_add_media_tag", array( $this, 'add_media_tag' ), 10, 2 );
		add_action( "wplr_remove_media_tag", array( $this, 'remove_media_tag' ), 10, 2 );

		// Media
		add_action( "wplr_add_media_to_collection", array( $this, 'set_media_to_collection' ), 10, 2 );
		add_action( "wplr_remove_media_from_collection", array( $this, 'remove_media_from_collection' ), 10, 2 );

		// Post Types List
		if ( is_admin() ) {
			add_filter( 'manage_posts_columns', array( $this, 'manage_posts_columns' ), 10, 2 );
			add_filter( 'manage_pages_columns', array( $this, 'manage_pages_columns' ) );

			$fn = array( $this, 'manage_posts_custom_column' );
			add_action( 'manage_posts_custom_column', $fn, 10, 2 );
			add_action( 'manage_pages_custom_column', $fn, 10, 2 );
		}

		// Ajax Actions
		add_action( 'wp_ajax_fetch_mappings', array( $this, 'wp_ajax_fetch_mappings' ) );
		add_action( 'wp_ajax_save_mappings', array( $this, 'wp_ajax_save_mappings' ) );

		// Extra
		//add_action( 'wplr_reset', array( $this, 'reset' ), 10, 0 );
	}

	function extensions( $extensions ) {
		array_push( $extensions, 'Theme Assistant' );
		return $extensions;
	}

	function manage_posts_columns( $cols, $type ) {
		$maps = $this->get_mappings();
		foreach ( $maps as $map ) {
			if ( $type == $map->posttype ) {
				$cols['WPLRSync_PostTypes_' . $map->id] = 'WP/LR Sync';
				return $cols;
			}
		}
		return $cols;
	}

	function manage_pages_columns( $cols ) {
		return $this->manage_posts_columns( $cols, 'page' );
	}

	function manage_posts_custom_column( $column_name, $id ) {
		global $wpdb, $wplr;
		if ( strpos( $column_name, 'WPLRSync_PostTypes' ) === false )
			return;
		$mapping_id = str_replace( 'WPLRSync_PostTypes_', '', $column_name );
		echo "<div class='wplr-sync-info wplrsync-media-" . $id . "'>";
		$metaname = $this->get_metaname_for_posttype( $mapping_id );
		$res = $wplr->get_meta_from_value( $metaname, $id );
		echo $wplr->html_for_collection( $res );
		echo "</div>";
	}

	/*
		INIT / ADMIN MENU
	*/

	function init() {
		$this->migrate();

		if ( get_option( 'wplr_hide_posttypes' ) )
			delete_option( 'wplr_hide_posttypes' );
		add_action( 'admin_menu', array( $this, 'admin_menu' ) );
	}

	// This function migrates old data from the former 'Post Types Extension'
	function migrate() {
		if ( get_option( WPLR_MappingsManager::SAVE_KEY ) !== false ) return; // No need to migrate

		// Get the former options
		$wplr_posttype = get_option( 'wplr_posttype' );
		$wplr_posttype_status = get_option( 'wplr_posttype_status' );
		$wplr_posttype_hierarchical = get_option( 'wplr_posttype_hierarchical' );
		$wplr_posttype_reuse = get_option( 'wplr_posttype_reuse' );
		$wplr_posttype_mode = get_option( 'wplr_posttype_mode' );
		$wplr_posttype_meta = get_option( 'wplr_posttype_meta' );
		$wplr_taxonomy = get_option( 'wplr_taxonomy' );
		$wplr_taxonomy_reuse = get_option( 'wplr_taxonomy_reuse' );
		$wplr_taxonomy_tags = get_option( 'wplr_taxonomy_tags' );
		$wplr_taxonomy_tags_reuse = get_option( 'wplr_taxonomy_tags_reuse' );

		if ( empty( $wplr_posttype_mode ) )
			return;

		// Remap posttype_mode value
		$remap = array (
			"WP Gallery"                                => 'gallery',
			"Array in Post Meta"                        => 'ids_in_post_meta',
			"Array in Post Meta (Imploded)"             => 'ids_in_post_meta_imploded',
			"Array of (ID -> FullSize) in Post Meta"    => 'urls_in_post_meta',
			"Array of IDs as String in Post Meta" 			=> 'ids_as_string_in_post_meta' // ACF Gallery Format
		);
		$wplr_posttype_mode = array_key_exists( $wplr_posttype_mode, $remap ) ? $remap[$wplr_posttype_mode] : 'gallery';

		// Create the new mapping from the former options
		$mapping = new WPLR_Mapping();
		$mapping->setFields(array (
			'posttype' => $wplr_posttype,
			'posttype_status' => $wplr_posttype_status,
			'posttype_hierarchical' => $wplr_posttype_hierarchical,
			'posttype_reuse' => $wplr_posttype_reuse,
			'posttype_mode' => $wplr_posttype_mode,
			'posttype_meta' => $wplr_posttype_meta,
			'taxonomy' => $wplr_taxonomy,
			'taxonomy_reuse' => $wplr_taxonomy_reuse,
			'taxonomy_tags' => $wplr_taxonomy_tags,
			'taxonomy_tags_reuse' => $wplr_taxonomy_tags_reuse
		) );

		// Store the mapping
		$maps = WPLR_MappingsManager::instance();
		$maps->add( $mapping, 0 );
		$maps->save();

		// Delete former options
		delete_option( 'wplr_posttype' );
		delete_option( 'wplr_posttype_status' );
		delete_option( 'wplr_posttype_hierarchical' );
		delete_option( 'wplr_posttype_reuse' );
		delete_option( 'wplr_posttype_mode' );
		delete_option( 'wplr_posttype_meta' );
		delete_option( 'wplr_taxonomy' );
		delete_option( 'wplr_taxonomy_reuse' );
		delete_option( 'wplr_taxonomy_tags' );
		delete_option( 'wplr_taxonomy_tags_reuse' );

	}

	function admin_menu() {
		$page = add_submenu_page( 'wplr-main-menu', 'Theme Assistant', '&#8674; Theme Assistant',
			'manage_options', 'wplr-post_types-menu', array( $this, 'admin_settings' ) );

		add_action( "load-{$page}", array( $this, 'on_load_main_menu' ) );

		add_settings_section( 'wplr-post_types-settings', null,
			array( $this, 'admin_settings_intro' ),'wplr-post_types-menu' );

		$mode = get_option( 'wplr_posttype_mode' );
		$posttypes = get_post_types( '', 'names' );
		$posttype = get_option( 'wplr_posttype' );
		$taxonomy = get_option( 'wplr_taxonomy' );
		$taxonomy_tags = get_option( 'wplr_taxonomy_tags' );
		$posttypes = array_diff( $posttypes, array( 'attachment', 'revision', 'nav_menu_item' ) );

		array_unshift( $posttypes, "" );
		$taxonomies = get_object_taxonomies( $posttype );
		array_unshift( $taxonomies, "" );

		if ( $this->is_hierarchical() && !is_post_type_hierarchical( $this->get_posttype() ) )
			update_option( 'wplr_posttype_hierarchical', null );
		if ( $this->is_posttype_reuse() && empty( $posttype ) )
			update_option( 'wplr_posttype_reuse', null );
		if ( $this->is_taxonomy_reuse() && empty( $taxonomy ) )
			update_option( 'wplr_taxonomy_reuse', null );
		if ( $this->is_taxonomy_tags_reuse() && empty( $taxonomy_tags ) )
			update_option( 'wplr_taxonomy_tags_reuse', null );

			// POST TYPE SECTION
		add_settings_field( 'wplr_posttype', __( "Post Type", 'wplr-sync' ),
			array( $this, 'admin_posttype_callback' ), 'wplr-post_types-menu',
			'wplr-post_types-settings', $posttypes );
		add_settings_field( 'wplr_posttype_status', __( "Status", 'wplr-sync' ),
			array( $this, 'admin_posttype_status_callback' ), 'wplr-post_types-menu',
			'wplr-post_types-settings', array( 'publish', 'draft' ) );
		add_settings_field( 'wplr_posttype_reuse', __( "Reuse", 'wplr-sync' ),
			array( $this, 'admin_posttypes_reuse_callback' ), 'wplr-post_types-menu',
			'wplr-post_types-settings', array( "Enable" ) );
		add_settings_field( 'wplr_posttype_hierarchical', __( "Hierarchical", 'wplr-sync' ),
			array( $this, 'admin_posttypes_hierarchical_callback' ), 'wplr-post_types-menu',
			'wplr-post_types-settings', array( "Enable" ) );
		add_settings_field( 'wplr_posttype_mode', __( "Mode", 'wplr-sync' ),
			array( $this, 'admin_posttype_mode_callback' ), 'wplr-post_types-menu',
			'wplr-post_types-settings', array( 'WP Gallery', 'Array in Post Meta', 'Array in Post Meta (Imploded)',
				'Array of (ID -> FullSize) in Post Meta', 'Array of IDs as String in Post Meta' ) );

		if ( ( $mode == 'ids_in_post_meta'
			|| $mode == 'urls_in_post_meta'
			|| $mode == 'ids_as_string_in_post_meta'
			|| $mode == 'ids_in_post_meta_imploded' ) ) {
			add_settings_field( 'wplr_posttype_meta', "Post Meta",
				array( $this, 'admin_posttype_meta_callback' ), 'wplr-post_types-menu',
				'wplr-post_types-settings', "" );
		}

		// TAXONOMY SECTION
		add_settings_section( 'wplr-post_types-taxonomy-settings', null,
			array( $this, 'admin_settings_intro_taxonomy' ),'wplr-post_types-taxonomy-menu' );
		add_settings_field( 'wplr_taxonomy', __( "Taxonomy", 'wplr-sync' ),
			array( $this, 'admin_posttype_taxonomy_callback' ), 'wplr-post_types-taxonomy-menu',
			'wplr-post_types-taxonomy-settings', $taxonomies );
		add_settings_field( 'wplr_taxonomy_reuse', __( "Reuse", 'wplr-sync' ),
			array( $this, 'admin_posttypes_taxonomy_reuse_callback' ), 'wplr-post_types-taxonomy-menu',
			'wplr-post_types-taxonomy-settings', array( "Enable" ) );

		// TAXONOMY TAGS
		add_settings_section( 'wplr-post_types-taxonomy-tags-settings', null,
			array( $this, 'admin_settings_intro_taxonomy_tags' ),'wplr-post_types-taxonomy-tags-menu' );
		add_settings_field( 'wplr_taxonomy_tags', __( "Taxonomy", 'wplr-sync' ),
			array( $this, 'admin_posttype_taxonomy_tags_callback' ), 'wplr-post_types-taxonomy-tags-menu',
			'wplr-post_types-taxonomy-tags-settings', $taxonomies );
		add_settings_field( 'wplr_taxonomy_tags_reuse', __( "Reuse", 'wplr-sync' ),
			array( $this, 'admin_posttypes_taxonomy_tags_reuse_callback' ), 'wplr-post_types-taxonomy-tags-menu',
			'wplr-post_types-taxonomy-tags-settings', array( "Enable" ) );

		register_setting( 'wplr-post_types-settings', 'wplr_posttype' );
		register_setting( 'wplr-post_types-settings', 'wplr_posttype_status' );
		register_setting( 'wplr-post_types-settings', 'wplr_posttype_hierarchical' );
		register_setting( 'wplr-post_types-settings', 'wplr_posttype_reuse' );
		register_setting( 'wplr-post_types-settings', 'wplr_posttype_mode' );
		register_setting( 'wplr-post_types-settings', 'wplr_posttype_meta' );
		register_setting( 'wplr-post_types-taxonomy-settings', 'wplr_taxonomy' );
		register_setting( 'wplr-post_types-taxonomy-settings', 'wplr_taxonomy_reuse' );
		register_setting( 'wplr-post_types-taxonomy-tags-settings', 'wplr_taxonomy_tags' );
		register_setting( 'wplr-post_types-taxonomy-tags-settings', 'wplr_taxonomy_tags_reuse' );
	}

	/**
	 * Fires on loading the main menu
	 */
	function on_load_main_menu() {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts_for_main_menu' ) );
	}

	/**
	 * Activates the scripts and styles for the main menu
	 */
	function enqueue_scripts_for_main_menu() {
		// Modules
		wp_enqueue_script( 'vue',      $this->base_url .'modules/vue.js' );
		wp_enqueue_script( 'vue-tabs', $this->base_url .'modules/vue-tabs.js' );
		wp_enqueue_style(  'vue-tabs', $this->base_url .'modules/vue-tabs.css' );

		// Settings JS
		$script = 'wplr-themex-settings';
		wp_enqueue_script(
			$script,
			$this->base_url .'scripts/settings.js',
			array( 'vue', 'vue-tabs' )
		);
		wp_localize_script( // Global object
			$script,
			'$_WPLR_THEMEX_SETTINGS', // Varible name
			array ( // Properties
				'maps' => array (
					'schema' => WPLR_Mapping::getFieldSchema(),
					'api' => array (
						'url'   => trailingslashit( get_rest_url() ) .WPLR_MappingsAPI::NS,
						'nonce' => wp_create_nonce( 'wp_rest' )
					)
				)
			)
		);

		// Styles
		wp_enqueue_style( 'wplr-themex', $this->base_url .'style.css' );
	}

	/**
	 * Returns all the mappings stored in DB
	 * @return WPLR_Mapping[]
	 */
	function get_mappings() {
		return WPLR_MappingsManager::instance()->getAll();
	}

	function is_posttype_reuse() {
		return get_option( 'wplr_posttype_reuse' );
	}

	function is_taxonomy_reuse() {
		return get_option( 'wplr_taxonomy_reuse' );
	}

	function is_taxonomy_tags_reuse() {
		return get_option( 'wplr_taxonomy_tags_reuse' );
	}

	function is_hierarchical() {
		return get_option( 'wplr_posttype_hierarchical' );
	}

	function get_posttype() {
		$posttype = get_option( 'wplr_posttype' );
		if ( empty( $posttype ) || $posttype == 'none' )
			return null;
		return $posttype;
	}

	function get_posttype_status() {
		$posttype = get_option( 'wplr_posttype_status' );
		if ( empty( $posttype ) || $posttype == 'none' )
			return 'draft';
		return $posttype;
	}

	function get_posttype_mode() {
		$mode = get_option( 'wplr_posttype_mode' );
		if ( empty( $mode ) || $mode == 'none' )
			return 'gallery';
		return $mode;
	}

	function get_posttype_meta() {
		$meta = get_option( 'wplr_posttype_meta' );
		if ( empty( $meta ) || $meta == 'none' )
			return '';
		return $meta;
	}

	function get_taxonomy() {
		$taxonomy = get_option( 'wplr_taxonomy' );
		if ( empty( $taxonomy ) || $taxonomy == 'none' )
			return null;
		return $taxonomy;
	}

	function get_taxonomy_tags() {
		$taxonomy = get_option( 'wplr_taxonomy_tags' );
		if ( empty( $taxonomy ) || $taxonomy == 'none' )
			return null;
		return $taxonomy;
	}

	function get_metaname_for_posttype( $id ) {
		return $id <= 1 ? "wplr_pt_posttype" : ( "wplr_pt_posttype_" . $id );
	}

	function get_metaname_for_term_id( $id ) {
		return $id <= 1 ? "wplr_pt_term_id" : "wplr_pt_term_id_" . $id;
	}

	function admin_settings_intro() {
		$taxonomy = $this->get_taxonomy();
		$taxonomy_tags = $this->get_taxonomy_tags();
		$posttype = $this->get_posttype();
		$is_found = false;
		if ( !empty( $taxonomy ) ) {
			$taxonomies = get_object_taxonomies( $posttype );
			foreach ( $taxonomies as $t ) {
				if ( $t == $taxonomy )
					$is_found = true;
			}
			if ( !$is_found ) {
				update_option( 'wplr_taxonomy', null );
				if ( !empty( $posttype ) ) {
					echo "<div class='notice notice-error is-dismissible'><p>";
					echo sprintf( __( "Taxonomy (for folders) was reset since '%s' could not be found in '%s'.", 'wplr-sync' ),
						$taxonomy, $posttype );
					echo "</p></div>";
				}
			}
		}
		if ( !empty( $taxonomy_tags ) ) {
			$taxonomies = get_object_taxonomies( $posttype );
			foreach ( $taxonomies as $t ) {
				if ( $t == $taxonomy_tags )
					$is_found = true;
			}
			if ( !$is_found ) {
				update_option( 'wplr_taxonomy_tags', null );
				if ( !empty( $posttype ) ) {
					echo "<div class='notice notice-error is-dismissible'><p>";
					echo sprintf( __( "Taxonomy (for tags) was reset since '%s' could not be found in '%s'.", 'wplr-sync' ),
						$taxonomy_tags, $posttype );
					echo "</p></div>";
				}
			}
		}
		$mode = $this->get_posttype_mode();
		$meta = $this->get_posttype_meta();
		if ( ( $mode == 'ids_in_post_meta' ||
			$mode == 'urls_in_post_meta' ||
			$mode == 'ids_as_string_in_post_meta' ||
			$mode == 'ids_in_post_meta_imploded' ) && empty( $meta ) ) {
				echo "<div class='notice notice-error is-dismissible'><p>";
				_e( "A Post Meta is required by the current mode.", 'wplr-sync' );
				echo "</p></div>";
		}
	}

	function admin_settings_intro_taxonomy() {
		echo '<h2>' . __( "Folder (LR) &#x2192; Taxonomy (WP)", 'wplr-sync' ) . '</h2>';
	}

	function admin_settings_intro_taxonomy_tags() {
		echo '<h2>' . __( "Keywords (LR) &#x2192; Taxonomy (WP)", 'wplr-sync' ) . '</h2>';
	}

	function admin_posttype_callback( $args ) {
		$html = '<select id="wplr_posttype" name="wplr_posttype" style="width: 100%;">';
		foreach ( $args as $arg )
			$html .= '<option value="' . $arg . '"' . selected( $arg, get_option( 'wplr_posttype' ), false ) . ' > '  .
				( empty( $arg ) ? 'none' : $arg ) . '</option><br />';
		$html .= '</select><br />';

		$html .= '<span class="description">';
		$html .= __( 'Your collections in LR will be synchronized with this post type.<br /><b>Please click "Save Changes" every time you modify the "Post Type"</b> so that the taxonomies can be properly reloaded.', 'wplr-sync' );
		$html .= '</span>';

		echo $html;
	}

	function admin_posttype_status_callback( $args ) {
		$html = '<select id="wplr_posttype_status" name="wplr_posttype_status" style="width: 100%;">';
		foreach ( $args as $arg )
			$html .= '<option value="' . $arg . '"' . selected( $arg, $this->get_posttype_status(), false ) . ' > '  .
				( empty( $arg ) ? 'none' : $arg ) . '</option><br />';
		$html .= '</select><br />';

		$html .= '<span class="description">';
		$html .= __( 'Status of your post-type when it is created.', 'wplr-sync' );
		$html .= '</span>';

		echo $html;
	}

	function admin_posttype_mode_callback( $args ) {
		$html = '<select id="wplr_posttype_mode" name="wplr_posttype_mode" style="width: 100%;">';
		foreach ( $args as $arg )
			$html .= '<option value="' . $arg . '"' . selected( $arg, $this->get_posttype_mode(), false ) . ' > '  .
				( empty( $arg ) ? 'none' : $arg ) . '</option><br />';
		$html .= '</select><br />';

		$html .= '<span class="description">';
		$html .= __( ' and maintained in your posts. For other modes, check the <a href="https://meowapps.com/wplr-sync-theme-assistant/">tutorial</a>. When switching to a different mode, click on "Save Changes". More settings might be available after you "Save Changes".', 'wplr-sync' );
		$html .= '</span>';

		echo $html;
	}

	function admin_posttype_meta_callback( $args ) {
		$meta = $this->get_posttype_meta();
		$html = '<input type="text" style="width: 260px;" id="wplr_posttype_meta" name="wplr_posttype_meta" value="' . $meta . '" />';
		$html .= '<br />';

		$html .= '<span class="description">';
		$html .= __( 'The current chosen mode require the key of the <b>Post Meta</b> you would like the extension to update.', 'wplr-sync' );
		$html .= '</span>';

		echo $html;
	}

	function admin_posttypes_hierarchical_callback( $args ) {
		$posttype = $this->get_posttype();
		$html = '<input type="checkbox" id="wplr_posttype_hierarchical" name="wplr_posttype_hierarchical" value="1" ' .
			( is_post_type_hierarchical( $this->get_posttype() ) ? '' : 'disabled ' ) .
			checked( 1, get_option( 'wplr_posttype_hierarchical' ), false ) . '/>';
		$html .= '<label for="wplr_posttype_hierarchical"> '  . $args[0] . '</label><br>';

		$html .= '<span class="description">';
		$html .= sprintf( __( 'If your post type is hierarchical, with this option the hierarchy of collections will be made using the Post Type "%s".<br />Usage of taxonomies will be disabled.', 'wplr-sync' ), $posttype );
		$html .= '</span>';

		echo $html;
	}

	function admin_posttypes_reuse_callback( $args ) {
		$posttype = $this->get_posttype();
		$html = '<input type="checkbox" id="wplr_posttype_reuse" name="wplr_posttype_reuse" value="1" ' .
			( empty( $posttype ) ? 'disabled ' : '' ) .
			checked( 1, get_option( 'wplr_posttype_reuse' ), false ) . '/>';
		$html .= '<label for="wplr_posttype_reuse"> '  . $args[0] . '</label><br>';

		$html .= '<span class="description">';
		$html .= __( 'If the name of your collection (LR) already matches the name of an existing post type, it will become associated with it instead of creating a new one.', 'wplr-sync' );
		$html .= '</span>';

		echo $html;
	}

	function admin_posttype_taxonomy_callback( $args ) {
		$taxonomy = $this->get_taxonomy();
		$html = '<select id="wplr_taxonomy" name="wplr_taxonomy" ' .
			( $this->is_hierarchical() ? 'disabled ' : '' ) . ' style="width: 100%;">';
		foreach ( $args as $arg )
			$html .= '<option value="' . $arg . '"' .
				selected( $arg, get_option( 'wplr_taxonomy' ), false ) . ' > '  .
				( empty( $arg ) ? 'none' : $arg ) . '</option><br />';
		$html .= '</select><br />';
		$html .= '<span class="description">' . __( 'Your folders (LR) will be synchronized with the terms in this taxonomy.', 'wplr-sync' ) . '</label>';
		echo $html;
	}

	function admin_posttypes_taxonomy_reuse_callback( $args ) {
		$taxonomy = $this->get_taxonomy();
		$html = '<input type="checkbox" id="wplr_taxonomy_reuse" name="wplr_taxonomy_reuse" value="1" ' .
			( empty( $taxonomy ) ? 'disabled ' : '' ) .
			checked( 1, get_option( 'wplr_taxonomy_reuse' ), false ) . '/>';
		$html .= '<label for="wplr_taxonomy_reuse"> '  . $args[0] . '</label><br>';

		$html .= '<span class="description">';
		$html .= __( 'If the name of your folder (LR) already matches the name of an existing term (of your taxonomy), it will become associated with it instead of creating a new one.', 'wplr-sync' );
		$html .= '</span>';

		echo $html;
	}

	function admin_posttype_taxonomy_tags_callback( $args ) {
		$taxonomy = $this->get_taxonomy_tags();
		$html = '<select id="wplr_taxonomy_tags" name="wplr_taxonomy_tags" ' .
			( $this->is_hierarchical() ? 'disabled ' : '' ) . ' style="width: 100%;">';
		foreach ( $args as $arg )
			$html .= '<option value="' . $arg . '"' .
				selected( $arg, get_option( 'wplr_taxonomy_tags' ), false ) . ' > '  .
				( empty( $arg ) ? 'none' : $arg ) . '</option><br />';
		$html .= '</select><br />';

		$html .= '<span class="description">';
		$html .= __( 'Your keywords (LR) will be synchronized with the terms in this taxonomy.', 'wplr-sync' );
		$html .= '</span>';

		echo $html;
	}

	function admin_posttypes_taxonomy_tags_reuse_callback( $args ) {
		$taxonomy = $this->get_taxonomy_tags();
		$html = '<input type="checkbox" id="wplr_taxonomy_tags_reuse" name="wplr_taxonomy_tags_reuse" value="1" ' .
			( empty( $taxonomy ) ? 'disabled ' : '' ) .
			checked( 1, get_option( 'wplr_taxonomy_tags_reuse' ), false ) . '/>';
		$html .= '<label for="wplr_taxonomy_tags_reuse"> '  . $args[0] . '</label><br>';

		$html .= '<span class="description">';
		$html .= __( 'If the name of your keyword (LR) already matches the name of an existing term (of your taxonomy), it will become associated with it instead of creating a new one.', 'wplr-sync' );
		$html .= '</span>';

		echo $html;
	}

	function admin_settings() {
		global $wplr_admin;
		$manager = WPLR_MappingsManager::instance();
		if ( !$manager->getAll() ) $manager->newDraft(); // If there's no mapping, create a draft

		include __DIR__ .'/views/settings.php';
	}

	/*
		COLLECTIONS AND FOLDERS
	*/

	function create_collection( $collectionId, $inFolderId, $collection, $isFolder = false ) {
		global $wplr;
		$maps = $this->get_mappings();
		foreach ( $maps as $map ) { // TODO: Make the overall operation mapping-wise
			$posttype = $map->posttype;
			if ( empty( $posttype ) )
				continue;
			$this->create_collection_one( $collectionId, $inFolderId, $collection, $isFolder, $map );
		}
	}

	function create_collection_one( $collectionId, $inFolderId, $collection, $isFolder, $map ) {
		global $wplr;
		$wplr_pt_posttype = $this->get_metaname_for_posttype( $map->id );
		$wplr_pt_term_id = $this->get_metaname_for_term_id( $map->id );
		$posttype = $map->posttype;
		$id = $wplr->get_meta( $wplr_pt_posttype, $collectionId );
		$name = wp_strip_all_tags( isset( $collection['name'] ) ? $collection['name'] : '[Unknown]' );

		if ( $id && !get_post( $id ) ) {
			error_log( "WP/LR Sync: Collection $name ($id) has to be re-created." );
			$id = null;
		}

		// Check if the entry with same name exist already
		if ( empty( $id ) && $map->posttype_reuse ) {
			global $wpdb;
			$id = $wpdb->get_var( "SELECT ID FROM $wpdb->posts WHERE post_type = '". $posttype ."' and post_title = '" . $name . "'" ); // XXX $name might not be initialized
			if ( !empty( $id ) )
				$wplr->set_meta( $wplr_pt_posttype, $collectionId, $id, true );
		}

		// If doesn't exit, create new entry
		if ( empty( $id ) ) {

			// Get the ID of the parent collection (if any) - check the end of this function for more explanation.
			$post_parent = null;
			if ( $map->posttype_hierarchical && !empty( $inFolderId ) )
				$post_parent = $wplr->get_meta( $wplr_pt_posttype, $inFolderId );

			$mode = $map->posttype_mode;

			// Create the content (except if it's a folder or a mode using meta fields)
			$post_content = '';
			if ( $mode == 'gallery' ) {
				$post_content = '[gallery link="file" size="large" ids=""]';
			}
			else if ( $mode == 'meow-gallery-block' ) {
				// We can't use the Gutenberg Gallery Block directly, as updating it later will cause it
				// to break. That's where the Gutenberg Editor was unfortunately not well-made at all,
				// luckily the Meow Gallery implements the blocks with shortcode, which allows the blocks
				// to be dynamic and updated by the server-side as well. Standard blocks can only be
				// updated through the editor.
				$post_content = '<!-- wp:meow-gallery/gallery {"wplrCollection":"' . $collectionId . '"} -->
					[gallery ids="" layout="default" wplr-collection="' . $collectionId . '"][/gallery]
				<!-- /wp:meow-gallery/gallery -->';
			}
			else if ( $mode == 'gallery-shortcode-block' ) {
				$post_content = '<!-- wp:shortcode -->
					[gallery link="file" size="large" ids=""]
				<!-- /wp:shortcode -->';
			}

			// Create the collection.
			$post = array(
				'post_title'    => wp_strip_all_tags( isset( $collection['name'] ) ? $collection['name'] : '[Unknown]' ),
				'post_content'  => $post_content,
				'post_status'   => $map->posttype_status,
				'post_type'     => $posttype,
				'post_parent'   => $map->posttype_hierarchical ? $post_parent : null
			);
			$id = wp_insert_post( $post );
			$wplr->set_meta( $wplr_pt_posttype, $collectionId, $id, true );

			// Add taxonomy information
			$taxonomy = $map->taxonomy;
			if ( !$map->posttype_hierarchical && !empty( $taxonomy ) && !empty( $inFolderId ) )
				$wplr->add_taxonomy_to_posttype( $inFolderId, $collectionId, $taxonomy, $wplr_pt_posttype, $wplr_pt_term_id );
		}
	}

	function create_folder( $folderId, $inFolderId, $folder ) {
		global $wplr;
		$maps = $this->get_mappings();
		foreach ( $maps as $map ) { // TODO: Make the overall operation mapping-wise
			$wplr_pt_term_id = $this->get_metaname_for_term_id( $map->id );
			$taxonomy = $map->taxonomy;

			// Create a collection (post type) that will act as a container for real collections
			if ( $map->posttype_hierarchical ) {
				$this->create_collection_one( $folderId, $inFolderId, $folder, null, $map );
			}

			// Create a tax for that folder
			else if ( !empty( $taxonomy ) ) {
				$wplr->create_taxonomy( $folderId, $inFolderId, $folder, $taxonomy, $wplr_pt_term_id );
			}
		}
	}

	// Updated the folder with new information.
	// Currently, that would be only its name.
	function update_folder( $folderId, $folder ) {
		global $wplr;
		$maps = $this->get_mappings();
		foreach ( $maps as $map ) { // TODO: Make the overall operation mapping-wise
			$wplr_pt_posttype = $this->get_metaname_for_posttype( $map->id );
			$wplr_pt_term_id = $this->get_metaname_for_term_id( $map->id );
			if ( $map->posttype_hierarchical ) {
				$this->update_collection( $folderId, $folder );
				$id = $wplr->get_meta( $wplr_pt_posttype, $folderId );
				$post = array( 'ID' => $id, 'post_title' => wp_strip_all_tags( $folder['name'] ) );
				wp_update_post( $post );
			}
			else {
				$taxonomy = $map->taxonomy;
				if ( $taxonomy )
					$wplr->update_taxonomy( $folderId, $folder, $taxonomy, $wplr_pt_term_id );
			}
		}
	}

	// Updated the collection with new information.
	// Currently, that would be only its name.
	function update_collection( $collectionId, $collection ) {
		global $wplr;
		$maps = $this->get_mappings();
		foreach ( $maps as $map ) { // TODO: Make the overall operation mapping-wise
			$wplr_pt_posttype = $this->get_metaname_for_posttype( $map->id );
			$id = $wplr->get_meta( $wplr_pt_posttype, $collectionId );
			$post = array( 'ID' => $id, 'post_title' => wp_strip_all_tags( isset( $collection['name'] ) ? $collection['name'] : '[Unknown]' ) );
			wp_update_post( $post );
		}
	}

	// Updated the folder with new information (currently, only its name)
	function wplr_keyword_tax_id( $folderId, $folder ) {
		global $wplr;
		$maps = $this->get_mappings();
		foreach ( $maps as $map ) { // TODO: Make the overall operation mapping-wise
			$wplr_pt_posttype = $this->get_metaname_for_posttype( $map->id );
			$taxonomy = $map->taxonomy;

			// Hierarchical
			if ( $map->posttype_hierarchical )
				$this->update_collection( $folderId, $folder );

			// Taxonomy
			else if ( !empty( $taxonomy ) ) {
				$wplr->update_taxonomy( $folderId, $folder, $taxonomy, $wplr_pt_posttype );
			}
		}
	}

	// Moved the collection under another folder.
	// If the folder is empty, then it is the root.
	function move_collection( $collectionId, $folderId, $previousFolderId ) {
		global $wplr;
		$maps = $this->get_mappings();
		foreach ( $maps as $map ) { // TODO: Make the overall operation mapping-wise
			$wplr_pt_posttype = $this->get_metaname_for_posttype( $map->id );
			$wplr_pt_term_id = $this->get_metaname_for_term_id( $map->id );
			$taxonomy = $map->taxonomy;

			// Hierarchical
			if ( $map->posttype_hierarchical ) {
				$post_parent = null;
				if ( !empty( $folderId ) )
					$post_parent = $wplr->get_meta( $wplr_pt_posttype, $folderId );
				$id = $wplr->get_meta( $wplr_pt_posttype, $collectionId );
				$post = array( 'ID' => $id, 'post_parent' => $post_parent );
				wp_update_post( $post );
			}

			// Taxonomy
			else if ( !empty( $taxonomy ) ) {
				$wplr->remove_taxonomy_from_posttype( $previousFolderId, $collectionId, $taxonomy, $wplr_pt_posttype, $wplr_pt_term_id );
				$wplr->add_taxonomy_to_posttype( $folderId, $collectionId, $taxonomy, $wplr_pt_posttype, $wplr_pt_term_id );
			}
		}
	}

	// Move the folder (category) under another one.
	// If the folder is empty, then it is the root.
	function move_folder( $folderId, $inFolderId, $previousFolderId ) {
		global $wplr;
		$maps = $this->get_mappings();
		foreach ( $maps as $map ) { // TODO: Make the overall operation mapping-wise
			$wplr_pt_term_id = $this->get_metaname_for_term_id( $map->id );
			if ( $map->posttype_hierarchical ) {
				$this->move_collection( $folderId, $inFolderId, $previousFolderId );
			}
			else {
				$taxonomy = $map->taxonomy;
				if ( $taxonomy )
					$wplr->move_taxonomy( $folderId, $inFolderId, $taxonomy, $wplr_pt_term_id );
			}
		}
	}

	/*********************************************************************************************************
	* MANAGE MEDIA IN COLLECTIONS
	*********************************************************************************************************/

	function set_media_to_collection_one( $id, $mediaId, $mode, $map, $collectionId,
		$wplr_pt_posttype, $wplr_pt_term_id, $isRemove = false, $isTranslation = false ) {
		global $wplr;
		$ids = array();

		// In the case it is a WP Gallery
		if ( $mode == 'gallery' || $mode == 'gallery-shortcode-block' ) {
			$content = get_post_field( 'post_content', $id );
			preg_match_all( '/\[gallery.*ids="([0-9,]*)".*\]/', $content, $results );
			if ( !empty( $results ) && !empty( $results[1] ) ) {
				$str = $results[1][0];
				$ids = !empty( $str ) ? explode( ',', $str ) : array();
				$index = array_search( $mediaId, $ids, false );
				if ( $isRemove ) {
					if ( $index !== FALSE )
						unset( $ids[$index] );
				}
				else {
					// If mediaId already there then exit.
					if ( $index !== FALSE )
						return;
					array_push( $ids, $mediaId );
				}

				// Replace the array within the gallery shortcode.
				$content = str_replace( 'ids="' . $str, 'ids="' . implode( ',', $ids ), $content );
				$post = array( 'ID' => $id, 'post_content' => $content );
				wp_update_post( $post );
			}
			else {
				error_log( "Cannot find gallery in the post $collectionId." );
			}
		}
		// In the case the meta is an array, or an imploded array (a string that needs to be exploded)
		// In the case the meta is an array containing directly the ids as string of the image (ACF Gallery Format)
		else if ( $mode == 'ids_in_post_meta' || $mode == 'ids_in_post_meta_imploded' || $mode == 'ids_as_string_in_post_meta' ) {
			$meta = $map->posttype_meta;
			$ids = get_post_meta( $id, $meta, true );
			if ( $mode == 'ids_in_post_meta_imploded' )
				$ids = explode( ',', $ids );
			if ( empty( $ids ) )
				$ids = array();
			$index = array_search( $mediaId, $ids, false );
			if ( $isRemove ) {
				if ( $index !== FALSE )
					unset( $ids[$index] );
			}
			else {
				// If mediaId already there then exit.
				if ( $index !== FALSE )
					return;
				array_push( $ids, ( $mode == 'ids_as_string_in_post_meta' ? (string)$mediaId : $mediaId ) );
			}
			if ( $mode == 'ids_in_post_meta_imploded' ) {
				$idsForUpdate =  implode( ',', $ids );
				$idsForUpdate =  trim( $idsForUpdate, ',' );
				update_post_meta( $id, $meta, $idsForUpdate );
			}
			update_post_meta( $id, $meta, $ids );
		}
		// In the case the meta is an array containing directly the url to the FullSize image
		else if ( $mode == 'urls_in_post_meta' ) {
			$meta = $map->posttype_meta;
			$ids = get_post_meta( $id, $meta, true );
			if ( empty( $ids ) )
				$ids = array();
			if ( $isRemove ) {
				if ( !empty( $ids[$mediaId] ) )
					unset( $ids[$mediaId] );
			}
			else {
				// If mediaId already there then exit.
				if ( !empty( $ids[$mediaId] ) )
					return;
				$ids[$mediaId] = get_attached_file( $mediaId );
			}
			update_post_meta( $id, $meta, $ids );
		}

		if ( $isRemove ) {
			// Need to delete the featured image if it was this media
			$thumbId = get_post_meta( $id, '_thumbnail_id', true );
			if ( $thumbId == $mediaId ) {
				if ( count( $ids ) > 0 )
					update_post_meta( $id, '_thumbnail_id', reset( $ids ) );
				else
					delete_post_meta( $id, '_thumbnail_id' );
			}
		}
		else {
			// Add a default featured image if none
			add_post_meta( $id, '_thumbnail_id', $mediaId, true );
		}

		if ( !$isTranslation ) {

			// Attach the media to the collection
			wp_update_post( array( 'ID' => $mediaId, 'post_parent' => $id ) );

			// Update keywords
			$taxotag = $map->taxonomy_tags;
			if ( !empty( $taxotag ) ) {
				$tags = $wplr->get_tags_from_media( $mediaId );
				foreach ( $tags as $tagId )
					$wplr->add_taxonomy_to_posttype( $tagId, $collectionId, $taxotag, $wplr_pt_posttype, $wplr_pt_term_id );
			}
		}
	}

	// Returns the IDs of the translated posts for this given $id
	function get_translations_for_post( $id ) {
		// Support for Polylang
		if ( function_exists( 'pll_languages_list' ) ) {
			$ids = array();
			$languages = pll_languages_list();
			$post_language = pll_get_post_language( $id );
			foreach ( $languages as $language ) {
				if ( $post_language === $language )
					continue;
				$id = pll_get_post( $id, $language );
				if ( !empty( $id ) )
					array_push( $ids, $id );
			}
			return $ids;
		}
		return null;
	}

	// Added meta to a collection.
	// The $mediaId is actually the WordPress Post/Attachment ID.
	function set_media_to_collection( $mediaId, $collectionId, $isRemove = false ) {
		global $wplr;

		$maps = $this->get_mappings();
		foreach ( $maps as $map ) { // TODO: Make the overall operation mapping-wise
			$mode = $map->posttype_mode;
			$wplr_pt_posttype = $this->get_metaname_for_posttype( $map->id );
			$wplr_pt_term_id = $this->get_metaname_for_term_id( $map->id );
			$id = $wplr->get_meta( $wplr_pt_posttype, $collectionId );

			// Main collection
			$this->set_media_to_collection_one( $id, $mediaId, $mode, $map,
				$collectionId, $wplr_pt_posttype, $wplr_pt_term_id, $isRemove, false );

			// Translations
			$ids = $this->get_translations_for_post( $id );
			if ( !empty( $ids ) ) {
				foreach ( $ids as $id ) {
					$this->set_media_to_collection_one( $id, $mediaId, $mode, $map,
						$collectionId, $wplr_pt_posttype, $wplr_pt_term_id, $isRemove, true );
				}
			}
		}
	}

	function order_collection_one( $id, $mediaIds, $mode, $map, $collectionId ) {
		// In the case it is a WP Gallery
		if ( $mode == 'gallery' || $mode == 'gallery-shortcode-block' ) {
			$content = get_post_field( 'post_content', $id );
			preg_match_all( '/\[gallery.*ids="([0-9,]*)".*\]/', $content, $results );
			if ( !empty( $results ) && !empty( $results[1] ) ) {
				$str = $results[1][0];
				// Replace the array within the gallery shortcode.
				$content = str_replace( 'ids="' . $str, 'ids="' . implode( ',', $mediaIds ), $content );
				$post = array( 'ID' => $id, 'post_content' => $content );
				wp_update_post( $post );
			}
			else {
				error_log( "Cannot find gallery in the post $collectionId." );
			}
		}
		// In the case the meta is an array, or an imploded array (a string that needs to be exploded)
		else if ( $mode == 'ids_in_post_meta' || $mode == 'ids_in_post_meta_imploded' ) {
			$meta = $map->posttype_meta;
			if ( $mode == 'ids_in_post_meta_imploded' ) {
				$idsForUpdate =  implode( ',', $mediaIds );
				$idsForUpdate =  trim( $idsForUpdate, ',' );
				update_post_meta( $id, $meta, $idsForUpdate );
			}
			else
				update_post_meta( $id, $meta, $mediaIds );
		}
		// In the case the meta is an array containing directly the url to the FullSize image
		// In the case the meta is an array containing directly the ids as string of the image (ACF Gallery Format)
		else if ( $mode == 'urls_in_post_meta' || $mode == 'ids_as_string_in_post_meta') {
			$meta = $map->posttype_meta;
			update_post_meta( $id, $meta, $mediaIds );
		}
	}

	// Re-order collection
	function order_collection( $mediaIds, $collectionId ) {
		global $wplr;
		$maps = $this->get_mappings();
		foreach ( $maps as $map ) { // TODO: Make the overall operation mapping-wise
			$wplr_pt_posttype = $this->get_metaname_for_posttype( $map->id );
			$id = $wplr->get_meta( $wplr_pt_posttype, $collectionId );
			$mode = $map->posttype_mode;

			// Main collection
			$this->order_collection_one( $id, $mediaIds, $mode, $map, $collectionId );

			// Translations
			$ids = $this->get_translations_for_post( $id );
			if ( !empty( $ids ) ) {
				foreach ( $ids as $id ) {
					$this->order_collection_one( $id, $mediaIds, $mode, $map, $collectionId );
				}
			}
		}
	}

	// Remove media from the collection.
	function remove_media_from_collection( $mediaId, $collectionId ) {
		global $wplr;
		$this->set_media_to_collection( $mediaId, $collectionId, true );

		// Attach the media to the collection
		wp_update_post( array( 'ID' => $mediaId, 'post_parent' => 0 ) );

		// Update keywords

		// Process mappings
		$maps = $this->get_mappings();
		foreach ( $maps as $map ) { // TODO: Make the overall operation mapping-wise
			// Count the number of time the tags are used in the collection
			$wplr_pt_posttype = $this->get_metaname_for_posttype( $map->id );
			$wplr_pt_term_id = $this->get_metaname_for_term_id( $map->id );
			$taxotag = $map->taxonomy_tags;
			if ( !empty( $taxotag ) ) {
				$tagsCount = array();
				$mediaIds = $wplr->get_media_from_collection( $collectionId );
				foreach ( $mediaIds as $m ) {
					$tags = $wplr->get_tags_from_media( $m );
					foreach ( $tags as $tagId ) {
						if ( isset( $tagsCount[$tagId] ) )
							$tagsCount[$tagId]++;
						else
							$tagsCount[$tagId] = 1;
					}
				}
				//error_log( "TAGSCOUNT: ", print_r( $tagsCount, 1 ) );

				$tags = $wplr->get_tags_from_media( $mediaId );
				//error_log( "TAGS FROM MEDIA $mediaId (to remove maybe): ", print_r( $tags, 1 ) );
				$taxotag = $map->taxonomy_tags;
				foreach ( $tags as $tagId ) {
					if ( !isset( $tagsCount[$tagId] ) )
						$wplr->remove_taxonomy_from_posttype( $tagId, $collectionId, $taxotag, $wplr_pt_posttype, $wplr_pt_term_id );
				}
			}
		}
	}

	// The collection was deleted.
	function remove_collection( $collectionId ) {
		$maps = $this->get_mappings();
		foreach ( $maps as $map ) { // TODO: Make the overall operation mapping-wise
			$this->remove_collection_one( $collectionId, $map );
		}
	}

	function remove_collection_one( $collectionId, $map ) {
		global $wplr;
		$wplr_pt_posttype = $this->get_metaname_for_posttype( $map->id );
		$id = $wplr->get_meta( $wplr_pt_posttype, $collectionId );
		wp_delete_post( $id, true );
		$wplr->delete_meta( $wplr_pt_posttype, $collectionId );
	}

	// Delete the folder.
	function remove_folder( $folderId ) {
		global $wplr;
		$maps = $this->get_mappings();
		foreach ( $maps as $map ) { // TODO: Make the overall operation mapping-wise
			$wplr_pt_term_id = $this->get_metaname_for_term_id( $map->id );
			if ( $map->posttype_hierarchical ) {
				$this->remove_collection_one( $folderId, $map );
			}
			else {
				$taxonomy = $map->taxonomy;
				if ( $taxonomy )
					$wplr->remove_taxonomy( $folderId, $taxonomy, $map->posttype, $wplr_pt_term_id );
			}
		}
	}

	/*
		TAGS
	*/

	// New keyword added.
	function add_tag( $tagId, $name, $parentId ) {
		global $wplr;
		$maps = $this->get_mappings();
		foreach ( $maps as $map ) { // TODO: Make the overall operation mapping-wise
			$wplr_pt_term_id = $this->get_metaname_for_term_id( $map->id );
			$taxonomy = $map->taxonomy_tags;
			if ( $taxonomy )
				$wplr->create_taxonomy( $tagId, $parentId, array( 'name' => $name ), $taxonomy, $wplr_pt_term_id );
		}
	}

	// Keyword updated.
	function update_tag( $tagId, $name ) {
		global $wplr;
		$maps = $this->get_mappings();
		foreach ( $maps as $map ) { // TODO: Make the overall operation mapping-wise
			$wplr_pt_posttype = $this->get_metaname_for_posttype( $map->id );
			$taxonomy = $map->taxonomy_tags;
			if ( $taxonomy )
				$wplr->update_taxonomy( $tagId, array( 'name' => $name ), $taxonomy, $wplr_pt_posttype );
		}
	}

	function move_tag( $folderId, $inFolderId, $previousFolderId ) {
		global $wplr;
		$maps = $this->get_mappings();
		foreach ( $maps as $map ) { // TODO: Make the overall operation mapping-wise
			$wplr_pt_term_id = $this->get_metaname_for_term_id( $map->id );
			$taxonomy = $map->taxonomy_tags;
			if ( $taxonomy )
				$wplr->move_taxonomy( $folderId, $inFolderId, $taxonomy, $wplr_pt_term_id );
		}
	}

	// New keyword added.
	function remove_tag( $tagId ) {
		global $wplr;
		$maps = $this->get_mappings();
		foreach ( $maps as $map ) { // TODO: Make the overall operation mapping-wise
			$wplr_pt_term_id = $this->get_metaname_for_term_id( $map->id );
			$taxonomy = $map->taxonomy_tags;
			if ( $taxonomy )
				$wplr->remove_taxonomy( $tagId, $taxonomy, $map->posttype, $wplr_pt_term_id );
		}
	}

	// New keyword added for this media.
	function add_media_tag( $mediaId, $tagId ) {
		global $wplr;
		$maps = $this->get_mappings();
		foreach ( $maps as $map ) { // TODO: Make the overall operation mapping-wise
			$wplr_pt_posttype = $this->get_metaname_for_posttype( $map->id );
			$wplr_pt_term_id = $this->get_metaname_for_term_id( $map->id );
			$taxonomy = $map->taxonomy_tags;
			if ( $taxonomy ) {
				$collections = $wplr->get_collections_from_media( $mediaId );
				foreach ( $collections as $collectionId )
					$wplr->add_taxonomy_to_posttype( $tagId, $collectionId, $taxonomy, $wplr_pt_posttype, $wplr_pt_term_id );
			}
		}
	}

	// Keyword removed for this media.
	function remove_media_tag( $mediaId, $tagId ) {
		global $wplr;
		$maps = $this->get_mappings();
		foreach ( $maps as $map ) { // TODO: Make the overall operation mapping-wise
			$wplr_pt_posttype = $this->get_metaname_for_posttype( $map->id );
			$wplr_pt_term_id = $this->get_metaname_for_term_id( $map->id );
			$taxonomy = $map->taxonomy_tags;
			if ( $taxonomy ) {
				$collections = $wplr->get_collections_from_media( $mediaId );
				foreach ( $collections as $collectionId )
					$wplr->remove_taxonomy_from_posttype( $tagId, $collectionId,
						$taxonomy, $wplr_pt_posttype, $wplr_pt_term_id );
			}
		}
	}

	/**
	 * Retrieves all the mappings from DB via Ajax
	 */
	public function wp_ajax_fetch_mappings() {
		$maps = WPLR_MappingsManager::instance();
		wp_send_json_success( $maps->toArray() );
	}

	/**
	 * Saves the mappings sent from the client via Ajax
	 * @uses $_POST['data'] Mappings data array encoded as a JSON string
	 */
	public function wp_ajax_save_mappings() {
		$data = $_POST['data'];
		$data = html_entity_decode( stripslashes( $data ) );
		$maps = WPLR_MappingsManager::instance();
		$maps->load( $data );
		try {
			$maps->save();
		} catch ( Exception $e) {
			wp_send_json_error( $e->getMessage() );
		}
		wp_send_json_success();
	}
}

new WPLR_Theme_Assistant;

?>
