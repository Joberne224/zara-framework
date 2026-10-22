<?php
declare(strict_types=1);

namespace Zara\Container\Attributes;

use Attribute;

/**
 * Explicit injection directive for a parameter (constructor, method, or
 * closure) or property. Useful when the YAML service file does not cover a
 * case, or to keep wiring information close to the code without external config.
 *
 * Deliberately reuses the YAML `arguments` syntax instead of introducing a
 * second reference language:
 *   - '@service.id'      -> resolves one service (see ArgumentResolver)
 *   - '%parameter.id%'   -> resolves a parameter (with recursive interpolation)
 *   - '!tagged:tag.name' -> resolves all services tagged 'tag.name'
 *
 * A bare #[Inject] (without a value) forces resolution by the declared type
 * of the parameter or property:
 *   - on a parameter, it overrides any default value, which would otherwise
 *     take precedence over implicit autowiring;
 *   - on a property, it is the only way to enable autowiring, since properties
 *     are never resolved implicitly.
 *
 * Global resolution priority (constructor/method):
 *   1. Explicit argument from the service file (YAML)
 *   2. #[Inject] on the parameter
 *   3. implicit autowiring by type
 *   4. parameter default value
 *
 * @example
 *   final class ReportGenerator
 *   {
 *       public function __construct(
 *           #[Inject('@Zara\Log\AuditLogger')] private LoggerInterface $logger,
 *           #[Inject('%app.debug%')] private bool $debug,
 *           #[Inject('!tagged:report.formatters')] private array $formatters,
 *           #[Inject] private Clock $clock, // forced by type, even if $clock has a default
 *       ) {}
 *
 *       #[Inject]
 *       private CacheInterface $cache; // property injection is not handled by YAML
 *   }
 */
#[Attribute(Attribute::TARGET_PARAMETER | Attribute::TARGET_PROPERTY)]
final class Inject
{
    public function __construct(
        public readonly ?string $value = null,
    ) {
    }
}
