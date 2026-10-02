<?php

namespace TelegramApiServer\Controllers;

use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler\ClosureRequestHandler;
use Amp\Http\Server\Response;
use Amp\Sync\LocalMutex;
use danog\MadelineProto\API;
use TelegramApiServer\Client;
use TelegramApiServer\Config;
use TelegramApiServer\SessionLoginPolicy;

/** Separate from AbstractApiController: no auth values or exception traces in logs/responses. */
final class SessionLoginController
{
    private LocalMutex $mutex;
    private int $lastPhoneAttempt = 0;

    public function __construct()
    {
        $this->mutex = new LocalMutex();
    }

    public static function getRouterCallback(): ClosureRequestHandler
    {
        $controller = new self();
        return new ClosureRequestHandler($controller->handle(...));
    }

    private function response(int $status, array|string $data, array $headers = []): Response
    {
        $json = is_array($data);
        return new Response($status, $headers + [
            'Content-Type' => $json ? 'application/json;charset=utf-8' : 'text/html;charset=utf-8',
            'Cache-Control' => 'no-store',
            'Referrer-Policy' => 'no-referrer',
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
        ], $json ? json_encode($data, JSON_THROW_ON_ERROR) : $data);
    }

    private function handle(Request $request): Response
    {
        if (getenv('SESSION_LOGIN_ENABLED') !== 'true') {
            return $this->response(404, ['error' => 'LOGIN_DISABLED']);
        }
        // No IP/localhost bypass; an existing non-empty Basic Auth credential is mandatory.
        $passwords = (array)Config::getInstance()->get('api.passwords', []);
        $user = SessionLoginPolicy::authenticate((string)$request->getHeader('Authorization'), $passwords);
        if ($user === null) {
            return $this->response(401, ['error' => 'SERVER_AUTH_REQUIRED'], [
                'WWW-Authenticate' => 'Basic realm="SNIPER recovery", charset="UTF-8"',
            ]);
        }
        if ($request->getUri()->getQuery() !== '') {
            return $this->response(400, ['error' => 'QUERY_NOT_ALLOWED']);
        }
        $directory = dirname(__DIR__, 2) . '/sessions';
        $persistent = SessionLoginPolicy::persistentDirectory((string)getenv('RAILWAY_VOLUME_MOUNT_PATH'), $directory);
        if ($request->getMethod() === 'GET' && $request->getUri()->getPath() === '/session-login') {
            $nonce = bin2hex(random_bytes(24));
            $token = SessionLoginPolicy::issueToken($user, $passwords[$user], time());
            $html = file_get_contents(dirname(__DIR__, 2) . '/resources/session-login.html');
            $html = str_replace(['__NONCE__', '__CSRF__'], [$nonce, $token], $html);
            return $this->response(200, $html, [
                'Content-Security-Policy' => "default-src 'none'; script-src 'nonce-$nonce'; style-src 'nonce-$nonce'; connect-src 'self'; base-uri 'none'; frame-ancestors 'none'; form-action 'self'",
            ]);
        }
        if (!$persistent) {
            return $this->response(409, ['error' => 'PERSISTENT_VOLUME_REQUIRED']);
        }
        try {
            $lock = $this->mutex->acquire();
            $sessionName = (string)getenv('SESSION');
            if (!preg_match('/^[A-Za-z0-9_-]+$/D', $sessionName)) {
                return $this->response(409, ['error' => 'SESSION_CONFIGURATION_INVALID']);
            }
            $client = Client::getInstance();
            $api = $client->getSession($sessionName);
            if ($request->getMethod() === 'GET') {
                return $this->response(200, $this->state($api));
            }
            if (!SessionLoginPolicy::validOrigin((string)$request->getHeader('Origin'), $request->getUri()->getHost())
                || !SessionLoginPolicy::verifyToken((string)$request->getHeader('X-CSRF-Token'), $user, $passwords[$user], time())) {
                return $this->response(403, ['error' => 'RELOAD_LOGIN_PAGE']);
            }
            if ($request->getHeader('Content-Type') !== 'application/json') {
                return $this->response(415, ['error' => 'JSON_REQUIRED']);
            }
            // Bound the body even if Content-Length is absent or dishonest.
            $body = '';
            $stream = $request->getBody();
            while (($chunk = $stream->read()) !== null) {
                $body .= $chunk;
                if (strlen($body) > 4096) {
                    return $this->response(413, ['error' => 'BODY_TOO_LARGE']);
                }
            }
            $data = json_decode($body, true, 8, JSON_THROW_ON_ERROR);
            if (!is_array($data) || array_diff(array_keys($data), ['action', 'value'])
                || !is_string($data['action'] ?? null) || !is_string($data['value'] ?? null)) {
                return $this->response(400, ['error' => 'INVALID_INPUT']);
            }
            $state = $api->getAuthorization();
            if ($state === API::LOGGED_IN) {
                return $this->response(409, ['error' => 'ALREADY_LOGGED_IN']);
            }
            switch ($data['action']) {
                case 'phone':
                    if (!in_array($state, [API::NOT_LOGGED_IN, API::LOGGED_OUT], true)
                        || !preg_match('/^\+[1-9]\d{7,14}$/D', $data['value'])) {
                        return $this->response(400, ['error' => 'CHECK_PHONE_OR_STATE']);
                    }
                    if (time() - $this->lastPhoneAttempt < 60) {
                        return $this->response(429, ['error' => 'WAIT_60_SECONDS']);
                    }
                    $this->lastPhoneAttempt = time();
                    $api->phoneLogin($data['value']);
                    break;
                case 'code':
                    if ($state !== API::WAITING_CODE || !preg_match('/^\d{4,8}$/D', $data['value'])) {
                        return $this->response(400, ['error' => 'CHECK_CODE_OR_STATE']);
                    }
                    $api->completePhoneLogin($data['value']);
                    break;
                case 'password':
                    if ($state !== API::WAITING_PASSWORD || $data['value'] === '' || strlen($data['value']) > 1024) {
                        return $this->response(400, ['error' => 'CHECK_PASSWORD_OR_STATE']);
                    }
                    $api->complete2faLogin($data['value']);
                    break;
                default:
                    return $this->response(400, ['error' => 'ACTION_NOT_ALLOWED']);
            }
            unset($data, $body);
            if ($api->getAuthorization() === API::LOGGED_IN) {
                $client->startLoggedInSession($sessionName);
                $api->serialize();
            }
            return $this->response(200, $this->state($api));
        } catch (\JsonException) {
            return $this->response(400, ['error' => 'INVALID_JSON']);
        } catch (\Throwable $error) {
            $data = ['error' => SessionLoginPolicy::safeError($error)];
            if (isset($api)) {
                $data['state'] = $this->authorizationState($api);
            }
            return $this->response(400, $data);
        }
    }

    private function state(API $api): array
    {
        $state = $this->authorizationState($api);
        // A saved auth flag alone is insufficient: verify that Telegram accepts the key.
        if ($state === 'LOGGED_IN') {
            $api->users->getUsers(id: [['_'=>'inputUserSelf']]);
        }
        return ['state' => $state, 'persistent' => true];
    }

    private function authorizationState(API $api): string
    {
        return match ($api->getAuthorization()) {
            API::NOT_LOGGED_IN, API::LOGGED_OUT => 'PHONE_REQUIRED',
            API::WAITING_CODE => 'CODE_REQUIRED',
            API::WAITING_PASSWORD => 'PASSWORD_REQUIRED',
            API::WAITING_SIGNUP => 'REGISTRATION_NOT_SUPPORTED',
            API::LOGGED_IN => 'LOGGED_IN',
            default => 'UNKNOWN_STATE',
        };
    }
}
