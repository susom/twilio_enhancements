<?php
namespace Stanford\TwilioEnhancements;
/** @var \Stanford\TwilioEnhancements\TwilioEnhancements $this */

// initiate REDCap JSMO object
echo $this->initializeJavascriptModuleObject();
$cmds = [
    "const module = " . $this->getJavascriptModuleObjectName()
];
if (!empty($data)) $cmds[] = "module.data = " . json_encode($data);
if (!empty($init_method)) $cmds[] = "module.afterRender(module." . $init_method . ")";
?>
<script src="<?=$this->getUrl("assets/jsmo.js",true)?>"></script>
<script>
    $(function() { <?php echo implode(";\n", $cmds) ?> })
</script>



<script>
    var phone_field = "<?php echo $this->getProjectSetting('phone-field');?>"
    var record_id = "<?php echo $this->record;?>"
    $(document).ready(function () {
        console.log(phone_field)

        $('input[name="'+phone_field+'"]').on('blur', function () {
            var phoneNumber = $(this).val();
            // Simple phone number validation (US format, 10 digits)
            var phoneRegex = /^\(?([0-9]{3})\)?[-.● ]?([0-9]{3})[-.● ]?([0-9]{4})$/;

            if(phoneRegex.test(phoneNumber)){
                ExternalModules.Stanford.TwilioEnhancements.lookupPhoneNumber(phoneNumber, record_id);
            }
        });

    });
</script>
