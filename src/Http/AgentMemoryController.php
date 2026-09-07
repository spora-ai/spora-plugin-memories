<?php

declare(strict_types=1);

namespace Spora\Plugins\Memories\Http;

use Spora\Services\Exceptions\AgentNotFoundException;
use Spora\Services\Exceptions\PrincipalNotAccessibleException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Throwable;

/**
 * Handles agent-scoped memory CRUD, reordering, and surgical substring
 * replacement. Agent memories are keyed by `agent_id` and travel with the
 * agent across principal transfers — the principal id from the controller
 * is used only as an ownership-gate to make sure the caller can see the
 * agent before any service call lands. The service layer routes that
 * principal id through {@see \Spora\Services\PrincipalResolver::ownerUserId()}
 * and {@see \Spora\Services\PrincipalResolver::isVisibleTo()} so
 * group-owned agents reach every group member, not just the caller's
 * personal principal.
 *
 * The principal itself comes from `?principal_id=` via
 * {@see AbstractMemoryController::requestPrincipalId()} — same convention
 * as the global controller, so the frontend's PrincipalChipRow choice
 * applies uniformly to both `/memories` and `/agents/{id}/memories`.
 */
final class AgentMemoryController extends AbstractMemoryController
{
    /**
     * GET /api/v1/agents/{agentId}/memories
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $userId = $this->requestUserId($request);
            $this->requestPrincipalId($request);
            $agentId = (int) $request->attributes->get('agentId', 0);
            $type = $request->query->get('type');

            $memories = $this->memoryQuery->listAgentMemories($agentId, $userId, is_string($type) && $type !== '' ? $type : null);
        } catch (PrincipalNotAccessibleException $e) {
            return $this->forbidden($e->getMessage());
        }

        return $memories === null
            ? $this->notFound()
            : new JsonResponse(['data' => ['memories' => $memories]]);
    }

    /**
     * POST /api/v1/agents/{agentId}/memories
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $userId = $this->requestUserId($request);
            $principalId = $this->requestPrincipalId($request);
            $agentId = (int) $request->attributes->get('agentId', 0);

            $body = $this->decodeRequestBody($request);
            if ($body instanceof JsonResponse) {
                return $body;
            }

            $validationError = $this->validateCreateInput($body);
            return $validationError ?? $this->runCreate(fn() => $this->memoryCommand->createAgentMemory($agentId, $userId, $principalId, $body));
        } catch (PrincipalNotAccessibleException $e) {
            return $this->forbidden($e->getMessage());
        }
    }

    /**
     * GET /api/v1/agents/{agentId}/memories/{memoryId}
     */
    public function show(Request $request): JsonResponse
    {
        try {
            $userId = $this->requestUserId($request);
            $agentId = (int) $request->attributes->get('agentId', 0);
            $memoryId = (string) $request->attributes->get('memoryId', '');

            $result = $this->memoryQuery->getAgentMemory($memoryId, $agentId, $userId);
        } catch (PrincipalNotAccessibleException $e) {
            return $this->forbidden($e->getMessage());
        }

        return $result === null ? $this->notFound() : new JsonResponse(['data' => $result]);
    }

    /**
     * PUT /api/v1/agents/{agentId}/memories/{memoryId}
     */
    public function update(Request $request): JsonResponse
    {
        try {
            $userId = $this->requestUserId($request);
            $principalId = $this->requestPrincipalId($request);
            $agentId = (int) $request->attributes->get('agentId', 0);
            $memoryId = (string) $request->attributes->get('memoryId', '');

            $body = $this->decodeRequestBody($request);
            if ($body instanceof JsonResponse) {
                return $body;
            }

            return $this->runUpdate(fn() => $this->memoryCommand->updateAgentMemory($memoryId, $agentId, $userId, $principalId, $body));
        } catch (PrincipalNotAccessibleException $e) {
            return $this->forbidden($e->getMessage());
        }
    }

    /**
     * POST /api/v1/agents/{agentId}/memories/{memoryId}/replace
     */
    public function replace(Request $request): JsonResponse
    {
        try {
            $userId = $this->requestUserId($request);
            $principalId = $this->requestPrincipalId($request);
            $agentId = (int) $request->attributes->get('agentId', 0);
            $memoryId = (string) $request->attributes->get('memoryId', '');

            $body = $this->decodeRequestBody($request);
            if ($body instanceof JsonResponse) {
                return $body;
            }

            $validationError = $this->validateReplaceInput($body);
            return $validationError ?? $this->runReplace(fn() => $this->memoryCommand->replaceAgentMemory($memoryId, $agentId, $userId, $principalId, $body));
        } catch (PrincipalNotAccessibleException $e) {
            return $this->forbidden($e->getMessage());
        }
    }

    /**
     * DELETE /api/v1/agents/{agentId}/memories/{memoryId}
     */
    public function destroy(Request $request): JsonResponse
    {
        try {
            $userId = $this->requestUserId($request);
            $principalId = $this->requestPrincipalId($request);
            $agentId = (int) $request->attributes->get('agentId', 0);
            $memoryId = (string) $request->attributes->get('memoryId', '');

            $deleted = $this->memoryCommand->deleteAgentMemory($memoryId, $agentId, $userId, $principalId);
        } catch (PrincipalNotAccessibleException $e) {
            return $this->forbidden($e->getMessage());
        } catch (AgentNotFoundException) {
            return $this->notFound();
        }

        return $deleted ? new JsonResponse(['data' => ['deleted' => true]]) : $this->notFound();
    }

    /**
     * PATCH /api/v1/agents/{agentId}/memories/reorder
     */
    public function reorder(Request $request): JsonResponse
    {
        try {
            $userId = $this->requestUserId($request);
            $principalId = $this->requestPrincipalId($request);
            $agentId = (int) $request->attributes->get('agentId', 0);

            $body = $this->decodeRequestBody($request);
            if ($body instanceof JsonResponse) {
                return $body;
            }

            return $this->runReorder(
                $body['order'] ?? [],
                fn(array $order) => $this->memoryCommand->reorderAgentMemories($agentId, $userId, $principalId, $order),
            );
        } catch (PrincipalNotAccessibleException $e) {
            return $this->forbidden($e->getMessage());
        }
    }

    private function runReplace(callable $operation): JsonResponse
    {
        try {
            return $this->replaceResponse($operation());
        } catch (Throwable $e) {
            return $this->translateReplaceFailure($e);
        }
    }
}
