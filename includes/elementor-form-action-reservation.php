<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Create POS reservation from Elementor form submission
 */
class Reservations_Action_After_Submit extends \ElementorPro\Modules\Forms\Classes\Action_Base {

	/**
	 * Get action name.
	 *
	 * Retrieve ping action name.
	 *
	 * @since 1.0.0
	 * @access public
	 * @return string
	 */
	public function get_name() {
		return 'pos_reservations';
	}

	/**
	 * Get action label.
	 *
	 * Retrieve ping action label.
	 *
	 * @since 1.0.0
	 * @access public
	 * @return string
	 */
	public function get_label() {
		return esc_html__( 'Grindless Reservations', 'elementor-forms-reservations-action' );
	}

	/**
	 * Run action.
	 *
	 * Ping an external server after form submission.
	 *
	 * @since 1.0.0
	 * @access public
	 * @param \ElementorPro\Modules\Forms\Classes\Form_Record  $record
	 * @param \ElementorPro\Modules\Forms\Classes\Ajax_Handler $ajax_handler
	 */
	public function run( $record, $ajax_handler ) {

		$raw_fields = $record->get('fields');

		$fields = [];
		foreach ( $raw_fields as $id => $field ) {
			$fields[ $id ] = $field['value'];
		}

		$phone = $fields['phone'];
		$email = $fields['email'];
		$firstname = $fields['firstname'];
		$lastname = $fields['lastname'];
		$date = new DateTime($fields['time'] . ' ' . $fields['date']);
		echo $date->format('h:ia m/d/Y');
		$message = 'Date/Time: ' . $date->format('h:i a m/d/Y') . ' | ' . $fields['message'];
		$orgid =$fields['orgid'];

		$payload = array(
			'Phone' => $phone,
			'Email' => $email,
			'FirstName' => $firstname,
			'LastName' => $lastname,
			'RequestType' => 'Party',
			'Message' => $message
		);

		$result = wp_remote_post(
		'https://pos.grindless.com/api/customer/request/' . $orgid,
			[
				'method' => 'PUT',
				'headers' => [
					'Content-Type' => 'application/json',
				],
				'body' => wp_json_encode($payload),
				'httpversion' => '1.0',
				'timeout' => 60,
			]
		);

	}

	/**
	 * Register action controls.
	 *
	 * Ping action has no input fields to the form widget.
	 *
	 * @since 1.0.0
	 * @access public
	 * @param \Elementor\Widget_Base $widget
	 */
	public function register_settings_section( $widget ) {}

	/**
	 * On export.
	 *
	 * Ping action has no fields to clear when exporting.
	 *
	 * @since 1.0.0
	 * @access public
	 * @param array $element
	 */
	public function on_export( $element ) {}

}
