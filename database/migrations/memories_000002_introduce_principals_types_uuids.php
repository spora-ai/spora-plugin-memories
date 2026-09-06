<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

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
 * `Idempotency`: this migration assumes the operator either has a fresh
 * install or has explicitly cleaned the `memories` table — the user
 * confirmed there are no live installations to migrate.
 */
return new class extends Migration
{
    public function up(): void
    {
        $schema = Capsule::schema();
        $driver = Capsule::connection()->getDriverName();

        if ($driver === 'sqlite') {
            $this->upSqlite();
            return;
        }

        // MySQL path
        $schema->table('memories', static function (Blueprint $table): void {
            $table->dropForeign(['user_id']);
            $table->dropIndex(['user_id', 'name']);
            $table->dropColumn('user_id');
            $table->dropPrimary('id');
            $table->dropColumn('id');
            $table->char('id', 36)->first();
        });

        Capsule::statement(<<<'SQL'
            ALTER TABLE memories
                ADD COLUMN scope ENUM('global','agent') NOT NULL AFTER id,
                ADD COLUMN type  ENUM('plan','documentation','examples','context') NOT NULL DEFAULT 'context' AFTER scope,
                ADD COLUMN principal_id BIGINT UNSIGNED NULL AFTER id,
                ADD COLUMN scope_key VARCHAR(255) GENERATED ALWAYS AS (
                    CONCAT(
                        scope, ':',
                        COALESCE(principal_id, 0), ':',
                        COALESCE(agent_id, 0), ':',
                        type, ':',
                        name
                    )
                ) STORED,
                ADD PRIMARY KEY (id),
                ADD CONSTRAINT fk_memories_principal_id FOREIGN KEY (principal_id) REFERENCES principals(id) ON DELETE CASCADE,
                ADD UNIQUE INDEX uniq_memories_scope_key (scope_key),
                ADD INDEX idx_memories_principal_scope_type (principal_id, scope, type),
                ADD INDEX idx_memories_agent_scope_type_order (agent_id, scope, type, `order`),
                DROP INDEX memories_agent_id_name
        SQL);
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
