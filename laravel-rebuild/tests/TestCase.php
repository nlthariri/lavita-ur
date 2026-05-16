<?php

namespace Tests;

use App\Models\AuthSession;
use App\Models\MfaSecret;
use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Str;

abstract class TestCase extends BaseTestCase
{
    /**
     * Maak een actieve AuthSession voor de gegeven gebruiker en geef de
     * Bearer-token terug die je kunt meesturen in requests.
     */
    protected function createBearerToken(User $user): string
    {
        $token = Str::random(64);
        $tokenHash = hash('sha256', $token);

        AuthSession::create([
            'user_id' => $user->id,
            'session_token_hash' => $tokenHash,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'TestSuite/1.0',
            'last_seen_at' => now(),
            'expires_at' => now()->addHour(),
        ]);

        if (in_array((string) $user->role, ['owner', 'manager'], true)) {
            MfaSecret::updateOrCreate(
                ['user_id' => $user->id],
                [
                    'secret_encrypted' => 'test-secret',
                    'issuer' => 'La Vita Urenregistratie',
                    'label' => $user->email.' (La Vita Urenregistratie)',
                    'verified_at' => now(),
                    'disabled_at' => null,
                    'rotated_at' => now(),
                ]
            );
        }

        return $token;
    }

    /**
     * Stuur een GET-request met Bearer-authenticatie.
     */
    protected function getWithAuth(User $user, string $uri, array $headers = []): \Illuminate\Testing\TestResponse
    {
        $token = $this->createBearerToken($user);

        return $this->get($uri, array_merge(['Authorization' => 'Bearer '.$token], $headers));
    }

    /**
     * Stuur een POST-request met Bearer-authenticatie.
     */
    protected function postWithAuth(User $user, string $uri, array $data = []): \Illuminate\Testing\TestResponse
    {
        $token = $this->createBearerToken($user);

        return $this->postJson($uri, $data, ['Authorization' => 'Bearer '.$token]);
    }
}

