<?php

declare(strict_types=1);

namespace Spora\Plugins\Memories\Http;

use Spora\Plugins\Memories\Services\Exceptions\MemoryValidationException;
use Spora\Services\Exceptions\AgentNotFoundException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Handles principal-scoped (formerly user-scoped) memory CRUD, reordering,
 * and surgical substring replacement.
 *
 * The principal id is resolved once per request via
 * {@see AbstractMemoryController::requestPrincipalId()} — mirroring how
 * the typst and media-archive plugins anchor writes to a principal.
 */
final class MemoryController extends AbstractMemoryController
{
    /**
     * GET /api/v1/memories
     */
    public function index(Request $request): JsonResponse
    {
        $principalId = $this->requestPrincipalId();
        $type = $request->query->get('type');

        $memories = $this->memoryQuery->listGlobalMemories($principalId, is_string($type) && $type !== '' ? $type : null);

        return new JsonResponse(['data' => ['memories' => $memories]]);
    }

    /**
     * POST /api/v1/memories
     */
    public function store(Request $request): JsonResponse
    {
        $principalId = $this->requestPrincipalId();

        $body = $this->decodeRequestBody($request);
        if ($body instanceof JsonResponse) {
            return $body;
        }

        $validationError = $this->validateCreateInput($body);
        if ($validationError !== null) {
            return $validationError;
        }

        return $this->createMemoryOrError($principalId, $body);
    }

    /**
     * GET /api/v1/memories/{id}
     */
    public function show(Request $request): JsonResponse
    {
        $principalId = $this->requestPrincipalId();
        $memoryId = (string) $request->attributes->get('id', '');

        $result = $this->memoryQuery->getGlobalMemory($memoryId, $principalId);

        if ($result === null) {
            return $this->notFound();
        }

        return new JsonResponse(['data' => $result]);
    }

    /**
     * PUT /api/v1/memories/{id}
     */
    public function update(Request $request): JsonResponse
    {
        $principalId = $this->requestPrincipalId();
        $memoryId = (string) $request->attributes->get('id', '');

        $body = $this->decodeRequestBody($request);
        if ($body instanceof JsonResponse) {
            return $body;
        }

        return $this->updateMemoryOrError($memoryId, $principalId, $body);
    }

    /**
     * POST /api/v1/memories/{id}/replace
     */
    public function replace(Request $request): JsonResponse
    {
        $principalId = $this->requestPrincipalId();
        $memoryId = (string) $request->attributes->get('id', '');

        $body = $this->decodeRequestBody($request);
        if ($body instanceof JsonResponse) {
            return $body;
        }

        $validationError = $this->validateReplaceInput($body);
        if ($validationError !== null) {
            return $validationError;
        }

        return $this->replaceMemoryOrError($memoryId, $principalId, $body);
    }

    /**
     * DELETE /api/v1/memories/{id}
     */
    public function destroy(Request $request): JsonResponse
    {
        $principalId = $this->requestPrincipalId();
        $memoryId = (string) $request->attributes->get('id', '');

        $deleted = $this->memoryCommand->deleteGlobalMemory($memoryId, $principalId);

        if (! $deleted) {
            return $this->notFound();
        }

        return new JsonResponse(['data' => ['deleted' => true]]);
    }

    /**
     * PATCH /api/v1/memories/reorder
     */
    public function reorder(Request $request): JsonResponse
    {
        $principalId = $this->requestPrincipalId();

        $body = $this->decodeRequestBody($request);
        if ($body instanceof JsonResponse) {
            return $body;
        }

        $order = $body['order'] ?? [];
        if (! is_array($order)) {
            return $this->error('VALIDATION_ERROR', 'order must be an array of memory IDs.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $this->memoryCommand->reorderGlobalMemories($principalId, array_values($order));

        return new JsonResponse(['data' => ['success' => true]]);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function validateCreateInput(array $body): ?JsonResponse
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
     * @param array<string, mixed> $body
     */
    private function validateReplaceInput(array $body): ?JsonResponse
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
     * @param array<string, mixed> $body
     */
    private function createMemoryOrError(int $principalId, array $body): JsonResponse
    {
        try {
            $result = $this->memoryCommand->createGlobalMemory($principalId, $body);
            return new JsonResponse(['data' => $result], Response::HTTP_CREATED);
        } catch (MemoryValidationException $e) {
            return $this->error('VALIDATION_ERROR', $e->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
        }
    }

    /**
     * @param array<string, mixed> $body
     */
    private function updateMemoryOrError(string $memoryId, int $principalId, array $body): JsonResponse
    {
        try {
            $result = $this->memoryCommand->updateGlobalMemory($memoryId, $principalId, $body);
        } catch (MemoryValidationException $e) {
            return $this->error('VALIDATION_ERROR', $e->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if ($result === null) {
            return $this->notFound();
        }

        return new JsonResponse(['data' => $result]);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function replaceMemoryOrError(string $memoryId, int $principalId, array $body): JsonResponse
    {
        try {
            $result = $this->memoryCommand->replaceGlobalMemory($memoryId, $principalId, $body);
        } catch (MemoryValidationException $e) {
            return $this->error(
                \Spora\Plugins\Memories\Services\MemoryTypes::REPLACE_NOT_UNIQUE_CODE,
                $e->getMessage(),
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        } catch (AgentNotFoundException) {
            return $this->notFound();
        }

        if ($result === null) {
            return $this->error(
                \Spora\Plugins\Memories\Services\MemoryTypes::REPLACE_NOT_FOUND_CODE,
                'Memory not found for replace.',
                Response::HTTP_NOT_FOUND,
            );
        }

        return new JsonResponse(['data' => $result]);
    }
}
