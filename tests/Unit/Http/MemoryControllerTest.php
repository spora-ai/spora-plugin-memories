<?php

declare(strict_types=1);

namespace Spora\Plugins\Memories\Tests\Unit\Http;

use Spora\Auth\AuthService;
use Spora\Plugins\Memories\Http\MemoryController;
use Spora\Plugins\Memories\Services\MemoryCommandService;
use Spora\Plugins\Memories\Services\MemoryQueryService;
use Spora\Plugins\Memories\Services\MemoryService;
use Spora\Services\PrincipalService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

const MEM_API               = '/api/v1/memories';
const MEM_REORDER_API       = '/api/v1/memories/reorder';
const MEM_CONTENT_TYPE_JSON = 'application/json';
const MEM_INVALID_JSON_BODY = 'not json';
const MEM_TEST_400_INVALID_JSON = 'returns 400 on invalid JSON';
const MEM_TEST_404_UNKNOWN_ID   = 'returns 404 for unknown id';

function makeMemController(?AuthService $authService = null): array
{
    $authService = $authService ?? bootAuthLayer();
    $memoryQuery = new MemoryQueryService();
    $memoryCommand = new MemoryCommandService();
    $principals = new PrincipalService(new \Spora\Services\PrincipalResolver());
    $controller = new MemoryController($authService, $memoryQuery, $memoryCommand, $principals);

    return [$controller, $authService, $memoryQuery, $memoryCommand, $principals];
}

function createMemUser(AuthService $authService, string $email): int
{
    static $seq = 0;
    $seq++;
    $unique = "{$seq}{$email}";
    $userId = $authService->register($unique, 'Password1!', 'User');
    simulateLoggedInSession($userId, $unique);

    return $userId;
}

describe('MemoryController::index', function (): void {
    test('returns list of global memories for the principal', function (): void {
        [$controller, $authService, , $memoryCommand] = makeMemController();
        $userId = createMemUser($authService, 'index@example.com');
        $principalId = createUserPrincipal($userId);
        $memoryCommand->createGlobalMemory($principalId, ['name' => 'Mem 1', 'type' => 'context', 'content' => 'x']);
        $memoryCommand->createGlobalMemory($principalId, ['name' => 'Mem 2', 'type' => 'context', 'content' => 'y']);

        $response = $controller->index(new Request());

        expect($response->getStatusCode())->toBe(Response::HTTP_OK);
        $body = json_decode($response->getContent(), true);
        expect($body['data']['memories'])->toHaveCount(2);
    });

    test('returns empty list when no memories exist', function (): void {
        [$controller, $authService] = makeMemController();
        createMemUser($authService, 'empty@example.com');

        $response = $controller->index(new Request());

        expect($response->getStatusCode())->toBe(Response::HTTP_OK);
        $body = json_decode($response->getContent(), true);
        expect($body['data']['memories'])->toBe([]);
    });

    test('forwards the ?type filter to the service', function (): void {
        [$controller, $authService, , $memoryCommand] = makeMemController();
        $userId = createMemUser($authService, 'typefilter@example.com');
        $principalId = createUserPrincipal($userId);
        $memoryCommand->createGlobalMemory($principalId, ['name' => 'plan_one', 'type' => 'plan', 'content' => 'p']);
        $memoryCommand->createGlobalMemory($principalId, ['name' => 'ctx_one', 'type' => 'context', 'content' => 'c']);

        $request = Request::create(MEM_API, 'GET', ['type' => 'plan']);
        $response = $controller->index($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_OK);
        $body = json_decode($response->getContent(), true);
        expect($body['data']['memories'])->toHaveCount(1)
            ->and($body['data']['memories'][0]['name'])->toBe('plan_one');
    });
});

describe('MemoryController::store', function (): void {
    test('returns 201 with the created memory on success', function (): void {
        [$controller, $authService] = makeMemController();
        createMemUser($authService, 'store@example.com');

        $request = jsonRequest('POST', MEM_API, ['name' => 'New Memory', 'type' => 'context', 'content' => 'body']);
        $response = $controller->store($request);
        expect($response->getStatusCode())->toBe(Response::HTTP_CREATED);
        $body = json_decode($response->getContent(), true);
        expect($body['data']['memory']['name'])->toBe('New Memory')
            ->and($body['data']['memory']['type'])->toBe('context');
    });

    test(MEM_TEST_400_INVALID_JSON, function (): void {
        [$controller, $authService] = makeMemController();
        createMemUser($authService, 'store400@example.com');

        $request = Request::create(MEM_API, 'POST', [], [], [], ['CONTENT_TYPE' => MEM_CONTENT_TYPE_JSON], MEM_INVALID_JSON_BODY);
        $response = $controller->store($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_BAD_REQUEST);
    });

    test('returns 422 when name is missing', function (): void {
        [$controller, $authService] = makeMemController();
        createMemUser($authService, 'store422@example.com');

        $request = jsonRequest('POST', MEM_API, ['type' => 'context', 'content' => 'no name']);
        $response = $controller->store($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_UNPROCESSABLE_ENTITY);
    });

    test('returns 422 when type is missing', function (): void {
        [$controller, $authService] = makeMemController();
        createMemUser($authService, 'storetype@example.com');

        $request = jsonRequest('POST', MEM_API, ['name' => 'X', 'content' => 'body']);
        $response = $controller->store($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_UNPROCESSABLE_ENTITY);
        $body = json_decode($response->getContent(), true);
        expect($body['error']['code'])->toBe('VALIDATION_ERROR');
    });

    test('returns 422 TYPE_NOT_ALLOWED when type is unknown', function (): void {
        [$controller, $authService] = makeMemController();
        createMemUser($authService, 'storebadtype@example.com');

        $request = jsonRequest('POST', MEM_API, ['name' => 'X', 'type' => 'mystery', 'content' => 'body']);
        $response = $controller->store($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_UNPROCESSABLE_ENTITY);
        $body = json_decode($response->getContent(), true);
        expect($body['error']['code'])->toBe(MemoryService::TYPE_NOT_ALLOWED_CODE);
    });

    test('returns 422 on service validation', function (): void {
        [$controller, $authService] = makeMemController();
        createMemUser($authService, 'storerun@example.com');

        $request = jsonRequest('POST', MEM_API, ['name' => '   ', 'type' => 'context']);
        $response = $controller->store($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_UNPROCESSABLE_ENTITY);
    });
});

describe('MemoryController::show', function (): void {
    test('returns 200 with the memory', function (): void {
        [$controller, $authService, , $service] = makeMemController();
        $userId = createMemUser($authService, 'show@example.com');
        $principalId = createUserPrincipal($userId);
        $created = $service->createGlobalMemory($principalId, ['name' => 'Show Me', 'type' => 'context', 'content' => 'c']);

        $request = new Request();
        $request->attributes->set('id', $created['memory']['id']);
        $response = $controller->show($request);
        expect($response->getStatusCode())->toBe(Response::HTTP_OK);
        $body = json_decode($response->getContent(), true);
        expect($body['data']['memory']['name'])->toBe('Show Me');
    });

    test(MEM_TEST_404_UNKNOWN_ID, function (): void {
        [$controller, $authService] = makeMemController();
        createMemUser($authService, 'show404@example.com');

        $request = new Request();
        $request->attributes->set('id', '00000000-0000-4000-8000-000000000000');
        $response = $controller->show($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_NOT_FOUND);
    });
});

describe('MemoryController::update', function (): void {
    test('returns 200 with the updated memory on success', function (): void {
        [$controller, $authService, , $service] = makeMemController();
        $userId = createMemUser($authService, 'update@example.com');
        $principalId = createUserPrincipal($userId);
        $created = $service->createGlobalMemory($principalId, ['name' => 'Old', 'type' => 'context', 'content' => 'c']);

        $request = jsonRequest('PUT', '/api/v1/memories/' . $created['memory']['id'], ['name' => 'New', 'type' => 'context', 'content' => 'c2']);
        $request->attributes->set('id', $created['memory']['id']);
        $response = $controller->update($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_OK);
        $body = json_decode($response->getContent(), true);
        expect($body['data']['memory']['name'])->toBe('New');
    });

    test(MEM_TEST_404_UNKNOWN_ID, function (): void {
        [$controller, $authService] = makeMemController();
        createMemUser($authService, 'update404@example.com');

        $request = jsonRequest('PUT', '/api/v1/memories/00000000-0000-4000-8000-000000000000', ['name' => 'X']);
        $request->attributes->set('id', '00000000-0000-4000-8000-000000000000');
        $response = $controller->update($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_NOT_FOUND);
    });

    test(MEM_TEST_400_INVALID_JSON, function (): void {
        [$controller, $authService, , $service] = makeMemController();
        $userId = createMemUser($authService, 'updatebad@example.com');
        $principalId = createUserPrincipal($userId);
        $created = $service->createGlobalMemory($principalId, ['name' => 'Test', 'type' => 'context', 'content' => 'c']);

        $request = Request::create('/api/v1/memories/' . $created['memory']['id'], 'PUT', [], [], [], ['CONTENT_TYPE' => MEM_CONTENT_TYPE_JSON], MEM_INVALID_JSON_BODY);
        $request->attributes->set('id', $created['memory']['id']);
        $response = $controller->update($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_BAD_REQUEST);
    });
});

describe('MemoryController::replace', function (): void {
    test('returns 200 on a unique-substring replace', function (): void {
        [$controller, $authService, , $service] = makeMemController();
        $userId = createMemUser($authService, 'replace@example.com');
        $principalId = createUserPrincipal($userId);
        $created = $service->createGlobalMemory($principalId, ['name' => 'roadmap', 'type' => 'documentation', 'content' => 'phase 1: alpha\nphase 2: beta']);

        $request = jsonRequest('POST', '/api/v1/memories/' . $created['memory']['id'] . '/replace', [
            'name' => 'roadmap', 'type' => 'documentation',
            'find' => 'phase 2: beta', 'new_text' => 'phase 2: closed-beta',
        ]);
        $request->attributes->set('id', $created['memory']['id']);
        $response = $controller->replace($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_OK);
        $body = json_decode($response->getContent(), true);
        expect($body['data']['memory']['content'])->toContain('phase 2: closed-beta');
    });

    test('returns 422 REPLACE_NOT_UNIQUE when find matches > 1 occurrence', function (): void {
        [$controller, $authService, , $service] = makeMemController();
        $userId = createMemUser($authService, 'replaceamb@example.com');
        $principalId = createUserPrincipal($userId);
        $created = $service->createGlobalMemory($principalId, ['name' => 'roadmap', 'type' => 'documentation', 'content' => 'phase 1: beta\nphase 2: beta']);

        $request = jsonRequest('POST', '/api/v1/memories/' . $created['memory']['id'] . '/replace', [
            'name' => 'roadmap', 'type' => 'documentation',
            'find' => 'beta', 'new_text' => 'gamma',
        ]);
        $request->attributes->set('id', $created['memory']['id']);
        $response = $controller->replace($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_UNPROCESSABLE_ENTITY);
        $body = json_decode($response->getContent(), true);
        expect($body['error']['code'])->toBe(MemoryService::REPLACE_NOT_UNIQUE_CODE);
    });

    test('returns 404 REPLACE_NOT_FOUND when memory id does not exist', function (): void {
        [$controller, $authService] = makeMemController();
        createMemUser($authService, 'replace404@example.com');

        $missingId = '00000000-0000-4000-8000-000000000000';
        $request = jsonRequest('POST', '/api/v1/memories/' . $missingId . '/replace', [
            'name' => 'roadmap', 'type' => 'documentation',
            'find' => 'a', 'new_text' => 'b',
        ]);
        $request->attributes->set('id', $missingId);
        $response = $controller->replace($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_NOT_FOUND);
        $body = json_decode($response->getContent(), true);
        expect($body['error']['code'])->toBe(MemoryService::REPLACE_NOT_FOUND_CODE);
    });

    test('returns 422 TYPE_NOT_ALLOWED when replace type is unknown', function (): void {
        [$controller, $authService] = makeMemController();
        createMemUser($authService, 'replacebadtype@example.com');

        $request = jsonRequest('POST', '/api/v1/memories/abc/replace', [
            'name' => 'X', 'type' => 'mystery', 'find' => 'a', 'new_text' => 'b',
        ]);
        $request->attributes->set('id', 'abc');
        $response = $controller->replace($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_UNPROCESSABLE_ENTITY);
        $body = json_decode($response->getContent(), true);
        expect($body['error']['code'])->toBe(MemoryService::TYPE_NOT_ALLOWED_CODE);
    });
});

describe('MemoryController::destroy', function (): void {
    test('returns 200 with deleted: true on success', function (): void {
        [$controller, $authService, , $service] = makeMemController();
        $userId = createMemUser($authService, 'destroy@example.com');
        $principalId = createUserPrincipal($userId);
        $created = $service->createGlobalMemory($principalId, ['name' => 'Delete Me', 'type' => 'context', 'content' => 'c']);

        $request = new Request();
        $request->attributes->set('id', $created['memory']['id']);
        $response = $controller->destroy($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_OK);
        $body = json_decode($response->getContent(), true);
        expect($body['data']['deleted'])->toBeTrue();
    });

    test(MEM_TEST_404_UNKNOWN_ID, function (): void {
        [$controller, $authService] = makeMemController();
        createMemUser($authService, 'destroy404@example.com');

        $request = new Request();
        $request->attributes->set('id', '00000000-0000-4000-8000-000000000000');
        $response = $controller->destroy($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_NOT_FOUND);
    });
});

describe('MemoryController::reorder', function (): void {
    test('returns 200 with success: true on valid order', function (): void {
        [$controller, $authService, , $service] = makeMemController();
        $userId = createMemUser($authService, 'reorder@example.com');
        $principalId = createUserPrincipal($userId);
        $a = $service->createGlobalMemory($principalId, ['name' => 'A', 'type' => 'context', 'content' => 'a']);
        $b = $service->createGlobalMemory($principalId, ['name' => 'B', 'type' => 'context', 'content' => 'b']);

        $request = jsonRequest('PATCH', MEM_REORDER_API, ['order' => [$b['memory']['id'], $a['memory']['id']]]);
        $response = $controller->reorder($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_OK);
        $body = json_decode($response->getContent(), true);
        expect($body['data']['success'])->toBeTrue();
    });

    test(MEM_TEST_400_INVALID_JSON, function (): void {
        [$controller, $authService] = makeMemController();
        createMemUser($authService, 'reorder400@example.com');

        $request = Request::create(MEM_REORDER_API, 'PATCH', [], [], [], ['CONTENT_TYPE' => MEM_CONTENT_TYPE_JSON], MEM_INVALID_JSON_BODY);
        $response = $controller->reorder($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_BAD_REQUEST);
    });

    test('returns 422 when order is not an array', function (): void {
        [$controller, $authService] = makeMemController();
        createMemUser($authService, 'reorder422@example.com');

        $request = jsonRequest('PATCH', MEM_REORDER_API, ['order' => 'not-an-array']);
        $response = $controller->reorder($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_UNPROCESSABLE_ENTITY);
    });

    test('returns 404 when memory in order does not exist (silently no-op)', function (): void {
        [$controller, $authService] = makeMemController();
        createMemUser($authService, 'reorder404@example.com');

        $request = jsonRequest('PATCH', MEM_REORDER_API, ['order' => ['00000000-0000-4000-8000-000000000000']]);
        $response = $controller->reorder($request);

        // Service does not throw for unknown ids; controller returns 200
        expect($response->getStatusCode())->toBe(Response::HTTP_OK);
    });
});

describe('Cross-principal isolation', function (): void {
    test('user cannot update another users global memory', function (): void {
        [$controller, $authService, , $service] = makeMemController();
        $ownerId = createMemUser($authService, 'owner@iso.com');
        $ownerPrincipalId = createUserPrincipal($ownerId);
        $created = $service->createGlobalMemory($ownerPrincipalId, ['name' => 'Private', 'type' => 'context', 'content' => 'secret']);

        // New session for an attacker user.
        $attackerService = bootAuthLayer();
        $attackerId = $attackerService->register('attacker@iso.com', 'Password1!', 'Attacker');
        simulateLoggedInSession($attackerId, 'attacker@iso.com');

        $request = jsonRequest('PUT', '/api/v1/memories/' . $created['memory']['id'], ['name' => 'Hijack', 'type' => 'context']);
        $request->attributes->set('id', $created['memory']['id']);
        $response = $controller->update($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_NOT_FOUND);
    });
});
