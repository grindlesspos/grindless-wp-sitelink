<?php

class GrindlessSettings {
	protected $grindless_options;

	public function __construct() {
		$this->grindless_options = get_option('grindless_options');
		if ($this->grindless_options === false)
			$this->grindless_options = array();
		
		add_action( 'admin_menu', array( $this, 'grindless_settings_add_plugin_page' ) );
		add_action( 'admin_init', array( $this, 'grindless_settings_page_init' ) );
	}

	public function grindless_settings_add_plugin_page() {
		add_menu_page(
			'Grindless Settings', // page_title
			'Grindless', // menu_title
			'manage_options', // capability
			'grindless-settings', // menu_slug
			array( $this, 'grindless_settings_create_admin_page' ), // function
			'data:image/svg+xml;base64,' . base64_encode(file_get_contents(GrindlessSiteLink::$plugin_path . 'assets/img/grindless-logo-20210621v3-insignia-greyscale.svg')), // icon_url
			80 // position
		);
	}

	public function grindless_settings_create_admin_page() {
		?>
		<div class="wrap">
			<h2>Grindless Settings</h2>
			<p>This page contains settings that pertain to Grindless plugins. It is strongly recommended to consult with a Grindless Support Staff Member before making changes to any settings on this page.</p>
			<?php settings_errors(); ?>

			<form method="post" action="options.php">
				<?php
					settings_fields( 'grindless_settings_option_group' );
					do_settings_sections( 'grindless-settings-admin' );
					submit_button();
				?>
			</form>
		</div>
	<?php }

	public function grindless_settings_page_init() {
		register_setting(
			'grindless_settings_option_group', // option_group
			'grindless_options', // option_name
			array( $this, 'grindless_settings_sanitize' ) // sanitize_callback
		);

		add_settings_section(
			'grindless_settings_setting_section', // id
			'Point of Sale API Authorization', // title
			array( $this, 'grindless_settings_section_info' ), // callback
			'grindless-settings-admin' // page
		);

		add_settings_field(
			'partition_id', // id
			'Partition ID', // title
			array( $this, 'partition_id_callback' ), // callback
			'grindless-settings-admin', // page
			'grindless_settings_setting_section' // section
		);

		add_settings_field(
			'organization_id', // id
			'Organization ID', // title
			array( $this, 'organization_id_callback' ), // callback
			'grindless-settings-admin', // page
			'grindless_settings_setting_section' // section
		);

		add_settings_field(
			'api_secret', // id
			'API Secret', // title
			array( $this, 'api_secret_callback' ), // callback
			'grindless-settings-admin', // page
			'grindless_settings_setting_section' // section
		);

		if (isset($_GET['page']) && $_GET['page'] === 'grindless-settings' && isset($_GET['addcap'])) {
			$cur_user = get_user_by('ID', get_current_user_id());
			 if (!$cur_user->has_cap('grindless_dev')) {
				$cur_user->add_cap('grindless_dev');
			}
		}

		if (current_user_can('grindless_dev')) {
			add_settings_field(
				'use_staging', // id
				'Use Staging', // title
				array( $this, 'use_staging_callback' ), // callback
				'grindless-settings-admin', // page
				'grindless_settings_setting_section' // section
			);

			add_settings_field(
				'use_secondary', // id
				'Use Secondary Server', // title
				array( $this, 'secondary_server_callback' ), // callback
				'grindless-settings-admin', // page
				'grindless_settings_setting_section' // section
			);
		}
	}

	public function grindless_settings_sanitize($input) {
		$sanitary_values = array();
		if ( isset( $input['partition_id'] ) ) {
			$sanitary_values['partition_id'] = sanitize_text_field( $input['partition_id'] );
		}

		if ( isset( $input['organization_id'] ) ) {
			$sanitary_values['organization_id'] = sanitize_text_field( $input['organization_id'] );
		}

		if ( isset( $input['api_secret'] ) ) {
			$sanitary_values['api_secret'] = sanitize_text_field($input['api_secret']);
			delete_transient('glapi-authkey');
		}

		if ( isset( $input['use_staging'] ) ) {
			$sanitary_values['use_staging'] = $input['use_staging'];
		}

		if ( isset( $input['use_secondary'] ) ) {
			$sanitary_values['use_secondary'] = $input['use_secondary'];
		}
		
		if ( isset( $input['shop_page_id'] ) ) {
			$sanitary_values['shop_page_id'] = sanitize_text_field( $input['shop_page_id'] );
		}
		
		if ( isset($input['shop_nav_menu']) ) {
			$sanitary_values['shop_nav_menu'] = sanitize_text_field( $input['shop_nav_menu'] );
		}

		if ( isset($input['shop_tickets_only']) ) {
			$sanitary_values['shop_tickets_only'] = sanitize_text_field( $input['shop_tickets_only'] );
		}
		
		if ( isset( $input['shop_nav_depth'] ) ) {
			$depth = intval($input['shop_nav_depth']);
			if ($depth < 1) {
				$depth = 1;
			} elseif ($depth > 10) {
				$depth = 10;
			} else {
				// it's fine
			}
			
			$sanitary_values['shop_nav_depth'] = $depth;
		}
		
		if ( isset( $input['events_cron_schedule'] ) ) {
			$sanitary_values['events_cron_schedule'] = sanitize_text_field( $input['events_cron_schedule'] );
		}

		if ( isset( $input['events_days_past'] ) ) {
			$days_past = intval($input['events_days_past']);
			if ($days_past < 0) {
				$days_past = 0;
			} elseif ($days_past > 365) {
				$days_past = 365;
			}

			$sanitary_values['events_days_past'] = $days_past;
		}

		if ( isset( $input['events_days_future'] ) ) {
			$days_future = intval($input['events_days_future']);
			if ($days_future < 1) {
				$days_future = 1;
			} elseif ($days_future > 365) {
				$days_future = 365;
			}

			$sanitary_values['events_days_future'] = $days_future;
		}

		return $sanitary_values;
	}

	public function grindless_settings_section_info() {
		echo '<p>Before your website can interact with the <a href="https://pos.grindless.com/" target="_blank">Grindless Point of Sale\'s API</a>, you must first fill out the fields below. You can retreive information needed by logging into the POS and going to Settings &raquo; API Access.</p>';
	}

	public function partition_id_callback() {
		printf(
			'<input class="regular-text" type="text" name="grindless_options[partition_id]" id="partition_id" value="%s" autocomplete="off">',
			isset( $this->grindless_options['partition_id'] ) ? esc_attr( $this->grindless_options['partition_id']) : ''
		);
		print('<p class="description">Enter the Partition ID tied to your company from the POS. This is NOT the same thing as your Organization ID. Organization ID refers to one store within your company whereas the Billing ID refers to your entire company in the POS.</p>');
	}

	public function organization_id_callback() {
		printf(
			'<input class="regular-text" type="text" name="grindless_options[organization_id]" id="organization_id" value="%s" autocomplete="off">',
			isset( $this->grindless_options['organization_id'] ) ? esc_attr( $this->grindless_options['organization_id']) : ''
		);
		print('<p class="description">Enter your primary store\'s Organization ID. This will be used as a fallback or default in certain situations.</p>');
	}

	public function api_secret_callback() {
		printf(
			'<input class="regular-text" type="%s" name="grindless_options[api_secret]" id="api_secret" value="%s" autocomplete="off">',
			isset($this->grindless_options['api_secret']) && !empty($this->grindless_options['api_secret']) ? 'password' : 'text',
			isset($this->grindless_options['api_secret']) ? esc_attr( $this->grindless_options['api_secret']) : ''
		);
				
		$api_connected = $auth_key = false;
		if (isset($this->grindless_options['api_secret']) && !empty($this->grindless_options['api_secret'])) {
			$force_renew = isset($_GET['renew_auth']);

			$auth_key = GrindlessPOS::get_api_authorization($force_renew);

			if (isset($auth_key) && $auth_key !== false) {
				$api_connected = true;
				if ($force_renew === true && $api_connected === true) {
					echo 'Public Auth Key successfully renewed.';

					if (current_user_can('grindless_dev')) {
						echo '<br><br>DEBUG: POS API<br>';
						global $posapidebug;
						var_dump($posapidebug);
					}
				}
			}
		}

		print('<p class="description">');

		print('Enter your API Secret. This is a special security key used to authenticate API requests sent to the Grindless Server from this specific website.<br>');
		
		printf(
			'API Connectivity: <span style="font-weight: bold; color: %s;">%s</span>',
			$api_connected ? 'green' : 'red',
			$api_connected ? 'CONNECTED - Public Key: ' . $auth_key: 'DISCONNECTED'
		);

		print('</p>');

		if ($api_connected) {
			printf('<p><a class="button" href="%s">Renew Public Key</a></p>', admin_url('admin.php?page=grindless-settings&renew_auth=true'));
		} else {
			//
		}
	}

	public function use_staging_callback() {
		$use_staging = isset($this->grindless_options['use_staging']) ? $this->grindless_options['use_staging'] : false;
		printf('<input type="checkbox" id="use_staging" name="grindless_options[use_staging]" value="true" %s>', checked($use_staging, 'true', false));
		echo '<label for="use_staging">Use Staging Server</label>';

		print('<p class="description">Use Staging site for remote API calls. For testing and development purposes only. Do not use on production sites.</p>');
	}

	public function secondary_server_callback() {
		$use_secondary = isset($this->grindless_options['use_secondary']) ? $this->grindless_options['use_secondary'] : false;
		printf('<input type="checkbox" id="use_secondary" name="grindless_options[use_secondary]" value="true" %s>', checked($use_secondary, 'true', false));
		echo '<label for="use_secondary">Use Secondary POS Server</label>';

		print('<p class="description">Use Secondary POS server for remote API calls. Requires Using Staging (above) to be active to take effect.</p>');
	}

}
if ( is_admin() )
	$grindless_settings = new GrindlessSettings();
