<?php

namespace ItkDev\OpenIdConnectBundle\Tests;

/**
 * Undo the exception handler a handled request leaves behind.
 *
 * On Symfony 6.4 — the floor this bundle supports — handling a request in debug mode
 * installs an exception handler and never removes it. PHPUnit reports the test as
 * risky, and rightly: left in place it would also handle exceptions raised by later
 * tests in the same process.
 *
 * Both methods are called explicitly rather than hooked into `setUp()`/`tearDown()`
 * here, so that a class adding either of its own cannot silently switch this off.
 */
trait RestoresExceptionHandlers
{
    private mixed $handlerBeforeTest = null;

    /**
     * Note what was installed before the test, so only what the test added is undone.
     */
    protected function captureExceptionHandler(): void
    {
        $this->handlerBeforeTest = $this->currentExceptionHandler();
    }

    protected function restoreExceptionHandlers(): void
    {
        // Pops one handler at a time rather than draining the stack, which would
        // discard a global handler registered before the suite ran.
        while ($this->currentExceptionHandler() !== $this->handlerBeforeTest) {
            restore_exception_handler();
        }
    }

    /**
     * Read the current handler without changing the stack: the push is undone
     * immediately, and `set_exception_handler()` returns what it displaced.
     */
    private function currentExceptionHandler(): mixed
    {
        $handler = set_exception_handler(null);
        restore_exception_handler();

        return $handler;
    }
}
