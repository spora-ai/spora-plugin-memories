<?php

declare(strict_types=1);

use Spora\Auth\AuthService;
use Spora\Plugins\Memories\Http\AgentMemoryController;
use Spora\Plugins\Memories\Models\Memory;
use Spora\Plugins\Memories\Services\MemoryService;
use Spora\Services\PrincipalService;
use Symfony\Component\HttpFoundation\Response;

const AGENT_MEMORY_REORDER_PATH = '/api/v1/agents/1/memories/reorder';

function makeAgentMemoryController(?AuthService $authService = null): array
{
    $authService = $authService ?? bootAuthLayer();
    $memoryService = new MemoryService();
    $principals = new PrincipalService(new Spora\Services\PrincipalResolver());
    $controller = new AgentMemoryController($authService, $memoryService, $principals);

    return [$controller, $authService, $memoryService];
}

function createMemoryTestUserWithAgents(AuthService $authService, string $email = 'agentcontroller@example.com'): array
{
    static $seq = 0;
    $seq++;
    $displayName = ucfirst(explode('@', "{$seq}{$email}")[0]);
    $userId = $authService->register("{$seq}{$email}", 'Password1!', $displayName);
    simulateLoggedInSession($userId, "{$seq}{$email}");

    $agentId1 = createAgentWithPrincipal($userId, 'Agent One', ['max_steps' => 10]);
    $agentId2 = createAgentWithPrincipal($userId, 'Agent Two', ['max_steps' => 10]);
    $principalId = createUserPrincipal($userId);

    return [$userId, $agentId1, $agentId2, $principalId];
}

// reorder

describe('AgentMemoryController::reorder', function (): void {

    test('reorder() throws when unauthenticated', function (): void {
        [$controller] = makeAgentMemoryController();
        clearSession();

        $request = jsonRequest('PATCH', AGENT_MEMORY_REORDER_PATH, ['order' => []]);
        expect(fn() => $controller->reorder($request))
            ->toThrow(RuntimeException::class);
    });

    test('reorder() returns 400 for invalid JSON', function (): void {
        [$controller, $authService] = makeAgentMemoryController();
        $authService->register('reorder400@example.com', 'Password1!', 'Reorder 400');
        simulateLoggedInSession((int) Illuminate\Database\Capsule\Manager::table('users')->where('email', 'reorder400@example.com')->value('id'), 'reorder400@example.com');

        $request = Symfony\Component\HttpFoundation\Request::create(
            '/api/v1/agents/1/memories/reorder',
            'PATCH',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            'not json at all',
        );
        $response = $controller->reorder($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_BAD_REQUEST);
        $body = json_decode($response->getContent(), true);
        expect($body['error']['code'])->toBe('INVALID_JSON');
    });

    test('reorder() returns 422 when order is not an array', function (): void {
        [$controller, $authService] = makeAgentMemoryController();
        $authService->register('reorder422@example.com', 'Password1!', 'Reorder 422');
        $uid = (int) Illuminate\Database\Capsule\Manager::table('users')->where('email', 'reorder422@example.com')->value('id');
        simulateLoggedInSession($uid, 'reorder422@example.com');

        $request = jsonRequest('PATCH', '/api/v1/agents/1/memories/reorder', ['order' => 'not-an-array']);
        $response = $controller->reorder($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_UNPROCESSABLE_ENTITY);
        $body = json_decode($response->getContent(), true);
        expect($body['error']['code'])->toBe('VALIDATION_ERROR');
        expect($body['error']['message'])->toContain('order must be an array');
    });

    test('reorder() returns 404 when agent does not exist', function (): void {
        [$controller, $authService] = makeAgentMemoryController();
        $authService->register('reorder404@example.com', 'Password1!', 'Reorder 404');
        $uid = (int) Illuminate\Database\Capsule\Manager::table('users')->where('email', 'reorder404@example.com')->value('id');
        simulateLoggedInSession($uid, 'reorder404@example.com');

        $request = jsonRequest('PATCH', '/api/v1/agents/99999/memories/reorder', ['order' => []]);
        $response = $controller->reorder($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_NOT_FOUND);
    });

    test('reorder() returns 200 with empty array', function (): void {
        [$controller, $authService] = makeAgentMemoryController();
        [, $agentId] = createMemoryTestUserWithAgents($authService);

        $request = jsonRequest('PATCH', "/api/v1/agents/{$agentId}/memories/reorder", ['order' => []]);
        $request->attributes->set('agentId', $agentId);
        $response = $controller->reorder($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_OK);
        $body = json_decode($response->getContent(), true);
        expect($body['data']['success'])->toBeTrue();
    });

    test('reorder() reorders memories and persists to database', function (): void {
        [$controller, $authService] = makeAgentMemoryController();
        [, $agentId, , $principalId] = createMemoryTestUserWithAgents($authService);

        $m1 = Memory::create(['principal_id' => $principalId, 'agent_id' => $agentId, 'scope' => 'agent', 'type' => 'context', 'name' => 'first', 'order' => 1]);
        $m2 = Memory::create(['principal_id' => $principalId, 'agent_id' => $agentId, 'scope' => 'agent', 'type' => 'context', 'name' => 'second', 'order' => 2]);
        $m3 = Memory::create(['principal_id' => $principalId, 'agent_id' => $agentId, 'scope' => 'agent', 'type' => 'context', 'name' => 'third', 'order' => 3]);

        $request = jsonRequest('PATCH', "/api/v1/agents/{$agentId}/memories/reorder", [
            'order' => [$m3->id, $m1->id, $m2->id],
        ]);
        $request->attributes->set('agentId', $agentId);
        $response = $controller->reorder($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_OK);

        $m1->refresh();
        $m2->refresh();
        $m3->refresh();
        expect($m3->order)->toBe(1);
        expect($m1->order)->toBe(2);
        expect($m2->order)->toBe(3);
    });

    test('reorder() only affects the specified agent memories', function (): void {
        [$controller, $authService] = makeAgentMemoryController();
        [, $agentId1, $agentId2, $principalId] = createMemoryTestUserWithAgents($authService);

        $a1m1 = Memory::create(['principal_id' => $principalId, 'agent_id' => $agentId1, 'scope' => 'agent', 'type' => 'context', 'name' => 'a1_first', 'order' => 1]);
        $a1m2 = Memory::create(['principal_id' => $principalId, 'agent_id' => $agentId1, 'scope' => 'agent', 'type' => 'context', 'name' => 'a1_second', 'order' => 2]);
        $a2m1 = Memory::create(['principal_id' => $principalId, 'agent_id' => $agentId2, 'scope' => 'agent', 'type' => 'context', 'name' => 'a2_first', 'order' => 1]);

        $request = jsonRequest('PATCH', "/api/v1/agents/{$agentId1}/memories/reorder", [
            'order' => [$a1m2->id, $a1m1->id],
        ]);
        $request->attributes->set('agentId', $agentId1);
        $response = $controller->reorder($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_OK);

        $a1m1->refresh();
        $a1m2->refresh();
        expect($a1m2->order)->toBe(1);
        expect($a1m1->order)->toBe(2);

        $a2m1->refresh();
        expect($a2m1->order)->toBe(1);
    });

    test('reorder() ignores memories from other agents in the order array', function (): void {
        [$controller, $authService] = makeAgentMemoryController();
        [, $agentId1, $agentId2, $principalId] = createMemoryTestUserWithAgents($authService);

        $a1m = Memory::create(['principal_id' => $principalId, 'agent_id' => $agentId1, 'scope' => 'agent', 'type' => 'context', 'name' => 'a1', 'order' => 1]);
        $a2m = Memory::create(['principal_id' => $principalId, 'agent_id' => $agentId2, 'scope' => 'agent', 'type' => 'context', 'name' => 'a2', 'order' => 1]);

        $request = jsonRequest('PATCH', "/api/v1/agents/{$agentId1}/memories/reorder", [
            'order' => [$a2m->id, $a1m->id],
        ]);
        $request->attributes->set('agentId', $agentId1);
        $response = $controller->reorder($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_OK);

        $a1m->refresh();
        expect($a1m->order)->toBe(1);

        $a2m->refresh();
        expect($a2m->order)->toBe(1);
    });
});

// index

describe('AgentMemoryController::index', function (): void {

    test('index() throws when unauthenticated', function (): void {
        [$controller] = makeAgentMemoryController();
        clearSession();

        $request = new Symfony\Component\HttpFoundation\Request();
        $request->attributes->set('agentId', 1);
        expect(fn() => $controller->index($request))
            ->toThrow(RuntimeException::class);
    });

    test('index() returns 404 when agent does not exist', function (): void {
        [$controller, $authService] = makeAgentMemoryController();
        $authService->register('idx404@example.com', 'Password1!', 'Idx 404');
        $uid = (int) Illuminate\Database\Capsule\Manager::table('users')->where('email', 'idx404@example.com')->value('id');
        simulateLoggedInSession($uid, 'idx404@example.com');

        $request = new Symfony\Component\HttpFoundation\Request();
        $request->attributes->set('agentId', 99999);
        $response = $controller->index($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_NOT_FOUND);
    });

    test('index() returns memories for an agent', function (): void {
        [$controller, $authService] = makeAgentMemoryController();
        [, $agentId, , $principalId] = createMemoryTestUserWithAgents($authService);

        Memory::create(['principal_id' => $principalId, 'agent_id' => $agentId, 'scope' => 'agent', 'type' => 'context', 'name' => 'agent_memory']);

        $request = new Symfony\Component\HttpFoundation\Request();
        $request->attributes->set('agentId', $agentId);
        $response = $controller->index($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_OK);
        $body = json_decode($response->getContent(), true);
        expect($body['data']['memories'])->toHaveCount(1);
        expect($body['data']['memories'][0]['name'])->toBe('agent_memory');
    });
});

// store

describe('AgentMemoryController::store', function (): void {

    test('store() throws when unauthenticated', function (): void {
        [$controller] = makeAgentMemoryController();
        clearSession();

        $request = jsonRequest('POST', '/api/v1/agents/1/memories', ['name' => 'test', 'type' => 'context']);
        $request->attributes->set('agentId', 1);
        expect(fn() => $controller->store($request))
            ->toThrow(RuntimeException::class);
    });

    test('store() returns 404 when agent does not exist', function (): void {
        [$controller, $authService] = makeAgentMemoryController();
        $authService->register('store404@example.com', 'Password1!', 'Store 404');
        $uid = (int) Illuminate\Database\Capsule\Manager::table('users')->where('email', 'store404@example.com')->value('id');
        simulateLoggedInSession($uid, 'store404@example.com');

        $request = jsonRequest('POST', '/api/v1/agents/99999/memories', ['name' => 'test', 'type' => 'context']);
        $request->attributes->set('agentId', 99999);
        $response = $controller->store($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_NOT_FOUND);
    });

    test('store() creates an agent memory and auto-assigns order', function (): void {
        [$controller, $authService] = makeAgentMemoryController();
        [, $agentId] = createMemoryTestUserWithAgents($authService);

        $request = jsonRequest('POST', "/api/v1/agents/{$agentId}/memories", [
            'name'    => 'New Agent Memory',
            'type'    => 'context',
            'content' => 'Agent-specific content',
        ]);
        $request->attributes->set('agentId', $agentId);
        $response = $controller->store($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_CREATED);
        $body = json_decode($response->getContent(), true);
        expect($body['data']['memory']['name'])->toBe('New Agent Memory')
            ->and($body['data']['memory']['agent_id'])->toBe($agentId)
            ->and($body['data']['memory']['order'])->toBe(1);
    });

    test('store() returns 422 when name is empty', function (): void {
        [$controller, $authService] = makeAgentMemoryController();
        [, $agentId] = createMemoryTestUserWithAgents($authService);

        $request = jsonRequest('POST', "/api/v1/agents/{$agentId}/memories", ['name' => '', 'type' => 'context']);
        $request->attributes->set('agentId', $agentId);
        $response = $controller->store($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_UNPROCESSABLE_ENTITY);
    });

    test('store() returns 422 TYPE_NOT_ALLOWED on unknown type', function (): void {
        [$controller, $authService] = makeAgentMemoryController();
        [, $agentId] = createMemoryTestUserWithAgents($authService);

        $request = jsonRequest('POST', "/api/v1/agents/{$agentId}/memories", ['name' => 'X', 'type' => 'mystery']);
        $request->attributes->set('agentId', $agentId);
        $response = $controller->store($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_UNPROCESSABLE_ENTITY);
        $body = json_decode($response->getContent(), true);
        expect($body['error']['code'])->toBe(MemoryService::TYPE_NOT_ALLOWED_CODE);
    });
});

// show

describe('AgentMemoryController::show', function (): void {

    test('show() throws when unauthenticated', function (): void {
        [$controller] = makeAgentMemoryController();
        clearSession();

        $request = new Symfony\Component\HttpFoundation\Request();
        $request->attributes->set('agentId', 1);
        $request->attributes->set('memoryId', 'abc');
        expect(fn() => $controller->show($request))
            ->toThrow(RuntimeException::class);
    });

    test('show() returns 404 when agent does not exist', function (): void {
        [$controller, $authService] = makeAgentMemoryController();
        $authService->register('show404@example.com', 'Password1!', 'Show 404');
        $uid = (int) Illuminate\Database\Capsule\Manager::table('users')->where('email', 'show404@example.com')->value('id');
        simulateLoggedInSession($uid, 'show404@example.com');

        $request = new Symfony\Component\HttpFoundation\Request();
        $request->attributes->set('agentId', 99999);
        $request->attributes->set('memoryId', 'abc');
        $response = $controller->show($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_NOT_FOUND);
    });
});

// update

describe('AgentMemoryController::update', function (): void {

    test('update() throws when unauthenticated', function (): void {
        [$controller] = makeAgentMemoryController();
        clearSession();

        $request = jsonRequest('PUT', '/api/v1/agents/1/memories/1', ['name' => 'updated', 'type' => 'context']);
        $request->attributes->set('agentId', 1);
        $request->attributes->set('memoryId', 'abc');
        expect(fn() => $controller->update($request))
            ->toThrow(RuntimeException::class);
    });

    test('update() modifies an existing agent memory', function (): void {
        [$controller, $authService] = makeAgentMemoryController();
        [, $agentId, , $principalId] = createMemoryTestUserWithAgents($authService);

        $memory = Memory::create([
            'principal_id' => $principalId,
            'agent_id'     => $agentId,
            'scope'        => 'agent',
            'type'         => 'context',
            'name'         => 'original',
            'content'      => 'original content',
        ]);

        $request = jsonRequest('PUT', "/api/v1/agents/{$agentId}/memories/{$memory->id}", [
            'name'    => 'updated',
            'type'    => 'examples',
            'content' => 'new content',
        ]);
        $request->attributes->set('agentId', $agentId);
        $request->attributes->set('memoryId', $memory->id);
        $response = $controller->update($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_OK);
        $body = json_decode($response->getContent(), true);
        expect($body['data']['memory']['name'])->toBe('updated')
            ->and($body['data']['memory']['type'])->toBe('examples')
            ->and($body['data']['memory']['content'])->toBe('new content');
    });
});

// destroy

describe('AgentMemoryController::destroy', function (): void {

    test('destroy() throws when unauthenticated', function (): void {
        [$controller] = makeAgentMemoryController();
        clearSession();

        $request = new Symfony\Component\HttpFoundation\Request();
        $request->attributes->set('agentId', 1);
        $request->attributes->set('memoryId', 'abc');
        expect(fn() => $controller->destroy($request))
            ->toThrow(RuntimeException::class);
    });

    test('destroy() deletes an existing agent memory', function (): void {
        [$controller, $authService] = makeAgentMemoryController();
        [, $agentId, , $principalId] = createMemoryTestUserWithAgents($authService);

        $memory = Memory::create([
            'principal_id' => $principalId,
            'agent_id'     => $agentId,
            'scope'        => 'agent',
            'type'         => 'context',
            'name'         => 'to_delete',
        ]);

        $request = new Symfony\Component\HttpFoundation\Request();
        $request->attributes->set('agentId', $agentId);
        $request->attributes->set('memoryId', $memory->id);
        $response = $controller->destroy($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_OK);
        expect(Memory::find($memory->id))->toBeNull();
    });
});

// replace

describe('AgentMemoryController::replace', function (): void {

    test('replace() throws when unauthenticated', function (): void {
        [$controller] = makeAgentMemoryController();
        clearSession();

        $request = jsonRequest('POST', '/api/v1/agents/1/memories/abc/replace', ['name' => 'X', 'type' => 'context', 'find' => 'a', 'new_text' => 'b']);
        $request->attributes->set('agentId', 1);
        $request->attributes->set('memoryId', 'abc');
        expect(fn() => $controller->replace($request))
            ->toThrow(RuntimeException::class);
    });

    test('replace() returns 200 on a unique-substring replace', function (): void {
        [$controller, $authService] = makeAgentMemoryController();
        [, $agentId, , $principalId] = createMemoryTestUserWithAgents($authService);

        $memory = Memory::create([
            'principal_id' => $principalId,
            'agent_id'     => $agentId,
            'scope'        => 'agent',
            'type'         => 'documentation',
            'name'         => 'sprint',
            'content'      => 'TODO: ship auth, write tests',
        ]);

        $request = jsonRequest('POST', "/api/v1/agents/{$agentId}/memories/{$memory->id}/replace", [
            'name' => 'sprint', 'type' => 'documentation',
            'find' => 'write tests', 'new_text' => 'write tests (done)',
        ]);
        $request->attributes->set('agentId', $agentId);
        $request->attributes->set('memoryId', $memory->id);
        $response = $controller->replace($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_OK);
        $body = json_decode($response->getContent(), true);
        expect($body['data']['memory']['content'])->toContain('write tests (done)');
    });
});
