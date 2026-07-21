<?php

declare(strict_types=1);

namespace Spora\Plugins\Memories\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spora\Models\Agent;

/**
 * Eloquent model for the `memories` table.
 *
 * Lives at `Spora\Plugins\Memories\Models\Memory` — the plugin's PSR-4
 * namespace — so the autoloader can find it via the standard
 * `Spora\Plugins\Memories\` => `src/` mapping. The {@see Agent} FK target
 * stays in core's `Spora\Models\` namespace (that's where the Eloquent
 * relationship resolves), and the plugin's {@see \Spora\Plugins\Memories\MemoriesPlugin::migrationsPath()}
 * ships the table's create statement with the plugin-slug-prefixed filename.
 *
 * @property int $id
 * @property int|null $user_id
 * @property int|null $agent_id
 * @property string $name
 * @property string|null $summary
 * @property string|null $content
 * @property int $order
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 * @property Agent|null $agent
 */
final class Memory extends Model
{
    protected $table = 'memories';

    protected $fillable = [
        'user_id',
        'agent_id',
        'name',
        'summary',
        'content',
        'order',
    ];

    protected $casts = [
        'order' => 'integer',
    ];

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    /**
     * @param \Illuminate\Database\Eloquent\Builder<Memory> $query
     */
    public function scopeGlobal($query)
    {
        return $query->whereNull('agent_id');
    }

    /**
     * @param \Illuminate\Database\Eloquent\Builder<Memory> $query
     */
    public function scopeForAgent($query, int $agentId)
    {
        return $query->where('agent_id', $agentId);
    }
}
