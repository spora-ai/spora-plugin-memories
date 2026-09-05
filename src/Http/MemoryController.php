<?php

declare(strict_types=1);

namespace Spora\Plugins\Memories\Http;

use Spora\Plugins\Memories\Services\Exceptions\MemoryValidationException;
use Spora\Services\Exceptions\AgentNotFoundException;
use Spora\Services\Exceptions\PrincipalNotAccessibleException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Handles principal-scoped (formerly user-scoped) memory CRUD, reordering,
 * and surgical substring replacement.
 *
 * The principal id is resolved once per request via
 * {@see AbstractMemoryController::requestPrincipalId()} — mirroring how
 * the typst and media-archive plugins anchor writes to a principal.
 * When the frontend's `PrincipalChipRow` is on a group principal, the
 * caller sends `?principal_id=<group>` on every request; the resolver
 * honours it when the caller controls it (i.e. is `admin`/`owner` of
 * the group) and 403s otherwise.
 */
final class MemoryController extends AbstractMemoryController
{
    /**
     * GET /api/v1/memories
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $principalId = $this->requestPrincipalId($request);
            $type = $request->query->get('type');

            $memories = $this->memoryQuery->listGlobalMemories($principalId, is_string($type) && $type !== '' ? $type : null);
        } catch (PrincipalNotAccessibleException $e) {
            return $this->forbidden($e->getMessage());
        }

        return new JsonResponse(['data' => ['memories' => $memories]]);
    }

    /**
     * POST /api/v1/memories
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $principalId = $this->requestPrincipalId($request);

            $body = $this->decodeRequestBody($request);
            if ($body instanceof JsonResponse) {
                return $body;
            }

            $validationError = $this->validateCreateInput($body);
            return $validationError ?? $this->runCreate(fn() => $this->memoryCommand->createGlobalMemory($principalId, $body));
        } catch (PrincipalNotAccessibleException $e) {
            return $this->forbidden($e->getMessage());
        }
    }

    /**
     * GET /api/v1/memories/{id}
     */
    public function show(Request $request): JsonResponse
    {
        try {
            $principalId = $this->requestPrincipalId($request);
            $memoryId = (string) $request->attributes->get('id', '');

            $result = $this->memoryQuery->getGlobalMemory($memoryId, $principalId);
        } catch (PrincipalNotAccessibleException $e) {
            return $this->forbidden($e->getMessage());
        }

        return $result === null ? $this->notFound() : new JsonResponse(['data' => $result]);
    }

    /**
     * PUT /api/v1/memories/{id}
     */
    public function update(Request $request): JsonResponse
    {
        try {
            $principalId = $this->requestPrincipalId($request);
            $memoryId = (string) $request->attributes->get('id', '');

            $body = $this->decodeRequestBody($request);
            if ($body instanceof JsonResponse) {
                return $body;
            }

            return $this->runUpdate(fn() => $this->memoryCommand->updateGlobalMemory($memoryId, $principalId, $body));
        } catch (PrincipalNotAccessibleException $e) {
            return $this->forbidden($e->getMessage());
        }
    }

    /**
     * POST /api/v1/memories/{id}/replace
     */
    public function replace(Request $request): JsonResponse
    {
        try {
            $principalId = $this->requestPrincipalId($request);
            $memoryId = (string) $request->attributes->get('id', '');

            $body = $this->decodeRequestBody($request);
            if ($body instanceof JsonResponse) {
                return $body;
            }

            $validationError = $this->validateReplaceInput($body);
            return $validationError ?? $this->runReplace(fn() => $this->memoryCommand->replaceGlobalMemory($memoryId, $principalId, $body));
        } catch (PrincipalNotAccessibleException $e) {
            return $this->forbidden($e->getMessage());
        }
    }

    /**
     * DELETE /api/v1/memories/{id}
     */
    public function destroy(Request $request): JsonResponse
    {
        try {
            $principalId = $this->requestPrincipalId($request);
            $memoryId = (string) $request->attributes->get('id', '');

            $deleted = $this->memoryCommand->deleteGlobalMemory($memoryId, $principalId);
        } catch (PrincipalNotAccessibleException $e) {
            return $this->forbidden($e->getMessage());
        }

        return $deleted ? new JsonResponse(['data' => ['deleted' => true]]) : $this->notFound();
    }

    /**
     * PATCH /api/v1/memories/reorder
     */
    public function reorder(Request $request): JsonResponse
    {
        try {
            $principalId = $this->requestPrincipalId($request);

            $body = $this->decodeRequestBody($request);
            if ($body instanceof JsonResponse) {
                return $body;
            }

            return $this->runReorder(
                $body['order'] ?? [],
                fn(array $order) => $this->memoryCommand->reorderGlobalMemories($principalId, $order),
            );
        } catch (PrincipalNotAccessibleException $e) {
            return $this->forbidden($e->getMessage());
        }
    }

    private function runReplace(callable $operation): JsonResponse
    {
        try {
            return $this->replaceResponse($operation());
        } catch (MemoryValidationException | AgentNotFoundException $e) {
            return $this->replaceResponse(null, $e);
        }
    }
}
