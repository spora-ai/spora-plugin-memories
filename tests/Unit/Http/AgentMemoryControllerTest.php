<?php

declare(strict_types=1);

namespace Spora\Plugins\Memories\Tests\Unit\Http;

use Spora\Auth\AuthService;
use Spora\Plugins\Memories\Http\AgentMemoryController;
use Spora\Plugins\Memories\Services\MemoryService;
use Spora\Services\PrincipalService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

const AGENT_MEM_CONTENT_TYPE_JSON = 'application/json';
const AGENT_MEM_INVALID_JSON_BODY = 'not json';
const AGENT_MEM_TEST_400_INVALID_JSON = 'returns 400 on invalid JSON';
const AGENT_MEM_TEST_404_UNKNOWN_MEMORY = 'returns 404 for unknown memory';

function makeAgentMemController(?AuthService $authService = null): array
{
    $authService = $authService ?? bootAuthLayer();
    $memoryService = new MemoryService();
    $principals = new PrincipalService(new \Spora\Services\PrincipalResolver());
    $controller = new AgentMemoryController($authService, $memoryService, $principals);

    return [$controller, $authService, $memoryService, $principals];
}

function createAgentMemUser(AuthService $authService, string $email): array
{
    static $seq = 0;
    $seq++;
    $unique = "{$seq}{$email}";
    $userId = $authService->register($unique, 'Password1!', 'User');
    simulateLoggedInSession($userId, $unique);

    $agentId = createAgentWithPrincipal($userId, 'Test Agent', ['max_steps' => 10]);
    $principalId = createUserPrincipal($userId);

    return [$userId, $agentId, $principalId];
}

describe('AgentMemoryController::index', function (): void {
    test('returns 200 with memories for an existing agent', function (): void {
        [$controller, $authService, $service] = makeAgentMemController();
        [, $agentId, $principalId] = createAgentMemUser($authService, 'index@example.com');
        $service->createAgentMemory($agentId, $principalId, ['name' => 'M1', 'type' => 'context', 'content' => 'x']);

        $request = new Request();
        $request->attributes->set('agentId', $agentId);
        $response = $controller->index($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_OK);
        $body = json_decode($response->getContent(), true);
        expect($body['data']['memories'])->toHaveCount(1);
    });

    test('returns 404 for unknown agent', function (): void {
        [$controller, $authService] = makeAgentMemController();
        createAgentMemUser($authService, 'idx404@example.com');

        $request = new Request();
        $request->attributes->set('agentId', 999999);
        $response = $controller->index($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_NOT_FOUND);
    });

    test('forwards the ?type filter to the service', function (): void {
        [$controller, $authService, $service] = makeAgentMemController();
        [, $agentId, $principalId] = createAgentMemUser($authService, 'indextype@example.com');
        $service->createAgentMemory($agentId, $principalId, ['name' => 'plan_one', 'type' => 'plan', 'content' => 'p']);
        $service->createAgentMemory($agentId, $principalId, ['name' => 'ctx_one', 'type' => 'context', 'content' => 'c']);

        $request = Request::create('/api/v1/agents/' . $agentId . '/memories', 'GET', ['type' => 'plan']);
        $request->attributes->set('agentId', $agentId);
        $response = $controller->index($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_OK);
        $body = json_decode($response->getContent(), true);
        expect($body['data']['memories'])->toHaveCount(1)
            ->and($body['data']['memories'][0]['name'])->toBe('plan_one');
    });
});

describe('AgentMemoryController::store', function (): void {
    test('returns 201 with the created memory', function (): void {
        [$controller, $authService] = makeAgentMemController();
        [, $agentId] = createAgentMemUser($authService, 'store@example.com');

        $request = jsonRequest('POST', "/api/v1/agents/{$agentId}/memories", ['name' => 'New', 'type' => 'context', 'content' => 'c']);
        $request->attributes->set('agentId', $agentId);
        $response = $controller->store($request);
        expect($response->getStatusCode())->toBe(Response::HTTP_CREATED);
    });

    test(AGENT_MEM_TEST_400_INVALID_JSON, function (): void {
        [$controller, $authService] = makeAgentMemController();
        [, $agentId] = createAgentMemUser($authService, 'store400@example.com');

        $request = Request::create("/api/v1/agents/{$agentId}/memories", 'POST', [], [], [], ['CONTENT_TYPE' => AGENT_MEM_CONTENT_TYPE_JSON], AGENT_MEM_INVALID_JSON_BODY);
        $request->attributes->set('agentId', $agentId);
        $response = $controller->store($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_BAD_REQUEST);
    });

    test('returns 422 when name is empty', function (): void {
        [$controller, $authService] = makeAgentMemController();
        [, $agentId] = createAgentMemUser($authService, 'store422@example.com');

        $request = jsonRequest('POST', "/api/v1/agents/{$agentId}/memories", ['name' => '', 'type' => 'context']);
        $request->attributes->set('agentId', $agentId);
        $response = $controller->store($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_UNPROCESSABLE_ENTITY);
    });

    test('returns 422 when type is missing', function (): void {
        [$controller, $authService] = makeAgentMemController();
        [, $agentId] = createAgentMemUser($authService, 'storetype@example.com');

        $request = jsonRequest('POST', "/api/v1/agents/{$agentId}/memories", ['name' => 'X']);
        $request->attributes->set('agentId', $agentId);
        $response = $controller->store($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_UNPROCESSABLE_ENTITY);
    });

    test('returns 422 TYPE_NOT_ALLOWED when type is unknown', function (): void {
        [$controller, $authService] = makeAgentMemController();
        [, $agentId] = createAgentMemUser($authService, 'storebadtype@example.com');

        $request = jsonRequest('POST', "/api/v1/agents/{$agentId}/memories", ['name' => 'X', 'type' => 'mystery']);
        $request->attributes->set('agentId', $agentId);
        $response = $controller->store($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_UNPROCESSABLE_ENTITY);
        $body = json_decode($response->getContent(), true);
        expect($body['error']['code'])->toBe(MemoryService::TYPE_NOT_ALLOWED_CODE);
    });

    test('returns 404 when agent does not exist', function (): void {
        [$controller, $authService] = makeAgentMemController();
        createAgentMemUser($authService, 'store404@example.com');

        $request = jsonRequest('POST', '/api/v1/agents/999999/memories', ['name' => 'X', 'type' => 'context']);
        $request->attributes->set('agentId', 999999);
        $response = $controller->store($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_NOT_FOUND);
    });
});

describe('AgentMemoryController::show', function (): void {
    test('returns 200 with the memory', function (): void {
        [$controller, $authService, $service] = makeAgentMemController();
        [, $agentId, $principalId] = createAgentMemUser($authService, 'show@example.com');
        $created = $service->createAgentMemory($agentId, $principalId, ['name' => 'M', 'type' => 'context', 'content' => 'c']);

        $request = new Request();
        $request->attributes->set('agentId', $agentId);
        $request->attributes->set('memoryId', $created['memory']['id']);
        $response = $controller->show($request);
        expect($response->getStatusCode())->toBe(Response::HTTP_OK);
    });

    test(AGENT_MEM_TEST_404_UNKNOWN_MEMORY, function (): void {
        [$controller, $authService] = makeAgentMemController();
        [, $agentId] = createAgentMemUser($authService, 'show404@example.com');

        $request = new Request();
        $request->attributes->set('agentId', $agentId);
        $request->attributes->set('memoryId', '00000000-0000-4000-8000-000000000000');
        $response = $controller->show($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_NOT_FOUND);
    });
});

describe('AgentMemoryController::update', function (): void {
    test('returns 200 with the updated memory', function (): void {
        [$controller, $authService, $service] = makeAgentMemController();
        [, $agentId, $principalId] = createAgentMemUser($authService, 'update@example.com');
        $created = $service->createAgentMemory($agentId, $principalId, ['name' => 'Old', 'type' => 'context', 'content' => 'c']);

        $request = jsonRequest('PUT', "/api/v1/agents/{$agentId}/memories/{$created['memory']['id']}", ['name' => 'New', 'type' => 'context', 'content' => 'c2']);
        $request->attributes->set('agentId', $agentId);
        $request->attributes->set('memoryId', $created['memory']['id']);
        $response = $controller->update($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_OK);
        $body = json_decode($response->getContent(), true);
        expect($body['data']['memory']['name'])->toBe('New');
    });

    test(AGENT_MEM_TEST_404_UNKNOWN_MEMORY, function (): void {
        [$controller, $authService] = makeAgentMemController();
        [, $agentId] = createAgentMemUser($authService, 'update404@example.com');

        $request = jsonRequest('PUT', "/api/v1/agents/{$agentId}/memories/00000000-0000-4000-8000-000000000000", ['name' => 'X', 'type' => 'context']);
        $request->attributes->set('agentId', $agentId);
        $request->attributes->set('memoryId', '00000000-0000-4000-8000-000000000000');
        $response = $controller->update($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_NOT_FOUND);
    });

    test(AGENT_MEM_TEST_400_INVALID_JSON, function (): void {
        [$controller, $authService] = makeAgentMemController();
        [, $agentId] = createAgentMemUser($authService, 'updatebad@example.com');

        $request = Request::create("/api/v1/agents/{$agentId}/memories/abc", 'PUT', [], [], [], ['CONTENT_TYPE' => AGENT_MEM_CONTENT_TYPE_JSON], AGENT_MEM_INVALID_JSON_BODY);
        $request->attributes->set('agentId', $agentId);
        $request->attributes->set('memoryId', 'abc');
        $response = $controller->update($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_BAD_REQUEST);
    });
});

describe('AgentMemoryController::replace', function (): void {
    test('returns 200 on a unique-substring replace', function (): void {
        [$controller, $authService, $service] = makeAgentMemController();
        [, $agentId, $principalId] = createAgentMemUser($authService, 'replace@example.com');
        $created = $service->createAgentMemory($agentId, $principalId, ['name' => 'sprint', 'type' => 'plan', 'content' => 'TODO: ship auth, write tests']);

        $request = jsonRequest('POST', "/api/v1/agents/{$agentId}/memories/{$created['memory']['id']}/replace", [
            'name' => 'sprint', 'type' => 'plan',
            'find' => 'write tests', 'new_text' => 'write tests (done)',
        ]);
        $request->attributes->set('agentId', $agentId);
        $request->attributes->set('memoryId', $created['memory']['id']);
        $response = $controller->replace($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_OK);
        $body = json_decode($response->getContent(), true);
        expect($body['data']['memory']['content'])->toContain('write tests (done)');
    });

    test('returns 422 REPLACE_NOT_UNIQUE on multiple matches', function (): void {
        [$controller, $authService, $service] = makeAgentMemController();
        [, $agentId, $principalId] = createAgentMemUser($authService, 'replaceamb@example.com');
        $created = $service->createAgentMemory($agentId, $principalId, ['name' => 'sprint', 'type' => 'plan', 'content' => 'foo foo foo']);

        $request = jsonRequest('POST', "/api/v1/agents/{$agentId}/memories/{$created['memory']['id']}/replace", [
            'name' => 'sprint', 'type' => 'plan',
            'find' => 'foo', 'new_text' => 'bar',
        ]);
        $request->attributes->set('agentId', $agentId);
        $request->attributes->set('memoryId', $created['memory']['id']);
        $response = $controller->replace($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_UNPROCESSABLE_ENTITY);
        $body = json_decode($response->getContent(), true);
        expect($body['error']['code'])->toBe(MemoryService::REPLACE_NOT_UNIQUE_CODE);
    });

    test('returns 404 REPLACE_NOT_FOUND on missing memory', function (): void {
        [$controller, $authService] = makeAgentMemController();
        [, $agentId] = createAgentMemUser($authService, 'replace404@example.com');

        $request = jsonRequest('POST', "/api/v1/agents/{$agentId}/memories/00000000-0000-4000-8000-000000000000/replace", [
            'name' => 'X', 'type' => 'context', 'find' => 'a', 'new_text' => 'b',
        ]);
        $request->attributes->set('agentId', $agentId);
        $request->attributes->set('memoryId', '00000000-0000-4000-8000-000000000000');
        $response = $controller->replace($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_NOT_FOUND);
        $body = json_decode($response->getContent(), true);
        expect($body['error']['code'])->toBe(MemoryService::REPLACE_NOT_FOUND_CODE);
    });

    test('returns 422 TYPE_NOT_ALLOWED on unknown type', function (): void {
        [$controller, $authService] = makeAgentMemController();
        [, $agentId] = createAgentMemUser($authService, 'replacebadtype@example.com');

        $request = jsonRequest('POST', "/api/v1/agents/{$agentId}/memories/abc/replace", [
            'name' => 'X', 'type' => 'mystery', 'find' => 'a', 'new_text' => 'b',
        ]);
        $request->attributes->set('agentId', $agentId);
        $request->attributes->set('memoryId', 'abc');
        $response = $controller->replace($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_UNPROCESSABLE_ENTITY);
        $body = json_decode($response->getContent(), true);
        expect($body['error']['code'])->toBe(MemoryService::TYPE_NOT_ALLOWED_CODE);
    });
});

describe('AgentMemoryController::destroy', function (): void {
    test('returns 200 with deleted: true on success', function (): void {
        [$controller, $authService, $service] = makeAgentMemController();
        [, $agentId, $principalId] = createAgentMemUser($authService, 'destroy@example.com');
        $created = $service->createAgentMemory($agentId, $principalId, ['name' => 'X', 'type' => 'context', 'content' => 'c']);

        $request = new Request();
        $request->attributes->set('agentId', $agentId);
        $request->attributes->set('memoryId', $created['memory']['id']);
        $response = $controller->destroy($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_OK);
        $body = json_decode($response->getContent(), true);
        expect($body['data']['deleted'])->toBeTrue();
    });

    test(AGENT_MEM_TEST_404_UNKNOWN_MEMORY, function (): void {
        [$controller, $authService] = makeAgentMemController();
        [, $agentId] = createAgentMemUser($authService, 'destroy404@example.com');

        $request = new Request();
        $request->attributes->set('agentId', $agentId);
        $request->attributes->set('memoryId', '00000000-0000-4000-8000-000000000000');
        $response = $controller->destroy($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_NOT_FOUND);
    });
});

describe('AgentMemoryController::reorder', function (): void {
    test('returns 200 with success: true on valid order', function (): void {
        [$controller, $authService, $service] = makeAgentMemController();
        [, $agentId, $principalId] = createAgentMemUser($authService, 'reorder@example.com');
        $a = $service->createAgentMemory($agentId, $principalId, ['name' => 'A', 'type' => 'context', 'content' => 'a']);
        $b = $service->createAgentMemory($agentId, $principalId, ['name' => 'B', 'type' => 'context', 'content' => 'b']);

        $request = jsonRequest('PATCH', "/api/v1/agents/{$agentId}/memories/reorder", ['order' => [$b['memory']['id'], $a['memory']['id']]]);
        $request->attributes->set('agentId', $agentId);
        $response = $controller->reorder($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_OK);
    });

    test(AGENT_MEM_TEST_400_INVALID_JSON, function (): void {
        [$controller, $authService] = makeAgentMemController();
        [, $agentId] = createAgentMemUser($authService, 'reorder400@example.com');

        $request = Request::create("/api/v1/agents/{$agentId}/memories/reorder", 'PATCH', [], [], [], ['CONTENT_TYPE' => AGENT_MEM_CONTENT_TYPE_JSON], AGENT_MEM_INVALID_JSON_BODY);
        $request->attributes->set('agentId', $agentId);
        $response = $controller->reorder($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_BAD_REQUEST);
    });

    test('returns 422 when order is not an array', function (): void {
        [$controller, $authService] = makeAgentMemController();
        [, $agentId] = createAgentMemUser($authService, 'reorder422@example.com');

        $request = jsonRequest('PATCH', "/api/v1/agents/{$agentId}/memories/reorder", ['order' => 'oops']);
        $request->attributes->set('agentId', $agentId);
        $response = $controller->reorder($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_UNPROCESSABLE_ENTITY);
    });

    test('returns 404 when agent does not exist', function (): void {
        [$controller, $authService] = makeAgentMemController();
        createAgentMemUser($authService, 'reorder404@example.com');

        $request = jsonRequest('PATCH', '/api/v1/agents/999999/memories/reorder', ['order' => []]);
        $request->attributes->set('agentId', 999999);
        $response = $controller->reorder($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_NOT_FOUND);
    });
});
