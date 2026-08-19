<?php

namespace ItkDev\OpenIdConnectBundle\Tests;

use Psr\Log\AbstractLogger;

/**
 * In-memory PSR-3 logger, so tests can assert what the bundle logs.
 *
 * `psr/log` 3 ships no test double, and the bundle's log calls have to be
 * asserted rather than merely executed — an unasserted log call survives as a
 * mutant under Infection.
 */
class TestLogger extends AbstractLogger
{
    /** @var list<array{level: mixed, message: string, context: mixed[]}> */
    public array $records = [];

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->records[] = [
            'level' => $level,
            'message' => (string) $message,
            'context' => $context,
        ];
    }

    /**
     * The single record logged, failing loudly if there is not exactly one.
     *
     * @return array{level: mixed, message: string, context: mixed[]}
     */
    public function singleRecord(): array
    {
        if (1 !== count($this->records)) {
            throw new \RuntimeException(sprintf('Expected exactly 1 log record, got %d.', count($this->records)));
        }

        return $this->records[0];
    }
}
