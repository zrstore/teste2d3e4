<?php

namespace Appwrite\Tests\Async;

use PHPUnit\Framework\Constraint\Constraint;

final class Eventually extends Constraint
{
    private int $timeoutMs;
    private int $waitMs;

    public function __construct(int $timeoutMs = 10000, int $waitMs = 500)
    {
        $this->timeoutMs = $timeoutMs;
        $this->waitMs = $waitMs;
    }

    public function evaluate(mixed $probe, string $description = '', bool $returnResult = false): ?bool
    {
        if (!is_callable($probe)) {
            throw new \Exception('Probe must be a callable');
        }

        $start = microtime(true);
        $lastException = null;

        do {
            try {
                $probe();
                return true;
            } catch (\Exception $exception) {
                $lastException = $exception;
            }

            usleep($this->waitMs * 1000);
        } while (microtime(true) - $start < $this->timeoutMs / 1000);

        if ($returnResult) {
            return false;
        }

        throw $lastException;
    }

    protected function failureDescription(mixed $other): string
    {
        return 'the given probe was satisfied within the provided timeout';
    }

    public function toString(): string
    {
        return 'Eventually';
    }
}
