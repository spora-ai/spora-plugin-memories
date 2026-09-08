<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Migrations\Migration;
use Spora\Core\Database\MigrationHelpers;

/**
 * The plugin's Pest.php beforeEach already boots every migration
 * (spora-core's `bin/spora spora:install`-equivalent + the plugin's own
 * `database/migrations/*.php`), so by the time any test in this file
 * runs, the `memories` table exists in its post-000002 shape.
 *
 * To exercise 000002 in isolation, each test first drops `memories`
 * and re-runs the predecessor (000001) to reach the pre-000002
 * starting state. Then we run 000002 and assert the post-state.
 */

/**
 * Typed handle on the `MigrationHelpers` trait. Pest's `uses()` binding
 * doesn't propagate to PHPStan's view of `$this` (PHPStan sees
 * `Pest\PendingCalls\TestCall`), so we wrap the trait on a typed
 * anonymous-class instance and call through that.
 */
function migrationHelpers(): object
{
    return new class extends Migration {
        use MigrationHelpers;
    };
}

/**
 * SQLite port of the user's MySQL/MariaDB partial-state simulation.
 *
 * The actual production failure was on MariaDB, where
 * `ALTER TABLE memories DROP FOREIGN KEY memories_user_id_foreign`
 * raised SQLSTATE[42000] 1091 because the FK had already been
 * dropped by a prior failed migration run. We can't replay that
 * exact DDL on SQLite — SQLite doesn't support `ALTER TABLE ...
 * DROP FOREIGN KEY`, and Laravel's anonymous FK naming means the
 * trait's `foreignKeyExists()` would return false here anyway —
 * so we rebuild the table in place without the `user_id` FK and
 * drop the supporting index. The resulting pre-state mirrors the
 * MariaDB partial state for the migration's purposes: no FK on
 * `user_id`, no index on `(user_id, name)`, `user_id` column
 * still present.
 */
function simulateMemoriesPartialState(): void
{
    $conn = Capsule::connection();

    $columns = $conn->select("PRAGMA table_info('memories')");
    $columnDefs = [];
    foreach ($columns as $c) {
        $isPk = ((int) $c->pk) > 0;
        $dflt = ($c->dflt_value !== null && strtoupper((string) $c->dflt_value) !== 'NULL')
            ? ' DEFAULT ' . $c->dflt_value
            : '';
        $columnDefs[] = sprintf(
            '"%s" %s%s%s%s',
            $c->name,
            $c->type,
            ($c->notnull && !$isPk ? ' NOT NULL' : ''),
            $dflt,
            ($isPk ? ' PRIMARY KEY' : ''),
        );
    }

    $fks = $conn->select("PRAGMA foreign_key_list('memories')");
    $fkDefs = [];
    foreach ($fks as $fk) {
        if ($fk->from === 'user_id') {
            continue;
        }
        $key = $fk->id;
        if (!isset($fkDefs[$key])) {
            $fkDefs[$key] = ['table' => $fk->table, 'columns' => [], 'references' => []];
        }
        $fkDefs[$key]['columns'][] = $fk->from;
        $fkDefs[$key]['references'][] = $fk->to;
    }

    $sql = "CREATE TABLE memories (\n  ";
    $sql .= implode(",\n  ", $columnDefs);
    foreach ($fkDefs as $def) {
        $cols = implode(', ', array_map(static fn($c) => "\"{$c}\"", $def['columns']));
        $refs = implode(', ', array_map(static fn($r) => "\"{$r}\"", $def['references']));
        $sql .= ",\n  FOREIGN KEY ({$cols}) REFERENCES \"{$def['table']}\" ({$refs})";
    }
    $sql .= "\n)";

    $rows = Capsule::table('memories')->get()->all();

    $conn->statement('PRAGMA foreign_keys = OFF');
    $conn->statement('DROP TABLE memories');
    $conn->statement($sql);

    if ($rows !== []) {
        $columnsToKeep = array_map(static fn($c) => $c->name, $columns);
        $colsList = implode(', ', array_map(static fn($c) => "\"{$c}\"", $columnsToKeep));
        $placeholders = '(' . implode(',', array_fill(0, count($columnsToKeep), '?')) . ')';
        foreach ($rows as $row) {
            $bindings = [];
            foreach ($columnsToKeep as $c) {
                $bindings[] = $row->{$c} ?? null;
            }
            $conn->insert("INSERT INTO memories ({$colsList}) VALUES {$placeholders}", $bindings);
        }
    }

    $conn->statement('DROP INDEX IF EXISTS memories_user_id_name_index');

    $conn->statement('PRAGMA foreign_keys = ON');
}

/**
 * `hasPrimaryKey()` in the trait is MySQL/MariaDB-only (its docstring
 * says so). On SQLite we read `pk > 0` from PRAGMA table_info instead.
 */
function memoriesHasPrimaryKey(): bool
{
    if (Capsule::connection()->getDriverName() === 'sqlite') {
        $cols = Capsule::connection()->select("PRAGMA table_info('memories')");
        foreach ($cols as $c) {
            if ((int) $c->pk > 0) {
                return true;
            }
        }
        return false;
    }
    return migrationHelpers()->hasPrimaryKey('memories');
}

beforeEach(function (): void {
    Capsule::schema()->dropIfExists('memories');
});

test('memories_000002 fresh install: schema lands in the post-state', function (): void {
    $predecessor = require __DIR__ . '/../../../database/migrations/memories_000001_create_memories_table.php';
    $predecessor->up();

    $migration = require __DIR__ . '/../../../database/migrations/memories_000002_introduce_principals_types_uuids.php';
    $migration->up();

    $helpers = migrationHelpers();

    expect(Capsule::schema()->hasColumn('memories', 'id'))->toBeTrue();
    expect(Capsule::schema()->hasColumn('memories', 'scope'))->toBeTrue();
    expect(Capsule::schema()->hasColumn('memories', 'type'))->toBeTrue();
    expect(Capsule::schema()->hasColumn('memories', 'principal_id'))->toBeTrue();
    expect(Capsule::schema()->hasColumn('memories', 'scope_key'))->toBeTrue();
    expect(Capsule::schema()->hasColumn('memories', 'user_id'))->toBeFalse();
    expect(Capsule::schema()->hasColumn('memories', 'agent_id'))->toBeTrue();

    expect($helpers->indexExists('memories', 'uniq_memories_scope_key'))->toBeTrue();
    expect($helpers->indexExists('memories', 'idx_memories_principal_scope_type'))->toBeTrue();
    expect($helpers->indexExists('memories', 'idx_memories_agent_scope_type_order'))->toBeTrue();
    expect($helpers->indexExists('memories', 'memories_agent_id_name'))->toBeFalse();
    expect(memoriesHasPrimaryKey())->toBeTrue();
});

test('memories_000002 idempotency: re-running up() on a fresh post-state does not throw', function (): void {
    $predecessor = require __DIR__ . '/../../../database/migrations/memories_000001_create_memories_table.php';
    $predecessor->up();

    $migration = require __DIR__ . '/../../../database/migrations/memories_000002_introduce_principals_types_uuids.php';
    $migration->up();

    // The user's MariaDB error: re-run after the post-state is reached.
    // With idempotent guards, this must NOT throw the 1091 we saw on MariaDB.
    expect(fn() => $migration->up())->not()->toThrow(Throwable::class);

    expect(Capsule::schema()->hasColumn('memories', 'id'))->toBeTrue();
    expect(Capsule::schema()->hasColumn('memories', 'user_id'))->toBeFalse();
    expect(memoriesHasPrimaryKey())->toBeTrue();
});

test('memories_000002 partial-state: pre-dropping the user_id FK + index lets up() complete cleanly', function (): void {
    $predecessor = require __DIR__ . '/../../../database/migrations/memories_000001_create_memories_table.php';
    $predecessor->up();

    // Simulate the partial state: the FK + supporting index on user_id
    // have been dropped (either by a prior failed migration run or by
    // a manual operator cleanup). On MariaDB this triggered 1091 when
    // the migration's `ALTER TABLE ... DROP FOREIGN KEY` ran without
    // its existence guard; on SQLite we rebuild the table without the
    // FK constraint and `DROP INDEX IF EXISTS` to land in the same
    // starting point for the migration.
    simulateMemoriesPartialState();

    $helpers = migrationHelpers();
    expect($helpers->foreignKeyExists('memories', 'memories_user_id_foreign'))->toBeFalse();
    expect($helpers->indexExists('memories', 'memories_user_id_name_index'))->toBeFalse();

    $migration = require __DIR__ . '/../../../database/migrations/memories_000002_introduce_principals_types_uuids.php';
    expect(fn() => $migration->up())->not()->toThrow(Throwable::class);

    expect(Capsule::schema()->hasColumn('memories', 'user_id'))->toBeFalse();
    expect(Capsule::schema()->hasColumn('memories', 'scope'))->toBeTrue();
    expect(Capsule::schema()->hasColumn('memories', 'scope_key'))->toBeTrue();
});
