<?php
/*
* Class GrindlessPOS
* Provides support for querying information from the Grindless POS database to WordPress
*/

class GrindlessPOS {
	const API = 'https://pos.grindless.com/api';
	const CACHEPERIOD = 60 * MINUTE_IN_SECONDS;
	
	// fetches remote data. set $cache to false to prevent cached data being returned
	// set $json to 'array' to return an associative array or TRUE for an object. False if it's not JSON
	// Return is false if there was a problem. An empty array is returned if there was simply no data to fetch.
	// Note: setting $cache to (int) 0 will make it so the cached data NEVER expires. Use (bool) false instead of
	// (int) 0 to stop cached data from being returned.
	// Setting $cache to -1 (or lower) will delete the cached data and will NOT cache the subsequent API call. This
	// is meant for testing to clear out old data as code is altered, new data is fed from the forced call.
	public static function remote_request( $url, $cache = self::CACHEPERIOD, $json = true, $wp_request_args = array(), $return = 'data' ) {
		global $wpdb, $posapidebug;
		$posapidebug = null;
		
		$data = $response_code = false;

		$grindless_options = get_option('grindless_options');

		$use_staging = isset($grindless_options['use_staging']) ? $grindless_options['use_staging'] : false;
		if ($use_staging || (isset($_REQUEST['usestaging']) && $_REQUEST['usestaging'] == true)) {
			$url = str_replace('pos.grindless.com', 'staging.pos.grindless.com', $url);
		}

		$use_secondary = isset($grindless_options['use_secondary']) ? $grindless_options['use_secondary'] : false;
		if ($use_staging || (isset($_REQUEST['usesecondary']) && $_REQUEST['usesecondary'] == true)) {
			$url = str_replace('staging.pos.grindless.com', 'staging2.pos.grindless.com', $url);
		}
		
		$cache_key = 'posremote_' . md5($url);
		$posapidebug['url'] = $url;
		$posapidebug['cache_key'] = $cache_key;

		$wp_request_args = wp_parse_args($wp_request_args, array(
			'timeout'	=> 10,
			'headers'	=> array(
				'Content-Type: application/json'
			)
		));
		
		// /v1 endpoints need token where vanilla (/api) endpoints do not
		// include token regardless since it doesnt hurt to always send it
		if ($auth_key = GrindlessPOS::get_api_authorization()) {
			$wp_request_args['headers']['Authorization'] = 'Bearer ' . $auth_key;
		}
		
		if (isset($_REQUEST['clearposcache'])) {
			$cache = -1;
		}
		
		// Verify that cache is either not used (false) or is set to a number - just in case this function is misused (param entered in wrong spot for example)
		if ($cache !== false && !is_numeric($cache)) {
			$cache = false;
		}
		
		// if $cache is "-1", force-clear the cache (and fetch new data)
		if (is_numeric($cache) && $cache < 0) {
			delete_transient( $cache_key );
			$cache = false;
		}
		
		if ($cache !== false) {
			$data = get_transient($cache_key);
		}
		
		$posapidebug['cache'] = $cache;
		
		if ( $data === false ) { // Cache wasn't checked or has expired
			$posapidebug['request_args'] = $wp_request_args;
			
			// Fetch data from API
			$response = wp_remote_request($url, $wp_request_args);
			
			$posapidebug['response'] = $response;
			
			// Check for errors in sending the request
			if (is_wp_error($response)) {
				return false;
			}
			
			if ($return === 'raw') {
				$data = $response;
			} else {
				// Make sure the response was OK by checking HTTP status code
				$response_code = wp_remote_retrieve_response_code($response);

				if (empty($response_code) || ($response_code !== 200)) {
					$data = false;
				} else {
					// Parse remote HTML file
					$data = wp_remote_retrieve_body($response);

					// are we expecting a JSON response and need to decode it?
					if ($json) {
						$data = json_decode($data, ($json === 'array'));
					}
				}
			}
			
			// Store remote data in transient, expire after the cache period
			if ($cache !== false) {
				// reminder, $cache is seconds from now (not epoch in future)
				set_transient($cache_key, $data, $cache);
			}
		}
		
		return $data;
	}
	
	private static function remote_get( $url, $cache = self::CACHEPERIOD, $json = true, $wp_request_args = array() ) {
		return self::remote_request( $url, $cache, $json, $wp_request_args);
	}
	
	private static function remote_post( $url, $postdata = array(), $args = array() ) {
		$args = wp_parse_args($args, array(
			'timeout'	=> 10,
			'body'		=> $postdata
		) );

		$grindless_options = get_option('grindless_options');
		$use_staging = isset($grindless_options['use_staging']) ? $grindless_options['use_staging'] : false;

		if ($use_staging || (isset($_REQUEST['usestaging']) && $_REQUEST['usestaging'] == true)) {
			$url = str_replace('https://pos.grindless.com', 'https://staging.pos.grindless.com', $url);
		}
		
		global $posapidebug;
		$posapidebug['url'] = $url;
		$posapidebug['args'] = $args;
		
		return wp_remote_post( $url, $args );
	}
	
	public static function get_organizations( $cache = self::CACHEPERIOD ) {
		$organizations = self::remote_get( self::API . "/organizations", $cache );
		
		// sort alphabetically
		if (is_array($organizations) && count($organizations) && property_exists($organizations[0], 'OrgID')) {
			usort($organizations, function ($item1, $item2) {
				if ($item1->Name == $item2->Name) return 0;
				return $item1->Name < $item2->Name ? -1 : 1;
			});
		}
		
		return $organizations;
	}
	
	public static function get_organization( $org_id, $cache = self::CACHEPERIOD ) {
		return self::remote_get( self::API . "/organization/{$org_id}", $cache );
	}
	
	public static function get_employees( $org_id, $cache = self::CACHEPERIOD ) {
		return self::remote_get( self::API . "/employees/{$org_id}", $cache );
	}
	
	public static function get_employee( $emp_guid, $cache = self::CACHEPERIOD ) {
		return self::remote_get( self::API . "/employee/{$emp_guid}", $cache );
	}
	
	public static function station_message( $org_id, $station, $message ) {
		return self::remote_post( self::API . "/station/message/{$org_id}/{$station}", array('message' => $message) );
	}
	
	public static function get_events( $org_id, $date_from, $date_to, $cache = self::CACHEPERIOD ) {
		return self::remote_get( self::API . "/events/{$org_id}/from/{$date_from}/to/{$date_to}", $cache );
	}
	
	public static function get_event( $org_id, $id, $cache = self::CACHEPERIOD ) {
		return self::remote_get( self::API . "/event/{$org_id}/id/{$id}", $cache );
	}
	
	public static function get_upcoming_events( $org_id, $days = 90, $cache = self::CACHEPERIOD ) {
		return self::remote_get( self::API . "/events/{$org_id}/upcoming/{$days}", $cache );
	}
	
	public static function get_event_tickets( $org_id, $event_id, $cache = self::CACHEPERIOD) {
		return self::remote_get( self::API . "/event/{$org_id}/id/{$event_id}/tickets", $cache );
	}
	
	public static function get_repair( $org_id = '', $cache = self::CACHEPERIOD ) {
		return self::remote_get( self::API . "/parties/{$org_id}", $cache );
	}	
	
	public static function get_parties_active( $org_id, $cache = self::CACHEPERIOD ) {
		return self::remote_get( self::API . "/parties/{$org_id}/active", $cache );
	}	

	public static function get_parties( $org_id, $date_from, $date_to, $cache = self::CACHEPERIOD ) {
		return self::remote_get( self::API . "/parties/{$org_id}/from/{$date_from}/to/{$date_to}", $cache );
	}
	
	public static function get_party_packages( $org_id, $cache = self::CACHEPERIOD ) {
		return self::remote_get( self::API . "/party/packages/{$org_id}", $cache );
	}
	
	public static function get_passes( $org_id, $cache = self::CACHEPERIOD ) {
		return self::remote_get( self::API . "/passes/{$org_id}", $cache );
	}
	
	public static function get_specials( $org_id, $cache = self::CACHEPERIOD ) {
		return self::get_passes( $org_id, $cache );
	}
	
	public static function get_gametime_rates( $org_id, $cache = self::CACHEPERIOD ) {
		return self::remote_get( self::API . "/gametime/{$org_id}", $cache );
	}
	
	public static function get_games_list( $org_id, $cache = self::CACHEPERIOD ) {
		return self::remote_get( self::API . "/games/{$org_id}/list", $cache );
	}    
   
	public static function get_installed_games( $org_id, $station, $cache = self::CACHEPERIOD ) {
		return self::remote_get( self::API . "/station/games/{$org_id}/{$station}", $cache );
	}       
	
	public static function get_memberships( $org_id, $cache = self::CACHEPERIOD ) {
		return self::remote_get( self::API . "/clubs/memberships/{$org_id}", $cache );
	}
	
	public static function get_membership_discounts( $org_id, $clubid, $cache = self::CACHEPERIOD ) {
		return WP_Error('This endpoint is deprecated');
	}
	
	public static function get_membership_freetime( $org_id, $club_id, $cache = self::CACHEPERIOD ) {
		return self::remote_get( self::API . "/club/{$clubid}/freetime/{$org_id}", $cache );
	}
	
	public static function get_sold_tickets($date_from, $date_to, $searchterm, $cache = self::CACHEPERIOD ) {
		return self::remote_get( self::API . "/events/from/{$date_from}/to/{$date_to}?search={$searchterm}", $cache );
	}

	public static function get_products($orgid, $category, $page = 1, $pagesize = 100, $cache = self::CACHEPERIOD) {
		return self::remote_get( self::API . "/v1/inventory/products/{$orgid}/{$category}/{$page}/{$pagesize}", $cache );
	}

	public static function post_alert($orgid, $title, $text, $importance = 0) {
		if ($importance > 3)
			$importance = 3;
		elseif ($importance < 0)
			$importance = 0;
		
		$response = self::remote_post(self::API . "/alert", array(
			'OrgID' => $orgid,
			'Importance' => $importance,
			'title' => $title,
			'text' => $text
		));
		
		if ( is_wp_error( $response ) )
			return false;
		
		$response_code = wp_remote_retrieve_response_code( $response );

		if ( empty($response_code) || ($response_code !== 200) )
			return false;

		$responsemsg = wp_remote_retrieve_body($response);
		if (is_string($responsemsg) && strlen($responsemsg)) {
			if ($responsemsg === 'true') {
				return true;
			} elseif ($responsemsg === 'false') {
				return false;
			}
		}
		
		return null;
	}
    
    
    public static function make_reservation_request($org_id, $phone, $email, $first, $last, $message) {
        $response = self::remote_post(self::API . "/customer/request/{$org_id}", array(
			'Phone' => $phone,
			'Email' => $email,
			'FirstName' => $first,
			'LastName' => $last,
            'RequestType' => 'Party',
            'Message' => $message
		), 
        array(
            'method' => 'PUT'
        ));
		
		global $posapidebug;
		$posapidebug['response'] = $response;
		
        if ( is_wp_error( $response ) )
			return false;
		
		$response_code = wp_remote_retrieve_response_code( $response );

		if ( empty($response_code) || ($response_code !== 200) )
			return false;        
        
        return true;
    }

	public static function status_check() {
		return wp_remote_request("https://pos.grindless.com/statuscheck.aspx", array(
			'headers' => array(
				'user-agent' => 'Grindless POS Health Check/1.0',
				'timeout' => 8
			)
		));
	}
	
	// return API auth key from database (or fetch from POS if not set)
	public static function get_api_authorization($force_renew = false) {
		global $posapidebug;

		$posapidebug['force_renew'] = $force_renew;
		
		// note- transients are used in place of options here because the token expires
		// and we can leverage WP's built-in transient cleanup features
		$transient_key = 'glapi-authkey';
		$posapidebug['transient_key'] = $transient_key;
		$auth_key = ($force_renew) ? false : get_transient($transient_key);
		// TO-DO: what if we have a cached key that is no longer valid (which shouldn't normally happen)
		
		// check to see if our auth token has expired and we need to get a new one
		if ($auth_key === false || !is_string($auth_key) || empty($auth_key)) {
			$posapidebug['api_auth_renew_needed'] = true;
			// go out and get an auth key from the POS.
			// returns the actual key (string) or false (bool) if auth failed
			$auth_key = self::renew_api_authorization();
		} else {
			$posapidebug['api_auth_renew_needed'] = false;
		}
		
		return $auth_key;
	}
	
	// fetch brand new auth key from POS
	public static function renew_api_authorization() {
		global $posapidebug;

		$fallback_expiration = 3 * DAY_IN_SECONDS;
		
		$payload = array(
			'OrgBillingID' => 0, // changed 04-21-2026
			'Secret' => '',
			'Access' => ''
		);
		
		$grindless_options = get_option('grindless_options');
		
		// get the stored billing org billing ID (which is different from the OrgID)
		$partition_id = $grindless_options['partition_id'];
		if ($partition_id) {
			$payload['OrgBillingID'] = intval($partition_id);
		}
		
		// get the secret key
		$api_secret = $grindless_options['api_secret'];
		if (!$api_secret) { // TO-DO handle this gracefully
			return false;
		} else {
			$payload['Secret'] = $api_secret;
		}
		
		// determine level of access needed for this authorization request
		$payload['Access'] = self::determine_api_access_level();
		
		$posapidebug['api_auth_payload'] = $payload;

		$remote_args = array(
			'headers' => array(
				'Content-Type' => 'application/json'
			)
		);
		
		$response = self::remote_post(self::API . "/authorize", json_encode($payload), $remote_args);
		$posapidebug['remote_response'] = $response;
		
		// Make sure the response was OK by checking HTTP status code
		$response_code = wp_remote_retrieve_response_code($response);
		if ( empty($response_code) || ($response_code !== 200) ) {
			return false;
		}
		
		$response_body = wp_remote_retrieve_body($response);
		$response_json = json_decode($response_body, true);
		if (!is_null($response_json) && isset($response_json['Key']) && is_string($response_json['Key'])) {
			// this part is intentionally not consolidated for easier debugging ->
			
			$expires = isset($response_json['Expires']) ? $response_json['Expires'] : null; // example: "2021-06-25 09:38:44Z" (GMT)
			if ($expires) {
				$ExpirationDate = new DateTime($expires);
				$ExpirationDate->setTimezone(new DateTimeZone('UTC'));
				$NowDate = new DateTime('NOW');
				$expiration_seconds = $ExpirationDate->getTimestamp() - $NowDate->getTimestamp();
				
				// make sure expiration time is valid
				if ($expiration_seconds < 0) {
					$expiration_seconds = $fallback_expiration;
				}
			} else {
				// for some reason, the POS didn't reply with an expiration time for the Auth key.
				// this shouldn't happen, but doesn't stop us from continuing.
				// Set it to 3 days (it's usually longer, so this should be safe)
				$expiration_seconds = $fallback_expiration;
			}
			
			// cache it for later use
			$result = set_transient('glapi-authkey', $response_json['Key'], $expiration_seconds);
			
			if ($result !== true) {
				// it could not store the key for future use
				// this usually happens when WP receives empty data, an invalid key, etc.
				// TO-DO: handle this gracefully
			}
			
			return $response_json['Key'];
		} else {
			// no key was returned from the POS. This shouldn't happen
			// TO-DO: replace this with WP_Error and handle it in other calls
			return false;
		}
	}
	
	public static function determine_api_access_level() {
		$access_level = null; // the actual number sent to POS for auth
		$access_privs = array(); // an array of required privs (array of strings), used to calculate $access_level
		
		// see https://pos.grindless.com/apis
		$access_level_map = array(
			1 => 'GeneralAccess',
			2 => 'OrderRead',
			4 => 'OrderFull',
			8 => 'ProductBrowse',
			16 => 'Users',
			32 => 'Reservations',
			64 => 'Timer',
			256 => 'Events',
			512 => 'Customers',
			1024 => 'CustomerService'
		);
		
		// general is needed for things like getting store config and using customer support API, plus others.
		// should be fine including this by default
		$access_privs[] = 'GeneralAccess';
		
		$online_shop_enabled = true; // to-do, make this a checkbox on a settings page somewhere
		
		if ($online_shop_enabled) {
			$access_privs[] = 'OrderRead';
			$access_privs[] = 'OrderFull';
			$access_privs[] = 'ProductBrowse';
			$access_privs[] = 'Users';
		}
		
		// check to see if site is using events calendar. If so, add this to the access level
		if(in_array('the-events-calendar/the-events-calendar.php', apply_filters('active_plugins', get_option('active_plugins')))) {
			$access_privs[] = 'Events';
			$access_privs[] = 'Reservations';
		}
		
		// allow the access level to be change by other functions (optional)
		$access_privs = apply_filters('glapi-access-privs', $access_privs, $access_level_map);
		
		// calculate access level (bitfield)
		if (count($access_privs)) {
			$privs_assoc = array_intersect($access_level_map, array_unique($access_privs));
			$access_level = array_sum(array_keys($privs_assoc));
		}
		
		return $access_level;
	}


}