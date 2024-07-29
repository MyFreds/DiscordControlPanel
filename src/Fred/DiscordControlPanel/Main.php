<?php

namespace Fred\DiscordControlPanel;

use pocketmine\utils\Config;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerLoginEvent;
use pocketmine\player\Player;
use pocketmine\plugin\PluginBase;
use pocketmine\plugin\PluginException;
use pocketmine\Server;

use JaxkDev\DiscordBot\Models\Messages\Embed\Embed;
use JaxkDev\DiscordBot\Models\Messages\Embed\Field;
use JaxkDev\DiscordBot\Models\Messages\Embed\Footer;
use JaxkDev\DiscordBot\Plugin\Events\MessageSent;
use JaxkDev\DiscordBot\Plugin\Api;
use JaxkDev\DiscordBot\Plugin\Main as DiscordBot;
use JaxkDev\DiscordBot\Plugin\ApiRejection;

class Main extends PluginBase implements Listener {

  public DiscordBot $discord;
  public Config $config;
  
  public function onEnable() : void {
    $this->saveDefaultConfig();
    $discord = $this->getServer()->getPluginManager()->getPlugin("DiscordBot");
    if (!$discord instanceof DiscordBot) {
      throw new PluginException("Incompatible dependency 'DiscordBot' detected, see https://github.com/DiscordBot-PMMP/DiscordBot/releases for the correct plugin.");
    }
    $this->discord = $discord;
    $this->config = new Config($this->getDataFolder() . "config.yml", Config::YAML, [
      "ServerId" => 1234567890,
      "CommandList" => "!list",
      "CommandListAllowedChannel" => [1063456789101112],
      "CommandBan" => "!ban",
      "CommandBanAllowedChannel" => [115678910111213, 1063456789101112],
      "CommandUnban" => "!unban",
      "CommandUnbanAllowedChannel" => [115678910111213, 1063456789101112],
      "CommandKick" => "!kick",
      "CommandKickAllowedChannel" => [115678910111213, 1063456789101112],
      "CommandSay" => "!say",
      "CommandSayAllowedChannel" => [115678910111213, 1063456789101112]
    ]);
    $this->getServer()->getPluginManager()->registerEvents($this, $this);
  }

  public function banPlayer(string $playerName): void {
    $file = $this->getDataFolder() . "banned-players.txt";
    file_put_contents($file, $playerName . PHP_EOL, FILE_APPEND);
  }

  public function unbanPlayer(string $playerName): void {
    $file = $this->getDataFolder() . "banned-players.txt";
    $lines = file($file, FILE_IGNORE_NEW_LINES);
    $lines = array_filter($lines, fn($line) => trim($line) !== $playerName);
    file_put_contents($file, implode(PHP_EOL, $lines) . PHP_EOL);
  }
  
  public function isPlayerBanned(string $playerName): bool {
    $file = $this->getDataFolder() . "banned-players.txt";
    $lines = file($file, FILE_IGNORE_NEW_LINES);
    return in_array($playerName, $lines);
  }
  
  public function MessageSent(MessageSent $event) {
    $api = $this->discord->getApi();
    $message = $event->getMessage();
    $content = $message->getContent();
    $channel_id = $message->getChannelId();
    $args = explode(" ", $content);
    $args[0] ??= "";

    $server_id = $this->config->get("ServerId", 1234567890);
    if ($server_id == 1234567890) {
      $this->getLogger()->info("§cPlease add discord server id in config.yml");
      $this->getServer()->getPluginManager()->disablePlugin($this);
      return false;
    }
      
    $isCommandAllowed = function (string $command, string $channel_id): bool {
      $allowed_channels = $this->config->get($command . "AllowedChannel", []);
      return in_array($channel_id, $allowed_channels);
    };

    $getAllowedChannelsString = function (string $command): string {
      $allowed_channels = $this->config->get($command . "AllowedChannel", []);
      return implode(", ", array_map(fn($id) => "<#$id>", $allowed_channels));
    };

    // HANDLE !list COMMAND
    if ($args[0] == $this->config->get("CommandList", "!list")) {
      if ($isCommandAllowed("CommandList", $channel_id)) {
        $onlines = $this->getServer()->getOnlinePlayers();
        $players = implode("\n", array_map(fn(Player $player) => $player->getName(), $onlines));
        $api->sendMessage($server_id, $channel_id, null, $message->getId(), [new Embed(
          "List Players",
          count($onlines) === 0 ? "There are no players in the server" : count($onlines) . "/" . $this->getServer()->getMaxPlayers(),
          null, time(), null,
          new Footer("List Players v" . $this->getDescription()->getVersion()),
          null, null, null, null, null,
          count($onlines) === 0 ? [] : [new Field("Players", $players, true)]
        )])->otherwise(function(ApiRejection $rejection){
          $this->getLogger()->error("Failed to send command response: " . $rejection->getMessage());
        });
      } else {
        $api->sendMessage($server_id, $channel_id, "Commands `!list` can only be executed on channels " . $getAllowedChannelsString("CommandList"));
      }
    }

    // HANDLE !ban COMMAND
    if ($args[0] == $this->config->get("CommandBan", "!ban")) {
      if ($isCommandAllowed("CommandBan", $channel_id)) {
        if (isset($args[1])) {
          $playerName = $args[1];
          $player = $this->getServer()->getPlayerExact($playerName);
          $this->banPlayer($playerName);
          if ($player instanceof Player) {
            $player->kick("You have been banned by a Discord command.");
          }
          $api->sendMessage($server_id, $channel_id, "Player $playerName has been banned and added to banned-players.txt.");
        } else {
          $api->sendMessage($server_id, $channel_id, "Please provide a player name.");
        }
      } else {
        $api->sendMessage($server_id, $channel_id, "Commands `!ban` can only be executed on channels " . $getAllowedChannelsString("CommandBan"));
      }
    }

    // HANDLE !unban COMMAND
    if ($args[0] == $this->config->get("CommandUnban", "!unban")) {
      if ($isCommandAllowed("CommandUnban", $channel_id)) {
        if (isset($args[1])) {
          $playerName = $args[1];
          if ($this->isPlayerBanned($playerName)) {
            $this->unbanPlayer($playerName);
            $api->sendMessage($server_id, $channel_id, "Player $playerName has been unbanned and removed from banned-players.txt.");
          } else {
            $api->sendMessage($server_id, $channel_id, "Player $playerName is not banned.");
          }
        } else {
          $api->sendMessage($server_id, $channel_id, "Please provide a player name.");
        }
      } else {
        $api->sendMessage($server_id, $channel_id, "Commands `!unban` can only be executed on channels " . $getAllowedChannelsString("CommandUnban"));
      }
    }

    // HANDLE !kick COMMAND
    if ($args[0] == $this->config->get("CommandKick", "!kick")) {
      if ($isCommandAllowed("CommandKick", $channel_id)) {
        if (isset($args[1])) {
          $playerName = $args[1];
          $player = $this->getServer()->getPlayerExact($playerName);
          if ($player instanceof Player) {
            $player->kick("You have been kicked by a Discord command.");
            $api->sendMessage($server_id, $channel_id, "Player $playerName has been kicked.");
          } else {
            $api->sendMessage($server_id, $channel_id, "Player $playerName is not online.");
          }
        } else {
          $api->sendMessage($server_id, $channel_id, "Please provide a player name.");
        }
      } else {
        $api->sendMessage($server_id, $channel_id, "Commands `!kick` can only be executed on channels " . $getAllowedChannelsString("CommandKick"));
      }
    }

    // HANDLE !say COMMAND
    if ($args[0] == $this->config->get("CommandSay", "!say")) {
      if ($isCommandAllowed("CommandSay", $channel_id)) {
        array_shift($args);
        $messageContent = implode(" ", $args);
        
        Server::getInstance()->broadcastMessage($messageContent);

        $api->sendMessage($server_id, $channel_id, "Message broadcasted: \"$messageContent\"");
      } else {
        $api->sendMessage($server_id, $channel_id, "Commands `!say` can only be executed on channels " . $getAllowedChannelsString("CommandSay"));
      }
    }
  }

  public function onPlayerLogin(PlayerLoginEvent $event): void {
    $player = $event->getPlayer();
    if ($this->isPlayerBanned($player->getName())) {
      $player->kick("You are banned from this server.");
    }
  }
}
