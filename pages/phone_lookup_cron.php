<?php
namespace Stanford\TwilioEnhancements;
/** @var \Stanford\TwilioEnhancements\TwilioEnhancements $module */

try{
    $module->twilioCarrierLookup();
}catch (\Exception $e) {
    $module->emError($e->getMessage());
}
