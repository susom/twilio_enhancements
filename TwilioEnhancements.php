<?php

namespace Stanford\TwilioEnhancements;

require_once "emLoggerTrait.php";

use ExternalModules\AbstractExternalModule;
use Messaging;
use GuzzleHttp;
use REDCap;

class TwilioEnhancements extends AbstractExternalModule
{
    use emLoggerTrait;

    public function __construct()
    {
        parent::__construct();
        // Other code to run when object is instantiated
    }


    public function redcap_every_page_before_render($project_id)
    {

        if (PAGE == 'surveys/index.php' && $project_id = $this->getProjectId() &&
            Messaging::getIncomingRequestType() == Messaging::PROVIDER_TWILIO && isset($_POST['OptOutType'])) {

            // get phone field/event and opt-out field/event in project
            $phone_field = $this->getProjectSetting('phone-field');
            $phone_field_event_id = (int)$this->getProjectSetting('phone-field-event-id');
            $out_out_field = $this->getProjectSetting('opt-out-field');
            $out_out_field_event_id = $this->getProjectSetting('opt-out-field-event-id');

            // This is the number for the opt-out status change
            $from_number = $this->formatNumber($_POST['From'], "redcap");

            // Either save the date (opt-out) or clear the date (opt-in)
            $opt_out_type = $_POST['OptOutType'];
            if ($opt_out_type == "START") {
                $value = "";
            } elseif ($opt_out_type == "STOP") {
                $value = date("Y-m-d H:i:s");
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
                                        $phone_field, $phone_field_event_id, $from_number);

            // Save the opt-out/opt-in status for each record and conditionally send email
            global $project_contact_email;
            foreach ($q as $record_id => $eventInfo) {
                // value must be either START or STOP
                $data = [ $record_id => [$out_out_field_event_id => [ $out_out_field => $value ]]];
                $param = [
                    "project_id" => $this->getProjectId(),
                    "overwriteBehavior" => "overwrite",
                    "data" => $data
                ];
                $q = REDCap::saveData($param);
                if (!empty($q['errors'])) {
                    $this->emError("There was an error setting the opt_out value", $param, $q);

                    // Even if there is an error, send a message that a person is changing their opt-out status
                    if ($email = $this->getProjectSetting('email-notifications')) {
                        REDCap::email($email,$project_contact_email,'Twilio Enhancements: Opt-Out Change ERROR (#' . $record_id . ')',
                            "Record $record_id from project " . $this->getProjectId() . " received an opt-out status update to $opt_out_type but REDCap could not save the new status");
                    }

                } else if ($email = $this->getProjectSetting('email-notifications')) {
                    REDCap::email($email,$project_contact_email,'Twilio Enhancements: Opt-Out Change SUCCESSFUL (#' . $record_id . ')',
                        "Record $record_id from project " . $this->getProjectId() . " received an opt-out status update to $opt_out_type");
                }
            }
        }
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
                                     $phone_field, $phone_field_event_id, $from_number) {

        // If this is longitudinal, append event name to retrieve the phone number field
        $event_prefix = $is_longitudinal ? "[" . $event_name . "]" : "";
        $param = [
            "project_id" => $this->getProjectId(),
            "fields" => ["$record_id_field"],
            "filterLogic" => $event_prefix . "[" . $phone_field . "] = '$from_number'"
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
            $this->emDebug("More than one record was found with $from_number, just using the first: " . implode(",",array_keys($q)));
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
                    'synchronous' => true
                ]
            );
            $return = json_decode($res->getBody()->getContents(), true);
            $event_name =  ($return['isLongitudinal'] ? $return['events']["$phone_field_event_id"] : "");

            return [$return['pk_field'], $return['isLongitudinal'], $event_name];

        } catch (\Exception $ex) {
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
        } elseif ($type == "digits") {
            $output = $digits;
        }
        if ($output == "") $this->emDebug("Unable to parse $number to $digits into type $type");
        // $this->emDebug("FORMATNUMBER $type $number => $output");
        return strval($output);
    }

/*
    public function redcap_every_page_top(int $project_id)
    {
        $this->emDebug("2");
        $this->emDebug("This is POST: " . json_encode($_POST));

    }


    public function redcap_survey_page_top(int $project_id, $record, string $instrument, int $event_id, $group_id, string $survey_hash, $response_id, $repeat_instance)
    {
        $this->emDebug("3");
        $this->emDebug("This is POST: " . json_encode($_POST));
    }
*/

}
