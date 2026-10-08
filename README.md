# semitexa-project-graph

Project graph package for fast AI and developer understanding of a Semitexa codebase.

Scans PHP source files, extracts semantic information via attributes and AST analysis, and stores it as a directed graph of nodes and edges. Enables LLMs and developers to understand a codebase in minutes instead of hours.

## Install

Included in every project created by the installer (https://semitexa.com/install.sh).

## Quick Start

```bash
# Generate or update the graph
bin/semitexa ai:review-graph:generate

# Confirm it's ready
bin/semitexa ai:review-graph:stats
```

## Full Documentation

- [Overview](https://semitexa.com/docs/project-graph/overview) — What the graph is and when to reach for it
- [Architecture](https://semitexa.com/docs/project-graph/architecture) — Graph data model, node/edge types, extractors, storage
- [Commands](https://semitexa.com/docs/project-graph/commands) — Complete CLI reference with examples
- [AI Integration](https://semitexa.com/docs/project-graph/ai-integration) — How AI agents consume the graph
- [Intelligence Layer](https://semitexa.com/docs/project-graph/intelligence) — Domains, flows, events, hotspots, intent inference

## Primary Workflow

Run these commands before deep code changes or code generation:

```bash
bin/semitexa ai:review-graph:generate --json
bin/semitexa ai:review-graph:stats --json
```

`generate` builds or incrementally refreshes the graph. `stats` confirms that the graph is ready and shows node/edge counts.

## Intelligence Commands

Beyond basic graph queries, the intelligence layer provides:

```bash
# Trace an event's full lifecycle (emitters → listeners → NATS → replay → DLQ)
bin/semitexa ai:review-graph:event-trace OrderCreated

# Trace an execution flow end-to-end
bin/semitexa ai:review-graph:flow-trace CheckoutFlow

# Get context for a development task
bin/semitexa ai:review-graph:context "adding payment method"

# Analyze impact of changing a component (class FQCN, file path or node id)
bin/semitexa ai:review-graph:impact 'App\Modules\Ordering\Application\Service\PaymentService'

# Module overview with domain, flows, events, hotspots
bin/semitexa ai:review-graph:module Ordering --include-events --include-flows

# See how the graph changed since last scan
bin/semitexa ai:review-graph:diff

# Watch for file changes and auto-update
bin/semitexa ai:review-graph:watch
```

For JSON output, `generate`, `stats`, `query`, `impact` and `capabilities` take `--json`; `context`, `event-trace`, `flow-trace`, `module`, `diff`, `show` and `findings` take `--format=json`. `intelligence` offers text or markdown only, and `watch` has no JSON mode.

## Storage

The graph uses its own named ORM connection: `project_graph`.

- Preferred configuration: `DB_PROJECT_GRAPH_*`
- Default fallback: dedicated SQLite database
- Default SQLite paths: `var/storage/project-graph.sqlite` or `var/tmp/project-graph.sqlite` (whichever this process can write; the most recently written graph wins)

This keeps graph storage separate from the application's primary database.

## Why AI agents should use it first

- It gives a fast structural view of the real project, not just static package docs.
- It is safer than assuming the default database connection.
- It reduces blind searching before edits.
- It reveals event flows and cross-module dependencies that are invisible from file-level analysis.

## All Commands

| Command | Purpose |
|---------|---------|
| `ai:review-graph:generate` | Build or incrementally update the graph |
| `ai:review-graph:stats` | Show graph statistics |
| `ai:review-graph:show` | Show details for a specific node |
| `ai:review-graph:query` | Find nodes by type, module, or metadata |
| `ai:review-graph:event-trace` | Trace full event lifecycle |
| `ai:review-graph:flow-trace` | Trace execution flow end-to-end |
| `ai:review-graph:context` | Build context for a development task |
| `ai:review-graph:impact` | Analyze impact of changing a component |
| `ai:review-graph:module` | Module overview with full context |
| `ai:review-graph:diff` | Show graph changes since last scan |
| `ai:review-graph:watch` | Watch for file changes and auto-update |
| `ai:review-graph:findings` | Unused classes graded by confidence, and class dependency loops |
| `ai:review-graph:intelligence` | Query the intelligence layer |
| `ai:review-graph:capabilities` | List all available commands |
