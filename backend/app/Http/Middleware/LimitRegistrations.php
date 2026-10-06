<?php

namespace App\Http\Middleware;

use App\Support\LocalJsonStore;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class LimitRegistrations
{
    public function __construct(private readonly LocalJsonStore $store) {}

    public function handle(Request $request, Closure $next): Response
    {
        $now = now()->timestamp;
        $key = hash('sha256', (string) $request->ip());
        $entry = $this->store->update(function (array &$state) use ($key, $now): array {
            $entry = $state['registration'][$key] ?? [
                'count' => 0,
                'expires_at' => $now + 3600,
            ];
            $entry['count']++;
            $state['registration'][$key] = $entry;

            return $entry;
        });

        if ($entry['count'] > 5) {
            $retryAfter = max(1, $entry['expires_at'] - $now);

            return response()->json([
                'message' => 'Registration limit has been hit. Please wait for an hour and try to register again.',
            ], 429)
                ->header('Retry-After', (string) $retryAfter);
        }

        return $next($request);
    }
}
