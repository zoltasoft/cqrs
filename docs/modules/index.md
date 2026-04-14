---
title: Modules
description: Overview of all Zolta CQRS modules and their interactions.
navigation:
  title: Modules
  order: 2
---

# Modules

Zolta CQRS is organized into focused modules that work together to provide a complete application-layer framework.

## Module map

| Module | Purpose | Key classes |
|--------|---------|-------------|
| [Commands](/modules/commands) | Write operations with decorator pipeline | `Command`, `CommandBusInterface`, `Result` |
| [Queries](/modules/queries) | Read operations with handler resolution | `Query`, `QueryBusInterface`, `Option` |
| [Events](/modules/events) | Domain event dispatching and handling | `EventDispatcherInterface`, `EventHandlerInterface` |
| [Application Service](/modules/application-service) | Orchestration pipeline with transactions | `ApplicationService`, `CqrsProxy` |
| [Repository](/modules/repository) | Persistence abstraction with caching | `AbstractRepository`, `EloquentBaseRepository` |
| [Caching](/modules/caching) | Repository cache layer | `RepositoryCache`, `CacheKeyGenerator` |
| [Transactions](/modules/transactions) | Database transaction management | `TransactionManagerInterface` |
| [Hydration](/modules/hydration) | Message serialization and construction | `MessageHydratorInterface` |
| [Argument Resolver](/modules/argument-resolver) | Handler method and argument resolution | `ArgumentResolver` |

## Data flow

### Command flow

```
Controller
  └─ cqrs->dispatch(CreateUserCommand)
       └─ WorkerAwareRoutingCommandBus
            ├─ ShouldQueue? → QueuedCommandBus → Job
            └─ EventDispatchingCommandBus
                 └─ ValidatingCommandBus
                      └─ SynchronousCommandBus
                           └─ CreateUserHandler::__invoke()
                                └─ Result::success($data, $events)
                                     └─ Events dispatched
```

### Query flow

```
Controller
  └─ cqrs->ask(GetUserQuery)
       └─ InMemoryQueryBus
            └─ GetUserHandler::__invoke()
                 └─ Option::some($data) | Option::none()
```

### ApplicationService flow

```
Service
  └─ applicationService->transactional(function() {
       $this->runAndCapture(CreateUserCommand, ...args)
       $this->runAndCapture(AssignRoleCommand, ...args)
       return $this->response($map, ResponseDTO::class)
  })
  │
  ├─ BEGIN TRANSACTION
  ├─ Execute commands, capture results
  ├─ Build response from captured data
  ├─ Result::failure()? → ROLLBACK
  └─ Result::success()? → COMMIT
```

## Module dependencies

```
argument-resolver ◄── commands, queries
hydration         ◄── commands, queries (via Cqrs service)
events            ◄── commands (EventDispatchingCommandBus)
transactions      ◄── application-service
caching           ◄── repository
repository        ◄── application-service (persistence)
application-service ◄── top-level orchestration
```
