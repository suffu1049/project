<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Server-side input validation.
 *
 * Client-side validation is a convenience only; every rule that protects data
 * integrity is enforced here. Rules are expressed as a pipe-delimited string,
 * e.g. 'required|string|max:190'.
 */
final class Validator
{
    private array $errors = [];

    private array $validated = [];

    private function __construct(
        private readonly array $data,
        private readonly array $rules,
        private readonly array $labels = [],
    ) {
        $this->run();
    }

    public static function make(array $data, array $rules, array $labels = []): self
    {
        return new self($data, $rules, $labels);
    }

    public function fails(): bool
    {
        return $this->errors !== [];
    }

    public function passes(): bool
    {
        return $this->errors === [];
    }

    public function errors(): array
    {
        return $this->errors;
    }

    public function firstError(): ?string
    {
        foreach ($this->errors as $fieldErrors) {
            return $fieldErrors[0] ?? null;
        }

        return null;
    }

    /**
     * Only the fields that were present and passed validation.
     */
    public function validated(): array
    {
        return $this->validated;
    }

    private function run(): void
    {
        foreach ($this->rules as $field => $ruleString) {
            $value = $this->data[$field] ?? null;
            $rules = is_array($ruleString) ? $ruleString : explode('|', $ruleString);
            $label = $this->labels[$field] ?? ucfirst(str_replace('_', ' ', $field));

            $nullable = in_array('nullable', $rules, true);
            $required = in_array('required', $rules, true);

            $isEmpty = $value === null || $value === '' || (is_array($value) && $value === []);

            if ($required && $isEmpty) {
                $this->addError($field, sprintf('%s is required.', $label));
                continue;
            }

            if ($isEmpty) {
                if ($nullable) {
                    $this->validated[$field] = null;
                }
                continue;
            }

            foreach ($rules as $rule) {
                if (in_array($rule, ['required', 'nullable'], true)) {
                    continue;
                }

                [$name, $parameter] = array_pad(explode(':', $rule, 2), 2, null);

                if (!$this->applyRule($name, $parameter, $value, $field, $label)) {
                    break;
                }
            }

            if (!isset($this->errors[$field])) {
                $this->validated[$field] = $value;
            }
        }
    }

    private function applyRule(string $name, ?string $parameter, mixed $value, string $field, string $label): bool
    {
        $ok = match ($name) {
            'string' => is_string($value),
            'email'  => is_string($value) && filter_var($value, FILTER_VALIDATE_EMAIL) !== false,
            'integer' => is_int($value) || (is_string($value) && preg_match('/^-?\d+$/', $value) === 1),
            'numeric' => is_numeric($value),
            'boolean' => in_array($value, [true, false, 0, 1, '0', '1'], true),
            'array'  => is_array($value),
            'in'     => in_array((string) $value, explode(',', (string) $parameter), true),
            'min'    => $this->compareLength($value, (float) $parameter, '>='),
            'max'    => $this->compareLength($value, (float) $parameter, '<='),
            'between' => $this->between($value, (string) $parameter),
            'regex'  => is_string($value) && @preg_match($parameter ?? '', $value) === 1,
            'confirmed' => $this->confirmed($value, $field),
            default  => true,
        };

        if (!$ok) {
            $this->addError($field, sprintf($this->message($name), $label, $parameter));
        }

        return $ok;
    }

    private function compareLength(mixed $value, float $limit, string $operator): bool
    {
        $length = $this->length($value);

        return $operator === '>=' ? $length >= $limit : $length <= $limit;
    }

    private function between(mixed $value, string $parameter): bool
    {
        [$min, $max] = array_pad(explode(',', $parameter), 2, '0');
        $length = $this->length($value);

        return $length >= (float) $min && $length <= (float) $max;
    }

    /**
     * Numeric value for numeric inputs, otherwise character/array length.
     * Uses mbstring when available so multibyte text is measured correctly.
     */
    private function length(mixed $value): float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        if (is_array($value)) {
            return (float) count($value);
        }

        $string = (string) $value;

        return (float) (function_exists('mb_strlen') ? mb_strlen($string) : strlen($string));
    }

    private function confirmed(mixed $value, string $field): bool
    {
        return $value === ($this->data[$field . '_confirmation'] ?? null);
    }

    private function message(string $rule): string
    {
        return match ($rule) {
            'string'    => '%s must be text.',
            'email'     => '%s must be a valid email address.',
            'integer'   => '%s must be a whole number.',
            'numeric'   => '%s must be a number.',
            'boolean'   => '%s must be true or false.',
            'array'     => '%s must be a list.',
            'in'        => '%s is not a valid option.',
            'min'       => '%s must be at least %s characters.',
            'max'       => '%s must not exceed %s characters.',
            'between'   => '%s is out of the allowed range (%s).',
            'regex'     => '%s has an invalid format.',
            'confirmed' => '%s confirmation does not match.',
            default     => '%s is invalid.',
        };
    }

    private function addError(string $field, string $message): void
    {
        $this->errors[$field][] = $message;
    }
}
