<?php

namespace App\Support;

use Illuminate\Foundation\Events\Terminating;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Routing\Events\PreparingResponse;
use Illuminate\Routing\Events\ResponsePrepared;
use Illuminate\Routing\Events\RouteMatched;
use Illuminate\Routing\Events\Routing;
use Illuminate\Support\Facades\Event;
use Laravel\Octane\Events\RequestReceived;
use Laravel\Octane\Events\RequestTerminated;

/*
| A stopwatch for one request, off unless the server is started with FEED_PROFILE=1 (then it costs a few listeners
| per request, so it is never on for a load test). It notes when PHP received the request and when each stage of
| Octane and Laravel was reached, using the events they announce anyway, and how long the database took.
|
|   - the answer carries two headers: X-Feed-Received (the clock when PHP got the request, Unix seconds) and
|     Server-Timing (milliseconds from then until the answer was ready), so a caller can see what the trip added;
|   - every request adds one line to storage/logs/profile.log with the time of each stage in microseconds.
*/
final class Profile
{
    public static bool $on = false;

    /** @var array<string, float> stage => seconds on the clock */
    private static array $at = [];

    private static float $dbNanoseconds = 0.0;
    private static int $queries = 0;

    public static function register(): void
    {
        self::$on = (bool) ($_SERVER['FEED_PROFILE'] ?? getenv('FEED_PROFILE'));
        if (! self::$on) {
            return;
        }
        // Registered last, so it runs after Octane's own work on a new request (its resets).
        Event::listen(RequestReceived::class, function (RequestReceived $event): void {
            self::$at = ['received' => (float) $event->request->server('REQUEST_TIME_FLOAT', microtime(true)), 'octane' => microtime(true)];
            self::$dbNanoseconds = 0.0;
            self::$queries = 0;
        });
        Event::listen(Routing::class, fn () => self::mark('routing'));
        Event::listen(RouteMatched::class, fn () => self::mark('matched'));
        Event::listen(PreparingResponse::class, fn () => self::mark('answered'));
        Event::listen(ResponsePrepared::class, function (ResponsePrepared $event): void {
            self::mark('prepared');
            $event->response->headers->set('X-Feed-Received', sprintf('%.6f', self::$at['received']));
            $event->response->headers->set('Server-Timing', sprintf('app;dur=%.3f, db;dur=%.3f', (self::$at['prepared'] - self::$at['received']) * 1000, self::$dbNanoseconds / 1e6));
        });
        Event::listen(RequestHandled::class, fn () => self::mark('handled'));
        Event::listen(Terminating::class, fn () => self::mark('sent'));
        Event::listen(RequestTerminated::class, function (RequestTerminated $event): void {
            self::mark('end');
            self::write($event->request->getMethod() . ' ' . $event->request->getPathInfo(), $event->response->getStatusCode());
        });
    }

    public static function mark(string $stage): void
    {
        self::$at[$stage] ??= microtime(true);
    }

    public static function database(float $nanoseconds): void
    {
        self::$dbNanoseconds += $nanoseconds;
        self::$queries++;
    }

    private static function write(string $what, int $status): void
    {
        $us = static fn (string $from, string $to): ?int => isset(self::$at[$from], self::$at[$to]) ? (int) round((self::$at[$to] - self::$at[$from]) * 1e6) : null;
        $db = (int) round(self::$dbNanoseconds / 1000);
        $controller = $us('controller', 'answered');
        $line = [
            'request'        => $what,
            'status'         => $status,
            'received_at'    => sprintf('%.6f', self::$at['received']),
            'total'          => $us('received', 'end'),
            'until_sent'     => $us('received', 'sent'),
            'stages'         => [
                'octane: new request, its resets'        => $us('received', 'octane'),
                'laravel: into the kernel'               => $us('octane', 'routing'),
                'laravel: find the route'                => $us('routing', 'matched'),
                'laravel: reach the controller'          => $us('matched', 'controller'),
                'controller without the database'        => $controller === null ? null : $controller - $db,
                'database (' . self::$queries . ' queries)' => $db,
                'laravel: turn the answer into a response' => $us('answered', 'prepared'),
                'laravel: out of the kernel'             => $us('prepared', 'handled'),
                'octane: send the response'              => $us('handled', 'sent'),
                'after the answer: clean-up'             => $us('sent', 'end'),
            ],
        ];
        @file_put_contents($_SERVER['FEED_PROFILE_LOG'] ?? storage_path('logs/profile.log'), json_encode($line, JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND | LOCK_EX);
    }
}
