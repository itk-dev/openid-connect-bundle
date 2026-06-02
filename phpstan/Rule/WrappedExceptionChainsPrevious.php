<?php

namespace ItkDev\OpenIdConnectBundle\PHPStan\Rule;

use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\Throw_;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Stmt\Catch_;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * When a catch block rethrows by constructing a new exception, the caught
 * exception must be chained as `$previous` (positional 3rd argument or named
 * `previous:`). This enforces the "wrap at the boundary" rule in ADR 001,
 * preserving `getPrevious()` traversal for logs and debugging.
 *
 * Escape hatch: add "phpstan-ignore throw.unchainedPrevious" on the throw line
 * with a justification comment when the rethrow is intentionally unrelated to
 * the caught cause.
 *
 * @implements Rule<Catch_>
 */
final class WrappedExceptionChainsPrevious implements Rule
{
    public function getNodeType(): string
    {
        return Catch_::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        if (null === $node->var) {
            return [];
        }

        $catchVarName = $node->var->name;
        if (!is_string($catchVarName)) {
            return [];
        }

        $errors = [];
        foreach ($this->findThrows($node->stmts) as $throw) {
            $newExpr = $throw->expr;
            if (!$newExpr instanceof New_) {
                continue;
            }

            if ($this->chainsCaughtAsPrevious($newExpr, $catchVarName)) {
                continue;
            }

            $thrownLabel = $this->describeNewExpr($newExpr);
            $errors[] = RuleErrorBuilder::message(sprintf(
                'Exception %s thrown inside catch($%s) does not chain the caught exception as `previous`. Pass `previous: $%s` (or as the 3rd positional argument), or annotate with `@phpstan-ignore throw.unchainedPrevious` when the unrelated throw is intentional.',
                $thrownLabel,
                $catchVarName,
                $catchVarName,
            ))
                ->identifier('throw.unchainedPrevious')
                ->line($throw->getStartLine())
                ->build();
        }

        return $errors;
    }

    /**
     * Collect every `throw` node reachable from these statements, but stop at
     * nested catch blocks — those bind a different catch variable and are
     * inspected by their own rule invocation.
     *
     * @param Node[] $stmts
     *
     * @return list<Throw_>
     */
    private function findThrows(array $stmts): array
    {
        $found = [];
        foreach ($stmts as $stmt) {
            $this->collect($stmt, $found);
        }

        return $found;
    }

    /**
     * @param list<Throw_> $found
     */
    private function collect(Node $node, array &$found): void
    {
        if ($node instanceof Catch_) {
            // A nested catch owns its own catch variable scope; its rethrows are
            // checked when that catch is processed.
            return;
        }

        if ($node instanceof Throw_) {
            $found[] = $node;
        }

        foreach ($node->getSubNodeNames() as $subName) {
            $sub = $node->{$subName}; // @phpstan-ignore property.dynamicName (walking an AST node's children requires a dynamic subnode name lookup; the names come from getSubNodeNames() and are intrinsically dynamic)
            if ($sub instanceof Node) {
                $this->collect($sub, $found);
            } elseif (is_array($sub)) {
                foreach ($sub as $item) {
                    if ($item instanceof Node) {
                        $this->collect($item, $found);
                    }
                }
            }
        }
    }

    /**
     * Accept either a named `previous: $catchVar` argument, or any argument whose
     * value is directly the catch variable. The latter covers conventional PHP
     * exceptions (positional $previous at index 2) and Symfony's HTTP exception
     * subclasses (which bake the status code into the type and place $previous
     * at index 1). Passing the catch variable as any other slot is unusual in
     * practice; the looser check trades a rare false-negative for not depending
     * on constructor reflection.
     */
    private function chainsCaughtAsPrevious(New_ $new, string $catchVarName): bool
    {
        foreach ($new->args as $arg) {
            if (!$arg instanceof Arg) {
                continue;
            }

            if (null !== $arg->name && 'previous' === $arg->name->name) {
                return $this->valueIsVariable($arg->value, $catchVarName);
            }

            if (null === $arg->name && $this->valueIsVariable($arg->value, $catchVarName)) {
                return true;
            }
        }

        return false;
    }

    private function valueIsVariable(Node\Expr $expr, string $name): bool
    {
        return $expr instanceof Variable && is_string($expr->name) && $expr->name === $name;
    }

    private function describeNewExpr(New_ $new): string
    {
        $cls = $new->class;
        if ($cls instanceof Node\Name) {
            return $cls->toString();
        }

        return 'anonymous/dynamic exception';
    }
}
