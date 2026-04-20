# semitexa-project-graph Documentation

Technical reference for the Semitexa project graph — the intelligence layer that enables LLMs and developers to understand a codebase in minutes instead of hours.

## Quick Start

```bash
# Generate or update the graph
bin/semitexa ai:review-graph:generate

# View stats
bin/semitexa ai:review-graph:stats

# Trace an event lifecycle
bin/semitexa ai:review-graph:event-trace OrderCreated

# Analyze impact of a change
bin/semitexa ai:review-graph:impact PaymentService

# Get context for a task
bin/semitexa ai:review-graph:context "adding payment method"
```

## Documentation Map

| Document | What it covers |
|----------|---------------|
| [ARCHITECTURE](ARCHITECTURE.md) | Graph data model, node/edge types, storage, extractors |
| [COMMANDS](COMMANDS.md) | Complete CLI reference with examples |
| [AI_INTEGRATION](AI_INTEGRATION.md) | How AI agents consume the graph, JSON output, query patterns |
| [INTELLIGENCE](INTELLIGENCE.md) | Intelligence layer: domains, flows, events, hotspots, intent inference |

## Package Structure

```
src/
├── Application/
│   ├── Console/          # CLI commands
│   ├── Db/               # ORM storage layer
│   ├── Extractor/        # Code analysis extractors
│   │   ├── Attribute/    # Attribute-based extractors
│   │   └── Ast/          # AST-based extractors
│   ├── Graph/            # Core graph model (Node, Edge, NodeType, EdgeType, NodeId)
│   ├── Index/            # Incremental indexing engine
│   ├── Intelligence/     # Intelligence layer (domains, flows, hotspots, intent)
│   ├── Parser/           # PHP-Parser adapter
│   ├── Query/            # Graph query service
│   └── Scanner/          # File scanner
└── Domain/
    └── Model/            # Domain entities
```

## Related

- Canonical framework docs: `packages/semitexa-docs/docs/` and `packages/semitexa-docs/docs/workspace/`.
- For package-local architecture and intelligence-layer notes, see the other files in this directory.
