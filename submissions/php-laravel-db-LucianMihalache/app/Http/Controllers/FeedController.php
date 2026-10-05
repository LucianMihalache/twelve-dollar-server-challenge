<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

/*
| The five endpoints of SPEC.md, with the queries written as SQL and run through Laravel's DB facade. A row comes
| back as an object whose fields are in the order of the SELECT, which is the key order the spec asks for.
*/
class FeedController extends Controller
{
    private const POST = 'SELECT p.id, p.body, p.created_at, u.username AS author,
               (SELECT count(*) FROM likes l WHERE l.post_id = p.id) AS like_count
          FROM posts p JOIN users u ON u.id = p.user_id';

    private static ?int $startedAt = null;

    public function health(): JsonResponse
    {
        self::$startedAt ??= time();
        try {
            DB::select('SELECT 1');
        } catch (Throwable $e) {
            return response()->json(['status' => 'degraded', 'db' => 'unreachable', 'error' => $e->getMessage()], 503);
        }

        return response()->json(['status' => 'ok', 'db' => 'ok', 'uptime_s' => time() - self::$startedAt]);
    }

    public function feed(): JsonResponse
    {
        return response()->json(['posts' => DB::select(self::POST . ' ORDER BY p.created_at DESC, p.id DESC LIMIT 20')]);
    }

    public function show(string $id): JsonResponse
    {
        if (! $this->isId($id)) {
            return response()->json(['error' => 'invalid post id'], 400);
        }
        $post = DB::selectOne(self::POST . ' WHERE p.id = ?', [(int) $id]);

        return $post === null ? response()->json(['error' => 'post not found'], 404) : response()->json(['post' => $post]);
    }

    public function store(Request $request): JsonResponse
    {
        if (! json_validate($request->getContent())) {
            return response()->json(['error' => 'malformed JSON body'], 400);
        }
        // Laravel's middleware has trimmed the text and turned an empty one into null before this runs.
        $body = $request->validate(
            ['body' => ['required', 'string', 'max:500']],
            ['body.required' => 'body is required', 'body.string' => 'body is required', 'body.max' => 'body must be at most 500 characters'],
        )['body'];

        $row = DB::selectOne('INSERT INTO posts (user_id, body) VALUES (?, ?) RETURNING id, created_at', [$request->attributes->get('user_id'), $body]);

        return response()->json(['post' => [
            'id' => $row->id, 'body' => $body, 'created_at' => $row->created_at, 'author' => $request->attributes->get('username'), 'like_count' => 0,
        ]], 201);
    }

    public function like(Request $request, string $id): JsonResponse
    {
        if (! $this->isId($id)) {
            return response()->json(['error' => 'invalid post id'], 400);
        }
        // One statement: the like is written only when the post exists, and a repeat changes nothing.
        $written = DB::affectingStatement(
            'INSERT INTO likes (user_id, post_id) SELECT ?, id FROM posts WHERE id = ? ON CONFLICT (user_id, post_id) DO NOTHING',
            [$request->attributes->get('user_id'), (int) $id],
        );
        if ($written === 0 && DB::selectOne('SELECT 1 FROM posts WHERE id = ?', [(int) $id]) === null) {
            return response()->json(['error' => 'post not found'], 404);
        }

        return response()->json(['liked' => true, 'already_liked' => $written === 0, 'post_id' => (int) $id], $written === 1 ? 201 : 200);
    }

    /** A positive integer written in digits only: "0", "-1", "abc" and "1.5" are not. */
    private function isId(string $id): bool
    {
        return isset($id[0]) && ! isset($id[18]) && ctype_digit($id) && (int) $id > 0;
    }
}
