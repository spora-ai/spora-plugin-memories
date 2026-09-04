<?php

declare(strict_types=1);

use Spora\Plugins\Memories\Models\Memory;
use Spora\Plugins\Memories\Tools\AgentMemoryTool;
use Spora\Plugins\Memories\Tools\GlobalMemoryTool;
use Spora\Services\PrincipalContext;
use Spora\Tools\Attributes\Tool;

const MEM_TEST_PASSWORD = 'Password1!';
const MEM_ERR_NAME_REQUIRED = 'name is required';
const MEM_ERR_NOT_FOUND = 'not found';


function createMemoryToolTestUser(string $email = 'memory@example.com'): array
{
    static $seq = 0;
    $seq++;
    $authService = bootAuthLayer();
    $userId = bootAuth($authService, "{$seq}{$email}", MEM_TEST_PASSWORD);

    $agentId = createAgentWithPrincipal($userId, 'Test Agent', ['max_steps' => 10]);
    $principalId = createUserPrincipal($userId);

    return [$userId, $agentId, $principalId];
}

function getToolName(object $tool): string
{
    $ref = new ReflectionClass($tool);
    $attr = $ref->getAttributes(Tool::class)[0];
    return $attr->newInstance()->name;
}

function principalContextFor(int $principalId): PrincipalContext
{
    return new PrincipalContext(
        principalId: $principalId,
        type: 'user',
        ownerUserId: $principalId,
        runnerUserId: $principalId,
    );
}

describe('AgentMemoryTool::tool metadata', function (): void {

    it('returns correct tool name from attribute', function (): void {
        expect(getToolName(new AgentMemoryTool()))->toBe('memory');
    });

    it('describeAction returns correct description', function (): void {
        $tool = new AgentMemoryTool();

        expect($tool->describeAction(['action' => 'save', 'name' => 'my_memory']))
            ->toContain('memory')
            ->toContain('save')
            ->toContain('my_memory');
    });

    it('list/get/save auto-approve; delete and replace require approval', function (): void {
        $tool = new AgentMemoryTool();

        expect($tool->requiresApprovalByDefault('list'))->toBeFalse()
            ->and($tool->requiresApprovalByDefault('get'))->toBeFalse()
            ->and($tool->requiresApprovalByDefault('save'))->toBeFalse()
            ->and($tool->requiresApprovalByDefault('delete'))->toBeTrue()
            ->and($tool->requiresApprovalByDefault('replace'))->toBeTrue();
    });
});

describe('AgentMemoryTool::list action', function (): void {

    it('list returns empty message when no memories exist', function (): void {
        [, $agentId, $principalId] = createMemoryToolTestUser();
        $tool = new AgentMemoryTool();

        $result = $tool->execute(['action' => 'list'], $agentId, null, null, principalContextFor($principalId));

        expect($result->success)->toBeTrue()
            ->and($result->content)->toContain('No memories found')
            ->and($result->content)->toContain('agent scope');
    });

    it('list returns memories for this agent only', function (): void {
        [, $agentId, $principalId] = createMemoryToolTestUser();
        $tool = new AgentMemoryTool();

        Memory::create([
            'principal_id' => $principalId,
            'agent_id'     => $agentId,
            'scope'        => 'agent',
            'type'         => 'context',
            'name'         => 'my_memory',
            'summary'      => 'My summary',
            'content'      => 'My content',
        ]);

        $otherAgentId = createAgentWithPrincipal($principalId, 'Other Agent', ['max_steps' => 10]);
        Memory::create([
            'principal_id' => $principalId,
            'agent_id'     => $otherAgentId,
            'scope'        => 'agent',
            'type'         => 'context',
            'name'         => 'other_memory',
            'content'      => 'Other content',
        ]);

        $result = $tool->execute(['action' => 'list'], $agentId, null, null, principalContextFor($principalId));

        expect($result->success)->toBeTrue()
            ->and($result->content)->toContain('my_memory')
            ->and($result->content)->toContain('My summary')
            ->and($result->content)->not->toContain('other_memory');
    });

});

describe('AgentMemoryTool::save action', function (): void {

    it('save creates a new memory', function (): void {
        [, $agentId, $principalId] = createMemoryToolTestUser();
        $tool = new AgentMemoryTool();

        $result = $tool->execute([
            'action'  => 'save',
            'name'    => 'project_notes',
            'type'    => 'context',
            'content' => '# Project Notes\n\nThese are the project notes.',
            'summary' => 'Project notes summary',
            'order'   => 5,
        ], $agentId, null, null, principalContextFor($principalId));

        expect($result->success)->toBeTrue()
            ->and($result->content)->toContain('Created memory [project_notes]')
            ->and($result->content)->toContain('agent scope');

        $memory = Memory::where('name', 'project_notes')->first();
        expect($memory)->not->toBeNull()
            ->and($memory->agent_id)->toBe($agentId)
            ->and($memory->content)->toContain('Project Notes')
            ->and($memory->summary)->toBe('Project notes summary')
            ->and($memory->order)->toBe(5);
    });

    it('save updates an existing memory', function (): void {
        [, $agentId, $principalId] = createMemoryToolTestUser();
        $tool = new AgentMemoryTool();

        Memory::create([
            'principal_id' => $principalId,
            'agent_id'     => $agentId,
            'scope'        => 'agent',
            'type'         => 'context',
            'name'         => 'updatable',
            'content'      => 'Original content',
            'summary'      => 'Original summary',
        ]);

        $result = $tool->execute([
            'action'  => 'save',
            'name'    => 'updatable',
            'type'    => 'context',
            'content' => 'Updated content',
        ], $agentId, null, null, principalContextFor($principalId));

        expect($result->success)->toBeTrue()
            ->and($result->content)->toContain('Updated memory [updatable]');

        $memory = Memory::where('name', 'updatable')->first();
        expect($memory->content)->toBe('Updated content')
            ->and($memory->summary)->toBe('Original summary');
    });

    it('save auto-derives summary from content when not provided', function (): void {
        [, $agentId, $principalId] = createMemoryToolTestUser();
        $tool = new AgentMemoryTool();

        $longContent = '<p>This is a <strong>long</strong> content that should have a summary auto-derived from it.</p>';
        $tool->execute([
            'action'  => 'save',
            'name'    => 'auto_summary',
            'type'    => 'context',
            'content' => $longContent,
        ], $agentId, null, null, principalContextFor($principalId));

        $memory = Memory::where('name', 'auto_summary')->first();
        expect($memory->summary)->not->toBeNull()
            ->and(strlen($memory->summary))->toBeLessThanOrEqual(200)
            ->and($memory->summary)->not->toContain('<p>');
    });

    it('save returns error when name is missing', function (): void {
        [, $agentId, $principalId] = createMemoryToolTestUser();
        $tool = new AgentMemoryTool();

        $result = $tool->execute([
            'action'  => 'save',
            'type'    => 'context',
            'content' => 'Some content without a name',
        ], $agentId, null, null, principalContextFor($principalId));

        expect($result->success)->toBeFalse()
            ->and($result->content)->toContain(MEM_ERR_NAME_REQUIRED);
    });

    it('save returns error when type is missing', function (): void {
        [, $agentId, $principalId] = createMemoryToolTestUser();
        $tool = new AgentMemoryTool();

        $result = $tool->execute([
            'action'  => 'save',
            'name'    => 'X',
            'content' => 'body',
        ], $agentId, null, null, principalContextFor($principalId));

        expect($result->success)->toBeFalse()
            ->and($result->content)->toContain('type is required');
    });

    it('save returns error when type is unknown', function (): void {
        [, $agentId, $principalId] = createMemoryToolTestUser();
        $tool = new AgentMemoryTool();

        $result = $tool->execute([
            'action'  => 'save',
            'name'    => 'X',
            'type'    => 'mystery',
            'content' => 'body',
        ], $agentId, null, null, principalContextFor($principalId));

        expect($result->success)->toBeFalse()
            ->and($result->content)->toContain("type 'mystery' is not one of");
    });

});

describe('AgentMemoryTool::get action', function (): void {

    it('get retrieves a memory by name and type', function (): void {
        [, $agentId, $principalId] = createMemoryToolTestUser();
        $tool = new AgentMemoryTool();

        Memory::create([
            'principal_id' => $principalId,
            'agent_id'     => $agentId,
            'scope'        => 'agent',
            'type'         => 'context',
            'name'         => 'get_test',
            'summary'      => 'Summary for get test',
            'content'      => '# Get Test Content\n\nThis is the content.',
        ]);

        $result = $tool->execute([
            'action' => 'get',
            'name'   => 'get_test',
            'type'   => 'context',
        ], $agentId, null, null, principalContextFor($principalId));

        expect($result->success)->toBeTrue()
            ->and($result->content)->toContain('# Get Test Content')
            ->and($result->content)->toContain('Summary for get test')
            ->and($result->content)->toContain('This is the content');
    });

    it('get returns error when name is missing', function (): void {
        [, $agentId, $principalId] = createMemoryToolTestUser();
        $tool = new AgentMemoryTool();

        $result = $tool->execute(['action' => 'get', 'type' => 'context'], $agentId, null, null, principalContextFor($principalId));

        expect($result->success)->toBeFalse()
            ->and($result->content)->toContain(MEM_ERR_NAME_REQUIRED);
    });

    it('get returns error when type is missing', function (): void {
        [, $agentId, $principalId] = createMemoryToolTestUser();
        $tool = new AgentMemoryTool();

        $result = $tool->execute(['action' => 'get', 'name' => 'X'], $agentId, null, null, principalContextFor($principalId));

        expect($result->success)->toBeFalse()
            ->and($result->content)->toContain('type is required');
    });

    it('get returns error when memory not found', function (): void {
        [, $agentId, $principalId] = createMemoryToolTestUser();
        $tool = new AgentMemoryTool();

        $result = $tool->execute([
            'action' => 'get',
            'name'   => 'nonexistent',
            'type'   => 'context',
        ], $agentId, null, null, principalContextFor($principalId));

        expect($result->success)->toBeFalse()
            ->and($result->content)->toContain(MEM_ERR_NOT_FOUND);
    });

});

describe('AgentMemoryTool::delete action', function (): void {

    it('delete removes a memory by name and type', function (): void {
        [, $agentId, $principalId] = createMemoryToolTestUser();
        $tool = new AgentMemoryTool();

        Memory::create([
            'principal_id' => $principalId,
            'agent_id'     => $agentId,
            'scope'        => 'agent',
            'type'         => 'context',
            'name'         => 'to_delete',
            'content'      => 'Will be deleted',
        ]);

        $result = $tool->execute([
            'action' => 'delete',
            'name'   => 'to_delete',
            'type'   => 'context',
        ], $agentId, null, null, principalContextFor($principalId));

        expect($result->success)->toBeTrue()
            ->and($result->content)->toContain('Deleted memory [to_delete]');

        expect(Memory::where('name', 'to_delete')->first())->toBeNull();
    });

    it('delete returns error when name is missing', function (): void {
        [, $agentId, $principalId] = createMemoryToolTestUser();
        $tool = new AgentMemoryTool();

        $result = $tool->execute(['action' => 'delete', 'type' => 'context'], $agentId, null, null, principalContextFor($principalId));

        expect($result->success)->toBeFalse()
            ->and($result->content)->toContain(MEM_ERR_NAME_REQUIRED);
    });

    it('delete returns error when memory not found', function (): void {
        [, $agentId, $principalId] = createMemoryToolTestUser();
        $tool = new AgentMemoryTool();

        $result = $tool->execute([
            'action' => 'delete',
            'name'   => 'nonexistent',
            'type'   => 'context',
        ], $agentId, null, null, principalContextFor($principalId));

        expect($result->success)->toBeFalse()
            ->and($result->content)->toContain(MEM_ERR_NOT_FOUND);
    });

});

describe('AgentMemoryTool::replace action', function (): void {

    it('replace changes the content via unique-substring anchor', function (): void {
        [, $agentId, $principalId] = createMemoryToolTestUser();
        $tool = new AgentMemoryTool();

        Memory::create([
            'principal_id' => $principalId,
            'agent_id'     => $agentId,
            'scope'        => 'agent',
            'type'         => 'documentation',
            'name'         => 'sprint',
            'content'      => 'TODO: ship auth, write tests',
        ]);

        $result = $tool->execute([
            'action'   => 'replace',
            'name'     => 'sprint',
            'type'     => 'documentation',
            'find'     => 'write tests',
            'new_text' => 'write tests (done)',
        ], $agentId, null, null, principalContextFor($principalId));

        expect($result->success)->toBeTrue()
            ->and($result->content)->toContain('Replaced 1 occurrence');

        $memory = Memory::where('name', 'sprint')->first();
        expect($memory->content)->toContain('write tests (done)');
    });

    it('replace fails when find matches 0 occurrences', function (): void {
        [, $agentId, $principalId] = createMemoryToolTestUser();
        $tool = new AgentMemoryTool();

        Memory::create([
            'principal_id' => $principalId,
            'agent_id'     => $agentId,
            'scope'        => 'agent',
            'type'         => 'documentation',
            'name'         => 'sprint',
            'content'      => 'plain body',
        ]);

        $result = $tool->execute([
            'action'   => 'replace',
            'name'     => 'sprint',
            'type'     => 'documentation',
            'find'     => 'missing',
            'new_text' => 'X',
        ], $agentId, null, null, principalContextFor($principalId));

        expect($result->success)->toBeFalse()
            ->and($result->content)->toContain('matches 0 occurrences');
    });

    it('replace fails when find matches > 1 occurrences', function (): void {
        [, $agentId, $principalId] = createMemoryToolTestUser();
        $tool = new AgentMemoryTool();

        Memory::create([
            'principal_id' => $principalId,
            'agent_id'     => $agentId,
            'scope'        => 'agent',
            'type'         => 'documentation',
            'name'         => 'sprint',
            'content'      => 'foo foo foo',
        ]);

        $result = $tool->execute([
            'action'   => 'replace',
            'name'     => 'sprint',
            'type'     => 'documentation',
            'find'     => 'foo',
            'new_text' => 'bar',
        ], $agentId, null, null, principalContextFor($principalId));

        expect($result->success)->toBeFalse()
            ->and($result->content)->toContain('matches 3');
    });

    it('replace requires approval by default', function (): void {
        $tool = new AgentMemoryTool();
        expect($tool->requiresApprovalByDefault('replace'))->toBeTrue();
    });
});

describe('AgentMemoryTool::invalid action', function (): void {

    it('returns error for invalid action', function (): void {
        [, $agentId, $principalId] = createMemoryToolTestUser();
        $tool = new AgentMemoryTool();

        $result = $tool->execute(['action' => 'invalid_action'], $agentId, null, null, principalContextFor($principalId));

        expect($result->success)->toBeFalse()
            ->and($result->content)->toContain('Invalid action');
    });

});



// GlobalMemoryTool

describe('GlobalMemoryTool::tool metadata', function (): void {

    it('returns correct tool name from attribute', function (): void {
        $tool = new GlobalMemoryTool();

        expect(getToolName($tool))->toBe('global_memory');
    });

    it('describeAction returns correct description', function (): void {
        $tool = new GlobalMemoryTool();

        expect($tool->describeAction(['action' => 'save', 'name' => 'my_memory']))
            ->toContain('memory')
            ->toContain('save')
            ->toContain('my_memory');
    });

    it('list/get/save auto-approve; delete and replace require approval', function (): void {
        $tool = new GlobalMemoryTool();

        expect($tool->requiresApprovalByDefault('list'))->toBeFalse()
            ->and($tool->requiresApprovalByDefault('get'))->toBeFalse()
            ->and($tool->requiresApprovalByDefault('save'))->toBeFalse()
            ->and($tool->requiresApprovalByDefault('delete'))->toBeTrue()
            ->and($tool->requiresApprovalByDefault('replace'))->toBeTrue();
    });
});

describe('GlobalMemoryTool::list action', function (): void {

    it('list returns empty message when no global memories exist', function (): void {
        [, $agentId, $principalId] = createMemoryToolTestUser();
        $tool = new GlobalMemoryTool();

        $result = $tool->execute(['action' => 'list'], $agentId, null, null, principalContextFor($principalId));

        expect($result->success)->toBeTrue()
            ->and($result->content)->toContain('No memories found')
            ->and($result->content)->toContain('global scope');
    });

    it('list returns global memories only (not agent-scoped)', function (): void {
        [, $agentId, $principalId] = createMemoryToolTestUser();
        $tool = new GlobalMemoryTool();

        Memory::create([
            'principal_id' => $principalId,
            'scope'        => 'global',
            'type'         => 'context',
            'name'         => 'global_pref',
            'content'      => 'Global preference content',
        ]);

        Memory::create([
            'principal_id' => $principalId,
            'agent_id'     => $agentId,
            'scope'        => 'agent',
            'type'         => 'context',
            'name'         => 'agent_only',
            'content'      => 'Agent-only content',
        ]);

        $result = $tool->execute(['action' => 'list'], $agentId, null, null, principalContextFor($principalId));

        expect($result->success)->toBeTrue()
            ->and($result->content)->toContain('global_pref')
            ->and($result->content)->not->toContain('agent_only');
    });

});

describe('GlobalMemoryTool::save action', function (): void {

    it('save creates a new global memory', function (): void {
        [, $agentId, $principalId] = createMemoryToolTestUser();
        $tool = new GlobalMemoryTool();

        $result = $tool->execute([
            'action'  => 'save',
            'name'    => 'company_policy',
            'type'    => 'context',
            'content' => 'Our company policy is to be excellent.',
            'summary' => 'Company policy',
        ], $agentId, null, null, principalContextFor($principalId));

        expect($result->success)->toBeTrue()
            ->and($result->content)->toContain('Created memory [company_policy]')
            ->and($result->content)->toContain('global scope');

        $memory = Memory::where('name', 'company_policy')->first();
        expect($memory)->not->toBeNull()
            ->and($memory->agent_id)->toBeNull()
            ->and($memory->principal_id)->toBe($principalId);
    });

    it('save updates an existing global memory', function (): void {
        [, $agentId, $principalId] = createMemoryToolTestUser();
        $tool = new GlobalMemoryTool();

        Memory::create([
            'principal_id' => $principalId,
            'scope'        => 'global',
            'type'         => 'context',
            'name'         => 'global_update',
            'content'      => 'Original global content',
        ]);

        $result = $tool->execute([
            'action'  => 'save',
            'name'    => 'global_update',
            'type'    => 'context',
            'content' => 'Updated global content',
        ], $agentId, null, null, principalContextFor($principalId));

        expect($result->success)->toBeTrue()
            ->and($result->content)->toContain('Updated memory [global_update]');

        $memory = Memory::where('name', 'global_update')->first();
        expect($memory->content)->toBe('Updated global content');
    });

});

describe('GlobalMemoryTool::get action', function (): void {

    it('get retrieves a global memory by name and type', function (): void {
        [, $agentId, $principalId] = createMemoryToolTestUser();
        $tool = new GlobalMemoryTool();

        Memory::create([
            'principal_id' => $principalId,
            'scope'        => 'global',
            'type'         => 'context',
            'name'         => 'global_get',
            'summary'      => 'Global get summary',
            'content'      => '# Global Get\n\nGlobal content here.',
        ]);

        $result = $tool->execute([
            'action' => 'get',
            'name'   => 'global_get',
            'type'   => 'context',
        ], $agentId, null, null, principalContextFor($principalId));

        expect($result->success)->toBeTrue()
            ->and($result->content)->toContain('# Global Get')
            ->and($result->content)->toContain('Global get summary')
            ->and($result->content)->toContain('Global content here');
    });

    it('get does not find agent-scoped memory with same name', function (): void {
        [, $agentId, $principalId] = createMemoryToolTestUser();
        $tool = new GlobalMemoryTool();

        Memory::create([
            'principal_id' => $principalId,
            'agent_id'     => $agentId,
            'scope'        => 'agent',
            'type'         => 'context',
            'name'         => 'agent_scoped_get',
            'content'      => 'This should not be found by global tool',
        ]);

        $result = $tool->execute([
            'action' => 'get',
            'name'   => 'agent_scoped_get',
            'type'   => 'context',
        ], $agentId, null, null, principalContextFor($principalId));

        expect($result->success)->toBeFalse()
            ->and($result->content)->toContain(MEM_ERR_NOT_FOUND);
    });

});

describe('GlobalMemoryTool::delete action', function (): void {

    it('delete removes a global memory by name and type', function (): void {
        [, $agentId, $principalId] = createMemoryToolTestUser();
        $tool = new GlobalMemoryTool();

        Memory::create([
            'principal_id' => $principalId,
            'scope'        => 'global',
            'type'         => 'context',
            'name'         => 'global_delete',
            'content'      => 'Will be deleted globally',
        ]);

        $result = $tool->execute([
            'action' => 'delete',
            'name'   => 'global_delete',
            'type'   => 'context',
        ], $agentId, null, null, principalContextFor($principalId));

        expect($result->success)->toBeTrue()
            ->and($result->content)->toContain('Deleted memory [global_delete]');

        expect(Memory::where('name', 'global_delete')->first())->toBeNull();
    });

    it('delete does not delete agent-scoped memory with same name', function (): void {
        [, $agentId, $principalId] = createMemoryToolTestUser();
        $tool = new GlobalMemoryTool();

        Memory::create([
            'principal_id' => $principalId,
            'scope'        => 'global',
            'type'         => 'context',
            'name'         => 'shared_name',
            'content'      => 'Global version',
        ]);
        Memory::create([
            'principal_id' => $principalId,
            'agent_id'     => $agentId,
            'scope'        => 'agent',
            'type'         => 'context',
            'name'         => 'shared_name',
            'content'      => 'Agent version',
        ]);

        $tool->execute(['action' => 'delete', 'name' => 'shared_name', 'type' => 'context'], $agentId, null, null, principalContextFor($principalId));

        expect(Memory::where('name', 'shared_name')->whereNull('agent_id')->first())->toBeNull();
        expect(Memory::where('name', 'shared_name')->where('agent_id', $agentId)->first())->not->toBeNull();
    });

});

describe('GlobalMemoryTool::replace action', function (): void {

    it('replace changes the content via unique-substring anchor', function (): void {
        [, $agentId, $principalId] = createMemoryToolTestUser();
        $tool = new GlobalMemoryTool();

        Memory::create([
            'principal_id' => $principalId,
            'scope'        => 'global',
            'type'         => 'documentation',
            'name'         => 'editorial_plan',
            'content'      => '# Editorial plan\n\nphase 1: pitch\nphase 2: draft',
        ]);

        $result = $tool->execute([
            'action'   => 'replace',
            'name'     => 'editorial_plan',
            'type'     => 'documentation',
            'find'     => 'phase 2: draft',
            'new_text' => 'phase 2: copy edit',
        ], $agentId, null, null, principalContextFor($principalId));

        expect($result->success)->toBeTrue();

        $memory = Memory::where('name', 'editorial_plan')->first();
        expect($memory->content)->toContain('phase 2: copy edit');
    });

    it('replace fails when find matches > 1 occurrences', function (): void {
        [, $agentId, $principalId] = createMemoryToolTestUser();
        $tool = new GlobalMemoryTool();

        Memory::create([
            'principal_id' => $principalId,
            'scope'        => 'global',
            'type'         => 'documentation',
            'name'         => 'editorial_plan',
            'content'      => 'x x x',
        ]);

        $result = $tool->execute([
            'action'   => 'replace',
            'name'     => 'editorial_plan',
            'type'     => 'documentation',
            'find'     => 'x',
            'new_text' => 'y',
        ], $agentId, null, null, principalContextFor($principalId));

        expect($result->success)->toBeFalse()
            ->and($result->content)->toContain('matches 3');
    });
});

describe('GlobalMemoryTool::invalid action', function (): void {

    it('returns error for invalid action', function (): void {
        [, $agentId, $principalId] = createMemoryToolTestUser();
        $tool = new GlobalMemoryTool();

        $result = $tool->execute(['action' => 'hack'], $agentId, null, null, principalContextFor($principalId));

        expect($result->success)->toBeFalse()
            ->and($result->content)->toContain('Invalid action');
    });

});



// Isolation between principals

describe('Principal isolation', function (): void {

    it('principals cannot see each others global memories', function (): void {
        $authService1 = bootAuthLayer();
        $userId1 = bootAuth($authService1, 'user1@example.com', MEM_TEST_PASSWORD);
        $principalId1 = createUserPrincipal($userId1);
        createAgentWithPrincipal($userId1, 'Agent 1', ['max_steps' => 10]);

        $authService2 = bootAuthLayer();
        $userId2 = bootAuth($authService2, 'user2@example.com', MEM_TEST_PASSWORD);
        $agentId2 = createAgentWithPrincipal($userId2, 'Agent 2', ['max_steps' => 10]);
        $principalId2 = createUserPrincipal($userId2);

        Memory::create([
            'principal_id' => $principalId1,
            'scope'        => 'global',
            'type'         => 'context',
            'name'         => 'user1_private',
            'content'      => 'User 1 private global memory',
        ]);

        $tool = new GlobalMemoryTool();
        $result = $tool->execute(['action' => 'list'], $agentId2, null, null, principalContextFor($principalId2));

        expect($result->content)->not->toContain('user1_private');
    });

    it('principals cannot see each others agent memories', function (): void {
        $authService1 = bootAuthLayer();
        $userId1 = bootAuth($authService1, 'user3@example.com', MEM_TEST_PASSWORD);
        $principalId1 = createUserPrincipal($userId1);
        $agentId1 = createAgentWithPrincipal($userId1, 'Agent 1', ['max_steps' => 10]);

        $authService2 = bootAuthLayer();
        $userId2 = bootAuth($authService2, 'user4@example.com', MEM_TEST_PASSWORD);
        $agentId2 = createAgentWithPrincipal($userId2, 'Agent 2', ['max_steps' => 10]);
        $principalId2 = createUserPrincipal($userId2);

        Memory::create([
            'principal_id' => $principalId1,
            'agent_id'     => $agentId1,
            'scope'        => 'agent',
            'type'         => 'context',
            'name'         => 'user1_agent_memory',
            'content'      => 'User 1 agent memory content',
        ]);

        $tool = new AgentMemoryTool();
        $result = $tool->execute(['action' => 'list'], $agentId2, null, null, principalContextFor($principalId2));

        expect($result->content)->not->toContain('user1_agent_memory');
    });
});
