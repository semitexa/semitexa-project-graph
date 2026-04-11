# semitexa-project-graph

Project graph package for fast AI and developer understanding of a Semitexa codebase.

## Primary workflow

Run these commands before deep code changes or code generation:

```bash
bin/semitexa ai:review-graph:generate --json
bin/semitexa ai:review-graph:stats --json
```

`generate` builds or incrementally refreshes the graph. `stats` confirms that the graph is ready and shows node/edge counts.

## Storage

The graph uses its own named ORM connection: `project_graph`.

- Preferred configuration: `DB_PROJECT_GRAPH_*`
- Default fallback: dedicated SQLite database
- Local CLI fallback path: `var/tmp/project-graph.sqlite`

This keeps graph storage separate from the application's primary database.

## Why AI agents should use it first

- It gives a fast structural view of the real project, not just static package docs.
- It is safer than assuming the default database connection.
- It reduces blind searching before edits.

## Related commands

- `bin/semitexa ai:review-graph:capabilities --json`
- `bin/semitexa ai:review-graph:query`
- `bin/semitexa ai:review-graph:show`
- `bin/semitexa ai:review-graph:impact`
