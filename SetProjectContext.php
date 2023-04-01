<?php

namespace Stanford\TwilioEnhancements;
/** @var TwilioEnhancements $module */

use REDCap;

// This is now in Project Context so we can retrieve these values
$events = REDCap::getEventNames(true, false);

$isLongitudinal = REDCap::isLongitudinal();

$record_id_field = REDCap::getRecordIdField();

$params = [ "events"            => $events,
            "pk_field"          => $record_id_field,
            "isLongitudinal"    => $isLongitudinal
            ];

echo json_encode($params);
