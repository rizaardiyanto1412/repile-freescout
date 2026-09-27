<?php

namespace Modules\Repile\Support;

use App\Conversation;
use App\Thread;

class Logins
{
    private const PASSWORD_LABEL = '(?:password|passwd|passphrase|passcode|pass|pwd|pw|senha|contraseña|contrasena|kata\s*sandi|sandi|mot\s*de\s*passe|passwort)';
    private const USER_LABEL = '(?:user\s*name|username|user|login\s*name|login|email|e-mail|admin\s*user|admin|usuario|pengguna)';
    private const URL_LABEL = '(?:login\s*url|admin\s*url|login\s*link|site\s*url|website|site|url|link)';
    private const SEPARATOR = '[ \t]*[:=][ \t]*';
    private const VALUE = '("[^"\n]*"|\'[^\'\n]*\'|[^\s<>]+)';

    public static function find($text)
    {
        $text = (string) $text;
        $passwords = self::matches(self::PASSWORD_LABEL, $text);
        if (count($passwords) === 0) {
            return [];
        }
        $users = array_values(array_filter(self::matches(self::USER_LABEL, $text), function ($user) {
            return !preg_match('#^(https?://|www\.)#i', $user['value']);
        }));
        $urls = self::urls($text);

        $logins = [];
        foreach ($passwords as $password) {
            $user = self::nearest($users, $password['offset']);
            $url = self::nearest($urls, $password['offset']);
            $logins[] = [
                'url' => $url ? $url['value'] : null,
                'username' => $user ? $user['value'] : null,
                'password' => $password['value'],
            ];
        }

        return self::unique($logins);
    }

    public static function forConversation(Conversation $conversation)
    {
        $threads = Thread::where('conversation_id', $conversation->id)
            ->whereIn('state', [Thread::STATE_PUBLISHED, Thread::STATE_DRAFT])
            ->orderBy('id')
            ->get();
        $logins = [];
        foreach ($threads as $thread) {
            if (Bot::isBot($thread->created_by_user_id) || !Payload::sharesNote($thread)) {
                continue;
            }
            $logins = array_merge($logins, self::find(self::plain((string) $thread->body)));
        }

        return self::unique($logins);
    }

    public static function plain($html)
    {
        $text = preg_replace('#<(br|/p|/div|/li|/tr|/h[1-6])\b[^>]*>#i', "\n", $html);
        $text = strip_tags($text);

        return html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    public static function hideUsernames($value, array $logins)
    {
        $value = (string) $value;
        foreach ($logins as $login) {
            $username = (string) ($login['username'] ?? '');
            if (mb_strlen($username) < 3) {
                continue;
            }
            foreach (array_unique([$username, htmlspecialchars($username, ENT_QUOTES | ENT_HTML5, 'UTF-8')]) as $needle) {
                $value = preg_replace(
                    '/(?<![\p{L}\p{N}_.@-])'.preg_quote($needle, '/').'(?![\p{L}\p{N}_@-]|\.[\p{L}\p{N}])/iu',
                    Redactor::MASK,
                    $value
                );
            }
        }

        return $value;
    }

    private static function matches($label, $text)
    {
        $pattern = '/(?<![\p{L}\p{N}_-])'.$label.'(?![\p{L}\p{N}_-])'.self::SEPARATOR.self::VALUE.'/iu';
        if (!preg_match_all($pattern, $text, $found, PREG_OFFSET_CAPTURE)) {
            return [];
        }
        $result = [];
        foreach ($found[1] as $match) {
            $value = $match[0];
            if (strlen($value) >= 2 && ($value[0] === '"' || $value[0] === "'") && substr($value, -1) === $value[0]) {
                $value = substr($value, 1, -1);
            } else {
                $value = rtrim($value, '.,;');
            }
            if ($value === '' || $value === Redactor::MASK) {
                continue;
            }
            $result[] = ['value' => $value, 'offset' => $match[1]];
        }

        return $result;
    }

    private static function urls($text)
    {
        $labeled = array_values(array_filter(self::matches(self::URL_LABEL, $text), function ($url) {
            return preg_match('#^(https?://|www\.)#i', $url['value']);
        }));
        if (count($labeled) > 0) {
            return $labeled;
        }
        if (!preg_match_all('#https?://[^\s<>"\']+#i', $text, $found, PREG_OFFSET_CAPTURE)) {
            return [];
        }
        $admin = [];
        foreach ($found[0] as $match) {
            if (preg_match('#wp-(admin|login)#i', $match[0])) {
                $admin[] = ['value' => rtrim($match[0], '.,;)'), 'offset' => $match[1]];
            }
        }

        return $admin;
    }

    private static function nearest(array $candidates, $offset)
    {
        $before = null;
        $after = null;
        foreach ($candidates as $candidate) {
            if ($candidate['offset'] <= $offset) {
                $before = $candidate;
            } elseif ($after === null) {
                $after = $candidate;
            }
        }

        return $before ?: $after;
    }

    private static function unique(array $logins)
    {
        $seen = [];
        $result = [];
        foreach ($logins as $login) {
            $key = json_encode([$login['url'], $login['username'], $login['password']]);
            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $result[] = $login;
            }
        }

        return $result;
    }
}
