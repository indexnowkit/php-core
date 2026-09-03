<?php

declare(strict_types=1);

namespace IndexNowKit\Attribute;

/**
 * How an accessor is read off objects the plain DSL cannot see into: Active-Record models whose attributes live in
 * an array behind `__get()` (Eloquent), CMS records with a `get_field()` API. Registered once per process with
 * {@see ParamExtractor::registerReader()}; consulted for every single-segment accessor before the DSL
 * (property, getter, is/has method) so a reader can claim `slug` and leave `isPublished()` to the DSL.
 *
 * "Implement" tier (docs/bc.md): methods are not added without a major version.
 */
interface SubjectReaderInterface
{
    /**
     * Whether $subject is one of the objects this reader knows (an Eloquent model). Such an object passed as a route
     * parameter stays an object (route model binding) even when it is Stringable.
     */
    public function supports(object $subject): bool;

    /**
     * Whether this reader owns $accessor on $subject. Must be cheap and side-effect free; false hands the accessor
     * to the next reader and finally to the DSL, whose "no property, getter or method" error is what a typo gets.
     */
    public function has(object $subject, string $accessor): bool;

    /**
     * @throws \IndexNowKit\Exception\ConfigurationException when the value cannot be read after has() said yes
     */
    public function read(object $subject, string $accessor): mixed;
}
