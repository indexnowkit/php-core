<?php

declare(strict_types=1);

namespace IndexNowKit\Url;

use IndexNowKit\Attribute\AttributeReaderInterface;
use IndexNowKit\Attribute\ParamExtractor;
use IndexNowKit\Event;
use IndexNowKit\Exception\ConfigurationException;

/**
 * Default resolver: reads #[IndexNow] and delegates to the attribute's custom resolver or the framework router.
 */
final class AttributeUrlResolver implements UrlResolverInterface
{
    public function __construct(
        private readonly AttributeReaderInterface $reader,
        private readonly ?RouteUrlResolverInterface $router = null,
        private readonly ?ResolverLocatorInterface $locator = null,
    ) {}

    public function resolve(object $subject, Event $event): iterable
    {
        $attribute = $this->reader->read($subject);
        if ($attribute === null) {
            return [];
        }
        if ($attribute->resolver !== null) {
            if ($this->locator === null) {
                throw new ConfigurationException(\sprintf('%s uses #[IndexNow(resolver: "%s")] but no resolver locator is configured.', $subject::class, $attribute->resolver));
            }

            return $this->locator->get($attribute->resolver)->resolve($subject, $event);
        }
        if ($this->router === null) {
            throw new ConfigurationException(\sprintf('%s uses #[IndexNow(route: "%s")] but no router bridge is configured. Use a framework adapter or #[IndexNow(resolver: ...)].', $subject::class, (string) $attribute->route));
        }

        return $this->router->generate((string) $attribute->route, ParamExtractor::extract($subject, $attribute->params), $attribute->locales);
    }
}
