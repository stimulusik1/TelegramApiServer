<?php
// Offline behavioral tests: no Telegram connection, real phone or credentials.
namespace Amp\Http\Server {
    final class Uri {
        public function __construct(private string $path, private string $query = '') {}
        public function getPath(): string { return $this->path; }
        public function getQuery(): string { return $this->query; }
        public function getHost(): string { return 'sniper.example'; }
    }
    final class Body {
        private bool $read = false;
        public function __construct(private string $body) {}
        public function read(): ?string { if ($this->read) return null; $this->read = true; return $this->body; }
    }
    final class Request {
        private ?Body $stream = null;
        public function __construct(private string $method, private Uri $uri, private array $headers = [], private string $body = '') {}
        public function getMethod(): string { return $this->method; }
        public function getUri(): Uri { return $this->uri; }
        public function getHeader(string $name): ?string { return $this->headers[$name] ?? null; }
        public function getBody(): Body { return $this->stream ??= new Body($this->body); }
    }
    final class Response {
        public function __construct(public int $status, public array $headers, public string $body) {}
    }
}
namespace Amp\Http\Server\RequestHandler {
    final class ClosureRequestHandler {
        public function __construct(private \Closure $handler) {}
        public function handleRequest(\Amp\Http\Server\Request $request): \Amp\Http\Server\Response { return ($this->handler)($request); }
    }
}
namespace Amp\Sync { final class LocalMutex { public function acquire(): object { return new \stdClass(); } } }
namespace danog\MadelineProto {
    final class API {
        public const NOT_LOGGED_IN = 0, WAITING_CODE = 1, WAITING_PASSWORD = 2, WAITING_SIGNUP = 3, LOGGED_IN = 4, LOGGED_OUT = 5;
        public int $state = self::NOT_LOGGED_IN, $calls = 0, $serialized = 0, $selfChecks = 0;
        public object $users;
        public function __construct() {
            $this->users = new class($this) {
                public function __construct(private API $api) {}
                public function getUsers(array $id): array {
                    if ($id !== [['_'=>'inputUserSelf']]) throw new \RuntimeException('Not a self-only query');
                    $this->api->selfChecks++; return ['phone' => 'never-output-this'];
                }
            };
        }
        public function getAuthorization(): int { return $this->state; }
        public function phoneLogin(string $phone): void { $this->calls++; $this->state = self::WAITING_CODE; }
        public function completePhoneLogin(string $code): void {
            $this->calls++;
            if ($code === '99999') throw new \RuntimeException('PHONE_CODE_INVALID with secret ' . $code);
            $this->state = self::WAITING_PASSWORD;
        }
        public function complete2faLogin(string $password): void { $this->calls++; $this->state = self::LOGGED_IN; }
        public function serialize(): void { $this->serialized++; }
    }
}
namespace TelegramApiServer {
    final class Config {
        public array $passwords = ['tester' => 'offline-password'];
        public static function getInstance(): self { static $instance; return $instance ??= new self(); }
        public function get(string $path, array $default = []): array { return $this->passwords; }
    }
    final class Client {
        public \danog\MadelineProto\API $api;
        public int $started = 0;
        public function __construct() { $this->api = new \danog\MadelineProto\API(); }
        public static function getInstance(): self { static $instance; return $instance ??= new self(); }
        public function getSession(string $session): \danog\MadelineProto\API {
            if ($session !== 'sniper') throw new \RuntimeException('wrong session'); return $this->api;
        }
        public function startLoggedInSession(string $session): void { $this->started++; }
    }
}
namespace {
    require __DIR__ . '/../src/SessionLoginPolicy.php';
    require __DIR__ . '/../src/Controllers/SessionLoginController.php';
    use TelegramApiServer\SessionLoginPolicy as Policy;
    use TelegramApiServer\Controllers\SessionLoginController as Controller;
    use Amp\Http\Server\Request;
    use Amp\Http\Server\Uri;
    $count = 0;
    function check(bool $condition, string $label): void { global $count; if (!$condition) throw new \RuntimeException($label); $count++; }
    $auth = 'Basic ' . base64_encode('tester:offline-password');
    check(Policy::authenticate($auth, ['tester' => 'offline-password']) === 'tester', 'Valid auth refused');
    check(Policy::authenticate('Basic malformed', ['tester' => 'offline-password']) === null, 'Malformed auth accepted');
    check(Policy::authenticate($auth, []) === null, 'Auth fails open with no credential');
    check(Policy::authenticate($auth, ['tester' => 'wrong']) === null, 'Wrong password accepted');
    $token = Policy::issueToken('tester', 'offline-password', time());
    check(Policy::verifyToken($token, 'tester', 'offline-password', time()), 'Valid token refused');
    check(!Policy::verifyToken($token, 'other', 'offline-password', time()), 'Token valid for other owner');
    check(!Policy::verifyToken($token, 'tester', 'offline-password', time() + 901), 'Expired token accepted');
    check(!Policy::verifyToken($token . 'x', 'tester', 'offline-password', time()), 'Tampered token accepted');
    check(Policy::validOrigin('https://sniper.example', 'sniper.example'), 'Same HTTPS origin refused');
    foreach (['https://evil.example', 'http://sniper.example', 'https://user@sniper.example', 'https://sniper.example:444', 'https://sniper.example/path'] as $origin) {
        check(!Policy::validOrigin($origin, 'sniper.example'), 'Unsafe origin accepted');
    }
    check(Policy::safeError(new \RuntimeException('PHONE_CODE_INVALID: secret 99999')) === 'PHONE_CODE_INVALID', 'OTP leaked');
    check(Policy::safeError(new \RuntimeException('super-secret')) === 'LOGIN_FAILED', 'Unexpected exception leaked');
    check(Policy::safeError(new \RuntimeException('FLOOD_WAIT_25')) === 'FLOOD_WAIT_25', 'Flood wait lost');
    $handler = Controller::getRouterCallback();
    putenv('SESSION_LOGIN_ENABLED');
    $get = fn(array $headers = [], string $path = '/session-login', string $query = '') => new Request('GET', new Uri($path, $query), $headers);
    check($handler->handleRequest($get())->status === 404, 'Recovery enabled by default');
    putenv('SESSION_LOGIN_ENABLED=true'); putenv('SESSION=sniper'); putenv('RAILWAY_VOLUME_MOUNT_PATH');
    $challenge = $handler->handleRequest($get());
    check($challenge->status === 401 && isset($challenge->headers['WWW-Authenticate']), 'Browser auth prompt absent');
    $headers = ['Authorization' => $auth];
    $page = $handler->handleRequest($get($headers));
    check($page->status === 200 && !str_contains($page->body, 'offline-password') && !str_contains($page->body, '__CSRF__'), 'Page leaked credential or token absent');
    check($page->headers['Cache-Control'] === 'no-store' && str_contains($page->headers['Content-Security-Policy'], "frame-ancestors 'none'"), 'Security headers absent');
    check($handler->handleRequest($get($headers, '/session-login', 'code=99999'))->status === 400, 'URL auth accepted');
    check($handler->handleRequest($get($headers, '/session-login/state'))->status === 409, 'Login before persistent storage');
    $directory = __DIR__ . '/../sessions'; $created = !is_dir($directory); if ($created) mkdir($directory, 0700);
    putenv('RAILWAY_VOLUME_MOUNT_PATH=' . realpath($directory));
    $headers += ['Origin' => 'https://sniper.example', 'X-CSRF-Token' => $token, 'Content-Type' => 'application/json'];
    $post = fn(array $data, array $h) => new Request('POST', new Uri('/session-login'), $h, json_encode($data));
    $phone = ['action' => 'phone', 'value' => '+70000000000'];
    check($handler->handleRequest($post($phone, array_replace($headers, ['Origin' => 'https://evil.example'])))->status === 403, 'Cross-site login accepted');
    check($handler->handleRequest($post($phone, array_replace($headers, ['X-CSRF-Token' => 'bad'])))->status === 403, 'CSRF accepted');
    check($handler->handleRequest($post(['action' => 'delete', 'value' => ''], $headers))->status === 400, 'Arbitrary action allowed');
    check($handler->handleRequest($post($phone + ['token' => 'secret'], $headers))->status === 400, 'Unknown secret field accepted');
    check($handler->handleRequest(new Request('POST', new Uri('/session-login'), $headers, str_repeat('x', 4097)))->status === 413, 'Oversize body accepted');
    $r = $handler->handleRequest($post($phone, $headers));
    check($r->status === 200 && json_decode($r->body, true)['state'] === 'CODE_REQUIRED', 'Phone flow failed');
    $bad = $handler->handleRequest($post(['action' => 'code', 'value' => '99999'], $headers));
    check($bad->status === 400 && $bad->body === '{"error":"PHONE_CODE_INVALID"}', 'Code leaked in error');
    $r = $handler->handleRequest($post(['action' => 'code', 'value' => '12345'], $headers));
    check(json_decode($r->body, true)['state'] === 'PASSWORD_REQUIRED', '2FA flow failed');
    $r = $handler->handleRequest($post(['action' => 'password', 'value' => 'offline-2fa'], $headers));
    check(json_decode($r->body, true)['state'] === 'LOGGED_IN' && !str_contains($r->body, 'never-output-this'), 'Account data leaked');
    $client = \TelegramApiServer\Client::getInstance();
    check($client->api->serialized === 1 && $client->started === 1 && $client->api->selfChecks === 1, 'Final auth not verified/saved');
    check($handler->handleRequest($post($phone, $headers))->status === 409, 'Existing healthy session changed');
    $client->api->state = \danog\MadelineProto\API::NOT_LOGGED_IN;
    check($handler->handleRequest($post($phone, $headers))->status === 429, 'Phone rate limit missing');
    if ($created) rmdir($directory);
    echo "Session recovery: {$count} checks passed.\n";
}
