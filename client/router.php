<?php
	// OBS Manager PHP client.
	// (C) 2022 CubicleSoft.  All Rights Reserved.

	if (!isset($_SERVER["argc"]) || !$_SERVER["argc"])
	{
		echo "This file is intended to be run from the command-line.";

		exit();
	}

	// Temporary root.
	$rootpath = str_replace("\\", "/", dirname(__FILE__));

	require_once $rootpath . "/support/cli.php";

	// Process the command-line options.
	$options = array(
		"shortmap" => array(
			"h" => "host",
			"p" => "password",
			"?" => "help"
		),
		"rules" => array(
			"host" => array("arg" => true),
			"password" => array("arg" => true),
			"help" => array("arg" => false)
		),
		"allow_opts_after_param" => false
	);
	$args = CLI::ParseCommandLine($options);

	if (isset($args["opts"]["help"]) || count($args["params"]) < 2)
	{
		echo "The OBS Manager client\n";
		echo "Purpose:  Connect to a remote OBS Manager instance and Palakis obs-websocket from the command-line.\n";
		echo "\n";
		echo "Syntax:  " . $args["file"] . " [options] OBSManagerURL LocationName\n";
		echo "Options:\n";
		echo "\t-h   Host and port to connect to (Default is \"ws://127.0.0.1:4455/\").\n";
		echo "\t-p   Password for obs-websocket.\n";
		echo "\n";
		echo "Examples:\n";
		echo "\tphp " . $args["file"] . "\n";
		echo "\tphp " . $args["file"] . " -p supersecret https://yourdomain.com/obsmanager/ Home\n";

		exit();
	}

	$host = (isset($args["opts"]["host"]) ? $args["opts"]["host"] : "ws://127.0.0.1:4455/");

	require_once $rootpath . "/support/web_browser.php";
	require_once $rootpath . "/support/websocket.php";
	require_once $rootpath . "/support/sdk_drc_client.php";

	// Connect to OBS.
	// OBS Websocket Protocol specification:  https://github.com/obsproject/obs-websocket/blob/master/docs/generated/protocol.md
	echo "Initializing...\n";
	$ws = new WebSocket();
	$result = $ws->Connect($host, "http://127.0.0.1");
	if (!$result["success"])  CLI::DisplayError("Unable to connect to '" . $host . "'.", $result);

	$nextobsmsg = 1;
	$obsmessages = array();
	$obsstate = "connect";

	function SendOBSRequest($type, $data = array(), $drcclient = false)
	{
		global $nextobsmsg, $ws, $obsmessages;

		$reqid = "router-" . $nextobsmsg;
		$nextobsmsg++;

		// Request (Opcode 6).
		$payload = array(
			"op" => 6,
			"d" => array(
				"requestType" => $type,
				"requestId" => $reqid,
				"requestData" => $data
			)
		);

		$result = $ws->Write(json_encode($payload, JSON_UNESCAPED_SLASHES), WebSocket::FRAMETYPE_TEXT);
		if (!$result["success"])  return $result;

		$obsmessages[$reqid] = array("type" => $type, "reqid" => $reqid, "origmsg" => $data, "drcclient" => $drcclient);

		return array("success" => true);
	}

	// Get the DRC connection and token information.
	$web = new WebBrowser();

	$url = $args["params"][0];
	if (substr($url, -1) != "/")  $url .= "/";
	$url .= "?api=init&location=" . urlencode($args["params"][1]);
	$result = $web->Process($url);
	if (!$result["success"])  CLI::DisplayError("Unable to retrieve '" . $url . "'.", $result);

	$drcinfo = json_decode($result["body"], true);
	if (!is_array($drcinfo))  CLI::DisplayError("Expected a JSON response from the OBS Manager.", $result);
	if (!$drcinfo["success"])  CLI::DisplayError("A failure occurred while attempting to get access to the remote OBS Manager.", $drcinfo);
	if ($drcinfo["data"]["protocol"] !== "obs-websocket-remote-api")  CLI::DisplayError("The server returned an unknown/unsupported DRC protocol.", $result);

	$drc = false;
	$drcstate = "connect";

	echo "Ready.\n";

	do
	{
		if ($drc === false)
		{
			$drc = new DRCClient();

			// Connect to the DRC server.
			$result = $drc->Connect($drcinfo["drc_url"], $drcinfo["drc_origin"]);
			if (!$result["success"])
			{
				CLI::DisplayError("Unable to connect to the DRC server.", $result, false);

				$drc = false;
			}
			else
			{
				// Join the channel.
				$result = $drc->JoinChannel($drcinfo["data"]["channelname"], $drcinfo["data"]["protocol"], $drcinfo["data"]["token"], true);
				if (!$result["success"])  CLI::DisplayError("Unable to join the DRC channel '" . $drcinfo["data"]["channelname"] . "'.", $result);

				$drcchannelinfo = $result["data"];

				echo "Joined DRC channel " . $drcchannelinfo["channel"] . " as client ID " . $drcchannelinfo["id"] . ".\n";
				echo "Channel name:  " . $drcinfo["user_channel"] . "\n";
				echo "Users may join via:  " . $drcinfo["user_url"] . "\n";
			}
		}

		// Implement stream_select() directly since multiple clients are involved.
		$timeout = 30;
		$readfps = array();
		$writefps = array();
		$exceptfps = NULL;

		$fp = $ws->GetStream();
		$readfps[] = $fp;
		if ($ws->NeedsWrite())  $writefps[] = $fp;

		if ($drc === false)  $timeout = 3;
		else
		{
			$fp = $drc->GetStream();
			$readfps[] = $fp;
			if ($drc->NeedsWrite())  $writefps[] = $fp;
		}

		$result = @stream_select($readfps, $writefps, $exceptfps, $timeout);
		if ($result === false)  break;

		// Process WebSocket data.
		$result = $ws->Wait(0);
		if (!$result["success"])  CLI::DisplayError("WebSocket connection failure.", $result);

		do
		{
			$result = $ws->Read();
			if (!$result["success"])  CLI::DisplayError("WebSocket connection failure.", $result);

			if ($result["data"] !== false)
			{
//				echo "Raw message from server:\n";
//				var_dump($result["data"]);
//				echo "\n";

				$data = json_decode($result["data"]["payload"], true);
				if ($obsstate === "connect")
				{
					if ($data["op"] == 0)
					{
						// Identify (Opcode 1).
						$payload = array(
							"op" => 1,
							"d" => array(
								"rpcVersion" => 1
							)
						);

						if (isset($data["d"]["authentication"]))
						{
							if (!isset($args["opts"]["password"]))  CLI::DisplayError("The OBS websocket host '" . $host . "' requires a password.");

							echo "Authenticating with OBS...\n";
							$hash = base64_encode(hash("sha256", base64_encode(hash("sha256", $args["opts"]["password"] . $data["d"]["authentication"]["salt"], true)) . $data["d"]["authentication"]["challenge"], true));

							$payload["d"]["authentication"] = $hash;
						}

						$result2 = $ws->Write(json_encode($payload, JSON_UNESCAPED_SLASHES), WebSocket::FRAMETYPE_TEXT);
						if (!$result2["success"])  return $result2;

						$obsstate = "identify";
					}
					else
					{
						echo "Expected Hello (Opcode 0).  Received unknown message:\n";
						var_dump($data);
						echo "\n";
					}
				}
				else if ($obsstate === "identify")
				{
					if ($data["op"] == 2)
					{
						$obsstate = "main";
					}
					else
					{
						echo "Expected Identified (Opcode 2).  Received unknown message:\n";
						var_dump($data);
						echo "\n";
					}
				}
				else if ($obsstate === "main")
				{
					// RequestResponse (Opcode 7).
					if ($data["op"] == 7)
					{
						if (!isset($data["d"]["requestId"]) || !isset($obsmessages[$data["d"]["requestId"]]))
						{
							echo "Unknown message:\n";
							var_dump($data);
							echo "\n";
						}
						else
						{
							$origreq = $obsmessages[$data["d"]["requestId"]];
							unset($obsmessages[$data["d"]["requestId"]]);

							// Send the results back to the client.
							unset($data["d"]["requestId"]);
							if ($drc !== false)
							{
								$result2 = $drc->SendCommand($origreq["drcclient"]["channel"], $origreq["drcclient"]["cmd"], $origreq["drcclient"]["from"], array("result" => $data["d"]));
								if (!$result2["success"])  CLI::DisplayError("Unable to send response to the request.", $result2, false);
							}
						}
					}
				}


/*
				if (!isset($data["message-id"]) || !isset($obsmessages[$data["message-id"]]))
				{
					// Skip all events.
					if (!isset($data["update-type"]))
					{
						echo "Unknown message:\n";
						var_dump($data);
						echo "\n";
					}
				}
				else
				{
					$origreq = $obsmessages[$data["message-id"]];
					unset($obsmessages[$data["message-id"]]);

					if ($origreq["type"] === "GetAuthRequired")
					{
						// Send password if required.
						if ($data["authRequired"])
						{
							if (!isset($args["opts"]["password"]))  CLI::DisplayError("The OBS websocket host '" . $host . "' requires a password.");

							echo "Authenticating with OBS...\n";
							$hash = base64_encode(hash("sha256", base64_encode(hash("sha256", $args["opts"]["password"] . $data["salt"], true)) . $data["challenge"], true));

							SendOBSMessage("Authenticate", array("auth" => $hash));
						}
					}
					else if ($origreq["type"] === "Authenticate")
					{
						if ($data["status"] !== "ok")  CLI::DisplayError("Unable to authenticate with OBS websocket.  Incorrect password?  " . $data["error"]);
						else  echo "Authentication successful.\n";
					}
					else if (isset($origreq["drcclient"]))
					{
						// Send the results back to the client.
						unset($data["message-id"]);
						if ($drc !== false)
						{
							$result2 = $drc->SendCommand($origreq["drcclient"]["channel"], $origreq["drcclient"]["cmd"], $origreq["drcclient"]["from"], array("result" => $data));
							if (!$result2["success"])  CLI::DisplayError("Unable to send response to the request.", $result2, false);
						}
					}
					else
					{
echo "Other!\n";
var_dump($origreq);
var_dump($data);
					}
				}
*/
			}
		} while ($result["data"] !== false);


		// Process DRC channel data.
		if ($drc !== false)
		{
			$result = $drc->Wait(0);
			if (!$result["success"])
			{
				CLI::DisplayError("DRC connection failure.", $result, false);

				$drc = false;
			}
			else
			{
				do
				{
					$result = $drc->Read();
					if (!$result["success"])
					{
						CLI::DisplayError("DRC connection failure.", $result, false);

						$drc = false;

						break;
					}

					if ($result["data"] !== false)
					{
						$data = $result["data"];

						if (strncmp($data["cmd"], "OBS-", 4) == 0 && $data["cmd"] !== "OBS-GetAuthRequired" && $data["cmd"] !== "OBS-Authenticate")
						{
							$data2 = $data;
							unset($data2["channel"]);
							unset($data2["success"]);
							unset($data2["from"]);
							unset($data2["cmd"]);

							echo "Client " . $data["from"] . " requested " . substr($data["cmd"], 4) . "." . (count($data2) ? "\n  " . json_encode($data2, JSON_UNESCAPED_SLASHES) : "") . "\n";

							SendOBSRequest(substr($data["cmd"], 4), $data2, $data);
						}
					}
				} while ($result["data"] !== false);
			}
		}

	} while (1);
?>