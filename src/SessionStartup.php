<?php

namespace TelegramApiServer;

final class SessionStartup
{
    public static function apply(array $options, string|false $configuredSession): array
    {
        // Explicit CLI sessions retain the original interactive login behavior.
        if (!empty($options['session']) || $configuredSession === false || trim($configuredSession) === '') {
            return $options;
        }

        $session = trim($configuredSession);
        // SESSION names a session; it must never contain a serialized session or credential.
        if (strlen($session) > 128 || preg_match('~\A[A-Za-z0-9_-]+(?:/[A-Za-z0-9_-]+)*\z~D', $session) !== 1) {
            $options['session_configuration_error'] = true;
            return $options;
        }

        $options['session'] = [$session];
        // A missing/expired authorization must leave HTTP available for secure manual recovery.
        $options['interactive_login'] = false;
        return $options;
    }
}
