<?php

declare(strict_types=1);

namespace Zara\Validation;

use Zara\Validation\Exceptions\ValidationException;
use Zara\Validation\Interfaces\RuleInterface;
use Zara\Validation\Interfaces\ValidatorInterface;
use Zara\Validation\Rule\BailRule;

final class Validator implements ValidatorInterface
{
    public function __construct(
        private readonly RuleParser $parser,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, string|array<int, RuleInterface|string>> $rules
     */
    public function validate(array $data, array $rules): ValidationResult
    {
        $result = new ValidationResult();

        foreach ($rules as $field => $spec) {
            $value = $data[$field] ?? null;
            $fieldRules = $this->normalizeRules($spec);
            $bail = false;

            foreach ($fieldRules as $rule) {
                if ($rule instanceof BailRule) {
                    $bail = true;
                    break;
                }
            }

            foreach ($fieldRules as $rule) {
                if ($rule instanceof BailRule) {
                    continue;
                }

                if (!$rule->passes($value, $data)) {
                    $result->addError($field, $rule->message($field));

                    if ($bail) {
                        break;
                    }
                }
            }
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, string|array<int, RuleInterface|string>> $rules
     *
     * @throws ValidationException
     */
    public function validateOrFail(array $data, array $rules): ValidationResult
    {
        $result = $this->validate($data, $rules);

        if ($result->fails()) {
            throw new ValidationException($result);
        }

        return $result;
    }

    /**
     * @param string|array<int, RuleInterface|string> $spec
     *
     * @return RuleInterface[]
     */
    private function normalizeRules(string|array $spec): array
    {
        if (is_string($spec)) {
            return $this->parser->parse($spec);
        }

        $rules = [];

        foreach ($spec as $entry) {
            if ($entry instanceof RuleInterface) {
                $rules[] = $entry;
                continue;
            }

            array_push($rules, ...$this->parser->parse($entry));
        }

        return $rules;
    }
}
