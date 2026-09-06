<?php

declare(strict_types=1);

namespace IndexNowKit\Attribute\Param;

/**
 * A `when` guard evaluated against the whole object: the page exists while it holds. Write one for anything a single
 * field cannot say (`new Published()` that looks at three fields, `new Between('price', 1, 100)`); a condition that
 * reads one field is a {@see FieldCondition} instead (`Equals` is the shipped one), so the ORM change set can give the
 * classifier the old state. A Condition reads the object itself (the plain DSL or its own code), so it has no old
 * value: name the fields it reads in `whenFields` when their change should count as a visibility flip.
 *
 * Implement tier (docs/bc.md): the core calls you; methods are not added in a minor. A condition must not throw for
 * a valid object: an exception is logged as `cannot evaluate when` and the rule yields nothing.
 */
interface Condition
{
    public function evaluate(object $subject): bool;
}
