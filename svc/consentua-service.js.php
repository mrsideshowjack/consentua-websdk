<?php

	header("Content-type: text/javascript");

	// Load environment configuration
	require 'config.php';

?>

/**
 * This script is run by the service at websdk.consentua.com
 */

console.log("Service loaded");
$().ready(function(){

// Parse info from hash
var h = window.location.hash;
var hp = h.replace(/^#/, '').split(/&/);
var args = {};
for(var i in hp){
  var a = hp[i].split(/=/);
  args[a[0]] = decodeURIComponent(a[1]);
}

$('#consentua-interaction').hide(); // Hide the iframe


console.log("Initialise Consentua Web SDK Service", args);

if(typeof args['s'] == 'undefined' || typeof args['c'] == 'undefined' || typeof args['t'] == 'undefined'){
  console.error("Required parameter(s) missing - Check and update your consentua embed code").show();
  return;
}

if(typeof args['lang'] == 'undefined')
	var lang = 'en';
else
	var lang = args['lang'];

/**
* Set up messaging with the embedding page, and the interaction itself
*/
var wrapcomms = new WindowComms(window.parent);
var intcomms = new WindowComms($('#consentua-interaction').get(0).contentWindow);

var apipath = "<?php echo $_CONF['api-path']; ?>";
var c = new ConsentuaClient({
	serviceID: args['s'],
	clientID: args['c'],
	lang: lang,
	baseURL: apipath,
	serviceKey: '19fb13ab-d6f2-42dc-a41c-42249450b5b6' // BUG: Only reqd while templates need logged in service, to be dropped ASAP!
});


/**
 * Stage 1: Create or retrieve the user
 */
function login()
{
	c.login().then(function()
	{
        /**
         * A user identifier *may* be passed as the uid field in the hash, but otherwise an anonymous one is generated by the service
         */
		// Not required - API now generates identifiers
		/*
        if(typeof args['uid'] == 'undefined'){
          var ts = Date.now();
          args['uid'] = 'anon-' + args['s'] + '-' + Math.floor(ts / 1000) + '-' + Math.floor(Math.random() * 1000000);
        }
		*/

		/**
		 * Either create a new user, of retrieve the one specified in ?uid
		 */
		if(typeof args['uid'] == 'undefined')
		{
	        var au = c.addUser();
	        au.then(function(user){
	            console.log("Created Consentua user", user.UserId, " with identifier ", user.Identifier);
				args.uid = user.Identifier; // Store the created identifier
	        });
		}
		else
		{
			var au = c.testIfUserExists(args.uid);
	        au.then(function(exists, data){

				if(!exists)
				{
					console.error("User " + args.uid + " was not found");
					throw "unknown uid";
				}

	            console.log("Consentua UID: ", args.uid, "API UserID: ", userid);
	        });
		}

        /**
         * In parallel, download the template
         */
         var gt = c.getTemplate(args.t);
         gt.done(function(template){
             console.log("Consentua template:", template);
         });

         /**
          * When the template and the user are both ready, load the interaction in the child iframe
          */
         $.when(gt, au).then(loadInteraction);
	 }); // End login callback
}

/**
 * Stage 2: Load the interaction and any existing user consents
 */
function loadInteraction(template, userid)
{
    // Interaction can be overridden by an argument from the calling page
    if(typeof args['ix'] !== 'undefined') {
		console.log("Interaction URL has been overridden by calling page", args[ix]);
        template.ixUrl = args['ix'];
    }
    else
	{
		template.ixUrl = template.InteractionUrl;
    }

    console.log("Template and user account are ready; loading interaction", template.ixUrl);

    // Load the interaction into the iframe
    $('#consentua-interaction').attr('src', template.ixUrl);

    /**
     * Wait for the interaction to load, then send it the template information
     */
    intcomms.addHandler('consentua-waiting', function(msg)
    {
       console.log("Interaction indicated that it is waiting for template");

       var user = {id: args.uid};

       // NB: Template info will already be loaded, so this should be quick
       var pTemplate = c.getTemplate(args['t']);

       // Check for existing user consents
       // TODO: Do this while the interaction itself loads? See above
       var pConsents = c.getConsents(args['uid']);

       // When the existing consents and the template are ready, give them to the interaction
       $.when(pTemplate, pConsents).then(function(template, consents){
          msg.reply({template: template, consents: consents, user: user});

					// Show the iframe and hide the loading indicator
					$('#loading').hide();
					$('#consentua-interaction').show();
       });
     });

    /**
     * Wait for interaction to be ready
     */
    intcomms.addHandler('consentua-ready', function(msg)
    {
        console.log("Interaction indicated to service that it is ready", msg);

        // Fit frame to interaction height
        var iframe = $('#consentua-interaction').get(0);
        var height = msg.message.height + 20;
        iframe.style.height = height + 'px';

        // Tell the embedding page, too
        wrapcomms.send('consentua-ready', {height: height, uid: args['uid']});
    });

    /**
     * Wait for consent to be set
     */
    intcomms.addHandler('consentua-set', function(msg)
    {
        console.log("Interaction sent updated consent", msg);

				// Look for additional information from the interaction to store with the consent record
				if(typeof msg.message.extra !== "object") {
					var extra = {};
				} else {
					var extra = msg.message.extra;
				}

				// Store our own metadata in there, too
				extra._ix = template.ixUrl;
				extra._ua = window.navigator.userAgent;
				extra._lang = lang;
				extra._url = window.location.href;

        // Save the consent settings
        c.setConsents(args['uid'], args['t'], msg.message.consents, extra);

        // Tell the customer site that the consent interaction is complete
        wrapcomms.send('consentua-set', {
          uid: args['uid'],
          consents: msg.message.consents,
          complete: msg.message.complete
        });
    });
}

login();

}); // End ready handler
