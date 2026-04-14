---
title: Zolta CQRS
description: A full-featured Command Query Responsibility Segregation framework for PHP 8.2+ with automatic handler discovery, decorator-based middleware, transaction management, and repository caching.
navigation:
  title: Introduction
  order: 0
---

# Zolta CQRS

Zolta CQRS is the **application layer** of the Zolta framework. It provides a complete CQRS (Command Query Responsibility Segregation) implementation with automatic handler discovery via PHP attributes, a decorator-based command bus pipeline, Result/Option monads, repository abstractions with built-in caching, and full transaction management.

## Architecture overview

```
┌─────────────────────────────────────────────────────────┐
│                    ApplicationService                    │
│  transactional() · runAndCapture() · response()         │
├─────────────────────────────────────────────────────────┤
│                      CqrsProxy                          │
│  Placeholder resolution · Auto-capture                  │
├───────────────────────┬─────────────────────────────────┤
│    Command Pipeline   │         Query Pipeline          │
│                       │                                 │
│ WorkerAwareRouting    │      InMemoryQueryBus           │
│  ├─ EventDispatching  │       └─ Handler                │
│  │   ├─ Validating    │                                 │
│  │   │   └─ Sync      │                                 │
│  │   └─ Event Dispatch│                                 │
│  └─ Queued (async)    │                                 │
├───────────────────────┴─────────────────────────────────┤
│                   Event Dispatcher                      │
│  Domain events · Laravel integration                    │
├─────────────────────────────────────────────────────────┤
│                   Repository Layer                      │
│  AbstractRepository · Eloquent · Cache · Filters        │
├─────────────────────────────────────────────────────────┤
│               Transaction Management                    │
│  TransactionManagerInterface · Auto commit/rollback     │
└─────────────────────────────────────────────────────────┘
```

## Core concepts

| Concept | Description |
|---------|-------------|
| **Commands** | Write operations that change state. Dispatched through the command bus pipeline. |
| **Queries** | Read operations that return data. Executed through the query bus. |
| **Events** | Domain events recorded by aggregates and dispatched after command execution. |
| **ApplicationService** | Orchestration pipeline with transactions, capture store, and response mapping. |
| **Result** | Success/Failure monad for command outcomes with event collection. |
| **Option** | Some/None/Error monad for query outcomes. |
| **Repository** | Persistence abstraction with caching, filtering, and query definitions. |

## Command bus decorator chain

Commands flow through a layered decorator pipeline:

```
WorkerAwareRoutingCommandBus
  │
  ├─ ShouldQueue? ──► QueuedCommandBus ──► ExecuteCommandJob (async)
  │
  └─ Sync path:
      EventDispatchingCommandBus
        └─ ValidatingCommandBus
             └─ SynchronousCommandBus
                  └─ Handler (via #[HandlesCommand])
```

| Decorator | Responsibility |
|-----------|---------------|
| `WorkerAwareRoutingCommandBus` | Routes sync vs async based on `ShouldQueue` interface |
| `QueuedCommandBus` | Enqueues commands as Laravel jobs |
| `EventDispatchingCommandBus` | Extracts and dispatches domain events from `Result` |
| `ValidatingCommandBus` | Runs validators before handler execution |
| `SynchronousCommandBus` | Resolves and executes the command handler |

## Ecosystem integration

| Package | Layer | Purpose |
|---------|-------|---------|
| **zolta-forge** | Domain | Value Objects, Entities, Rules, Specifications |
| **zolta-cqrs** | Application | Commands, Queries, Events, Repositories, Transactions |
| **zolta-http** | API | Routing, Request/Response, Authorization |

Zolta CQRS builds on top of zolta-forge's domain primitives and provides the application-layer orchestration consumed by zolta-http's API handlers.

## Key features

- **Attribute-based handler discovery** — `#[HandlesCommand]`, `#[HandlesQuery]`, `#[ValidatesCommand]`
- **Automatic argument resolution** — Handlers receive injected dependencies alongside commands
- **Result/Option monads** — Type-safe success/failure/none handling
- **Transaction management** — Automatic commit/rollback based on Result outcomes
- **Repository caching** — Hashed cache keys, namespace-scoped invalidation
- **Event dispatching** — Domain events extracted from Results and dispatched post-commit
- **Queue integration** — Commands marked `ShouldQueue` are automatically deferred
- **Worker detection** — Queued commands execute synchronously inside workers to prevent re-queuing
- **Message hydration** — Automatic DTO/VO construction from raw data
- **Framework agnostic** — Core is PSR-compatible; Laravel adapter provided
