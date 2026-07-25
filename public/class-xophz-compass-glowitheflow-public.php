<?php

class Xophz_Compass_Glowitheflow_Public {

	private $plugin_name;
	private $version;

	public function __construct( $plugin_name, $version ) {
		$this->plugin_name = $plugin_name;
		$this->version     = $version;
	}

	public function register_endpoints() {
		$load_mode   = get_option( 'xophz_compass_glowitheflow_load_mode', 'routes_only' );
		$custom_slug = get_option( 'xophz_compass_glowitheflow_custom_slug', 'glowitheflow' );

		// Always register default route /glowitheflow
		add_rewrite_rule( '^glowitheflow(/.*)?$', 'index.php?xophz_compass_glowitheflow=1', 'top' );

		if ( $load_mode === 'custom_slug' && ! empty( $custom_slug ) && $custom_slug !== 'glowitheflow' ) {
			add_rewrite_rule( '^' . preg_quote( $custom_slug, '/' ) . '(/.*)?$', 'index.php?xophz_compass_glowitheflow=1', 'top' );
		}
	}

	public function register_query_vars( $vars ) {
		$vars[] = 'xophz_compass_glowitheflow';
		return $vars;
	}

	public function template_redirect() {
		global $wp_query;

		// Do not intercept WordPress admin or login routes.
		$request_uri = $_SERVER['REQUEST_URI'] ?? '';
		if ( strpos( $request_uri, '/wp-admin' ) === 0 || strpos( $request_uri, '/wp-login.php' ) === 0 ) {
			return;
		}

		$isRouteMatch          = isset( $wp_query->query_vars['xophz_compass_glowitheflow'] );
		$isConfiguredPageMatch = $this->is_configured_page();

		$load_mode             = get_option( 'xophz_compass_glowitheflow_load_mode', 'routes_only' );
		$isHomepage404Fallback = ( $load_mode === 'homepage' && is_404() );

		if ( $isRouteMatch || $isConfiguredPageMatch || $isHomepage404Fallback ) {
			status_header( 200 );
			$wp_query->is_404 = false;

			$app_base = $this->resolve_app_base( $isRouteMatch );
			$this->render_glowitheflow_shell( $app_base );
			exit;
		}
	}

	private function is_configured_page() {
		$load_mode      = get_option( 'xophz_compass_glowitheflow_load_mode', 'routes_only' );
		$isHomepageMode = ( $load_mode === 'homepage' && is_front_page() );

		$targetPageId       = (int) get_option( 'xophz_compass_glowitheflow_load_page_id', 0 );
		$isSpecificPageMode = ( $load_mode === 'specific_page' && $targetPageId > 0 && is_page( $targetPageId ) );

		return $isHomepageMode || $isSpecificPageMode;
	}

	private function resolve_app_base( $isRouteMatch ) {
		if ( $isRouteMatch ) {
			$load_mode   = get_option( 'xophz_compass_glowitheflow_load_mode', 'routes_only' );
			$custom_slug = get_option( 'xophz_compass_glowitheflow_custom_slug', 'glowitheflow' );
			$requestPath = trim( parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH ) ?: '', '/' );

			if ( $load_mode === 'custom_slug' && ! empty( $custom_slug ) && strpos( $requestPath, $custom_slug ) === 0 ) {
				return $custom_slug;
			}
			return 'glowitheflow';
		}

		$load_mode = get_option( 'xophz_compass_glowitheflow_load_mode', 'routes_only' );
		if ( $load_mode === 'homepage' ) {
			return '';
		}

		return trim( parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH ) ?: '', '/' );
	}

	private function is_dev_mode() {
		return ( defined( 'WP_ENV' ) && WP_ENV === 'development' ) || ( defined( 'WP_DEBUG' ) && WP_DEBUG );
	}

	private function render_glowitheflow_shell( $app_base ) {
		$is_dev          = $this->is_dev_mode();
		$wp_host         = wp_parse_url( home_url(), PHP_URL_HOST );
		$vite_port       = '5177';
		$vite_url        = '//' . $wp_host . ':' . $vite_port;
		$app_base_slash  = $app_base ? '/' . trim( $app_base, '/' ) . '/' : '/';

		if ( $is_dev ) {
			$internal_host = 'compass';
			$dev_html      = @file_get_contents( "http://{$internal_host}:{$vite_port}/" );

			// Fallback to localhost if internal host is unreachable directly
			if ( ! $dev_html ) {
				$dev_html = @file_get_contents( "http://127.0.0.1:{$vite_port}/" );
			}

			if ( $dev_html ) {
				// Rewrite relative paths for dev server
				$dev_html = str_replace( 'src="/', 'src="' . $vite_url . '/', $dev_html );
				$dev_html = str_replace( 'href="/', 'href="' . $vite_url . '/', $dev_html );
				$dev_html = str_replace( 'import("/', 'import("' . $vite_url . '/', $dev_html );

				// Dynamic Nuxt router base path configuration
				$dev_html = str_replace( 'baseURL:"/"', 'baseURL:"' . esc_js( $app_base_slash ) . '"', $dev_html );
				$dev_html = str_replace( 'baseURL: "/"', 'baseURL: "' . esc_js( $app_base_slash ) . '"', $dev_html );

				// Inject WP API Settings and nonces
				$nonce           = wp_create_nonce( 'wp_rest' );
				$user_id         = get_current_user_id();
				$wp_api_settings = "<script>window.wpApiSettings = { root: '" . esc_url_raw( rest_url() ) . "', nonce: '" . $nonce . "', pluginUrl: '" . esc_url_raw( XOPHZ_COMPASS_GLOWITHEFLOW_URL ) . "', version: '" . esc_js( $this->version ) . "', userId: " . $user_id . " };</script>";
				$dev_html        = str_replace( '</head>', $wp_api_settings . "\n</head>", $dev_html );

				echo $dev_html;
				exit;
			}
		}

		// Production Mode: Load static build output
		$index_file = XOPHZ_COMPASS_GLOWITHEFLOW_PATH . 'public/dist/index.html';
		if ( ! file_exists( $index_file ) ) {
			$index_file = XOPHZ_COMPASS_GLOWITHEFLOW_PATH . 'public/dist/200.html';
		}

		if ( file_exists( $index_file ) ) {
			$html     = file_get_contents( $index_file );
			$dist_url = XOPHZ_COMPASS_GLOWITHEFLOW_URL . 'public/dist/';

			// Dynamic Nuxt router base path configuration
			$html = str_replace( 'baseURL:"/"', 'baseURL:"' . esc_js( $app_base_slash ) . '"', $html );
			$html = str_replace( 'baseURL: "/"', 'baseURL: "' . esc_js( $app_base_slash ) . '"', $html );

			// Rewrite assets paths
			$html = str_replace( '"/_nuxt/', '"' . $dist_url . '_nuxt/', $html );
			$html = str_replace( "'/_nuxt/", "'" . $dist_url . "_nuxt/", $html );
			$html = str_replace( '"/assets/', '"' . $dist_url . 'assets/', $html );
			$html = str_replace( "'/assets/", "'" . $dist_url . "assets/", $html );

			// Inject WP API Settings
			$nonce           = wp_create_nonce( 'wp_rest' );
			$user_id         = get_current_user_id();
			$wp_api_settings = "<script>window.wpApiSettings = { root: '" . esc_url_raw( rest_url() ) . "', nonce: '" . $nonce . "', pluginUrl: '" . esc_url_raw( XOPHZ_COMPASS_GLOWITHEFLOW_URL ) . "', version: '" . esc_js( $this->version ) . "', userId: " . $user_id . " };</script>";
			$html            = str_replace( '</head>', $wp_api_settings . "\n</head>", $html );

			echo $html;
			exit;
		} else {
			echo '<h2>Glowitheflow build not found.</h2><p>Please run <code>pnpm build:glowitheflow</code> in the project workspace.</p>';
			exit;
		}
	}
}
