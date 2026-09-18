<?php
/**
 * Authentication and Login Handler for Glowitheflow.
 *
 * Provides a dedicated, captcha-free login form for glowitheflow.com
 * reusing the BlackBOX Bedrock styles and smoke canvas particle effects,
 * while suppressing broken Cloudflare Turnstile instances on the domain.
 *
 * @package Xophz_Compass_Glowitheflow
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Glow_Auth_Handler {

	/**
	 * Check if the active request domain is glowitheflow.com.
	 *
	 * @return bool
	 */
	public static function is_glow_domain(): bool {
		$host = isset( $_SERVER['HTTP_HOST'] ) ? strtolower( trim( (string) $_SERVER['HTTP_HOST'] ) ) : '';
		if ( strpos( $host, 'glowitheflow' ) !== false ) {
			return true;
		}

		$home_host = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		if ( strpos( $home_host, 'glowitheflow' ) !== false ) {
			return true;
		}

		return false;
	}

	/**
	 * Register hooks for Turnstile suppression and custom login endpoints.
	 */
	public function init(): void {
		// Suppress Turnstile and captcha filters on login init and auth hooks
		add_action( 'login_init', array( $this, 'suppress_turnstile_on_glow' ), 1 );
		add_action( 'init', array( $this, 'suppress_turnstile_on_glow' ), 1 );
	}

	/**
	 * Suppress Turnstile widget and authentication verification for glowitheflow.
	 */
	public function suppress_turnstile_on_glow(): void {
		if ( ! self::is_glow_domain() ) {
			return;
		}

		// Disable Cloudflare Turnstile filters if present
		add_filter( 'cfturnstile_widget_disable', '__return_true', 999 );
		add_filter( 'easy_cloudflare_turnstile_render_list', '__return_empty_array', 999 );
		add_filter( 'easy_cloudflare_turnstile_verify_list', '__return_empty_array', 999 );

		// Strip captcha validation hooks on wp-login.php
		self::strip_captcha_filters();

		// Dequeue captcha scripts and inject CSS hide rules
		add_action( 'login_enqueue_scripts', array( $this, 'dequeue_captcha_scripts' ), 9999 );
		add_action( 'login_head', array( $this, 'inject_turnstile_bypass_css' ), 9999 );
	}

	/**
	 * Strip captcha filters from authenticate and wp_authenticate_user.
	 */
	public static function strip_captcha_filters(): void {
		global $wp_filter;

		$filter_names = array( 'authenticate', 'wp_authenticate_user', 'login_form', 'login_footer' );
		foreach ( $filter_names as $filter_name ) {
			if ( ! isset( $wp_filter[ $filter_name ] ) || ! isset( $wp_filter[ $filter_name ]->callbacks ) ) {
				continue;
			}

			$callbacks_by_priority = $wp_filter[ $filter_name ]->callbacks;
			foreach ( $callbacks_by_priority as $priority => $callbacks ) {
				foreach ( $callbacks as $id => $callback ) {
					$is_captcha = false;
					$func       = $callback['function'] ?? null;

					if ( is_array( $func ) ) {
						$class_name  = is_object( $func[0] ) ? get_class( $func[0] ) : ( is_string( $func[0] ) ? $func[0] : '' );
						$method_name = is_string( $func[1] ) ? $func[1] : '';

						if ( preg_match( '/turnstile|captcha|recaptcha|hcaptcha|defender|wordfence|cloudflare/i', $class_name ) ||
						     preg_match( '/turnstile|captcha|recaptcha|hcaptcha|defender/i', $method_name ) ) {
							$is_captcha = true;
						}
					} elseif ( is_string( $func ) ) {
						if ( preg_match( '/turnstile|captcha|recaptcha|hcaptcha|defender|wordfence|cloudflare/i', $func ) ) {
							$is_captcha = true;
						}
					} elseif ( is_object( $func ) && ! ( $func instanceof \Closure ) ) {
						$class_name = get_class( $func );
						if ( preg_match( '/turnstile|captcha|recaptcha|hcaptcha|defender|wordfence|cloudflare/i', $class_name ) ) {
							$is_captcha = true;
						}
					}

					if ( $is_captcha ) {
						remove_filter( $filter_name, $callback['function'], $priority );
					}
				}
			}
		}
	}

	/**
	 * Dequeue captcha and Turnstile frontend scripts.
	 */
	public function dequeue_captcha_scripts(): void {
		if ( ! self::is_glow_domain() ) {
			return;
		}

		wp_dequeue_script( 'wpdef_captcha_api' );
		wp_dequeue_script( 'wpdef_turnstile_api' );
		wp_dequeue_script( 'cf-turnstile' );
		wp_dequeue_script( 'cfturnstile' );
		wp_dequeue_script( 'turnstile' );
	}

	/**
	 * Inject CSS rule to cleanly hide any lingering Turnstile containers.
	 */
	public function inject_turnstile_bypass_css(): void {
		if ( ! self::is_glow_domain() ) {
			return;
		}

		echo '<style id="glow-suppress-turnstile">.captcha_wrap, .cf-turnstile, #turnstile-wrapper, div[class*="turnstile"], iframe[src*="cloudflare"] { display: none !important; height: 0 !important; margin: 0 !important; padding: 0 !important; visibility: hidden !important; }</style>';
	}

	/**
	 * Process dedicated login form submission.
	 *
	 * @return string|null Error message string on failure, or redirects on success.
	 */
	public static function process_login(): ?string {
		check_admin_referer( 'glow_login_action', 'glow_login_nonce' );

		$username = isset( $_POST['log'] ) ? sanitize_text_field( wp_unslash( $_POST['log'] ) ) : '';
		$password = isset( $_POST['pwd'] ) ? (string) $_POST['pwd'] : '';
		$remember = ! empty( $_POST['rememberme'] );

		if ( empty( $username ) || empty( $password ) ) {
			return __( 'Please enter both your username/email and password.', 'xophz-compass-glowitheflow' );
		}

		// Ensure captcha filters are cleanly stripped before authenticating
		self::strip_captcha_filters();

		$creds = array(
			'user_login'    => $username,
			'user_password' => $password,
			'remember'      => $remember,
		);

		$user = wp_signon( $creds, is_ssl() );

		if ( is_wp_error( $user ) ) {
			return $user->get_error_message();
		}

		// Set current user and cookies
		wp_set_current_user( $user->ID );
		wp_set_auth_cookie( $user->ID, $remember, is_ssl() );
		do_action( 'wp_login', $user->user_login, $user );

		// Resolve safe redirect target
		$redirect_to = ! empty( $_REQUEST['redirect_to'] ) ? esc_url_raw( wp_unslash( $_REQUEST['redirect_to'] ) ) : admin_url();
		wp_safe_redirect( $redirect_to );
		exit;
	}

	/**
	 * Render the dedicated standalone login page recycling BlackBOX Bedrock styling and canvas FX.
	 *
	 * @param string|null $error_message Optional error message to display.
	 */
	public static function render_login_page( ?string $error_message = null ): void {
		$user_login  = isset( $_POST['log'] ) ? sanitize_text_field( wp_unslash( $_POST['log'] ) ) : '';
		$rememberme  = ! empty( $_POST['rememberme'] );
		$redirect_to = ! empty( $_REQUEST['redirect_to'] ) ? esc_url_raw( wp_unslash( $_REQUEST['redirect_to'] ) ) : admin_url();

		// Resolve BlackBOX Bedrock CSS paths and URLs
		$bedrock_dir = defined( 'WPMU_PLUGIN_DIR' ) ? WPMU_PLUGIN_DIR . '/blackbox-bedrock' : WP_CONTENT_DIR . '/mu-plugins/blackbox-bedrock';
		$bedrock_url = defined( 'WPMU_PLUGIN_URL' ) ? WPMU_PLUGIN_URL . '/blackbox-bedrock' : content_url( 'mu-plugins/blackbox-bedrock' );

		$logo_path  = $bedrock_dir . '/assets/css/logo.css';
		$login_path = $bedrock_dir . '/assets/css/login.css';

		$logo_css  = file_exists( $logo_path ) ? file_get_contents( $logo_path ) : '';
		$login_css = file_exists( $login_path ) ? file_get_contents( $login_path ) : '';

		$assets_url = $bedrock_url . '/assets';
		$logo_css   = str_replace( '../images/', $assets_url . '/images/', $logo_css );
		$login_css  = str_replace( '../images/', $assets_url . '/images/', $login_css );

		// Load smoke canvas script content
		$smoke_canvas_path = $bedrock_dir . '/assets/js/smoke-canvas.js';
		$smoke_canvas_js   = file_exists( $smoke_canvas_path ) ? file_get_contents( $smoke_canvas_path ) : '';

		status_header( 200 );
		header( 'Content-Type: text/html; charset=utf-8' );
		?>
		<!DOCTYPE html>
		<html lang="<?php echo esc_attr( get_locale() ); ?>">
		<head>
			<meta charset="<?php bloginfo( 'charset' ); ?>">
			<meta name="viewport" content="width=device-width, initial-scale=1.0">
			<title><?php esc_html_e( 'Log In &lsaquo; Glow with the Flow', 'xophz-compass-glowitheflow' ); ?></title>
			<?php wp_site_icon(); ?>
			<link rel="stylesheet" href="<?php echo esc_url( includes_url( 'css/dashicons.min.css' ) ); ?>" type="text/css" media="all">
			<link rel="stylesheet" href="<?php echo esc_url( includes_url( 'css/buttons.min.css' ) ); ?>" type="text/css" media="all">
			<link rel="stylesheet" href="<?php echo esc_url( admin_url( 'css/login.min.css' ) ); ?>" type="text/css" media="all">
			<style id="blackbox-login-admin">
				<?php echo $logo_css . $login_css; ?>
			</style>
			<style>
				.glow-badge-tag {
					display: inline-block;
					padding: 2px 10px;
					border-radius: 999px;
					font-size: 11px;
					font-weight: 700;
					letter-spacing: 1px;
					text-transform: uppercase;
					color: var(--hog-gold, #d9be6f);
					border: 1px solid rgba(217, 190, 111, 0.35);
					background: rgba(217, 190, 111, 0.1);
					margin-bottom: 12px;
				}
				.wp-pwd {
					position: relative;
				}
				.wp-pwd .wp-hide-pw {
					position: absolute;
					right: 8px;
					top: 50%;
					transform: translateY(-50%);
					background: transparent !important;
					border: none !important;
					color: var(--hog-gold, #d9be6f) !important;
					cursor: pointer;
					padding: 4px 8px;
				}
			</style>
		</head>
		<body class="login login-action-login wp-core-ui glow-login-page">
			<div id="login">
				<h1>
					<a href="<?php echo esc_url( home_url() ); ?>" title="<?php esc_attr_e( 'Glow with the Flow', 'xophz-compass-glowitheflow' ); ?>">
						<?php esc_html_e( 'Glow with the Flow', 'xophz-compass-glowitheflow' ); ?>
					</a>
				</h1>

				<?php if ( ! empty( $error_message ) ) : ?>
					<div id="login_error" class="notice notice-error">
						<?php echo wp_kses_post( $error_message ); ?>
					</div>
				<?php endif; ?>

				<form name="loginform" id="loginform" action="" method="post">
					<p>
						<label for="user_login"><?php esc_html_e( 'Username or Email Address', 'xophz-compass-glowitheflow' ); ?></label>
						<input type="text" name="log" id="user_login" class="input" value="<?php echo esc_attr( $user_login ); ?>" size="20" autofocus required />
					</p>

					<div class="user-pass-wrap">
						<label for="user_pass"><?php esc_html_e( 'Password', 'xophz-compass-glowitheflow' ); ?></label>
						<div class="wp-pwd">
							<input type="password" name="pwd" id="user_pass" class="input password-input" size="20" required />
							<button type="button" class="wp-hide-pw" aria-label="<?php esc_attr_e( 'Show password', 'xophz-compass-glowitheflow' ); ?>" onclick="togglePasswordVisibility()">
								<span id="pw-toggle-icon" class="dashicons dashicons-visibility" aria-hidden="true"></span>
							</button>
						</div>
					</div>

					<p class="forgetmenot">
						<input name="rememberme" type="checkbox" id="rememberme" value="forever" <?php checked( $rememberme ); ?> />
						<label for="rememberme"><?php esc_html_e( 'Remember Me', 'xophz-compass-glowitheflow' ); ?></label>
					</p>

					<p class="submit">
						<input type="submit" name="wp-submit" id="wp-submit" class="button button-primary button-large" value="<?php esc_attr_e( 'Log In', 'xophz-compass-glowitheflow' ); ?>" />
						<input type="hidden" name="redirect_to" value="<?php echo esc_attr( $redirect_to ); ?>" />
						<?php wp_nonce_field( 'glow_login_action', 'glow_login_nonce' ); ?>
					</p>
				</form>

				<p id="nav">
					<a href="<?php echo esc_url( wp_lostpassword_url() ); ?>"><?php esc_html_e( 'Lost your password?', 'xophz-compass-glowitheflow' ); ?></a>
				</p>

				<p id="backtoblog">
					<a href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php esc_html_e( '&larr; Go to Glow with the Flow', 'xophz-compass-glowitheflow' ); ?></a>
				</p>
			</div>

			<script>
				function togglePasswordVisibility() {
					var input = document.getElementById('user_pass');
					var icon = document.getElementById('pw-toggle-icon');
					if (input.type === 'password') {
						input.type = 'text';
						if (icon) {
							icon.classList.remove('dashicons-visibility');
							icon.classList.add('dashicons-hidden');
						}
					} else {
						input.type = 'password';
						if (icon) {
							icon.classList.remove('dashicons-hidden');
							icon.classList.add('dashicons-visibility');
						}
					}
				}
			</script>

			<?php if ( ! empty( $smoke_canvas_js ) ) : ?>
				<script id="blackbox-smoke-canvas-js">
					<?php echo $smoke_canvas_js; ?>
				</script>
			<?php endif; ?>
		</body>
		</html>
		<?php
		exit;
	}
}
