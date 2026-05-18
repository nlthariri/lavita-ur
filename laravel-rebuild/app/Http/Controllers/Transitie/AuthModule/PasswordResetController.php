<?php

namespace App\Http\Controllers\Transitie\AuthModule;

use App\Http\Controllers\Controller;
use App\Rules\StrongPassword;
use App\Services\PasswordResetService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class PasswordResetController extends Controller
{
    private const GENERIC_MSG = 'Als dit e-mailadres bestaat, ontvang je een resetlink.';

    public function __construct(
        private readonly PasswordResetService $passwordResetService,
    ) {}

    /**
     * POST /auth/password-reset/request
     */
    public function postRequest(Request $request): JsonResponse
    {
        try {
            $data = $request->validate([
                'email' => 'required|email|max:254',
            ]);

            $this->passwordResetService->requestReset($data['email']);
        } catch (ValidationException $e) {
            return response()->json([
                'ok' => false,
                'errors' => $e->errors(),
            ], 422);
        } catch (\Throwable) {
            // Generiek om geen info te lekken
        }

        return response()->json([
            'ok' => true,
            'message' => self::GENERIC_MSG,
        ], 200);
    }

    /**
     * POST /auth/password-reset/confirm
     */
    public function postConfirm(Request $request): JsonResponse
    {
        try {
            $data = $request->validate([
                'token' => 'required|string',
                'password' => ['required', 'string', new StrongPassword],
            ]);

            $this->passwordResetService->resetPassword($data['token'], $data['password']);

            return response()->json(['ok' => true, 'message' => 'Wachtwoord succesvol gewijzigd.'], 200);
        } catch (ValidationException $e) {
            return response()->json([
                'ok' => false,
                'errors' => $e->errors(),
            ], 422);
        } catch (\Throwable) {
            return response()->json([
                'ok' => false,
                'message' => 'Resetlink is ongeldig of verlopen.',
            ], 422);
        }
    }
}
