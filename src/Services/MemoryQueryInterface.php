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
     * @param string|null $type Optional document-type filter.
     * @return list<array>|null Null when the agent does not exist or is not visible to the principal.
     */
    public function listAgentMemories(int $agentId, int $principalId, ?string $type = null): ?array;

    /**
     * @return array|null
     */
    public function getGlobalMemory(string $memoryId, int $principalId): ?array;

    /**
     * @return array|null
     */
    public function getAgentMemory(string $memoryId, int $agentId, int $principalId): ?array;
}
