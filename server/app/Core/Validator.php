<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Small rule-based validator. Usage:
 *   $v = new Validator($request->all(), [
 *       'first_name' => 'required|name|max:80',
 *       'email'      => 'required|email',
 *       'password'   => 'required|password',
 *       'password_confirmation' => 'same:password',
 *   ]);
 *   if ($v->fails()) { ... $v->errors() ... }
 */
final class Validator
{
    /** @var array<string, string> */
    private array $errors = [];

    public function __construct(private readonly array $data, array $rules, private readonly array $labels = [])
    {
        foreach ($rules as $field => $ruleString) {
            $value = $data[$field] ?? null;
            $value = is_string($value) ? trim($value) : $value;
            foreach (explode('|', $ruleString) as $rule) {
                [$name, $arg] = array_pad(explode(':', $rule, 2), 2, null);
                $message = $this->check($field, $name, $arg, $value);
                if ($message !== null) {
                    $this->errors[$field] = $message;
                    break;
                }
            }
        }
    }

    public function fails(): bool
    {
        return $this->errors !== [];
    }

    /** @return array<string, string> */
    public function errors(): array
    {
        return $this->errors;
    }

    public function addError(string $field, string $message): void
    {
        $this->errors[$field] = $message;
    }

    private function label(string $field): string
    {
        return $this->labels[$field] ?? ucfirst(str_replace('_', ' ', $field));
    }

    private function check(string $field, string $rule, ?string $arg, mixed $value): ?string
    {
        $empty = $value === null || $value === '' || $value === [];
        if ($rule !== 'required' && $rule !== 'accepted' && $empty) {
            return null; // optional and absent
        }
        $label = $this->label($field);
        return match ($rule) {
            'required' => $empty ? "{$label} is required." : null,
            'accepted' => in_array($value, ['1', 'on', 'yes', true], true) ? null : "Please accept the {$label}.",
            'string'   => is_string($value) ? null : "{$label} is invalid.",
            'name'     => is_string($value) && preg_match("/^[\\p{L}][\\p{L} .'-]{0,79}$/u", $value) ? null : "Please enter a valid {$label}.",
            'email'    => is_string($value) && filter_var($value, FILTER_VALIDATE_EMAIL) && mb_strlen($value) <= 191 ? null : 'Please enter a valid email address.',
            'mobile'   => normalize_mobile(is_string($value) ? $value : '') !== null ? null : 'Please enter a valid 10-digit Indian mobile number.',
            'password' => !(is_string($value) && mb_strlen($value) >= 8 && mb_strlen($value) <= 128
                          && preg_match('/[A-Za-z]/', $value) && preg_match('/\d/', $value))
                          ? 'Password must be at least 8 characters and include a letter and a number.'
                          : (self::isCommonPassword($value) ? 'This password is too common and easy to guess. Please choose another.' : null),
            'same'     => $value === ($this->data[$arg] ?? null) ? null : 'Passwords do not match.',
            'min'      => mb_strlen((string) $value) >= (int) $arg ? null : "{$label} must be at least {$arg} characters.",
            'max'      => mb_strlen((string) $value) <= (int) $arg ? null : "{$label} must be at most {$arg} characters.",
            'digits'   => is_string($value) && preg_match('/^\d{' . (int) $arg . '}$/', $value) ? null : "{$label} must be {$arg} digits.",
            'in'       => in_array((string) $value, explode(',', (string) $arg), true) ? null : "{$label} is invalid.",
            default    => throw new \InvalidArgumentException("Unknown rule {$rule}"),
        };
    }

    /**
     * Passwords that pass the length/letter/number rule but are among the first
     * guesses in any attack. Compared case-insensitively, with trailing
     * symbols removed ("Password@123" counts as "password123").
     */
    private const COMMON_PASSWORDS = [
        'password1', 'password12', 'password123', 'password1234', 'passw0rd', 'p@ssw0rd', 'pass1234', 'pass@123', 'admin123', 'admin1234',
        'administrator1', 'welcome1', 'welcome123', 'welcome@123', 'qwerty123', 'qwerty12', 'qwerty1234', 'qwertyuiop1', 'abc12345', 'abcd1234',
        'abc123456', 'a1b2c3d4', '1q2w3e4r', '1qaz2wsx', 'zaq12wsx', 'asdf1234', 'asdfgh123', 'iloveyou1', 'iloveyou123', 'letmein1',
        'letmein123', 'test1234', 'test12345', 'testing123', 'trustno1', 'sunshine1', 'princess1', 'football1', 'baseball1', 'monkey123',
        'dragon123', 'master123', 'shadow123', 'superman1', 'batman123', 'india123', 'india@123', 'india1234', 'mumbai123', 'surat123',
        'krishna123', 'ganesh123', 'sairam123', 'omsairam1', 'jaishreeram1', 'hello123', 'hello1234', 'changeme1', 'login123',
        '12345678a', '123456789a', 'a12345678', 'a123456789', 'rental123', 'demo12345', 'user1234', 'computer1', 'internet1', 'google123',
    ];

    private static function isCommonPassword(string $value): bool
    {
        $v = strtolower(trim($value));
        return in_array($v, self::COMMON_PASSWORDS, true)
            || in_array(str_replace('@', '', $v), self::COMMON_PASSWORDS, true)
            || in_array(rtrim($v, '!@#$%^&*.'), self::COMMON_PASSWORDS, true);
    }
}
