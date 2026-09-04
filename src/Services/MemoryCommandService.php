<?php

declare(strict_types=1);

namespace Spora\Plugins\Memories\Services;

use Illuminate\Database\Capsule\Manager as Capsule;
use Spora\Models\Agent;
use Spora\Plugins\Memories\Models\Memory;
use Spora\Plugins\Memories\Services\Exceptions\MemoryValidationException;
use Spora\Services\Exceptions\AgentNotFoundException;
use Spora\Services\Text\Utf8Sanitizer;

/**
 * Write-side service for the memories domain — create, update, replace,
 * delete, and reorder operations over both principal-scoped (global)
 * and agent-scoped memories.
 *
 * Lives next to {@see MemoryQueryService} after the v2 split so each
 * side stays under Sonar's per-class method-count ceiling.
 *
 * `AgentNotFoundException` lives in `Spora\Services\Exceptions\` (a core
 * class) because Agent is a core model — moving the exception to the
 * plugin namespace would force callers outside the plugin to import two
 * namespaces for the same concept. The exception stays in core; this
 * service throws it when the agent for the requested memory is missing.
 */
final class MemoryCommandService implements MemoryCommandInterface
{
    private const DATETIME_FORMAT = 'Y-m-d H:i:s';

    public function createGlobalMemory(int $principalId, array $data): array
    {
        $this->validate($data, isCreation: true);

        $memory = $this->newMemory('global', null, $principalId, $data);
        $this->insertWithTimestamps($memory, date(self::DATETIME_FORMAT));

        return ['memory' => MemoryResource::toArray($memory)];
    }

    public function createAgentMemory(int $agentId, int $principalId, array $data): array
    {
        if ($this->findAgent($agentId, $principalId) === null) {
            throw new AgentNotFoundException('Agent not found');
        }

        $this->validate($data, isCreation: true);

        $memory = $this->newMemory('agent', $agentId, $principalId, $data);
        $this->insertWithTimestamps($memory, date(self::DATETIME_FORMAT));

        return ['memory' => MemoryResource::toArray($memory)];
    }

    /**
     * Materialise a fresh Memory row with the standard field set; the
     * createGlobalMemory / createAgentMemory callers differ only in
     * scope ('global' vs 'agent') and the corresponding owner id.
     *
     * @param array<string, mixed> $data
     */
    private function newMemory(string $scope, ?int $agentId, int $principalId, array $data): Memory
    {
        $payload = self::scrubStringFields($data, 'summary', 'content');
        $memory = new Memory();
        $memory->id = $memory->newUniqueId();
        if ($scope === 'global') {
            $memory->principal_id = $principalId;
        } else {
            $memory->agent_id = $agentId;
        }
        $memory->scope = $scope;
        $memory->type = $data['type'];
        $memory->name = $data['name'];
        $memory->summary = isset($payload['summary']) ? trim((string) $payload['summary']) : null;
        $memory->content = isset($payload['content']) ? trim((string) $payload['content']) : null;
        $memory->order = $this->getNextOrder($agentId, $principalId);

        return $memory;
    }

    public function updateGlobalMemory(string $memoryId, int $principalId, array $data): ?array
    {
        $memory = Memory::where('id', $memoryId)->where('principal_id', $principalId)->where('scope', 'global')->first();
        if ($memory === null) {
            return null;
        }

        $this->applyUpdate($memory, $data);

        return ['memory' => MemoryResource::toArray($memory)];
    }

    public function updateAgentMemory(string $memoryId, int $agentId, int $principalId, array $data): ?array
    {
        if ($this->findAgent($agentId, $principalId) === null) {
            return null;
        }

        $memory = Memory::where('id', $memoryId)->where('agent_id', $agentId)->where('scope', 'agent')->first();
        if ($memory === null) {
            return null;
        }

        $this->applyUpdate($memory, $data);

        return ['memory' => MemoryResource::toArray($memory)];
    }

    /**
     * @param array<string, mixed> $data
     */
    private function applyUpdate(Memory $memory, array $data): void
    {
        $this->validate($data, isCreation: false);

        $allowed = ['name', 'summary', 'content', 'order', 'type'];
        $updateData = array_intersect_key($data, array_flip($allowed));
        if (isset($updateData['type'])) {
            $this->validateType($updateData['type']);
        }

        if ($updateData !== []) {
            if (isset($updateData['order'])) {
                $updateData['order'] = (int) $updateData['order'];
            }
            $updateData = self::scrubStringFields($updateData, 'summary', 'content');
            Capsule::table('memories')
                ->where('id', $memory->id)
                ->update(array_merge($updateData, ['updated_at' => date(self::DATETIME_FORMAT)]));
            $memory->refresh();
        }
    }

    public function replaceGlobalMemory(string $memoryId, int $principalId, array $data): ?array
    {
        $memory = Memory::where('id', $memoryId)->where('principal_id', $principalId)->where('scope', 'global')->first();
        if ($memory === null) {
            return null;
        }

        return $this->applyReplace($memory, $data);
    }

    public function replaceAgentMemory(string $memoryId, int $agentId, int $principalId, array $data): ?array
    {
        if ($this->findAgent($agentId, $principalId) === null) {
            return null;
        }

        $memory = Memory::where('id', $memoryId)->where('agent_id', $agentId)->where('scope', 'agent')->first();
        if ($memory === null) {
            return null;
        }

        return $this->applyReplace($memory, $data);
    }

    /**
     * @param array<string, mixed> $data
     * @return array{memory: array<string, mixed>}
     */
    private function applyReplace(Memory $memory, array $data): array
    {
        $find = (string) ($data['find'] ?? '');
        $newText = (string) ($data['new_text'] ?? '');
        $memory->content = $this->replaceInMemoryContent((string) ($memory->content ?? ''), $find, $newText);
        $memory->save();
        $this->touchUpdatedAt((string) $memory->id);

        return ['memory' => MemoryResource::toArray($memory->refresh())];
    }

    public function deleteGlobalMemory(string $memoryId, int $principalId): bool
    {
        $deleted = Capsule::table('memories')
            ->where('id', $memoryId)
            ->where('principal_id', $principalId)
            ->where('scope', 'global')
            ->delete();

        return $deleted > 0;
    }

    public function deleteAgentMemory(string $memoryId, int $agentId, int $principalId): bool
    {
        if ($this->findAgent($agentId, $principalId) === null) {
            return false;
        }

        $deleted = Capsule::table('memories')
            ->where('id', $memoryId)
            ->where('agent_id', $agentId)
            ->where('scope', 'agent')
            ->delete();

        return $deleted > 0;
    }

    public function reorderGlobalMemories(int $principalId, array $orderedIds): void
    {
        foreach ($orderedIds as $index => $memoryId) {
            Capsule::table('memories')
                ->where('id', $memoryId)
                ->where('principal_id', $principalId)
                ->where('scope', 'global')
                ->update(['order' => $index + 1, 'updated_at' => date(self::DATETIME_FORMAT)]);
        }
    }

    public function reorderAgentMemories(int $agentId, int $principalId, array $orderedIds): void
    {
        if ($this->findAgent($agentId, $principalId) === null) {
            throw new AgentNotFoundException('Agent not found');
        }

        // Process only IDs that actually belong to this agent, preserving input order
        $order = 1;
        foreach ($orderedIds as $memoryId) {
            $updated = Capsule::table('memories')
                ->where('id', $memoryId)
                ->where('agent_id', $agentId)
                ->where('scope', 'agent')
                ->update(['order' => $order, 'updated_at' => date(self::DATETIME_FORMAT)]);
            if ($updated > 0) {
                $order++;
            }
        }
    }

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

    public function replaceInMemoryContent(string $current, string $find, string $newText): string
    {
        $count = mb_substr_count($current, $find);
        if ($count === 0) {
            throw new MemoryValidationException("find matches 0 occurrences.");
        }
        if ($count > 1) {
            throw new MemoryValidationException(
                "find matches {$count} > 1 occurrences; provide a unique substring.",
            );
        }

        return Utf8Sanitizer::scrubString(str_replace($find, $newText, $current));
    }

    /**
     * Insert a row with explicit timestamp strings. Eloquent's `$casts`
     * declares `created_at`/`updated_at` as Carbon, which prevents PHPStan
     * from accepting a raw `Y-m-d H:i:s` string. Building the insert via
     * the query builder keeps PHPStan happy while letting the model's
     * HasUuids trait own the id.
     */
    private function insertWithTimestamps(Memory $memory, string $now): void
    {
        Capsule::table('memories')->insert([
            'id'           => $memory->id,
            'principal_id' => $memory->principal_id,
            'agent_id'     => $memory->agent_id,
            'scope'        => $memory->scope,
            'type'         => $memory->type,
            'name'         => $memory->name,
            'summary'      => $memory->summary,
            'content'      => $memory->content,
            'order'        => $memory->order,
            'created_at'   => $now,
            'updated_at'   => $now,
        ]);
        // Repopulate the Carbon accessors so the resource() round-trip yields
        // a populated created_at/updated_at without a second SELECT.
        $memory->setRawAttributes([
            'id'           => $memory->id,
            'principal_id' => $memory->principal_id,
            'agent_id'     => $memory->agent_id,
            'scope'        => $memory->scope,
            'type'         => $memory->type,
            'name'         => $memory->name,
            'summary'      => $memory->summary,
            'content'      => $memory->content,
            'order'        => $memory->order,
            'created_at'   => $now,
            'updated_at'   => $now,
        ], true);
    }

    /**
     * Bump updated_at via raw SQL — sidesteps PHPStan's strict typing on
     * `$memory->updated_at` (cast to `Carbon\Carbon`) while keeping Eloquent's
     * mutator pipeline for everything else.
     */
    private function touchUpdatedAt(string $memoryId): void
    {
        Capsule::table('memories')
            ->where('id', $memoryId)
            ->update(['updated_at' => date(self::DATETIME_FORMAT)]);
    }

    private function findAgent(int $id, int $principalId): ?Agent
    {
        return Agent::where('id', $id)->where('principal_id', $principalId)->first();
    }

    private function getNextOrder(?int $agentId, int $principalId): int
    {
        $query = Memory::where('scope', $agentId === null ? 'global' : 'agent');
        if ($agentId === null) {
            $query->where('principal_id', $principalId);
        } else {
            $query->where('agent_id', $agentId);
        }
        $max = $query->max('order');

        return $max !== null ? ((int) $max) + 1 : 1;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function validate(array $data, bool $isCreation): void
    {
        if ($isCreation) {
            $name = trim((string) ($data['name'] ?? ''));
            if ($name === '') {
                throw new MemoryValidationException('name is required');
            }
            $type = $data['type'] ?? null;
            if (!is_string($type) || $type === '') {
                throw new MemoryValidationException('type is required');
            }
            $this->validateType($type);
            return;
        }
        if (array_key_exists('name', $data) && trim((string) $data['name']) === '') {
            throw new MemoryValidationException('name cannot be empty');
        }
        if (array_key_exists('type', $data)) {
            if (!is_string($data['type']) || $data['type'] === '') {
                throw new MemoryValidationException('type cannot be empty');
            }
            $this->validateType($data['type']);
        }
    }

    /**
     * @param array<string, mixed> $data
     * @param string ...$fields
     * @return array<string, mixed>
     */
    private static function scrubStringFields(array $data, string ...$fields): array
    {
        foreach ($fields as $field) {
            if (isset($data[$field]) && is_string($data[$field])) {
                $data[$field] = Utf8Sanitizer::scrubString($data[$field]);
            }
        }
        return $data;
    }

}
