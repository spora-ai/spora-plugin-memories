<?php

declare(strict_types=1);

namespace Spora\Plugins\Memories\Services;

use Illuminate\Database\Capsule\Manager as Capsule;
use Spora\Models\Agent;
use Spora\Plugins\Memories\Models\Memory;
use Spora\Services\Exceptions\AgentNotFoundException;
use Spora\Services\PrincipalResolver;

/**
 * Write-side service for the memories domain — create, update, replace,
 * delete, and reorder operations over both principal-scoped (global)
 * and agent-scoped memories.
 *
 * Lives next to {@see MemoryQueryService} after the v2 split so each
 * side stays under Sonar's per-class method-count ceiling. Validation
 * lives in {@see MemoryValidator} and content-string edits in
 * {@see MemoryContentEditor}; this class only orchestrates them.
 *
 * Agent-scoped methods now resolve `$principalId` back to a user id
 * through {@see PrincipalResolver::ownerUserId()} so the visibility
 * gate at {@see PrincipalResolver::isVisibleTo()} expands to the
 * user's full principal set — see the matching rationale on
 * {@see MemoryQueryService}.
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

    private readonly MemoryValidator $validator;
    private readonly MemoryContentEditor $contentEditor;

    public function __construct(
        private readonly PrincipalResolver $principals = new PrincipalResolver(),
    ) {
        $this->validator      = new MemoryValidator();
        $this->contentEditor  = new MemoryContentEditor();
    }

    public function createGlobalMemory(int $principalId, array $data): array
    {
        $this->validator->validate($data, isCreation: true);

        $memory = $this->newMemory('global', null, $principalId, $data);
        $memory->order = $this->getNextOrderForGlobal($principalId);
        $this->insertWithTimestamps($memory, date(self::DATETIME_FORMAT));

        return ['memory' => MemoryResource::toArray($memory)];
    }

    public function createAgentMemory(int $agentId, int $principalId, array $data): array
    {
        if ($this->findAgent($agentId, $principalId) === null) {
            throw new AgentNotFoundException('Agent not found');
        }

        $this->validator->validate($data, isCreation: true);

        $memory = $this->newMemory('agent', $agentId, $principalId, $data);
        $memory->order = $this->getNextOrderForAgent($agentId);
        $this->insertWithTimestamps($memory, date(self::DATETIME_FORMAT));

        return ['memory' => MemoryResource::toArray($memory)];
    }

    /**
     * Materialise a fresh Memory row with the standard field set; the
     * createGlobalMemory / createAgentMemory callers differ only in
     * scope ('global' vs 'agent') and the corresponding owner id. The
     * `principalId` argument is unused in agent scope (`agent_id` keys
     * the row instead); it is recorded on the row as `principal_id` for
     * global memories only.
     *
     * @param array<string, mixed> $data
     */
    private function newMemory(string $scope, ?int $agentId, int $principalId, array $data): Memory
    {
        $payload = $this->contentEditor->scrubStringFields($data, 'summary', 'content');
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
        $this->validator->validate($data, isCreation: false);

        $allowed = ['name', 'summary', 'content', 'order', 'type'];
        $updateData = array_intersect_key($data, array_flip($allowed));
        if (isset($updateData['type'])) {
            $this->validator->validateType($updateData['type']);
        }

        if ($updateData !== []) {
            if (isset($updateData['order'])) {
                $updateData['order'] = (int) $updateData['order'];
            }
            $updateData = $this->contentEditor->scrubStringFields($updateData, 'summary', 'content');
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
        $memory->content = $this->contentEditor->replaceInMemoryContent((string) ($memory->content ?? ''), $find, $newText);
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

    /**
     * Interface passthrough — delegates to {@see MemoryValidator} so the
     * enum check lives in one place. Kept on the public surface because
     * callers (and tests) reach for `$service->validateType(...)`.
     *
     * @throws Exceptions\MemoryValidationException
     */
    public function validateType(string $type): void
    {
        $this->validator->validateType($type);
    }

    /**
     * Interface passthrough — delegates to {@see MemoryContentEditor}.
     *
     * @throws Exceptions\MemoryValidationException
     */
    public function replaceInMemoryContent(string $current, string $find, string $newText): string
    {
        return $this->contentEditor->replaceInMemoryContent($current, $find, $newText);
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

    /**
     * Visibility-gated agent lookup. The pre-v2.1 implementation matched
     * `principal_id = $principalId` against the caller's personal
     * principal id, which silently 404'd every agent owned by a group
     * the user belongs to. We now route through {@see PrincipalResolver::ownerUserId()}
     * to recover the calling user id from the acting principal, then
     * hand off to {@see PrincipalResolver::isVisibleTo()} which expands
     * to the user's full principal set.
     */
    private function findAgent(int $id, int $principalId): ?Agent
    {
        $userId = $this->principals->ownerUserId($principalId);
        if ($userId === null) {
            return null;
        }

        return $this->principals->isVisibleTo($id, $userId) ? Agent::find($id) : null;
    }

    private function getNextOrderForGlobal(int $principalId): int
    {
        $max = Capsule::table('memories')
            ->where('scope', 'global')
            ->where('principal_id', $principalId)
            ->max('order');

        return $max !== null ? ((int) $max) + 1 : 1;
    }

    private function getNextOrderForAgent(int $agentId): int
    {
        $max = Capsule::table('memories')
            ->where('scope', 'agent')
            ->where('agent_id', $agentId)
            ->max('order');

        return $max !== null ? ((int) $max) + 1 : 1;
    }
}
