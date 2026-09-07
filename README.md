# spora-plugin-memories

Persistent memory storage for agents and users — UI + `memory` / `global_memory` tools for Spora agents.

This plugin contributes the **Memories** admin panel to the host's Apps dropdown. The panel is a pre-built Vue SPA delivered as a separate Composer package (`spora-ai/spora-plugin-memories-frontend`, type `spora-plugin-frontend`). The two-package split lets the frontend evolve on its own release cadence and lets backend-only operators skip the bundle entirely.

## Install

```bash
composer require spora-ai/spora-plugin-memories
```

The PHP package's `require` block pulls the frontend package in transitively — operators don't need to require it separately. The PHP package ships the migration (`memories` table), the controllers, the service layer, and the two memory tools (`memory` and `global_memory`); the frontend package ships the Vue IIFE bundle that the host SPA lazy-loads at runtime.

Requires `spora-ai/spora-core` ≥ 0.12.0 (when this plugin shipped, the memories feature was extracted out of core and the host was bumped to drop the previous core implementation).

## What it does

- Surfaces rows from the `memories` table as a sidebar-and-detail admin panel scoped per principal (global memories) and per agent (agent-scoped memories).
- Ships two migrations: `memories_000001_create_memories_table.php` (idempotent `hasTable` guard) and `memories_000002_introduce_principals_types_uuids.php` (v2 schema: principals model, `type` enum, UUIDv7 ids). The v2 migration is forward-only — see the migration file for the rationale and the pre-install-cleanup assumption.
- Ships two LLM-callable tools — `memory` (agent-scoped) and `global_memory` (principal-scoped, shared across the principal's agents) — each with `list`, `get`, `save`, `replace`, and `delete` operations. Every memory is tagged with a `type` (`plan` / `documentation` / `examples` / `context`) which is mandatory on save/get/replace. The `replace` op performs an exact substring edit on memory content (errors on zero or non-unique matches). List/get/save auto-approve; replace and delete require user approval.
- Adds a bundled agent template (`memories-assistant.json`) under the plugin's `agent-templates/` directory, wiring both tools onto the host's default system prompt with explicit guidance on when to use each.

## API surface

After install, 14 endpoints appear under `/api/v1/memories*`. All require `AuthMiddleware` + `CsrfMiddleware`. Every endpoint honours `?principal_id=N` for the `PrincipalChipRow`: omit it for the caller's user-principal, supply it to act on any principal (user or group) the caller controls.

- `GET    /api/v1/memories` — list the current principal's global memories. Optional `?type=plan|documentation|examples|context`.
- `POST   /api/v1/memories` — create a global memory (requires `name`, `type`, `content`).
- `PATCH  /api/v1/memories/reorder` — reorder global memories (body: `{order: [memoryId, ...]}`).
- `GET    /api/v1/memories/{id}` — fetch a single global memory (UUIDv7).
- `PUT    /api/v1/memories/{id}` — update a global memory (partial fields allowed).
- `POST   /api/v1/memories/{id}/replace` — surgical substring replacement (`{find, new_text}`).
- `DELETE /api/v1/memories/{id}` — delete a global memory.
- `GET    /api/v1/agents/{agentId}/memories` — list memories for one agent.
- `POST   /api/v1/agents/{agentId}/memories` — create an agent memory.
- `PATCH  /api/v1/agents/{agentId}/memories/reorder` — reorder agent memories.
- `GET    /api/v1/agents/{agentId}/memories/{memoryId}` — fetch one agent memory.
- `PUT    /api/v1/agents/{agentId}/memories/{memoryId}` — update an agent memory.
- `POST   /api/v1/agents/{agentId}/memories/{memoryId}/replace` — surgical substring replacement.
- `DELETE /api/v1/agents/{agentId}/memories/{memoryId}` — delete an agent memory.

Memories use UUIDv7 ids; v7 sorts chronologically and keeps adjacent writes in B-tree order, which matters for editorial workflows. Legacy UUIDv4 ids from v0.2.x continue to be readable by name+type lookups; no data migration needed beyond the v2 schema migration (which is forward-only).

## Uninstalling

`composer remove spora-ai/spora-plugin-memories` removes the admin-panel metadata from the App Registry, drops the 14 routes, and the navbar tile disappears cleanly. The `memories` table is **preserved** — uninstalling does not `Capsule::schema()->dropIfExists('memories')`. Reinstalling is a no-op on the schema. This is intentional: data persists across plugin uninstall/reinstall cycles.

## Reference

The canonical reference (REST contract, schema, tool definitions, agent template schema) lives on the docs site at [docs.spora-ai.com/develop/plugins/reference/memories](https://docs.spora-ai.com/develop/plugins/reference/memories).

If that URL is unreachable (the page may not have published yet), the source-of-truth copy lives in the spora-docs repo at [`docs/develop/plugins/reference/memories.md`](https://github.com/spora-ai/spora-docs/blob/main/docs/develop/plugins/reference/memories.md).

## License

MIT — see [LICENSE](LICENSE).
