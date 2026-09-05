<?php

declare(strict_types=1);

namespace Spora\Plugins\Memories\Services;

/**
 * Read-side contract for the memories domain (split from
 * {@see MemoryServiceInterface} to keep each side under Sonar's
 * method-count ceiling while preserving the combined surface).
 */
interface MemoryQueryInterface
{
    /**
     * @param string|null $type Optional document-type filter (plan | documentation | examples | context).
     * @return list<array>
     */
    public function listGlobalMemories(int $principalId, ?string $type = null): array;

    /**
     * The agent must be reachable from the principal that owns `$principalId`
     * — agents are not directly addressable by principal id, so the service
     * resolves the principal back to its {@see \Spora\Models\User} and
     * runs {@see \Spora\Services\PrincipalResolver::isVisibleTo()} against
     * the user's full visible-principal set. The pre-v2.1 implementation
     * did a strict `principal_id = $principalId` against the caller's
     * personal principal, which silently 404'd every agent owned by a
     * group the user happens to belong to.
     *
     * @param string|null $type Optional document-type filter.
     * @return list<array>|null Null when the agent does not exist or is not visible to the principal.
     */
    public function listAgentMemories(int $agentId, int $principalId, ?string $type = null): ?array;

    /**
     * @return array|null
     */
    public function getGlobalMemory(string $memoryId, int $principalId): ?array;

    /**
     * @return array|null Null when the agent is not visible to the principal or the memory does not exist on this agent.
     */
    public function getAgentMemory(string $memoryId, int $agentId, int $principalId): ?array;
}
