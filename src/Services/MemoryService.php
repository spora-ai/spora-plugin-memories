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
 * Service for memory management.
 * All DB access for Memory domain goes through this service.
 *
 * `AgentNotFoundException` lives in `Spora\Services\Exceptions\` (a core class)
 * because Agent is a core model — moving the exception to the plugin namespace
 * would force callers outside the plugin to import two namespaces for the same
 * concept. The exception stays in core; this service throws it when the agent
 * for the requested memory is missing.
 */
final class MemoryService implements MemoryServiceInterface
{
    private const DATETIME_FORMAT = 'Y-m-d H:i:s';

    /**
     * Wraps each named string field in Utf8Sanitizer::scrubString. Non-string
     * and absent fields pass through untouched. Used by the create / update
     * paths so every byte that lands in `memories.summary` and
     * `memories.content` is valid UTF-8.
     *
     * @param array<string, mixed> $data
     * @param string ...$fields field names to scrub when present
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


    public function listGlobalMemories(int $userId): array
    {
        $memories = Memory::global()
            ->where('user_id', $userId)
            ->orderBy('order')
            ->orderBy('name')
            ->get()
            ->map(fn(Memory $m) => $this->resource($m));

        return $memories->all();
    }

    public function listAgentMemories(int $agentId, int $userId): ?array
    {
        $agent = $this->findAgent($agentId, $userId);
        if ($agent === null) {
            return null;
        }

        $memories = Memory::forAgent($agentId)
            ->orderBy('order')
            ->orderBy('name')
            ->get()
            ->map(fn(Memory $m) => $this->resource($m));

        return $memories->all();
    }

    public function getGlobalMemory(int $memoryId, int $userId): ?array
    {
        $memory = Memory::find($memoryId);
        if ($memory === null) {
            return null;
        }

        if ($memory->agent_id !== null || $memory->user_id !== $userId) {
            return null;
        }

        return ['memory' => $this->resource($memory)];
    }

    public function getAgentMemory(int $memoryId, int $agentId, int $userId): ?array
    {
        $agent = $this->findAgent($agentId, $userId);
        if ($agent === null) {
            return null;
        }

        $memory = Memory::where('id', $memoryId)->where('agent_id', $agentId)->first();
        if ($memory === null) {
            return null;
        }

        return ['memory' => $this->resource($memory)];
    }

    public function createGlobalMemory(int $userId, array $data): array
    {
        $this->validate($data, isCreation: true);

        $payload = self::scrubStringFields($data, 'summary', 'content');
        $id = Capsule::table('memories')->insertGetId([
            'user_id'    => $userId,
            'agent_id'   => null,
            'name'       => $data['name'],
            'summary'    => isset($payload['summary']) ? trim((string) $payload['summary']) : null,
            'content'    => isset($payload['content']) ? trim((string) $payload['content']) : null,
            'order'      => $this->getNextOrder(null, $userId),
            'created_at' => date(self::DATETIME_FORMAT),
            'updated_at' => date(self::DATETIME_FORMAT),
        ]);

        $memory = Memory::findOrFail($id);

        return ['memory' => $this->resource($memory)];
    }

    public function createAgentMemory(int $agentId, int $userId, array $data): array
    {
        $agent = $this->findAgent($agentId, $userId);
        if ($agent === null) {
            throw new AgentNotFoundException('Agent not found');
        }

        $this->validate($data, isCreation: true);

        $payload = self::scrubStringFields($data, 'summary', 'content');
        $id = Capsule::table('memories')->insertGetId([
            'user_id'    => $userId,
            'agent_id'   => $agentId,
            'name'       => $data['name'],
            'summary'    => isset($payload['summary']) ? trim((string) $payload['summary']) : null,
            'content'    => isset($payload['content']) ? trim((string) $payload['content']) : null,
            'order'      => $this->getNextOrder($agentId, $userId),
            'created_at' => date(self::DATETIME_FORMAT),
            'updated_at' => date(self::DATETIME_FORMAT),
        ]);

        $memory = Memory::findOrFail($id);

        return ['memory' => $this->resource($memory)];
    }

    public function updateGlobalMemory(int $memoryId, int $userId, array $data): ?array
    {
        $memory = Memory::find($memoryId);
        if ($memory === null) {
            return null;
        }

        if ($memory->agent_id !== null || $memory->user_id !== $userId) {
            return null;
        }

        $this->validate($data, isCreation: false);

        $allowed = ['name', 'summary', 'content', 'order'];
        $updateData = array_intersect_key($data, array_flip($allowed));

        if ($updateData !== []) {
            if (isset($updateData['order'])) {
                $updateData['order'] = (int) $updateData['order'];
            }
            $updateData = self::scrubStringFields($updateData, 'summary', 'content');
            Capsule::table('memories')
                ->where('id', $memoryId)
                ->update(array_merge($updateData, ['updated_at' => date(self::DATETIME_FORMAT)]));
            $memory->refresh();
        }

        return ['memory' => $this->resource($memory)];
    }

    public function updateAgentMemory(int $memoryId, int $agentId, int $userId, array $data): ?array
    {
        $agent = $this->findAgent($agentId, $userId);
        if ($agent === null) {
            return null;
        }

        $memory = Memory::where('id', $memoryId)->where('agent_id', $agentId)->first();
        if ($memory === null) {
            return null;
        }

        $this->validate($data, isCreation: false);

        $allowed = ['name', 'summary', 'content', 'order'];
        $updateData = array_intersect_key($data, array_flip($allowed));

        if ($updateData !== []) {
            if (isset($updateData['order'])) {
                $updateData['order'] = (int) $updateData['order'];
            }
            $updateData = self::scrubStringFields($updateData, 'summary', 'content');
            Capsule::table('memories')
                ->where('id', $memoryId)
                ->update(array_merge($updateData, ['updated_at' => date(self::DATETIME_FORMAT)]));
            $memory->refresh();
        }

        return ['memory' => $this->resource($memory)];
    }

    public function deleteGlobalMemory(int $memoryId, int $userId): bool
    {
        $memory = Memory::find($memoryId);
        if ($memory === null) {
            return false;
        }

        if ($memory->agent_id !== null || $memory->user_id !== $userId) {
            return false;
        }

        Capsule::table('memories')->where('id', $memoryId)->delete();

        return true;
    }

    public function deleteAgentMemory(int $memoryId, int $agentId, int $userId): bool
    {
        $agent = $this->findAgent($agentId, $userId);
        if ($agent === null) {
            return false;
        }

        $memory = Memory::where('id', $memoryId)->where('agent_id', $agentId)->first();
        if ($memory === null) {
            return false;
        }

        Capsule::table('memories')->where('id', $memoryId)->delete();

        return true;
    }

    private function findAgent(int $id, int $userId): ?Agent
    {
        // Resolve via the user-principal — spora-core migration 0067 dropped
        // agents.user_id and made agents.principal_id the ownership column.
        // SQLite silently treats an unknown double-quoted identifier as a
        // string literal (so the previous `where('user_id', $userId)` was a
        // no-op that returned null for every row), which manifested as a
        // blanket AgentNotFoundException in tests.
        $principalId = Capsule::table('principals')
            ->where('type', 'user')
            ->where('user_id', $userId)
            ->value('id');
        if ($principalId === null) {
            return null;
        }
        return Agent::where('id', $id)->where('principal_id', $principalId)->first();
    }

    private function getNextOrder(?int $agentId, int $userId): int
    {
        $query = Memory::where('user_id', $userId);
        if ($agentId !== null) {
            $query->where('agent_id', $agentId);
        } else {
            $query->whereNull('agent_id');
        }
        $max = $query->max('order');

        return $max !== null ? ((int) $max) + 1 : 1;
    }

    public function reorderGlobalMemories(int $userId, array $orderedIds): void
    {
        foreach ($orderedIds as $index => $memoryId) {
            Capsule::table('memories')
                ->where('id', $memoryId)
                ->where('user_id', $userId)
                ->whereNull('agent_id')
                ->update(['order' => $index + 1, 'updated_at' => date(self::DATETIME_FORMAT)]);
        }
    }

    public function reorderAgentMemories(int $agentId, int $userId, array $orderedIds): void
    {
        $agent = $this->findAgent($agentId, $userId);
        if ($agent === null) {
            throw new AgentNotFoundException('Agent not found');
        }

        // Process only IDs that actually belong to this agent, preserving input order
        $order = 1;
        foreach ($orderedIds as $memoryId) {
            $updated = Capsule::table('memories')
                ->where('id', $memoryId)
                ->where('agent_id', $agentId)
                ->update(['order' => $order, 'updated_at' => date(self::DATETIME_FORMAT)]);
            if ($updated > 0) {
                $order++;
            }
        }
    }

    private function validate(array $data, bool $isCreation): void
    {
        if ($isCreation) {
            $name = trim((string) ($data['name'] ?? ''));
            if ($name === '') {
                throw new MemoryValidationException('name is required');
            }
            return;
        }
        if (array_key_exists('name', $data) && trim((string) $data['name']) === '') {
            throw new MemoryValidationException('name cannot be empty');
        }
    }

    private function resource(Memory $memory): array
    {
        return [
            'id'         => (int) $memory->id,
            'user_id'    => $memory->user_id !== null ? (int) $memory->user_id : null,
            'agent_id'   => $memory->agent_id !== null ? (int) $memory->agent_id : null,
            'name'       => $memory->name,
            'summary'    => $memory->summary,
            'content'    => $memory->content,
            'order'      => (int) $memory->order,
            'created_at' => $memory->created_at->toIso8601String(),
            'updated_at' => $memory->updated_at->toIso8601String(),
        ];
    }
}
