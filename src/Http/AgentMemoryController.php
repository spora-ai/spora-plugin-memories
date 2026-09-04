<?php

declare(strict_types=1);

namespace Spora\Plugins\Memories\Http;

use Spora\Plugins\Memories\Services\Exceptions\MemoryValidationException;
use Spora\Services\Exceptions\AgentNotFoundException;
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
final class AgentMemoryController extends AbstractMemoryController
{
    /**
     * GET /api/v1/agents/{agentId}/memories
     */
    public function index(Request $request): JsonResponse
    {
        $principalId = $this->requestPrincipalId();
        $agentId = (int) $request->attributes->get('agentId', 0);
        $type = $request->query->get('type');

        $memories = $this->memoryQuery->listAgentMemories($agentId, $principalId, is_string($type) && $type !== '' ? $type : null);

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
        $principalId = $this->requestPrincipalId();
        $agentId = (int) $request->attributes->get('agentId', 0);

        $body = $this->decodeRequestBody($request);
        if ($body instanceof JsonResponse) {
            return $body;
        }

        $validationError = $this->validateCreateInput($body);
        if ($validationError !== null) {
            return $validationError;
        }

        return $this->createMemoryOrError($agentId, $principalId, $body);
    }

    /**
     * GET /api/v1/agents/{agentId}/memories/{memoryId}
     */
    public function show(Request $request): JsonResponse
    {
        $principalId = $this->requestPrincipalId();
        $agentId = (int) $request->attributes->get('agentId', 0);
        $memoryId = (string) $request->attributes->get('memoryId', '');

        $result = $this->memoryQuery->getAgentMemory($memoryId, $agentId, $principalId);

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
        $principalId = $this->requestPrincipalId();
        $agentId = (int) $request->attributes->get('agentId', 0);
        $memoryId = (string) $request->attributes->get('memoryId', '');

        $body = $this->decodeRequestBody($request);
        if ($body instanceof JsonResponse) {
            return $body;
        }

        return $this->updateMemoryOrError($memoryId, $agentId, $principalId, $body);
    }

    /**
     * POST /api/v1/agents/{agentId}/memories/{memoryId}/replace
     */
    public function replace(Request $request): JsonResponse
    {
        $principalId = $this->requestPrincipalId();
        $agentId = (int) $request->attributes->get('agentId', 0);
        $memoryId = (string) $request->attributes->get('memoryId', '');

        $body = $this->decodeRequestBody($request);
        if ($body instanceof JsonResponse) {
            return $body;
        }

        $validationError = $this->validateReplaceInput($body);
        if ($validationError !== null) {
            return $validationError;
        }

        return $this->replaceMemoryOrError($memoryId, $agentId, $principalId, $body);
    }

    /**
     * DELETE /api/v1/agents/{agentId}/memories/{memoryId}
     */
    public function destroy(Request $request): JsonResponse
    {
        $principalId = $this->requestPrincipalId();
        $agentId = (int) $request->attributes->get('agentId', 0);
        $memoryId = (string) $request->attributes->get('memoryId', '');

        $deleted = $this->memoryCommand->deleteAgentMemory($memoryId, $agentId, $principalId);

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
        $principalId = $this->requestPrincipalId();
        $agentId = (int) $request->attributes->get('agentId', 0);

        $body = $this->decodeRequestBody($request);
        if ($body instanceof JsonResponse) {
            return $body;
        }

        $order = $body['order'] ?? [];
        if (! is_array($order)) {
            return $this->error('VALIDATION_ERROR', 'order must be an array of memory IDs.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->reorderMemoriesOrNotFound($agentId, $principalId, array_values($order));
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
    private function createMemoryOrError(int $agentId, int $principalId, array $body): JsonResponse
    {
        try {
            $result = $this->memoryCommand->createAgentMemory($agentId, $principalId, $body);
            return new JsonResponse(['data' => $result], Response::HTTP_CREATED);
        } catch (AgentNotFoundException) {
            return $this->notFound();
        } catch (MemoryValidationException $e) {
            return $this->error('VALIDATION_ERROR', $e->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
        }
    }

    /**
     * @param array<string, mixed> $body
     */
    private function updateMemoryOrError(string $memoryId, int $agentId, int $principalId, array $body): JsonResponse
    {
        try {
            $result = $this->memoryCommand->updateAgentMemory($memoryId, $agentId, $principalId, $body);
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
    private function replaceMemoryOrError(string $memoryId, int $agentId, int $principalId, array $body): JsonResponse
    {
        try {
            return $this->replaceResponse(
                $this->memoryCommand->replaceAgentMemory($memoryId, $agentId, $principalId, $body),
            );
        } catch (MemoryValidationException | AgentNotFoundException $e) {
            return $this->replaceResponse(null, $e);
        }
    }

    /**
     * @param list<string> $order
     */
    private function reorderMemoriesOrNotFound(int $agentId, int $principalId, array $order): JsonResponse
    {
        try {
            $this->memoryCommand->reorderAgentMemories($agentId, $principalId, $order);
        } catch (AgentNotFoundException) {
            return $this->notFound();
        }

        return new JsonResponse(['data' => ['success' => true]]);
    }
}
