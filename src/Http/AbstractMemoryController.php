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
use Spora\Services\Exceptions\PrincipalNotAccessibleException;
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
     * Resolve the principal id once per request. Both controllers feed
     * this into the service layer; agent-scoped services then route it
     * through {@see \Spora\Services\PrincipalResolver::ownerUserId()} so
     * the visibility gate at
     * {@see \Spora\Services\PrincipalResolver::isVisibleTo()} expands to
     * the user's full principal set.
     *
     * The frontend's `PrincipalChipRow` lets the operator pick which
     * principal to act as (their own user-principal or any group they
     * belong to). The selection is sent as `?principal_id=N` on every
     * request; if it's absent we fall back to the user principal so
     * callers that don't know about the param keep working. When the
     * principal id is present but the caller doesn't control it (e.g.
     * forged by curl), {@see PrincipalNotAccessibleException} surfaces
     * the rejection as a 403 rather than silently writing under the
     * wrong scope.
     *
     * @throws NotAuthenticatedException When no user is logged in.
     * @throws PrincipalMaterialisationException When the principal row cannot be materialised.
     * @throws PrincipalNotAccessibleException When `?principal_id=` names a principal the caller does not control.
     */
    protected function requestPrincipalId(Request $request): int
    {
        if ($this->resolvedPrincipalId !== null) {
            return $this->resolvedPrincipalId;
        }
        $userId = $this->authService->currentUserId();
        if ($userId === null) {
            throw new NotAuthenticatedException('Authenticated user required');
        }

        $requestedPrincipalId = self::extractPrincipalIdFromRequest($request);
        if ($requestedPrincipalId !== null) {
            // `visiblePrincipalIdsFor` is the right primitive here — it
            // includes the caller's user-principal AND every group
            // principal for groups the user belongs to (any role, not
            // just owner/admin). That's what makes "select a group
            // principal in the chip row" actually mean "manage the
            // group's memories" for ordinary members, not just owners.
            // `PrincipalService::callerControlsPrincipal` would be too
            // restrictive — it's the admin gate on transfers and
            // deletions, not the visibility gate.
            if (! in_array($requestedPrincipalId, $this->principals->visiblePrincipalIdsFor($userId), true)) {
                throw new PrincipalNotAccessibleException(
                    "Caller does not control principal #{$requestedPrincipalId}.",
                );
            }
            $this->resolvedPrincipalId = $requestedPrincipalId;
            return $this->resolvedPrincipalId;
        }

        $this->resolvedPrincipalId = (int) $this->principals->ensureUserPrincipal($userId)->id;

        return $this->resolvedPrincipalId;
    }

    /**
     * Parse `?principal_id=` from the request bag into a positive int.
     * Returns null when the parameter is absent or syntactically invalid
     * so the caller can fall back to the user-principal silently.
     */
    private static function extractPrincipalIdFromRequest(Request $request): ?int
    {
        $raw = $request->query->get('principal_id');
        // `query->get()` typically returns string (URL-encoded query)
        // but Symfony's repeated-key de-dup yields an int for single-
        // valued URL params. Accept either via numeric coercion.
        if ($raw === null || $raw === '') {
            return null;
        }
        $value = is_numeric($raw) ? (int) $raw : -1;
        return $value > 0 ? $value : null;
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
        } catch (Throwable $e) {
            return $this->translateMemoryFailure($e);
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
        } catch (Throwable $e) {
            return $this->translateMemoryFailure($e);
        }

        return $result === null
            ? $this->notFound()
            : new JsonResponse(['data' => $result]);
    }

    /**
     * Validate the `order` body field, then run the agent/global reorder
     * service method via `$operation`. Returns 200 on success, 422 when
     * `order` is not a list, 404 when the agent (or memory ownership
     * check) is missing.
     */
    protected function runReorder(mixed $order, callable $operation): JsonResponse
    {
        if (!is_array($order) || ($order !== [] && !array_is_list($order))) {
            return $this->error('VALIDATION_ERROR', 'order must be an array of memory IDs.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $operation($order);
            return new JsonResponse(['data' => ['success' => true]]);
        } catch (Throwable $e) {
            return $this->translateMemoryFailure($e);
        }
    }

    /**
     * Map service-layer exceptions to the matching HTTP envelope.
     * Centralised so the per-op wrappers (`runCreate`/`runUpdate`/
     * `runReorder`) stay under SonarCloud's S1142 3-return cap.
     * Unknown exception types bubble up to a 500 via the framework
     * error handler — we don't try to translate every possible throw.
     */
    private function translateMemoryFailure(Throwable $e): JsonResponse
    {
        if ($e instanceof MemoryValidationException) {
            return $this->error('VALIDATION_ERROR', $e->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        if ($e instanceof PrincipalNotAccessibleException) {
            return $this->forbidden($e->getMessage());
        }
        if ($e instanceof AgentNotFoundException) {
            return $this->notFound();
        }

        // Re-throw so the global error handler can log + 500 it.
        throw $e;
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

    protected function forbidden(string $message): JsonResponse
    {
        return new JsonResponse(
            ['error' => ['code' => 'FORBIDDEN', 'message' => $message]],
            Response::HTTP_FORBIDDEN,
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
        if ($e instanceof PrincipalNotAccessibleException) {
            return $this->forbidden($e->getMessage());
        }

        return $this->notFound();
    }
}
