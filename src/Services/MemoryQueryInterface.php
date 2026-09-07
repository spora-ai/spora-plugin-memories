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
     * The agent must be reachable from the **calling user** (`$userId`,
     * supplied by the controller's auth layer). {@see \Spora\Services\PrincipalResolver::isVisibleTo()}
     * expands to the user's full visible-principal set, so a member of
     * a group sees every group-owned agent while an outsider is rejected.
     * The pre-fix-release-readiness implementation routed through
     * {@see \Spora\Services\PrincipalResolver::ownerUserId()} against
     * `$principalId`, which returned the **principal's owner** rather
     * than the caller — a cross-principal IDOR that authorised every
     * non-owner group member on every agent visible to the owner.
     *
     * @param string|null $type Optional document-type filter.
     * @return list<array>|null Null when the agent does not exist or is not visible to the caller.
     */
    public function listAgentMemories(int $agentId, int $userId, ?string $type = null): ?array;

    /**
     * @return array|null
     */
    public function getGlobalMemory(string $memoryId, int $principalId): ?array;

    /**
     * @return array|null Null when the agent is not visible to the caller or the memory does not exist on this agent.
     */
    public function getAgentMemory(string $memoryId, int $agentId, int $userId): ?array;
}
