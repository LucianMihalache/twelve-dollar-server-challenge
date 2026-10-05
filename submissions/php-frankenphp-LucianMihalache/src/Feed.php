<?php

/*
| The five endpoints of SPEC.md: which one a request is for, and its answer. Bodies are written as strings in the
| exact key order the spec shows; only the two free texts (a post's body, its author) go through json_encode.
*/
final class Feed
{
    private const JSON = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_LINE_TERMINATORS;

    private static ?int $startedAt = null;

    /** Answers the request PHP is holding now ($_SERVER, php://input). */
    public static function answer(): void
    {
        try {
            $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
            $path = $_SERVER['REQUEST_URI'] ?? '/';
            if (($query = strpos($path, '?')) !== false) {
                $path = substr($path, 0, $query);
            }
            if ($method === 'GET') {
                if ($path === '/feed') {
                    self::feed();
                } elseif (str_starts_with($path, '/posts/') && ! str_contains($id = substr($path, 7), '/')) {
                    self::show($id);
                } elseif ($path === '/health') {
                    self::health();
                } else {
                    self::error(404, 'not found');
                }
            } elseif ($method === 'POST' && $path === '/posts') {
                self::store();
            } elseif ($method === 'POST' && str_starts_with($path, '/posts/') && str_ends_with($path, '/like') && ! str_contains($id = substr($path, 7, -5), '/')) {
                self::like($id);
            } else {
                self::error(404, 'not found');
            }
        } catch (Throwable $e) {
            error_log((string) $e);
            self::error(500, 'internal server error');
        }
    }

    private static function health(): void
    {
        self::$startedAt ??= time();
        try {
            Db::rows('ping');
        } catch (Throwable $e) {
            self::send(503, '{"status":"degraded","db":"unreachable","error":' . json_encode($e->getMessage(), self::JSON) . '}');

            return;
        }
        self::send(200, '{"status":"ok","db":"ok","uptime_s":' . (time() - self::$startedAt) . '}');
    }

    private static function feed(): void
    {
        $posts = [];
        foreach (Db::rows('feed') as $row) {
            $posts[] = self::post($row);
        }
        self::send(200, '{"posts":[' . implode(',', $posts) . ']}');
    }

    private static function show(string $id): void
    {
        if (! self::isId($id)) {
            self::error(400, 'invalid post id');

            return;
        }
        $rows = Db::rows('post', [(int) $id]);
        $rows === [] ? self::error(404, 'post not found') : self::send(200, '{"post":' . self::post($rows[0]) . '}');
    }

    private static function store(): void
    {
        $user = Jwt::user($_SERVER['HTTP_AUTHORIZATION'] ?? null);
        if (is_string($user)) {
            self::error(401, $user);

            return;
        }
        $input = json_decode((string) file_get_contents('php://input'), true);
        if ($input === null && json_last_error() !== JSON_ERROR_NONE) {
            self::error(400, 'malformed JSON body');

            return;
        }
        $body = is_array($input) ? ($input['body'] ?? null) : null;
        // Whitespace as JavaScript's trim() understands it, so every language's implementation trims the same text.
        $body = is_string($body) ? (string) preg_replace('/^[\s\x{FEFF}]+|[\s\x{FEFF}]+$/u', '', $body) : '';
        if ($body === '') {
            self::error(400, 'body is required');

            return;
        }
        if (mb_strlen($body, 'UTF-8') > 500) {
            self::error(400, 'body must be at most 500 characters');

            return;
        }
        $row = Db::rows('create', [$user[0], $body])[0];
        self::send(201, '{"post":' . self::post([$row[0], $body, $row[1], $user[1], 0]) . '}');
    }

    private static function like(string $id): void
    {
        $user = Jwt::user($_SERVER['HTTP_AUTHORIZATION'] ?? null);
        if (is_string($user)) {
            self::error(401, $user);

            return;
        }
        if (! self::isId($id)) {
            self::error(400, 'invalid post id');

            return;
        }
        if (Db::changed('like', [$user[0], (int) $id]) === 1) {
            self::send(201, '{"liked":true,"already_liked":false,"post_id":' . (int) $id . '}');

            return;
        }
        // Nothing was written: either this user liked it before, or there is no such post.
        Db::rows('exists', [(int) $id]) === []
            ? self::error(404, 'post not found')
            : self::send(200, '{"liked":true,"already_liked":true,"post_id":' . (int) $id . '}');
    }

    private static function error(int $status, string $message): void
    {
        self::send($status, '{"error":"' . $message . '"}');
    }

    private static function send(int $status, string $json): void
    {
        http_response_code($status);
        header('Content-Type: application/json');
        echo $json;
    }

    /** @param array{0: int, 1: string, 2: string, 3: string, 4: int} $row id, body, created_at, author, like_count */
    private static function post(array $row): string
    {
        return '{"id":' . (int) $row[0] . ',"body":' . json_encode($row[1], self::JSON) . ',"created_at":"' . $row[2]
            . '","author":' . json_encode($row[3], self::JSON) . ',"like_count":' . (int) $row[4] . '}';
    }

    /** A positive integer written in digits only: "0", "-1", "abc" and "1.5" are not. */
    private static function isId(string $id): bool
    {
        return isset($id[0]) && ! isset($id[18]) && ctype_digit($id) && (int) $id > 0;
    }
}
