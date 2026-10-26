<?php

namespace App\Support\OpenApi;

use Dedoc\Scramble\Extensions\ExceptionToResponseExtension;
use Dedoc\Scramble\Support\Generator\Response;
use Dedoc\Scramble\Support\Generator\Schema;
use Dedoc\Scramble\Support\Generator\Types as OpenApiTypes;
use Dedoc\Scramble\Support\Type\ObjectType;
use Dedoc\Scramble\Support\Type\Type;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The project envelope for the four statuses Scramble infers on its own.
 *
 * Scramble answers an inferred exception with `{message}`. This project answers
 * it with `{message, code, meta}` (`docs/base/api/error-contract.md`), so the
 * generated document described a body no client would ever receive — 148 of
 * 180 documented error responses, before this class existed.
 *
 * Registered through `Scramble::registerExtension()`; the package reverses the
 * extension list, so a later registration wins over its own default extension
 * for the same exception. Statuses this class does not cover are the ones an
 * endpoint annotates itself (`#[Response]`), because only the code that raises
 * them knows which of the contract's codes applies — a 409 is `LAST_SUPERADMIN`
 * on one route and `HTTP_ERROR` on another.
 */
class ApiErrorResponseExtension extends ExceptionToResponseExtension
{
    /**
     * Exception class => [status, the one code that status carries here].
     *
     * Mirrors the branches in `bootstrap/app.php`; `AccessDeniedHttpException`
     * is listed separately because `abort(403)` and a failed `can:` do not share
     * a parent, and the handler answers both with `FORBIDDEN`.
     */
    private const ENVELOPE = [
        ValidationException::class => [422, 'VALIDATION_ERROR'],
        AuthenticationException::class => [401, 'UNAUTHENTICATED'],
        AuthorizationException::class => [403, 'FORBIDDEN'],
        AccessDeniedHttpException::class => [403, 'FORBIDDEN'],
        ModelNotFoundException::class => [404, 'NOT_FOUND'],
        NotFoundHttpException::class => [404, 'NOT_FOUND'],
    ];

    public function shouldHandle(Type $type): bool
    {
        return $this->match($type) !== null;
    }

    public function toResponse(Type $type): ?Response
    {
        $match = $this->match($type);

        if ($match === null) {
            return null;
        }

        [$status, $code] = $match;

        $required = ['message', 'code', 'meta'];

        $body = (new OpenApiTypes\ObjectType())
            ->addProperty('message', (new OpenApiTypes\StringType())->setDescription('Human-readable summary.'))
            ->addProperty('code', (new OpenApiTypes\StringType())->setDescription("Machine-readable code. `{$code}` for this status."))
            ->addProperty('meta', (new OpenApiTypes\ObjectType())
                ->addProperty('request_id', (new OpenApiTypes\StringType())->setDescription('Matches `X-Request-ID` on the response.'))
                ->addProperty('timestamp', (new OpenApiTypes\StringType())->setDescription('ISO-8601.'))
                ->setRequired(['request_id', 'timestamp']));

        // Only 422 adds a key: the field map, per `error-contract.md`.
        if ($status === 422) {
            $required[] = 'errors';

            $body->addProperty('errors', (new OpenApiTypes\ObjectType())
                ->setDescription('Field name => messages.')
                ->additionalProperties((new OpenApiTypes\ArrayType())->setItems(new OpenApiTypes\StringType())));
        }

        return Response::make($status)
            ->setDescription($this->description($status))
            ->setContent('application/json', Schema::fromType($body->setRequired($required)));
    }

    /**
     * @return array{0: int, 1: string}|null
     */
    private function match(Type $type): ?array
    {
        if (! $type instanceof ObjectType) {
            return null;
        }

        foreach (self::ENVELOPE as $exception => $envelope) {
            if ($type->isInstanceOf($exception)) {
                return $envelope;
            }
        }

        return null;
    }

    private function description(int $status): string
    {
        return match ($status) {
            401 => 'Unauthenticated',
            403 => 'Forbidden',
            404 => 'Not found',
            422 => 'Validation failed',
        };
    }
}
