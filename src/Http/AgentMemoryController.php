<?php

declare(strict_types=1);

namespace Spora\Plugins\Memories\Http;

use JsonException;
use RuntimeException;
use Spora\Auth\AuthService;
use Spora\Plugins\Memories\Services\MemoryServiceInterface;
use Spora\Services\Exceptions\PrincipalMaterialisationException;
use Spora\Services\PrincipalService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Handles agent-scoped memory CRUD, reordering, and surgical substring
 * replacement. Agent memories are keyed by `agent_id` and travel with the
 * agent across principal transfers — the principal id from the controller
 * is used only as an ownership-gate to make sure the caller can see the
 * agent before any service call lands.
 */
final class AgentMemoryController
{
    private const INVALID_JSON_MESSAGE = 'Request body must be valid JSON.';

    private ?int $resolvedPrincipalId = null;

    public function __construct(
        private readonly AuthService $authService,
        private readonly MemoryServiceInterface $memoryService,
        private readonly PrincipalService $principals,
    ) {}

    /**
     * GET /api/v1/agents/{agentId}/memories
     */
    public function index(Request $request): JsonResponse
    {
        $principalId = $this->resolvePrincipalId();
        $agentId = (int) $request->attributes->get('agentId', 0);
        $type = $request->query->get('type');

        $memories = $this->memoryService->listAgentMemories($agentId, $principalId, is_string($type) && $type !== '' ? $type : null);

        if ($memories === null) {
            return $this->notFound();
        }

        return new JsonResponse(['data' => ['memories' => $memories]]);
    }

    /**
     * POST /api/v1/agents/{agentId}/memories
     */
    public function store(Request $request): JsonResponse
    {
        $principalId = $this->resolvePrincipalId();
        $agentId = (int) $request->attributes->get('agentId', 0);

        try {
            $body = $this->decodeJson($request);
        } catch (JsonException) {
            return $this->error('INVALID_JSON', self::INVALID_JSON_MESSAGE, Response::HTTP_BAD_REQUEST);
        }

        $name = trim((string) ($body['name'] ?? ''));
        $type = $body['type'] ?? null;

        if ($name === '') {
            return $this->error('VALIDATION_ERROR', 'name is required.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        if (!is_string($type) || $type === '') {
            return $this->error('VALIDATION_ERROR', 'type is required.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        if (!in_array($type, \Spora\Plugins\Memories\Services\MemoryService::DOCUMENT_TYPES, true)) {
            return $this->error(
                \Spora\Plugins\Memories\Services\MemoryService::TYPE_NOT_ALLOWED_CODE,
                sprintf("type '%s' is not one of: %s", $type, implode(', ', \Spora\Plugins\Memories\Services\MemoryService::DOCUMENT_TYPES)),
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        return $this->createMemoryOrNotFound($agentId, $principalId, $body);
    }

    private function createMemoryOrNotFound(int $agentId, int $principalId, array $body): JsonResponse
    {
        try {
            $result = $this->memoryService->createAgentMemory($agentId, $principalId, $body);
            return new JsonResponse(['data' => $result], Response::HTTP_CREATED);
        } catch (RuntimeException) {
            return $this->notFound();
        }
    }

    /**
     * GET /api/v1/agents/{agentId}/memories/{memoryId}
     */
    public function show(Request $request): JsonResponse
    {
        $principalId = $this->resolvePrincipalId();
        $agentId = (int) $request->attributes->get('agentId', 0);
        $memoryId = (string) $request->attributes->get('memoryId', '');

        $result = $this->memoryService->getAgentMemory($memoryId, $agentId, $principalId);

        if ($result === null) {
            return $this->notFound();
        }

        return new JsonResponse(['data' => $result]);
    }

    /**
     * PUT /api/v1/agents/{agentId}/memories/{memoryId}
     */
    public function update(Request $request): JsonResponse
    {
        $principalId = $this->resolvePrincipalId();
        $agentId = (int) $request->attributes->get('agentId', 0);
        $memoryId = (string) $request->attributes->get('memoryId', '');

        try {
            $body = $this->decodeJson($request);
        } catch (JsonException) {
            return $this->error('INVALID_JSON', self::INVALID_JSON_MESSAGE, Response::HTTP_BAD_REQUEST);
        }

        try {
            $result = $this->memoryService->updateAgentMemory($memoryId, $agentId, $principalId, $body);
        } catch (RuntimeException $e) {
            return $this->error('VALIDATION_ERROR', $e->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if ($result === null) {
            return $this->notFound();
        }

        return new JsonResponse(['data' => $result]);
    }

    /**
     * POST /api/v1/agents/{agentId}/memories/{memoryId}/replace
     */
    public function replace(Request $request): JsonResponse
    {
        $principalId = $this->resolvePrincipalId();
        $agentId = (int) $request->attributes->get('agentId', 0);
        $memoryId = (string) $request->attributes->get('memoryId', '');

        try {
            $body = $this->decodeJson($request);
        } catch (JsonException) {
            return $this->error('INVALID_JSON', self::INVALID_JSON_MESSAGE, Response::HTTP_BAD_REQUEST);
        }

        $name = trim((string) ($body['name'] ?? ''));
        $type = (string) ($body['type'] ?? '');
        $typeValid = in_array($type, \Spora\Plugins\Memories\Services\MemoryService::DOCUMENT_TYPES, true);
        $find = (string) ($body['find'] ?? '');

        if ($name === '' || $type === '' || $find === '') {
            return $this->error(
                'VALIDATION_ERROR',
                'name, type, and find are required.',
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }
        if (!$typeValid) {
            return $this->error(
                \Spora\Plugins\Memories\Services\MemoryService::TYPE_NOT_ALLOWED_CODE,
                sprintf("type '%s' is not one of: %s", $type, implode(', ', \Spora\Plugins\Memories\Services\MemoryService::DOCUMENT_TYPES)),
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        try {
            $result = $this->memoryService->replaceAgentMemory($memoryId, $agentId, $principalId, $body);
        } catch (RuntimeException $e) {
            return $this->error(
                \Spora\Plugins\Memories\Services\MemoryService::REPLACE_NOT_UNIQUE_CODE,
                $e->getMessage(),
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        if ($result === null) {
            return $this->error(
                \Spora\Plugins\Memories\Services\MemoryService::REPLACE_NOT_FOUND_CODE,
                'Memory not found for replace.',
                Response::HTTP_NOT_FOUND,
            );
        }

        return new JsonResponse(['data' => $result]);
    }

    /**
     * DELETE /api/v1/agents/{agentId}/memories/{memoryId}
     */
    public function destroy(Request $request): JsonResponse
    {
        $principalId = $this->resolvePrincipalId();
        $agentId = (int) $request->attributes->get('agentId', 0);
        $memoryId = (string) $request->attributes->get('memoryId', '');

        $deleted = $this->memoryService->deleteAgentMemory($memoryId, $agentId, $principalId);

        if (! $deleted) {
            return $this->notFound();
        }

        return new JsonResponse(['data' => ['deleted' => true]]);
    }

    /**
     * PATCH /api/v1/agents/{agentId}/memories/reorder
     */
    public function reorder(Request $request): JsonResponse
    {
        $principalId = $this->resolvePrincipalId();
        $agentId = (int) $request->attributes->get('agentId', 0);

        try {
            $body = $this->decodeJson($request);
        } catch (JsonException) {
            return $this->error('INVALID_JSON', self::INVALID_JSON_MESSAGE, Response::HTTP_BAD_REQUEST);
        }

        $order = $body['order'] ?? [];
        if (! is_array($order)) {
            return $this->error('VALIDATION_ERROR', 'order must be an array of memory IDs.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->reorderMemoriesOrNotFound($agentId, $principalId, array_values($order));
    }

    private function reorderMemoriesOrNotFound(int $agentId, int $principalId, array $order): JsonResponse
    {
        try {
            $this->memoryService->reorderAgentMemories($agentId, $principalId, $order);
        } catch (RuntimeException) {
            return $this->notFound();
        }

        return new JsonResponse(['data' => ['success' => true]]);
    }

    private function resolvePrincipalId(): int
    {
        if ($this->resolvedPrincipalId !== null) {
            return $this->resolvedPrincipalId;
        }
        $userId = $this->authService->currentUserId();
        if ($userId === null) {
            throw new RuntimeException('Authenticated user required');
        }
        try {
            $this->resolvedPrincipalId = (int) $this->principals->ensureUserPrincipal($userId)->id;
        } catch (PrincipalMaterialisationException $e) {
            throw new RuntimeException($e->getMessage(), 0, $e);
        }

        return $this->resolvedPrincipalId;
    }

    private function decodeJson(Request $request): array
    {
        $content = $request->getContent();
        if ($content === '') {
            return [];
        }

        return json_decode($content, true, 512, JSON_THROW_ON_ERROR);
    }

    private function error(string $code, string $message, int $status): JsonResponse
    {
        return new JsonResponse(['error' => ['code' => $code, 'message' => $message]], $status);
    }

    private function notFound(): JsonResponse
    {
        return new JsonResponse(
            ['error' => ['code' => 'NOT_FOUND', 'message' => 'Memory not found.']],
            Response::HTTP_NOT_FOUND,
        );
    }
}
