<?php

declare(strict_types=1);

namespace IndexNowKit\Attribute\Param;

/**
 * A `when` guard evaluated against the object: the page exists while it holds. `Equals` is the shipped one; write
 * your own for anything a comparison cannot say (`new Between('price', 1, 100)`, `new Published()`), and implement
 * {@see FieldCondition} on top when the ORM change set should let the classifier see the old state.
 *
 * Implement tier (docs/bc.md): the core calls you; methods are not added in a minor. A condition must not throw for
 * a valid object: an exception is logged as `cannot evaluate when` and the rule yields nothing.
 */
interface Condition
{
    public function evaluate(object $subject): bool;
}
