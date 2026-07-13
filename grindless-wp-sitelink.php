<?php
/*
Plugin Name:	Grindless SiteLink
Plugin URI:		https://grindless.com
Description:	A collection of useful utilities for Grindless clients
Version:		1.3.5
Author:			Grindless LLC.
Author URI:		mailto:admin@grindless.com
*/

if (!defined('ABSPATH')) die; // Don't allow direct loading

add_action('plugins_loaded', array('GrindlessSiteLink', 'init'), 20);
register_activation_hook(__FILE__, array('GrindlessSiteLink', 'plugin_activate'));
register_deactivation_hook(__FILE__, array('GrindlessSiteLink', 'plugin_deactivate'));

class GrindlessSiteLink {
	const version = '1.3.5';
	const events_cron_hook = 'grnd_events_cron_action';
	public static $instance = null;
	public static $plugin_path;				// PHP friendly path to this plugin
	public static $plugin_url;				// browser friendly URL to this plugin's directory
	private static $glutil_debug;
	
	// runs when the plugin is activated in WP
	public static function plugin_activate() {
		$result = self::schedule_events_cron();

		if (is_wp_error($result) || $result === false) {
			deactivate_plugins(plugin_basename(__FILE__));

			$message = is_wp_error($result) ? $result->get_error_message() : 'unknown scheduling error';
			wp_die(esc_html('Error registering sync cron action: ' . $message));
		}

		// clear any cached API results
		// if something broke and we fix it, stale results could delay fixes from being seen publicly
		global $wpdb;
		$wpdb->query(
			"
			DELETE FROM {$wpdb->options}
			WHERE option_name LIKE '\\_transient\\_posremote\\_%'
			   OR option_name LIKE '\\_transient\\_timeout\\_posremote\\_%'
			"
		);
	}

	// runs when the plugin is deactivated in WP
	public static function plugin_deactivate() {
		wp_clear_scheduled_hook(self::events_cron_hook);
	}

	// get the selected cron schedule or use the default
	public static function get_events_cron_schedule($options = null) {
		if (!is_array($options)) {
			$options = get_option('grindless_options', array());
		}

		$schedule = !empty($options['events_cron_schedule']) ? sanitize_key($options['events_cron_schedule']) : 'hourly';
		$schedules = wp_get_schedules();

		return isset($schedules[$schedule]) ? $schedule : 'hourly';
	}

	// schedule the event sync if it is not already scheduled
	public static function schedule_events_cron($options = null) {
		if (wp_next_scheduled(self::events_cron_hook)) {
			return true;
		}

		return wp_schedule_event(
			time() + MINUTE_IN_SECONDS,
			self::get_events_cron_schedule($options),
			self::events_cron_hook,
			array(),
			true
		);
	}

	// restore the cron event if it is ever removed
	public static function ensure_events_cron() {
		if (!wp_next_scheduled(self::events_cron_hook)) {
			self::schedule_events_cron();
		}
	}

	// rebuild the cron event when its schedule changes
	public static function options_updated($old_value, $value, $option) {
		$old_schedule = self::get_events_cron_schedule(is_array($old_value) ? $old_value : array());
		$new_schedule = self::get_events_cron_schedule(is_array($value) ? $value : array());

		if ($old_schedule === $new_schedule) {
			return;
		}

		wp_clear_scheduled_hook(self::events_cron_hook);
		delete_transient('grnd-events-lastcheck');
		self::schedule_events_cron($value);
	}

	// runs each time WP loads (fires relatively early)
	public static function init() {
		null === self::$instance AND self::$instance = new self;
		return self::$instance;
	}
	
	// initialize plugin
	public function __construct() {
		self::$plugin_path = WP_PLUGIN_DIR . '/' . basename(dirname(__FILE__)) . '/';
		self::$plugin_url = WP_PLUGIN_URL . '/' . basename(dirname(__FILE__)) . '/';
		
		self::$glutil_debug = (isset($_GET['glutil_debug']) && current_user_can('manage_options')) ? $_GET['glutil_debug'] : false;
		
		// load POS API class
		if (!class_exists('GrindlessPOS')) {
			require_once(__DIR__ . '/includes/grindless-pos-api.php');
		}
		
		// load Tribe Events functions (events importing, etc)
		if (!class_exists('GrindlessTribeEvents')) {
			require_once(__DIR__ . '/includes/tribe-events-functions.php');
		}
		new GrindlessTribeEvents();

		// keep the cron event available and update it when settings change
		add_action('init', array(__CLASS__, 'ensure_events_cron'));
		add_action('update_option_grindless_options', array(__CLASS__, 'options_updated'), 10, 3);

		if ( in_array( 'elementor/elementor.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ) ) ) {
			add_action( 'elementor_pro/forms/actions/register', array($this, 'elementor_register_reservations_form_action' ) );
		}

		if (is_admin()) {
			// setup a Grindless Settings page
			require_once(__DIR__ . '/includes/wp-admin-settings.php');
			
			// add in Grindless-branded admin theme
			add_action('admin_init', array($this, 'additional_admin_color_schemes'));
		} else {
			add_filter( 'template_include', array($this, 'template_include') );
		}

		add_action('wp_insert_post', array($this, 'status_post_publish'), 10, 3);
		add_shortcode('system_status', array($this, 'status_sc_output'));
	}
	
	// add custom wp-admin theme
	function additional_admin_color_schemes() {
		wp_admin_css_color(
			'grindless',
			'Grindless',
			self::$plugin_url . '/assets/css/admin-colors-grindless.css',
			array('#300947', '#450d65', '#7d5694', '#f8d42f'),
			array('base' => '#e5f8ff', 'focus' => '#fff', 'current' => '#fff')
		);
	}

	public function template_include($template) {
		if (is_page('sandbox')) {
			$template = self::$plugin_path . '/templates/page-sandbox.php';
		}

		return $template;
	}

	public static function elementor_register_reservations_form_action($form_actions_registrar) {
		include_once(__DIR__ . '/includes/elementor-form-action-reservation.php' );
		$form_actions_registrar->register( new \Reservations_Action_After_Submit() );
	}

	public static function status_collect_recipients() {
		if ( !in_array( 'elementor/elementor.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ) ) ) {
			return array();
		}

		global $wpdb;

		$recipients = array();

		$cutoff = $datetime = date('Y-m-d H:i:s', strtotime('-24 hours'));

		$results = $wpdb->get_results(
			"SELECT val.submission_id,val.key,val.value FROM {$wpdb->prefix}e_submissions_values val 
			JOIN {$wpdb->prefix}e_submissions sub on sub.id=val.submission_id 
			WHERE sub.form_name = 'Status Notify'
			AND sub.created_at_gmt >= '{$cutoff}'
			"
		);

		if (!count($results)) {
			return array();
		}

		// format the results
		foreach($results as $result) {
			$recipients[$result->submission_id][$result->key] = $result->value;
		}

		return $recipients;
	}

	public static function status_send_notifications($post, $delivery = array('email', 'sms')) {
		$results = array();

		$recipients = self::status_collect_recipients();

		if (!$recipients) {
			return $results;
		}

		if (in_array('email', $delivery)) {
			// emails
			$recip_email = array_column($recipients, 'email');
			if (count($recip_email)) {
				$recip_email = array_unique($recip_email);

				$subject = 'New Status Alert: ' . get_the_title($post);
				$message_email = '<h1>System Status Alert</h1><br>';
				$message_email .= '<p>You are receiving this message because you have subscribed to receive notifications about system outages and status messages.</p>';
				$message_email .= '<p>A new status alert message has been posted by our team. The contents of this alert are as follows:</p>';
				$message_email .= get_the_content(null, true, $post);
				$message_email .= '<p>For more info and to read past alerts, visit the status page at <a href="https://grindless.com/status">grindless.com/status</a>.</p>';
				//error_log('Email recipients: ' . print_r($recip_email, true));
				$results['email'] = self::status_send_email($recip_email, $subject, $message_email);
			}
		}

		if (in_array('sms', $delivery) && file_exists(__DIR__ . '/lib/Twilio/autoload.php')) {
			require_once(__DIR__ . '/lib/Twilio/autoload.php');

			// SMS texts
			$recip_phone = array_column($recipients, 'phone');
			if (count($recip_phone)) {
				$recip_phone = array_unique($recip_phone);
				$message_sms = 'New Grindless Status Alert: ';
				$message_sms .= get_the_excerpt($post);
				$message_sms .= ' | More: https://grindless.com/status';
				$results['sms'] = self::status_send_sms($recip_phone, $message_sms);
			}
		}

		return $results;
	}

	public static function status_send_email($email_recipients, $subject, $message) {
		foreach($email_recipients as $email_address) {
			$result = wp_mail($email_address, $subject, $message);
		}
	}

	public static function status_send_sms($sms_recipients, $message) {
		$sender = null;
		$sid = null;
		$token = null;
		$twilio = new Twilio\Rest\Client($sid, $token);

		foreach ($sms_recipients as $phone_number) {
			$twilio->messages->create(
				$phone_number,
				[
					'from' => $sender,
					'body' => $message
				]
			);
		}
	}

	public static function status_post_publish($post_id, $post, $update) {
		if ( $post->post_type !== 'post' || $post->post_status !== 'publish' || !in_category('status-alerts', $post) ) {
			return;
		}

		// make sure we are not somehow dealing with old posts
		if (strtotime(get_the_date('', $post)) < strtotime('-24 hours')) {
			return;
		}
		
		// make sure we have not already sent notifications for this post
		$has_sent = get_post_meta($post_id, 'alerts_sent', true);
		if (!empty($has_sent) || $has_sent == 'false') {
			return;
		}

		// send notifications
		self::status_send_notifications($post);

		update_post_meta($post_id, 'alerts_sent', 'true' );
	}

	public static function status_sc_output( $atts ){
		$args = shortcode_atts( array(
			'foo' => 'something',
		), $atts );

		// reminder: anything echoed here will show up out of place
		
		// wrap output in output buffer
		ob_start();

		$response = GrindlessPOS::status_check();

		$response_code = wp_remote_retrieve_response_code($response);

		$force_fail = isset($_GET['forcefail']);

		if ($response_code !== 200 || $force_fail == true) {
			if ( $template = get_page_by_path( 'status-fail', 'OBJECT', 'elementor_library' ) ){	
				$shortcode = ' [elementor-template id="'.$template->ID.'"] ';
				echo do_shortcode( $shortcode );
			} else {
				echo '<h3>Outage Detected</h3>';
				echo '<p>An outage has been detected. Our development team has been alerted to the issue.</p>';
			}
		} else {
			if ( $template = get_page_by_path( 'status-pass', 'OBJECT', 'elementor_library' ) ){	
				$shortcode = ' [elementor-template id="'.$template->ID.'"] ';
				echo do_shortcode( $shortcode );
			} else {
				echo '<h3>Systems Online</h3>';
				echo '<p>The Point of Sale is online and operational.</p>';
			}

			echo '<div style="display: none;">';
			$doc = new DOMDocument();
			libxml_use_internal_errors(true);
			$doc->loadHTML(wp_remote_retrieve_body($response));
			libxml_clear_errors();

			$pools = $doc->getElementById('lblPools');

			$metrics = array();

			$table = $doc->getElementById('grd');

			if ($table) {
				foreach ($table->getElementsByTagName('tr') as $row) {
					$cols = $row->getElementsByTagName('td');
					if ($cols->length >= 2) {
						$key = sanitize_key($cols->item(0)->textContent);
						$value = trim($cols->item(1)->textContent);
						$metrics[$key] = $value;
					}
				}
			}

			
			echo '<h3>Uptime</h3>';
			if (isset($metrics['uptime'])) {
				$uptime = $metrics['uptime'];
				$start = new DateTime('now');
				$end = new DateTime('now');
				$end->sub(new DateInterval("PT{$uptime}S"));
				$diff = $start->diff($end);
				echo '<p>Time since last shutdown: ';
				echo $diff->format('%a days, %h hours, %i minutes, %s seconds');
				echo '</p>';
			}
			echo '<h3>Application Pools</h3>';
			echo $pools->textContent;
			echo '</div>';
		}

		return ob_get_clean();
	}
}