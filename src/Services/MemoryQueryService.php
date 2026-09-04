<?php

declare(strict_types=1);

namespace Spora\Plugins\Memories\Services;

use Spora\Models\Agent;
use Spora\Plugins\Memories\Models\Memory;
use Spora\Plugins\Memories\Services\Exceptions\MemoryValidationException;
use Spora\Services\Exceptions\AgentNotFoundException;

/**
 * Read-side service for the memories domain — list + get operations
 * over both principal-scoped (global) and agent-scoped memories.
 *
 * Lives next to {@see MemoryCommandService} after the v2 split so each
 * side stays under Sonar's per-class method-count ceiling.
 *
 * `AgentNotFoundException` lives in `Spora\Services\Exceptions\` (a core
 * class) because Agent is a core model — moving the exception to the
 * plugin namespace would force callers outside the plugin to import two
 * namespaces for the same concept. The exception stays in core; this
 * service throws it when the agent for the requested memory is missing.
 */
final class MemoryQueryService implements MemoryQueryInterface
{
    public function listGlobalMemories(int $principalId, ?string $type = null): array
    {
        $query = Memory::forPrincipal($principalId)->orderBy('order')->orderBy('name');
        if ($type !== null) {
            $this->validateType($type);
            $query->ofType($type);
        }

        return $query->get()
            ->map(fn(Memory $m) => self::resource($m))
            ->all();
    }

    public function listAgentMemories(int $agentId, int $principalId, ?string $type = null): ?array
    {
        if ($this->findAgent($agentId, $principalId) === null) {
            return null;
        }

        $query = Memory::forAgent($agentId)->orderBy('order')->orderBy('name');
        if ($type !== null) {
            $this->validateType($type);
            $query->ofType($type);
        }

        return $query->get()
            ->map(fn(Memory $m) => self::resource($m))
            ->all();
    }

    public function getGlobalMemory(string $memoryId, int $principalId): ?array
    {
        $memory = Memory::where('id', $memoryId)->where('principal_id', $principalId)->where('scope', 'global')->first();
        if ($memory === null) {
            return null;
        }

        return ['memory' => self::resource($memory)];
    }

    public function getAgentMemory(string $memoryId, int $agentId, int $principalId): ?array
    {
        if ($this->findAgent($agentId, $principalId) === null) {
            return null;
        }

        $memory = Memory::where('id', $memoryId)->where('agent_id', $agentId)->where('scope', 'agent')->first();
        if ($memory === null) {
            return null;
        }

        return ['memory' => self::resource($memory)];
    }

    /**
     * Validate a document type — exposed here because the list/get paths
     * need it to reject unknown `?type=` query values before hitting the
     * Eloquent layer. Shared with {@see MemoryCommandService} which
     * also calls into {@see validateType()} for the same enum.
     */
    public function validateType(string $type): void
    {
        if (!in_array($type, MemoryTypes::DOCUMENT_TYPES, true)) {
            throw new MemoryValidationException(
                sprintf(
                    "type '%s' is not one of: %s",
                    $type,
                    implode(', ', MemoryTypes::DOCUMENT_TYPES),
                ),
            );
        }
    }

    private function findAgent(int $id, int $principalId): ?Agent
    {
        return Agent::where('id', $id)->where('principal_id', $principalId)->first();
    }

    /**
     * @return array<string, mixed>
     */
    private static function resource(Memory $memory): array
    {
        return [
            'id'           => (string) $memory->id,
            'principal_id' => $memory->principal_id !== null ? (int) $memory->principal_id : null,
            'agent_id'     => $memory->agent_id !== null ? (int) $memory->agent_id : null,
            'scope'        => (string) $memory->scope,
            'type'         => (string) $memory->type,
            'name'         => $memory->name,
            'summary'      => $memory->summary,
            'content'      => $memory->content,
            'order'        => (int) $memory->order,
            'created_at'   => $memory->created_at->toIso8601String(),
            'updated_at'   => $memory->updated_at->toIso8601String(),
        ];
    }
}
