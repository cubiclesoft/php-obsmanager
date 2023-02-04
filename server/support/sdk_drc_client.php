<?php
	// Data Relay Center client PHP SDK.
	// (C) 2019 CubicleSoft.  All Rights Reserved.

	if (!class_exists("WebSocket", false))  require_once str_replace("\\", "/", dirname(__FILE__)) . "/websocket.php";

	class DRCClient extends WebSocket
	{
		protected $drc_channels, $drc_client_id;

		const CM_RECV_ONLY = 0;
		const CM_SEND_TO_AUTHS = 1;
		const CM_SEND_TO_ANY = 2;

		public function Reset()
		{
			parent::Reset();

			$this->drc_channels = array();
			$this->drc_client_id = false;
		}

		public function Read($finished = true, $wait = false)
		{
			$result = parent::Read();
			if (!$result["success"] || $result["data"] === false)  return $result;

			$result["data"] = @json_decode($result["data"]["payload"], true);

			if (!is_array($result["data"]))  $result["data"] = array("success" => false, "error" => "Invalid packet.", "errorcode" => "invalid_packet");
			else if (!$result["data"]["success"])  return $result;
			else if (isset($result["data"]["cmd"]) && $result["data"]["cmd"] === "JOINED")
			{
				if (isset($result["data"]["channelname"]) && isset($result["data"]["protocol"]) && isset($result["data"]["clients"]))
				{
					$this->drc_channels[$result["data"]["channel"]] = $result["data"];
					$this->drc_client_id = $result["data"]["id"];
				}
				else if (isset($this->drc_channels[$result["data"]["channel"]]))
				{
					$this->drc_channels[$result["data"]["channel"]]["clients"][$result["data"]["id"]] = $result["data"]["info"];
				}
			}
			else if (isset($result["data"]["cmd"]) && $result["data"]["cmd"] === "SET_EXTRA" && isset($this->drc_channels[$result["data"]["channel"]]) && isset($this->drc_channels[$result["data"]["channel"]]["clients"][$result["data"]["id"]]))
			{
				$this->drc_channels[$result["data"]["channel"]]["clients"][$result["data"]["id"]]["extra"] = $result["data"]["extra"];
			}
			else if (isset($result["data"]["cmd"]) && $result["data"]["cmd"] === "LEFT" && isset($this->drc_channels[$result["data"]["channel"]]))
			{
				if ($result["data"]["id"] === $this->drc_client_id)  unset($this->drc_channels[$result["data"]["channel"]]);
				else  unset($this->drc_channels[$result["data"]["channel"]]["clients"][$result["data"]["id"]]);
			}

			return $result;
		}

		public function CreateToken($authtoken, $channelname, $protocol, $clientmode, $extra = array(), $wait = false, $makeauth = false)
		{
			$data = array(
				"cmd" => "GRANT",
				"channel" => $channelname,
				"protocol" => $protocol,
				"clientmode" => $clientmode,
				"extra" => $extra
			);

			if ($authtoken !== false)  $data["token"] = $authtoken;
			if ($makeauth !== false)  $data["makeauth"] = true;

			$result = $this->Write(json_encode($data, JSON_UNESCAPED_SLASHES), WebSocket::FRAMETYPE_TEXT);
			if (!$result["success"])  return $result;

			if ($wait)
			{
				$ts = time();
				$result = ($wait === true ? $this->Wait() : $this->Wait(1));
				while ($result["success"])
				{
					do
					{
						$result = $this->Read();
						if (!$result["success"])  return $result;
						if ($result["data"] !== false && !$result["data"]["success"])  return $result["data"];

						if ($result["data"] !== false && isset($result["data"]["cmd"]) && $result["data"]["cmd"] === "GRANTED" && isset($result["data"]["channelname"]) && $result["data"]["channelname"] === $channelname && isset($result["data"]["protocol"]) && $result["data"]["protocol"] === $protocol && isset($result["data"]["token"]))
						{
							return $result;
						}
					} while ($result["data"] !== false);

					if ($wait !== true && $ts + $wait <= time())  return array("success" => false, "error" => "Timed out while creating a token.", "errorcode" => "create_token_timeout");

					$result = ($wait === true ? $this->Wait() : $this->Wait(1));
				}
			}

			return $result;
		}

		public function JoinChannel($channelname, $protocol, $token, $wait = false, $allowipauth = true)
		{
			$data = array(
				"cmd" => "JOIN",
				"channel" => $channelname,
				"protocol" => $protocol
			);

			if ($token !== false)  $data["token"] = $token;
			if ($allowipauth === false)  $data["ipauth"] = false;

			$result = $this->Write(json_encode($data, JSON_UNESCAPED_SLASHES), WebSocket::FRAMETYPE_TEXT);
			if (!$result["success"])  return $result;

			if ($wait)
			{
				$ts = time();
				$result = ($wait === true ? $this->Wait() : $this->Wait(1));
				while ($result["success"])
				{
					do
					{
						$result = $this->Read();
						if (!$result["success"])  return $result;
						if ($result["data"] !== false && !$result["data"]["success"])  return $result["data"];

						if ($result["data"] !== false && isset($result["data"]["cmd"]) && $result["data"]["cmd"] === "JOINED" && isset($result["data"]["channelname"]) && $result["data"]["channelname"] === $channelname && isset($result["data"]["protocol"]) && $result["data"]["protocol"] === $protocol && isset($result["data"]["clients"]))
						{
							return $result;
						}
					} while ($result["data"] !== false);

					if ($wait !== true && $ts + $wait <= time())  return array("success" => false, "error" => "Timed out while joining the channel.", "errorcode" => "join_channel_timeout");

					$result = ($wait === true ? $this->Wait() : $this->Wait(1));
				}
			}

			return $result;
		}

		public function GetChannels()
		{
			return $this->drc_channels;
		}

		public function GetChannel($channel)
		{
			return (isset($this->drc_channels[$channel]) ? $this->drc_channels[$channel] : false);
		}

		public function GetClientID()
		{
			return $this->drc_client_id;
		}

		public function SetExtra($channel, $id, $extra = array(), $wait = false)
		{
			$data = array(
				"channel" => $channel,
				"cmd" => "SET_EXTRA",
				"id" => $id,
				"extra" => $extra
			);

			$result = $this->Write(json_encode($data, JSON_UNESCAPED_SLASHES), WebSocket::FRAMETYPE_TEXT);
			if (!$result["success"])  return $result;

			if ($wait)
			{
				$ts = time();
				$result = ($wait === true ? $this->Wait() : $this->Wait(1));
				while ($result["success"])
				{
					do
					{
						$result = $this->Read();
						if (!$result["success"])  return $result;
						if ($result["data"] !== false && !$result["data"]["success"])  return $result["data"];

						if ($result["data"] !== false && isset($result["data"]["cmd"]) && $result["data"]["cmd"] === "SET_EXTRA" && $result["data"]["id"] === $id)  return $result;
					} while ($result["data"] !== false);

					if ($wait !== true && $ts + $wait <= time())  return array("success" => false, "error" => "Timed out while setting extra information.", "errorcode" => "set_extra_timeout");

					$result = ($wait === true ? $this->Wait() : $this->Wait(1));
				}
			}

			return $result;
		}

		public function SendCommand($channel, $cmd, $to, $options = array(), $wait = false, $waitcmd = false)
		{
			$data = array(
				"channel" => $channel,
				"cmd" => $cmd,
				"to" => $to
			);

			$data = $data + $options;

			$result = $this->Write(json_encode($data, JSON_UNESCAPED_SLASHES), WebSocket::FRAMETYPE_TEXT);
			if (!$result["success"])  return $result;

			if (is_string($waitcmd))
			{
				$ts = time();
				$result = ($wait === true ? $this->Wait() : $this->Wait(1));
				while ($result["success"])
				{
					do
					{
						$result = $this->Read();
						if (!$result["success"])  return $result;
						if ($result["data"] !== false && !$result["data"]["success"])  return $result["data"];

						if ($result["data"] !== false && isset($result["data"]["cmd"]) && $result["data"]["cmd"] === $waitcmd && ($to < 0 || $result["data"]["from"] === $to))  return $result;
					} while ($result["data"] !== false);

					if ($wait !== true && $ts + $wait <= time())  return array("success" => false, "error" => "Timed out while waiting for a response to a sent command.", "errorcode" => "send_command_timeout");

					$result = ($wait === true ? $this->Wait() : $this->Wait(1));
				}
			}
			else if ($wait)
			{
				$ts = time();
				do
				{
					if ($wait !== true && $ts + $wait <= time())  return array("success" => false, "error" => "Timed out while sending command.", "errorcode" => "send_command_timeout");

					$result = ($wait === true ? $this->Wait() : $this->Wait(1));
				} while ($result["success"] && $this->NeedsWrite());
			}

			return $result;
		}

		public function GetRandomAuthClientID($channel)
		{
			if (!isset($this->drc_channels[$channel]))  return false;

			$idmap = array();
			foreach ($this->drc_channels[$channel]["clients"] as $id => $info)
			{
				if ($info["auth"])  $idmap[] = $id;
			}

			$y = count($idmap);
			if (!$y)  return false;

			return $idmap[random_int(0, $y - 1)];
		}

		public function SendCommandToAuthClients($channel, $cmd, $options = array(), $wait = false)
		{
			if (!isset($this->drc_channels[$channel]))  return array("success" => false, "error" => "Invalid channel.", "errorcode" => "invalid_channel");

			foreach ($this->drc_channels[$channel]["clients"] as $id => $info)
			{
				if ($info["auth"])
				{
					$result = $this->SendCommand($channel, $cmd, $id, $options, $wait);
					if (!$result["success"])  return $result;
				}
			}

			return array("success" => true);
		}

		public function LeaveChannel($channel, $wait = false)
		{
			unset($this->drc_channels[$channel]);

			$data = array(
				"cmd" => "LEAVE",
				"channel" => $channel
			);

			$result = $this->Write(json_encode($data, JSON_UNESCAPED_SLASHES), WebSocket::FRAMETYPE_TEXT);
			if (!$result["success"])  return $result;

			if ($wait)
			{
				$ts = time();
				$result = ($wait === true ? $this->Wait() : $this->Wait(1));
				while ($result["success"])
				{
					do
					{
						$result = $this->Read();
						if (!$result["success"])  return $result;
						if ($result["data"] !== false && !$result["data"]["success"])  return $result["data"];

						if ($result["data"] !== false && isset($result["data"]["cmd"]) && $result["data"]["cmd"] === "LEFT" && !isset($this->drc_channels[$channel]))  return $result;
					} while ($result["data"] !== false);

					if ($wait !== true && $ts + $wait <= time())  return array("success" => false, "error" => "Timed out while leaving the channel.", "errorcode" => "leave_channel_timeout");

					$result = ($wait === true ? $this->Wait() : $this->Wait(1));
				}
			}

			return $result;
		}
	}
?>