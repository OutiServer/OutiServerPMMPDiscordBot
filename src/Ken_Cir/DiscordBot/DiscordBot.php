<?php

declare(strict_types=1);

namespace Ken_Cir\DiscordBot;

use pocketmine\console\ConsoleCommandSender;
use pocketmine\lang\Language;
use pocketmine\plugin\PluginBase;
use pocketmine\scheduler\ClosureTask;
use pocketmine\Server;

class DiscordBot extends PluginBase
{
    private DiscordBotThread $discordClient;

    protected function onEnable(): void
    {
        $this->saveResource("config.yml");

        $this->discordClient = new DiscordBotThread($this->getLogger(),
        $this->getFile(),
            $this->getConfig()->get("token"),
            $this->getConfig()->get("activity_message"),
            $this->getConfig()->get("console_guild_id"),
            $this->getConfig()->get("console_channel_id"),
            $this->getConfig()->get("chat_guild_id"),
            $this->getConfig()->get("chat_channel_id"));

        $this->getScheduler()->scheduleDelayedTask(new ClosureTask(
            function (): void {
                ob_start();
            }
        ), 10);
        $this->getScheduler()->scheduleDelayedRepeatingTask(new ClosureTask(
            function (): void {
                if (!$this->discordClient->isRunning()) return;
                $content = ob_get_contents();

                if ($content === "") return;
                $this->discordClient->sendConsole($content);
                ob_flush();
            }
        ), 10, 1);
        $this->getScheduler()->scheduleDelayedRepeatingTask(new ClosureTask(
            function (): void {
                foreach ($this->discordClient->fetchConsoleMessages() as $message) {
                    if ($message === "") continue;
                    $this->getServer()->dispatchCommand(new ConsoleCommandSender(Server::getInstance(), new Language("jpn")), $message);
                }

                foreach ($this->discordClient->fetchChatMessage() as $message) {
                    if ($message === "") continue;
                    $this->getServer()->broadcastMessage($message);
                }
            }
        ), 10, 10);

        $this->getServer()->getPluginManager()->registerEvents(new EventListener($this), $this);

        $this->discordClient->start(PTHREADS_INHERIT_CONSTANTS);
        $this->discordClient->sendChat("サーバーが起動しました！");
    }

    protected function onDisable(): void
    {
        if (isset($this->discordClient)) {
            $this->discordClient->sendChat("サーバーが停止しました");
            while ($this->discordClient->getQueueCount() > 0) {
                sleep(1);
            }
            $this->discordClient->shutdown();
        }

        if (ob_get_contents()) {
            ob_flush();
            ob_end_clean();
        }
    }

    /**
     * @return DiscordBotThread
     */
    public function getDiscordClient(): DiscordBotThread
    {
        return $this->discordClient;
    }
}