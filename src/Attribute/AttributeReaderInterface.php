<?php

declare(strict_types=1);

namespace IndexNowKit\Attribute;

use IndexNowKit\Exception\ConfigurationException;

/**
 * Finds the URL rules of a class or object. The default implementation compiles #[IndexNow] attributes;
 * adapters for frameworks without attributes (CMS post types, closure registries) implement it themselves
 * or register rules at runtime with {@see RuleRegistry}.
 */
interface AttributeReaderInterface
{
    /**
     * @param class-string|object $classOrObject
     *
     * @return RuleSet empty when the class has no rules
     *
     * @throws ConfigurationException when a declared rule is invalid (no source, unknown event, ...); ORM hooks
     *                                must read through ObjectChangeHandler / GuardedUrlResolver, which log instead
     */
    public function rules(string|object $classOrObject): RuleSet;
}
