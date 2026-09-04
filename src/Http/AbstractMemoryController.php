<?php

declare(strict_types=1);

namespace Spora\Plugins\Memories\Http;

use JsonException;
use Spora\Auth\AuthService;
use Spora\Plugins\Memories\Exceptions\NotAuthenticatedException;
use Spora\Plugins\Memories\Services\MemoryCommandInterface;
use Spora\Plugins\Memories\Services\MemoryQueryInterface;
use Spora\Plugins\Memories\Services\MemoryTypes;
use Spora\Services\Exceptions\PrincipalMaterialisationException;
use Spora\Services\PrincipalService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

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
     * @return array|JsonResponse Empty array body or a 400 envelope.
     */
    protected function decodeJson(Request $request): array|JsonResponse
    {
        return $this->decodeRequestBody($request);
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
}
