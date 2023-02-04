<?php
	// Admin Pack.
	// (C) 2022 CubicleSoft.  All Rights Reserved.

	// This small package exists to make it easy to design quick-n-dirty administrative backends that look good.
	// This file is well-commented.  When republishing based on this work, copyrights must remain intact.

	require_once "support/str_basics.php";
	require_once "support/page_basics.php";

	Str::ProcessAllInput();

	// Switch to SSL.
	if (!BB_IsSSLRequest())
	{
		header("Location: " . BB_GetFullRequestURLBase("https"));
		exit();
	}

	// $bb_randpage is used in combination with a user token to prevent hackers from sending malicious URLs.
	$bb_randpage = "ae87b6184b9b5f1c84287d5a0620998095d7e1f4";
	$bb_rootname = "OBS Remote Manager";

	$rootpath = str_replace("\\", "/", dirname(__FILE__));

	require_once "config.php";
	require_once "support/random.php";
	require_once "support/sdk_drc_client.php";

	function OutputAPIResult($result)
	{
		header("Content-Type: application/json");

		echo json_encode($result, JSON_UNESCAPED_SLASHES);

		exit();
	}

	function OutputAPIError($msg, $msgcode, $info = false)
	{
		http_response_code(400);

		$result = array(
			"success" => false,
			"error" => $msg,
			"errorcode" => $msgcode
		);

		if ($info !== false)  $result["info"] = $info;

		OutputAPIResult($result);
	}

	// Handle API calls.
	if (isset($_REQUEST["api"]))
	{
		if ($_REQUEST["api"] == "init")
		{
			if (!isset($_REQUEST["location"]) || !is_string($_REQUEST["location"]))  OutputAPIError("Missing or invalid 'location'.", "missing_location");

			$rng = new CSPRNG();

			$freqmap = json_decode(file_get_contents("support/en_us_lite.json"), true);

			$words = array();
			for ($x = 0; $x < 3; $x++)  $words[] = $rng->GenerateWordLite($freqmap, $rng->GetInt(4, 5));
			$words = implode("-", $words);
			$channelname = "OBSManager-" . $words;

			$drc = new DRCClient();

			// Connect to the server.
			$result = $drc->Connect($drc_private_url, $drc_private_origin);
			if (!$result["success"])  OutputAPIError("Unable to connect to DRC.", "drc_connect_failed", $result);

			// Create a grant token.
			$result = $drc->CreateToken(false, $channelname, "obs-websocket-remote-api", DRCClient::CM_SEND_TO_ANY, array("location" => $_REQUEST["location"]), 10, true);
			if (!$result["success"])  OutputAPIError("Unable to create a DRC channel token.", "drc_create_token_failed", $result);

			$result["user_channel"] = $words;
			$result["user_url"] = BB_GetFullRequestURLBase() . "?channel=" . urlencode($words);
			$result["drc_url"] = $drc_public_url;
			$result["drc_origin"] = $drc_public_origin;

			OutputAPIResult($result);
		}
		else
		{
			OutputAPIError("Unknown API '" . $_REQUEST["api"] . "'.", "invalid_api");
		}
	}

	// Establish a regular session.
	session_start();

	if (!isset($_SESSION["ga_usertoken"]))
	{
		$rng = new CSPRNG();

		$_SESSION["ga_usertoken"] = $rng->GenerateToken(64);
	}

	$bb_usertoken = $_SESSION["ga_usertoken"];


	BB_ProcessPageToken("action");

	// Menu/Navigation options.
	$menuopts = array(
	);

	// Optional function to customize styles.
	function BB_InjectLayoutHead()
	{
		// Menu title underline:  Colors with 60% saturation and 75% brightness generally look good.
?>
<style type="text/css">
#menuwrap .menu .title { border-bottom: 2px solid #C48851; }
</style>
<?php

		// Keep PHP sessions alive.
		if (session_status() === PHP_SESSION_ACTIVE)
		{
?>
<script type="text/javascript">
setInterval(function() {
	jQuery.post('<?=BB_GetRequestURLBase()?>', {
		'action': 'heartbeat',
		'sec_t': '<?=BB_CreateSecurityToken("heartbeat")?>'
	});
}, 5 * 60 * 1000);
</script>
<?php
		}
	}

	if (isset($_REQUEST["action"]) && $_REQUEST["action"] == "heartbeat")
	{
		$_SESSION["lastts"] = time();

		echo "OK";

		exit();
	}

	session_write_close();

	if (isset($_REQUEST["action"]) && $_REQUEST["action"] == "activatescene")
	{
		if (!isset($_REQUEST["channel"]) || !is_string($_REQUEST["channel"]) || $_REQUEST["channel"] === "")  OutputAPIError("Missing 'channel'.", "missing_channel");
		if (!isset($_REQUEST["scene"]) || !is_string($_REQUEST["scene"]) || $_REQUEST["scene"] === "")  OutputAPIError("Missing 'scene'.", "missing_scene");

		$channelname = "OBSManager-" . $_REQUEST["channel"];

		$drc = new DRCClient();

		// Connect to the server.
		$result = $drc->Connect($drc_private_url, $drc_private_origin);
		if (!$result["success"])  OutputAPIError("Unable to connect to DRC.", "drc_connect_failed", $result);

		// Create a grant token.
		$result = $drc->CreateToken(false, $channelname, "obs-websocket-remote-api", DRCClient::CM_SEND_TO_AUTHS, array(), 10);
		if (!$result["success"])  OutputAPIError("Unable to create a DRC channel token.", "drc_create_token_failed", $result);

		$token = $result["data"]["token"];

		// Join the channel.
		$result = $drc->JoinChannel($channelname, "obs-websocket-remote-api", $token, 10, false);
		if (!$result["success"])  OutputAPIError("Unable to join DRC channel.", "drc_join_channel_failed", $result);

		$channelinfo = $result["data"];
		$channelid = $channelinfo["channel"];

		// Verify that an authority client is available.
		$authclientid = $drc->GetRandomAuthClientID($channelid);
		if ($authclientid === false)  OutputAPIError("No authorities are available.  Is OBS running?", "no_authorities");

		// Activate the scene.
		$result = $drc->SendCommand($channelid, "OBS-SetCurrentProgramScene", $authclientid, array("sceneName" => $_REQUEST["scene"]), 10, "OBS-SetCurrentProgramScene");
		if (!$result["success"])  OutputAPIError("Failed to activate the scene.", "obs_setcurrentscene_failed", $result);

		if (!$result["data"]["result"]["requestStatus"]["result"])  OutputAPIError("Failed to activate the scene.", "obs_setcurrentprogramscene_failed", $result);

		$result = array(
			"success" => true
		);

		OutputAPIResult($result);
	}

	if (isset($_REQUEST["action"]) && $_REQUEST["action"] == "managechannel" && isset($_REQUEST["channel"]) && is_string($_REQUEST["channel"]) && $_REQUEST["channel"] !== "")
	{
		$location = "";
		$scenes = array();
		$channelname = "OBSManager-" . $_REQUEST["channel"];

		$drc = new DRCClient();

		// Connect to the server.
		$result = $drc->Connect($drc_private_url, $drc_private_origin);
		if (!$result["success"])  BB_SetPageMessage("error", "Unable to connect to DRC.  " . $result["error"] . " (" . $result["errorcode"] . ")");
		else
		{
			// Create a grant token.
			$result = $drc->CreateToken(false, $channelname, "obs-websocket-remote-api", DRCClient::CM_SEND_TO_AUTHS, array(), 10);
			if (!$result["success"])  BB_SetPageMessage("error", "Unable to create a DRC channel token.  " . $result["error"] . " (" . $result["errorcode"] . ")");
			else
			{
				$token = $result["data"]["token"];

				// Join the channel.
				$result = $drc->JoinChannel($channelname, "obs-websocket-remote-api", $token, 10, false);
				if (!$result["success"])  BB_SetPageMessage("error", "Unable to join DRC channel.  " . $result["error"] . " (" . $result["errorcode"] . ")");
				else
				{
					$channelinfo = $result["data"];
					$channelid = $channelinfo["channel"];

					// Verify that an authority client is available.
					$authclientid = $drc->GetRandomAuthClientID($channelid);
					if ($authclientid === false)  BB_SetPageMessage("error", "No authorities are available.  Is OBS running?");
					else
					{
						// Get the scene list.
						if (isset($channelinfo["clients"][$authclientid]["extra"]["location"]))  $location = $channelinfo["clients"][$authclientid]["extra"]["location"];

						$result = $drc->SendCommand($channelid, "OBS-GetSceneList", $authclientid, array(), 10, "OBS-GetSceneList");
						if (!$result["success"])  BB_SetPageMessage("error", "Unable to request scene list.  " . $result["error"] . " (" . $result["errorcode"] . ")");
						else if (!$result["data"]["result"]["requestStatus"]["result"])  BB_SetPageMessage("error", "Request for the scene list resulted in an error.  " . $result["data"]["result"]["requestStatus"]["comment"]);
						else
						{
							$scenes2 = array_reverse($result["data"]["result"]["responseData"]["scenes"]);

							foreach ($scenes2 as $scene)
							{
								$scenes[$scene["sceneName"]] = "<a class=\"scenebutton\" href=\"#\" onclick=\"return ActivateScene(this);\">" . htmlspecialchars($scene["sceneName"]) . "</a>";
							}
						}
					}
				}
			}
		}

		$desc = "<br>";
		ob_start();
?>
<style type="text/css">
.scenebuttons { margin-top: 2em; }
a.scenebutton { display: block; margin-top: 1em; border: 1px solid #CCCCCC; padding: 1em; line-height: 1.1; color: #333333; }
a.scenebutton:hover, a.scenebutton:focus { text-decoration: none; border: 1px solid #888888; }
</style>

<script type="text/javascript">
function ActivateScene(obj)
{
	$.ajax({
		url: '<?=BB_GetRequestURLBase()?>',
		method: 'post',
		data: {
			action: 'activatescene',
			channel: '<?=BB_JSSafe($_REQUEST["channel"])?>',
			scene: $(obj).text(),
			sec_t: '<?=BB_CreateSecurityToken("activatescene")?>'
		}
	});

	return false;
}
</script>
<?php
		$desc .= ob_get_contents();
		ob_end_clean();
		$desc .= "<div class=\"scenebuttons\">\n" . implode("\n", $scenes) . "\n</div>";

		$contentopts = array(
			"desc" => "Select the current OBS scene" . ($location !== "" ? " for " . $location : "") . ".",
			"htmldesc" => $desc
		);

		BB_GeneratePage("Manage OBS Channel", array(), $contentopts);
	}

	if (isset($_REQUEST["channel"]))
	{
		if ($_REQUEST["channel"] == "")  BB_SetPageMessage("error", "Please fill in 'Channel Name'.", "channel");

		if (BB_GetPageMessageType() != "error")
		{
			// Redirect to the channel.
			BB_RedirectPage("", "", array("action=managechannel&channel=" . urlencode($_REQUEST["channel"]) . "&sec_t=" . BB_CreateSecurityToken("managechannel")));
		}
	}

	$contentopts = array(
		"desc" => "Fill in the field below to remotely manage OBS.",
		"fields" => array(
			array(
				"title" => "Channel Name",
				"type" => "text",
				"name" => "channel",
				"default" => ""
			),
		),
		"submit" => "Go"
	);

	BB_GeneratePage("Select Channel", array(), $contentopts);
?>