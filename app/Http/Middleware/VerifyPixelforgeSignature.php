<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyPixelforgeSignature
{
    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        $secret = config('services.pixelforge.webhook_secret');

        if (! is_string($secret) || $secret === '') {
            return response()->json(['message' => 'Webhook is unavailable.'], 503);
        }

        $header = (string) $request->header('Pixelforge-Signature');

        if (preg_match('/\At=([0-9]+),\s*v1=([a-f0-9]{64})\z/', $header, $parts) !== 1) {
            return response()->json(['message' => 'Invalid webhook signature.'], 400);
        }

        $timestamp = filter_var($parts[1], FILTER_VALIDATE_INT);

        if ($timestamp === false || $timestamp < 1 || abs(now()->getTimestamp() - $timestamp) > 300) {
            return response()->json(['message' => 'Invalid webhook signature.'], 400);
        }

        $expected = hash_hmac('sha256', $parts[1].'.'.$request->getContent(), $secret);

        if (! hash_equals($expected, $parts[2])) {
            return response()->json(['message' => 'Invalid webhook signature.'], 400);
        }

        return $next($request);
    }
}
