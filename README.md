# spora-plugin-memories

Persistent memory storage for agents and users — UI + `memory` / `global_memory` tools for Spora agents.

This plugin contributes the **Memories** admin panel to the host's Apps dropdown. The panel is a pre-built Vue SPA delivered as a separate Composer package (`spora-ai/spora-plugin-memories-frontend`, type `spora-plugin-frontend`). The two-package split lets the frontend evolve on its own release cadence and lets backend-only operators skip the bundle entirely.

## Install

```bash
composer require spora-ai/spora-plugin-memories
composer require spora-ai/spora-plugin-memories-frontend
```

Both packages are required: the PHP package ships the migration (`memories` table), the controllers, the service layer, and the two memory tools (`memory` and `global_memory`). The frontend package ships the Vue IIFE bundle that the host SPA lazy-loads at runtime.

Requires `spora-ai/spora-core` ≥ 0.12.0 (when this plugin shipped, the memories feature was extracted out of core and the host was bumped to drop the previous core implementation).

## What it does

- Surfaces rows from the `memories` table as a sidebar-and-detail admin panel scoped per user (global) and per agent.
- Creates a `memories_000001_create_memories_table.php` migration. On installs upgrading from a host that already shipped the `memories` table, the migration's `hasTable('memories')` guard makes it a no-op.
- Ships two LLM-callable tools — `memory` (agent-scoped) and `global_memory` (cross-agent, per user) — each with `list`, `get`, `save`, and `delete` operations. Read ops auto-approve; write ops require explicit approval.
- Adds a bundled agent template (`memories-assistant.json`) under the plugin's `agent-templates/` directory, wiring both tools onto the host's default system prompt with explicit guidance on when to use each.

## API surface

After install, 12 endpoints appear under `/api/v1/memories*`:

- `GET    /api/v1/memories` — list the current user's global memories.
- `POST   /api/v1/memories` — create a global memory.
- `PATCH  /api/v1/memories/reorder` — reorder global memories.
- `GET    /api/v1/memories/{id}` — fetch a single global memory.
- `PUT    /api/v1/memories/{id}` — update a global memory.
- `DELETE /api/v1/memories/{id}` — delete a global memory.
- `GET    /api/v1/agents/{agentId}/memories` — list memories for one agent.
- `POST   /api/v1/agents/{agentId}/memories` — create an agent memory.
- `PATCH  /api/v1/agents/{agentId}/memories/reorder` — reorder agent memories.
- `GET    /api/v1/agents/{agentId}/memories/{memoryId}` — fetch one agent memory.
- `PUT    /api/v1/agents/{agentId}/memories/{memoryId}` — update an agent memory.
- `DELETE /api/v1/agents/{agentId}/memories/{memoryId}` — delete an agent memory.

All endpoints require `AuthMiddleware` + `CsrfMiddleware`.

## Companion plugins

Listed in `composer.json` under `suggest`:

- _None yet._ Future plugins that consume memory (a `spora-plugin-vault`, a `spora-plugin-search`) should appear here.

## Uninstalling

`composer remove spora-ai/spora-plugin-memories` removes the admin-panel metadata from the App Registry, drops the 12 routes, and the navbar tile disappears cleanly. The `memories` table is **preserved** — uninstalling does not `Capsule::schema()->dropIfExists('memories')`. Reinstalling is a no-op on the schema. This is intentional: data persists across plugin uninstall/reinstall cycles.

## Reference

The canonical reference (REST contract, schema, tool definitions, agent template schema) lives on the docs site:

**[docs.spora-ai.com/develop/plugins/reference/memories](https://docs.spora-ai.com/develop/plugins/reference/memories)**

## License

MIT — see [LICENSE](LICENSE).
