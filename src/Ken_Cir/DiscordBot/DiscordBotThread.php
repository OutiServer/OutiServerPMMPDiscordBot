<?php

declare(strict_types=1);

namespace Ken_Cir\DiscordBot;

use AttachableLogger;
use Discord\Discord;
use Discord\Exceptions\IntentException;
use Discord\Http\Exceptions\NoPermissionsException;
use Discord\Parts\Channel\Message;
use Discord\Parts\User\Activity;
use Discord\Parts\User\Member;
use Discord\WebSockets\Intents;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use pocketmine\thread\Thread;
use pocketmine\utils\TextFormat;
use React\EventLoop\Factory;
use Threaded;

class DiscordBotThread extends Thread
{
    /**
     * Logger
     *
     * @var AttachableLogger
     */
    private AttachableLogger $logger;

    private string $vendorPath;

    /**
     * DiscordBot Token
     *
     * @var string
     */
    private string $token;

    /**
     * Discord Activity Message
     * @var string
     */
    private string $activityMsg;

    /**
     * コンソールチャンネルがあるギルドID
     *
     * @var string
     */
    private string $consoleGuildId;

    /**
     * コンソールチャンネルID
     *
     * @var string
     */
    private string $consoleChannelId;

    /**
     * チャットチャンネルがあるギルドID
     *
     * @var string
     */
    private string $chatGuildId;

    /**
     * チャットチャンネルID
     *
     * @var string
     */
    private string $chatChannelId;

    /**
     * Minecraft -> Discord
     * のコンソールキュー
     *
     * @var Threaded
     */
    private Threaded $discordConsoleQueue;

    /**
     * Discord -> Minecraft
     * のコンソールキュー
     *
     * @var Threaded
     */
    private Threaded $minecraftConsoleQueue;

    /**
     * Minecraft -> Discord
     * のチャットキュー
     *
     * @var Threaded
     */
    private Threaded $discordChatQueue;

    /**
     * Discord -> Minecraft
     * のチャットキュー
     *
     * @var Threaded
     */
    private Threaded $minecraftChatQueue;

    /**
     * 起動済みか
     *
     * @var bool
     */
    private bool $isReady;

    public function __construct(AttachableLogger $logger, string $vendorPath, string $token, string $activityMsg, string $consoleGuildId, string $consoleChannelId, string $chatGuildId, string $chatChannelId)
    {
        $this->logger = $logger;
        $this->vendorPath = $vendorPath;
        $this->token = $token;
        $this->activityMsg = $activityMsg;
        $this->consoleGuildId = $consoleGuildId;
        $this->consoleChannelId = $consoleChannelId;
        $this->chatGuildId = $chatGuildId;
        $this->chatChannelId = $chatChannelId;
        $this->discordConsoleQueue = new Threaded();
        $this->minecraftConsoleQueue = new Threaded();
        $this->discordChatQueue = new Threaded();
        $this->minecraftChatQueue = new Threaded();
        $this->isReady = false;
    }

    public function shutdown(): void
    {
        $this->isKilled = true;
    }

    /**
     * discordキューに入っている合計カウント
     *
     * @return int
     */
    public function getQueueCount(): int
    {
        return (count($this->minecraftConsoleQueue) + count($this->minecraftChatQueue));
    }

    public function fetchConsoleMessages(): array
    {
        $messages = [];
        while (count($this->discordConsoleQueue) > 0) {
            $messages[] = unserialize($this->discordConsoleQueue->shift());
        }

        return $messages;
    }

    public function sendConsole(string $content): void
    {
        $this->minecraftConsoleQueue[] = serialize($content);
    }

    public function fetchChatMessage(): array
    {
        $messages = [];
        while (count($this->discordChatQueue) > 0) {
            $messages[] = unserialize($this->discordChatQueue->shift());
        }

        return $messages;
    }

    public function sendChat(string $content): void
    {
        $this->minecraftChatQueue[] = serialize($content);
    }

    protected function onRun(): void
    {
        $this->registerClassLoaders();

        include "$this->vendorPath/vendor/autoload.php";

        $loop = Factory::create();

        try {
            $logger = new Logger('Logger');
            $logger->pushHandler(new StreamHandler('php://stdout', Logger::WARNING));
            $discord = new Discord([
                "token" => $this->token,
                "loop" => $loop,
                "logger" => $logger,
                'loadAllMembers' => true,
                'intents' => Intents::GUILDS | Intents::GUILD_MESSAGES | Intents::GUILD_MEMBERS
            ]);
        }
        catch (IntentException $exception) {
            $this->logger->error("{$exception->getMessage()}\n{$exception->getTraceAsString()}");
            $this->logger->critical("DiscordBotのログインに失敗しました");
            $this->isKilled = true;
            return;
        }

        $loop->addPeriodicTimer(1, function () use ($discord) {
            if ($this->isKilled) {
                $discord->close();
            }
        });

        $loop->addPeriodicTimer(1, function () use ($discord) {
            $this->task($discord);
        });

        $discord->on('ready', function (Discord $discord) {
            $this->isReady = true;
            $this->logger->info("Bot is ready.");

            $activity = new Activity($discord);
            $activity->name = $this->activityMsg;
            $activity->type = Activity::TYPE_PLAYING;
            $discord->updatePresence($activity);

            $discord->on('message', function (Message $message) use ($discord) {
                if ($message->author instanceof Member ? $message->author->user->bot : $message->author->bot or $message->type !== Message::TYPE_NORMAL or $message->content === "") return;

                // コンソールチャンネル
                if ($message->channel_id === $this->consoleChannelId) {
                    $this->discordConsoleQueue[] = serialize($message->content);
                }
                elseif ($message->channel_id === $this->chatChannelId) {
                    $this->discordChatQueue[] = serialize("[{$message->author->displayname}] $message->content");
                }
            });
        });

        $discord->run();
    }

    private function task(Discord $discord): void
    {
        if (!$this->isReady) return;

        $consoleGuild = $discord->guilds->get('id', $this->consoleGuildId);
        $consoleChannel = $consoleGuild->channels->get('id', $this->consoleChannelId);
        $chatGuild = $discord->guilds->get('id', $this->chatGuildId);
        $chatChannel = $chatGuild->channels->get('id', $this->chatChannelId);

        while (count($this->minecraftConsoleQueue) > 0) {
            $message = unserialize($this->minecraftConsoleQueue->shift());//
            $message = preg_replace(['/]0;.*%/', '//', "/Server thread\//"], '', TextFormat::clean(substr($message, 0, 2000)));
            if ($message === "") continue;
            if (strlen($message) < 2000) {
                try {
                    $consoleChannel->sendMessage("```$message```");
                }
                catch (NoPermissionsException $e) {
                    $this->logger->error($e->getTraceAsString());
                }
            }
        }

        while (count($this->minecraftChatQueue) > 0) {
            $message = unserialize($this->minecraftChatQueue->shift());//
            $message = preg_replace(['/]0;.*%/', '/[\x07]/', "/Server thread\//"], '', TextFormat::clean(substr($message, 0, 2000)));
            if ($message === "") continue;
            if (strlen($message) < 2000) {
                try {
                    $chatChannel->sendMessage($message);
                }
                catch (NoPermissionsException $e) {
                    $this->logger->error("{$e->getMessage()}\n{$e->getTraceAsString()}");
                }
            }
        }
    }
}