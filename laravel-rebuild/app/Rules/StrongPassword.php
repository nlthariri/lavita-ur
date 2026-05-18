<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Wachtwoordbeleid conform NCSC-richtlijnen en Requirement 6.11:
 * - Minimaal 12 tekens
 * - Minimaal 1 hoofdletter
 * - Minimaal 1 kleine letter
 * - Minimaal 1 cijfer
 * - Minimaal 1 speciaal teken (!@#$%^&*()-_=+[]{}|;:',.<>?/`~")
 * - Maximaal 128 tekens (voorkomt bcrypt-truncatie en DoS)
 *
 * Enterprise-standaard: OWASP ASVS 2.1.7, NCSC wachtwoordbeleid 2024.
 */
class StrongPassword implements ValidationRule
{
    private const MIN_LENGTH = 12;

    private const MAX_LENGTH = 128;

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            $fail('Wachtwoord moet een tekst zijn.');

            return;
        }

        if (mb_strlen($value) < self::MIN_LENGTH) {
            $fail('Wachtwoord moet minimaal 12 tekens lang zijn.');

            return;
        }

        if (mb_strlen($value) > self::MAX_LENGTH) {
            $fail('Wachtwoord mag maximaal 128 tekens lang zijn.');

            return;
        }

        if (! preg_match('/[A-Z]/', $value)) {
            $fail('Wachtwoord moet minimaal één hoofdletter bevatten.');

            return;
        }

        if (! preg_match('/[a-z]/', $value)) {
            $fail('Wachtwoord moet minimaal één kleine letter bevatten.');

            return;
        }

        if (! preg_match('/[0-9]/', $value)) {
            $fail('Wachtwoord moet minimaal één cijfer bevatten.');

            return;
        }

        if (! preg_match('/[^A-Za-z0-9]/', $value)) {
            $fail('Wachtwoord moet minimaal één speciaal teken bevatten.');

            return;
        }
    }
}
