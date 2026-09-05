<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Spora\Auth\AuthService;
use Spora\Models\GroupMembership;
use Spora\Plugins\Memories\Http\AgentMemoryController;
use Spora\Plugins\Memories\Models\Memory;
use Spora\Plugins\Memories\Services\MemoryCommandService;
use Spora\Plugins\Memories\Services\MemoryQueryService;
use Spora\Services\PrincipalService;
use Symfony\Component\HttpFoundation\Response;

/**
 * Group-owned agent visibility.
 *
 * Pre-fix regression coverage for the v2.1 bug where
 * `MemoryQueryService::findAgent` and its twin in `MemoryCommandService`
 * did a strict `principal_id = $actingPrincipalId` against the caller's
 * personal principal id. Members of the owning group therefore saw 404
 * on every `/api/v1/agents/{id}/memories*` endpoint because their
 * visible-principal set includes the group principal — not the user's
 * own principal — so the strict equality silently failed.
 *
 * These tests mirror the live spora-local fixture from the session:
 *   - `owner` user creates a group (he is its `owner`-role member)
 *   - `member` user is added to the same group with `member` role
 *   - `outsider` user is left out
 *   - an agent is created with `principal_id` = group's principal
 *
 * Tests then drive all 7 agent-memory endpoints for every triplet
 * (owner / member / outsider) and verify:
 *   - 200/201/204 for principals the caller can act as
 *   - 404 for principals outside the caller's reach (no leak)
 */

const GROUP_VISIBILITY_LABEL = 'group-visibility';

function makeGroupOwnedAgentController(?AuthService $authService = null): AgentMemoryController
{
    $authService   = $authService   ?? bootAuthLayer();
    $memoryQuery   = new MemoryQueryService();
    $memoryCommand = new MemoryCommandService();
    $principals    = new PrincipalService(new Spora\Services\PrincipalResolver());

    return new AgentMemoryController($authService, $memoryQuery, $memoryCommand, $principals);
}

/**
 * Build a 3-user / 1-group / 1-agent fixture and return a tidy record.
 * Each call yields fresh IDs thanks to the in-memory transaction
 * boundary in {@see beforeEach()}.
 *
 * @return array{
 *     owner:    array{userId: int, principalId: int},
 *     member:   array{userId: int, principalId: int},
 *     outsider: array{userId: int, principalId: int},
 *     groupId:        int,
 *     groupPrincipal: int,
 *     agentId:        int,
 * }
 */
function createGroupVisibilityFixture(AuthService $authService): array
{
    static $seq = 0;
    $seq++;

    $ownerUserId    = bootAuth($authService, "{$seq}owner@group.local", 'Password1!', "Owner {$seq}");
    $memberUserId   = bootAuth($authService, "{$seq}member@group.local", 'Password1!', "Member {$seq}");
    $outsiderUserId = bootAuth($authService, "{$seq}outside@group.local", 'Password1!', "Outsider {$seq}");

    $principalService = new PrincipalService(new Spora\Services\PrincipalResolver());

    $ownerPrincipalId    = (int) $principalService->ensureUserPrincipal($ownerUserId)->id;
    $memberPrincipalId   = (int) $principalService->ensureUserPrincipal($memberUserId)->id;
    $outsiderPrincipalId = (int) $principalService->ensureUserPrincipal($outsiderUserId)->id;

    $groupId = (int) Capsule::table('groups')->insertGetId([
        'name'               => "Group {$seq}",
        'description'        => "{$seq}-group-visibility",
        'created_by_user_id' => $ownerUserId,
        'created_at'         => date('Y-m-d H:i:s'),
        'updated_at'         => date('Y-m-d H:i:s'),
    ]);

    foreach ([
        [$groupId, $ownerUserId,  GroupMembership::ROLE_OWNER],
        [$groupId, $memberUserId, GroupMembership::ROLE_MEMBER],
    ] as [$gid, $uid, $role]) {
        Capsule::table('group_memberships')->insert([
            'group_id'  => $gid,
            'user_id'   => $uid,
            'role'      => $role,
            'joined_at' => date('Y-m-d H:i:s'),
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    $groupPrincipalId = (int) (new PrincipalService(new Spora\Services\PrincipalResolver()))
        ->ensureGroupPrincipal($groupId)
        ->id;

    $agentId = (int) Capsule::table('agents')->insertGetId([
        'principal_id' => $groupPrincipalId,
        'name'         => "Group Agent {$seq}",
        'max_steps'    => 5,
        'is_active'    => true,
        'created_at'   => date('Y-m-d H:i:s'),
        'updated_at'   => date('Y-m-d H:i:s'),
    ]);

    return [
        'owner'          => ['userId' => $ownerUserId,    'principalId' => $ownerPrincipalId],
        'member'         => ['userId' => $memberUserId,   'principalId' => $memberPrincipalId],
        'outsider'       => ['userId' => $outsiderUserId, 'principalId' => $outsiderPrincipalId],
        'groupId'        => $groupId,
        'groupPrincipal' => $groupPrincipalId,
        'agentId'        => $agentId,
    ];
}

function simulateAs(int $userId): void
{
    $email = (string) Capsule::table('users')->where('id', $userId)->value('email');
    simulateLoggedInSession($userId, $email);
}

// ----- index -----

describe(GROUP_VISIBILITY_LABEL . ' :: AgentMemoryController::index', function (): void {

    test('group owner can list a group-owned agent\'s memories', function (): void {
        $controller = makeGroupOwnedAgentController();
        $f = createGroupVisibilityFixture(bootAuthLayer());
        simulateAs($f['owner']['userId']);

        $request = new Symfony\Component\HttpFoundation\Request();
        $request->attributes->set('agentId', $f['agentId']);
        $response = $controller->index($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_OK);
        $body = json_decode($response->getContent(), true);
        expect($body['data']['memories'])->toBeArray()->toBeEmpty();
    });

    test('group member can list a group-owned agent\'s memories (regression)', function (): void {
        $controller = makeGroupOwnedAgentController();
        $f = createGroupVisibilityFixture(bootAuthLayer());
        simulateAs($f['member']['userId']);

        $request = new Symfony\Component\HttpFoundation\Request();
        $request->attributes->set('agentId', $f['agentId']);
        $response = $controller->index($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_OK);
        $body = json_decode($response->getContent(), true);
        expect($body['data']['memories'])->toBeArray()->toBeEmpty();
    });

    test('outsider gets 404 on the index endpoint', function (): void {
        $controller = makeGroupOwnedAgentController();
        $f = createGroupVisibilityFixture(bootAuthLayer());
        simulateAs($f['outsider']['userId']);

        $request = new Symfony\Component\HttpFoundation\Request();
        $request->attributes->set('agentId', $f['agentId']);
        $response = $controller->index($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_NOT_FOUND);
    });

    test('group member sees the persisted memory list (round-trip)', function (): void {
        $controller = makeGroupOwnedAgentController();
        $f = createGroupVisibilityFixture(bootAuthLayer());

        Memory::create([
            'principal_id' => $f['groupPrincipal'],
            'agent_id'     => $f['agentId'],
            'scope'        => 'agent',
            'type'         => 'context',
            'name'         => 'shared',
            'order'        => 1,
        ]);

        simulateAs($f['member']['userId']);
        $request = new Symfony\Component\HttpFoundation\Request();
        $request->attributes->set('agentId', $f['agentId']);
        $response = $controller->index($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_OK);
        $body = json_decode($response->getContent(), true);
        expect($body['data']['memories'])->toHaveCount(1);
        expect($body['data']['memories'][0]['name'])->toBe('shared');
    });
});

// ----- store -----

describe(GROUP_VISIBILITY_LABEL . ' :: AgentMemoryController::store', function (): void {

    test('group member can create a memory on a group-owned agent (regression)', function (): void {
        $controller = makeGroupOwnedAgentController();
        $f = createGroupVisibilityFixture(bootAuthLayer());
        simulateAs($f['member']['userId']);

        $request = jsonRequest('POST', "/api/v1/agents/{$f['agentId']}/memories", [
            'name'    => 'created_by_member',
            'type'    => 'context',
            'content' => 'written by a group member',
        ]);
        $request->attributes->set('agentId', $f['agentId']);
        $response = $controller->store($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_CREATED);
        $body = json_decode($response->getContent(), true);
        expect($body['data']['memory']['name'])->toBe('created_by_member');
        expect($body['data']['memory']['agent_id'])->toBe($f['agentId']);
    });

    test('outsider cannot create a memory on a group-owned agent', function (): void {
        $controller = makeGroupOwnedAgentController();
        $f = createGroupVisibilityFixture(bootAuthLayer());
        simulateAs($f['outsider']['userId']);

        $request = jsonRequest('POST', "/api/v1/agents/{$f['agentId']}/memories", [
            'name'    => 'should_not_persist',
            'type'    => 'context',
            'content' => 'attempted by outsider',
        ]);
        $request->attributes->set('agentId', $f['agentId']);
        $response = $controller->store($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_NOT_FOUND);
        expect(Capsule::table('memories')->where('name', 'should_not_persist')->doesntExist())->toBeTrue();
    });
});

// ----- show / update / replace / destroy -----

describe(GROUP_VISIBILITY_LABEL . ' :: AgentMemoryController::show+update+replace+destroy', function (): void {

    test('show is forbidden for outsiders and allowed for both members and owners', function (): void {
        $authService = bootAuthLayer();
        $f = createGroupVisibilityFixture($authService);

        $memory = Memory::create([
            'principal_id' => $f['groupPrincipal'],
            'agent_id'     => $f['agentId'],
            'scope'        => 'agent',
            'type'         => 'context',
            'name'         => 'shared_doc',
            'order'        => 1,
        ]);

        // Outsider: 404
        simulateAs($f['outsider']['userId']);
        $controller = makeGroupOwnedAgentController($authService);
        $request = new Symfony\Component\HttpFoundation\Request();
        $request->attributes->set('agentId', $f['agentId']);
        $request->attributes->set('memoryId', (string) $memory->id);
        expect($controller->show($request)->getStatusCode())->toBe(Response::HTTP_NOT_FOUND);

        // Member: 200
        simulateAs($f['member']['userId']);
        $controller = makeGroupOwnedAgentController($authService);
        $request = new Symfony\Component\HttpFoundation\Request();
        $request->attributes->set('agentId', $f['agentId']);
        $request->attributes->set('memoryId', (string) $memory->id);
        $response = $controller->show($request);
        expect($response->getStatusCode())->toBe(Response::HTTP_OK);
        expect(json_decode($response->getContent(), true)['data']['memory']['name'])->toBe('shared_doc');

        // Owner: 200
        simulateAs($f['owner']['userId']);
        $controller = makeGroupOwnedAgentController($authService);
        $request = new Symfony\Component\HttpFoundation\Request();
        $request->attributes->set('agentId', $f['agentId']);
        $request->attributes->set('memoryId', (string) $memory->id);
        expect($controller->show($request)->getStatusCode())->toBe(Response::HTTP_OK);
    });

    test('update is forbidden for outsiders and allowed for members', function (): void {
        $authService = bootAuthLayer();
        $f = createGroupVisibilityFixture($authService);

        $memory = Memory::create([
            'principal_id' => $f['groupPrincipal'],
            'agent_id'     => $f['agentId'],
            'scope'        => 'agent',
            'type'         => 'context',
            'name'         => 'writable',
            'order'        => 1,
        ]);

        simulateAs($f['outsider']['userId']);
        $controller = makeGroupOwnedAgentController($authService);
        $request = jsonRequest('PUT', "/api/v1/agents/{$f['agentId']}/memories/{$memory->id}", [
            'name' => 'sneaky',
        ]);
        $request->attributes->set('agentId', $f['agentId']);
        $request->attributes->set('memoryId', (string) $memory->id);
        expect($controller->update($request)->getStatusCode())->toBe(Response::HTTP_NOT_FOUND);
        $memory->refresh();
        expect($memory->name)->toBe('writable');

        simulateAs($f['member']['userId']);
        $controller = makeGroupOwnedAgentController($authService);
        $request = jsonRequest('PUT', "/api/v1/agents/{$f['agentId']}/memories/{$memory->id}", [
            'name' => 'edited_by_member',
        ]);
        $request->attributes->set('agentId', $f['agentId']);
        $request->attributes->set('memoryId', (string) $memory->id);
        expect($controller->update($request)->getStatusCode())->toBe(Response::HTTP_OK);
        $memory->refresh();
        expect($memory->name)->toBe('edited_by_member');
    });

    test('replace is forbidden for outsiders and allowed for members', function (): void {
        $authService = bootAuthLayer();
        $f = createGroupVisibilityFixture($authService);

        $memory = Memory::create([
            'principal_id' => $f['groupPrincipal'],
            'agent_id'     => $f['agentId'],
            'scope'        => 'agent',
            'type'         => 'documentation',
            'name'         => 'replaceable',
            'content'      => 'before replacement',
            'order'        => 1,
        ]);

        simulateAs($f['outsider']['userId']);
        $controller = makeGroupOwnedAgentController($authService);
        $request = jsonRequest('POST', "/api/v1/agents/{$f['agentId']}/memories/{$memory->id}/replace", [
            'name' => 'replaceable',
            'type' => 'documentation',
            'find' => 'before',
            'new_text' => 'AFTER',
        ]);
        $request->attributes->set('agentId', $f['agentId']);
        $request->attributes->set('memoryId', (string) $memory->id);
        expect($controller->replace($request)->getStatusCode())->toBe(Response::HTTP_NOT_FOUND);
        $memory->refresh();
        expect((string) $memory->content)->toBe('before replacement');

        simulateAs($f['member']['userId']);
        $controller = makeGroupOwnedAgentController($authService);
        $request = jsonRequest('POST', "/api/v1/agents/{$f['agentId']}/memories/{$memory->id}/replace", [
            'name' => 'replaceable',
            'type' => 'documentation',
            'find' => 'before',
            'new_text' => 'AFTER',
        ]);
        $request->attributes->set('agentId', $f['agentId']);
        $request->attributes->set('memoryId', (string) $memory->id);
        expect($controller->replace($request)->getStatusCode())->toBe(Response::HTTP_OK);
        $memory->refresh();
        expect((string) $memory->content)->toBe('AFTER replacement');
    });

    test('destroy is forbidden for outsiders and allowed for members', function (): void {
        $authService = bootAuthLayer();
        $f = createGroupVisibilityFixture($authService);

        $memory = Memory::create([
            'principal_id' => $f['groupPrincipal'],
            'agent_id'     => $f['agentId'],
            'scope'        => 'agent',
            'type'         => 'context',
            'name'         => 'deletable',
            'order'        => 1,
        ]);

        simulateAs($f['outsider']['userId']);
        $controller = makeGroupOwnedAgentController($authService);
        $request = new Symfony\Component\HttpFoundation\Request();
        $request->attributes->set('agentId', $f['agentId']);
        $request->attributes->set('memoryId', (string) $memory->id);
        expect($controller->destroy($request)->getStatusCode())->toBe(Response::HTTP_NOT_FOUND);
        expect(Capsule::table('memories')->where('id', $memory->id)->exists())->toBeTrue();

        simulateAs($f['member']['userId']);
        $controller = makeGroupOwnedAgentController($authService);
        $request = new Symfony\Component\HttpFoundation\Request();
        $request->attributes->set('agentId', $f['agentId']);
        $request->attributes->set('memoryId', (string) $memory->id);
        expect($controller->destroy($request)->getStatusCode())->toBe(Response::HTTP_OK);
        expect(Capsule::table('memories')->where('id', $memory->id)->exists())->toBeFalse();
    });
});

// ----- reorder -----

describe(GROUP_VISIBILITY_LABEL . ' :: AgentMemoryController::reorder', function (): void {

    test('reorder is forbidden for outsiders and allowed for members', function (): void {
        $authService = bootAuthLayer();
        $f = createGroupVisibilityFixture($authService);

        $m1 = Memory::create([
            'principal_id' => $f['groupPrincipal'], 'agent_id' => $f['agentId'], 'scope' => 'agent',
            'type' => 'context', 'name' => 'a', 'order' => 1,
        ]);
        $m2 = Memory::create([
            'principal_id' => $f['groupPrincipal'], 'agent_id' => $f['agentId'], 'scope' => 'agent',
            'type' => 'context', 'name' => 'b', 'order' => 2,
        ]);

        simulateAs($f['outsider']['userId']);
        $controller = makeGroupOwnedAgentController($authService);
        $request = jsonRequest('PATCH', "/api/v1/agents/{$f['agentId']}/memories/reorder", [
            'order' => [$m2->id, $m1->id],
        ]);
        $request->attributes->set('agentId', $f['agentId']);
        expect($controller->reorder($request)->getStatusCode())->toBe(Response::HTTP_NOT_FOUND);
        $m1->refresh();
        $m2->refresh();
        expect($m1->order)->toBe(1);
        expect($m2->order)->toBe(2);

        simulateAs($f['member']['userId']);
        $controller = makeGroupOwnedAgentController($authService);
        $request = jsonRequest('PATCH', "/api/v1/agents/{$f['agentId']}/memories/reorder", [
            'order' => [$m2->id, $m1->id],
        ]);
        $request->attributes->set('agentId', $f['agentId']);
        expect($controller->reorder($request)->getStatusCode())->toBe(Response::HTTP_OK);
        $m1->refresh();
        $m2->refresh();
        expect($m2->order)->toBe(1);
        expect($m1->order)->toBe(2);
    });
});

// ----- service-level regression coverage -----

describe(GROUP_VISIBILITY_LABEL . ' :: Service layer regression', function (): void {

    test('MemoryQueryService::listAgentMemories returns null for an outsider and an array for a member', function (): void {
        $service = new MemoryQueryService();
        $f = createGroupVisibilityFixture(bootAuthLayer());

        // Member sees the agent.
        $result = $service->listAgentMemories($f['agentId'], $f['member']['principalId']);
        expect($result)->toBeArray()->toBeEmpty();

        // Outsider does not.
        $result = $service->listAgentMemories($f['agentId'], $f['outsider']['principalId']);
        expect($result)->toBeNull();
    });

    test('MemoryCommandService::createAgentMemory returns row for member and throws for outsider', function (): void {
        $service = new MemoryCommandService();
        $f = createGroupVisibilityFixture(bootAuthLayer());

        $result = $service->createAgentMemory($f['agentId'], $f['member']['principalId'], [
            'name' => 'created_by_member', 'type' => 'context', 'content' => 'ok',
        ]);
        expect($result['memory']['name'])->toBe('created_by_member');

        expect(fn() => $service->createAgentMemory($f['agentId'], $f['outsider']['principalId'], [
            'name' => 'should_not_persist', 'type' => 'context', 'content' => 'no',
        ]))->toThrow(Spora\Services\Exceptions\AgentNotFoundException::class);

        expect(Capsule::table('memories')->where('name', 'should_not_persist')->doesntExist())->toBeTrue();
    });

    test('MemoryCommandService::deleteAgentMemory returns false for outsider, true for member', function (): void {
        $service = new MemoryCommandService();
        $f = createGroupVisibilityFixture(bootAuthLayer());

        $memory = Memory::create([
            'principal_id' => $f['groupPrincipal'], 'agent_id' => $f['agentId'], 'scope' => 'agent',
            'type' => 'context', 'name' => 'deletable', 'order' => 1,
        ]);

        expect($service->deleteAgentMemory((string) $memory->id, $f['agentId'], $f['outsider']['principalId']))->toBeFalse();
        expect(Capsule::table('memories')->where('id', $memory->id)->exists())->toBeTrue();

        expect($service->deleteAgentMemory((string) $memory->id, $f['agentId'], $f['member']['principalId']))->toBeTrue();
        expect(Capsule::table('memories')->where('id', $memory->id)->exists())->toBeFalse();
    });
});
