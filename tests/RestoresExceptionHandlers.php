<?php

namespace ItkDev\OpenIdConnectBundle\Tests;

/**
 * Undo the exception handler a handled request leaves behind.
 *
 * On Symfony 6.4 — the floor this bundle supports — handling a request in debug mode
 * installs an exception handler that is never removed. PHPUnit reports the test as
 * risky, and rightly: left in place it would also handle exceptions raised by later
 * tests in the same process.
 */
trait RestoresExceptionHandlers
{
    protected function tearDown(): void
    {
        // set_exception_handler(null) pushes a null handler and returns the one that
        // was current, so each iteration pops both it and the handler it found.
        while (null !== set_exception_handler(null)) {
            restore_exception_handler();
            restore_exception_handler();
        }

        // The last iteration pushed a null handler of its own.
        restore_exception_handler();
    }
}
