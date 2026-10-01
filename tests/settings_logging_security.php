<?php

declare(strict_types=1);

namespace danog\MadelineProto {
    abstract class SettingsAbstract {}
    class Settings extends SettingsAbstract {}
}

namespace SniperSecurityTest {
    final class Capture {
        public static array $calls = [];
    }

    class FakeSettings extends \danog\MadelineProto\SettingsAbstract {
        public function __call(string $method, array $arguments): mixed {
            if (str_starts_with($method, 'get')) {
                return new self();
            }
            Capture::$calls[] = ['method' => $method, 'value' => $arguments[0] ?? null];
            return $this;
        }
    }

    final class ValueLogged extends \RuntimeException {}
}

namespace danog\MadelineProto\Settings\Database {
    class Memory extends \SniperSecurityTest\FakeSettings {}
    class Postgres extends \SniperSecurityTest\FakeSettings {}
}

namespace TelegramApiServer {
    final class Logger {
        public static array $messages = [];
        public static function getInstance(): self {
            static $instance;
            return $instance ??= new self();
        }
        public function info(string $message): void {
            self::$messages[] = $message;
        }
    }
}

namespace {
    try {
        if (PHP_VERSION_ID < 80200) {
            throw new \RuntimeException('PHP 8.2 or newer required');
        }
        require ($argv[1] ?? dirname(__DIR__) . '/src/Client.php');

        // Synthetic sentinels only. This test never starts a server or a Telegram client.
        $sentinels = [
            'api' => 'SNIPER_TEST_ONLY_API_VALUE',
            'database' => 'SNIPER_TEST_ONLY_DATABASE_VALUE',
            'proxy' => 'SNIPER_TEST_ONLY_PROXY_VALUE',
            'username' => 'SNIPER_TEST_ONLY_USERNAME_VALUE',
        ];
        $settings = [
            'app_info' => ['api_id' => 424242, 'api_hash' => $sentinels['api']],
            'db' => [
                'type' => 'postgres',
                'postgres' => [
                    'uri' => 'tcp://example.invalid:5432',
                    'username' => $sentinels['username'],
                    'password' => $sentinels['database'],
                ],
            ],
            'connection' => [
                'proxies' => [
                    'FakeProxy' => [['username' => 'fixture', 'password' => $sentinels['proxy']]],
                ],
            ],
        ];

        $target = new \SniperSecurityTest\FakeSettings();
        $method = new \ReflectionMethod(\TelegramApiServer\Client::class, 'getSettingsFromArray');
        $result = $method->invoke(null, 'synthetic-security-fixture', $settings, $target);
        if ($result !== $target || count(\TelegramApiServer\Logger::$messages) < 6) {
            throw new \RuntimeException('Settings path did not execute');
        }

        $logged = implode("\n", \TelegramApiServer\Logger::$messages);
        foreach ($sentinels as $sentinel) {
            if (str_contains($logged, $sentinel)) {
                throw new \SniperSecurityTest\ValueLogged();
            }
        }
        foreach (\TelegramApiServer\Logger::$messages as $message) {
            if (!preg_match('/^Set setting .*::set[A-Za-z0-9_]+$/D', $message)) {
                throw new \RuntimeException('Unexpected log shape');
            }
        }

        $expected = [
            'setApiHash' => $sentinels['api'],
            'setPassword' => $sentinels['database'],
            'setProxies' => $settings['connection']['proxies'],
        ];
        foreach ($expected as $name => $value) {
            $matches = array_filter(
                \SniperSecurityTest\Capture::$calls,
                static fn(array $call): bool => $call['method'] === $name && $call['value'] === $value
            );
            if (!$matches) {
                throw new \RuntimeException('A setting was changed before reaching its setter');
            }
        }

        echo 'PASS: settings preserved; log messages contain metadata only.' . PHP_EOL;
    } catch (\SniperSecurityTest\ValueLogged $error) {
        fwrite(STDERR, 'Security regression: a synthetic settings value reached the logger.' . PHP_EOL);
        exit(23);
    } catch (\Throwable $error) {
        fwrite(STDERR, 'Security test could not complete.' . PHP_EOL);
        exit(2);
    }
}
