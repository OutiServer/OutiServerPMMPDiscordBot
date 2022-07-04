<?php

declare(strict_types=1);

namespace Ken_Cir\DiscordBot;

use pocketmine\event\Listener;
use pocketmine\event\player\PlayerChatEvent;
use pocketmine\event\player\PlayerDeathEvent;
use pocketmine\event\player\PlayerJoinEvent;
use pocketmine\event\player\PlayerQuitEvent;

class EventListener implements Listener
{
    private DiscordBot $plugin;

    public function __construct(DiscordBot $plugin)
    {
        $this->plugin = $plugin;
    }

    /**
     * @priority MONITOR
     *
     * @param PlayerJoinEvent $event
     * @return void
     */
    public function onPlayerJoin(PlayerJoinEvent $event): void
    {
        $player = $event->getPlayer();

        $this->plugin->getDiscordClient()->sendChat("{$player->getName()}がサーバーに参加しました");
    }

    /**
     * @priority MONITOR
     *
     * @param PlayerQuitEvent $event
     * @return void
     */
    public function onPlayerQuit(PlayerQuitEvent $event): void
    {
        $player = $event->getPlayer();

        $this->plugin->getDiscordClient()->sendChat("{$player->getName()}がサーバーから退出しました");
    }

    /**
     * @priority MONITOR
     *
     * @param PlayerChatEvent $event
     * @return void
     */
    public function onPlayerChat(PlayerChatEvent $event): void
    {
        $player = $event->getPlayer();
        $message = $event->getMessage();

        $this->plugin->getDiscordClient()->sendChat("[{$player->getName()}] $message");
    }

    public function onPlayerDeath(PlayerDeathEvent $event): void
    {
        $player = $event->getPlayer();

        $this->plugin->getDiscordClient()->sendChat("{$player->getName()}は死んだ");
    }
}