<?php


class GrindlessTribeEvents {
	public static $instance = null;
	protected static $debug = false;
	
	public function __construct() {
		add_action('wp_ajax_gevents_sync', array($this, 'ajax_events_sync'));
		add_action('wp_ajax_nopriv_gevents_sync', array($this, 'ajax_events_sync'));
		add_action('grnd_events_cron_action', array($this, 'grnd_events_cron_func'));
		
		self::$debug = (isset($_GET['gdebug']) && current_user_can('manage_options')) ? $_GET['gdebug'] : false;
		
		add_action('admin_init', array( $this, 'settings_page_init'));
		add_filter('grindless-options-sanitary-values', array($this, 'settings_sanitize'), 11, 2);
		
		/*
		default image override (stored as meta on category term)
		Adds a field to event categories so a default image can be set for an entire category at once.
		Images set per each event will still be shown instead of these defaults.
		*/
		add_filter('post_thumbnail_id', array($this, 'insert_event_image'), 10, 2);
		// below: how the default category image is chosen and saved in wp-admin area
		add_action('tribe_events_cat_add_form_fields', array($this, 'add_catimage_group_field'), 10, 2);
		add_action('created_tribe_events_cat', array($this, 'save_catimage_meta'), 10, 2);
		add_action('tribe_events_cat_edit_form_fields', array($this, 'edit_catimage_group_field'), 10, 2);
		add_action('edited_tribe_events_cat', array($this, 'update_catimage_meta'), 10, 2);
		add_filter('manage_edit-tribe_events_cat_columns', array($this, 'add_catimage_column'));
		add_filter('manage_tribe_events_cat_custom_column', array($this, 'add_image_column_content'), 10, 3);
		add_action('admin_footer', array($this, 'media_selector_print_scripts'));
		/* END default image */
		
		if(in_array('grindless-wp-sitelink/grindless-wp-sitelink.php', apply_filters('active_plugins', get_option('active_plugins')))) {
			add_action('tribe_events_single_event_after_the_content', array($this, 'add_tickets_link'));
		}
	}
	
	public static function ajax_events_sync() {
		$sync_result = self::events_sync();
		
		wp_send_json($sync_result);
	}
	
	public static function events_sync($dryrun = false, $nocache = false) {
		$sync_result = array(
			'did_sync' => false,
			'last_update' => 0,
			'earliest_sync' => 0,
			'added_count' => 0,
			'added' => array(),
			'updated_count' => 0,
			'updated' => array(),
			'deleted_count' => 0,
			'deleted' => array(),
			'message' => 'Sync did not occur',
		);
		
		// setup sync-frequency vars
		$grindless_options = get_option('grindless_options');
		$cron_schedule_key = isset($grindless_options['events_cron_schedule']) ? $grindless_options['events_cron_schedule'] : null;
		$cron_schedules = wp_get_schedules();
		$cron_schedule = isset($cron_schedules[$cron_schedule_key]) ? $cron_schedules[$cron_schedule_key] : null;
		$sync_interval = $sync_result['sync_interval'] = isset($cron_schedule) ? $cron_schedule['interval'] : HOUR_IN_SECONDS*24; // fallback to daily just in case
		
		// check how long it has been and if we should check events
		$last_update_time = $nocache === true || isset($_REQUEST['nocache']) ? 0 : get_transient('grnd-events-lastcheck');
		$last_update_time = ($last_update_time === false) ? 0 : intval($last_update_time);
		$sync_result['last_update'] = ($last_update_time === false) ? 'None (false)' : $last_update_time;
		$earliest_sync = $sync_result['earliest_sync'] = ($last_update_time + $sync_interval);
		
		if (time() >= $earliest_sync) {
			// going to check for events in a moment. Update lastcheck time to now
			set_transient('grnd-events-lastcheck', time(), time() + $sync_interval);
		} else {
			// no need to update
			if (self::$debug) echo __METHOD__.'#'.__LINE__. ': Last sync occured at ' . $last_update_time . '. Update is not needed! Returning...<br>';
			$sync_result['message'] = 'Last sync occurred too recently. Earliest upcoming sync: ' . $earliest_sync;
			
			return $sync_result;
		}

		// get list of venues from WP. We will then hit the POS API to get events for each venue.
		$venues = get_posts(
			array(
				'post_type' => 'tribe_venue',
				'post_status' => 'publish',
				'meta_query' => array(
					'key' => 'org_id',
					'compare' => 'EXISTS'
				),
				'numberposts' => -1
			)
		);

		// make sure we have a list of venues
		if (!count($venues)) {
			$sync_result['message'] = 'No venues are configured. Cannot fetch events from POS API.';
			return $sync_result;
		}

		// get list of org_ids from the venues
		$org_ids = array();
		foreach ($venues as $venue) {
			$tmp_org_id = get_post_meta($venue->ID, 'org_id', true);
			if (is_numeric($tmp_org_id)) {
				$org_ids[] = $tmp_org_id;
			}
		}

		if (empty($org_ids)) {
			$sync_result['message'] = 'No Organization IDs gathered from Venues. Ensure Venues are configured and try again.';
			return $sync_result;
		}

		// run through orgs and query API for events
		$pos_events_list = array();
		foreach ($org_ids as $org_id) {
			// we're letting the "grnd-events-lastcheck" override the events cache time param to make sure it is always fresh
			$tmp_events = self::fetch_events($org_id, $cache = false);
			if (!empty($tmp_events)) {
				$pos_events_list[$org_id] = $tmp_events;
			}
		}
		
		if (empty($pos_events_list)) {
			$sync_result['message'] = 'No events returned from POS API';
			return $sync_result;
		}

		// #TO-DO
		// some kind of cutoff goes here...
		// should we only care about some events?

		// get a list of all events in our db (excludes custom events made in UI), keyed by pos_guid => post_id.
		// built once up front (single query) and reused by both the add/edit routine (existence checks) and the
		// delete routine (diffing against what the POS returned), instead of querying once per POS event.
		$existing_events_tmp = array();
		$existing_events = tribe_get_events(array('meta_key' => 'pos_guid'));
		if (is_array($existing_events) && count($existing_events)) {
			foreach ($existing_events as $existing_event) {
				$event_guid = get_post_meta($existing_event->ID, 'pos_guid', true);
				if (!is_string($event_guid) or !strlen($event_guid)) continue;

				$existing_events_tmp[$event_guid] = $existing_event->ID;
			}
		}

		// add / edit routine
		foreach ($pos_events_list as $org_id => $pos_events) {
			foreach ($pos_events as $raw_event) {
				$result = null;

				// format the event to Tribe's standard
				$formatted_event = self::format_event($raw_event);

				if (isset($existing_events_tmp[$raw_event->ID])) {
					$post_id = $existing_events_tmp[$raw_event->ID];

					// TO-DO: compare event to existing to see if we need to update it
					// also should we allow user to override event details in WP? Somehow track that they've manually changed it and avoid updating...

					// update the stored WP event with info from the POS
					if ($dryrun !== true) {
						$result = tribe_update_event($post_id, $formatted_event); // gives back either post_id or false
					} else {
						$result = 9900 + rand(1, 99);
					}

					// if we got a post_id back, then it worked. log it with the others
					if (is_numeric($result)) {
						$sync_result['updated_count']++;
						$sync_result['updated'][] = $result;
					}
				} else {
					// add the new event to the database
					if ($dryrun !== true) {
						$result = tribe_create_event($formatted_event);
					} else {
						$result = 9900 + rand(1, 99);
					}

					if (is_numeric($result)) {
						$sync_result['added_count']++;
						$sync_result['added'][] = $result;
					}
				}
			}
		}

		// begin delete routine

		// format POS events array
		$pos_events_tmp = array();
		if (is_array($pos_events_list) && count($pos_events_list)) {
			foreach ($pos_events_list as $org_id => $pos_events) {
				foreach ($pos_events as $pos_event) {
					$pos_events_tmp[$pos_event->ID] = $pos_event;
				}
			}
		}

		// delete events in our db that no longer exist server side
		if (count($existing_events_tmp)) {
			$pos_only_events = array_diff_key($existing_events_tmp, $pos_events_tmp);

			foreach ($pos_only_events as $event_guid => $post_id) {
				$delete_result = wp_delete_post($post_id, true); // true = skip the trash
				if ($delete_result) {
					$sync_result['deleted'][] = $delete_result->ID;
					$sync_result['deleted_count']++;
				}
			}
		}

		// end delete routine

		
		if ($sync_result['added_count'] > 0 || $sync_result['updated_count'] > 0 || $sync_result['deleted_count'] > 0) {
			$sync_result['did_sync'] = true;
			$sync_result['message'] = sprintf('%d total records processed', $sync_result['added_count'] + $sync_result['updated_count'] + $sync_result['deleted_count']);
		}
		
		if ($dryrun === true) {
			$sync_result['message'] .= ' | dryrun! No records changed.';
		}
		
		return $sync_result;
	}
	
	public static function fetch_events($org_id, $cache = null) {
		$date_cutoff = self::get_date_cutoff();
		
		$events = GrindlessPOS::get_events($org_id, $date_cutoff['date_from'], $date_cutoff['date_to'], $cache);
		
		return $events;
	}
	
	public static function get_date_cutoff() {
		$grindless_options = get_option('grindless_options');

		$days_past = isset($grindless_options['events_days_past']) ? intval($grindless_options['events_days_past']) : 1;
		$days_future = isset($grindless_options['events_days_future']) ? intval($grindless_options['events_days_future']) : 60;

		return array(
			'date_from' => date('m-d-Y', strtotime('-' . $days_past . ' days')),
			'date_to' => date('m-d-Y', strtotime('+' . $days_future . ' days'))
		);
	}
	
	public static function format_event($event, $process_terms = true) {
		// set dates
		$event_date = date_create_from_format('Y-m-d\TH:i:s', $event->start);
		$end = date_create_from_format('Y-m-d\TH:i:s', $event->start);
		
		if ($event->duration > 0) {
			$end->add(new DateInterval('PT' . $event->duration . 'M'));
		}
		
		// map event params
		$args = array(
			'ID' => 0,
			'post_author' => 0,
			'post_content' => $event->Description,
			'post_title' => $event->name . ' [' . $event_date->format('m/d/Y') . ']',
			'post_name' => sanitize_title($event->name . '-' . $event_date->format('m-d-Y')),
			'post_status' => 'publish',
			'EventStartDate' => $event_date->format('Y-m-d'),
			'EventEndDate' => $end->format('Y-m-d'),
			'EventStartHour' => $event_date->format('g'),
			'EventStartMinute' => $event_date->format('i'),
			'EventStartMeridian' => $event_date->format('a'),
			'EventEndHour' => $end->format('g'),
			'EventEndMinute' => $end->format('i'),
			'EventEndMeridian' => $end->format('a'),
		);
		
		$venue = self::get_venue($event->OrgID); // returns a WP_Post
		if ($venue instanceof WP_Post) {
			$args['Venue'] = array('VenueID' => $venue->ID);
		}
		
		$args['EventShowMapLink'] = true;
		
		$meta_input = array();

		if ($event->Limit)
			$meta_input['limit'] = $event->Limit;

		if ($event->Sold)
			$meta_input['sold'] = $event->Sold;

		if ($event->duration)
			$meta_input['duration'] = $event->duration;
		
		if ($event->OrgID)
			$meta_input['org_id'] = $event->OrgID;

		if ($event->ID)
			$meta_input['pos_guid'] = $event->ID;

		if (!empty($event->FaceBookID))
			$args['EventURL'] = 'http://www.facebook.com/events/' . $event->FaceBookID;
		
		if ($meta_input)
			$args['meta_input'] = $meta_input;
		
		
		if ($process_terms && strlen($event->Description) > 0) {
			$category_ids = self::process_event_terms($event->Description, 'tribe_events_cat');
			if (is_array($category_ids) && count($category_ids)) {
				$args['tax_input']['tribe_events_cat'] = $category_ids;
			}
			
			/*
			WIP - tags not working for some reason...?
			
			if (stripos($event->Description, 'tags:')) {
				$tag_ids = self::process_event_terms($event->Description, 'post_tag');
				if (is_array($tag_ids) && count($tag_ids)) {
					$args['tax_input']['post_tag'] = $tag_ids;
				}
			}
			*/
		}
		
		return $args;
		
	}
	
	public static function process_event_terms($description, $taxonomy = 'tribe_events_cat') {
		$term_ids = null;
		
		// this function can be used for categories or terms.
		// we will search the $description for the keyword "category" or "tag" depending on which we're dealing with.
		$keyword = ($taxonomy == 'tribe_events_cat') ? 'category' : 'tag';
		
		// determine where our list of terms is located within the given $description
		$keyword_pos = stripos($description, $keyword . ':');
		
		if ($keyword_pos === false) {
			// the keyword wasn't found. Nothing to do.
			return null;
		}
		
		// get everything from the keyword to the end of the string
		$term_string = substr($description, $keyword_pos);

		// check for line breaks (which means there may be regular description content after the keyword)
		// try to allow content after the keyword, in case user puts it at the beginning
		$line_breaks_regex = "~\R~";
		if (preg_match($line_breaks_regex, $term_string) === 1) {
			// there's a line break. Focus on only the part we care about, disregard the rest.
			$lines = preg_split($line_breaks_regex, $term_string);
			if (is_array($lines) && count($lines)) {
				$term_string = reset($lines);
			}
		}

		// strip the keyword text off of the front string and trim leading/trailing whitespace
		$term_string = trim(str_ireplace($keyword . ':', '', $term_string));
		
		if (strpos($term_string, ',')) {
			// there are multiple terms. put them into an array (trimming any spaces from each)
			$terms = array_map('trim', explode(',', $term_string));
		} else {
			// even if there's just one, put it in an array so we can deal with it the same way later
			$terms = array($term_string);
		}
		
		foreach($terms as $term_name) {
			$result = self::get_or_make_term($term_name, $taxonomy);
			if (is_numeric($result)) {
				$term_ids[] = $result;
			}
		}
		
		return $term_ids;
	}
	
	public static function get_or_make_term($term_name, $taxonomy) {
		$term_id = null;
		
		// check to see if this category already exists in the database
		if ($existing_term = get_term_by('name', $term_name, $taxonomy, 'ARRAY_A')) {
			// the category does exist. Get its ID
			$term_id = $existing_term['term_id'];
		} else {
			// create a new category and return its ID
			$new_term = wp_insert_term(ucwords($term_name), $taxonomy);
			if (!is_wp_error($new_term)) {
				$term_id = $new_term['term_id'];
			}
		}
		
		return $term_id;
	}
	
	public static function get_venue($org_id) {
		$args = array(
			'post_type' => class_exists('Tribe__Events__Main') ? Tribe__Events__Main::VENUE_POST_TYPE : 'tribe_venue',
			'numberposts' => 1,
			'meta_key' => 'org_id',
			'meta_value' => $org_id
		);
		
		$venues = get_posts($args);
		
		if (is_array($venues) && count($venues)) {
			return reset($venues);
		}
		
		return null;
	}
	
	// fallback when no image exists for an event
	// category images should override this typically
	public function default_featured_image( $template_vars ) {
		$attachments = get_posts(array(
			's'				 => 'events-default-image',
			'post_type'      => 'attachment',
			'posts_per_page' => 1,
			'post_status'    => 'any',
			'post_parent'    => null,
			'fields'		 => 'ids'
		));
		
		if (is_array($attachments) && count($attachments)) {
			$url = wp_get_attachment_image_url(reset($attachments));
			if ($url) {
				$template_vars['placeholder_url'] = $url;
			}
		}

		return $template_vars;
	}

	public function insert_event_image($thumbnail_id, $post) {
		// don't interere with admin related stuff, other kinds of post types, or individual events that have images set already
		if (is_admin() || $post->post_type !== 'tribe_events' || $thumbnail_id !== 0) {
			return $thumbnail_id;
		}
		
		// get categories this event is in
		$taxonomy = 'tribe_events_cat';
		$event_terms = wp_get_post_terms($post->ID, $taxonomy, array('hierarchical' => true, 'orderby' => 'parent', 'order' => 'DESC'));

		$image_id = self::get_featured_image_from_categories($event_terms);
		if ($image_id && !is_null($image_id)) {
			return $image_id;
		} else {
			return $thumbnail_id;
		}
	}

	public function get_featured_image_from_categories($event_terms) {
		if (!is_array($event_terms) || count($event_terms) < 1) {
			return null;
		}

		// terms arrive going from highest level parent to lowest child element
		// need to reverse it to prioritize child terms over their parents
		foreach ($event_terms as $term) {
			$featured_image_id = get_term_meta($term->term_id, 'catimage', true);
			if ($featured_image_id && is_numeric($featured_image_id)) {
				return $featured_image_id;
			}
		}

		return null;
	}
	
	public function add_catimage_group_field($taxonomy) {
		wp_enqueue_media();
		?><div class="form-field term-group">
			<label for="catimage-field">Category Image</label>
			
			<input id="upload_image_button" class="catimage-set" type="button" class="button" value="Select Image" />
			<input id="reset_image_button" class="catimage-reset" type="button" class="button" value="Reset Image" disabled />
			<input id="catimage-field" type="hidden" name="catimage" value="">
			<div class="image-preview-wrapper">
				<img id="catimage-url" class="catimage-set" src="" style="width: 400px; height: auto; margin: 1em 0;border-radius: 4px;">
			</div>
		</div><?php
	}
	
	public function save_catimage_meta($term_id, $tt_id) {
		if (isset($_POST['catimage'])) {
			$category_image_id = $_POST['catimage'];
			
			if ($category_image_id === '-1') {
				return;
			}
			
			// sanity check. make sure it exists
			$tmp_url = wp_get_attachment_image_url($category_image_id);
			if ($tmp_url !== false) {
				// add it as meta
				add_term_meta($term_id, 'catimage', $category_image_id, true);
			}
		}
	}
	
	public function update_catimage_meta($term_id, $tt_id) {
		if(isset($_POST['catimage'])) {
			$category_image_id = $_POST['catimage'];
			
			if ($category_image_id === '-1') {
				delete_term_meta($term_id, 'catimage');
				return;
			}
			
			// sanity check. make sure it exists
			$tmp_url = wp_get_attachment_image_url($category_image_id);
			if ($tmp_url !== false) {
				// update meta
				update_term_meta($term_id, 'catimage', $category_image_id);
			}
		}
	}
	
	public function edit_catimage_group_field($term, $taxonomy) {
		wp_enqueue_media();
		?><tr class="form-field term-group-wrap">
			<th scope="row"><label for="catimage-field">Category Image</label></th>
			<td>
				<input id="catimage-field" type="hidden" name="catimage" value="<?php echo $category_image_id; ?>">
				<div class="image-preview-wrapper">
					<?php
					$category_image_id = get_term_meta($term->term_id, 'catimage', true);
					$reset_btn_enabled = false;
					$img_src = '';
					if (!empty($category_image_id)) {
						$url = wp_get_attachment_image_url($category_image_id, 'medium');
						if ($url !== false) {
							$img_src = $url;
							$reset_btn_enabled = true;
						}
					}
					
					$reset_btn_prop = $reset_btn_enabled ? '' : 'disabled="disabled"';
					?>
					<img id="catimage-url" class="catimage-set" src="<?php echo $url; ?>" style="width: 400px; height: auto; margin: 1em 0;border-radius: 4px;">
				</div>
				<input id="upload_image_button" class="catimage-set" type="button" class="button" value="Select Image" />
				<input id="reset_image_button" class="catimage-reset" type="button" class="button" value="Reset Image" <?php echo $reset_btn_prop; ?> />
			</td>
		</tr><?php
	}
	
	public function add_catimage_column($columns) {
		$columns['catimage'] = 'Image';
		return $columns;
	}
	
	public function add_image_column_content($content, $column_name, $term_id) {
		if ($column_name !== 'catimage') {
			return $content;
		}
		
		$term_id = absint($term_id);
		$category_image_id = get_term_meta($term_id, 'catimage', true);
		
		if (!empty($category_image_id)) {
			$url = wp_get_attachment_image_url($category_image_id, 'thumbnail');
			if ($url !== false) {
				$content .= sprintf('<img id="catimage-url" src="%s" style="width: 120px; height: auto;">', $url);
			}
		}
		
		return $content;
	}
	
	public function media_selector_print_scripts() {
		$screen = get_current_screen();
		if (!$screen = 'edit-tribe_events_cat') {
			return;
		}
		
		$term_id = isset($_GET['tag_ID']) ? $_GET['tag_ID'] : 0;
		$category_image_id = isset($term_id) ? get_term_meta($term_id, 'catimage', true) : 0;
		?><script type='text/javascript'>
			jQuery( document ).ready( function( $ ) {
				// Uploading files
				var file_frame;
				var wp_media_post_id = wp.media.model.settings.post.id; // Store the old id
				var set_to_post_id = '<?php echo $category_image_id; ?>'; // Set this

				jQuery('.catimage-set').css('cursor', 'pointer').on('click', function( event ) {

					event.preventDefault();

					// If the media frame already exists, reopen it.
					if ( file_frame ) {
						// Set the post ID to what we want
						if (set_to_post_id) {
							file_frame.uploader.uploader.param( 'post_id', set_to_post_id );
						}
						// Open frame
						file_frame.open();
						return;
					} else {
						// Set the wp.media post id so the uploader grabs the ID we want when initialised
						if (set_to_post_id) {
							wp.media.model.settings.post.id = set_to_post_id;
						}
					}

					// Create the media frame.
					file_frame = wp.media.frames.file_frame = wp.media({
						title: 'Select a image to upload',
						button: {
							text: 'Use this image',
						},
						multiple: false	// Set to true to allow multiple files to be selected
					});

					// When an image is selected, run a callback.
					file_frame.on( 'select', function() {
						// We set multiple to false so only get one image from the uploader
						attachment = file_frame.state().get('selection').first().toJSON();

						// Do something with attachment.id and/or attachment.url here
						$( '#catimage-url' ).attr( 'src', attachment.url ).css( 'width', '400px' );
						$( '#catimage-field' ).val( attachment.id );
						$( '#reset_image_button' ).prop( 'disabled', false );

						// Restore the main post ID
						wp.media.model.settings.post.id = wp_media_post_id;
					});
					
					// Finally, open the modal
					file_frame.open();
				});

				// Restore the main ID when the add media button is pressed
				jQuery( 'a.add_media' ).on( 'click', function() {
					wp.media.model.settings.post.id = wp_media_post_id;
				});
				
				jQuery( '.catimage-reset' ).css('cursor', 'pointer').on( 'click', function() {
					$( '#catimage-url' ).attr( 'src', '' );
					$( '#catimage-field' ).val( '-1' );
					$( this ).prop( 'disabled', true );
				});
			});
		</script><?php
	}
	
	public function grnd_events_cron_func() {
		$events_updated = self::events_sync();
		
		return $events_updated;
	}
	
	public function add_tickets_link() {
		$pos_guid = get_post_meta(get_the_ID(), 'pos_guid', true);
		if (!$pos_guid) {
			if (self::$debug) echo '[DEBUG] Info: POS Event GUID not set for this event (or event was added manually in WP).<br>';
			return;
		}
		
		$org_id = get_post_meta(get_the_ID(), 'org_id', true);
		if (!$org_id) {
			if (self::$debug) echo '[DEBUG] Info: Organization ID not set for this event.<br>';
			return;
		}
		
		$grindless_options = get_option('grindless_options');
		$shop_page_id = isset($grindless_options['shop_page_id']) ? $grindless_options['shop_page_id'] : null;
		if (!isset($shop_page_id)) {
			if (self::$debug) echo '[DEBUG] Info: Shop Page ID not set.<br>';
			return;
		}

		$tickets = GrindlessPOS::get_event_tickets($org_id, $pos_guid, 15 * MINUTE_IN_SECONDS);

		$event_start = tribe_get_start_date(get_the_ID(), false, 'U');
		$event_start_midnight = $event_start - ($event_start % 86400);

		$now = current_time('U'); // adjusted to offset of site's timezone
		$today_midnight = $now - ($now % 86400);

		if (self::$debug) echo '[DEBUG] Current date: ' . date('Y-m-d H:i:s', $now) . '<br>';
		if (self::$debug) echo '[DEBUG] Event date: ' . date('Y-m-d H:i:s', $event_start) . '<br>';
		if (self::$debug) echo '[DEBUG] Midnight this morning was: ' . date('Y-m-d H:i:s', $today_midnight) . '<br>';

		foreach ($tickets as $i => $ticket) {
			if (self::$debug) echo '[DEBUG] ## Processing ' . $ticket->TicketName . ' (' . $ticket->TicketType . ')...<br>';

			if ($ticket->TicketType == 'PRE') {
				if (self::$debug) echo '[DEBUG] [PRESALE TICKET] Latest ticket can be sold: ' . date('Y-m-d H:i:s', $event_start_midnight) . '<br>';
				if ($event_start_midnight <= $today_midnight) {
					// its a pre-sale ticket and its expired. Remove it.
					if (self::$debug) echo  '[DEBUG] [PRESALE TICKET] Ticket cannot be sold! Ticket purchase not allowed on this date.<br>';
					unset($tickets[$i]);
				} else {
					// pre-sale ticket and its not the day of the event yet. Show it.
					if (self::$debug) echo  '[DEBUG] [PRESALE TICKET] Ticket can be sold. Checks passed.<br>';
				}
			} elseif ($ticket->TicketType == 'DAY') {
				// make sure we're only offering DAY-OF tickets the day of the actual Event
				if (self::$debug) echo '[DEBUG] [DAY TICKET] Earliest ticket can be sold: ' . date('Y-m-d H:i:s', $event_start_midnight) . '<br>';
				if (self::$debug) echo '[DEBUG] [DAY TICKET] Latest ticket can be sold: ' . date('Y-m-d H:i:s', $event_start) . '<br>';
				if (($event_start_midnight == $today_midnight) && (!$event_start > $now)) {
					if (self::$debug) echo '[DEBUG] [DAY TICKET] Day of event and event has not passed. Checks passed.<br>';
					// ticket is for today so can still be purchased. Show it.
				} else {
					if (self::$debug) echo '[DEBUG] [DAY TICKET] Ticket cannot be sold!<br>';
					unset($tickets[$i]);
				}
			} else {
				// just make sure date for event hasn't passed for this REG ticket
				if ($event_start < $now) {
					if (self::$debug) echo '[DEBUG] [REG] Ticket cannot be sold! Event has passed.<br>';
					unset($tickets[$i]);
				} else {
					if (self::$debug) echo '[DEBUG] [REG] Ticket can be sold.<br>';
				}
			}
		}

		if (!is_array($tickets) || !count($tickets)) {
			if (self::$debug) {
				echo '[DEBUG] Info: No tickets for this event were returned from API. Dumping API request:<br><pre>';
				global $posapidebug;
				var_dump($posapidebug);
				echo '</pre>';

			}

			return;
		}
		
		$shop_url = get_permalink($shop_page_id);

		echo '<div class="grindless-shop-tickets">';

		echo '<strong class="buy-tickets-title" style="display: block; margin-top: 1em;"><i class="fas fa-ticket-alt"></i> Tickets</strong>';

		echo '<div class="grindless-tickets-availability">';
		// tickets remaining
		$pos_event = GrindlessPOS::get_event($org_id, $pos_guid, 15 * MINUTE_IN_SECONDS);
		$tickets_limit = $pos_event->Limit;
		$tickets_sold = $pos_event->Sold;

		if ($tickets_limit > 0) {
			if ($tickets_sold >= $tickets_limit) {
				echo '<span class="soldout">SOLD OUT!</span>';
			} else {
				$tickets_remaining = $tickets_limit - $tickets_sold;
				$pct_remaining = round($tickets_remaining / $tickets_limit, 2) * 100;

				if ($pct_remaining <= 50) {
					printf('<span class="low-tickets">Only %s Ticket%s Left! Get your ticket today!</span>', $tickets_remaining, ($tickets_remaining > 1) ? 's' : '');
				} else {
					echo '<span class="tickets-available">Tickets Still Available!</span>';
				}
			}
		} else {
			echo '<span class="tickets-available no-limit">Tickets Still Available!</span>';
		}
		echo '</div>';

		echo '<ul class="grindless-tickets-list">';
		foreach ($tickets as $ticket) {
			$ticket_link = add_query_arg(array(
				'o' => $org_id,
				'l' => 'browse',
				's'	=> 'detail',
				'p'	=> $ticket->Guid
			), $shop_url);

			printf('<li class="ticket-type-%s" data-ticket_id="%s"><a class="single-ticket button tribe-events-button" href="%s">%s ($%s)</a></li>',
				sanitize_key($ticket->TicketType),
				$ticket->Guid,
				$ticket_link,
				$ticket->TicketName,
				number_format($ticket->TicketPrice, 2, '.', ',')
			);
		}
		echo '</ul>';

		echo '</div>';
	}
	
	public function settings_page_init() {
		add_settings_section(
			'grindless_events_section', // id
			'Events Calendar Settings', // title
			array( $this, 'settings_info_html' ), // callback
			'grindless-settings-admin' // page
		);

		add_settings_field(
			'events_cron_schedule', // id
			'Sync Interval', // title
			array( $this, 'settings_cron_callback' ), // callback
			'grindless-settings-admin', // page
			'grindless_events_section' // section
		);

		add_settings_field(
			'events_days_past', // id
			'Sync Range - Days in the Past', // title
			array( $this, 'settings_days_past_callback' ), // callback
			'grindless-settings-admin', // page
			'grindless_events_section' // section
		);

		add_settings_field(
			'events_days_future', // id
			'Sync Range - Days in the Future', // title
			array( $this, 'settings_days_future_callback' ), // callback
			'grindless-settings-admin', // page
			'grindless_events_section' // section
		);
	}
	
	public function settings_info_html() {
		echo '<p>Settings specific to events functionality, including events syncronization and related settings. This relies on the WordPress plugin <a href="https://wordpress.org/plugins/the-events-calendar">The Events Calendar</a> for events management.</p>';
	}
	
	public function settings_cron_callback() {
		$cron_schedules = wp_get_schedules();
		$grindless_options = get_option('grindless_options');
		$current = isset($grindless_options['events_cron_schedule']) ? esc_attr($grindless_options['events_cron_schedule']) : 'hourly';
		echo '<select name="grindless_options[events_cron_schedule]" id="events_cron_schedule" autocomplete="off">';
		foreach ($cron_schedules as $key => $value)
			printf('<option value="%s" %s>%s</option>', $key, selected($current, $key, false), $value['display']);
		echo '</select>';
		
		if ( $scheduled = wp_next_scheduled( 'grnd_events_cron_action' ) ) {
			printf('<p class="description">Next sync is set to occur in %d second(s).</p>', $scheduled - time());
		}
		
		print('<p class="description">Enter the amount of time that should pass before checking the POS for new events and running the sync routine. This relies on WordPress\'s <a href="https://developer.wordpress.org/plugins/cron/">WP Cron</a> feature.</p>');
		printf('<p class="description">This feature requires <a href="%s">Venues</a> to be configured. Each Venue must have the correlating Organization ID set as meta data. The key for the meta data should be "org_id" and the value should be the actual Organization ID from the POS.</p>', admin_url('edit.php?post_type=tribe_venue'));
		
		$link = add_query_arg(array('action' => 'gevents_sync', 'nocache' => 'true'), admin_url('admin-ajax.php'));
		printf('<p><a class="button" href="%s" target="_blank">Sync Events Now</a></p>', $link);
	}

	public function settings_days_past_callback() {
		$grindless_options = get_option('grindless_options');
		$current = isset($grindless_options['events_days_past']) ? intval($grindless_options['events_days_past']) : 1;
		printf('<input type="number" min="0" max="365" step="1" class="small-text" name="grindless_options[events_days_past]" id="events_days_past" value="%s" autocomplete="off">', esc_attr($current));
		print('<p class="description">The number of days prior to today that should be included when querying the POS for events. Default is 1.</p>');
	}

	public function settings_days_future_callback() {
		$grindless_options = get_option('grindless_options');
		$current = isset($grindless_options['events_days_future']) ? intval($grindless_options['events_days_future']) : 60;
		printf('<input type="number" min="1" max="365" step="1" class="small-text" name="grindless_options[events_days_future]" id="events_days_future" value="%s" autocomplete="off">', esc_attr($current));
		print('<p class="description">The number of days beyond today that should be included when querying the POS for events. Default is 60.</p>');
	}
}