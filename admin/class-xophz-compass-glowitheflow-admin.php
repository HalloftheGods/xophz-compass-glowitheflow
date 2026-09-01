<?php

class Xophz_Compass_Glowitheflow_Admin {

	private $plugin_name;
	private $version;

	public function __construct( $plugin_name, $version ) {
		$this->plugin_name = $plugin_name;
		$this->version     = $version;
	}

	public function add_plugin_admin_menu() {
		add_options_page(
			'Glowitheflow Settings',
			'Glowitheflow',
			'manage_options',
			'xophz-compass-glowitheflow',
			array( $this, 'display_plugin_setup_page' )
		);
	}

	public function register_settings() {
		register_setting( 'xophz_compass_glowitheflow_options', 'xophz_compass_glowitheflow_load_mode' );
		register_setting( 'xophz_compass_glowitheflow_options', 'xophz_compass_glowitheflow_custom_slug' );
		register_setting( 'xophz_compass_glowitheflow_options', 'xophz_compass_glowitheflow_load_page_id' );
	}

	public function display_plugin_setup_page() {
		$load_mode = get_option( 'xophz_compass_glowitheflow_load_mode', 'routes_only' );
		$custom_slug = get_option( 'xophz_compass_glowitheflow_custom_slug', 'glowitheflow' );
		$load_page_id = (int) get_option( 'xophz_compass_glowitheflow_load_page_id', 0 );
		$pages = get_pages();
		?>
		<div class="wrap">
			<h2>Xophz Glowitheflow Settings</h2>
			<form method="post" action="options.php">
				<?php
				settings_fields( 'xophz_compass_glowitheflow_options' );
				do_settings_sections( 'xophz_compass_glowitheflow_options' );
				?>
				<table class="form-table">
					<tr valign="top">
						<th scope="row">Load Mode</th>
						<td>
							<fieldset>
								<label>
									<input type="radio" name="xophz_compass_glowitheflow_load_mode" value="routes_only" <?php checked( $load_mode, 'routes_only' ); ?> />
									<strong>Default Route:</strong> Load at <code>/glowitheflow</code>
								</label><br />
								<label>
									<input type="radio" name="xophz_compass_glowitheflow_load_mode" value="custom_slug" <?php checked( $load_mode, 'custom_slug' ); ?> />
									<strong>Custom Slug:</strong> Load at a custom URL slug
								</label><br />
								<label>
									<input type="radio" name="xophz_compass_glowitheflow_load_mode" value="homepage" <?php checked( $load_mode, 'homepage' ); ?> />
									<strong>Site Homepage:</strong> Override the front page with Glowitheflow
								</label><br />
								<label>
									<input type="radio" name="xophz_compass_glowitheflow_load_mode" value="specific_page" <?php checked( $load_mode, 'specific_page' ); ?> />
									<strong>Specific Page:</strong> Target an existing WordPress page
								</label>
							</fieldset>
						</td>
					</tr>
					<tr valign="top">
						<th scope="row">Custom Deployment Slug</th>
						<td>
							<input type="text" name="xophz_compass_glowitheflow_custom_slug" value="<?php echo esc_attr( $custom_slug ); ?>" class="regular-text" />
							<p class="description">URL path where Glowitheflow is accessible (e.g. <code>glowitheflow</code> for <code>/glowitheflow</code>).</p>
						</td>
					</tr>
					<tr valign="top">
						<th scope="row">Target WordPress Page</th>
						<td>
							<select name="xophz_compass_glowitheflow_load_page_id">
								<option value="0">- Select Page -</option>
								<?php foreach ( $pages as $page ) : ?>
									<option value="<?php echo $page->ID; ?>" <?php selected( $load_page_id, $page->ID ); ?>>
										<?php echo esc_html( $page->post_title ); ?> (ID: <?php echo $page->ID; ?>)
									</option>
								<?php endforeach; ?>
							</select>
							<p class="description">Active when Load Mode is set to "Specific Page".</p>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}

	public function flush_rewrites_on_save( $old_value, $new_value ) {
		if ( $old_value !== $new_value ) {
			$public = new Xophz_Compass_Glowitheflow_Public( $this->plugin_name, $this->version );
			$public->register_endpoints();
			flush_rewrite_rules();
		}
	}
}
