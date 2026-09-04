<?php

declare(strict_types=1);

use Spora\Auth\AuthService;
use Spora\Plugins\Memories\Http\MemoryController;
use Spora\Plugins\Memories\Models\Memory;
use Spora\Plugins\Memories\Services\MemoryService;
use Spora\Services\PrincipalService;
use Symfony\Component\HttpFoundation\Response;

const REORDER_ENDPOINT = '/api/v1/memories/reorder';
const MEMORIES_ENDPOINT = '/api/v1/memories';

function makeMemoryController(?AuthService $authService = null): array
{
    $authService = $authService ?? bootAuthLayer();
    $memoryService = new MemoryService();
    $principals = new PrincipalService(new Spora\Services\PrincipalResolver());
    $controller = new MemoryController($authService, $memoryService, $principals);

    return [$controller, $authService, $memoryService];
}

function createMemoryTestUser(AuthService $authService, string $email = 'controller@example.com'): array
{
    static $seq = 0;
    $seq++;
    $displayName = ucfirst(explode('@', "{$seq}{$email}")[0]);
    $userId = $authService->register("{$seq}{$email}", 'Password1!', $displayName);
    simulateLoggedInSession($userId, "{$seq}{$email}");

    $agentId = createAgentWithPrincipal($userId, 'Test Agent', ['max_steps' => 10]);
    $principalId = createUserPrincipal($userId);

    return [$userId, $agentId, $principalId];
}

// reorder

describe('MemoryController::reorder', function (): void {

    test('reorder() throws when session is not set', function (): void {
        clearSession();
        [$controller] = makeMemoryController();

        expect(fn() => $controller->reorder(jsonRequest('PATCH', REORDER_ENDPOINT, ['order' => []])))
            ->toThrow(RuntimeException::class);
    });

    test('reorder() returns 400 for invalid JSON', function (): void {
        [$controller, $authService] = makeMemoryController();
        createMemoryTestUser($authService);

        $request = Symfony\Component\HttpFoundation\Request::create(
            REORDER_ENDPOINT,
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
        [$controller, $authService] = makeMemoryController();
        createMemoryTestUser($authService);

        $request = jsonRequest('PATCH', REORDER_ENDPOINT, ['order' => 'not-an-array']);
        $response = $controller->reorder($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_UNPROCESSABLE_ENTITY);
        $body = json_decode($response->getContent(), true);
        expect($body['error']['code'])->toBe('VALIDATION_ERROR');
        expect($body['error']['message'])->toContain('order must be an array');
    });

    test('reorder() returns 422 when order is a numeric string', function (): void {
        [$controller, $authService] = makeMemoryController();
        createMemoryTestUser($authService);

        $request = jsonRequest('PATCH', REORDER_ENDPOINT, ['order' => '123']);
        $response = $controller->reorder($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_UNPROCESSABLE_ENTITY);
    });

    test('reorder() returns 200 with empty array', function (): void {
        [$controller, $authService] = makeMemoryController();
        createMemoryTestUser($authService);

        $request = jsonRequest('PATCH', REORDER_ENDPOINT, ['order' => []]);
        $response = $controller->reorder($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_OK);
        $body = json_decode($response->getContent(), true);
        expect($body['data']['success'])->toBeTrue();
    });

    test('reorder() reorders memories and persists to database', function (): void {
        [$controller, $authService] = makeMemoryController();
        [, , $principalId] = createMemoryTestUser($authService);

        $m1 = Memory::create(['principal_id' => $principalId, 'scope' => 'global', 'type' => 'context', 'name' => 'first', 'order' => 1]);
        $m2 = Memory::create(['principal_id' => $principalId, 'scope' => 'global', 'type' => 'context', 'name' => 'second', 'order' => 2]);
        $m3 = Memory::create(['principal_id' => $principalId, 'scope' => 'global', 'type' => 'context', 'name' => 'third', 'order' => 3]);

        $request = jsonRequest('PATCH', REORDER_ENDPOINT, [
            'order' => [$m3->id, $m1->id, $m2->id],
        ]);
        $response = $controller->reorder($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_OK);

        $m1->refresh();
        $m2->refresh();
        $m3->refresh();
        expect($m3->order)->toBe(1);
        expect($m1->order)->toBe(2);
        expect($m2->order)->toBe(3);
    });

    test('reorder() only affects the current principal memories', function (): void {
        [$controllerA, $authServiceA] = makeMemoryController();
        [, , $principalIdA] = createMemoryTestUser($authServiceA, 'user1@reorder.com');
        [, , $principalIdB] = createMemoryTestUser(bootAuthLayer(), 'user2@reorder.com');

        $u1m = Memory::create(['principal_id' => $principalIdA, 'scope' => 'global', 'type' => 'context', 'name' => 'u1_memory', 'order' => 1]);
        Memory::create(['principal_id' => $principalIdB, 'scope' => 'global', 'type' => 'context', 'name' => 'u2_memory', 'order' => 1]);

        $request = jsonRequest('PATCH', REORDER_ENDPOINT, [
            'order' => [$u1m->id],
        ]);
        $controllerA->reorder($request);

        $u1m->refresh();
        expect($u1m->order)->toBe(1);
    });

    test('reorder() ignores extra fields in body', function (): void {
        [$controller, $authService] = makeMemoryController();
        [, , $principalId] = createMemoryTestUser($authService);

        $m1 = Memory::create(['principal_id' => $principalId, 'scope' => 'global', 'type' => 'context', 'name' => 'only', 'order' => 1]);

        $request = jsonRequest('PATCH', REORDER_ENDPOINT, [
            'order' => [$m1->id],
            'unknown_field' => 'should be ignored',
        ]);
        $response = $controller->reorder($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_OK);
    });
});

// index

describe('MemoryController::index', function (): void {

    test('index() throws when session is not set', function (): void {
        clearSession();
        [$controller] = makeMemoryController();

        expect(fn() => $controller->index(new Symfony\Component\HttpFoundation\Request()))
            ->toThrow(RuntimeException::class);
    });

    test('index() returns empty data when no memories', function (): void {
        [$controller, $authService] = makeMemoryController();
        createMemoryTestUser($authService);

        $request = new Symfony\Component\HttpFoundation\Request();
        $response = $controller->index($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_OK);
        $body = json_decode($response->getContent(), true);
        expect($body['data']['memories'])->toBeArray()
            ->and($body['data']['memories'])->toBeEmpty();
    });
});

// store

describe('MemoryController::store', function (): void {

    test('store() throws when session is not set', function (): void {
        clearSession();
        [$controller] = makeMemoryController();

        expect(fn() => $controller->store(jsonRequest('POST', MEMORIES_ENDPOINT, ['name' => 'test', 'type' => 'context'])))
            ->toThrow(RuntimeException::class);
    });

    test('store() creates a global memory and auto-assigns order', function (): void {
        [$controller, $authService] = makeMemoryController();
        createMemoryTestUser($authService);

        $request = jsonRequest('POST', MEMORIES_ENDPOINT, [
            'name'    => 'New Memory',
            'type'    => 'context',
            'summary' => 'A brief summary',
            'content' => 'Full content here',
        ]);
        $response = $controller->store($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_CREATED);
        $body = json_decode($response->getContent(), true);
        expect($body['data']['memory']['name'])->toBe('New Memory')
            ->and($body['data']['memory']['order'])->toBe(1)
            ->and($body['data']['memory']['agent_id'])->toBeNull()
            ->and($body['data']['memory']['type'])->toBe('context');
    });

    test('store() returns 422 when name is empty', function (): void {
        [$controller, $authService] = makeMemoryController();
        createMemoryTestUser($authService);

        $request = jsonRequest('POST', MEMORIES_ENDPOINT, ['name' => '', 'type' => 'context']);
        $response = $controller->store($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_UNPROCESSABLE_ENTITY);
        $body = json_decode($response->getContent(), true);
        expect($body['error']['code'])->toBe('VALIDATION_ERROR');
    });

    test('store() returns 422 TYPE_NOT_ALLOWED when type is unknown', function (): void {
        [$controller, $authService] = makeMemoryController();
        createMemoryTestUser($authService);

        $request = jsonRequest('POST', MEMORIES_ENDPOINT, ['name' => 'X', 'type' => 'mystery']);
        $response = $controller->store($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_UNPROCESSABLE_ENTITY);
        $body = json_decode($response->getContent(), true);
        expect($body['error']['code'])->toBe(MemoryService::TYPE_NOT_ALLOWED_CODE);
    });
});

// show

describe('MemoryController::show', function (): void {

    test('show() throws when session is not set', function (): void {
        clearSession();
        [$controller] = makeMemoryController();

        $request = new Symfony\Component\HttpFoundation\Request();
        $request->attributes->set('id', '00000000-0000-4000-8000-000000000000');
        expect(fn() => $controller->show($request))
            ->toThrow(RuntimeException::class);
    });

    test('show() returns 404 for unknown memory', function (): void {
        [$controller, $authService] = makeMemoryController();
        createMemoryTestUser($authService);

        $request = new Symfony\Component\HttpFoundation\Request();
        $request->attributes->set('id', '00000000-0000-4000-8000-000000000000');
        $response = $controller->show($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_NOT_FOUND);
    });
});

// update

describe('MemoryController::update', function (): void {

    test('update() throws when session is not set', function (): void {
        clearSession();
        [$controller] = makeMemoryController();

        expect(fn() => $controller->update(jsonRequest('PUT', '/api/v1/memories/abc', ['name' => 'updated', 'type' => 'context'])))
            ->toThrow(RuntimeException::class);
    });

    test('update() modifies an existing global memory', function (): void {
        [$controller, $authService] = makeMemoryController();
        [, , $principalId] = createMemoryTestUser($authService);

        $memory = Memory::create([
            'principal_id' => $principalId,
            'scope'        => 'global',
            'type'         => 'context',
            'name'         => 'original',
            'summary'      => 'original summary',
        ]);

        $request = jsonRequest('PUT', "/api/v1/memories/{$memory->id}", [
            'name'    => 'updated',
            'type'    => 'plan',
            'summary' => 'new summary',
        ]);
        $request->attributes->set('id', $memory->id);
        $response = $controller->update($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_OK);
        $body = json_decode($response->getContent(), true);
        expect($body['data']['memory']['name'])->toBe('updated')
            ->and($body['data']['memory']['type'])->toBe('plan')
            ->and($body['data']['memory']['summary'])->toBe('new summary');
    });
});

// destroy

describe('MemoryController::destroy', function (): void {

    test('destroy() throws when session is not set', function (): void {
        clearSession();
        [$controller] = makeMemoryController();

        $request = new Symfony\Component\HttpFoundation\Request();
        $request->attributes->set('id', 'abc');
        expect(fn() => $controller->destroy($request))
            ->toThrow(RuntimeException::class);
    });

    test('destroy() deletes an existing global memory', function (): void {
        [$controller, $authService] = makeMemoryController();
        [, , $principalId] = createMemoryTestUser($authService);

        $memory = Memory::create([
            'principal_id' => $principalId,
            'scope'        => 'global',
            'type'         => 'context',
            'name'         => 'to_delete',
        ]);

        $request = new Symfony\Component\HttpFoundation\Request();
        $request->attributes->set('id', $memory->id);
        $response = $controller->destroy($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_OK);
        expect(Memory::find($memory->id))->toBeNull();
    });
});

// replace

describe('MemoryController::replace', function (): void {

    test('replace() throws when session is not set', function (): void {
        clearSession();
        [$controller] = makeMemoryController();

        $request = jsonRequest('POST', '/api/v1/memories/abc/replace', ['name' => 'X', 'type' => 'context', 'find' => 'a', 'new_text' => 'b']);
        expect(fn() => $controller->replace($request))
            ->toThrow(RuntimeException::class);
    });

    test('replace() returns 200 on a unique-substring replace', function (): void {
        [$controller, $authService] = makeMemoryController();
        [, , $principalId] = createMemoryTestUser($authService);

        $memory = Memory::create([
            'principal_id' => $principalId,
            'scope'        => 'global',
            'type'         => 'documentation',
            'name'         => 'roadmap',
            'content'      => 'phase 1: alpha\nphase 2: beta',
        ]);

        $request = jsonRequest('POST', "/api/v1/memories/{$memory->id}/replace", [
            'name' => 'roadmap', 'type' => 'documentation',
            'find' => 'phase 2: beta', 'new_text' => 'phase 2: closed-beta',
        ]);
        $request->attributes->set('id', $memory->id);
        $response = $controller->replace($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_OK);
        $body = json_decode($response->getContent(), true);
        expect($body['data']['memory']['content'])->toContain('phase 2: closed-beta');
    });

    test('replace() returns 422 REPLACE_NOT_UNIQUE on multiple matches', function (): void {
        [$controller, $authService] = makeMemoryController();
        [, , $principalId] = createMemoryTestUser($authService);

        $memory = Memory::create([
            'principal_id' => $principalId,
            'scope'        => 'global',
            'type'         => 'documentation',
            'name'         => 'roadmap',
            'content'      => 'foo foo foo',
        ]);

        $request = jsonRequest('POST', "/api/v1/memories/{$memory->id}/replace", [
            'name' => 'roadmap', 'type' => 'documentation',
            'find' => 'foo', 'new_text' => 'bar',
        ]);
        $request->attributes->set('id', $memory->id);
        $response = $controller->replace($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_UNPROCESSABLE_ENTITY);
        $body = json_decode($response->getContent(), true);
        expect($body['error']['code'])->toBe(MemoryService::REPLACE_NOT_UNIQUE_CODE);
    });

    test('replace() returns 404 REPLACE_NOT_FOUND for missing memory', function (): void {
        [$controller, $authService] = makeMemoryController();
        createMemoryTestUser($authService);

        $missingId = '00000000-0000-4000-8000-000000000000';
        $request = jsonRequest('POST', "/api/v1/memories/{$missingId}/replace", [
            'name' => 'X', 'type' => 'context', 'find' => 'a', 'new_text' => 'b',
        ]);
        $request->attributes->set('id', $missingId);
        $response = $controller->replace($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_NOT_FOUND);
        $body = json_decode($response->getContent(), true);
        expect($body['error']['code'])->toBe(MemoryService::REPLACE_NOT_FOUND_CODE);
    });
});

// cross-principal isolation

describe('Cross-principal isolation', function (): void {

    test('user A cannot view user B global memory', function (): void {
        [$controller, $authService] = makeMemoryController();
        [$ownerUserId] = createMemoryTestUser($authService, 'owner@cross.com');
        $ownerPrincipalId = createUserPrincipal($ownerUserId);

        $created = Memory::create([
            'principal_id' => $ownerPrincipalId,
            'scope'        => 'global',
            'type'         => 'context',
            'name'         => 'private',
        ]);

        // New session as the attacker
        $attackerService = bootAuthLayer();
        $attackerId = $attackerService->register('attacker@cross.com', 'Password1!', 'Atk');
        simulateLoggedInSession($attackerId, 'attacker@cross.com');

        $request = new Symfony\Component\HttpFoundation\Request();
        $request->attributes->set('id', $created->id);
        $response = $controller->show($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_NOT_FOUND);
    });
});
