<?php

namespace Stanford\TwilioEnhancements;

require_once "emLoggerTrait.php";

use ExternalModules\AbstractExternalModule;
use Messaging;
use TwilioRC;

use REDCap;

class TwilioEnhancements extends AbstractExternalModule
{
    use emLoggerTrait;

    public function __construct()
    {
        parent::__construct();
        // Other code to run when object is instantiated
    }


    private function getRecordFromNumber($number)
    {


    }


    public function redcap_every_page_before_render(int $project_id)
    {
        if (Messaging::getIncomingRequestType() == Messaging::PROVIDER_TWILIO && isset($_POST['OptOutType'])) {

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

            // get record_id from phone number
            $phone_field = $this->getProjectSetting('phone-field');
            $phone_field_event_id = (int)$this->getProjectSetting('phone-field-event-id');
            $from_number = $this->formatNumber($_POST['From'], "redcap");
            $event_prefix = REDCap::isLongitudinal() ? "[" . REDCap::getEventNames(true, false, $phone_field_event_id) . "]" : "";

            $param = [
                "project_id" => $project_id,
                "fields" => [REDCap::getRecordIdField()],
                "filterLogic" => $event_prefix . "[" . $phone_field . "] = '$from_number'"
            ];
            if (REDCap::isLongitudinal()) {
                $param["events"] = $phone_field_event_id;
            }
            $q = REDCap::getData($param);

            $record_count = count($q);
            if ($record_count == 0) {
                $this->emDebug("Unable to find a record from $from_number");
            } elseif ($record_count > 1) {
                // More than one hit - just taking first
                $this->emDebug("More than one record was found with $from_number, just using the first: " . implode(",",array_keys($q)));
                REDCap::logEvent(
                    "Multiple possible matches for inbound opt-in/opt-out - will opt out ALL of them",
                    "Record list: " . implode(",",array_keys($q)),
                    "",null,null,$project_id);
            }

            foreach ($q as $record_id => $eventInfo) {
                // value must be either START or STOP
                $out_out_field = $this->getProjectSetting('opt-out-field');
                $out_out_field_event_id = $this->getProjectSetting('opt-out-field-event-id');
                $data = [ $record_id => [$out_out_field_event_id => [ $out_out_field => $value ]]];
                $param = [
                    "project_id" => $project_id,
                    "overwriteBehavior" => "overwrite",
                    "data" => $data
                ];
                $q = REDCap::saveData($param);

                if (!empty($q['errors'])) {
                    $this->emError("There was an error setting the opt_out value", $param, $q);
                }

                if ($email = $this->getProjectSetting('email-notifications')) {
                    global $project_contact_email;
                    REDCap::email($email,$project_contact_email,'Twilio Enhancements: Opt-Out Change (#' . $record_id . ')',
                        "Record $record_id from project $project_id received opt-out update to $opt_out_type");
                }
            }
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


    public function redcap_every_page_top(int $project_id)
    {
        $this->emDebug("2");

    }


    public function redcap_survey_page_top(int $project_id, $record, string $instrument, int $event_id, $group_id, string $survey_hash, $response_id, $repeat_instance)
    {
        $this->emDebug("3");
    }


}
