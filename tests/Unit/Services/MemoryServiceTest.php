<?php

declare(strict_types=1);

use Spora\Plugins\Memories\Models\Memory;
use Spora\Plugins\Memories\Services\Exceptions\MemoryValidationException;
use Spora\Plugins\Memories\Services\MemoryCommandService;
use Spora\Plugins\Memories\Services\MemoryQueryService;

const AGENT1_EMAIL = 'agent1@test.com';
const AGENT2_NAME = 'Agent 2';
const OWNER_EMAIL = 'owner@test.com';
defined('OTHER_EMAIL') || define('OTHER_EMAIL', 'other@test.com');
const IT_AGENT_NOT_FOUND = 'returns null when agent does not exist';
const IT_MEMORY_NOT_FOUND = 'returns null when memory does not exist';

const MEM_DEFAULT_TYPE = 'context';


/**
 * @return MemoryCommandService
 */
function makeMemoryService(): MemoryCommandService
{
    return new MemoryCommandService();
}

/**
 * @return MemoryQueryService
 */
function makeMemoryQueryService(): MemoryQueryService
{
    return new MemoryQueryService();
}

/**
 * Create a user, surface their user-principal id, and an agent. Returns
 * `[userId, agentId, principalId]`.
 */
function createUserWithAgent(string $email = 'service@example.com'): array
{
    static $seq = 0;
    $seq++;
    $authService = bootAuthLayer();
    $userId = bootAuth($authService, "{$seq}{$email}", 'Password1!');

    $agentId = createAgentWithPrincipal($userId, 'Test Agent', ['max_steps' => 10]);
    $principalId = createUserPrincipal($userId);

    return [$userId, $agentId, $principalId];
}


describe('listGlobalMemories', function (): void {

    it('returns empty array when no global memories exist', function (): void {
        [, , $principalId] = createUserWithAgent();
        $service = makeMemoryQueryService();

        $result = $service->listGlobalMemories($principalId);

        expect($result)->toBeArray()
            ->and($result)->toBeEmpty();
    });

    it('returns only global memories for this principal', function (): void {
        [, $agentId, $principalId] = createUserWithAgent();
        $service = makeMemoryQueryService();

        Memory::create([
            'principal_id' => $principalId,
            'scope'        => 'global',
            'type'         => 'context',
            'name'         => 'global_1',
            'summary'      => 'Global summary 1',
            'order'        => 1,
        ]);
        Memory::create([
            'principal_id' => $principalId,
            'scope'        => 'global',
            'type'         => 'context',
            'name'         => 'global_2',
            'summary'      => 'Global summary 2',
            'order'        => 2,
        ]);
        Memory::create([
            'principal_id' => $principalId,
            'agent_id'     => $agentId,
            'scope'        => 'agent',
            'type'         => 'context',
            'name'         => 'agent_memory',
            'content'      => 'Should not appear',
        ]);

        $result = $service->listGlobalMemories($principalId);

        expect($result)->toHaveCount(2)
            ->and(array_column($result, 'name'))->toContain('global_1', 'global_2')
            ->and(array_column($result, 'name'))->not->toContain('agent_memory');
    });

    it('does not return another principals global memories', function (): void {
        [, , $principalId1] = createUserWithAgent('user1@test.com');
        [, , $principalId2] = createUserWithAgent('user2@test.com');
        $service = makeMemoryQueryService();

        Memory::create([
            'principal_id' => $principalId1,
            'scope'        => 'global',
            'type'         => 'context',
            'name'         => 'user1_global',
        ]);
        Memory::create([
            'principal_id' => $principalId2,
            'scope'        => 'global',
            'type'         => 'context',
            'name'         => 'user2_global',
        ]);

        $result = $service->listGlobalMemories($principalId1);

        expect($result)->toHaveCount(1)
            ->and($result[0]['name'])->toBe('user1_global');
    });

    it('orders by order field then by name', function (): void {
        [, , $principalId] = createUserWithAgent();
        $service = makeMemoryQueryService();

        Memory::create(['principal_id' => $principalId, 'scope' => 'global', 'type' => 'context', 'name' => 'zebra', 'order' => 1]);
        Memory::create(['principal_id' => $principalId, 'scope' => 'global', 'type' => 'context', 'name' => 'alpha', 'order' => 1]);
        Memory::create(['principal_id' => $principalId, 'scope' => 'global', 'type' => 'context', 'name' => 'beta', 'order' => 0]);

        $result = $service->listGlobalMemories($principalId);

        expect(array_column($result, 'name'))->toBe(['beta', 'alpha', 'zebra']);
    });

    it('filters by type when supplied', function (): void {
        [, , $principalId] = createUserWithAgent();
        $service = makeMemoryQueryService();

        Memory::create(['principal_id' => $principalId, 'scope' => 'global', 'type' => 'plan', 'name' => 'p']);
        Memory::create(['principal_id' => $principalId, 'scope' => 'global', 'type' => 'context', 'name' => 'c']);

        $plans = $service->listGlobalMemories($principalId, 'plan');

        expect($plans)->toHaveCount(1)
            ->and($plans[0]['name'])->toBe('p');
    });

    it('throws on unknown type filter', function (): void {
        [, , $principalId] = createUserWithAgent();
        $service = makeMemoryQueryService();

        expect(fn() => $service->listGlobalMemories($principalId, 'unknown'))
            ->toThrow(MemoryValidationException::class);
    });
});

describe('listAgentMemories', function (): void {

    it(IT_AGENT_NOT_FOUND, function (): void {
        [, , $principalId] = createUserWithAgent();
        $service = makeMemoryQueryService();

        $result = $service->listAgentMemories(9999, $principalId);

        expect($result)->toBeNull();
    });

    it('returns empty array when no agent memories exist', function (): void {
        [, $agentId, $principalId] = createUserWithAgent();
        $service = makeMemoryQueryService();

        $result = $service->listAgentMemories($agentId, $principalId);

        expect($result)->toBeArray()
            ->and($result)->toBeEmpty();
    });

    it('returns only memories for the specified agent', function (): void {
        [$userId, $agentId1, $principalId] = createUserWithAgent(AGENT1_EMAIL);
        $agentId2 = createAgentWithPrincipal($userId, AGENT2_NAME, ['max_steps' => 10]);
        $service = makeMemoryQueryService();

        Memory::create(['principal_id' => $principalId, 'agent_id' => $agentId1, 'scope' => 'agent', 'type' => 'context', 'name' => 'memory_for_agent1']);
        Memory::create(['principal_id' => $principalId, 'agent_id' => $agentId2, 'scope' => 'agent', 'type' => 'context', 'name' => 'memory_for_agent2']);

        $result = $service->listAgentMemories($agentId1, $principalId);

        expect($result)->toHaveCount(1)
            ->and($result[0]['name'])->toBe('memory_for_agent1');
    });

    it('filters by type when supplied', function (): void {
        [$userId, $agentId, $principalId] = createUserWithAgent();
        $service = makeMemoryQueryService();

        Memory::create(['principal_id' => $principalId, 'agent_id' => $agentId, 'scope' => 'agent', 'type' => 'plan', 'name' => 'plan_one']);
        Memory::create(['principal_id' => $principalId, 'agent_id' => $agentId, 'scope' => 'agent', 'type' => 'context', 'name' => 'ctx_one']);

        $plans = $service->listAgentMemories($agentId, $principalId, 'plan');

        expect($plans)->toHaveCount(1)
            ->and($plans[0]['name'])->toBe('plan_one');
    });
});

describe('createGlobalMemory', function (): void {

    it('creates a global memory with minimal data and auto-assigns order', function (): void {
        [, , $principalId] = createUserWithAgent();
        $service = makeMemoryService();

        $result = $service->createGlobalMemory($principalId, ['name' => 'minimal', 'type' => 'context']);

        expect($result['memory']['name'])->toBe('minimal')
            ->and($result['memory']['principal_id'])->toBe($principalId)
            ->and($result['memory']['agent_id'])->toBeNull()
            ->and($result['memory']['type'])->toBe('context')
            ->and($result['memory']['summary'])->toBeNull()
            ->and($result['memory']['content'])->toBeNull()
            ->and($result['memory']['order'])->toBe(1);
    });

    it('creates a global memory with full data and ignores explicit order (auto-assigns)', function (): void {
        [, , $principalId] = createUserWithAgent();
        $service = makeMemoryService();

        $result = $service->createGlobalMemory($principalId, [
            'name'    => 'full_memory',
            'type'    => 'documentation',
            'summary' => 'A summary',
            'content' => 'The content',
            'order'   => 42,
        ]);

        expect($result['memory']['name'])->toBe('full_memory')
            ->and($result['memory']['summary'])->toBe('A summary')
            ->and($result['memory']['content'])->toBe('The content')
            ->and($result['memory']['order'])->toBe(1);
    });

    it('trims whitespace from summary and content', function (): void {
        [, , $principalId] = createUserWithAgent();
        $service = makeMemoryService();

        $result = $service->createGlobalMemory($principalId, [
            'name'    => 'trim_test',
            'type'    => 'context',
            'summary' => '  trimmed summary  ',
            'content' => "  trimmed content\n",
        ]);

        expect($result['memory']['summary'])->toBe('trimmed summary')
            ->and($result['memory']['content'])->toBe('trimmed content');
    });

    it('throws when name is empty', function (): void {
        [, , $principalId] = createUserWithAgent();
        $service = makeMemoryService();

        expect(fn() => $service->createGlobalMemory($principalId, ['name' => '', 'type' => 'context']))
            ->toThrow(MemoryValidationException::class, 'name is required');
    });

    it('throws when name is missing', function (): void {
        [, , $principalId] = createUserWithAgent();
        $service = makeMemoryService();

        expect(fn() => $service->createGlobalMemory($principalId, []))
            ->toThrow(MemoryValidationException::class, 'name is required');
    });

    it('throws when type is missing', function (): void {
        [, , $principalId] = createUserWithAgent();
        $service = makeMemoryService();

        expect(fn() => $service->createGlobalMemory($principalId, ['name' => 'x']))
            ->toThrow(MemoryValidationException::class, 'type is required');
    });

    it('throws when type is unknown', function (): void {
        [, , $principalId] = createUserWithAgent();
        $service = makeMemoryService();

        expect(fn() => $service->createGlobalMemory($principalId, ['name' => 'x', 'type' => 'mystery']))
            ->toThrow(MemoryValidationException::class);
    });
});

describe('createAgentMemory', function (): void {

    it('throws when agent does not exist', function (): void {
        [, , $principalId] = createUserWithAgent();
        $service = makeMemoryService();

        expect(fn() => $service->createAgentMemory(9999, $principalId, ['name' => 'test', 'type' => 'context']))
            ->toThrow(RuntimeException::class, 'Agent not found');
    });

    it('creates an agent-scoped memory and auto-assigns order', function (): void {
        [, $agentId, $principalId] = createUserWithAgent();
        $service = makeMemoryService();

        $result = $service->createAgentMemory($agentId, $principalId, [
            'name'    => 'agent_memory',
            'type'    => 'context',
            'content' => 'Agent-specific content',
        ]);

        expect($result['memory']['name'])->toBe('agent_memory')
            ->and($result['memory']['agent_id'])->toBe($agentId)
            ->and($result['memory']['scope'])->toBe('agent')
            ->and($result['memory']['principal_id'])->toBeNull()
            ->and($result['memory']['order'])->toBe(1);
    });
});

//
// getGlobalMemory
//

describe('getGlobalMemory', function (): void {

    it(IT_MEMORY_NOT_FOUND, function (): void {
        [, , $principalId] = createUserWithAgent();
        $service = makeMemoryQueryService();

        $result = $service->getGlobalMemory('00000000-0000-4000-8000-000000000000', $principalId);

        expect($result)->toBeNull();
    });

    it('returns null when memory belongs to another principal', function (): void {
        [, , $principalId1] = createUserWithAgent(OWNER_EMAIL);
        [, , $principalId2] = createUserWithAgent(OTHER_EMAIL);
        $service = makeMemoryQueryService();

        $memory = Memory::create([
            'principal_id' => $principalId1,
            'scope'        => 'global',
            'type'         => 'context',
            'name'         => 'private',
        ]);

        $result = $service->getGlobalMemory((string) $memory->id, $principalId2);

        expect($result)->toBeNull();
    });

    it('returns null when memory is agent-scoped (called with global endpoint)', function (): void {
        [, $agentId, $principalId] = createUserWithAgent();
        $service = makeMemoryQueryService();

        $memory = Memory::create([
            'principal_id' => $principalId,
            'agent_id'     => $agentId,
            'scope'        => 'agent',
            'type'         => 'context',
            'name'         => 'agent_only',
        ]);

        $result = $service->getGlobalMemory((string) $memory->id, $principalId);

        expect($result)->toBeNull();
    });

    it('returns the memory when found', function (): void {
        [, , $principalId] = createUserWithAgent();
        $service = makeMemoryQueryService();

        $memory = Memory::create([
            'principal_id' => $principalId,
            'scope'        => 'global',
            'type'         => 'context',
            'name'         => 'findable',
            'summary'      => 'Found it',
        ]);

        $result = $service->getGlobalMemory((string) $memory->id, $principalId);

        expect($result)->not->toBeNull()
            ->and($result['memory']['name'])->toBe('findable')
            ->and($result['memory']['summary'])->toBe('Found it');
    });
});

//
// getAgentMemory
//

describe('getAgentMemory', function (): void {

    it(IT_AGENT_NOT_FOUND, function (): void {
        [, , $principalId] = createUserWithAgent();
        $service = makeMemoryQueryService();

        $result = $service->getAgentMemory('00000000-0000-4000-8000-000000000000', 9999, $principalId);

        expect($result)->toBeNull();
    });

    it(IT_MEMORY_NOT_FOUND, function (): void {
        [, $agentId, $principalId] = createUserWithAgent();
        $service = makeMemoryQueryService();

        $result = $service->getAgentMemory('00000000-0000-4000-8000-000000000000', $agentId, $principalId);

        expect($result)->toBeNull();
    });

    it('returns null when memory belongs to different agent', function (): void {
        [$userId, $agentId1, $principalId] = createUserWithAgent(OWNER_EMAIL);
        $agentId2 = createAgentWithPrincipal($userId, AGENT2_NAME, ['max_steps' => 10]);
        $service = makeMemoryQueryService();

        $memory = Memory::create([
            'principal_id' => $principalId,
            'agent_id'     => $agentId1,
            'scope'        => 'agent',
            'type'         => 'context',
            'name'         => 'agent1_only',
        ]);

        $result = $service->getAgentMemory((string) $memory->id, $agentId2, $principalId);

        expect($result)->toBeNull();
    });

    it('returns the memory when found', function (): void {
        [, $agentId, $principalId] = createUserWithAgent();
        $service = makeMemoryQueryService();

        $memory = Memory::create([
            'principal_id' => $principalId,
            'agent_id'     => $agentId,
            'scope'        => 'agent',
            'type'         => 'context',
            'name'         => 'agent_findable',
        ]);

        $result = $service->getAgentMemory((string) $memory->id, $agentId, $principalId);

        expect($result)->not->toBeNull()
            ->and($result['memory']['name'])->toBe('agent_findable');
    });
});

//
// updateGlobalMemory
//

describe('updateGlobalMemory', function (): void {

    it(IT_MEMORY_NOT_FOUND, function (): void {
        [, , $principalId] = createUserWithAgent();
        $service = makeMemoryService();

        $result = $service->updateGlobalMemory('00000000-0000-4000-8000-000000000000', $principalId, ['name' => 'new']);

        expect($result)->toBeNull();
    });

    it('returns null when memory belongs to another principal', function (): void {
        [, , $principalId1] = createUserWithAgent(OWNER_EMAIL);
        [, , $principalId2] = createUserWithAgent(OTHER_EMAIL);
        $service = makeMemoryService();

        $memory = Memory::create([
            'principal_id' => $principalId1,
            'scope'        => 'global',
            'type'         => 'context',
            'name'         => 'private',
        ]);

        $result = $service->updateGlobalMemory((string) $memory->id, $principalId2, ['name' => 'hacked']);

        expect($result)->toBeNull();
    });

    it('updates only allowed fields', function (): void {
        [, , $principalId] = createUserWithAgent();
        $service = makeMemoryService();

        $memory = Memory::create([
            'principal_id' => $principalId,
            'scope'        => 'global',
            'type'         => 'context',
            'name'         => 'updatable',
            'summary'      => 'Original summary',
            'content'      => 'Original content',
            'order'        => 0,
        ]);

        $result = $service->updateGlobalMemory((string) $memory->id, $principalId, [
            'name'    => 'new_name',
            'content' => 'new content',
            'order'   => 5,
            'unknown' => 'should be ignored',
        ]);

        expect($result['memory']['name'])->toBe('new_name')
            ->and($result['memory']['content'])->toBe('new content')
            ->and($result['memory']['order'])->toBe(5)
            ->and($result['memory']['summary'])->toBe('Original summary');
    });

    it('rejects empty name on update', function (): void {
        [, , $principalId] = createUserWithAgent();
        $service = makeMemoryService();
        $memory = Memory::create([
            'principal_id' => $principalId,
            'scope'        => 'global',
            'type'         => 'context',
            'name'         => 'existing',
        ]);

        expect(fn() => $service->updateGlobalMemory((string) $memory->id, $principalId, ['name' => '   ']))
            ->toThrow(MemoryValidationException::class, 'name cannot be empty');
    });

    it('updates without changes', function (): void {
        [, , $principalId] = createUserWithAgent();
        $service = makeMemoryService();

        $memory = Memory::create([
            'principal_id' => $principalId,
            'scope'        => 'global',
            'type'         => 'context',
            'name'         => 'no_change',
        ]);

        $result = $service->updateGlobalMemory((string) $memory->id, $principalId, []);

        expect($result['memory']['name'])->toBe('no_change');
    });

    it('changes the type when a valid value is provided', function (): void {
        [, , $principalId] = createUserWithAgent();
        $service = makeMemoryService();

        $memory = Memory::create([
            'principal_id' => $principalId,
            'scope'        => 'global',
            'type'         => 'context',
            'name'         => 'switch_type',
        ]);

        $result = $service->updateGlobalMemory((string) $memory->id, $principalId, ['type' => 'plan']);

        expect($result['memory']['type'])->toBe('plan');
    });

    it('rejects an unknown type on update', function (): void {
        [, , $principalId] = createUserWithAgent();
        $service = makeMemoryService();

        $memory = Memory::create([
            'principal_id' => $principalId,
            'scope'        => 'global',
            'type'         => 'context',
            'name'         => 'bad_type',
        ]);

        expect(fn() => $service->updateGlobalMemory((string) $memory->id, $principalId, ['type' => 'mystery']))
            ->toThrow(MemoryValidationException::class);
    });
});

//
// updateAgentMemory
//

describe('updateAgentMemory', function (): void {

    it(IT_AGENT_NOT_FOUND, function (): void {
        [, , $principalId] = createUserWithAgent();
        $service = makeMemoryService();

        $result = $service->updateAgentMemory('00000000-0000-4000-8000-000000000000', 9999, $principalId, ['name' => 'new']);

        expect($result)->toBeNull();
    });

    it(IT_MEMORY_NOT_FOUND, function (): void {
        [, $agentId, $principalId] = createUserWithAgent();
        $service = makeMemoryService();

        $result = $service->updateAgentMemory('00000000-0000-4000-8000-000000000000', $agentId, $principalId, ['name' => 'new']);

        expect($result)->toBeNull();
    });

    it('updates agent memory', function (): void {
        [, $agentId, $principalId] = createUserWithAgent();
        $service = makeMemoryService();

        $memory = Memory::create([
            'principal_id' => $principalId,
            'agent_id'     => $agentId,
            'scope'        => 'agent',
            'type'         => 'context',
            'name'         => 'agent_updatable',
            'content'      => 'Original',
        ]);

        $result = $service->updateAgentMemory((string) $memory->id, $agentId, $principalId, [
            'content' => 'Updated',
            'order'   => 10,
        ]);

        expect($result['memory']['content'])->toBe('Updated')
            ->and($result['memory']['order'])->toBe(10);
    });
});

//
// replaceGlobalMemory
//

describe('replaceGlobalMemory', function (): void {

    it('returns null when memory does not exist', function (): void {
        [, , $principalId] = createUserWithAgent();
        $service = makeMemoryService();

        $result = $service->replaceGlobalMemory('00000000-0000-4000-8000-000000000000', $principalId, [
            'find' => 'a', 'new_text' => 'b',
        ]);

        expect($result)->toBeNull();
    });

    it('returns null when memory belongs to another principal', function (): void {
        [, , $principalId1] = createUserWithAgent(OWNER_EMAIL);
        [, , $principalId2] = createUserWithAgent(OTHER_EMAIL);
        $service = makeMemoryService();

        $memory = Memory::create([
            'principal_id' => $principalId1,
            'scope'        => 'global',
            'type'         => 'context',
            'name'         => 'private',
            'content'      => 'hello world',
        ]);

        $result = $service->replaceGlobalMemory((string) $memory->id, $principalId2, [
            'find' => 'hello', 'new_text' => 'goodbye',
        ]);

        expect($result)->toBeNull();
    });

    it('replaces a unique occurrence and persists the result', function (): void {
        [, , $principalId] = createUserWithAgent();
        $service = makeMemoryService();

        $memory = Memory::create([
            'principal_id' => $principalId,
            'scope'        => 'global',
            'type'         => 'documentation',
            'name'         => 'roadmap',
            'content'      => '# Plan\n\nphase 1: alpha\nphase 2: beta\n',
        ]);

        $result = $service->replaceGlobalMemory((string) $memory->id, $principalId, [
            'find' => 'phase 2: beta',
            'new_text' => 'phase 2: closed-beta',
        ]);

        expect($result['memory']['content'])->toContain('phase 2: closed-beta')
            ->and($result['memory']['content'])->not->toContain('phase 2: beta');

        $persisted = Memory::find($memory->id);
        expect($persisted->content)->toContain('phase 2: closed-beta');
    });
});

//
// replaceAgentMemory
//

describe('replaceAgentMemory', function (): void {

    it('returns null when agent does not exist', function (): void {
        [, , $principalId] = createUserWithAgent();
        $service = makeMemoryService();

        $result = $service->replaceAgentMemory('00000000-0000-4000-8000-000000000000', 9999, $principalId, [
            'find' => 'a', 'new_text' => 'b',
        ]);

        expect($result)->toBeNull();
    });

    it('returns null when memory does not exist', function (): void {
        [, $agentId, $principalId] = createUserWithAgent();
        $service = makeMemoryService();

        $result = $service->replaceAgentMemory('00000000-0000-4000-8000-000000000000', $agentId, $principalId, [
            'find' => 'a', 'new_text' => 'b',
        ]);

        expect($result)->toBeNull();
    });

    it('replaces a unique occurrence and persists the result', function (): void {
        [, $agentId, $principalId] = createUserWithAgent();
        $service = makeMemoryService();

        $memory = Memory::create([
            'principal_id' => $principalId,
            'agent_id'     => $agentId,
            'scope'        => 'agent',
            'type'         => 'plan',
            'name'         => 'sprint',
            'content'      => 'TODO:\n- ship auth\n- write tests\n',
        ]);

        $result = $service->replaceAgentMemory((string) $memory->id, $agentId, $principalId, [
            'find' => 'write tests',
            'new_text' => 'write tests (done)',
        ]);

        expect($result['memory']['content'])->toContain('write tests (done)')
            ->and($result['memory']['content'])->not->toContain("write tests\n");
    });
});

//
// validateType + replaceInMemoryContent
//

describe('validateType', function (): void {

    it('accepts each document type', function (): void {
        $service = makeMemoryService();

        foreach (['plan', 'documentation', 'examples', 'context'] as $type) {
            $service->validateType($type);
        }

        expect(true)->toBeTrue();
    });

    it('throws on unknown type', function (): void {
        $service = makeMemoryService();

        expect(fn() => $service->validateType('not_a_type'))
            ->toThrow(MemoryValidationException::class);
    });
});

describe('replaceInMemoryContent', function (): void {

    it('returns the unchanged content when find matches 0 occurrences', function (): void {
        $service = makeMemoryService();

        expect(fn() => $service->replaceInMemoryContent('abc def', 'zzz', 'q'))
            ->toThrow(MemoryValidationException::class, 'matches 0 occurrences');
    });

    it('returns the unchanged content when find matches > 1 occurrences', function (): void {
        $service = makeMemoryService();

        expect(fn() => $service->replaceInMemoryContent('abc abc abc', 'abc', 'q'))
            ->toThrow(MemoryValidationException::class, 'matches 3 > 1');
    });

    it('replaces exactly one occurrence', function (): void {
        $service = makeMemoryService();

        $result = $service->replaceInMemoryContent('alpha beta alpha', 'beta', 'BETA');

        expect($result)->toBe('alpha BETA alpha');
    });

    it('scrubs invalid UTF-8 bytes from the replacement', function (): void {
        $service = makeMemoryService();

        $result = $service->replaceInMemoryContent('hello world', 'world', 'wörld' . chr(0xE9));

        expect(mb_check_encoding($result, 'UTF-8'))->toBeTrue();
        expect(json_encode(['v' => $result]))->not->toBeFalse();
    });
});

//
// deleteGlobalMemory
//

describe('deleteGlobalMemory', function (): void {

    it('returns false when memory does not exist', function (): void {
        [, , $principalId] = createUserWithAgent();
        $service = makeMemoryService();

        $result = $service->deleteGlobalMemory('00000000-0000-4000-8000-000000000000', $principalId);

        expect($result)->toBeFalse();
    });

    it('returns false when memory belongs to another principal', function (): void {
        [, , $principalId1] = createUserWithAgent(OWNER_EMAIL);
        [, , $principalId2] = createUserWithAgent(OTHER_EMAIL);
        $service = makeMemoryService();

        $memory = Memory::create([
            'principal_id' => $principalId1,
            'scope'        => 'global',
            'type'         => 'context',
            'name'         => 'private',
        ]);

        $result = $service->deleteGlobalMemory((string) $memory->id, $principalId2);

        expect($result)->toBeFalse()
            ->and(Memory::find($memory->id))->not->toBeNull();
    });

    it('returns false when memory is agent-scoped', function (): void {
        [, $agentId, $principalId] = createUserWithAgent();
        $service = makeMemoryService();

        $memory = Memory::create([
            'principal_id' => $principalId,
            'agent_id'     => $agentId,
            'scope'        => 'agent',
            'type'         => 'context',
            'name'         => 'agent_only',
        ]);

        $result = $service->deleteGlobalMemory((string) $memory->id, $principalId);

        expect($result)->toBeFalse()
            ->and(Memory::find($memory->id))->not->toBeNull();
    });

    it('deletes the memory and returns true', function (): void {
        [, , $principalId] = createUserWithAgent();
        $service = makeMemoryService();

        $memory = Memory::create([
            'principal_id' => $principalId,
            'scope'        => 'global',
            'type'         => 'context',
            'name'         => 'to_delete',
        ]);

        $result = $service->deleteGlobalMemory((string) $memory->id, $principalId);

        expect($result)->toBeTrue()
            ->and(Memory::find($memory->id))->toBeNull();
    });
});

//
// deleteAgentMemory
//

describe('deleteAgentMemory', function (): void {

    it('returns false when agent does not exist', function (): void {
        [, , $principalId] = createUserWithAgent();
        $service = makeMemoryService();

        $result = $service->deleteAgentMemory('00000000-0000-4000-8000-000000000000', 9999, $principalId);

        expect($result)->toBeFalse();
    });

    it('returns false when memory does not exist', function (): void {
        [, $agentId, $principalId] = createUserWithAgent();
        $service = makeMemoryService();

        $result = $service->deleteAgentMemory('00000000-0000-4000-8000-000000000000', $agentId, $principalId);

        expect($result)->toBeFalse();
    });

    it('returns false when memory belongs to different agent', function (): void {
        [$userId, $agentId1, $principalId] = createUserWithAgent(OWNER_EMAIL);
        $agentId2 = createAgentWithPrincipal($userId, AGENT2_NAME, ['max_steps' => 10]);
        $service = makeMemoryService();

        $memory = Memory::create([
            'principal_id' => $principalId,
            'agent_id'     => $agentId1,
            'scope'        => 'agent',
            'type'         => 'context',
            'name'         => 'agent1_only',
        ]);

        $result = $service->deleteAgentMemory((string) $memory->id, $agentId2, $principalId);

        expect($result)->toBeFalse()
            ->and(Memory::find($memory->id))->not->toBeNull();
    });

    it('deletes the agent memory and returns true', function (): void {
        [, $agentId, $principalId] = createUserWithAgent();
        $service = makeMemoryService();

        $memory = Memory::create([
            'principal_id' => $principalId,
            'agent_id'     => $agentId,
            'scope'        => 'agent',
            'type'         => 'context',
            'name'         => 'agent_to_delete',
        ]);

        $result = $service->deleteAgentMemory((string) $memory->id, $agentId, $principalId);

        expect($result)->toBeTrue()
            ->and(Memory::find($memory->id))->toBeNull();
    });
});

//
// Auto-assigned order on creation
//

describe('createGlobalMemory auto-assigns order', function (): void {

    it('assigns sequential order values to global memories', function (): void {
        [, , $principalId] = createUserWithAgent();
        $service = makeMemoryService();

        $m1 = $service->createGlobalMemory($principalId, ['name' => 'first', 'type' => 'context']);
        $m2 = $service->createGlobalMemory($principalId, ['name' => 'second', 'type' => 'context']);
        $m3 = $service->createGlobalMemory($principalId, ['name' => 'third', 'type' => 'context']);

        expect($m1['memory']['order'])->toBe(1);
        expect($m2['memory']['order'])->toBe(2);
        expect($m3['memory']['order'])->toBe(3);
    });

    it('orders global memories independently from agent memories', function (): void {
        [, $agentId, $principalId] = createUserWithAgent();
        $service = makeMemoryService();

        $g1 = $service->createGlobalMemory($principalId, ['name' => 'global_first', 'type' => 'context']);
        $a1 = $service->createAgentMemory($agentId, $principalId, ['name' => 'agent_first', 'type' => 'context']);
        $g2 = $service->createGlobalMemory($principalId, ['name' => 'global_second', 'type' => 'context']);
        $a2 = $service->createAgentMemory($agentId, $principalId, ['name' => 'agent_second', 'type' => 'context']);

        expect($g1['memory']['order'])->toBe(1);
        expect($g2['memory']['order'])->toBe(2);
        expect($a1['memory']['order'])->toBe(1);
        expect($a2['memory']['order'])->toBe(2);
    });

    it('orders memories per-agent independently', function (): void {
        [$userId, $agentId1, $principalId] = createUserWithAgent(AGENT1_EMAIL);
        $agentId2 = createAgentWithPrincipal($userId, AGENT2_NAME, ['max_steps' => 10]);
        $service = makeMemoryService();

        $a1 = $service->createAgentMemory($agentId1, $principalId, ['name' => 'agent1_first', 'type' => 'context']);
        $a2 = $service->createAgentMemory($agentId2, $principalId, ['name' => 'agent2_first', 'type' => 'context']);
        $a1b = $service->createAgentMemory($agentId1, $principalId, ['name' => 'agent1_second', 'type' => 'context']);

        expect($a1['memory']['order'])->toBe(1);
        expect($a2['memory']['order'])->toBe(1);
        expect($a1b['memory']['order'])->toBe(2);
    });
});

//
// reorderGlobalMemories
//

describe('reorderGlobalMemories', function (): void {

    it('updates order values based on provided ID array', function (): void {
        [, , $principalId] = createUserWithAgent();
        $command = makeMemoryService();
        $query = makeMemoryQueryService();

        $m1 = $command->createGlobalMemory($principalId, ['name' => 'first', 'type' => 'context']);
        $m2 = $command->createGlobalMemory($principalId, ['name' => 'second', 'type' => 'context']);
        $m3 = $command->createGlobalMemory($principalId, ['name' => 'third', 'type' => 'context']);

        $command->reorderGlobalMemories($principalId, [$m3['memory']['id'], $m1['memory']['id'], $m2['memory']['id']]);

        $result = $query->listGlobalMemories($principalId);

        expect(array_column($result, 'order'))->toBe([1, 2, 3]);
        expect(array_column($result, 'id'))->toBe([$m3['memory']['id'], $m1['memory']['id'], $m2['memory']['id']]);
    });

    it('only updates memories belonging to the specified principal', function (): void {
        [, , $principalId1] = createUserWithAgent('user1@test.com');
        [, , $principalId2] = createUserWithAgent('user2@test.com');
        $service = makeMemoryService();

        $u1m = $service->createGlobalMemory($principalId1, ['name' => 'u1_memory', 'type' => 'context']);

        $service->reorderGlobalMemories($principalId2, [$u1m['memory']['id']]);

        $m = Memory::find($u1m['memory']['id']);
        expect($m->order)->toBe(1);
    });
});

//
// reorderAgentMemories
//

describe('reorderAgentMemories', function (): void {

    it('updates order values for the specified agent only', function (): void {
        [$userId, $agentId1, $principalId] = createUserWithAgent(AGENT1_EMAIL);
        $agentId2 = createAgentWithPrincipal($userId, AGENT2_NAME, ['max_steps' => 10]);
        $command = makeMemoryService();
        $query = makeMemoryQueryService();

        $a1 = $command->createAgentMemory($agentId1, $principalId, ['name' => 'a1_first', 'type' => 'context']);
        $a2 = $command->createAgentMemory($agentId2, $principalId, ['name' => 'a2_first', 'type' => 'context']);
        $a1b = $command->createAgentMemory($agentId1, $principalId, ['name' => 'a1_second', 'type' => 'context']);

        $command->reorderAgentMemories($agentId1, $principalId, [$a1b['memory']['id'], $a1['memory']['id']]);

        $result = $query->listAgentMemories($agentId1, $principalId);
        expect(array_column($result, 'id'))->toBe([$a1b['memory']['id'], $a1['memory']['id']]);

        $result2 = $query->listAgentMemories($agentId2, $principalId);
        expect($result2[0]['id'])->toBe($a2['memory']['id'])
            ->and($result2[0]['order'])->toBe(1);
    });

    it('throws when agent does not exist', function (): void {
        [, , $principalId] = createUserWithAgent();
        $service = makeMemoryService();

        expect(fn() => $service->reorderAgentMemories(9999, $principalId, []))
            ->toThrow(RuntimeException::class, 'Agent not found');
    });
});

//
// Resource transformation
//

describe('resource transformation', function (): void {

    it('includes all expected fields in resource output', function (): void {
        [, $agentId, $principalId] = createUserWithAgent();
        $service = makeMemoryService();

        $result = $service->createAgentMemory($agentId, $principalId, [
            'name'    => 'resource_test',
            'type'    => 'examples',
            'summary' => 'Test summary',
            'content' => 'Test content',
            'order'   => 7,
        ]);

        $memory = $result['memory'];
        expect($memory)->toHaveKeys([
            'id', 'principal_id', 'agent_id', 'scope', 'type',
            'name', 'summary', 'content', 'order', 'created_at', 'updated_at',
        ]);
        expect($memory['id'])->toBeString();
        expect($memory['principal_id'])->toBeNull();
        expect($memory['agent_id'])->toBe($agentId);
        expect($memory['type'])->toBe('examples');
        expect($memory['scope'])->toBe('agent');
        expect($memory['order'])->toBe(1);
        expect($memory['created_at'])->not->toBeEmpty();
        expect($memory['updated_at'])->not->toBeEmpty();
    });
});



describe('UTF-8 sanitization on write', function (): void {

    it('createAgentMemory persists a clean UTF-8 content with Latin-1 bytes scrubbed', function (): void {
        [, $agentId, $principalId] = createUserWithAgent();
        $service = makeMemoryService();

        // 0xE9 / 0xFC are valid Windows-1252; the sanitizer salvages them as UTF-8.
        $dirty = 'café' . chr(0xE9) . chr(0xFC) . '!';
        $result = $service->createAgentMemory($agentId, $principalId, [
            'name'    => 'utf8_test',
            'type'    => 'context',
            'content' => $dirty,
        ]);

        $persisted = Memory::findOrFail($result['memory']['id']);
        expect($persisted->content)->not->toBeNull();
        expect(mb_check_encoding($persisted->content, 'UTF-8'))->toBeTrue();
        // The scrubber should drop or salvage the bad bytes — the key
        // invariant is "the stored value round-trips through json_encode".
        expect(json_encode(['v' => $persisted->content]))->not->toBeFalse();
    });

    it('updateGlobalMemory scrubs Latin-1 summary and content before persistence', function (): void {
        [, , $principalId] = createUserWithAgent();
        $service = makeMemoryService();

        $memory = Memory::create([
            'principal_id' => $principalId,
            'scope'        => 'global',
            'type'         => 'context',
            'name'         => 'upd_test',
        ]);

        $result = $service->updateGlobalMemory((string) $memory->id, $principalId, [
            'summary' => 'naïve' . chr(0xE9),
            'content' => 'über' . chr(0xFC),
        ]);

        $persisted = Memory::findOrFail($memory->id);
        expect(mb_check_encoding($persisted->summary, 'UTF-8'))->toBeTrue();
        expect(mb_check_encoding($persisted->content, 'UTF-8'))->toBeTrue();
        expect(json_encode($result['memory']))->not->toBeFalse();
    });

    it('createGlobalMemory scrubs a Latin-1 summary', function (): void {
        [, , $principalId] = createUserWithAgent();
        $service = makeMemoryService();

        $result = $service->createGlobalMemory($principalId, [
            'name'    => 'global_utf8',
            'type'    => 'context',
            'summary' => 'résumé' . chr(0xE9),
            'content' => 'plain',
        ]);

        $persisted = Memory::findOrFail($result['memory']['id']);
        expect(mb_check_encoding($persisted->summary, 'UTF-8'))->toBeTrue();
    });
});
