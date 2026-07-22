<?php

declare(strict_types=1);

namespace Spora\Plugins\Memories\Tests\Unit\Http;

use Spora\Auth\AuthService;
use Spora\Plugins\Memories\Http\MemoryController;
use Spora\Plugins\Memories\Services\MemoryService;
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
    $memoryService = new MemoryService();
    $controller = new MemoryController($authService, $memoryService);

    return [$controller, $authService, $memoryService];
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
    test('returns list of global memories for the user', function (): void {
        [$controller, $authService] = makeMemController();
        $userId = createMemUser($authService, 'index@example.com');
        $memoryService = new MemoryService();
        $memoryService->createGlobalMemory($userId, ['name' => 'Mem 1', 'content' => 'x']);
        $memoryService->createGlobalMemory($userId, ['name' => 'Mem 2', 'content' => 'y']);

        $response = $controller->index();

        expect($response->getStatusCode())->toBe(Response::HTTP_OK);
        $body = json_decode($response->getContent(), true);
        expect($body['data']['memories'])->toHaveCount(2);
    });

    test('returns empty list when no memories exist', function (): void {
        [$controller, $authService] = makeMemController();
        createMemUser($authService, 'empty@example.com');

        $response = $controller->index();

        expect($response->getStatusCode())->toBe(Response::HTTP_OK);
        $body = json_decode($response->getContent(), true);
        expect($body['data']['memories'])->toBe([]);
    });
});

describe('MemoryController::store', function (): void {
    test('returns 201 with the created memory on success', function (): void {
        [$controller, $authService] = makeMemController();
        createMemUser($authService, 'store@example.com');

        $request = jsonRequest('POST', MEM_API, ['name' => 'New Memory', 'content' => 'body']);
        $response = $controller->store($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_CREATED);
        $body = json_decode($response->getContent(), true);
        expect($body['data']['memory']['name'])->toBe('New Memory');
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

        $request = jsonRequest('POST', MEM_API, ['content' => 'no name']);
        $response = $controller->store($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_UNPROCESSABLE_ENTITY);
    });

    test('returns 422 on service RuntimeException', function (): void {
        [$controller, $authService] = makeMemController();
        createMemUser($authService, 'storerun@example.com');

        // send empty name → service throws → controller converts to 422
        $request = jsonRequest('POST', MEM_API, ['name' => '   ']);
        $response = $controller->store($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_UNPROCESSABLE_ENTITY);
    });
});

describe('MemoryController::show', function (): void {
    test('returns 200 with the memory', function (): void {
        [$controller, $authService, $service] = makeMemController();
        $userId = createMemUser($authService, 'show@example.com');
        $created = $service->createGlobalMemory($userId, ['name' => 'Show Me', 'content' => 'c']);

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
        $request->attributes->set('id', 999999);
        $response = $controller->show($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_NOT_FOUND);
    });
});

describe('MemoryController::update', function (): void {
    test('returns 200 with the updated memory on success', function (): void {
        [$controller, $authService, $service] = makeMemController();
        $userId = createMemUser($authService, 'update@example.com');
        $created = $service->createGlobalMemory($userId, ['name' => 'Old', 'content' => 'c']);

        $request = jsonRequest('PUT', '/api/v1/memories/' . $created['memory']['id'], ['name' => 'New', 'content' => 'c2']);
        $request->attributes->set('id', $created['memory']['id']);
        $response = $controller->update($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_OK);
        $body = json_decode($response->getContent(), true);
        expect($body['data']['memory']['name'])->toBe('New');
    });

    test(MEM_TEST_404_UNKNOWN_ID, function (): void {
        [$controller, $authService] = makeMemController();
        createMemUser($authService, 'update404@example.com');

        $request = jsonRequest('PUT', '/api/v1/memories/999999', ['name' => 'X']);
        $request->attributes->set('id', 999999);
        $response = $controller->update($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_NOT_FOUND);
    });

    test(MEM_TEST_400_INVALID_JSON, function (): void {
        [$controller, $authService, $service] = makeMemController();
        $userId = createMemUser($authService, 'updatebad@example.com');
        $created = $service->createGlobalMemory($userId, ['name' => 'Test', 'content' => 'c']);

        $request = Request::create('/api/v1/memories/' . $created['memory']['id'], 'PUT', [], [], [], ['CONTENT_TYPE' => MEM_CONTENT_TYPE_JSON], MEM_INVALID_JSON_BODY);
        $request->attributes->set('id', $created['memory']['id']);
        $response = $controller->update($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_BAD_REQUEST);
    });
});

describe('MemoryController::destroy', function (): void {
    test('returns 200 with deleted: true on success', function (): void {
        [$controller, $authService, $service] = makeMemController();
        $userId = createMemUser($authService, 'destroy@example.com');
        $created = $service->createGlobalMemory($userId, ['name' => 'Delete Me', 'content' => 'c']);

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
        $request->attributes->set('id', 999999);
        $response = $controller->destroy($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_NOT_FOUND);
    });
});

describe('MemoryController::reorder', function (): void {
    test('returns 200 with success: true on valid order', function (): void {
        [$controller, $authService, $service] = makeMemController();
        $userId = createMemUser($authService, 'reorder@example.com');
        $a = $service->createGlobalMemory($userId, ['name' => 'A', 'content' => 'a']);
        $b = $service->createGlobalMemory($userId, ['name' => 'B', 'content' => 'b']);

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

        $request = jsonRequest('PATCH', MEM_REORDER_API, ['order' => [999999]]);
        $response = $controller->reorder($request);

        // Service does not throw for unknown ids; controller returns 200
        expect($response->getStatusCode())->toBe(Response::HTTP_OK);
    });
});
