<?php

namespace App\Services;

use App\Models\AuthSession;
use App\Models\MfaRecoveryCode;
use App\Models\MfaSecret;
use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthMfaService
{
    public function __construct(private readonly TotpService $totpService)
    {
    }

    public function login(string $email, string $password, ?string $ipAddress, ?string $userAgent): array
    {
        $user = User::query()->where('email', strtolower(trim($email)))->first();

        if (!$user || !Hash::check($password, $user->password)) {
            throw ValidationException::withMessages([
                'email' => 'Ongeldige inloggegevens.',
            ]);
        }

        if (!$user->is_active) {
            throw ValidationException::withMessages([
                'email' => 'Account is gedeactiveerd.',
            ]);
        }

        $token = Str::random(64);
        $tokenHash = hash('sha256', $token);
        $expiresAt = now()->addHours(12);

        DB::transaction(function () use ($user, $tokenHash, $ipAddress, $userAgent, $expiresAt): void {
            AuthSession::query()->create([
                'user_id' => $user->id,
                'session_token_hash' => $tokenHash,
                'ip_address' => $ipAddress,
                'user_agent' => $userAgent,
                'last_seen_at' => now(),
                'expires_at' => $expiresAt,
            ]);
        });

        $mfaSecret = MfaSecret::query()->where('user_id', $user->id)->first();
        $mfaVerified = $mfaSecret && $mfaSecret->verified_at !== null && $mfaSecret->disabled_at === null;
        $mfaRequiredRole = in_array((string) $user->role, ['owner', 'manager'], true);

        return [
            'user_id' => $user->id,
            'session_token' => $token,
            'expires_at' => $expiresAt->toISOString(),
            'mfa_required' => $mfaRequiredRole && !$mfaVerified,
        ];
    }

    public function logout(string $sessionToken): bool
    {
        $tokenHash = hash('sha256', $sessionToken);

        $updated = AuthSession::query()
            ->where('session_token_hash', $tokenHash)
            ->whereNull('revoked_at')
            ->update([
                'revoked_at' => now(),
                'last_seen_at' => now(),
                'updated_at' => now(),
            ]);

        return $updated > 0;
    }

    public function setupMfa(int $userId, string $passwordConfirmation): array
    {
        $user = User::query()->find($userId);
        if (!$user) {
            throw ValidationException::withMessages([
                'user_id' => 'Gebruiker niet gevonden.',
            ]);
        }

        if (!Hash::check($passwordConfirmation, $user->password)) {
            throw ValidationException::withMessages([
                'password_confirmation' => 'Wachtwoordbevestiging ongeldig.',
            ]);
        }

        $secret = $this->totpService->generateSecret();
        $encryptedSecret = Crypt::encryptString($secret);
        $issuer = 'La Vita Urenregistratie';
        $label = sprintf('%s (%s)', $user->email, $issuer);

        $plainCodes = $this->generateRecoveryCodes();

        DB::transaction(function () use ($userId, $encryptedSecret, $issuer, $label, $plainCodes): void {
            $existing = MfaSecret::query()->where('user_id', $userId)->first();

            if ($existing) {
                $existing->update([
                    'secret_encrypted' => $encryptedSecret,
                    'issuer' => $issuer,
                    'label' => $label,
                    'verified_at' => null,
                    'rotated_at' => now(),
                    'disabled_at' => null,
                ]);
            } else {
                MfaSecret::query()->create([
                    'user_id' => $userId,
                    'secret_encrypted' => $encryptedSecret,
                    'issuer' => $issuer,
                    'label' => $label,
                    'rotated_at' => now(),
                ]);
            }

            // Verwijder oude recovery codes en sla nieuwe gehasht op
            MfaRecoveryCode::query()->where('user_id', $userId)->delete();
            foreach ($plainCodes as $plainCode) {
                MfaRecoveryCode::query()->create([
                    'user_id' => $userId,
                    'code_hash' => Hash::make($plainCode),
                ]);
            }
        });

        $response = [
            'user_id' => $userId,
            'issuer' => $issuer,
            'label' => $label,
            'provisioning_secret_last4' => substr($secret, -4),
            'recovery_codes' => $plainCodes,
        ];

        if (app()->environment(['local', 'testing'])) {
            $response['provisioning_secret'] = $secret;
        }

        return $response;
    }

    public function verifyMfa(int $userId, string $code): bool
    {
        $secretRecord = MfaSecret::query()
            ->where('user_id', $userId)
            ->whereNull('disabled_at')
            ->first();

        if (!$secretRecord) {
            throw ValidationException::withMessages([
                'user_id' => 'MFA is niet geconfigureerd voor deze gebruiker.',
            ]);
        }

        $secret = Crypt::decryptString($secretRecord->secret_encrypted);

        // Probeer TOTP-verificatie (precies 6 cijfers)
        if (strlen($code) === 6 && ctype_digit($code)) {
            if (!$this->totpService->verify($secret, $code)) {
                return false;
            }

            $secretRecord->update(['verified_at' => now()]);
            return true;
        }

        // Probeer recovery code (niet-6-cijferig: alfanumeriek 10 tekens)
        $recoveryCode = MfaRecoveryCode::query()
            ->where('user_id', $userId)
            ->whereNull('used_at')
            ->get()
            ->first(fn ($rc) => Hash::check($code, $rc->code_hash));

        if (!$recoveryCode) {
            return false;
        }

        DB::transaction(function () use ($recoveryCode, $secretRecord): void {
            $recoveryCode->update(['used_at' => now()]);
            if ($secretRecord->verified_at === null) {
                $secretRecord->update(['verified_at' => now()]);
            }
        });

        return true;
    }

    private function generateRecoveryCodes(int $count = 8): array
    {
        $codes = [];
        for ($i = 0; $i < $count; $i++) {
            // 10 willekeurige uppercase alfanumerieke tekens (bijv. A3B7C2D1E9)
            $codes[] = strtoupper(Str::random(10));
        }
        return $codes;
    }

    public function codeForTesting(string $provisioningSecret, ?int $timestamp = null): string
    {
        if (!app()->environment('testing')) {
            throw new \RuntimeException('codeForTesting is alleen toegestaan in test-omgeving.');
        }

        return $this->totpService->getCode($provisioningSecret, $timestamp ?? time());
    }
}
