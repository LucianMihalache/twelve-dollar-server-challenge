<?php

namespace App\Support;

use App\Http\Controllers\FeedController;
use Illuminate\Http\Request;
use Laravel\Octane\Octane;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/*
| Octane's own routes, with post ids.
|
| Octane can answer a request itself, without sending it through Laravel's HTTP kernel and router (Octane::route).
| Timing each stage of a request showed why that matters here: for an endpoint with no middleware, getting through the
| kernel and the router took several times longer than producing the answer. But a stock Octane route is an exact
| path, and two of the five endpoints carry a post id. This is Octane's route table with those two shapes added,
| bound in place of it (AppServiceProvider). Under Octane every request is answered here, by the same controller
| the routes in routes/api.php name; those routes still serve the application when it runs without Octane.
*/
final class DirectRoutes extends Octane
{
    private ?FeedController $feed = null;

    public function hasRouteFor(string $method, string $uri): bool
    {
        return true;
    }

    public function invokeRoute(Request $request, string $method, string $uri): Response
    {
        try {
            $feed = $this->feed ??= new FeedController;
            if ($method === 'GET') {
                if ($uri === '/feed') {
                    return $feed->feed();
                }
                if (str_starts_with($uri, '/posts/') && ! str_contains($id = substr($uri, 7), '/')) {
                    return $feed->show($id);
                }
                if ($uri === '/health') {
                    return $feed->health();
                }
            } elseif ($method === 'POST') {
                if (str_starts_with($uri, '/posts/') && str_ends_with($uri, '/like') && ! str_contains($id = substr($uri, 7, -5), '/')) {
                    return $feed->like($request, $id);
                }
                if ($uri === '/posts') {
                    return $feed->store($request);
                }
            }

            return $feed->notFound();
        } catch (Throwable $e) {
            report($e);

            return FeedController::error(500, 'internal server error');
        }
    }
}
