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
use Spora\Plugins\Memories\Tools\AgentMemoryTool;
use Spora\Plugins\Memories\Tools\GlobalMemoryTool;
use Spora\Services\PrincipalContext;
use Spora\Services\PrincipalService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Cross-layer principal-attribution agreement.
 *
 * The plugin has two parallel surfaces that read/write memories:
 *
 *   - Controllers (admin SPA, REST). They honour `?principal_id=N` on
 *     the query bag — wired by the principal-scope fix on PR-2.
 *   - Tools (`memory`, `global_memory`). They run inside an agent and
 *     read the principal from `PrincipalContext`, supplied by the host
 *     orchestrator when the agent starts.
 *
 * Both paths must agree on the same row: a memory written under
 * principal X (via the tool) is visible to the controller scoped to
 * X, and invisible everywhere else. Drift here would either leak
 * memories across principals or hide them from the operator.
 *
 * The existing `Principal isolation` unit suite in
 * `tests/Unit/Tools/MemoryToolTest.php` only exercises the tool path;
 * the existing `PrincipalScopeTest` feature suite only exercises the
 * controller path. This feature suite is the joining pin.
 */

function makeToolAgreementController(?AuthService $authService = null): array
{
    $authService  = $authService ?? bootAuthLayer();
    $memoryQuery  = new MemoryQueryService();
    $memoryCommand = new MemoryCommandService();
    $principals   = new PrincipalService(new Spora\Services\PrincipalResolver());

    return [
        new MemoryController($authService, $memoryQuery, $memoryCommand, $principals),
        new AgentMemoryController($authService, $memoryQuery, $memoryCommand, $principals),
        $authService,
    ];
}

function principalContextForPrincipal(int $principalId, ?int $ownerUserId = null, ?int $runnerUserId = null): PrincipalContext
{
    return new PrincipalContext(
        principalId: $principalId,
        type: 'user',
        ownerUserId: $ownerUserId ?? $principalId,
        runnerUserId: $runnerUserId ?? $principalId,
    );
}

function buildToolAgreementFixture(): array
{
    static $seq = 0;
    $seq++;

    $authService = bootAuthLayer();

    $ownerId    = bootAuth($authService, "{$seq}agree-owner@ps.local", 'Password1!', "Agree Owner {$seq}");
    $outsiderId = bootAuth($authService, "{$seq}agree-out@ps.local", 'Password1!', "Agree Out {$seq}");

    $svc = new PrincipalService(new Spora\Services\PrincipalResolver());

    $ownerPrincipal    = (int) $svc->ensureUserPrincipal($ownerId)->id;
    $outsiderPrincipal = (int) $svc->ensureUserPrincipal($outsiderId)->id;

    $groupId = (int) Capsule::table('groups')->insertGetId([
        'name'               => "Agree Group {$seq}",
        'description'        => "{$seq}-tool-controller-agreement",
        'created_by_user_id' => $ownerId,
        'created_at'         => date('Y-m-d H:i:s'),
        'updated_at'         => date('Y-m-d H:i:s'),
    ]);

    Capsule::table('group_memberships')->insert([
        'group_id'  => $groupId,
        'user_id'   => $ownerId,
        'role'      => GroupMembership::ROLE_OWNER,
        'joined_at' => date('Y-m-d H:i:s'),
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
    ]);

    $groupPrincipal = (int) $svc->ensureGroupPrincipal($groupId)->id;

    $agentId = (int) Capsule::table('agents')->insertGetId([
        'principal_id' => $groupPrincipal,
        'name'         => "Agree Group Agent {$seq}",
        'max_steps'    => 5,
        'is_active'    => true,
        'created_at'   => date('Y-m-d H:i:s'),
        'updated_at'   => date('Y-m-d H:i:s'),
    ]);

    return [
        'authService'    => $authService,
        'owner'          => ['userId' => $ownerId,    'principalId' => $ownerPrincipal],
        'outsider'       => ['userId' => $outsiderId, 'principalId' => $outsiderPrincipal],
        'groupId'        => $groupId,
        'groupPrincipal' => $groupPrincipal,
        'agentId'        => $agentId,
    ];
}

function simulateToolAgreementAs(int $userId): void
{
    $email = (string) Capsule::table('users')->where('id', $userId)->value('email');
    simulateLoggedInSession($userId, $email);
}

// ---------------------------------------------------------------------------
// Tool → Controller agreement: writes
// ---------------------------------------------------------------------------

describe('Tool/Controller agreement :: global memory', function (): void {

    test('tool save under group principal is visible to the controller scoped to that group', function (): void {
        [$global] = makeToolAgreementController();
        $f = buildToolAgreementFixture();

        // Tool side: agent runs with PrincipalContext bound to the group principal.
        $tool = new GlobalMemoryTool();
        $result = $tool->execute(
            [
                'action'  => 'save',
                'name'    => 'group-blueprint',
                'type'    => 'documentation',
                'content' => 'Owned by the group, written by the tool.',
            ],
            $f['agentId'],
            null,
            null,
            principalContextForPrincipal($f['groupPrincipal'], $f['owner']['userId']),
        );

        expect($result->success)->toBeTrue();
        $row = Memory::where('name', 'group-blueprint')->first();
        expect((int) $row->principal_id)->toBe($f['groupPrincipal']);

        // Controller side: same operator, scoped to the same group principal.
        simulateToolAgreementAs($f['owner']['userId']);
        $request = Request::create(
            "/api/v1/memories",
            'GET',
            ['principal_id' => $f['groupPrincipal']],
        );
        $response = $global->index($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_OK);
        $body = json_decode($response->getContent(), true);
        $names = array_column($body['data']['memories'], 'name');
        expect($names)->toContain('group-blueprint');
    });

    test('tool save under group is invisible to the controller scoped to the caller\'s user-principal', function (): void {
        [$global] = makeToolAgreementController();
        $f = buildToolAgreementFixture();

        $tool = new GlobalMemoryTool();
        $tool->execute(
            [
                'action'  => 'save',
                'name'    => 'group-only',
                'type'    => 'context',
                'content' => 'Group-only.',
            ],
            $f['agentId'],
            null,
            null,
            principalContextForPrincipal($f['groupPrincipal'], $f['owner']['userId']),
        );

        simulateToolAgreementAs($f['owner']['userId']);

        $response = $global->index(new Request());
        expect($response->getStatusCode())->toBe(Response::HTTP_OK);
        $body = json_decode($response->getContent(), true);
        $names = array_column($body['data']['memories'], 'name');
        expect($names)->not->toContain('group-only');
    });

    test('tool save under group is invisible to the controller when the caller is an outsider', function (): void {
        [$global] = makeToolAgreementController();
        $f = buildToolAgreementFixture();

        $tool = new GlobalMemoryTool();
        $tool->execute(
            [
                'action'  => 'save',
                'name'    => 'group-private',
                'type'    => 'context',
                'content' => 'Group-only, outsider must not see.',
            ],
            $f['agentId'],
            null,
            null,
            principalContextForPrincipal($f['groupPrincipal'], $f['owner']['userId']),
        );

        simulateToolAgreementAs($f['outsider']['userId']);

        $response = $global->index(new Request());
        $names = array_column(
            json_decode($response->getContent(), true)['data']['memories'],
            'name',
        );
        expect($names)->not->toContain('group-private');
    });
});

describe('Tool/Controller agreement :: agent memory', function (): void {

    // Agent-scope memories are intentionally keyed by `agent_id`, not by
    // `principal_id` — see the class doc on AbstractMemoryTool and the
    // rationale on MemoryCommandService::newMemory(). Agents that travel
    // between principals (0067 transfer) keep their memories because the
    // agent FK never changes. The cross-layer contract under test is
    // therefore *visibility to the agent*, not row-level principal
    // filtering: a memory written via the tool on a group-owned agent
    // must surface to the controller when the caller can see that agent,
    // and must be blocked (403, not 200-with-empty-list) when they can't.

    test('tool save on group-owned agent surfaces to the controller when the caller can see the agent', function (): void {
        [, $agentCtl] = makeToolAgreementController();
        $f = buildToolAgreementFixture();

        $tool = new AgentMemoryTool();
        $result = $tool->execute(
            [
                'action'  => 'save',
                'name'    => 'agent-handbook',
                'type'    => 'plan',
                'content' => 'How this agent should reason.',
            ],
            $f['agentId'],
            null,
            null,
            principalContextForPrincipal($f['groupPrincipal'], $f['owner']['userId']),
        );

        expect($result->success)->toBeTrue();
        $row = Memory::where('name', 'agent-handbook')->first();
        expect((int) $row->agent_id)->toBe($f['agentId']);

        simulateToolAgreementAs($f['owner']['userId']);
        $request = Request::create(
            "/api/v1/agents/{$f['agentId']}/memories",
            'GET',
            ['principal_id' => $f['groupPrincipal']],
        );
        $request->attributes->set('agentId', $f['agentId']);
        $response = $agentCtl->index($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_OK);
        $body = json_decode($response->getContent(), true);
        $names = array_column($body['data']['memories'], 'name');
        expect($names)->toContain('agent-handbook');
    });

    test('tool save on group-owned agent is invisible to an outsider scoped via the controller', function (): void {
        [, $agentCtl] = makeToolAgreementController();
        $f = buildToolAgreementFixture();

        $tool = new AgentMemoryTool();
        $tool->execute(
            [
                'action'  => 'save',
                'name'    => 'agent-private',
                'type'    => 'context',
                'content' => 'Group-only.',
            ],
            $f['agentId'],
            null,
            null,
            principalContextForPrincipal($f['groupPrincipal'], $f['owner']['userId']),
        );

        simulateToolAgreementAs($f['outsider']['userId']);
        $request = Request::create(
            "/api/v1/agents/{$f['agentId']}/memories",
            'GET',
            ['principal_id' => $f['groupPrincipal']],
        );
        $request->attributes->set('agentId', $f['agentId']);

        $response = $agentCtl->index($request);
        expect($response->getStatusCode())->toBe(Response::HTTP_FORBIDDEN);
        $body = json_decode($response->getContent(), true);
        expect($body['error']['code'])->toBe('FORBIDDEN');
    });
});

// ---------------------------------------------------------------------------
// Controller → Tool agreement: reads
// ---------------------------------------------------------------------------

describe('Tool/Controller agreement :: reads flow back', function (): void {

    test('memory created via controller under group principal is visible to the tool when it carries that principal', function (): void {
        [$global] = makeToolAgreementController();
        $f = buildToolAgreementFixture();

        simulateToolAgreementAs($f['owner']['userId']);
        $payload = Request::create(
            "/api/v1/memories?principal_id={$f['groupPrincipal']}",
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'name'    => 'controller-group-note',
                'type'    => 'documentation',
                'content' => 'Created via the controller.',
            ]),
        );
        $create = $global->store($payload);
        expect($create->getStatusCode())->toBe(Response::HTTP_CREATED);

        $tool = new GlobalMemoryTool();
        $toolList = $tool->execute(
            ['action' => 'list'],
            $f['agentId'],
            null,
            null,
            principalContextForPrincipal($f['groupPrincipal'], $f['owner']['userId']),
        );
        expect($toolList->content)->toContain('controller-group-note');

        $toolGet = $tool->execute(
            [
                'action' => 'get',
                'name'   => 'controller-group-note',
                'type'   => 'documentation',
            ],
            $f['agentId'],
            null,
            null,
            principalContextForPrincipal($f['groupPrincipal'], $f['owner']['userId']),
        );
        expect($toolGet->success)->toBeTrue()
            ->and($toolGet->content)->toContain('Created via the controller.');
    });
});
