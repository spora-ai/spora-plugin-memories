# Changelog

All notable changes to **spora-plugin-memories** are documented here.
The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.1.0] - 2026-07-21

### Added

- Initial release — extracts the agent-memories feature from `spora-core` into a
  self-contained Composer plugin.
- Two LLM-callable tools: `memory` (agent-scoped) and `global_memory`
  (user-scoped), each supporting `list` / `get` / `save` / `delete` operations.
- Admin Vue SPA mounted at `/apps/memories` via the host's `PluginAppPage`.
- Migration `memories_000001_create_memories_table.php` with a
  `hasTable('memories')` guard for clean upgrades from `spora-core` 0.11.x.
- Twelve REST endpoints under `/api/v1/memories` and
  `/api/v1/agents/{agentId}/memories`.
- Agent template `memories-assistant.json` exposing both tools plus a system
  prompt instructing the LLM when to use each.
- Pest test suite (158 tests, 94.78% statement coverage).
- 5-job GitHub Actions CI: `test` (PHP 8.4 + 8.5), `static-analysis`
  (PHPStan level 5), `code-style` (PHP-CS-Fixer), `coverage`, and `sonar`
  (SonarCloud quality gate).
