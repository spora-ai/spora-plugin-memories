<?php

declare(strict_types=1);

namespace Spora\Plugins\Memories\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Ramsey\Uuid\Uuid;
use Spora\Models\Agent;
use Spora\Models\Principal;

/**
 * Eloquent model for the `memories` table.
 *
 * Lives at `Spora\Plugins\Memories\Models\Memory` — the plugin's PSR-4
 * namespace — so the autoloader can find it via the standard
 * `Spora\Plugins\Memories\` => `src/` mapping. The {@see Agent} and
 * {@see Principal} FK targets stay in core's `Spora\Models\` namespace
 * (that's where the Eloquent relationship resolves), and the plugin's
 * {@see \Spora\Plugins\Memories\MemoriesPlugin::migrationsPath()} ships the
 * table's create statement with the plugin-slug-prefixed filename.
 *
 * The HasUuids trait is overridden so we mint UUIDv7 (time-ordered) rather
 * than the trait's default UUIDv4 — v7 keeps newly-created memories
 * adjacent in B-tree indexes and in API listing order, which matters for
 * the editorial workflows the plugin now supports (plan / documentation /
 * examples / context).
 *
 * @property string $id
 * @property int|null $principal_id
 * @property int|null $agent_id
 * @property string $scope 'global' | 'agent'
 * @property string $type 'plan' | 'documentation' | 'examples' | 'context'
 * @property string $name
 * @property string|null $summary
 * @property string|null $content
 * @property int $order
 * @property string|null $scope_key Generated non-null uniqueness key.
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 * @property Agent|null $agent
 * @property Principal|null $principal
 */
final class Memory extends Model
{
    use HasUuids;

    protected $table = 'memories';

    public $incrementing = false;

    protected $keyType = 'string';

    /** @var list<string> */
    protected $fillable = [
        'id',
        'principal_id',
        'agent_id',
        'scope',
        'type',
        'name',
        'summary',
        'content',
        'order',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'scope'        => 'string',
        'type'         => 'string',
        'order'        => 'integer',
        'principal_id' => 'integer',
        'agent_id'     => 'integer',
    ];

    /**
     * UUIDv7 (time-ordered) instead of the trait's default v4.
     * Returned to HasUuids via its internal `newUniqueId()` resolution.
     */
    public function newUniqueId(): string
    {
        return Uuid::uuid7()->toString();
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    public function principal(): BelongsTo
    {
        return $this->belongsTo(Principal::class);
    }

    /**
     * @param Builder<Memory> $query
     */
    public function scopeForPrincipal(Builder $query, int $principalId): Builder
    {
        return $query->where('principal_id', $principalId)->where('scope', 'global');
    }

    /**
     * @param Builder<Memory> $query
     */
    public function scopeForAgent(Builder $query, int $agentId): Builder
    {
        return $query->where('agent_id', $agentId)->where('scope', 'agent');
    }

    /**
     * @param Builder<Memory> $query
     */
    public function scopeOfType(Builder $query, string $type): Builder
    {
        return $query->where('type', $type);
    }
}
