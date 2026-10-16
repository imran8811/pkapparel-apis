<?php

declare(strict_types=1);

namespace App\Core;

use LogicException;

/**
 * Validates and normalises input. Rules are "required|string|max:120" or a list.
 *
 *   $data = Validator::make($request->all(), [
 *       'email'    => 'required|email',
 *       'quantity' => 'required|int|min:1',
 *       'mode'     => 'required|in:air,sea,own_forwarder',
 *       'website'  => 'nullable|url',
 *   ])->validate();
 *
 * Only fields listed in the rules are returned. Strings are trimmed and
 * int/numeric/bool/country values are cast to their proper type.
 */
final class Validator
{
    /** @var array<string, string> */
    private array $errors = [];

    /** @var array<string, mixed> */
    private array $validated = [];

    /**
     * @param array<string, mixed>               $data
     * @param array<string, string|list<string>> $rules
     */
    public function __construct(private readonly array $data, private readonly array $rules)
    {
    }

    /**
     * @param array<string, mixed>               $data
     * @param array<string, string|list<string>> $rules
     */
    public static function make(array $data, array $rules): self
    {
        return new self($data, $rules);
    }

    /**
     * @return array<string, mixed>
     * @throws ValidationException
     */
    public function validate(): array
    {
        if (!$this->passes()) {
            throw new ValidationException($this->errors);
        }

        return $this->validated;
    }

    public function passes(): bool
    {
        $this->errors = [];
        $this->validated = [];

        foreach ($this->rules as $field => $ruleSet) {
            $rules = is_array($ruleSet) ? $ruleSet : explode('|', $ruleSet);
            $present = array_key_exists($field, $this->data);
            $value = $this->data[$field] ?? null;
            if (is_string($value)) {
                $value = trim($value);
            }

            if ($value === null || $value === '' || $value === []) {
                if (in_array('required', $rules, true)) {
                    $this->errors[$field] = 'This field is required.';
                } elseif ($present) {
                    $this->validated[$field] = null;
                }
                continue;
            }

            foreach ($rules as $rule) {
                [$name, $argument] = array_pad(explode(':', $rule, 2), 2, null);
                if ($name === 'required' || $name === 'nullable') {
                    continue;
                }

                $error = $this->apply($name, $argument, $value);
                if ($error !== null) {
                    $this->errors[$field] = $error;
                    continue 2;
                }
            }

            $this->validated[$field] = $value;
        }

        return $this->errors === [];
    }

    /** @return array<string, string> */
    public function errors(): array
    {
        return $this->errors;
    }

    /** Applies one rule. May cast $value. Returns an error message, or null when the value is fine. */
    private function apply(string $rule, ?string $argument, mixed &$value): ?string
    {
        switch ($rule) {
            case 'string':
                return is_string($value) ? null : 'Must be text.';

            case 'email':
                return is_string($value) && strlen($value) <= 190 && filter_var($value, FILTER_VALIDATE_EMAIL) !== false
                    ? null
                    : 'Enter a valid email address.';

            case 'int':
                $int = filter_var($value, FILTER_VALIDATE_INT);
                if ($int === false || is_bool($value)) {
                    return 'Must be a whole number.';
                }
                $value = $int;

                return null;

            case 'numeric':
                if (!is_numeric($value)) {
                    return 'Must be a number.';
                }
                $value = $value + 0;

                return null;

            case 'bool':
                $bool = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                if ($bool === null) {
                    return 'Must be yes or no.';
                }
                $value = $bool;

                return null;

            case 'array':
                return is_array($value) ? null : 'Must be a list.';

            case 'min':
            case 'max':
                return $this->checkSize($rule, (int) $argument, $value);

            case 'in':
                $options = explode(',', (string) $argument);

                return is_scalar($value) && in_array((string) $value, $options, true)
                    ? null
                    : 'Choose one of: ' . implode(', ', $options) . '.';

            case 'url':
                return is_string($value)
                    && filter_var($value, FILTER_VALIDATE_URL) !== false
                    && preg_match('#^https?://#i', $value) === 1
                    ? null
                    : 'Enter a valid web address starting with https://';

            case 'regex':
                return is_string($value) && preg_match((string) $argument, $value) === 1 ? null : 'The format is not valid.';

            case 'country':
                if (!is_string($value) || preg_match('/^[A-Za-z]{2}$/', $value) !== 1) {
                    return 'Use a 2-letter country code.';
                }
                $value = strtoupper($value);

                return null;

            case 'phone':
                return is_string($value) && preg_match('/^\+?[0-9 ()\-]{6,20}$/', $value) === 1 ? null : 'Enter a valid phone number.';

            case 'date':
                $date = is_string($value) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $value) : false;

                return $date !== false && $date->format('Y-m-d') === $value ? null : 'Use a date like 2026-12-31.';

            case 'datetime':
                if (!is_string($value) || strtotime($value) === false) {
                    return 'Enter a valid date and time.';
                }
                $value = gmdate('Y-m-d H:i:s', (int) strtotime($value));

                return null;

            case 'slug':
                return is_string($value) && preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $value) === 1
                    ? null
                    : 'Use lowercase letters, numbers and hyphens only.';

            default:
                throw new LogicException("Unknown validation rule [{$rule}].");
        }
    }

    private function checkSize(string $rule, int $limit, mixed $value): ?string
    {
        $isMin = $rule === 'min';

        if (is_int($value) || is_float($value)) {
            $ok = $isMin ? $value >= $limit : $value <= $limit;

            return $ok ? null : ($isMin ? "Must be at least {$limit}." : "Must be at most {$limit}.");
        }

        if (is_array($value)) {
            $count = count($value);
            $ok = $isMin ? $count >= $limit : $count <= $limit;

            return $ok ? null : ($isMin ? "Choose at least {$limit}." : "Choose at most {$limit}.");
        }

        $length = mb_strlen((string) $value);
        $ok = $isMin ? $length >= $limit : $length <= $limit;

        return $ok ? null : ($isMin ? "Must be at least {$limit} characters." : "Must be at most {$limit} characters.");
    }
}
