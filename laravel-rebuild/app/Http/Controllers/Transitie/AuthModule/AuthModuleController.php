<?php

namespace App\Http\Controllers\Transitie\AuthModule;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AccountProvisioningService;
use App\Services\AuthMfaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthModuleController extends Controller
{
    public function __construct(
        private readonly AuthMfaService $authMfaService,
        private readonly AccountProvisioningService $accountProvisioningService,
    ) {}

    public function postAuthLogin(Request $request): JsonResponse
    {
        $request->validate([
            'email' => ['required', 'email:rfc'],
            'password' => ['required', 'string', 'min:12'],
        ]);

        $result = $this->authMfaService->login(
            $request->string('email')->toString(),
            $request->string('password')->toString(),
            $request->ip(),
            $request->userAgent(),
        );

        return response()->json([
            'status' => 'ok',
            'module' => 'AuthModule',
            'scope' => 'MUST-AUTH-MFA',
            'user_id' => $result['user_id'],
            'session_token' => $result['session_token'],
            'expires_at' => $result['expires_at'],
            'mfa_required' => $result['mfa_required'],
        ]);
    }

    public function postAuthLogout(Request $request): JsonResponse
    {
        // Bearer token al gevalideerd door InternalApiAuth middleware —
        // gebruik het direct om de sessie in te trekken.
        $revoked = $this->authMfaService->logout((string) $request->bearerToken());

        return response()->json([
            'status' => 'ok',
            'module' => 'AuthModule',
            'scope' => 'MUST-AUTH-MFA',
            'revoked' => $revoked,
        ]);
    }

    public function postAuthMfaSetup(Request $request): JsonResponse
    {
        $request->validate([
            'user_id' => ['required', 'integer', 'min:1'],
            'password_confirmation' => ['required', 'string', 'min:12'],
        ]);

        if ((int) $request->user()->id !== $request->integer('user_id')) {
            throw ValidationException::withMessages([
                'user_id' => 'MFA-setup is alleen toegestaan voor uw eigen account.',
            ]);
        }

        $result = $this->authMfaService->setupMfa(
            $request->integer('user_id'),
            $request->string('password_confirmation')->toString(),
        );

        return response()->json([
            'status' => 'ok',
            'module' => 'AuthModule',
            'scope' => 'MUST-AUTH-MFA',
            'user_id' => $result['user_id'],
            'issuer' => $result['issuer'],
            'label' => $result['label'],
            'provisioning_secret_last4' => $result['provisioning_secret_last4'],
            'provisioning_secret' => $result['provisioning_secret'] ?? null,
            'recovery_codes' => $result['recovery_codes'],
        ], 201);
    }

    public function postAuthMfaVerify(Request $request): JsonResponse
    {
        $request->validate([
            'user_id' => ['required', 'integer', 'min:1'],
            'code' => ['required', 'string', 'min:6', 'max:20'],
        ]);

        $verified = $this->authMfaService->verifyMfa(
            $request->integer('user_id'),
            $request->string('code')->toString(),
        );

        if (! $verified) {
            throw ValidationException::withMessages([
                'code' => 'MFA-code is ongeldig.',
            ]);
        }

        return response()->json([
            'status' => 'ok',
            'module' => 'AuthModule',
            'scope' => 'MUST-AUTH-MFA',
            'verified' => true,
        ]);
    }

    public function postInternalAuthAccounts(Request $request): JsonResponse
    {
        $actor = $request->user();

        if (! in_array((string) $actor->role, ['owner', 'manager'], true)) {
            return response()->json([
                'message' => 'Onvoldoende rechten voor account-aanmaak.',
            ], 403);
        }

        $validated = $request->validate([
            'password_confirmation' => ['required', 'string', 'min:12'],
            'name' => ['required', 'string', 'max:255'],
            'full_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'email' => ['required', 'email:rfc', 'max:254'],
            'role' => ['required', 'string', 'in:manager,employee,boekhouder'],
            'team_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'is_active' => ['sometimes', 'boolean'],
            'employment_start' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'employment_end' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:employment_start'],
        ]);

        // Uniqueness-check via email_index_hash i.p.v. de encrypted email-kolom.
        // De `unique:users,email` rule werkt niet met encrypted kolommen omdat
        // elke encryptie een ander ciphertext oplevert (non-deterministic).
        $emailHash = hash('sha256', strtolower(trim((string) $validated['email'])));
        $existingUser = User::where('email_index_hash', $emailHash)
            ->whereNull('deleted_at')
            ->exists();

        if ($existingUser) {
            throw ValidationException::withMessages([
                'email' => 'Dit e-mailadres is al in gebruik.',
            ]);
        }

        // Re-auth: wachtwoord bevestigen voor aanmaak van accounts (F-12)
        // De sessie-actor heeft geen wachtwoord geladen → los ophalen uit DB
        $actorPassword = User::query()->select('id', 'password')->find($actor->id)?->password;
        if (! $actorPassword || ! Hash::check($validated['password_confirmation'], $actorPassword)) {
            throw ValidationException::withMessages([
                'password_confirmation' => 'Wachtwoordbevestiging is onjuist.',
            ]);
        }

        try {
            $created = $this->accountProvisioningService->create($validated, (int) $actor->id);
        } catch (\RuntimeException $e) {
            // Requirement 5.4: outbox-fout bij welkomstmail → HTTP 500
            // met machine-leesbare code `WELCOME_EMAIL_FAILED`. De DB-
            // transactie in de service heeft de account-aanmaak reeds
            // teruggedraaid.
            if ($e->getMessage() === 'WELCOME_EMAIL_FAILED') {
                return response()->json([
                    'error' => 'Welkomstmail kon niet worden gequeued; account-aanmaak teruggedraaid.',
                    'code' => 'WELCOME_EMAIL_FAILED',
                ], 500);
            }

            throw $e;
        }

        return response()->json([
            'status' => 'ok',
            'module' => 'AuthModule',
            'scope' => 'MUST-AUTH-ACCOUNT-CREATE',
            'account' => $created,
        ], 201);
    }
}
