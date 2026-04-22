<?php

class Elementor_Grindless_Form_POS_Reservations extends \ElementorPro\Modules\Forms\Classes\Action_Base {
    public function get_name() {
		return 'grindless_reservations';
	}

    public function get_label() {
		return 'Grindless POS Reservations';
	}

    public function run( $record, $ajax_handler ) {
		$settings = $record->get( 'form_settings' );
		
		if ( empty( $settings['grindless_orgid'] ) ) {
			return;
		}
		
		// Get submitetd Form data
		$raw_fields = $record->get( 'fields' );
		
		// Normalize the Form Data
		$fields = [];
		foreach ( $raw_fields as $id => $field ) {
			$fields[ $id ] = $field['value'];
		}
		
		// Make sure that the user entered an email
		if ( empty( $fields[ $settings['sendy_email_field'] ] ) ) {
			return;
		}
		
		$sendy_data = [
			'email' => $fields[ $settings['sendy_email_field'] ],
			'OrgID' => $settings['grindless_orgid'],
			'ipaddress' => \ElementorPro\Classes\Utils::get_client_ip(),
			'referrer' => isset( $_POST['referrer'] ) ? $_POST['referrer'] : '',
		];
		
		GrindlessPOS::post_party_request($settings['grindless_orgid'], $phone, $email, $first, $last, $message);
	}

    public function register_settings_section( $widget ) {
		
	}

    public function on_export( $element ) {
		$widget->start_controls_section(
            'section_grindless_reservations',
            [
                'label' => 'Grindless POS Reservations',
                'condition' => [
                    'submit_actions' => $this->get_name(),
                ],
            ]
        );
		
		$widget->add_control(
            'grindless_orgid',
            [
                'label' => 'Organization ID',
                'type' => \Elementor\Controls_Manager::TEXT,
                'placeholder' => '1070',
                'label_block' => true,
                'separator' => 'before',
                'description' => 'Enter your Organization ID from the Grindless POS',
            ]
        );
		
		$widget->end_controls_section();
	}
}