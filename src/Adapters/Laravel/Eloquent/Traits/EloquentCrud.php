<?php

declare(strict_types=1);

namespace Zolta\Cqrs\Laravel\Eloquent\Traits;

use DomainException;
use Illuminate\Database\Eloquent\Model;
use Zolta\Domain\ValueObjects\AbstractUuid;

/**
 * Reusable CRUD behaviour for Eloquent repositories.
 *
 * Delegates query construction and caching to the surrounding repository,
 * keeping domain-level operations cohesive and testable.
 */
trait EloquentCrud
{
    public function create(Model $model): Model
    {
        try {
            $model->save();
            $this->invalidateModelCaches($model->getKey());
            if (method_exists($this, 'afterWrite')) {
                $this->afterWrite();
            }
            return $model->fresh($this->getAllowedRelations());
        } catch (\Throwable $ex) {
            throw new DomainException('Failed to create resource: ' . $ex->getMessage(), 0, $ex);
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(Model $model, array $attributes = []): Model
    {
        try {
            if ($attributes !== []) {
                $model->fill($attributes);
            }

            $model->save();
            $this->invalidateModelCaches($model->getKey());
            if (method_exists($this, 'afterWrite')) {
                $this->afterWrite();
            }
            return $model->fresh($this->getAllowedRelations());
        } catch (\Throwable $ex) {
            throw new DomainException('Failed to update resource: ' . $ex->getMessage(), 0, $ex);
        }
    }

    public function delete(Model $model): bool
    {
        $result = (bool) $model->delete();

        if ($result) {
            $this->invalidateModelCaches($model->getKey());
            if (method_exists($this, 'afterWrite')) {
                $this->afterWrite();
            }
        }

        return $result;
    }

    protected function invalidateModelCaches(string|int $id): void
    {
        try {
            $this->cache()->forget('entity', ['id' => (string) $id]);

            foreach ($this->cacheNamespacesForInvalidation() as $namespace) {
                $this->cache()->flushNamespace($namespace);
            }
        } catch (\Throwable) {
            // swallowing cache invalidation errors keeps write path resilient
        }
    }

    /**
     * Namespaces that should be flushed after a write operation.
     *
     * @return list<string>
     */
    protected function cacheNamespacesForInvalidation(): array
    {
        return ['first', 'all'];
    }

    protected function resolveId(AbstractUuid|string|int $id): string|int
    {
        return $id instanceof AbstractUuid ? $id->get('value') : $id;
    }

    abstract protected function modelClass(): string;
}
