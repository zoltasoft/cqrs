<?php

declare(strict_types=1);

namespace Zolta\Cqrs\Commands;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Zolta\Cqrs\Commands\Contracts\CommandInterface;

class ExecuteCommandJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function __construct(protected CommandInterface $command) {}

    /**
     * The handle method is executed by the queue worker.
     * It resolves the command bus and dispatches the original command.
     *
     * @param  ValidatingCommandBus  $validatingCommandBus  The decorated command bus.
     * @param  mixed  ...$args
     */
    public function handle(ValidatingCommandBus $validatingCommandBus, ...$args): void
    {
        app()->instance('zolta.commandbus.in_worker', true);
        $validatingCommandBus->dispatch($this->command, ...$args);
    }
}
