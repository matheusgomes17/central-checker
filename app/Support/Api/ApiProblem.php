<?php

declare(strict_types=1);

namespace App\Support\Api;

use App\Exceptions\PlanFeatureException;
use App\Exceptions\PlanLimitException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * One error shape for the whole API, following Problem Details (RFC 7807).
 *
 * Without it, whoever consumes the API writes a parser per failure: validation
 * comes out one way, a missing record another, and a crash a third. The point
 * of a single shape is that a client can handle "something went wrong" once.
 *
 * The detail of an unexpected error is deliberately generic outside of local
 * environments — an exception message is written for whoever wrote the code,
 * and it routinely contains table names, file paths and query fragments.
 */
final readonly class ApiProblem
{
    /**
     * @param  array<string, array<int, string>>  $errors
     */
    private function __construct(
        private int $status,
        private string $title,
        private string $detail,
        private array $errors = [],
    ) {}

    public static function from(Throwable $e): self
    {
        return match (true) {
            $e instanceof ValidationException => new self(
                Response::HTTP_UNPROCESSABLE_ENTITY,
                'validation_failed',
                __('The given data was invalid.'),
                self::messagesFrom($e),
            ),
            $e instanceof AuthenticationException => new self(
                Response::HTTP_UNAUTHORIZED,
                'unauthenticated',
                __('A valid API token is required.'),
            ),
            $e instanceof AuthorizationException => new self(
                Response::HTTP_FORBIDDEN,
                'forbidden',
                $e->getMessage() !== '' ? $e->getMessage() : __('This action is not allowed.'),
            ),
            $e instanceof ModelNotFoundException => new self(
                Response::HTTP_NOT_FOUND,
                'not_found',
                __('The requested resource does not exist.'),
            ),
            $e instanceof PlanLimitException => new self(
                Response::HTTP_PAYMENT_REQUIRED,
                'plan_limit_reached',
                $e->getMessage(),
            ),
            $e instanceof PlanFeatureException => new self(
                Response::HTTP_PAYMENT_REQUIRED,
                'plan_feature_required',
                $e->getMessage(),
            ),
            $e instanceof HttpExceptionInterface => new self(
                $e->getStatusCode(),
                self::titleForStatus($e->getStatusCode()),
                $e->getMessage() !== '' ? $e->getMessage() : self::titleForStatus($e->getStatusCode()),
            ),
            default => new self(
                Response::HTTP_INTERNAL_SERVER_ERROR,
                'server_error',
                app()->hasDebugModeEnabled() ? $e->getMessage() : __('Something went wrong on our side.'),
            ),
        };
    }

    public function toResponse(): JsonResponse
    {
        $body = [
            'type' => 'https://developer.example.com/errors/'.$this->title,
            'title' => $this->title,
            'status' => $this->status,
            'detail' => $this->detail,
        ];

        if ($this->errors !== []) {
            $body['errors'] = $this->errors;
        }

        return response()->json($body, $this->status, ['Content-Type' => 'application/problem+json']);
    }

    /**
     * @return array<string, array<int, string>>
     */
    private static function messagesFrom(ValidationException $e): array
    {
        $errors = [];

        foreach ($e->errors() as $field => $messages) {
            $texts = [];

            foreach ((array) $messages as $message) {
                if (is_string($message)) {
                    $texts[] = $message;
                }
            }

            $errors[(string) $field] = $texts;
        }

        return $errors;
    }

    private static function titleForStatus(int $status): string
    {
        return match ($status) {
            Response::HTTP_UNAUTHORIZED => 'unauthenticated',
            Response::HTTP_PAYMENT_REQUIRED => 'payment_required',
            Response::HTTP_FORBIDDEN => 'forbidden',
            Response::HTTP_NOT_FOUND => 'not_found',
            Response::HTTP_TOO_MANY_REQUESTS => 'rate_limited',
            default => 'error',
        };
    }
}
