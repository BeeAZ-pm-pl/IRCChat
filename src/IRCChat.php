<?php

declare(strict_types=1);

namespace IRCChat;

use pocketmine\utils\Config;
use pocketmine\player\Player;
use pocketmine\event\Listener;
use pocketmine\plugin\PluginBase;
use IRCChat\IRCChatClient;

class IRCChat extends PluginBase implements Listener {

	private $config, $api, $socket, $thread;

	public function __construct(ServerAPI $api, $server = false) {
		$this->api = $api;
	}

	protected function onEnable(): void {
		$this->getServer()->getPluginManager()->registerEvents($this, $this);
		$this->saveDefaultConfig();
		$this->config = new Config($this->getDataFolder() . "config.yml", Config::YAML, [
			"server" => "chat.freenode.net",
			"port" => 19132,
			"nickname" => "YourNicknameHere",
			"password" => "",
			"channel" => "#example,#example2",
			"authpassword" => substr(base64_encode(random_bytes(20)), 3, 8) //To use in IRC
		]);

		$this->workers = array();

		// $this->getServer()->getLogger()->info("Starting IRCChat");
		$this->socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
		socket_set_option($this->socket, SOL_SOCKET, SO_KEEPALIVE, 1);
		if ($this->socket === false or !socket_connect($this->socket, $this->config->get("server"), (int) $this->config->get("port"))) {
			$this->getServer()->getLogger()->error("IRCChat can't be started: " . socket_strerror(socket_last_error()));
			return;
		}
		socket_getpeername($this->socket, $addr, $port);
		socket_set_nonblock($this->socket);
		$this->thread = new IRCChatClient($this->socket, $this->config->get("nickname"), $this->config->get("password"), $this->config->get("channel"));
		$this->api->console->register("irc", "<message ...>", array($this, "commandHandler"));
		$this->getServer()->getLogger()->info("IRCChat connected to /$addr:$port");
		$this->api->schedule(2, array($this, "check"), array(), true);
		$this->api->addHandler("server.chat", array($this, "sendMessage"));
		$this->api->event("player.join", array($this, "eventHandler"));
	}

	public function commandHandler($cmd, $params, $issuer, $alias) {
		$output = "";
		switch ($cmd) {
			case "irc":
				if ($params[0] === "/") {
					$command = strtolower(substr(array_shift($params), 1));
					switch ($command) {
						case "tell":
						case "msg":
							socket_write($this->socket, "PRIVMSG " . array_shift($params) . " :" . implode(" ", $params) . "\r\n");
							break;
						case "list":
							socket_write($this->socket, "LIST " . array_shift($params) . "\r\n");
							break;
						case "me":
							socket_write($this->socket, "PRIVMSG " . $this->config->get("channel") . " :\x01ACTION " . implode(" ", $params) . "\x01\r\n");
							break;
						case "join":
							socket_write($this->socket, "JOIN " . array_shift($params) . "\r\n");
							break;
					}
				} else {
					$mes = implode(" ", $params);
					socket_write($this->socket, "PRIVMSG " . $this->config->get("channel") . " :" . $mes . "\r\n");
					$this->api->chat->send(false, "<" . $this->config->get("channel") . ":" . $this->config->get("nickname") . "> $mes", false, array("IRCChat", "ircchat"));
				}
				break;
		}
		return $output;
	}

	public function eventHandler($data, $event) {
		switch ($event) {
			case "player.join":
				socket_write($this->socket, "PRIVMSG " . $this->config->get("channel") . " :" . $data->username . " joined the game\r\n");
				break;
		}
	}

	public function sendMessage($data, $event) {
		if ($data->check("IRCChat") or $data->check("ircchat")) {
			$mes = $data->get();
			if (is_array($mes)) {
				$m = preg_replace('/\x1b\[[0-9;]*m/', "", $mes["message"]);
				if ($mes["player"] instanceof Player) {
					$m  = "<" . $mes["player"]->username . "> " . $m;
				} else {
					$m  = "<" . $mes["player"] . "> " . $m;
				}
			} else {
				$m = preg_replace('/\x1b\[[0-9;]*m/', "", $mes);
			}
			socket_write($this->socket, "PRIVMSG " . $this->config->get("channel") . " : " . $m . "\r\n");
		}
	}

	protected function onDisable(): void {
		$this->thread->stop = true;
		$this->thread->notify();
		$this->thread->join();
		@socket_close($this->socket);
	}

	public function check() {
		if ($this->thread->isWaiting()) {
			switch ($this->thread->type) {
				case 0:
					console($this->thread->msg);
					break;
				case 1:
					$this->api->chat->send(false, $this->thread->msg, false, array("IRCChat", "ircchat"));
					break;
				case 2:
					$len = Utils::readShort(substr($this->thread->msg, 0, 2));
					$owner = substr($this->thread->msg, 2, $len);
					$cmd = explode(" ", substr($this->thread->msg, 2 + $len));
					if (strtolower($cmd[0]) === "pass") {
						array_shift($cmd);
						$pass = array_shift($cmd);
						if ($pass != $this->config->get("authpassword")) {
							break;
						}
						$m = preg_replace('/\x1b\[[0-9;]*m/', "", $this->api->console->run(implode(" ", $cmd), "console"));
					} else {
						$m = preg_replace('/\x1b\[[0-9;]*m/', "", $this->api->console->run(implode(" ", $cmd), ":$owner"));
					}
					foreach (explode("\n", $m) as $l) {
						if ($l != "") {
							socket_write($this->socket, "PRIVMSG $owner : " . trim($l) . "\r\n");
						}
					}
					break;
			}
			$this->thread->notify();
		}
	}
}
