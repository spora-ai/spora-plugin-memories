<?php

declare(strict_types=1);

namespace Spora\Plugins\Memories\Services;

use Spora\Plugins\Memories\Models\Memory;

/**
 * Maps a {@see Memory} Eloquent row to the JSON-friendly array used in
 * HTTP responses and tool results. Lives in its own helper so the
 * query and command services don't carry a duplicate 12-line resource
 * method that Sonar's duplication-density metric would catch.
 */
final class MemoryResource
{
    /**
     * @return array<string, mixed>
     */
    public static function toArray(Memory $memory): array
    {
        return [
            'id'           => (string) $memory->id,
            'principal_id' => $memory->principal_id !== null ? (int) $memory->principal_id : null,
            'agent_id'     => $memory->agent_id !== null ? (int) $memory->agent_id : null,
            'scope'        => (string) $memory->scope,
            'type'         => (string) $memory->type,
            'name'         => $memory->name,
            'summary'      => $memory->summary,
            'content'      => $memory->content,
            'order'        => (int) $memory->order,
            'created_at'   => $memory->created_at->toIso8601String(),
            'updated_at'   => $memory->updated_at->toIso8601String(),
        ];
    }
}
