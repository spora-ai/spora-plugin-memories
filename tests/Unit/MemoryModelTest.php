<?php

declare(strict_types=1);

use Spora\Plugins\Memories\Models\Memory;
use Spora\Plugins\Memories\Services\MemoryService;

if (!function_exists('makePrincipalAndAgent')) {
    /**
     * Build a fresh principal+agent pair. Returns `[userId, agentId, principalId]`.
     * Local helper for MemoryModelTest — parallel mode splits test files into
     * separate processes, so global helpers from sibling test files are not
     * available here.
     */
    function makePrincipalAndAgent(string $email = 'model@example.com'): array
    {
        static $seq = 0;
        $seq++;
        $authService = bootAuthLayer();
        $userId = bootAuth($authService, "{$seq}{$email}", 'Password1!');
        $agentId = createAgentWithPrincipal($userId, 'Model Agent', ['max_steps' => 5]);
        $principalId = createUserPrincipal($userId);

        return [$userId, $agentId, $principalId];
    }
}

it('uses CHAR(36) string UUIDs (not auto-increment)', function (): void {
    $model = new Memory();

    expect($model->incrementing)->toBeFalse()
        ->and($model->getKeyType())->toBe('string');
});

it('mints UUIDv7 ids from the HasUuids trait override', function (): void {
    $model = new Memory();
    $uuid = $model->newUniqueId();

    // UUIDv7 format: xxxxxxxx-xxxx-7xxx-yxxx-xxxxxxxxxxxx where y is variant (8/9/a/b)
    expect($uuid)->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/');

    // Two consecutive ids should be monotonically time-ordered (UUIDv7
    // encodes the timestamp in the leading bits).
    $next = $model->newUniqueId();
    expect(strcmp($uuid, $next))->toBeLessThanOrEqual(0);
});

it('declares scope and type as string casts and the right fillable list', function (): void {
    $casts = (new Memory())->getCasts();
    expect($casts)->toHaveKey('scope')
        ->and($casts['scope'])->toBe('string')
        ->and($casts)->toHaveKey('type')
        ->and($casts['type'])->toBe('string');

    $fillable = (new Memory())->getFillable();
    expect($fillable)->toContain('id', 'principal_id', 'agent_id', 'scope', 'type', 'name', 'summary', 'content', 'order');
    expect($fillable)->not->toContain('user_id');
});

it('forPrincipal scope filters by principal_id and scope=global', function (): void {
    [, , $principalId] = makePrincipalAndAgent();
    $otherPrincipalId = $principalId + 999;

    Memory::create([
        'principal_id' => $principalId,
        'scope'        => 'global',
        'type'         => 'context',
        'name'         => 'mine',
    ]);
    Memory::create([
        'principal_id' => $otherPrincipalId,
        'scope'        => 'global',
        'type'         => 'context',
        'name'         => 'not_mine',
    ]);

    $rows = Memory::forPrincipal($principalId)->get();
    expect($rows->pluck('name')->all())->toBe(['mine']);
});

it('forAgent scope filters by agent_id and scope=agent', function (): void {
    [, $agentId, $principalId] = makePrincipalAndAgent();
    $otherAgentId = $agentId + 999;

    Memory::create([
        'principal_id' => $principalId,
        'agent_id'     => $agentId,
        'scope'        => 'agent',
        'type'         => 'context',
        'name'         => 'agent_mine',
    ]);
    Memory::create([
        'principal_id' => $principalId,
        'agent_id'     => $otherAgentId,
        'scope'        => 'agent',
        'type'         => 'context',
        'name'         => 'agent_other',
    ]);

    $rows = Memory::forAgent($agentId)->get();
    expect($rows->pluck('name')->all())->toBe(['agent_mine']);
});

it('ofType scope filters by the type column', function (): void {
    [, , $principalId] = makePrincipalAndAgent();

    Memory::create(['principal_id' => $principalId, 'scope' => 'global', 'type' => 'plan', 'name' => 'p1']);
    Memory::create(['principal_id' => $principalId, 'scope' => 'global', 'type' => 'context', 'name' => 'c1']);

    $plans = Memory::forPrincipal($principalId)->ofType('plan')->get();
    expect($plans->pluck('name')->all())->toBe(['p1']);
});

it('generates a distinct UUIDv7 per insert', function (): void {
    [, , $principalId] = makePrincipalAndAgent();

    $ids = [];
    for ($i = 0; $i < 5; $i++) {
        $memory = Memory::create([
            'principal_id' => $principalId,
            'scope'        => 'global',
            'type'         => 'context',
            'name'         => 'row_' . $i,
        ]);
        $ids[] = (string) $memory->id;
    }

    expect(count(array_unique($ids)))->toBe(5);
});

it('exposes document types via MemoryService::DOCUMENT_TYPES', function (): void {
    expect(MemoryService::DOCUMENT_TYPES)->toBe(['plan', 'documentation', 'examples', 'context']);
});
