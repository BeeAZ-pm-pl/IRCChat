<?php

declare(strict_types=1);

namespace IRCChat;

use pocketmine\thread\Thread;

class IRCChatClient extends Thread {

	public $msg;
	public $response;
	public $type;
	private $socket, $nickname, $password, $stop, $status, $channel;

	public function __construct($socket, $nickname, $password, $channel) {
		$this->stop = false;
		$this->msg = "";
		$this->response = "";
		$this->type = 0;
		$this->socket = $socket;
		$this->nickname = $nickname;
		$this->password = $password === "" ? false : $password;
		$this->channel = $channel;
		$this->status = 0;
		$this->start();
	}

	private function notification($msg, $type = 0) {
		$this->type = (int) $type;
		$this->msg = $msg;
		$this->wait();
		return $this->response;
	}

	public function run() {
		$connect = "";
		if ($this->password !== false) {
			$connect .= "PASS " . $this->password . "\r\n";
		}
		$connect .= "NICK " . $this->nickname . "\r\n";
		$connect .= "USER PMIRCChat a a :" . $this->nickname . " @ PocketMine-MP IRCChat plugin\r\n";

		socket_write($this->socket, $connect);
		$host = "";
		while (true) {
			$txt = socket_read($this->socket, 65535);
			if ($txt != "") {
				$txt = explode("\r\n", $txt);
				foreach ($txt as $line) {
					if (trim($line) == "") {
						continue;
					}
					$line = explode(" ", $line);
					$cmd = array_shift($line);
					$sender = "";
					if ($cmd[0] == ":") {
						$end = strpos($cmd, "!");
						if ($end === false) {
							$end = strlen($cmd);
						}
						$sender = substr($cmd, 1, $end - 1);
						if ($host === "") {
							$host = $sender;
						}
						$cmd = array_shift($line);
					}
					$msg = implode(" ", $line);
					switch (strtoupper($cmd)) {
						case "JOIN":
							// Undefined variable '$from'
							if ($from === $this->nickname) {
								$this->notification("[INFO] [IRCChat] Joined channel $msg", 0);
							} else {
								$this->notification(":$sender joined $msg", 1);
							}
							break;
						case "332": //Topic
							array_shift($line);
							$from = array_shift($line);
							$mes = substr($msg, strpos($msg, ":") + 1);
							$this->notification("[INFO] [IRCChat] $from topic: $mes", 0);
							break;
						case "QUIT":
						case "PART":
							$this->notification(":$sender left the channel", 1);
							break;
						case "MODE":
							$this->status = 1;
							$this->notification("[INFO] [IRCChat] Mode $msg", 0);
							break;
						case "PING":
							socket_write($this->socket, "PONG " . $msg . "\r\n");
							break;
						case "NOTICE":
							break;
						case "PRIVMSG":
							$from = array_shift($line);
							$mes = substr($msg, strpos($msg, ":") + 1);
							if ($mes[0] === "\x01") {
								$mes = str_replace(array("\x01", "ACTION "), array("", "*** "), $mes);
							}
							if ($from[0] === "#") {
								$this->notification("<" . $from . ":$sender> $mes", 1);
							} elseif ($from === $this->nickname) {
								$this->notification(Utils::writeShort(strlen($sender)) . $sender . $mes, 2);
							}
							break;
						default:
							break;
					}
					if ($this->status === 1) {
						socket_write($this->socket, "JOIN " . $this->channel . "\r\n");
						$this->status = 2;
					}
				}
			}
			usleep(1);
		}
	}
}
