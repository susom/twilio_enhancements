<?php

namespace Stanford\TwilioEnhancements;

require_once "emLoggerTrait.php";
require_once "vendor/autoload.php";

use ExternalModules\AbstractExternalModule;
use Messaging;
use GuzzleHttp;
use GuzzleHttp\Exception\GuzzleException;
use REDCap;
use Exception;
use Twilio\Rest\Client;

class TwilioEnhancements extends AbstractExternalModule
{
    use emLoggerTrait;

    public $record = null;

    private $TwilioClient;

    public function redcap_every_page_before_render(int $project_id=null)
    {
        // Check to see if this is a survey page and this message is coming from Twilio with an OptOutType entry
        // If all those three conditions are not met, skip processing
        if (PAGE == 'surveys/index.php' &&
            Messaging::getIncomingRequestType() == Messaging::PROVIDER_TWILIO &&
            isset($_POST['OptOutType'])) {

            // Save current pid so we can replace before we leave
            $old_pid = $_GET['pid'];
            $this->emDebug("In Twilio Opt-Out processing for project id: " . $this->getProjectId(), $project_id, $old_pid, $_POST);

            if (is_null($this->getProjectId())) {
                $project_id = $this->findProjectByToPhoneNum($_POST['To']);
                if (empty($project_id)) {
                    $this->emError("Cannot find the project ID based on the Twilio phone number of ". $_POST['To']);
                    return;
                }
                if (empty($this->getProjectId())) {
                    $this->emError("Cannot set the project ID from phone number. Project ID was found as $project_id and phone from POST is ". $_POST['To']);
                    return;
                }
            } else {
                $project_id = $this->getProjectId();
            }
            $_GET['pid'] = $project_id;

            $this->emDebug("Project ID before retrieving Project Settings: " . $project_id . ", getProjectId(): " . $this->getProjectId());

            // get phone field/event and opt-out field/event in project
            $phone_field = $this->getProjectSetting('phone-field', $project_id);
            $phone_field_event_id = (int)$this->getProjectSetting('phone-field-event-id', $project_id);
            $opt_out_field = $this->getProjectSetting('opt-out-field', $project_id);
            $opt_out_field_event_id = $this->getProjectSetting('opt-out-field-event-id', $project_id);
            $opt_out_checkbox = $this->getProjectSetting('opt-out-checkbox', $project_id);
            $opt_out_checkbox_event_id = $this->getProjectSetting('opt-out-checkbox-event-id', $project_id);
            $email = $this->getProjectSetting('email-notifications', $project_id);
            $this->emDebug("Email address: " . $email);

            // This is the number for the opt-out status change
            $from_number = $this->formatNumber($_POST['From'], "redcap");
            $from_number_dashes = $this->formatNumber($_POST['From'], "dashes");

            // Either save the date (opt-out) or clear the date (opt-in)
            $opt_out_type = $_POST['OptOutType'];
            if ($opt_out_type == "START") {
                $value = "";
                $checkbox_value = 0;
            } elseif ($opt_out_type == "STOP") {
                $value = date("Y-m-d H:i:s");
                $checkbox_value = 1;
            } else {
                // Shouldn't get here
                $this->emError("Received OptOutType value of " . $opt_out_type, $_POST);
                return;
            }

            // Make a call to set the project context so we can use all the project context functions
            // Since project context is still not set when we return, retrieve the project data in the
            // call and return it here
            [$record_id_field, $is_longitudinal, $event_name] = $this->retrieveProjectSettings($phone_field_event_id);

            // Retrieve the records with this opt-out/opt-in phone number
            $q = $this->retrieveRecords($record_id_field, $is_longitudinal, $event_name,
                                        $phone_field, $phone_field_event_id, $from_number, $from_number_dashes);

            // Save the opt-out/opt-in status for each record and conditionally send email
            global $project_contact_email;
            foreach ($q as $record_id => $eventInfo) {
                // value must be either START or STOP
                $data = [$record_id => [$opt_out_field_event_id => [$opt_out_field => $value]]];
                if (!is_null($opt_out_checkbox)) {
                    $data[$record_id][$opt_out_checkbox_event_id][$opt_out_checkbox . "___1"] = $checkbox_value;
                }
                $param = [
                    "project_id" => strval($this->getProjectId()),
                    "overwriteBehavior" => "overwrite",
                    "data" => $data
                ];
                $q = REDCap::saveData($param);
                if (!empty($q['errors'])) {
                    $this->emError("There was an error setting the opt_out value", $param, $q);

                    // Even if there is an error, send a message that a person is changing their opt-out status
                    if (!empty($email)) {
                        REDCap::email($email,$project_contact_email,'Twilio Enhancements: Opt-Out Change ERROR (#' . $record_id . ')',
                            "Record $record_id from project " . $this->getProjectId() . " received an opt-out status update to $opt_out_type but REDCap could not save the new status");
                    }

                } else {
                    $this->emDebug("Successfully changed opt out status for record $record_id from project " . $this->getProjectId() . " with new status $opt_out_type");
                    if (!empty($email)) {
                        REDCap::email($email, $project_contact_email, 'Twilio Enhancements: Opt-Out Change SUCCESSFUL (#' . $record_id . ')',
                            "Record $record_id from project " . $this->getProjectId() . " received an opt-out status update to $opt_out_type");
                    }
                }
            }

            // Replace whatever was in pid when it entered
            $_GET['pid'] = $old_pid;
        }
    }


    private function findProjectByToPhoneNum($to_phone) {

        $redcap_format = $this->formatNumber($to_phone, "digits");
        $sql = "select project_id from redcap_projects where twilio_from_number = ?";
        $project_ids = [];
        try {
            $q = $this->query($sql, [$redcap_format]);
            while ($row = $q->fetch_assoc()) {
                $project_ids[] = strval($row['project_id']);
            }
        } catch (\Exception $ex) {
            $this->emError("DB Error: " . json_encode($ex));
        }

        $cnt = count($project_ids);
        if ($cnt == 0) {
            $this->emDebug("No matches found for $to_phone");
            $project_id = null;
        } else {
            if ($cnt > 1) {
                $this->emDebug("Found more than one project match for $to_phone - using first: " . implode(",", $project_ids));
            }
            $project_id = reset($project_ids);
        }
        return $project_id;
    }

    /**
     * Retrieve the record(s) that contain this phone number so we can change the opt-out/opt-in status
     *
     * @param $record_id_field
     * @param $is_longitudinal
     * @param $event_name
     * @param $phone_field
     * @param $phone_field_event_id
     * @param $from_number
     * @return mixed|null
     */
    private function retrieveRecords($record_id_field, $is_longitudinal, $event_name,
                                     $phone_field, $phone_field_event_id, $from_number, $from_number_dashes) {

        // If this is longitudinal, append event name to retrieve the phone number field
        $event_prefix = $is_longitudinal ? "[" . $event_name . "]" : "";
        $param = [
            "project_id" => $this->getProjectId(),
            "fields" => ["$record_id_field"],
            "filterLogic" => $event_prefix . "[" . $phone_field . "] = '$from_number' or " .
                             $event_prefix . "[" . $phone_field . "] = '$from_number_dashes'"
        ];

        if ($is_longitudinal) {
            $param["events"] = $phone_field_event_id;
        }
        $q = REDCap::getData($param);

        // There may be more than one record with the same phone number. We want to set them all to opt-out
        $record_count = count($q);
        if ($record_count == 0) {
            $this->emError("Unable to find a record from $from_number");
            return null;
        } elseif ($record_count > 1) {
            // More than one hit - just taking first
            $this->emDebug("More than one record was found with $from_number, setting them all to opt-out: " . implode(",",array_keys($q)));
            REDCap::logEvent(
                "Multiple possible matches for inbound opt-in/opt-out - will opt-out/opt-in ALL of them",
                "Record list: " . implode(",",array_keys($q)),
                "", null, null, $this->getProjectId());
            return $q;
        } else {
            return $q;
        }
    }

    /**
     * Make a guzzle call with pid to set the project context so we can retrieve information about this project.  We
     * need to know if the project is longitudinal, what the event name is and the project primary key field name.
     *
     * @param $phone_field_event_id
     * @return array|null[]
     * @throws GuzzleHttp\Exception\GuzzleException
     */
    private function retrieveProjectSettings($phone_field_event_id) {

        try {
            $proj_context_url = $this->getUrl('SetProjectContext.php?pid=' . $this->getProjectId(), true, true);
            $client = new GuzzleHttp\Client;
            $res = $client->request('GET',
                $proj_context_url,
                [
                    'synchronous' => true,
                    'redcap_csrf_token' => $this->getCSRFToken()
                ]
            );
            $return = json_decode($res->getBody()->getContents(), true);
            $event_name =  ($return['isLongitudinal'] ? $return['events']["$phone_field_event_id"] : "");

            return [$return['pk_field'], $return['isLongitudinal'], $event_name];

        } catch (GuzzleException $ex) {
            $this->emError("Exception throw when instantiating Guzzle with error: " . $ex->getMessage());
            return [null, null, null];
        }

    }

    /**
     * Format a phone number
     * @param string $number
     * @param string $type E164 | redcap | digits
     * @return string
     */
    public function formatNumber($number, $type = "E164")
    {
        // REDCap stores numbers like '(650) 123-4567' -- convert to +16501234567
        $digits = preg_replace('/[^\d]/', '', $number);
        // $this->emDebug("INCOMING : $number");
        $output = "";
        if ($type == "E164") {
            // For US, append a 1 to 10 digit numbers that dont start with a 1
            if (strlen($digits) === 10 && left($digits, 1) != "1") {
                $output = "1" . $digits;
            } else {
                $output = $digits;
            }
            $output = "+" . $output;
        } elseif ($type == "redcap") {
            if (strlen($digits) === 11 && left($digits, 1,) == "1") {
                // 16503803405 => 6503803405
                $digits = mid($digits, 2, 10);
            }
            if (strlen($digits) === 10) {
                // 6503803405 => (650) 380-3405
                // TODO: ANDY? in redcap_data it's stored as 6503803405
                $output = "(" . mid($digits, 1, 3) . ") " . mid($digits, 4, 3) . "-" . mid($digits, 7, 4);
                //$output = $digits;
            }
        } elseif ($type == "dashes") {
            if (strlen($digits) === 11 && left($digits, 1,) == "1") {
                // 16503803405 => 6503803405
                $digits = mid($digits, 2, 10);
            }
            if (strlen($digits) === 10) {
                // 6503803405 => 650-380-3405
                $output = mid($digits, 1, 3) . "-" . mid($digits, 4, 3) . "-" . mid($digits, 7, 4);
            }
        } elseif ($type == "digits") {
            $output = $digits;
        }
        if ($output == "") $this->emDebug("Unable to parse $number to $digits into type $type");
        // $this->emDebug("FORMATNUMBER $type $number => $output");
        return strval($output);
    }

    /**
     * CRON JOBS
     * These are cron jobs that run to help with Twilio Administration.
     *
     *      1. campaignStatusManager - runs every 12 hours - checks the Twilio Tracker project to see which campaigns are not yet approved
     *          and checks to see the current status of the campaign.  If the campaign is approved or rejected, the Twilio Tracker
     *          status will be updated and alerts can be setup to notify the Jira RSSD project that a campaign status has changed
     *      2. deleteSubAccountManager - runs once a week - deletes any Twilio subaccount that starts with "DELETE_"
     *      3. financeManager - runs once a month - retrieves the monthly charges for each Twilio subaccount and updates the Twilio Tracker record.
     *
     */

    /**
     * This function will be called by the cron job or can be invoked by the System Page TwilioAdmin.php
     * This function will retrieve all Twilio records in the Twilio Tracker project that have the Campaign
     * status of InProgress and use the API call to retrieve the current status from Twilio.  If the
     * Campaign was Approved or Rejected, the Twilio Tracker record status will be updated and email will
     * be sent to the Jira.
     *
     * @return void
     */
    public function twilioCampaignStatusManager() {

        // Values of the [campaign_status] field in Twilio Tracker
        $verified_status = 1;
        $inprogress_status = 2;
        $rejected_status = 4;

        // Special SID that Twilio uses to check for Campaign statuses
        $checkCampaignStatusSID = "QE2c6890da8086d771620e9b13fadeba0b";

        // Retrieve pid of twilio tracker project
        $project_id = $this->getSystemSetting("twilio-tracker-pid");
        if (empty($project_id)) return;
        $fieldsToRetrieve = ["record_id", "twilio_acct_sid", "twilio_auth_token", "twilio_campaign_sid"];
        $filter = "[campaign_status]='$inprogress_status'";

        // Retrieve REDCap records in Twilio Tracker project where the [campaign_status] field is 'In Progress'
        // Campaign submitted but not yet verified (radio=2)
        $return = $this->getREDCapRecordsToProcess($project_id, $fieldsToRetrieve, $filter);

        // If there are Twilio subaccounts waiting for Campaign verification, see if the status was updated
        if (!empty($return)) {

            // Loop over each record and retrieve status from Twilio
            $updateRedcap = [];
            foreach($return as $oneRecord) {

                $url = "https://messaging.twilio.com/v1/Services/" . $oneRecord["twilio_campaign_sid"] . "/Compliance/Usa2p/" . $checkCampaignStatusSID;
                $basic_auth_user = $oneRecord["twilio_acct_sid"] . ":" . $oneRecord["twilio_auth_token"];
                $response = http_get($url, null, $basic_auth_user, null, null);
                if ($response !== false) {
                    $return = json_decode($response, true);
                    $this->emDebug("Record " . $oneRecord['record_id'] . " has status of " . $return['campaign_status']);

                    // If the Campaign status was verified, update the REDCap Twilio Tracker project
                    if ($return['campaign_status'] == "VERIFIED") {
                        $updateRecord = [
                            "record_id"             => $oneRecord["record_id"],
                            "campaign_status"       => "$verified_status",
                            "campaign_verified"     => "1",
                            "last_checked"          => DATE("Y-m-d")
                            ];
                        $updateRedcap[] = $updateRecord;
                    } else if ($return['campaign_status'] == 'FAILED') {

                        // If the campaign was rejected, update Twilio Tracker
                        $updateRecord = [
                            "record_id"             => $oneRecord["record_id"],
                            "campaign_status"       => "$rejected_status",
                            "campaign_verified"     => "0",
                            "last_checked"          => DATE("Y-m-d")
                        ];
                        $updateRedcap[] = $updateRecord;
                    }
                }
            }

            // Save any updated statuses in REDCap
            if (!empty($updateRedcap)) {
                $status = REDCap::saveData($project_id, 'json', json_encode($updateRedcap));
                if (!empty($status["errors"])) {
                    $this->emError("Could not update REDCap with current status: " . json_encode($status));
                }
            }
        }
    }

    /**
     * This function will run on a cron every two weeks or can be invoked using the System EM page
     * TwilioAdmin.php.  This function will retrieve Twilio costs for each subaccount and update the
     * REDCap project Twilio Tracker.  This API call always queries for charges during "LastMonth"
     * so it lags by a month.
     *
     * @return void
     * @throws Exception
     */
    public function twilioFinanceManager() {
        /**
             Each category for billing needs a separate API call.  Right now, we will retrieve
              * totalcost - all messaging and phone costs (does not include campaign fee)
              * sms - messaging count for both incoming and outgoing
              * mms - messaging count for both incoming and outgoing
              * campaign charge - monthly fee for an approved campaign
         */
        $categories = [
                "twilio_charge_amount"      => "totalprice",
                "twilio_sms_num_texts"      => "sms",
                "twilio_mms_num_texts"      => "mms",
                "twilio_campaign_amount"    => "a2p-registration-fees"
        ];

        // Retrieve pid of twilio tracker project
        $twilio_uri = $this->getSystemSetting("twilio-uri");
        $project_id = $this->getSystemSetting("twilio-tracker-pid");
        if (empty($project_id)) return;
        $fieldsToRetrieve = ["record_id", "twilio_acct_sid", "twilio_auth_token"];
        $filter = "[twilio_acct_sid]<> '' and [twilio_auth_token]<> '' and [twilio_end_date] = '' ";

        // Retrieve REDCap records in Twilio Tracker project where the SID and Auth Token are not blank
        $records = $this->getREDCapRecordsToProcess($project_id, $fieldsToRetrieve, $filter);
        //$this->emDebug("Records: ", $records);
        if (!empty($records)) {

            // Determine what the instance ID will be.  Just to make it easier, I'm going to set
            // instance_id to 1 for 01-01-2023 and then increment from there for each month.  Newer
            // records will not start with instance_id = 1.
            $instance_id = $this->calcInstanceId();

            // Look over each record which can be an account or subaccount
            $redcapCharges = [];
            foreach ($records as $oneRecord) {

                // We are always querying for last month
                $twilio_url = $twilio_uri . "/Accounts/" . $oneRecord['twilio_acct_sid'].
                    "/Usage/Records/LastMonth?Category=";
                //$twilio_url = $twilio_uri . "/Accounts/" . $oneRecord['twilio_acct_sid'].
                //    "/Usage/Records?StartDate=".$start_date."&EndDate=".$end_date."&Category=";
                $basic_auth_user = $oneRecord["twilio_acct_sid"] . ":" . $oneRecord["twilio_auth_token"];

                $recordCharges = [];
                foreach ($categories as $field_name => $cat) {
                    $cat_url = $twilio_url . $cat;
                    $response = http_get($cat_url, null, $basic_auth_user, null, null);
                    $twilio_response = new \SimpleXMLElement($response);
                    //$this->emDebug("Billing info for record " . $oneRecord['record_id'] , " return: ", $response);
                    if ($cat == "sms" or $cat == "mms") {
                        $recordCharges[$field_name] = current($twilio_response->UsageRecords->UsageRecord[0]->Count);
                    } else {
                        $recordCharges[$field_name] = current($twilio_response->UsageRecords->UsageRecord[0]->Price);
                    }

                    $recordCharges["twilio_charge_date"] = current($twilio_response->UsageRecords->UsageRecord[0]->StartDate);
                    $this->emDebug("For category $cat, price/count is $recordCharges[$field_name] for month starting date of "
                                . $recordCharges["twilio_charge_date"] . " for record " . $oneRecord['record_id']);
                }

                // Only save this month if there were some charges
                if ($recordCharges['twilio_charge_amount'] <> 0
                        or $recordCharges['twilio_campaign_amount'] <> 0) {
                    $recordCharges['record_id'] = $oneRecord['record_id'];
                    $recordCharges['redcap_repeat_instance'] = $instance_id;
                    $recordCharges['redcap_repeat_instrument'] = 'charges';
                    $recordCharges['charges_complete'] = 1;
                    $redcapCharges[] = $recordCharges;
                }
            }
        }

        // Save charge data in REDCap
        if (!empty($redcapCharges)) {
            $status = REDCap::saveData($project_id, 'json', json_encode($redcapCharges));
            if (!empty($status["errors"])) {
                $this->emError("Could not update REDCap with current finance status: " . json_encode($status));
            }
        }
    }

    /**
     * Retrieve the list of records to process based on the filter criteria. Returns a json array
     * of records with the fields specified in the $fields array.
     *
     * @param $project_id
     * @param $fields
     * @param $filter
     * @return mixed
     */
    private function getREDCapRecordsToProcess($project_id, $fields, $filter) {

        $params = [
            "project_id"        => $project_id,
            "return_format"     => "json",
            "fields"            => $fields,
            "filterLogic"       => $filter
        ];
        $return = REDCap::getData($params);
        if (empty($return)) {
            $this->emError("Error retrieve REDCap data for project $project_id with parameters: ", $params);
        }
        $json_return = json_decode($return, true);

        return $json_return;
    }

    /**
     * This function will calculate an instance ID for REDCap.  Since it is a lot of overhead to determine
     * the instance ID for each record, we will make each month the same instance ID for all records.  So
     * Jan 2023 will always be instance 1, Feb 2023 will always be instance 2, etc. Reports can be made for
     * instance 6 entries for all charges in June 2023.
     *
     * @param $start_date
     * @return float|int
     */
    private function calcInstanceId() {

        // We are always retrieving data for last month
        $start_month = DATE("m");
        $start_year = DATE("Y");
        $last_month_year = ($start_month == 1 ? ($start_year-1) : $start_year);
        $last_month = ($start_month == 1 ? 12 : ($start_month-1));

        // Calculate an instance_id based on number of months from 2023-01-01
        return  ($last_month_year-2023)*12 + $last_month;

    }

    public function redcap_module_ajax($action, $payload, $project_id, $record, $instrument, $event_id, $repeat_instance,
                                       $survey_hash, $response_id, $survey_queue_hash, $page, $page_full, $user_id, $group_id)
    {
        switch($action) {
            case "LookupPhoneNumbers":
                $phone_number = $payload['phone_number'];
                $record_id = $payload['record_id'];
                try{
                    $line_type_intelligence = $this->lookupPhoneNumber($phone_number);
                    if(!empty($line_type_intelligence)){
                        $data[\REDCap::getRecordIdField()]= $record_id;
                        if($this->getProjectSetting('phone-carrier-name') && $this->getProjectSetting('phone-carrier-name') != ''){
                            $data[$this->getProjectSetting('phone-carrier-name')] = $line_type_intelligence['carrier_name'];
                        }
                        if($this->getProjectSetting('phone-carrier-type') && $this->getProjectSetting('phone-carrier-type') != ''){
                            $data[$this->getProjectSetting('phone-carrier-type')] = $line_type_intelligence['type'];
                        }
                        $response = \REDCap::saveData($this->getProjectId(), 'json', json_encode(array($data)));
                        if (!empty($response['errors'])) {
                            REDCap::logEvent(implode(",", $response['errors']));
                        }else{
                            $result = [
                                "success"=>true,
                                "carrier_name"=>$line_type_intelligence['carrier_name'],
                                "carrier_type"=>$line_type_intelligence['type'],
                            ];
                        }
                    }else{
                        REDCap::logEvent("No line_type_intelligence for phone number $phone_number and record $record_id");
                    }

                    break;
                }catch (\Exception $e){

                }
                break;
            default:
                // Action not defined
                throw new Exception ("Action $action is not defined");
        }

        // Return is left as php object, is converted to json automatically
        return $result;
    }

    public function redcap_survey_page( int $project_id, string $record = NULL, string $instrument, int $event_id, int $group_id = NULL, string $survey_hash, int $response_id = NULL, int $repeat_instance = 1 )
    {
        // load phone_lookup page only if user wants to collect the data
        if($this->getProjectSetting('collect-carrier-info')){
            $this->record = $record;
            $this->includeFile('pages/phone_lookup.php');
        }
    }

    public function includeFile($path)
    {
        include_once $path;
    }

    /**
     * Perform a phone number lookup using Twilio API.
     *
     * @param string $phoneNumber The phone number to lookup (in E.164 format).
     * @return array|null The lookup data, or null if the lookup fails.
     */
    public function lookupPhoneNumber($phoneNumber) {
        try {
            $lookup = $this->getTwilioClient()->lookups->v2->phoneNumbers($phoneNumber)
                ->fetch(["fields" => "line_type_intelligence"]);

            return $lookup->lineTypeIntelligence ?? null;
        } catch (Exception $e) {
            $this->module->emError('Twilio Lookup Error: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * @return Client TwilioClient
     * @throws \Twilio\Exceptions\ConfigurationException
     */
    public function getTwilioClient() {
        if (empty($this->TwilioClient)) {
            $this->TwilioClient = new Client($this->getProjectSetting('twilio-sid'), $this->getProjectSetting('twilio-token'));
        }
        return $this->TwilioClient;
    }
}
