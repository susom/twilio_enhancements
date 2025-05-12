// This file extends the default JSMO object with methods for this EM
;{
    // Define the jsmo in IIFE so we can reference object in our new function methods
    const module = ExternalModules.Stanford.TwilioEnhancements;

    // Extend the official JSMO with new methods
    Object.assign(module, {

        ExampleFunction: function() {
            console.log("Example Function showing module's data:", module.data);
        },

        lookupPhoneNumber: function(number, record_id) {
            module.ajax('LookupPhoneNumbers', {'phone_number' : number, 'record_id' : record_id}).then(function (response) {
                console.log("LookupPhone RESPONSE", response);
            }).catch(function (err) {
                console.log("Error", err);
            });
        }


    });
}
