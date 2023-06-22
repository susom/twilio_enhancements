<?php
namespace Stanford\TwilioEnhancements;
/** @var \Stanford\TwilioEnhancements\TwilioEnhancements $module */

require APP_PATH_DOCROOT . "ControlCenter/header.php";

$taskName = isset($_GET['taskName']) && !empty($_GET['taskName']) ? $_GET['taskName'] : null;
$module->emDebug("Value of cron $taskName and GET params: " . json_encode($_GET));

if ($taskName == 'updateCampaignStatus') {
    $module->twilioCampaignStatusManager();
} else if ($taskName == 'deleteSubAccounts') {
    $module->deleteSubAccountManager();
} else if ($taskName == 'getFinances') {
    $module->twilioFinanceManager();
    $module->emDebug("Back from getFinances");
}

?>

<html>
<header>
    <title>Twilio Administrative Duties</title>
</header>
<body>
    <h1 style="margin-top: 20px;">Perform Twilio Administration Tasks</h1>

    <h3 style="margin-top: 20px;">Retrieve Campaign Statuses</h3>
    <div style="margin-left: 20px;">
        <p>This process runs through all the records in the Twilio Tracker project and checks the</p>
        <p>campaign status.  If the status is not 'In Progress', update the Twilio Tracker record.</p>
        <button style="padding: 10px; color: blue;" id="campaignCheck" onclick="checkCampaignStatuses()">Check Campaign Status</button>
    </div>

    <br><br>
    <h3 style="margin-top: 20px;">Retrieve Billing Information</h3>
    <div style="margin-left: 20px;">
        <p>This process runs through all Twilio records in the Twilio Tracker project.</p>
        <p>It retrieves billing information for last month. If run in June, it will retrieve</p>
        <p>billing charges for May.</p>
        <button style="padding: 10px; color: blue;" id="retrieveBilling" onclick="getMonthlyFinances()">Retrieve billing info</button>
    </div>

</body>
</html>

<script type="text/javascript">

    function checkCampaignStatuses() {
        var answer = confirm("Check Campaign Statuses?");
        if (answer == true) {
            RtoS.checkStatus();
        } else {
            alert("Canceling request to check statuses");
        }
    }

    function getMonthlyFinances() {
        var answer = confirm("Retrieve last month's billing?");
        if (answer == true) {
            RtoS.getFinances();
        } else {
            alert("Canceling request to retrieve billing");
        }
    }


    var RtoS = RtoS || {};

    // Make the API call back to the server to load the new config\
    RtoS.checkStatus = function() {

        $.ajax({
            type: "GET",
            data: {
                "taskName" : "updateCampaignStatus"
            },
            success:function(status) {
            }
        }).done(function (status) {
            //console.log("Done running code for " + whichCron);
        }).fail(function (jqXHR, textStatus, errorThrown) {
            //console.log("Failed in ron cron for " + whichCron);
        });

    };


    // Make the API call back to the server to load the new config\
    RtoS.getFinances = function() {

        $.ajax({
            type: "GET",
            data: {
                "taskName": "getFinances"
            },
            success:function(status) {
            }
        }).done(function (status) {
            //console.log("Done running code for " + whichCron);
        }).fail(function (jqXHR, textStatus, errorThrown) {
            //console.log("Failed in ron cron for " + whichCron);
        });

    };


</script>
