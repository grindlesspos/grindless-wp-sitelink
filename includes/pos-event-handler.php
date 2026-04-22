<?php
/*
The Grindless POS may send data to this WP site, if configured,
in the form of webhook calls. This script defines how those actions
are handled on WP's side.

OLD CODE AHEAD
Needs work before it can be used
*/

// some events triggered in the POS will need to be sync'd with WP.
add_action( 'init', array( 'POSEventHandler', 'init' ) );


class POSEventHandler {
	protected static $instance;
	
	public static function init() {
		null === self::$instance AND self::$instance = new self;
		return self::$instance;
	}
	
	public function __construct() {
		add_action( 'wp_ajax_posevent', array($this, 'event_router') );
		add_action( 'wp_ajax_nopriv_posevent', array($this, 'event_router') );
	}
	
	public function event_router() {
		if ($_SERVER['REMOTE_HOST'] !== GrindlessPOS::get_remote_api_ip()) {
			wp_send_json_error(array('message' => 'Error. Request to update password was made from an external server. Only the originating server is allowed to do this.'));
		}
		$data = $_POST;
		
		if (empty($data)) {
			wp_send_json_error(array('message' => 'Payload was empty.'));
		}
		
		if (!isset($data['Event'])) {
			wp_send_json_error(array('message' => 'Cannot route event to callback. Payload did not have an event specified.'));
		}
		
		$method = 'event__' . strtolower($data['Event']);
		if (method_exists($this, $method)) {
			call_user_func(array($this, $method), $data);
		} else {
			wp_send_json_error(array('message' => 'Could not find a suitable method. See error log for more.'));
			error_log(__METHOD__ . ': ERROR. Could not find relevant method for handling this type of request. Action given was "' . $data['Event'] . '".');
		}
		die;
	}
	
	// fires when an employee account is created/edited/deleted in the POS. Update correlating user in WP
	public function event__employee($data) {
		$wp_user = 0;
		$pos_user = GrindlessPOS::get_employee($data['GUID']);
		
		if (!is_object($pos_user)) {
			wp_send_json_error(array('message' => 'Invalid user. Not found in POS API.'));
		}
		
		// get WP user
		$wp_users = get_users( array(
			'meta_key'     => 'guid',
			'meta_value'   => $data['GUID'],
			'meta_compare' => '='
		) );
		
		if (empty($wp_users) && $pos_user->Active == true) {
			// user doesn't have an account or their GUID is not set in WP.
			// check to see if we can find their account by email/other means. If no account exists in WP, make one.
			
		} else {
			if (count($wp_users) > 1) {
				// multiple users match the incoming GUID. This should never happen.
				// tell an admin?
			}
			$wp_user = $wp_users[0];
		}
		
		// user info is now present. time to update user in wp
		
		// DELETE CHECK
		if (is_array($pos_user->ActiveOrgs) && count($pos_user->ActiveOrgs) > 1) {
			// time to remove them from wp
		}
		
		//error_log(__METHOD__.': Dumping... ($data, $wp_user, $pos_user) ' . print_r(array($data, $wp_user, $pos_user), true));
		
		wp_send_json_success(array('message' => 'User profile updated.'));
	}
	
	// fires when an org is changed/created
	public function event__organization($data) {
		//error_log(__METHOD__.': Dumping... ($data) ' . print_r($data, true));
	}
}