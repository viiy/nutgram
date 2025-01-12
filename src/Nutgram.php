<?php


namespace SergiX44\Nutgram;

use GuzzleHttp\Client as Guzzle;
use InvalidArgumentException;
use Laravel\SerializableClosure\SerializableClosure;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;
use SergiX44\Container\Container;
use SergiX44\Nutgram\Cache\ConversationCache;
use SergiX44\Nutgram\Cache\GlobalCache;
use SergiX44\Nutgram\Cache\UserCache;
use SergiX44\Nutgram\Conversations\Conversation;
use SergiX44\Nutgram\Handlers\FireHandlers;
use SergiX44\Nutgram\Handlers\ResolveHandlers;
use SergiX44\Nutgram\Handlers\Type\Command;
use SergiX44\Nutgram\Hydrator\Hydrator;
use SergiX44\Nutgram\Proxies\GlobalCacheProxy;
use SergiX44\Nutgram\Proxies\UpdateDataProxy;
use SergiX44\Nutgram\Proxies\UserCacheProxy;
use SergiX44\Nutgram\RunningMode\Polling;
use SergiX44\Nutgram\RunningMode\RunningMode;
use SergiX44\Nutgram\Support\BulkMessenger;
use SergiX44\Nutgram\Support\HandleLogging;
use SergiX44\Nutgram\Support\ValidatesWebData;
use SergiX44\Nutgram\Telegram\Client;
use SergiX44\Nutgram\Telegram\Types\Command\BotCommandScope;
use SergiX44\Nutgram\Telegram\Types\Common\Update;
use SergiX44\Nutgram\Testing\FakeNutgram;
use Throwable;

class Nutgram extends ResolveHandlers
{
    use Client, UpdateDataProxy, GlobalCacheProxy, UserCacheProxy, FireHandlers, ValidatesWebData, HandleLogging;

    /**
     * @var string
     */
    private string $token;

    /**
     * @var Configuration
     */
    private Configuration $config;

    /**
     * @var Guzzle
     */
    private Guzzle $http;

    /**
     * @var Hydrator
     */
    protected Hydrator $hydrator;

    /**
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * @var Container
     */
    protected Container $container;

    /**
     * Nutgram constructor.
     * @param string $token
     * @param Configuration|null $config
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public function __construct(string $token, ?Configuration $config = null)
    {
        if (empty($token)) {
            throw new InvalidArgumentException('The token cannot be empty.');
        }

        $this->bootstrap($token, $config ?? new Configuration());
    }

    /**
     * Initializes the current instance
     * @param string $token
     * @param Configuration $config
     * @return void
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    private function bootstrap(string $token, Configuration $config): void
    {
        $this->token = $token;
        $this->config = $config;
        $this->container = new Container();
        if ($config->container !== null) {
            $this->container->delegate($config->container);
        }

        SerializableClosure::setSecretKey($this->token);

        $this->http = new Guzzle([
            'base_uri' => sprintf(
                '%s/bot%s/%s',
                $config->apiUrl,
                $this->token,
                $config->testEnv ?? false ? 'test/' : ''
            ),
            'timeout' => $config->clientTimeout,
            'version' => $config->enableHttp2 ? '2.0' : '1.1',
            ...$config->clientOptions,
        ]);
        $botId = $config->botId ?? (int)explode(':', $this->token)[0];
        $this->container->set(ClientInterface::class, $this->http);
        $this->container->singleton(Hydrator::class, $config->hydrator);
        $this->container->singleton(CacheInterface::class, $config->cache);
        $this->container->singleton(LoggerInterface::class, $config->logger);
        $this->container->singleton(
            ConversationCache::class,
            fn (ContainerInterface $c) => new ConversationCache($c->get(CacheInterface::class), $botId)
        );
        $this->container->singleton(
            GlobalCache::class,
            fn (ContainerInterface $c) => new GlobalCache($c->get(CacheInterface::class), $botId)
        );
        $this->container->singleton(
            UserCache::class,
            fn (ContainerInterface $c) => new UserCache($c->get(CacheInterface::class), $botId)
        );

        $this->hydrator = $this->container->get(Hydrator::class);
        $this->conversationCache = $this->container->get(ConversationCache::class);
        $this->globalCache = $this->container->get(GlobalCache::class);
        $this->userCache = $this->container->get(UserCache::class);
        $this->logger = $this->container->get(LoggerInterface::class);

        $this->container->singleton(RunningMode::class, Polling::class);
        $this->container->set(__CLASS__, $this);
    }

    /**
     * @return array
     */
    public function __serialize(): array
    {
        return [
            'token' => $this->token,
            'config' => $this->config,
        ];
    }

    /**
     * @param array $data
     * @return void
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public function __unserialize(array $data): void
    {
        $this->bootstrap($data['token'], $data['config']);
    }

    /**
     * @param mixed $update
     * @param array $responses
     * @return FakeNutgram
     */
    public static function fake(mixed $update = null, array $responses = [], ?Configuration $config = null): FakeNutgram
    {
        return FakeNutgram::instance($update, $responses, $config);
    }

    /**
     * @param string|RunningMode $classOrInstance
     */
    public function setRunningMode(string|RunningMode $classOrInstance): void
    {
        $this->container->bind(RunningMode::class, $classOrInstance);
    }

    protected function preflight(): void
    {
        if (!$this->finalized) {
            $this->resolveGroups();
            $this->applyGlobalMiddleware();
            $this->finalized = true;
        }
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public function run(): void
    {
        $this->preflight();
        $this->container->get(RunningMode::class)->processUpdates($this);
    }

    /**
     * @param Update $update
     * @throws Throwable
     * @throws \Psr\SimpleCache\InvalidArgumentException
     */
    public function processUpdate(Update $update): void
    {
        $this->update = $update;

        $conversation = $this->currentConversation($this->userId(), $this->chatId());

        if ($conversation !== null) {
            $handlers = $this->continueConversation($conversation);
        } else {
            $handlers = $this->resolveHandlers();
        }

        if (empty($handlers) && !empty($this->handlers[self::FALLBACK])) {
            $this->addHandlersBy($handlers, self::FALLBACK, value: $this->update->getType()->value);
        }

        if (empty($handlers)) {
            $this->addHandlersBy($handlers, self::FALLBACK);
        }

        $this->fireHandlers($handlers);
    }

    /**
     * @param Conversations\Conversation|callable $callable
     * @param int|null $userId
     * @param int|null $chatId
     * @return $this
     * @throws \Psr\SimpleCache\InvalidArgumentException
     */
    public function stepConversation(
        Conversations\Conversation|callable $callable,
        ?int $userId = null,
        ?int $chatId = null
    ): self {
        $userId = $userId ?? $this->userId();
        $chatId = $chatId ?? $this->chatId();

        if ($this->update === null && ($userId === null || $chatId === null)) {
            throw new InvalidArgumentException('You cannot step a conversation without userId and chatId.');
        }

        $this->conversationCache->set($userId, $chatId, $callable);

        return $this;
    }

    /**
     * @param int|null $userId
     * @param int|null $chatId
     * @return $this
     * @throws \Psr\SimpleCache\InvalidArgumentException
     */
    public function endConversation(?int $userId = null, ?int $chatId = null): self
    {
        $userId = $userId ?? $this->userId();
        $chatId = $chatId ?? $this->chatId();

        if ($this->update === null && ($userId === null || $chatId === null)) {
            throw new InvalidArgumentException('You cannot end a conversation without userId and chatId.');
        }

        $this->conversationCache->delete($userId, $chatId);

        return $this;
    }

    public function getContainer(): ContainerInterface
    {
        return $this->container;
    }

    /**
     * @return Configuration
     */
    public function getConfig(): Configuration
    {
        return $this->config;
    }

    /**
     * @return string
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public function getRunningMode(): string
    {
        return $this->container->get(RunningMode::class)::class;
    }

    /**
     * Set my commands call to Telegram using all the registered commands
     */
    public function registerMyCommands(): void
    {
        $this->preflight();

        /** @var BotCommandScope[] $commands */
        $scopes = [];
        /** @var Command[] $commands */
        $commands = [];
        array_walk_recursive($this->handlers, static function ($handler) use (&$commands, &$scopes) {
            if ($handler instanceof Command && !$handler->isHidden()) {
                foreach ($handler->scopes() as $scope) {
                    $hashCode = crc32(serialize(get_object_vars($scope)));
                    $scopes[$hashCode] = $scope;
                    $commands[$hashCode][] = $handler->toBotCommand();
                }
            }
        });

        // set commands for each scope
        foreach ($scopes as $hashCode => $scope) {
            $this->setMyCommands(
                commands: array_values(array_unique($commands[$hashCode], SORT_REGULAR)),
                scope: $scope,
            );
        }
    }

    /**
     * @return BulkMessenger
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public function getBulkMessenger(): BulkMessenger
    {
        return $this->container->get(BulkMessenger::class);
    }

    public function invoke(callable|array|string $callable, array $params = []): mixed
    {
        return $this->container->call($callable, $params);
    }
}
