<?php

namespace Encore\Admin\Form;

use Illuminate\Database\Connection;
use Illuminate\Database\Events\TransactionRolledBack;

/** File changes follow the owning connection, including an outer host transaction. */
class UploadTransaction
{
    private array $operations = [];
    private ?object $active = null;

    public function run(Connection $connection, callable $callback)
    {
        $previous = $this->active;
        $operation = (object) ['connection' => $connection, 'level' => $connection->transactionLevel() + 1,
            'uploads' => [], 'deletes' => [], 'finished' => false];
        $id = spl_object_id($operation);
        $this->operations[$id] = $operation;
        $this->active = $operation;

        try {
            return $connection->transaction(function () use ($connection, $callback, $operation, $id) {
                $result = $callback();
                $connection->afterCommit(function () use ($operation, $id) {
                    if (!$operation->finished) {
                        $operation->finished = true;
                        $this->clean($operation->deletes);
                    }
                    unset($this->operations[$id]);
                });
                return $result;
            });
        } catch (\Throwable $exception) {
            $this->cancel($operation);
            unset($this->operations[$id]);
            throw $exception;
        } finally {
            $this->active = $previous;
        }
    }

    public function uploaded($storage, string $path): void
    {
        if ($this->active) {
            $this->active->uploads[] = [$storage, $path];
        }
    }

    public function delete($storage, ?string $path): void
    {
        if (!$path) {
            return;
        }
        if ($this->active) {
            $this->active->deletes[] = [$storage, $path];
        } elseif ($storage->exists($path) && $storage->delete($path) === false) {
            throw new \RuntimeException("Unable to delete file [{$path}].");
        }
    }

    public function rolledBack(TransactionRolledBack $event): void
    {
        foreach ($this->operations as $id => $operation) {
            if ($operation->connection === $event->connection && $operation->level > $event->connection->transactionLevel()) {
                $this->cancel($operation);
                unset($this->operations[$id]);
            }
        }
    }

    private function cancel(object $operation): void
    {
        if (!$operation->finished) {
            $operation->finished = true;
            $this->clean($operation->uploads);
        }
    }

    private function clean(array $files): void
    {
        foreach ($files as [$storage, $path]) {
            try {
                if ($storage->exists($path) && $storage->delete($path) === false) {
                    throw new \RuntimeException("Unable to clean file [{$path}].");
                }
            } catch (\Throwable $exception) {
                // A cleanup failure after commit must not turn a saved submission into a retry.
                report($exception);
            }
        }
    }
}
