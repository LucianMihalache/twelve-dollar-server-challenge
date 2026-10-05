<?php

namespace App\Http\Controllers;

use App\Http\Resources\PostResource;
use App\Models\Like;
use App\Models\Post;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

/*
| The five endpoints of SPEC.md the way a Laravel application usually writes them: Eloquent models with their
| relations (a post's author, its likes), and an API resource that shapes a post.
*/
class FeedController extends Controller
{
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
        $posts = Post::with('user:id,username')->withCount('likes')
            ->orderByDesc('created_at')->orderByDesc('id')->limit(20)->get();

        return response()->json(['posts' => PostResource::collection($posts)]);
    }

    public function show(string $id): JsonResponse
    {
        if (! $this->isId($id)) {
            return response()->json(['error' => 'invalid post id'], 400);
        }
        // A missing post throws ModelNotFoundException, which bootstrap/app.php answers as 404 "post not found".
        $post = Post::with('user:id,username')->withCount('likes')->findOrFail((int) $id);

        return response()->json(['post' => new PostResource($post)]);
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

        // created_at is the database's default: read the row back to have it.
        $post = Post::create(['user_id' => $request->attributes->get('user_id'), 'body' => $body])->refresh();
        // The author is the name in the token, as the spec says: it is not looked up.
        $post->setRelation('user', new User(['username' => $request->attributes->get('username')]));

        return response()->json(['post' => new PostResource($post)], 201);
    }

    public function like(Request $request, string $id): JsonResponse
    {
        if (! $this->isId($id)) {
            return response()->json(['error' => 'invalid post id'], 400);
        }
        $post = Post::findOrFail((int) $id);
        // One like per user and post: a repeat inserts nothing (the table's primary key is the pair).
        $written = Like::insertOrIgnore(['user_id' => $request->attributes->get('user_id'), 'post_id' => $post->id]);

        return response()->json(['liked' => true, 'already_liked' => $written === 0, 'post_id' => $post->id], $written === 1 ? 201 : 200);
    }

    /** A positive integer written in digits only: "0", "-1", "abc" and "1.5" are not. */
    private function isId(string $id): bool
    {
        return isset($id[0]) && ! isset($id[18]) && ctype_digit($id) && (int) $id > 0;
    }
}
