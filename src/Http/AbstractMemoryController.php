<?php

declare(strict_types=1);

namespace Spora\Plugins\Memories\Http;

use JsonException;
use Spora\Auth\AuthService;
use Spora\Plugins\Memories\Exceptions\NotAuthenticatedException;
use Spora\Plugins\Memories\Services\Exceptions\MemoryValidationException;
use Spora\Plugins\Memories\Services\MemoryCommandInterface;
use Spora\Plugins\Memories\Services\MemoryQueryInterface;
use Spora\Plugins\Memories\Services\MemoryTypes;
use Spora\Services\Exceptions\AgentNotFoundException;
use Spora\Services\Exceptions\PrincipalMaterialisationException;
use Spora\Services\PrincipalService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Shared surface for principal-scoped (global) and agent-scoped memory
 * controllers. Both siblings target the same Memory domain and only
 * diverge in which service method they call (e.g. `createGlobalMemory`
 * vs `createAgentMemory`) and which URL parameter identifies the
 * target (memory id vs memory id + agent id).
 *
 * Hoisting the principal-resolver cache, JSON decoder, error envelope
 * factory, and per-op input validation here is what keeps both
 * controllers under Sonar's per-method return-count ceiling and
 * eliminates the duplicated `resolvePrincipalId()` / `decodeJson()` /
 * `error()` / `notFound()` blocks the gate flagged.
 */
abstract class AbstractMemoryController
{
    protected const INVALID_JSON_MESSAGE = 'Request body must be valid JSON.';

    private ?int $resolvedPrincipalId = null;

    public function __construct(
        protected readonly AuthService $authService,
        protected readonly MemoryQueryInterface $memoryQuery,
        protected readonly MemoryCommandInterface $memoryCommand,
        protected readonly PrincipalService $principals,
    ) {}

    /**
     * Resolve the principal id once per request. Re-throws
     * {@see PrincipalMaterialisationException} verbatim instead of wrapping
     * it in a generic RuntimeException so the HTTP layer can recognise
     * the failure mode without parsing messages.
     *
     * @throws NotAuthenticatedException When no user is logged in.
     * @throws PrincipalMaterialisationException When the principal row cannot be materialised.
     */
    protected function requestPrincipalId(): int
    {
        if ($this->resolvedPrincipalId !== null) {
            return $this->resolvedPrincipalId;
        }
        $userId = $this->authService->currentUserId();
        if ($userId === null) {
            throw new NotAuthenticatedException('Authenticated user required');
        }
        $this->resolvedPrincipalId = (int) $this->principals->ensureUserPrincipal($userId)->id;

        return $this->resolvedPrincipalId;
    }

    /**
     * Parse the JSON body, returning a 400 envelope on parse failure so
     * the caller can early-return without a try/catch.
     *
     * @return array|JsonResponse
     */
    protected function decodeRequestBody(Request $request): array|JsonResponse
    {
        $content = $request->getContent();
        if ($content === '') {
            return [];
        }

        try {
            return json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return $this->error('INVALID_JSON', self::INVALID_JSON_MESSAGE, Response::HTTP_BAD_REQUEST);
        }
    }

    /**
     * Validate that `$type` is one of {@see MemoryTypes::DOCUMENT_TYPES}.
     * Returns the 422 envelope to return, or null when the type is valid
     * — collapses the per-call `in_array` / sprintf duplication.
     */
    protected function validateType(string $type): ?JsonResponse
    {
        if (in_array($type, MemoryTypes::DOCUMENT_TYPES, true)) {
            return null;
        }

        return $this->error(
            MemoryTypes::TYPE_NOT_ALLOWED_CODE,
            sprintf("type '%s' is not one of: %s", $type, implode(', ', MemoryTypes::DOCUMENT_TYPES)),
            Response::HTTP_UNPROCESSABLE_ENTITY,
        );
    }

    /**
     * Shared validation for `POST .../memories`: name and type must be
     * present and the type must be in the document-type enum.
     *
     * @param array<string, mixed> $body
     */
    protected function validateCreateInput(array $body): ?JsonResponse
    {
        $name = trim((string) ($body['name'] ?? ''));
        if ($name === '') {
            return $this->error('VALIDATION_ERROR', 'name is required.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $type = $body['type'] ?? null;
        if (!is_string($type) || $type === '') {
            return $this->error('VALIDATION_ERROR', 'type is required.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->validateType($type);
    }

    /**
     * Shared validation for `POST .../replace`: name, type, and `find`
     * are required; the type must be in the document-type enum.
     *
     * @param array<string, mixed> $body
     */
    protected function validateReplaceInput(array $body): ?JsonResponse
    {
        $name = trim((string) ($body['name'] ?? ''));
        $type = (string) ($body['type'] ?? '');
        $find = (string) ($body['find'] ?? '');

        if ($name === '' || $type === '' || $find === '') {
            return $this->error(
                'VALIDATION_ERROR',
                'name, type, and find are required.',
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        return $this->validateType($type);
    }

    /**
     * Wrap a `create*` service call: turn the success into a 201
     * envelope, convert `MemoryValidationException` to a 422 error, and
     * `AgentNotFoundException` (agent scope) to a 404. The agent-side
     * exception is harmless when invoked from the global controller —
     * the global path never throws it.
     *
     * @return JsonResponse 201 on success, 404 / 422 on the matching failure.
     */
    protected function runCreate(callable $operation): JsonResponse
    {
        try {
            $result = $operation();
            return new JsonResponse(['data' => $result], Response::HTTP_CREATED);
        } catch (MemoryValidationException $e) {
            return $this->error('VALIDATION_ERROR', $e->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (AgentNotFoundException) {
            return $this->notFound();
        }
    }

    /**
     * Wrap an `update*` service call: 200 on success, 422 on validation
     * failure, 404 when the memory disappeared.
     *
     * @return JsonResponse 200 with the updated memory on success, 404 / 422 otherwise.
     */
    protected function runUpdate(callable $operation): JsonResponse
    {
        try {
            $result = $operation();
        } catch (MemoryValidationException $e) {
            return $this->error('VALIDATION_ERROR', $e->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if ($result === null) {
            return $this->notFound();
        }

        return new JsonResponse(['data' => $result]);
    }

    protected function error(string $code, string $message, int $status): JsonResponse
    {
        return new JsonResponse(['error' => ['code' => $code, 'message' => $message]], $status);
    }

    protected function notFound(): JsonResponse
    {
        return new JsonResponse(
            ['error' => ['code' => 'NOT_FOUND', 'message' => 'Memory not found.']],
            Response::HTTP_NOT_FOUND,
        );
    }

    /**
     * Translate the result of a `replace*` service call into the right
     * JSON response — distinguishes the "found and replaced" success from
     * the "memory disappeared" not-found, and rethrows validation/agent
     * lookup failures as typed responses instead of leaking generic
     * RuntimeExceptions into the global error handler.
     *
     * @param array<string, mixed>|null $result
     * @param Throwable|null $caughtException Exception caught while attempting the replace, if any.
     */
    protected function replaceResponse(?array $result, ?Throwable $caughtException = null): JsonResponse
    {
        if ($caughtException !== null) {
            return $this->errorResponseForReplaceException($caughtException);
        }
        if ($result === null) {
            return $this->error(
                MemoryTypes::REPLACE_NOT_FOUND_CODE,
                'Memory not found for replace.',
                Response::HTTP_NOT_FOUND,
            );
        }

        return new JsonResponse(['data' => $result]);
    }

    private function errorResponseForReplaceException(Throwable $e): JsonResponse
    {
        if ($e instanceof MemoryValidationException) {
            return $this->error(
                MemoryTypes::REPLACE_NOT_UNIQUE_CODE,
                $e->getMessage(),
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        return $this->notFound();
    }
}
