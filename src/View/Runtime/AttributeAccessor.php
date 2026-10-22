<?php
declare(strict_types=1);

namespace Zara\View\Runtime;

use Zara\Core\Config\Env;

use Zara\View\Runtime\Exception\AttributeNotFoundException;
use Zara\View\Runtime\Exception\InaccessibleMemberException;
use Zara\View\Interfaces\MethodCallGuardInterface;

/**
 * Runtime counterpart to VariableExpressionResolver's `.` sugar.
 *
 * VariableExpressionResolver runs at *compile* time: it only ever sees the
 * identifier "object" as text, never the value it will hold at render
 * time. It cannot decide whether `object.name` means an array key, a
 * public property, or a getter call — that decision depends on what
 * `object` actually turns out to be, which is only known once the
 * template runs. So the resolver compiles `object.name` into a *call*
 * into this class instead of guessing a fixed `[...]` or `->` at compile
 * time, and defers the real dispatch to here, where the value exists.
 *
 * Two entry points, matching two distinct template syntaxes:
 *  - `object.name`          (no parentheses) -> get()  — read-only access
 *  - `object.method(a, b)`  (parentheses)     -> call() — explicit call
 *
 * Security note on call(): a public, non-abstract, non-magic method is
 * callable by default, matching this class's original design. An
 * MethodCallGuardInterface is configured by TemplateEngine and requires
 * #[TemplateCallable] on the target method — see AttributeMethodCallGuard.
 * Direct consumers that do not initialize TemplateEngine retain the
 * historical unrestricted behavior until they explicitly configure a guard.
 */
final class AttributeAccessor
{
    /**
     * Getter-convention prefixes tried, in order, when `object.name` does
     * not match a public property directly.
     */
    private const GETTER_PREFIXES = ['get', 'is', 'has'];

    private function __construct()
    {
        // Static utility class: no instance state, never instantiated.
    }

    /**
     * Resolves `object.name` (no explicit call in the template) against
     * an array or object subject.
     */
    public static function get(
        mixed $subject,
        string $attribute,
        ?MethodCallGuardInterface $guard = null
    ): mixed
    {   
        if (is_array($subject)) {
            return self::getFromArray($subject, $attribute);
        }

        if (is_object($subject)) {
            return self::getFromObject($subject, $attribute, $guard);
        }

        if (self::isDebugMode()) {
            throw new AttributeNotFoundException(sprintf(
                "Cannot read attribute '%s': expected an array or an object, got %s.",
                $attribute,
                get_debug_type($subject)
            ));
        }

        return null;
    }

    /**
     * Invokes `object.method(a, b)` — an explicit call written with
     * parentheses in the template.
     */
    public static function call(
        mixed $subject,
        string $method,
        array $arguments = [],
        ?MethodCallGuardInterface $guard = null
    ): mixed
    {
        if (!is_object($subject)) {
            if (self::isDebugMode()) {
                throw new AttributeNotFoundException(sprintf(
                    "Cannot call method '%s': expected an object, got %s.",
                    $method,
                    get_debug_type($subject)
                ));
            }
            return null;
        }

        if (self::guardAgainstMagicMember($method) === false) {
            return null;
        }

        if (!method_exists($subject, $method)) {
            if (self::isDebugMode()) {
                throw new AttributeNotFoundException(sprintf(
                    "Method '%s' does not exist on %s.",
                    $method,
                    $subject::class
                ));
            }
            return null;
        }

        $reflection = new \ReflectionMethod($subject, $method);

        if (!$reflection->isPublic()) {
            if (self::isDebugMode()) {
                throw new InaccessibleMemberException(sprintf(
                    "Method '%s' on %s is %s and cannot be called from a template.",
                    $method,
                    $subject::class,
                    $reflection->isPrivate() ? 'private' : 'protected'
                ));
            }
            return null;
        }

        if ($reflection->isAbstract()) {
            if (self::isDebugMode()) {
                throw new InaccessibleMemberException(sprintf(
                    "Method '%s' on %s is abstract and has no implementation to call.",
                    $method,
                    $subject::class
                ));
            }
            return null;
        }

        if ($guard !== null && !$guard->isAllowed($subject, $method)) {
            if (self::isDebugMode()) {
                throw new InaccessibleMemberException(sprintf(
                    "Method '%s' on %s is not marked #[TemplateCallable] and cannot be called from a template.",
                    $method,
                    $subject::class
                ));
            }
            return null;
        }

        return $subject->$method(...$arguments);
    }

    /**
     * Backs the `object?.name` template syntax (VariableExpressionResolver's
     * nullsafe chain segment). Unlike get(), a null $subject here is never
     * an error — even in debug mode — since `?.` exists specifically to
     * mark "this may legitimately be absent" at that point in the chain.
     * Any other non-array/non-object $subject still falls through to
     * get()'s normal debug-throw-or-null behavior: `?.` guards against
     * null specifically, not against every possible type mismatch.
     */
    public static function getOrNull(
        mixed $subject,
        string $attribute,
        ?MethodCallGuardInterface $guard = null
    ): mixed
    {
        return $subject === null ? null : self::get($subject, $attribute, $guard);
    }

    /**
     * Backs `object?.method(...)`. Same null-only short-circuit as
     * getOrNull() — see its docblock.
     */
    public static function callOrNull(
        mixed $subject,
        string $method,
        array $arguments = [],
        ?MethodCallGuardInterface $guard = null
    ): mixed
    {
        return $subject === null ? null : self::call($subject, $method, $arguments, $guard);
    }

    private static function getFromArray(array $subject, string $attribute): mixed
    {
        if (!array_key_exists($attribute, $subject)) {
            if (self::isDebugMode()) {
                throw new AttributeNotFoundException(sprintf(
                    "Array key '%s' does not exist.",
                    $attribute
                ));
            }
            return null;
        }

        return $subject[$attribute];
    }

    private static function getFromObject(
        object $subject,
        string $attribute,
        ?MethodCallGuardInterface $guard
    ): mixed
    {
        if (self::guardAgainstMagicMember($attribute) === false) {
            return null;
        }

        if (property_exists($subject, $attribute)) {
            $property = new \ReflectionProperty($subject, $attribute);

            if ($property->isPublic() && !$property->isStatic()) {
                return self::readPublicProperty($subject, $attribute);
            }
        }

        foreach (self::GETTER_PREFIXES as $prefix) {
            $getter = $prefix . ucfirst($attribute);

            if (self::isPubliclyCallable($subject, $getter, $guard)) {
                return $subject->$getter();
            }
        }

        if (property_exists($subject, $attribute)) {
            if (self::isDebugMode()) {
                throw new InaccessibleMemberException(sprintf(
                    "Property '%s' on %s is not directly accessible (private, protected, or static) and no public getter (%s) was found.",
                    $attribute,
                    $subject::class,
                    implode('/', array_map(
                        static fn (string $prefix): string => $prefix . ucfirst($attribute) . '()',
                        self::GETTER_PREFIXES
                    ))
                ));
            }
            return null;
        }

        if (self::isDebugMode()) {
            throw new AttributeNotFoundException(sprintf(
                "Neither a public property nor a public getter exists for attribute '%s' on %s.",
                $attribute,
                $subject::class
            ));
        }

        return null;
    }

    private static function readPublicProperty(object $subject, string $attribute): mixed
    {
        try {
            return $subject->$attribute;
        } catch (\Error $error) {
            if (self::isDebugMode()) {
                throw new InaccessibleMemberException(
                    sprintf(
                        "Property '%s' on %s is declared but not initialized.",
                        $attribute,
                        $subject::class
                    ),
                    previous: $error
                );
            }
            return null;
        }
    }

    private static function isPubliclyCallable(
        object $subject,
        string $method,
        ?MethodCallGuardInterface $guard
    ): bool
    {
        if (!method_exists($subject, $method)) {
            return false;
        }

        $reflection = new \ReflectionMethod($subject, $method);

        $callable = $reflection->isPublic()
            && !$reflection->isAbstract()
            && !$reflection->isStatic()
            && $reflection->getNumberOfRequiredParameters() === 0;

        if (!$callable || $guard === null || $guard->isAllowed($subject, $method)) {
            return $callable;
        }

        if (self::isDebugMode()) {
            throw new InaccessibleMemberException(sprintf(
                "Getter '%s' on %s is not marked #[TemplateCallable].",
                $method,
                $subject::class
            ));
        }

        return false;
    }

    private static function guardAgainstMagicMember(string $name): bool
    {
        if (str_starts_with($name, '__')) {
            if (self::isDebugMode()) {
                throw new InaccessibleMemberException(
                    "Access to magic member '{$name}' is not allowed in template expressions."
                );
            }
            return false;
        }

        return true;
    }

    /**
     * Determines whether exceptions should be thrown (Dev/Local environment)
     * or silenced with null (Production).
     */
    private static function isDebugMode(): bool
    {
        return Env::isDebug();
    }
}
