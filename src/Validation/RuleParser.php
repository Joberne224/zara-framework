<?php

declare(strict_types=1);

namespace Zara\Validation;

use Zara\Validation\Interfaces\RuleInterface;
use Zara\Validation\Rule\BailRule;
use Zara\Validation\Rule\ConfirmedRule;
use Zara\Validation\Rule\EmailRule;
use Zara\Validation\Rule\InRule;
use Zara\Validation\Rule\MaxRule;
use Zara\Validation\Rule\MinRule;
use Zara\Validation\Rule\NumericRule;
use Zara\Validation\Rule\RegexRule;
use Zara\Validation\Rule\RequiredRule;

/**
 * Parses the compact string rule syntax ("bail|required|email|min:3") into
 * RuleInterface instances. Only understands the fixed set of built-in
 * keywords below — custom rules are never referenced by name here, they
 * are passed as RuleInterface instances directly to Validator instead.
 */
final class RuleParser
{
    /**
     * @return RuleInterface[]
     */
    public function parse(string $ruleString): array
    {
        $rules = [];

        foreach (explode('|', $ruleString) as $token) {
            $token = trim($token);

            if ($token === '') {
                continue;
            }

            $rules[] = $this->buildRule($token);
        }

        return $rules;
    }

    private function buildRule(string $token): RuleInterface
    {
        [$name, $parameter] = array_pad(explode(':', $token, 2), 2, null);

        return match ($name) {
            'bail' => new BailRule(),
            'required' => new RequiredRule(),
            'email' => new EmailRule(),
            'numeric' => new NumericRule(),
            'min' => new MinRule($this->numericParameter($name, $parameter)),
            'max' => new MaxRule($this->numericParameter($name, $parameter)),
            'in' => new InRule(explode(',', $parameter ?? '')),
            'regex' => new RegexRule($parameter ?? throw $this->missingParameter($name)),
            'confirmed' => new ConfirmedRule($parameter ?? throw $this->missingParameter($name)),
            default => throw new \InvalidArgumentException("Unknown validation rule \"{$name}\"."),
        };
    }

    private function numericParameter(string $name, ?string $parameter): int|float
    {
        if ($parameter === null || !is_numeric($parameter)) {
            throw $this->missingParameter($name);
        }

        return str_contains($parameter, '.') ? (float) $parameter : (int) $parameter;
    }

    private function missingParameter(string $name): \InvalidArgumentException
    {
        return new \InvalidArgumentException("Rule \"{$name}\" requires a parameter.");
    }
}