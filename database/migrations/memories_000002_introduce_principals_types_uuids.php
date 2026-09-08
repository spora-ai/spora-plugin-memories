<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Migrations\Migration;
use Spora\Core\Database\MigrationHelpers;

/**
 * Switch memories to the principals model, add document types, and migrate
 * to UUIDv7 (CHAR(36)) primary keys.
 *
 * Drops `user_id` (ownership flows through `principal_id` post-0067) and
 * the `memories_agent_id_name` unique key (now anchored on `scope_key`).
 * Two new scalar columns (`scope`, `type`) plus a generated `scope_key`
 * collapse the five orthogonal uniqueness axes (scope, principal, agent,
 * type, name) into a single non-null VARCHAR primary candidate, since
 * MySQL treats NULL as distinct in unique indexes.
 *
 * Engine notes:
 *   - SQLite: rebuilds the table because the auto-increment `INTEGER PRIMARY KEY`
 *     cannot be altered in place to CHAR(36). Generated columns with
 *     `||`-style string concat are SQLite-3.31+ syntax.
 *   - MySQL: ALTER TABLE … ADD COLUMN … + ADD CONSTRAINT does the swap
 *     in a single statement.
 *
 * Idempotency: this migration is idempotent so re-runs against a partial
 * state succeed; the precheck refuses the migration if any legacy row has
 * a non-null `agent_id` (forward-only data assumption). Each DROP / ADD
 * step is guarded by the five `MigrationHelpers` trait methods
 * (`foreignKeyExists`, `indexExists`, `findForeignKeyOn`, `findIndexOn`,
 * `hasPrimaryKey`), which read from `information_schema` (MySQL/MariaDB)
 * or `PRAGMA` (SQLite). The schema-swap ALTER TABLE is split into
 * per-step statements so a re-run against a database that already has
 * some new columns / indexes / FKs skips cleanly. The trait lives at
 * `spora-core/app/Core/Database/MigrationHelpers.php` and is shared with
 * `0067_introduce_principals_and_groups` / `0073_add_principal_id_and_trigger_user_id_to_tasks`.
 */
return new class extends Migration
{
    use MigrationHelpers;

    public function up(): void
    {
        $agentRows = (int) Capsule::table('memories')->whereNotNull('agent_id')->count();
        if ($agentRows > 0) {
            throw new \RuntimeException(sprintf(
                'memories_000002 cannot run: %d agent-scoped row(s) present in the legacy `memories` table. ' .
                'Back up the agent rows, drop them, and rerun the migration. ' .
                'See the migration docblock for the forward-only cleanup assumption.',
                $agentRows,
            ));
        }

        $schema = Capsule::schema();
        $driver = Capsule::connection()->getDriverName();

        if ($driver === 'sqlite') {
            $this->upSqlite();
            return;
        }

        // MySQL / MariaDB path. Every DROP step is guarded because a
        // prior failed run (or a manual cleanup) may have left the table
        // missing the legacy FK / index / column — Laravel's
        // `$table->dropForeign(['user_id'])` infers the FK name from
        // `<table>_<col>_foreign` and bombs out with 1091 when that name
        // no longer exists.

        $userFk = $this->findForeignKeyOn('memories', 'user_id');
        if ($userFk !== null) {
            Capsule::statement("ALTER TABLE memories DROP FOREIGN KEY {$userFk}");
        }
        $userIdx = $this->findIndexOn('memories', 'user_id');
        if ($userIdx !== null) {
            Capsule::statement("ALTER TABLE memories DROP INDEX {$userIdx}");
        }
        if ($schema->hasColumn('memories', 'user_id')) {
            Capsule::statement('ALTER TABLE memories DROP COLUMN user_id');
        }

        if ($this->hasPrimaryKey('memories')) {
            Capsule::statement('ALTER TABLE memories DROP PRIMARY KEY');
        }
        if ($schema->hasColumn('memories', 'id')) {
            Capsule::statement('ALTER TABLE memories DROP COLUMN id');
        }
        if (!$schema->hasColumn('memories', 'id')) {
            Capsule::statement('ALTER TABLE memories ADD COLUMN id CHAR(36) NOT NULL FIRST');
        }

        // Schema swap — split into per-step statements so each is skipped
        // if the column / FK / index already exists from a partial prior
        // run. Column references in `scope_key` resolve only once
        // `scope`, `type`, `principal_id` exist, so add them in that
        // order.

        if (!$schema->hasColumn('memories', 'principal_id')) {
            Capsule::statement('ALTER TABLE memories ADD COLUMN principal_id BIGINT UNSIGNED NULL AFTER id');
        }
        if (!$schema->hasColumn('memories', 'scope')) {
            Capsule::statement("ALTER TABLE memories ADD COLUMN scope ENUM('global','agent') NOT NULL AFTER id");
        }
        if (!$schema->hasColumn('memories', 'type')) {
            Capsule::statement("ALTER TABLE memories ADD COLUMN type ENUM('plan','documentation','examples','context') NOT NULL DEFAULT 'context' AFTER scope");
        }
        if (!$schema->hasColumn('memories', 'scope_key')) {
            Capsule::statement(
                "ALTER TABLE memories ADD COLUMN scope_key VARCHAR(255) GENERATED ALWAYS AS ("
                . "CONCAT(scope, ':', COALESCE(principal_id, 0), ':', COALESCE(agent_id, 0), ':', type, ':', name)"
                . ') STORED'
            );
        }

        if (!$this->hasPrimaryKey('memories')) {
            Capsule::statement('ALTER TABLE memories ADD PRIMARY KEY (id)');
        }
        if (!$this->foreignKeyExists('memories', 'fk_memories_principal_id')) {
            Capsule::statement(
                'ALTER TABLE memories ADD CONSTRAINT fk_memories_principal_id '
                . 'FOREIGN KEY (principal_id) REFERENCES principals(id) ON DELETE CASCADE'
            );
        }
        if (!$this->indexExists('memories', 'uniq_memories_scope_key')) {
            Capsule::statement('ALTER TABLE memories ADD UNIQUE INDEX uniq_memories_scope_key (scope_key)');
        }
        if (!$this->indexExists('memories', 'idx_memories_principal_scope_type')) {
            Capsule::statement('ALTER TABLE memories ADD INDEX idx_memories_principal_scope_type (principal_id, scope, type)');
        }
        if (!$this->indexExists('memories', 'idx_memories_agent_scope_type_order')) {
            Capsule::statement('ALTER TABLE memories ADD INDEX idx_memories_agent_scope_type_order (agent_id, scope, type, `order`)');
        }

        if ($this->indexExists('memories', 'memories_agent_id_name')) {
            Capsule::statement('ALTER TABLE memories DROP INDEX memories_agent_id_name');
        }
    }

    private function upSqlite(): void
    {
        Capsule::statement('PRAGMA foreign_keys = OFF');

        Capsule::statement(<<<'SQL'
            CREATE TABLE memories_new (
                id CHAR(36) PRIMARY KEY NOT NULL,
                scope TEXT NOT NULL CHECK (scope IN ('global','agent')),
                type TEXT NOT NULL DEFAULT 'context' CHECK (type IN ('plan','documentation','examples','context')),
                principal_id INTEGER NULL,
                agent_id INTEGER NULL,
                name TEXT NOT NULL,
                summary VARCHAR(500) NULL,
                content TEXT NULL,
                "order" INTEGER NOT NULL DEFAULT 0,
                created_at TEXT NULL,
                updated_at TEXT NULL,
                scope_key TEXT GENERATED ALWAYS AS (
                    scope || ':' ||
                    COALESCE(principal_id, 0) || ':' ||
                    COALESCE(agent_id, 0) || ':' ||
                    type || ':' ||
                    name
                ) STORED
            )
        SQL);

        Capsule::statement(<<<'SQL'
            INSERT INTO memories_new (
                id, scope, type, agent_id, name, summary, content, "order", created_at, updated_at
            )
            SELECT
                lower(
                    hex(randomblob(4)) || '-' || hex(randomblob(2)) || '-4' ||
                    substr(hex(randomblob(2)), 2) || '-' ||
                    substr('89ab', 1 + (abs(random()) % 4), 1) ||
                    substr(hex(randomblob(2)), 2) || '-' ||
                    hex(randomblob(6))
                ) AS id,
                'global',
                'context',
                agent_id,
                name,
                summary,
                content,
                "order",
                created_at,
                updated_at
            FROM memories
        SQL);

        Capsule::statement('DROP TABLE memories');
        Capsule::statement('ALTER TABLE memories_new RENAME TO memories');

        Capsule::statement('CREATE UNIQUE INDEX uniq_memories_scope_key ON memories (scope_key)');
        Capsule::statement('CREATE INDEX idx_memories_principal_scope_type ON memories (principal_id, scope, type)');
        Capsule::statement('CREATE INDEX idx_memories_agent_scope_type_order ON memories (agent_id, scope, type, "order")');
        Capsule::statement('DROP INDEX IF EXISTS memories_agent_id_name');

        Capsule::statement('PRAGMA foreign_keys = ON');
    }

    public function down(): void
    {
        // Forward-only: rolling back would destroy the principal + type + UUID
        // semantics and require knowing the migration order. Operators who
        // need to roll back should restore from a backup taken before the
        // upgrade. Mirrors the policy in spora-core 0067.
    }
};
