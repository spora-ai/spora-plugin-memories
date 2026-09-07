<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Spora\Models\GroupMembership;
use Spora\Plugins\Memories\Models\Memory;
use Spora\Plugins\Memories\Services\MemoryCommandService;
use Spora\Plugins\Memories\Services\MemoryQueryService;
use Spora\Services\PrincipalResolver;
use Spora\Services\PrincipalService;

/**
 * Cross-principal IDOR regression coverage for the v2.1 → v2.2 fix.
 *
 * Before the fix:
 *   `MemoryQueryService::findAgent` and its `MemoryCommandService` twin
 *   routed the `?principal_id=` value through
 *   {@see PrincipalResolver::ownerUserId()} before calling
 *   {@see PrincipalResolver::isVisibleTo()}. `ownerUserId` returns the
 *   **principal's owner**, not the calling user — so any non-owner
 *   member Y of a group G could call `?principal_id=G` and the service
 *   would resolve visibility against G's owner X. X's visible-principal
 *   set includes every principal they own, so Y gained read/write access
 *   to every agent owned by X (including X's personal agents).
 *
 * The fix collapses the visibility gate to a single helper that takes
 * `$userId` straight from the controller's auth layer, so Y is
 *   `isVisibleTo($agentId, $userId_Y)` — which returns false on
 *   group-owned agents (Y isn't in the group) and false on
 *   user-principal agents (Y isn't X).
 *
 * These tests assert the post-fix behaviour across both services for
 * every agent-scoped write/read endpoint, with the calling user Y as a
 * non-owner `member` of group G, and the target agent owned by X's
 * user-principal (i.e. X's personal agent).
 */

function createCrossPrincipalIdorFixture(): array
{
    static $seq = 0;
    $seq++;

    $authService = bootAuthLayer();

    $ownerUserId    = bootAuth($authService, "{$seq}cp-owner@x.local", 'Password1!', "Owner {$seq}");
    $memberUserId   = bootAuth($authService, "{$seq}cp-member@x.local", 'Password1!', "Member {$seq}");
    $outsiderUserId = bootAuth($authService, "{$seq}cp-outsider@x.local", 'Password1!', "Outsider {$seq}");

    $resolver   = new PrincipalResolver();
    $principalSvc = new PrincipalService($resolver);

    $ownerPrincipal    = (int) $principalSvc->ensureUserPrincipal($ownerUserId)->id;
    $memberPrincipal   = (int) $principalSvc->ensureUserPrincipal($memberUserId)->id;
    $outsiderPrincipal = (int) $principalSvc->ensureUserPrincipal($outsiderUserId)->id;

    $groupId = (int) Capsule::table('groups')->insertGetId([
        'name'               => "CP Group {$seq}",
        'description'        => "{$seq}-cross-principal-idor",
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

    $groupPrincipal = (int) $principalSvc->ensureGroupPrincipal($groupId)->id;

    // Agent owned by X (the OWNER's user-principal) — X's personal agent.
    $xOwnedAgentId = (int) Capsule::table('agents')->insertGetId([
        'principal_id' => $ownerPrincipal,
        'name'         => "X's Personal Agent {$seq}",
        'max_steps'    => 5,
        'is_active'    => true,
        'created_at'   => date('Y-m-d H:i:s'),
        'updated_at'   => date('Y-m-d H:i:s'),
    ]);

    // Group-owned agent — members SHOULD be able to see this. Control case.
    $groupAgentId = (int) Capsule::table('agents')->insertGetId([
        'principal_id' => $groupPrincipal,
        'name'         => "CP Group Agent {$seq}",
        'max_steps'    => 5,
        'is_active'    => true,
        'created_at'   => date('Y-m-d H:i:s'),
        'updated_at'   => date('Y-m-d H:i:s'),
    ]);

    return [
        'authService'   => $authService,
        'owner'         => ['userId' => $ownerUserId,    'principalId' => $ownerPrincipal],
        'member'        => ['userId' => $memberUserId,   'principalId' => $memberPrincipal],
        'outsider'      => ['userId' => $outsiderUserId, 'principalId' => $outsiderPrincipal],
        'groupId'       => $groupId,
        'groupPrincipal' => $groupPrincipal,
        'xOwnedAgentId' => $xOwnedAgentId,
        'groupAgentId'  => $groupAgentId,
    ];
}

// ---------------------------------------------------------------------------
// QueryService — read paths
// ---------------------------------------------------------------------------

describe('Cross-principal IDOR :: MemoryQueryService', function (): void {

    test('non-owner member Y gets null on listAgentMemories for X\'s personal agent', function (): void {
        $service = new MemoryQueryService();
        $f = createCrossPrincipalIdorFixture();

        $result = $service->listAgentMemories($f['xOwnedAgentId'], $f['member']['userId']);
        expect($result)->toBeNull();
    });

    test('outsider also gets null on listAgentMemories for X\'s personal agent', function (): void {
        $service = new MemoryQueryService();
        $f = createCrossPrincipalIdorFixture();

        $result = $service->listAgentMemories($f['xOwnedAgentId'], $f['outsider']['userId']);
        expect($result)->toBeNull();
    });

    test('non-owner member Y gets null on getAgentMemory for X\'s personal agent', function (): void {
        $service = new MemoryQueryService();
        $f = createCrossPrincipalIdorFixture();

        $memory = Memory::create([
            'principal_id' => $f['owner']['principalId'],
            'agent_id'     => $f['xOwnedAgentId'],
            'scope'        => 'agent',
            'type'         => 'context',
            'name'         => 'x_personal_only',
            'order'        => 1,
        ]);

        $result = $service->getAgentMemory((string) $memory->id, $f['xOwnedAgentId'], $f['member']['userId']);
        expect($result)->toBeNull();
    });

    test('control: member Y CAN see the group-owned agent (regression guard)', function (): void {
        $service = new MemoryQueryService();
        $f = createCrossPrincipalIdorFixture();

        $result = $service->listAgentMemories($f['groupAgentId'], $f['member']['userId']);
        expect($result)->toBeArray()->toBeEmpty();
    });
});

// ---------------------------------------------------------------------------
// CommandService — write paths
// ---------------------------------------------------------------------------

describe('Cross-principal IDOR :: MemoryCommandService', function (): void {

    test('non-owner member Y throws on createAgentMemory for X\'s personal agent', function (): void {
        $service = new MemoryCommandService();
        $f = createCrossPrincipalIdorFixture();

        expect(fn() => $service->createAgentMemory(
            $f['xOwnedAgentId'],
            $f['member']['userId'],
            $f['member']['principalId'],
            ['name' => 'should_not_persist', 'type' => 'context', 'content' => 'no'],
        ))->toThrow(Spora\Services\Exceptions\AgentNotFoundException::class);

        expect(Capsule::table('memories')->where('name', 'should_not_persist')->doesntExist())->toBeTrue();
    });

    test('non-owner member Y throws on updateAgentMemory for X\'s personal agent', function (): void {
        $service = new MemoryCommandService();
        $f = createCrossPrincipalIdorFixture();

        $memory = Memory::create([
            'principal_id' => $f['owner']['principalId'],
            'agent_id'     => $f['xOwnedAgentId'],
            'scope'        => 'agent',
            'type'         => 'context',
            'name'         => 'x_personal',
            'content'      => 'original',
            'order'        => 1,
        ]);

        expect(fn() => $service->updateAgentMemory(
            (string) $memory->id,
            $f['xOwnedAgentId'],
            $f['member']['userId'],
            $f['member']['principalId'],
            ['content' => 'hijacked'],
        ))->toThrow(Spora\Services\Exceptions\AgentNotFoundException::class);

        $memory->refresh();
        expect($memory->content)->toBe('original');
    });

    test('non-owner member Y throws on replaceAgentMemory for X\'s personal agent', function (): void {
        $service = new MemoryCommandService();
        $f = createCrossPrincipalIdorFixture();

        $memory = Memory::create([
            'principal_id' => $f['owner']['principalId'],
            'agent_id'     => $f['xOwnedAgentId'],
            'scope'        => 'agent',
            'type'         => 'documentation',
            'name'         => 'x_personal_replace',
            'content'      => 'before replacement',
            'order'        => 1,
        ]);

        expect(fn() => $service->replaceAgentMemory(
            (string) $memory->id,
            $f['xOwnedAgentId'],
            $f['member']['userId'],
            $f['member']['principalId'],
            ['find' => 'before', 'new_text' => 'AFTER'],
        ))->toThrow(Spora\Services\Exceptions\AgentNotFoundException::class);

        $memory->refresh();
        expect((string) $memory->content)->toBe('before replacement');
    });

    test('non-owner member Y throws on deleteAgentMemory for X\'s personal agent', function (): void {
        $service = new MemoryCommandService();
        $f = createCrossPrincipalIdorFixture();

        $memory = Memory::create([
            'principal_id' => $f['owner']['principalId'],
            'agent_id'     => $f['xOwnedAgentId'],
            'scope'        => 'agent',
            'type'         => 'context',
            'name'         => 'x_personal_to_delete',
            'order'        => 1,
        ]);

        expect(fn() => $service->deleteAgentMemory(
            (string) $memory->id,
            $f['xOwnedAgentId'],
            $f['member']['userId'],
            $f['member']['principalId'],
        ))->toThrow(Spora\Services\Exceptions\AgentNotFoundException::class);

        expect(Capsule::table('memories')->where('id', $memory->id)->exists())->toBeTrue();
    });

    test('non-owner member Y throws on reorderAgentMemories for X\'s personal agent', function (): void {
        $service = new MemoryCommandService();
        $f = createCrossPrincipalIdorFixture();

        expect(fn() => $service->reorderAgentMemories(
            $f['xOwnedAgentId'],
            $f['member']['userId'],
            $f['member']['principalId'],
            [],
        ))->toThrow(Spora\Services\Exceptions\AgentNotFoundException::class);
    });

    test('control: member Y CAN create on the group-owned agent', function (): void {
        $service = new MemoryCommandService();
        $f = createCrossPrincipalIdorFixture();

        $result = $service->createAgentMemory(
            $f['groupAgentId'],
            $f['member']['userId'],
            $f['member']['principalId'],
            ['name' => 'member_writes_to_group_agent', 'type' => 'context', 'content' => 'ok'],
        );
        expect($result['memory']['name'])->toBe('member_writes_to_group_agent');
    });
});
