<?php

namespace App\Http\Controllers;

use App\Support\Db;
use App\Support\Jwt;
use App\Support\Profile;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Throwable;

/*
| The five endpoints of SPEC.md. Bodies are written as strings in the exact key order the spec shows; only the two
| free texts (a post's body, its author) go through json_encode.
*/
final class FeedController extends Controller
{
    private const JSON = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_LINE_TERMINATORS;
    private const TYPE = ['Content-Type' => 'application/json'];

    private static ?int $startedAt = null;

    public function health(): Response
    {
        Profile::$on && Profile::mark('controller');
        self::$startedAt ??= time();
        try {
            Db::rows('ping');
        } catch (Throwable $e) {
            return new Response('{"status":"degraded","db":"unreachable","error":' . json_encode($e->getMessage(), self::JSON) . '}', 503, self::TYPE);
        }

        return new Response('{"status":"ok","db":"ok","uptime_s":' . (time() - self::$startedAt) . '}', 200, self::TYPE);
    }

    public function feed(): Response
    {
        Profile::$on && Profile::mark('controller');
        $posts = [];
        foreach (Db::rows('feed') as $row) {
            $posts[] = self::post($row);
        }

        return new Response('{"posts":[' . implode(',', $posts) . ']}', 200, self::TYPE);
    }

    public function show(string $id): Response
    {
        Profile::$on && Profile::mark('controller');
        if (! self::isId($id)) {
            return self::error(400, 'invalid post id');
        }
        $rows = Db::rows('post', [(int) $id]);

        return $rows === [] ? self::error(404, 'post not found') : new Response('{"post":' . self::post($rows[0]) . '}', 200, self::TYPE);
    }

    public function store(Request $request): Response
    {
        Profile::$on && Profile::mark('controller');
        $user = Jwt::user($request->headers->get('Authorization'));
        if (is_string($user)) {
            return self::error(401, $user);
        }
        $input = json_decode($request->getContent(), true);
        if ($input === null && json_last_error() !== JSON_ERROR_NONE) {
            return self::error(400, 'malformed JSON body');
        }
        $body = is_array($input) ? ($input['body'] ?? null) : null;
        // Whitespace as JavaScript's trim() understands it, so every language's implementation trims the same text.
        $body = is_string($body) ? (string) preg_replace('/^[\s\x{FEFF}]+|[\s\x{FEFF}]+$/u', '', $body) : '';
        if ($body === '') {
            return self::error(400, 'body is required');
        }
        if (mb_strlen($body, 'UTF-8') > 500) {
            return self::error(400, 'body must be at most 500 characters');
        }
        $row = Db::rows('create', [$user[0], $body])[0];

        return new Response('{"post":' . self::post([$row[0], $body, $row[1], $user[1], 0]) . '}', 201, self::TYPE);
    }

    public function like(Request $request, string $id): Response
    {
        Profile::$on && Profile::mark('controller');
        $user = Jwt::user($request->headers->get('Authorization'));
        if (is_string($user)) {
            return self::error(401, $user);
        }
        if (! self::isId($id)) {
            return self::error(400, 'invalid post id');
        }
        if (Db::changed('like', [$user[0], (int) $id]) === 1) {
            return new Response('{"liked":true,"already_liked":false,"post_id":' . (int) $id . '}', 201, self::TYPE);
        }

        // Nothing was written: either this user liked it before, or there is no such post.
        return Db::rows('exists', [(int) $id]) === []
            ? self::error(404, 'post not found')
            : new Response('{"liked":true,"already_liked":true,"post_id":' . (int) $id . '}', 200, self::TYPE);
    }

    public function notFound(): Response
    {
        return self::error(404, 'not found');
    }

    public static function error(int $status, string $message): Response
    {
        return new Response('{"error":"' . $message . '"}', $status, self::TYPE);
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
