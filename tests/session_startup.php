<?php

// Offline regression: real startup selection and Client::connect, no Telegram or credentials.
namespace danog\MadelineProto {
    abstract class SettingsAbstract {}
    class Settings extends SettingsAbstract {}
    class API {
        public const NOT_LOGGED_IN = 0;
        public const LOGGED_IN = 3;
        public static array $states = [];
        public static array $startCalls = [];
        private string $name;
        private int $state;
        public function __construct(string $file, SettingsAbstract $settings) {
            $this->name = basename($file, '.madeline');
            $this->state = self::$states[$this->name] ?? self::NOT_LOGGED_IN;
        }
        public function updateSettings(SettingsAbstract $settings): void {}
        public function getAuthorization(): int { return $this->state; }
        public function start(): void {
            self::$startCalls[$this->name] = (self::$startCalls[$this->name] ?? 0) + 1;
            $this->state = self::LOGGED_IN;
        }
        public function echo(string $message): void {}
    }
}
namespace Amp\Sync {
    class LocalKeyedMutex {
        public function acquire(string $name): object { return new \stdClass(); }
    }
}
namespace Revolt {
    class EventLoop {
        public static function getErrorHandler(): ?callable { return null; }
        public static function setErrorHandler(callable $handler): void {}
    }
}
namespace Psr\Log {
    class LogLevel { public const ERROR = 'error'; }
}
namespace TelegramApiServer\EventObservers {
    class EventHandler { public static function cachePlugins(string $class): void {} }
}
namespace TelegramApiServer {
    final class Files {
        public static function getSessionName(string $file): string { return basename($file, '.madeline'); }
        public static function getSessionFile(string $name): string { return 'sessions/' . $name . '.madeline'; }
        public static function checkOrCreateSessionFolder(string $file): void {}
        public static function getSessionSettings(string $name): array { return []; }
    }
    final class Config {
        public static function getInstance(): self { return new self(); }
        public function get(string $key): mixed {
            return match ($key) {
                'telegram', 'error.peers' => [],
                'error.resume_on_error' => false,
                default => '',
            };
        }
    }
    final class Logger {
        public static array $levels = ['error' => 1];
        public int $minLevelIndex = 0;
        public static function getInstance(): self { static $self; return $self ??= new self(); }
        public function info(string $message): void {}
    }
    function warning(string $message, array $context = []): void {}
}
namespace {
    function check(bool $condition, string $message): void {
        if (!$condition) { throw new \RuntimeException($message); }
    }

    require dirname(__DIR__) . '/src/SessionStartup.php';
    require dirname(__DIR__) . '/src/Client.php';

    try {
        $base = ['session' => [], 'port' => ''];
        check(\TelegramApiServer\SessionStartup::apply($base, false) === $base, 'Absent SESSION changed defaults');
        check(\TelegramApiServer\SessionStartup::apply($base, '   ') === $base, 'Blank SESSION changed defaults');

        $selected = \TelegramApiServer\SessionStartup::apply($base, ' sniper ');
        check($selected['session'] === ['sniper'], 'SESSION was not selected');
        check($selected['interactive_login'] === false, 'Environment startup could prompt for login');
        $nested = \TelegramApiServer\SessionStartup::apply($base, 'users/sniper');
        check($nested['session'] === ['users/sniper'], 'Nested session name changed');

        $cli = ['session' => ['operator'], 'port' => ''];
        check(\TelegramApiServer\SessionStartup::apply($cli, 'sniper') === $cli, 'Environment overrode explicit CLI session');
        check(\TelegramApiServer\SessionStartup::apply($cli, '../invalid') === $cli, 'Invalid unused environment changed CLI');

        foreach (['../sniper', '/sniper', 'sniper/../other', 'sniper;command', '{"secret":"synthetic_marker"}', str_repeat('x', 129)] as $invalid) {
            $rejected = \TelegramApiServer\SessionStartup::apply($base, $invalid);
            check($rejected['session'] === [], 'Unsafe SESSION selected');
            check(($rejected['session_configuration_error'] ?? false) === true, 'Invalid SESSION was not classified');
            check(!str_contains(json_encode($rejected), $invalid), 'Invalid SESSION value escaped into diagnostics');
        }

        $unauthed = new \TelegramApiServer\Client();
        $unauthed->connect(['sessions/sniper.madeline'], false);
        check(isset($unauthed->instances['sniper']), 'Uninitialized session was not available for recovery');
        check($unauthed->instances['sniper']->getAuthorization() === \danog\MadelineProto\API::NOT_LOGGED_IN, 'Test authorized a session unexpectedly');
        check((\danog\MadelineProto\API::$startCalls['sniper'] ?? 0) === 0, 'Headless startup attempted interactive authorization');

        \danog\MadelineProto\API::$states['restored'] = \danog\MadelineProto\API::LOGGED_IN;
        $restored = new \TelegramApiServer\Client();
        $restored->connect(['sessions/restored.madeline'], false);
        check((\danog\MadelineProto\API::$startCalls['restored'] ?? 0) === 1, 'Saved logged-in session did not start');

        $interactive = new \TelegramApiServer\Client();
        $interactive->connect(['sessions/operator.madeline']);
        check((\danog\MadelineProto\API::$startCalls['operator'] ?? 0) > 0, 'Explicit CLI interactive login regressed');
        echo "PASS: environment session loaded; headless startup never prompts; CLI login preserved.\n";
    } catch (\Throwable $error) {
        fwrite(STDERR, "Session startup regression: " . $error->getMessage() . "\n");
        exit(1);
    }
}
