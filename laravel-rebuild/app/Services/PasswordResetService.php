<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class PasswordResetService
{
    private const TTL_HOURS = 24;

    public function __construct(
        private readonly EmailOutboxService $emailOutboxService,
    ) {}

    /**
     * Genereer een stateless HMAC-signed reset-token.
     * Structuur: base64url(json({userId, exp, sig}))
     * Signature: HMAC-SHA256(userId.exp.passwordHash, APP_KEY)
     */
    public function createToken(int $userId): string
    {
        $user = User::select('id', 'password')->findOrFail($userId);

        $exp = time() + (self::TTL_HOURS * 3600);
        $sig = $this->sign("{$user->id}.{$exp}.{$user->password}");

        $payload = ['userId' => $user->id, 'exp' => $exp, 'sig' => $sig];

        return rtrim(strtr(base64_encode(json_encode($payload)), '+/', '-_'), '=');
    }

    /**
     * Stuur een reset-e-mail naar het opgegeven adres (timing-safe: ook bij
     * onbekend adres wordt een generieke respons gegeven met vergelijkbare
     * response-tijd).
     */
    public function requestReset(string $email): void
    {
        $user = User::where('email_index_hash', hash('sha256', strtolower(trim($email))))
            ->where('is_active', true)
            ->first();

        if (! $user) {
            // Timing-safe: simuleer de verwerkingstijd van een echte
            // token-generatie + outbox-dispatch om timing-aanvallen te
            // voorkomen. usleep(50-150ms random) maakt het onmogelijk
            // om via response-tijd te bepalen of het adres bestaat.
            usleep(random_int(50_000, 150_000));

            return;
        }

        $token = $this->createToken($user->id);
        $appUrl = config('app.url', 'https://lavita.nl');
        $link = $appUrl.'/wachtwoord-reset?token='.urlencode($token);
        $naam = $user->full_name ?? $user->name;

        $this->emailOutboxService->dispatch([
            'idempotency_key' => 'pwd-reset-'.$user->id.'-'.floor(time() / 3600),
            'organization_id' => $user->organization_id,
            'user_id' => $user->id,
            'recipient' => $user->email,
            'subject' => 'Wachtwoord opnieuw instellen',
            'body_text' => "Beste {$naam},\n\nUw resetlink: {$link}\n\nDeze link is 24 uur geldig.",
            'body_html' => "<p>Beste {$naam},</p><p><a href=\"{$link}\">Wachtwoord opnieuw instellen</a></p><p>Deze link is 24 uur geldig.</p>",
            'type' => 'password_reset',
        ]);
    }

    /**
     * Verifieer token en sla nieuw wachtwoord op.
     */
    public function resetPassword(string $encodedToken, string $newPassword): void
    {
        $payload = $this->decodeToken($encodedToken);

        $user = User::select('id', 'password', 'is_active')->find($payload['userId'] ?? 0);

        if (! $user || ! $user->is_active) {
            throw ValidationException::withMessages([
                'token' => 'Resetlink is ongeldig of verlopen.',
            ]);
        }

        // Verifieer signature (timing-safe)
        $expected = $this->sign("{$user->id}.{$payload['exp']}.{$user->password}");
        if (! hash_equals($expected, (string) ($payload['sig'] ?? ''))) {
            throw ValidationException::withMessages([
                'token' => 'Resetlink is ongeldig of verlopen.',
            ]);
        }

        if ((int) ($payload['exp'] ?? 0) < time()) {
            throw ValidationException::withMessages([
                'token' => 'Resetlink is verlopen.',
            ]);
        }

        if (Hash::check($newPassword, $user->password)) {
            throw ValidationException::withMessages([
                'password' => 'Kies een nieuw wachtwoord dat verschilt van het huidige wachtwoord.',
            ]);
        }

        $user->update(['password' => Hash::make($newPassword)]);
    }

    private function sign(string $value): string
    {
        return hash_hmac('sha256', $value, config('app.key'));
    }

    private function decodeToken(string $encodedToken): array
    {
        try {
            $json = base64_decode(strtr($encodedToken, '-_', '+/'));
            $payload = json_decode($json, true, 3, JSON_THROW_ON_ERROR);

            if (! is_array($payload)) {
                throw new \RuntimeException('Invalid payload.');
            }

            return $payload;
        } catch (\Throwable) {
            throw ValidationException::withMessages([
                'token' => 'Resetlink is ongeldig of verlopen.',
            ]);
        }
    }
}
