<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Paypal enrolment plugin.
 *
 * This plugin allows you to set up paid courses.
 *
 * @package    enrol_stripe
 * @copyright  2015 Jat Macalalad
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Stripe enrolment plugin implementation. 
 */
class enrol_stripe_plugin extends enrol_plugin {

    public function get_currencies() {
        // See https://www.stripe.com/cgi-bin/webscr?cmd=p/sell/mc/mc_intro-outside,
        // 3-character ISO-4217: https://cms.stripe.com/us/cgi-bin/?cmd=_render-content&content_ID=developer/e_howto_api_currency_codes
        $codes = array(
            'AUD', 'BRL', 'CAD', 'CHF', 'CZK', 'DKK', 'EUR', 'GBP', 'HKD', 'HUF', 'ILS', 'JPY',
            'MXN', 'MYR', 'NOK', 'NZD', 'PHP', 'PLN', 'RUB', 'SEK', 'SGD', 'THB', 'TRY', 'TWD', 'USD');
        $currencies = array();
        foreach ($codes as $c) {
            $currencies[$c] = new lang_string($c, 'core_currencies');
        }

        return $currencies;
    }

    /**
     * Returns optional enrolment information icons.
     *
     * This is used in course list for quick overview of enrolment options.
     *
     * We are not using single instance parameter because sometimes
     * we might want to prevent icon repetition when multiple instances
     * of one type exist. One instance may also produce several icons.
     *
     * @param array $instances all enrol instances of this type in one course
     * @return array of pix_icon
     */
    public function get_info_icons(array $instances) {
        return array(new pix_icon('icon', get_string('pluginname', 'enrol_stripe'), 'enrol_stripe'));
    }

    public function roles_protected() {
        // users with role assign cap may tweak the roles later
        return false;
    }

    public function allow_unenrol(stdClass $instance) {
        // users with unenrol cap may unenrol other users manually - requires enrol/stripe:unenrol
        return true;
    }

    public function allow_manage(stdClass $instance) {
        // users with manage cap may tweak period and status - requires enrol/stripe:manage
        return true;
    }

    public function show_enrolme_link(stdClass $instance) {
        return ($instance->status == ENROL_INSTANCE_ENABLED);
    }

    /**
     * Sets up navigation entries.
     *
     * @param object $instance
     * @return void
     */
    public function add_course_navigation($instancesnode, stdClass $instance) {
        if ($instance->enrol !== 'stripe') {
             throw new coding_exception('Invalid enrol instance type!');
        }

        $context = context_course::instance($instance->courseid);
        if (has_capability('enrol/stripe:config', $context)) {
            $managelink = new moodle_url('/enrol/stripe/edit.php', array('courseid'=>$instance->courseid, 'id'=>$instance->id));
            $instancesnode->add($this->get_instance_name($instance), $managelink, navigation_node::TYPE_SETTING);
        }
    }

    /**
     * Returns edit icons for the page with list of instances
     * @param stdClass $instance
     * @return array
     */
    public function get_action_icons(stdClass $instance) {
        global $OUTPUT;

        if ($instance->enrol !== 'stripe') {
            throw new coding_exception('invalid enrol instance!');
        }
        $context = context_course::instance($instance->courseid);

        $icons = array();

        if (has_capability('enrol/stripe:config', $context)) {
            $editlink = new moodle_url("/enrol/stripe/edit.php", array('courseid'=>$instance->courseid, 'id'=>$instance->id));
            $icons[] = $OUTPUT->action_icon($editlink, new pix_icon('t/edit', get_string('edit'), 'core',
                    array('class' => 'iconsmall')));
        }

        return $icons;
    }

    /**
     * Returns link to page which may be used to add new instance of enrolment plugin in course.
     * @param int $courseid
     * @return moodle_url page url
     */
    public function get_newinstance_link($courseid) {
        $context = context_course::instance($courseid, MUST_EXIST);

        if (!has_capability('moodle/course:enrolconfig', $context) or !has_capability('enrol/stripe:config', $context)) {
            return NULL;
        }

        // multiple instances supported - different cost for different roles
        return new moodle_url('/enrol/stripe/edit.php', array('courseid'=>$courseid));
    }

    /**
     * Creates course enrol form, checks if form submitted
     * and enrols user if necessary. It can also redirect.
     *
     * @param stdClass $instance
     * @return string html text, usually a form in a text box
     */
    function enrol_page_hook(stdClass $instance) 
    {
        global $CFG, $USER, $OUTPUT, $PAGE, $DB, $COURSE;

        ob_start();
        
        // Retrieve keys from the setting page
        $objPluginConfig =  get_config("enrol_stripe");
        $instance->stripeapikey = $objPluginConfig->stripeapikey;
        $instance->stripesecretkey =  $objPluginConfig->stripesecretkey;
		$strStripeToken = optional_param('stripeToken', false, PARAM_TEXT);
		
		if (false != $strStripeToken)
		{
			require_once ($CFG->dirroot . '/enrol/stripe/lib/Stripe.php');

			// Set your secret key: remember to change this to your live secret key in production
			// See your keys here https://dashboard.stripe.com/account
			Stripe::setApiKey($instance->stripesecretkey);
			
			// Get the credit card details submitted by the form
			$strStripeEmail = required_param('stripeEmail', PARAM_EMAIL);
			
			// COnvert cost to cents
			$intAmount = $instance->cost * 100; 
			
			// Create the charge on Stripe's servers - this will charge the user's card
			//$intAmount =  
			
			try {
				$objCharge = Stripe_Charge::create(array(
						"amount" 		=> $intAmount, // amount in cents, again
						"currency" 		=> $instance->currency,
						"card" 			=> $strStripeToken,
						"description" 	=> $strStripeEmail
					)
				);
			} catch(Stripe_CardError $e) {
				// The card has been declined
				echo '<div class="alert alert-error">'. $e->getMessage() .'</div>';
			}
			
			if( ! empty ($objCharge) )
			{
				$plugin = enrol_get_plugin('stripe');
				
				if ($instance->enrolperiod) {
					$timestart = time();
					$timeend   = $timestart + $instance->enrolperiod;
				} else {
					$timestart = 0;
					$timeend   = 0;
				}
				
				require_once($CFG->dirroot. '/group/lib.php');
								
				// Enrol user
				$plugin->enrol_user($instance, $USER->id, $instance->roleid, $timestart, $timeend);				
				
				$boolGroupMembership = groups_add_member(intval($instance->customint1), $USER->id); 				
				
				$mailstudents = $plugin->get_config('mailstudents');
				$mailteachers = $plugin->get_config('mailteachers');
				$mailadmins   = $plugin->get_config('mailadmins');
				$shortname = format_string($COURSE->shortname, true, array('context' => $context));
				
				
				if (!empty($mailstudents)) {
					$a = new stdClass();
					$a->coursename = format_string($COURSE->fullname, true, array('context' => $coursecontext));
					$a->profileurl = "$CFG->wwwroot/user/view.php?id=$USER->id";
				
					$eventdata = new stdClass();
					$eventdata->modulename        = 'moodle';
					$eventdata->component         = 'enrol_stripe';
					$eventdata->name              = 'stripe_enrolment';
					$eventdata->userfrom          = empty($teacher) ? core_user::get_support_user() : $teacher;
					$eventdata->userto            = $USER;
					$eventdata->subject           = get_string("enrolmentnew", 'enrol', $shortname);
					$eventdata->fullmessage       = get_string('welcometocoursetext', '', $a);
					$eventdata->fullmessageformat = FORMAT_PLAIN;
					$eventdata->fullmessagehtml   = '';
					$eventdata->smallmessage      = '';
					message_send($eventdata);
				
				}
				
				if (!empty($mailteachers) && !empty($teacher)) {
					$a->course = format_string($COURSE->fullname, true, array('context' => $coursecontext));
					$a->user = fullname($USER);
				
					$eventdata = new stdClass();
					$eventdata->modulename        = 'moodle';
					$eventdata->component         = 'enrol_stripe';
					$eventdata->name              = 'stripe_enrolment';
					$eventdata->userfrom          = $USER;
					$eventdata->userto            = $teacher;
					$eventdata->subject           = get_string("enrolmentnew", 'enrol', $shortname);
					$eventdata->fullmessage       = get_string('enrolmentnewuser', 'enrol', $a);
					$eventdata->fullmessageformat = FORMAT_PLAIN;
					$eventdata->fullmessagehtml   = '';
					$eventdata->smallmessage      = '';
					message_send($eventdata);
				}
				
				if (!empty($mailadmins)) {
					$a->course = format_string($COURSE->fullname, true, array('context' => $coursecontext));
					$a->user = fullname($USER);
					$admins = get_admins();
					foreach ($admins as $admin) {
						$eventdata = new stdClass();
						$eventdata->modulename        = 'moodle';
						$eventdata->component         = 'enrol_stripe';
						$eventdata->name              = 'stripe_enrolment';
						$eventdata->userfrom          = $USER;
						$eventdata->userto            = $admin;
						$eventdata->subject           = get_string("enrolmentnew", 'enrol', $shortname);
						$eventdata->fullmessage       = get_string('enrolmentnewuser', 'enrol', $a);
						$eventdata->fullmessageformat = FORMAT_PLAIN;
						$eventdata->fullmessagehtml   = '';
						$eventdata->smallmessage      = '';
						message_send($eventdata);
					}
				}
			}
		}
		else
		{   
	        if ($DB->record_exists('user_enrolments', array('userid'=>$USER->id, 'enrolid'=>$instance->id))) {
	            return ob_get_clean();
	        }
	
	        if ($instance->enrolstartdate != 0 && $instance->enrolstartdate > time()) {
	            return ob_get_clean();
	        }
	
	        if ($instance->enrolenddate != 0 && $instance->enrolenddate < time()) {
	            return ob_get_clean();
	        }
	
	        $course = $DB->get_record('course', array('id'=>$instance->courseid));
	        $context = context_course::instance($course->id);
	
	        $shortname = format_string($course->shortname, true, array('context' => $context));
	        $strloginto = get_string("loginto", "", $shortname);
	        $strcourses = get_string("courses");
	
	        // Pass $view=true to filter hidden caps if the user cannot see them
	        if ($users = get_users_by_capability($context, 'moodle/course:update', 'u.*', 'u.id ASC',
	                                             '', '', '', '', false, true)) {
	            $users = sort_by_roleassignment_authority($users, $context);
	            $teacher = array_shift($users);
	        } else {
	            $teacher = false;
	        }
	
	        if ( (float) $instance->cost <= 0 ) {
	            $cost = (float) $this->get_config('cost');
	        } else {
	            $cost = (float) $instance->cost;
	        }
	
	        if (abs($cost) < 0.01) { // no cost, other enrolment methods (instances) should be used
	            echo '<p>'.get_string('nocost', 'enrol_stripe').'</p>';
	        } else {
	
	            // Calculate localised and "." cost, make sure we send Stripe the same value,
	            // please note Stripe expects amount with 2 decimal places and "." separator.
	            $localisedcost = format_float($cost, 2, true);
	            $cost = format_float($cost, 2, false);
	
	            if (isguestuser()) { // force login only for guest user, not real users with guest role
	                if (empty($CFG->loginhttps)) {
	                    $wwwroot = $CFG->wwwroot;
	                } else {
	                    // This actually is not so secure ;-), 'cause we're
	                    // in unencrypted connection...
	                    $wwwroot = str_replace("http://", "https://", $CFG->wwwroot);
	                }
	                echo '<div class="mdl-align"><p>'.get_string('paymentrequired').'</p>';
	                echo '<p><b>'.get_string('cost').": $instance->currency $localisedcost".'</b></p>';
	                echo '<p><a href="'.$wwwroot.'/login/">'.get_string('loginsite').'</a></p>';
	                echo '</div>';
	            } else {
	            	
	            	// Add jquery for stripe scripts
	            	$PAGE->requires->jquery();
	            	
	                //Sanitise some fields before building the Stripe form
	                $coursefullname  = format_string($course->fullname, true, array('context'=>$context));
	                $courseshortname = $shortname;
	                $userfullname    = fullname($USER);
	                $userfirstname   = $USER->firstname;
	                $userlastname    = $USER->lastname;
	                $useraddress     = $USER->address;
	                $usercity        = $USER->city;
	                $instancename    = $this->get_instance_name($instance);
					
	                include($CFG->dirroot.'/enrol/stripe/enrol.html');
	                
	            }
	
	        }
		}

        return $OUTPUT->box(ob_get_clean());
    }

    /**
     * Restore instance and map settings.
     *
     * @param restore_enrolments_structure_step $step
     * @param stdClass $data
     * @param stdClass $course
     * @param int $oldid
     */
    public function restore_instance(restore_enrolments_structure_step $step, stdClass $data, $course, $oldid) {
        global $DB;
        if ($step->get_task()->get_target() == backup::TARGET_NEW_COURSE) {
            $merge = false;
        } else {
            $merge = array(
                'courseid'   => $data->courseid,
                'enrol'      => $this->get_name(),
                'roleid'     => $data->roleid,
                'cost'       => $data->cost,
                'currency'   => $data->currency,
            );
        }
        if ($merge and $instances = $DB->get_records('enrol', $merge, 'id')) {
            $instance = reset($instances);
            $instanceid = $instance->id;
        } else {
            $instanceid = $this->add_instance($course, (array)$data);
        }
        $step->set_mapping('enrol', $oldid, $instanceid);
    }

    /**
     * Restore user enrolment.
     *
     * @param restore_enrolments_structure_step $step
     * @param stdClass $data
     * @param stdClass $instance
     * @param int $oldinstancestatus
     * @param int $userid
     */
    public function restore_user_enrolment(restore_enrolments_structure_step $step, $data, $instance, $userid, $oldinstancestatus) {
        $this->enrol_user($instance, $userid, null, $data->timestart, $data->timeend, $data->status);
    }

    /**
     * Gets an array of the user enrolment actions
     *
     * @param course_enrolment_manager $manager
     * @param stdClass $ue A user enrolment object
     * @return array An array of user_enrolment_actions
     */
    public function get_user_enrolment_actions(course_enrolment_manager $manager, $ue) {
        $actions = array();
        $context = $manager->get_context();
        $instance = $ue->enrolmentinstance;
        $params = $manager->get_moodlepage()->url->params();
        $params['ue'] = $ue->id;
        if ($this->allow_unenrol($instance) && has_capability("enrol/stripe:unenrol", $context)) {
            $url = new moodle_url('/enrol/unenroluser.php', $params);
            $actions[] = new user_enrolment_action(new pix_icon('t/delete', ''), get_string('unenrol', 'enrol'), $url, array('class'=>'unenrollink', 'rel'=>$ue->id));
        }
        if ($this->allow_manage($instance) && has_capability("enrol/stripe:manage", $context)) {
            $url = new moodle_url('/enrol/editenrolment.php', $params);
            $actions[] = new user_enrolment_action(new pix_icon('t/edit', ''), get_string('edit'), $url, array('class'=>'editenrollink', 'rel'=>$ue->id));
        }
        return $actions;
    }

    public function cron() {
        $trace = new text_progress_trace();
        $this->process_expirations($trace);
    }

    /**
     * Execute synchronisation.
     * @param progress_trace $trace
     * @return int exit code, 0 means ok
     */
    public function sync(progress_trace $trace) {
        $this->process_expirations($trace);
        return 0;
    }
    
    public function PrintDebug($aArgs)
    {
    	echo "<pre>" . print_r( $aArgs ) . "</pre>";
    }
    
    public function can_delete_instance($instance) 
    {
    	$context = context_course::instance($instance->courseid);
    	return has_capability('enrol/stripe:config', $context);
    }
    
    public function can_hide_show_instance($instance) 
    {
    	$context = context_course::instance($instance->courseid);
    	return has_capability('enrol/stripe:config', $context);
    }
    
}
