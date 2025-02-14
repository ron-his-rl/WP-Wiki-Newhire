// Example Endpoint
// https://wiki.rlgrandd.com/wp-json/wcra/v1/wcra_user_apps_uipath/?secret_key=rClWBtfHGvXTg1fAsAFhe1vOz0dVRvij&employee_name=Test User&email=test.user@rlglobal.com&conversation_id=cnv_1234

//FUNCTION FOR ENDPOINT CREATION
add_action('rest_api_init', function() {

	register_rest_route('wcra/v1', 'wcra_user_apps_uipath', [
		'methods'   => 'POST',
		'callback'  => 'wcra_user_apps_uipath_callback_handler'
	]);
});

// FUNCTION FOR USERNAME CREATION | to generate username with conflict resolution included
// function generate_unique_username($first_name, $last_name) {

// 	$max_depth = 3; // Maximum number of letters to include from the first name
//     $username_base = strtolower($last_name); // Start with the last name
//     $username = '';

//     for ($i = 1; $i <= $max_depth; $i++) {
//         $username = strtolower(substr($first_name, 0, $i) . $username_base);

//         // Check if the username is unique
//         if (!username_exists($username)) {
//             return $username;
//         }
//     }

//     // If all combinations are taken, append a random number as a fallback
//     return $username . rand(1, 999);
// }

// FUNCTION - GET REQUEST HEADERS FOR API REQUEST
function getRequestHeaders() {
    $headers = array();
    foreach($_SERVER as $key => $value) {
        if (substr($key, 0, 5) <> 'HTTP_') {
            continue;
        }
        $header = str_replace(' ', '-', ucwords(str_replace('_', ' ', strtolower(substr($key, 5)))));
        $headers[$header] = $value;
    }
    return $headers;
}

// FUNCTION - MAIN FUNCTION FOR ENDPOINT RESPONSE
function wcra_user_apps_uipath_callback_handler(WP_REST_Request $param) {
	$headers = getRequestHeaders();
	$errors = [];

	// Verify secret key
	$secret_key = $headers['Authorization'];
	$stored_secret_key = 'rClWBtfHGvXTg1fAsAFhe1vOz0dVRvij'; // Replace with secure storage method

	if ($secret_key !== $stored_secret_key) {
		return new WP_Error('unauthorized', __('Your credentials are invalid.'), ['status' => 401]);
		$secret_error = 'Unauthorized: line 57 - Your credentials are invalid. | 401 ';
		$errors[] = $secret_error;
	}

	// Get employee name & email from URL
	$employee_name = sanitize_text_field($param['employee_name']);
	$email = sanitize_text_field($param['email']);
	$front_cnv_id = sanitize_text_field($param['conversation_id']);
	global $wpdb;

	// Query Fluent Form Submissions
	// 	$results = $wpdb->get_results($wpdb->prepare(
	// 		"SELECT * FROM {$wpdb->prefix}fluentform_submissions WHERE form_id = %d",
	// 		27
	// 	));

	$form_ids = [27, 71];
	$placeholders = implode(',', array_fill(0, count($form_ids), '%d'));

	$results = $wpdb->get_results($wpdb->prepare(
		"SELECT * FROM {$wpdb->prefix}fluentform_submissions WHERE form_id IN ($placeholders)",
		...$form_ids
	));

	if (!$results) {
		return new WP_Error('no_entries', __('No entries found for the specified form ID.'), ['status' => 404]);
	}

	// Search for the matching employee name
	$matched_entry = null;
	foreach ($results as $result) {
		$form_data = json_decode($result->response, true);

		if (json_last_error() !== JSON_ERROR_NONE) {
			error_log("JSON decode error: " . json_last_error_msg());
			continue; // Skip if JSON decoding fails
		}

		// Check if the employee is a rehire
		$is_rehire = !empty($form_data['rehire_name']); // True if 'rehire_name' is set and not empty

		// Determine which field to use as the employee name
		$current_employee_name = $is_rehire ? $form_data['rehire_name'] : $form_data['employee_name'];

		// Match the employee name
		if (strcasecmp(trim($current_employee_name), trim($employee_name)) === 0) {
			$matched_entry = $form_data;
			$matched_form_id = $result->form_id; // Save the form ID of the matched entry
			break;
		}
	}

	if (!$matched_entry) {
		return new WP_Error('not_found', __('No entries found for the given employee name.'), ['status' => 404]);
	}

	// Extract other relevant fields
	$branch = $matched_entry['station_location'] ?? '';
	$job_title = $matched_entry['job_title'] ?? '';
	$employee_manager = $matched_entry['employee_manager'] ?? '';
	$si_access = $matched_entry['si_access'] ?? '';
	$new_hire_type = $matched_entry['new_hire_type'] ?? '';

	// Query the custom table to get the user_id for employee_manager
	$manager_user_id = $wpdb->get_var($wpdb->prepare(
		"SELECT ID FROM wp66_wiki_user_fields WHERE formal_name = %s",
		$employee_manager
	));

	if ($manager_user_id) {
		// Get the user object by ID
		$manager_user = get_user_by('ID', $manager_user_id);

		if ($manager_user) {
			$manager_email = $manager_user->user_email;
		} else {
			// Handle the case where the user is not found
			$manager_email = '';
		}
	} else {
		// Handle the case where the manager's name is not found in the custom table
		$manager_email = '';
	}

	// Extract name fields
	// 	$employee_full_name = explode(' ', $employee_name);
	// 	$first_name = $employee_full_name[0] ?? '';
	// 	$last_name = $employee_full_name[1] ?? '';


	// Extract name fields
	$employee_full_name = explode(' ', $employee_name);

	// Ensure all names are trimmed
	$employee_full_name = array_map('trim', $employee_full_name);

	// Assign names
	$first_name = implode(' ', array_slice($employee_full_name, 0, -1)); // All but the last part
	$last_name = $employee_full_name[array_key_last($employee_full_name)]; // Last part

	// Combine first and last name to create the username
	//$username = strtolower($first_name . '.' . $last_name);

	// Split the email at '@' and get the first part
	$username = explode('@', $email)[0];

	// Check if user exists
	if (username_exists($username) || email_exists($email)) {
		return new WP_Error('user_exists', __('A user with this username or email already exists.'), ['status' => 409]);
	}

	// Generate email
	// $email = strtolower("{$first_name}.{$last_name}@rlglobal.com"); // Hardcoded for testing purposes | Needs to be passed from UI Path possibly?

	// Check email existence
	if (email_exists($email)) {
		return new WP_Error('email_exists', __('A user with this email already exists.'), ['status' => 409]);
	}

	// Generate random password
	$password = wp_generate_password();

	// Manager conditon if user selects wrong New Hire Type
	$is_manager = $matched_entry['is_manager'] ?? '';

	// Determine the user role based on $is_manager
	if ( $is_manager === 'no' ) {
		$role = ($new_hire_type == 'New Hire - AGM / GM / RGM') ? 'editor' : 'subscriber';
	} else {
		$role = 'editor';
		$manager_ccap = array('manager', 'allsop');
	}

	// Create user
	$user_id = wp_insert_user([
		'user_login' => $username,
		'user_email' => $email,
		'first_name' => $first_name,
		'last_name'  => $last_name,
		'user_pass'  => $password,
		'role'       => $role
	]);

	if (is_wp_error($user_id)) {
		return new WP_Error('user_creation_failed', __('Failed to create the user.'), ['status' => 500]);
	}

	//****************************************************************************** WIKI USER FIELDS TABLE ******************************************************************************//

	global $wpdb;
	$table = 'wp66_wiki_user_fields';

	// Is this ID already in the wiki user table?
	$check_id = $wpdb->get_var("SELECT ID FROM wp66_wiki_user_fields WHERE ID = $user_id");
	if($check_id == NULL) {    // If no, insert it
		// Insert this ID

		//!!!************ NEEDS UPDATING
		$new_user_title = $job_title;

		//set their formal name as to not have it on the form
		$formal_name = "$first_name $last_name";
		$user_email = $email;
		//$si_access = 'Yes'; // NEED UPDATE: TO GET FROM NH FORM SELECTION

		//lets get the job title id from table: wp66_term_taxonomy
		$job_title_id = $wpdb->get_var( "SELECT term_id FROM wp66_term_taxonomy WHERE description = '$new_user_title' AND taxonomy = 'job-title'" );

		//lets get the ccaps and bunits from the wp66_job_title_fields using the job_title_id
		$bunit_obj = $wpdb->get_results( "SELECT ccap_list, bunit_list FROM wp66_job_title_fields WHERE ID = $job_title_id" );
		$job_bunit = $bunit_obj[0]->bunit_list;

		// Common ccaps Array for Incentive User table & to auto-add ccaps
		$common_assignments = array(
			"1157","313","321","22","1161","327","33","1302","115","48","336","51","52","61","69","70","72"
		);

		$ccap_obj = $wpdb->get_results( "SELECT ccap_list FROM wp66_job_title_fields WHERE ID = $job_title_id" );
		$job_ccap = $ccap_obj[0]->ccap_list;

		// Get the slugs for each ccap_id to work with incentive update
		$ccap_array = explode(",", $job_ccap);

		// Filter out common IDs for Incentive User table. Jennifer Wright manually updates sales ccaps so we don't need those.
		$common_ids = array_intersect($ccap_array, $common_assignments);

		$ccap_slugs = array(); // Array to store slugs

		foreach ($common_ids as $ccap_id) {
			// Fetching slug for each ccap_id
			$ccap_slug = $wpdb->get_var("SELECT slug FROM wp66_terms WHERE term_id = $ccap_id");
			if ($ccap_slug !== null) {
				// Adding slug to the array
				$ccap_slugs[] = $ccap_slug;
			}
		}

		// Implode the array to get a comma-separated string to insert into Wiki User table
		$sales_ccap_string = implode(",", $ccap_slugs);

		//now we insert the data into the wiki user fields table
		$insert_result = $wpdb->insert(
			'wp66_wiki_user_fields',
			array(
				'ID'                  => $user_id,
				'formal_name'         => $formal_name,
				'user_branch'         => $branch,
				'user_role'           => 'subscriber', // Let the Manager choose on the New Hire Form?
				'user_title'          => $new_user_title,
				'user_direct_manager' => $manager_email,
				'ccap_id'             => $sales_ccap_string,
				'business_unit_id'    => $job_bunit,
				'sales_plan_access'   => $si_access
			)
		);

		// Check if the insert failed
		if (!$insert_result) {
			return new WP_Error(
				'database_insert_failed',
				__('Failed to insert data into the Wiki User Fields table.'),
				['db_error' => $wpdb->last_error]
			);
		}

		// ******* RCP DATA TO SET GUA MEMBERSHIP LEVEL ******* //

		// Get the customer record associated with this user ID
		//$branch = $formData['branch'];
		$customer = rcp_get_customer_by_user_id($user_id);

		if ($customer != null && $branch === 'Guatemala City') {
			// Once you have the customer object, you can get the customer ID
			$customer_id = $customer->get_id();

			// Add the user to a specific membership level
			$membership_id = rcp_add_membership(array(
				'customer_id' => $customer_id,
				'object_id'   => 6, // Replace with the desired membership level ID
				'status'      => 'active'
			));

			if (is_wp_error($membership_id)) {
				// Handle the error, log it, or return an appropriate response
				return new WP_Error('Membership Error', $membership_id->get_error_message(), array('status' => 500));
			}
		}

		// find the direct manager by email address
		$mgr_obj = get_user_by( 'email', $manager_email);  //!!!**** NEEDS UPDATING TO GET MANAGER EMAIL?
		$mgr_user_id = $mgr_obj->ID;
		$mgr_subs_list = $wpdb->get_var( "SELECT user_subordinates FROM wp66_wiki_user_fields WHERE ID = $mgr_user_id" );

		//add to the manager's existing subordinates list
		if( !empty($mgr_subs_list) ){
			$updated_mgr_subs_list = "$mgr_subs_list,$user_id";

			$data = array( "user_subordinates" => $updated_mgr_subs_list );
			$where = array( "ID" => $mgr_user_id );

			$sub_update = $wpdb->update( $table, $data, $where );

			// Check if the update failed
			if ($sub_update === false) {
				return new WP_Error(
					'database_update_failed',
					__('Failed to update the managers subordinates.'),
					['db_error' => $wpdb->last_error]
				);
			}
		} else{
			//add the manager's first subordinate
			$updated_mgr_subs_list = "$user_id";

			$data = array( "user_subordinates" => $updated_mgr_subs_list );
			$where = array( "ID" => $mgr_user_id );

			$first_sub = $wpdb->update( $table, $data, $where );

			// Check if the update failed
			if ($first_sub === false) {
				return new WP_Error(
					'database_update_failed',
					__('Failed to update the managers subordinates.'),
					['db_error' => $wpdb->last_error]
				);
			}
		}

		//lets create a page for the employee so they can be edited on the front end on the Admin Dashboard
		$my_post = array(
			'post_title'    => $user_id,
			'post_type'     => 'rlg-employee',
			'post_status'   => 'publish'
		);

		// Insert the post into the database
		$post_id = wp_insert_post($my_post);

		// Check if the post insertion failed
		if (is_wp_error($post_id)) {
			return new WP_Error(
				'post_creation_failed',
				__('Failed to create the RLG employee page.'),
				['error_details' => $post_id->get_error_message()]
			);
		}

	}

	//****************************************************************************** END OF WIKI USER FIELDS TABLE ******************************************************************************//

	//****************************************************************************** INCENTIVE USER TABLE ******************************************************************************//

	// Serialize the array of ccaps to automatically give the Incentive User ONLY common ccaps based on their job.
	$serialized_ccap_array = serialize($ccap_slugs);

	if ( $is_manager === 'yes' ) {
		$serialized_ccap_array = serialize($manager_ccap);
	}

	$incentive_user_table = 'wp66_incentive_user';

	//*** PUBLISH INCENTIVE USER POST ***//

	$incentive_post_args = array(
		'post_title'    => $formal_name,
		'post_type'     => 'incentive_user',
		'post_status'   => 'publish'
	);

	$incentive_user_post = wp_insert_post( $incentive_post_args );

	// Check if the post insertion failed
	if (is_wp_error($incentive_user_post)) {
		return new WP_Error(
			'post_creation_failed',
			__('Failed to create the Incentive User Post.'),
			['error_details' => $incentive_user_post->get_error_message()]
		);
	}

	$user_incentive_data = array(
		'ID'  => $user_id,
		'incentive_user_id' => $incentive_user_post
	);
	global $wpdb;	
	$wiki_user_update = $wpdb->update('wp66_wiki_user_fields', $user_incentive_data, array('id'=>$user_id));

	// Check if the update failed
	if ($wiki_user_update === false) {
		return new WP_Error(
			'database_update_failed',
			__('Failed to update the incentive data on Wiki User Fields Table.'),
			['db_error' => $wpdb->last_error]
		);
	}

	// *** SET TAXONOMIES FOR INCENTIVE USER POST *** //

	$status = 'active';

	$formal_name_taxonomy = 'user_name';
	wp_set_object_terms( $incentive_user_post, $formal_name, $formal_name_taxonomy );

	$job_title_taxonomy = 'user_title';
	wp_set_object_terms( $incentive_user_post, $new_user_title, $job_title_taxonomy );

	$account_status_taxonomy = 'account_status';
	wp_set_object_terms( $incentive_user_post, $status, $account_status_taxonomy );

	$plan_access_taxonomy = 'sales_plan_access';
	wp_set_object_terms( $incentive_user_post, $si_access, $plan_access_taxonomy );

	//*** UPDATE THE INCENTIVE USER TABLE ***//

	$data = array(
		"ID"					=> $incentive_user_post,
		"user_id"           	=> $user_id,
		"user_name"      		=> $formal_name,
		"user_title"			=> $new_user_title,
		"sales_plan_access" 	=> $si_access,
		"common_ccaps"			=> $serialized_ccap_array,
		"account_status"		=> $status
	);

	$insert_result = $wpdb->insert($incentive_user_table, $data);

	// Check if the insert failed
	if (!$insert_result) {
		return new WP_Error(
			'database_insert_failed',
			__('Failed to update the incentive user table.'),
			['db_error' => $wpdb->last_error]
		);
	}

	// Send email to Jennifer Wright
	if($si_access == 'Yes') {

		//$to = array('jennifer.wright@rlglobal.com', 'jay.connelly@rlglobal.com'); // 
		//$to = 'brenda.durham@rlglobal.com'; // Backup email to Lead Developer
		$to   = 'jared.quidley@rlglobal.com'; // Backup email to Jared Q
		$subject = 'New RLG Wiki User: Sales Impact Access Needed';
		$site_url = get_site_url();
		$post_slug = get_post_field('post_name', $incentive_user_post);
		$myviewpost = $site_url . '/incentive_user/' . $post_slug;
		$message = 'Sales Impact Access Needed for "' . $formal_name . '"';
		$message .= '<br><br>Job Title: "' . $new_user_title . '"';
		$message .= '<br><br>Update their Incentive Plan Access Here: ' . $myviewpost;
		$headers = array(
			'Content-Type: text/html; charset=UTF-8',
		);

		add_filter('wp_mail_content_type', create_function('', 'return "text/html"; '));
		wp_mail($to, $subject, $message, $headers);	

	} 

	//****************************************************************************** END OF INCENTIVE USER TABLE ******************************************************************************//


	// *************************************************************************** USER APPS TABLE | ADD user_id to 'wp66_user_apps' table *************************************************************************** //

	// Contains the selected employee name from New Hire Form Entry
	$selected_value = $employee_name;

	// Extract employee name from the selected value
	$parts = explode('(', $selected_value);

	// Take the first part as the employee name
	$employee_name = trim($parts[0]); // Trim to remove any leading/trailing whitespace

	$table_apps = $wpdb->prefix . 'user_apps';

	// Check if the user_id already exists in the user_apps table
	$user_exists = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table_apps WHERE user_id = %d", $user_id));

	if (!$user_exists && $selected_value != 'Blank') {
		// Update the user_id column for the given employee name
		$user_id_update = $wpdb->update(
			$table_apps,
			array('user_id' => $user_id),
			array('employee_name' => $employee_name),
			array('%d'), // Format for user_id
			array('%s')  // Format for employee_name
		);

		// Check if the update failed
		if ($user_id_update === false) {
			return new WP_Error(
				'database_update_failed',
				__('Failed to update the user_id on User Apps Table.'),
				['db_error' => $wpdb->last_error]
			);
		}
	}

	//**************************************************************************** END OF USER APPS TABLE ***************************************************************************//	

	// Initialize arrays for active applications and mirror applications
	//$active_applications = [];
	//$mirror_applications = [];

	// List of application fields to check
	$applications = [
		'7l_freight',
		'adobe_acrobat',
		'brainshark',
		'calabrio',
		'cargosphere',
		'cargowise',
		'branch_and_department_cargowise',
		'crm_pipedrive',
		'dat_360',
		'deposco',
		'branch_cargowise',
		'dms',
		'docusign',
		'ebol',
		'front',
		'highway',
		'i_global_user_login',
		'insurance_rate_calculator',
		'issue_tracker',
		'logixboard',
		//'mcleod_imaging_setup',
		'mcleod_sec_access',
		'add_sec_group_access',
		'mercury_gate',
		'mpact',
		'oracle',
		'power_bi',
		'shipamax',
		'teamwork_desk',
		'teamwork_proj_standard',
		'veroot',
		'webex_meetings',
		'webex_teams',
		'the_wiki',
		'zoom',
		'zoom_info',
		'other_applications_not_listed'
	];

	// Filter active applications
	foreach ($applications as $app) {
		if (isset($matched_entry[$app]) && $matched_entry[$app] === 'yes') {
			$active_applications[] = $app;
		}
	}

	// Application Mirrors
	$application_mirrors = [
		'mirror_calabrio' => $matched_entry['mirror_calabrio'] ?? '',
		'mirror_cargowise' => $matched_entry['mirror_cargowise'] ?? '',
		'mirror_deposco' => $matched_entry['mirror_deposco'] ?? '',
		'mirror_front' => $matched_entry['mirror_front'] ?? '',
		'mirror_i_global_user_login_id' => $matched_entry['mirror_i_global_user_login_id'] ?? '',
		//'mirror_mcleod' => $matched_entry['mirror_mcleod'] ?? '',
		'mirror_mercury_gate' => $matched_entry['mirror_mercury_gate'] ?? '',
		'mirror_power_bi' => $matched_entry['mirror_power_bi'] ?? '',
		'mirror_teamwork_desk' => $matched_entry['mirror_teamwork_desk'] ?? '',
		'mirror_teamwork_projects_s' => $matched_entry['mirror_teamwork_projects_s'] ?? '',
		'mirror_webex_meetings' => $matched_entry['mirror_webex_meetings'] ?? '',
		'mirror_webex_teams' => $matched_entry['mirror_webex_teams'] ?? '',
		'mirror_the_wiki' => $matched_entry['mirror_the_wiki'] ?? '',
	];

	// Check and process McLeod-specific fields
	// 	$mcleod_apps = [];
	// 	if (!empty($matched_entry['mcleod'])) {
	// 		$mcleod_apps[] = 'mcleod';
	// 	}
	// 	if (!empty($matched_entry['Mcleod-ABCO'])) {
	// 		$mcleod_apps[] = 'Mcleod-PFS';
	// 	}
	// 	if (!empty($matched_entry['Mcleod-ABCO'])) {
	// 		$mcleod_apps[] = 'Mcleod-PFS';
	// 	}
	// 	if (!empty($matched_entry['Mcleod-PTLS'])) {
	// 		$mcleod_apps[] = 'Mcleod-PTLS';
	// 	}


	// Filter other active applications
	// 	$applications = [
	// 		'7l_freight', 'adobe_acrobat', 'brainshark', 'calabrio', 'mirror_calabrio', 'cargosphere', 'cargowise',
	// 		'mirror_cargowise', 'branch_and_department_cargowise', 'crm_pipedrive', 'dat_360', 'deposco', 'mirror_deposco',
	// 		'branch_cargowise', 'dms', 'docusign', 'ebol', 'front', 'mirror_front', 'highway', 'i_global_user_login',
	// 		'mirror_i_global_user_login_id', 'insurance_rate_calculator', 'issue_tracker', 'logixboard', 'mcleod', 'mcleod_imaging_setup',
	// 		'mirror_mcleod', 'mcleod_sec_access', 'add_sec_group_access', 'mercury_gate', 'mirror_mercury_gate', 'mpact', 'oracle',
	// 		'power_bi', 'mirror_power_bi', 'shipamax', 'teamwork_desk', 'mirror_teamwork_desk', 'teamwork_proj_standard',
	// 		'mirror_teamwork_projects_s', 'veroot', 'webex_meetings', 'mirror_webex_meetings', 'webex_teams', 'mirror_webex_teams',
	// 		'the_wiki', 'mirror_the_wiki', 'zoom', 'zoom_info', 'other_applications_not_listed'
	// 	];

	// 	// Include McLeod-specific fields in applications
	// 	//$applications = array_merge($applications, $mcleod_apps);

	// 	$active_applications = array_filter($applications, function ($app) use ($matched_entry) {
	// 		if (str_starts_with($app, 'mirror_')) {
	// 			// Include the value of the mirror fields directly if it exists
	// 			return isset($matched_entry[$app]) && !empty($matched_entry[$app]);
	// 		} else {
	// 			// Check for 'yes' for other applications
	// 			return isset($matched_entry[$app]) && $matched_entry[$app] === 'yes';
	// 		}
	// 	});

	// 	// Map and merge McLeod options
	// 	$formatted_active_applications = array_map(function ($app) use ($matched_entry) {
	// 		return str_starts_with($app, 'mirror_') ? $matched_entry[$app] : $app;
	// 	}, $active_applications);

	// 	// Add active applications to the response
	// 	$formatted_response['active_applications'] = array_values($formatted_active_applications);

	// Add McLeod apps to the response
	//$formatted_response['mcleod_apps'] = $mcleod_apps;

	// Complete the formatted response
	$formatted_response = [
		'username' => $username,
		'new_hire_type' => $matched_entry['new_hire_type'] ?? '',
		'start_date' => $matched_entry['start_date'] ?? '',
		'email' => $email,
		'password' => $password,
		'station_location' => $branch,
		'job_title' => $job_title,
		'employee_manager' => $manager_email,
		'active_applications' => $active_applications,
		//'active_applications' => $formatted_response['active_applications'],
		//'mcleod_apps' => $mcleod_apps, // McLeod not needed per Elyssa & Hari
		//'mcleod_apps' => $matched_entry['mcleod'] ?? 'N/A',
		'other_applications_not_listed' => $matched_entry['other_applications_not_listed'] ?? '',
		'application_mirrors' => $application_mirrors,
	];

	error_log(json_encode($formatted_response, JSON_PRETTY_PRINT));

	return new WP_REST_Response(
		[
			'message' => 'Success', 
			'data'    => $formatted_response 
		],
		200,                               
		['Content-Type' => 'application/json']
	);

	//*** FRONT API ***//

	$api_token = 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJzY29wZXMiOlsic2NpbSIsInByb3Zpc2lvbmluZyIsInByaXZhdGU6KiIsInNoYXJlZDoqIiwia2IiLCJ0aW06MTEyMzc0MDQiLCJ0aW06MTExMDA1MDgiLCJ0aW06MTEyMzQ3MTYiXSwiaWF0IjoxNzM2OTc0MzA4LCJpc3MiOiJmcm9udCIsInN1YiI6IjVjMDdkMjcyMmUwZmEzYmM2MGJjIiwianRpIjoiZGIyZGJiMTYzYTQyMTdjYSJ9.Yjx6m9LERe1UWssOyBGhDsTnenNegAJbTPW47shDJQM';

	// Post comment in Front Ticket with Success Message
	$api_url_comment = "https://api2.frontapp.com/conversations/" . $front_cnv_id . "/comments";

	$body = json_encode([
		//'subject' => $subject,
		'body' => 'Wiki User Successfully Created',
		//'to' => [$user_email], // Array of emails, even if just one
		'options' => [
			'archive' => false
		]
	]);

	$headers = [
		'authorization' => 'Bearer ' . $api_token,
		'content-type' => 'application/json',
		'accept' => 'application/json',
	];

	$response = wp_remote_post($api_url_comment, [
		'body' => $body,
		'headers' => $headers,
	]);

	if (is_wp_error($response)) {
		$error_message = $response->get_error_message();
		error_log($error_message);
	} else {
		$response_body = wp_remote_retrieve_body($response);
		error_log('Response: ' . $response_body);
	}


	// Send message reply to user and post reply in Front Ticket
	$api_url_messages = "https://api2.frontapp.com/conversations/" . $front_cnv_id . "/messages";

	// Send user their Wiki login credentials
	$to_user   = 'jared.quidley@rlglobal.com'; // Update to AD User's email for production
	//$to_user = 'rlgfrontstg@rlglobal.com'; // Front staging for testing
	$subject = 'Wiki User Credentials';
	$site_url = get_site_url(); // Get the site's URL dynamically

	// Construct the email message
	$message = 'Hi ' . $formal_name . '!';
	$message .= '<br><br>Welcome to the Wiki! Our Wiki is an internal website where you can find lots of information about our company, training materials, HR documents, etc. Below you’ll find your login and password credentials. After you login, you should change your password to something more secure. If you have any trouble logging in you can always send a request to wikisupport@rlglobal.com.';
	$message .= '<br><br>Link to the Wiki: <a href="' . esc_url($site_url) . '">' . esc_url($site_url) . '</a>';
	$message .= '<br><br><strong>Your Login Credentials:</strong>';
	$message .= '<br>Username: ' . $username;
	$message .= '<br>PW: ' . $password;

	$body_message = json_encode([
		//'subject' => $subject,
		'body' => $message,
		'to' => [$to_user], // Array of emails, even if just one
		'options' => [
			'archive' => false
		]
	]);

	$response = wp_remote_post($api_url_messages, [
		'body' => $body_message,
		'headers' => $headers,
	]);

	if (is_wp_error($response)) {
		$error_message = $response->get_error_message();
		error_log($error_message);
	} else {
		$response_body = wp_remote_retrieve_body($response);
		error_log('Response: ' . $response_body);
	}

	// Set email headers to send as HTML
	$email_headers = array('Content-Type: text/html; charset=UTF-8');

	// Send the Wiki Welcome Email only if fluent form ID is regular New Hire FF-74 - Not Contract Employee
	if ($matched_form_id == 74) {
		wp_mail($to_user, $subject, $message, $email_headers);
		error_log("Email sent to $to_user for branch: $branch and form ID: $matched_form_id");
	} else {
		error_log("Email not sent. Branch: $branch, Form ID: $matched_form_id");
	}

}
