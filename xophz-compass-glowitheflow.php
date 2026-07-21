<?php
/**
 * Plugin Name:       Xophz Glowitheflow
 * Description:       Standalone WordPress backend and router for the Glowitheflow web app.
 * Version:           26.7.21
 * Author:            Hall of the Gods, Inc.
 * Category:          Command Deck
 * Group:             Ecosystem
 * Text Domain:       xophz-compass-glowitheflow
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

define( 'XOPHZ_COMPASS_GLOWITHEFLOW_VERSION', '26.7.21' );
define( 'XOPHZ_COMPASS_GLOWITHEFLOW_PATH', plugin_dir_path( __FILE__ ) );
define( 'XOPHZ_COMPASS_GLOWITHEFLOW_URL', plugin_dir_url( __FILE__ ) );

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
	add_filter( 'query_vars', array( $public, 'register_query_vars' ) );
	add_action( 'template_redirect', array( $public, 'template_redirect' ) );
}

add_action( 'plugins_loaded', 'run_xophz_compass_glowitheflow' );

function xophz_compass_glowitheflow_activate() {
	$public = new Xophz_Compass_Glowitheflow_Public( 'xophz-compass-glowitheflow', XOPHZ_COMPASS_GLOWITHEFLOW_VERSION );
	$public->register_endpoints();
	flush_rewrite_rules();
}
register_activation_hook( __FILE__, 'xophz_compass_glowitheflow_activate' );

function xophz_compass_glowitheflow_deactivate() {
	flush_rewrite_rules();
}
register_deactivation_hook( __FILE__, 'xophz_compass_glowitheflow_deactivate' );
