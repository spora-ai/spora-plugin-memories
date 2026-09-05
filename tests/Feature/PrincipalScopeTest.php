<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Spora\Auth\AuthService;
use Spora\Models\GroupMembership;
use Spora\Plugins\Memories\Http\AgentMemoryController;
use Spora\Plugins\Memories\Http\MemoryController;
use Spora\Plugins\Memories\Models\Memory;
use Spora\Plugins\Memories\Services\MemoryCommandService;
use Spora\Plugins\Memories\Services\MemoryQueryService;
use Spora\Services\PrincipalService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Principal-scope feature coverage.
 *
 * The frontend's PrincipalChipRow lets the operator pick which
 * principal to view memories under (their own user-principal, or any
 * group-principal they belong to). The selection is sent as
 * `?principal_id=N` on every memory endpoint. These tests lock in the
 * three contracts the controllers must keep honouring:
 *
 *   - `?principal_id=` absent → resolves to the caller's user-principal
 *     so legacy callers (curl, scheduler workers) keep working.
 *   - `?principal_id=N` where the caller controls `N` (own user-principal
 *     or `admin`/`owner` of the underlying group) → uses `N`. The
 *     service-layer sees the requested principal and scopes reads/
 *     writes accordingly.
 *   - `?principal_id=N` where the caller does NOT control `N` → 403
 *     envelope. Forging a principal id is the attack surface the silent
 *     fallback hides; surfacing it keeps the security model auditable.
 */

const PRINCIPAL_SCOPE_LABEL = 'principal-scope';

function makePrincipalScopeController(?AuthService $authService = null): array
{
    $authService = $authService ?? bootAuthLayer();
    $memoryQuery = new MemoryQueryService();
    $memoryCommand = new MemoryCommandService();
    $principals = new PrincipalService(new Spora\Services\PrincipalResolver());

    return [
        new MemoryController($authService, $memoryQuery, $memoryCommand, $principals),
        new AgentMemoryController($authService, $memoryQuery, $memoryCommand, $principals),
        $authService,
    ];
}

/**
 * Build a 3-user / 1-group fixture and remember a single group-owned
 * agent for the agent-scoped tests.
 *
 * @return array{
 *     owner:          array{userId: int, principalId: int},
 *     member:         array{userId: int, principalId: int},
 *     outsider:       array{userId: int, principalId: int},
 *     groupId:        int,
 *     groupPrincipal: int,
 *     agentId:        int,
 * }
 */
function createPrincipalScopeFixture(AuthService $authService): array
{
    static $seq = 0;
    $seq++;

    $ownerId    = bootAuth($authService, "{$seq}scope-owner@ps.local", 'Password1!', "Scope Owner {$seq}");
    $memberId   = bootAuth($authService, "{$seq}scope-member@ps.local", 'Password1!', "Scope Member {$seq}");
    $outsiderId = bootAuth($authService, "{$seq}scope-out@ps.local", 'Password1!', "Scope Out {$seq}");

    $svc = new PrincipalService(new Spora\Services\PrincipalResolver());

    $ownerPrincipal    = (int) $svc->ensureUserPrincipal($ownerId)->id;
    $memberPrincipal   = (int) $svc->ensureUserPrincipal($memberId)->id;
    $outsiderPrincipal = (int) $svc->ensureUserPrincipal($outsiderId)->id;

    $groupId = (int) Capsule::table('groups')->insertGetId([
        'name'               => "Scope Group {$seq}",
        'description'        => "{$seq}-principal-scope",
        'created_by_user_id' => $ownerId,
        'created_at'         => date('Y-m-d H:i:s'),
        'updated_at'         => date('Y-m-d H:i:s'),
    ]);

    foreach ([
        [$groupId, $ownerId,  GroupMembership::ROLE_OWNER],
        [$groupId, $memberId, GroupMembership::ROLE_MEMBER],
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

    $groupPrincipal = (int) $svc->ensureGroupPrincipal($groupId)->id;

    $agentId = (int) Capsule::table('agents')->insertGetId([
        'principal_id' => $groupPrincipal,
        'name'         => "Scope Group Agent {$seq}",
        'max_steps'    => 5,
        'is_active'    => true,
        'created_at'   => date('Y-m-d H:i:s'),
        'updated_at'   => date('Y-m-d H:i:s'),
    ]);

    return [
        'owner'          => ['userId' => $ownerId,    'principalId' => $ownerPrincipal],
        'member'         => ['userId' => $memberId,   'principalId' => $memberPrincipal],
        'outsider'       => ['userId' => $outsiderId, 'principalId' => $outsiderPrincipal],
        'groupId'        => $groupId,
        'groupPrincipal' => $groupPrincipal,
        'agentId'        => $agentId,
    ];
}

function simulatePrincipalScopeAs(int $userId): void
{
    $email = (string) Capsule::table('users')->where('id', $userId)->value('email');
    simulateLoggedInSession($userId, $email);
}

// ---------------------------------------------------------------------------
// MemoryController — global memories
// ---------------------------------------------------------------------------

describe(PRINCIPAL_SCOPE_LABEL . ' :: MemoryController index', function (): void {

    test('default (no principal_id) resolves to caller user-principal', function (): void {
        [$global] = makePrincipalScopeController();
        $f = createPrincipalScopeFixture(bootAuthLayer());
        simulatePrincipalScopeAs($f['owner']['userId']);

        // Seed one memory under the owner's user-principal
        Memory::create([
            'principal_id' => $f['owner']['principalId'],
            'agent_id'     => null,
            'scope'        => 'global',
            'type'         => 'context',
            'name'         => 'owner-default',
            'order'        => 1,
        ]);

        $response = $global->index(new Request());

        expect($response->getStatusCode())->toBe(Response::HTTP_OK);
        $body = json_decode($response->getContent(), true);
        expect($body['data']['memories'])->toHaveCount(1);
        expect($body['data']['memories'][0]['name'])->toBe('owner-default');
    });

    test('?principal_id=group returns only that group\'s global memories', function (): void {
        [$global] = makePrincipalScopeController();
        $f = createPrincipalScopeFixture(bootAuthLayer());
        simulatePrincipalScopeAs($f['owner']['userId']);

        Memory::create([
            'principal_id' => $f['owner']['principalId'],
            'agent_id'     => null,
            'scope'        => 'global',
            'type'         => 'context',
            'name'         => 'owner-leak',
            'order'        => 1,
        ]);
        Memory::create([
            'principal_id' => $f['groupPrincipal'],
            'agent_id'     => null,
            'scope'        => 'global',
            'type'         => 'context',
            'name'         => 'group-shared',
            'order'        => 2,
        ]);

        $request = Request::create('/api/v1/memories', 'GET', ['principal_id' => $f['groupPrincipal']]);
        $response = $global->index($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_OK);
        $body = json_decode($response->getContent(), true);
        $names = array_column($body['data']['memories'], 'name');
        expect($names)->toContain('group-shared');
        expect($names)->not->toContain('owner-leak');
    });

    test('group member can scope the list with ?principal_id=group', function (): void {
        [$global] = makePrincipalScopeController();
        $f = createPrincipalScopeFixture(bootAuthLayer());

        Memory::create([
            'principal_id' => $f['groupPrincipal'],
            'agent_id'     => null,
            'scope'        => 'global',
            'type'         => 'documentation',
            'name'         => 'group-doc',
            'order'        => 1,
        ]);

        simulatePrincipalScopeAs($f['member']['userId']);
        $request = Request::create('/api/v1/memories', 'GET', ['principal_id' => $f['groupPrincipal']]);
        $response = $global->index($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_OK);
        $body = json_decode($response->getContent(), true);
        expect($body['data']['memories'])->toHaveCount(1);
        expect($body['data']['memories'][0]['name'])->toBe('group-doc');
    });

    test('outsider requesting a foreign principal gets 403', function (): void {
        [$global] = makePrincipalScopeController();
        $f = createPrincipalScopeFixture(bootAuthLayer());
        simulatePrincipalScopeAs($f['outsider']['userId']);

        $request = Request::create('/api/v1/memories', 'GET', ['principal_id' => $f['groupPrincipal']]);
        $response = $global->index($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_FORBIDDEN);
        $body = json_decode($response->getContent(), true);
        expect($body['error']['code'])->toBe('FORBIDDEN');
    });

    test('group member requesting their group principal gets a 200 even with no memories', function (): void {
        [$global] = makePrincipalScopeController();
        $f = createPrincipalScopeFixture(bootAuthLayer());
        simulatePrincipalScopeAs($f['member']['userId']);

        $request = Request::create('/api/v1/memories', 'GET', ['principal_id' => $f['groupPrincipal']]);
        $response = $global->index($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_OK);
        $body = json_decode($response->getContent(), true);
        expect($body['data']['memories'])->toBeArray()->toBeEmpty();
    });
});

describe(PRINCIPAL_SCOPE_LABEL . ' :: MemoryController store', function (): void {

    test('POST with ?principal_id=group persists under the group principal', function (): void {
        [$global] = makePrincipalScopeController();
        $f = createPrincipalScopeFixture(bootAuthLayer());
        simulatePrincipalScopeAs($f['owner']['userId']);

        $request = Request::create(
            "/api/v1/memories?principal_id={$f['groupPrincipal']}",
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['name' => 'group-doc', 'type' => 'context', 'content' => 'shared']),
        );

        $response = $global->store($request);
        expect($response->getStatusCode())->toBe(Response::HTTP_CREATED);

        $row = Capsule::table('memories')->where('name', 'group-doc')->first();
        expect((int) $row->principal_id)->toBe($f['groupPrincipal']);
        expect($row->scope)->toBe('global');
    });

    test('POST with foreign ?principal_id= returns 403 and writes nothing', function (): void {
        [$global] = makePrincipalScopeController();
        $f = createPrincipalScopeFixture(bootAuthLayer());
        simulatePrincipalScopeAs($f['outsider']['userId']);

        $request = Request::create(
            "/api/v1/memories?principal_id={$f['groupPrincipal']}",
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['name' => 'should-not-persist', 'type' => 'context']),
        );

        $response = $global->store($request);
        expect($response->getStatusCode())->toBe(Response::HTTP_FORBIDDEN);
        expect(Capsule::table('memories')->where('name', 'should-not-persist')->doesntExist())->toBeTrue();
    });
});

// ---------------------------------------------------------------------------
// AgentMemoryController — agent-scoped memories
// ---------------------------------------------------------------------------

describe(PRINCIPAL_SCOPE_LABEL . ' :: AgentMemoryController index', function (): void {

    test('group member can list agent memories with ?principal_id=group', function (): void {
        [, $agentCtl] = makePrincipalScopeController();
        $f = createPrincipalScopeFixture(bootAuthLayer());

        Memory::create([
            'principal_id' => $f['groupPrincipal'],
            'agent_id'     => $f['agentId'],
            'scope'        => 'agent',
            'type'         => 'context',
            'name'         => 'shared-by-owner',
            'order'        => 1,
        ]);

        simulatePrincipalScopeAs($f['member']['userId']);
        $request = Request::create(
            "/api/v1/agents/{$f['agentId']}/memories",
            'GET',
            ['principal_id' => $f['groupPrincipal']],
        );
        $request->attributes->set('agentId', $f['agentId']);
        $response = $agentCtl->index($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_OK);
        $body = json_decode($response->getContent(), true);
        expect($body['data']['memories'])->toHaveCount(1);
        expect($body['data']['memories'][0]['name'])->toBe('shared-by-owner');
    });

    test('outsider requesting foreign principal on agent endpoint gets 403', function (): void {
        [, $agentCtl] = makePrincipalScopeController();
        $f = createPrincipalScopeFixture(bootAuthLayer());
        simulatePrincipalScopeAs($f['outsider']['userId']);

        $request = Request::create(
            "/api/v1/agents/{$f['agentId']}/memories",
            'GET',
            ['principal_id' => $f['groupPrincipal']],
        );
        $request->attributes->set('agentId', $f['agentId']);
        $response = $agentCtl->index($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_FORBIDDEN);
    });
});
