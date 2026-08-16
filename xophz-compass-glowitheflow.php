<?php
/**
 * Plugin Name:       Xophz Glowitheflow
 * Description:       Standalone WordPress backend and router for the Glowitheflow web app.
 * Version:           26.8.16.13
 * Author:            Hall of the Gods, Inc.
 * Category:          Command Deck
 * Group:             Ecosystem
 * Text Domain:       xophz-compass-glowitheflow
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

define( 'XOPHZ_COMPASS_GLOWITHEFLOW_VERSION', '26.8.16.13' );
define( 'XOPHZ_COMPASS_GLOWITHEFLOW_PATH', plugin_dir_path( __FILE__ ) );
define( 'XOPHZ_COMPASS_GLOWITHEFLOW_URL', plugin_dir_url( __FILE__ ) );

$glow_autoload_callback = function ( $class ) {
	if ( strpos( $class, 'Glow_' ) !== 0 ) {
		return;
	}
	$class_name = strtolower( substr( $class, 5 ) );
	$class_name = str_replace( '_', '-', $class_name );
	$file       = XOPHZ_COMPASS_GLOWITHEFLOW_PATH . 'includes/class-glow-' . $class_name . '.php';
	if ( file_exists( $file ) ) {
		require_once $file;
	}
};
spl_autoload_register( $glow_autoload_callback );

require_once XOPHZ_COMPASS_GLOWITHEFLOW_PATH . 'admin/class-xophz-compass-glowitheflow-admin.php';
require_once XOPHZ_COMPASS_GLOWITHEFLOW_PATH . 'public/class-xophz-compass-glowitheflow-public.php';

function run_xophz_compass_glowitheflow() {
	$admin = new Xophz_Compass_Glowitheflow_Admin( 'xophz-compass-glowitheflow', XOPHZ_COMPASS_GLOWITHEFLOW_VERSION );
	add_action( 'admin_menu', array( $admin, 'add_plugin_admin_menu' ) );
	add_action( 'admin_init', array( $admin, 'register_settings' ) );
	add_action( 'update_option_xophz_compass_glowitheflow_load_mode', array( $admin, 'flush_rewrites_on_save' ), 10, 2 );
	add_action( 'update_option_xophz_compass_glowitheflow_custom_slug', array( $admin, 'flush_rewrites_on_save' ), 10, 2 );
	add_action( 'update_option_xophz_compass_glowitheflow_load_page_id', array( $admin, 'flush_rewrites_on_save' ), 10, 2 );

	$public = new Xophz_Compass_Glowitheflow_Public( 'xophz-compass-glowitheflow', XOPHZ_COMPASS_GLOWITHEFLOW_VERSION );
	add_action( 'init', array( $public, 'register_endpoints' ) );
	add_action( 'init', function() {
		register_post_type( 'glow_post', array(
			'labels' => array(
				'name'          => __( 'Glow Posts', 'xophz-compass-glowitheflow' ),
				'singular_name' => __( 'Glow Post', 'xophz-compass-glowitheflow' ),
			),
			'public'        => true,
			'has_archive'   => true,
			'supports'      => array( 'title', 'editor', 'author', 'custom-fields' ),
			'show_in_rest'  => true,
		) );
	} );
	add_filter( 'query_vars', array( $public, 'register_query_vars' ) );
	add_action( 'template_redirect', array( $public, 'template_redirect' ) );

	add_action( 'rest_api_init', function() {
		if ( class_exists( 'Glow_API' ) ) {
			$api = new Glow_API();
			$api->register_routes();
		}
	} );
}

add_action( 'plugins_loaded', 'run_xophz_compass_glowitheflow' );

function xophz_compass_glowitheflow_activate() {
	if ( class_exists( 'Glow_DB' ) ) {
		Glow_DB::activate();
	}
	$public = new Xophz_Compass_Glowitheflow_Public( 'xophz-compass-glowitheflow', XOPHZ_COMPASS_GLOWITHEFLOW_VERSION );
	$public->register_endpoints();
	flush_rewrite_rules();
}
register_activation_hook( __FILE__, 'xophz_compass_glowitheflow_activate' );

function xophz_compass_glowitheflow_deactivate() {
	flush_rewrite_rules();
}
register_deactivation_hook( __FILE__, 'xophz_compass_glowitheflow_deactivate' );

function xophz_compass_glowitheflow_action_links( $links ) {
	$settings_link = '<a href="options-general.php?page=xophz-compass-glowitheflow">' . __( 'Settings', 'xophz-compass-glowitheflow' ) . '</a>';
	array_unshift( $links, $settings_link );
	return $links;
}
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'xophz_compass_glowitheflow_action_links' );

