<?php

declare(strict_types=1);

namespace Spora\Plugins\Memories\Services;

/**
 * Write-side contract for the memories domain (split from
 * {@see MemoryServiceInterface} to keep each side under Sonar's
 * method-count ceiling).
 */
interface MemoryCommandInterface
{
    /**
     * @param array<string, mixed> $data
     * @return array
     */
    public function createGlobalMemory(int $principalId, array $data): array;

    /**
     * The agent must be reachable from the principal that owns `$principalId`.
     * See {@see MemoryQueryInterface::listAgentMemories()} for the rationale.
     *
     * @param array<string, mixed> $data
     * @return array
     */
    public function createAgentMemory(int $agentId, int $principalId, array $data): array;

    /**
     * @param array<string, mixed> $data
     * @return array|null
     */
    public function updateGlobalMemory(string $memoryId, int $principalId, array $data): ?array;

    /**
     * @param array<string, mixed> $data
     * @return array|null
     */
    public function updateAgentMemory(string $memoryId, int $agentId, int $principalId, array $data): ?array;

    /**
     * @param array<string, mixed> $data Must contain `find` and `new_text`.
     * @return array|null
     */
    public function replaceGlobalMemory(string $memoryId, int $principalId, array $data): ?array;

    /**
     * @param array<string, mixed> $data Must contain `find` and `new_text`.
     * @return array|null
     */
    public function replaceAgentMemory(string $memoryId, int $agentId, int $principalId, array $data): ?array;

    public function deleteGlobalMemory(string $memoryId, int $principalId): bool;

    public function deleteAgentMemory(string $memoryId, int $agentId, int $principalId): bool;

    /**
     * @param list<string> $orderedIds Memory UUIDs in desired display order
     */
    public function reorderGlobalMemories(int $principalId, array $orderedIds): void;

    /**
     * @param list<string> $orderedIds Memory UUIDs in desired display order
     */
    public function reorderAgentMemories(int $agentId, int $principalId, array $orderedIds): void;

    /**
     * Throws {@see Exceptions\MemoryValidationException}
     * when the type is not in the document-type enum.
     */
    public function validateType(string $type): void;

    /**
     * Single-occurrence substring replacement on memory content.
     *
     * Throws {@see Exceptions\MemoryValidationException}
     * with a message naming the actual occurrence count when `find` matches
     * 0 or >1 substrings (operators must supply a unique anchor). On a
     * single match the result is run through `Utf8Sanitizer::scrubString()`
     * before being returned so the persisted bytes stay valid UTF-8.
     */
    public function replaceInMemoryContent(string $current, string $find, string $newText): string;
}
