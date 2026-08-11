<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gives every request an identifier and puts it on every line it writes.
 *
 * Without it, reading production logs means guessing which of the lines
 * interleaved from a dozen concurrent requests belong together — and the
 * guessing gets worse exactly when traffic is high, which is when the incident
 * usually is.
 *
 * An inbound X-Request-Id is kept so a trace started by a load balancer or
 * by the caller carries through instead of being renamed at our door. It is
 * length-capped because it is a value from outside that ends up in every log
 * line: a caller sending a megabyte header should not be able to write a
 * megabyte per line into our logs.
 */
class AddRequestContext
{
    public const string HEADER = 'X-Request-Id';

    private const int MAX_LENGTH = 64;

    public function handle(Request $request, Closure $next): Response
    {
        $requestId = $this->incomingId($request) ?? (string) Str::uuid7();

        Context::add('request_id', $requestId);

        /** @var Response $response */
        $response = $next($request);

        // Devolvido ao cliente: sem isto, quem abre um chamado não tem como
        // dizer qual requisição falhou, e a busca vira caça ao horário.
        $response->headers->set(self::HEADER, $requestId);

        return $response;
    }

    private function incomingId(Request $request): ?string
    {
        $header = $request->header(self::HEADER);

        if (! is_string($header) || trim($header) === '') {
            return null;
        }

        return Str::limit(trim($header), self::MAX_LENGTH, '');
    }
}
