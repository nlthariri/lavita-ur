<?php

/**
 * FIX: Verwijder ghost-users die zijn achtergebleven van mislukte account-aanmaak pogingen.
 *
 * Bezoek: https://ur.la-vitatrading.nl/fix-ghost-users.php?token=lavita-ghost-2026
 * VERWIJDER NA GEBRUIK!
 */

$token = 'lavita-ghost-2026';

if (($_GET['token'] ?? '') !== $token) {
    http_response_code(403);
    echo 'Toegang geweigerd.';
    exit;
}

require __DIR__ . '/../vendor/autoload.php';

$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

echo "<pre>\n";
echo "=== Ghost Users Fix ===\n\n";

// Toon alle soft-deleted users
$ghostUsers = DB::table('users')
    ->whereNotNull('deleted_at')
    ->get(['id', 'name', 'email_index_hash', 'role', 'created_at', 'deleted_at']);

echo "Soft-deleted users gevonden: " . $ghostUsers->count() . "\n\n";

foreach ($ghostUsers as $user) {
    echo "  ID: {$user->id} | Naam: {$user->name} | Rol: {$user->role}\n";
    echo "  Hash: {$user->email_index_hash}\n";
    echo "  Aangemaakt: {$user->created_at} | Verwijderd: {$user->deleted_at}\n\n";
}

// Verwijder ze permanent (force delete)
if ($ghostUsers->count() > 0) {
    $ids = $ghostUsers->pluck('id')->toArray();
    
    // Verwijder gerelateerde records eerst
    DB::table('auth_sessions')->whereIn('user_id', $ids)->delete();
    DB::table('mfa_secrets')->whereIn('user_id', $ids)->delete();
    DB::table('mfa_recovery_codes')->whereIn('user_id', $ids)->delete();
    
    // Force delete de users
    $deleted = DB::table('users')->whereIn('id', $ids)->delete();
    echo "✓ {$deleted} ghost-user(s) permanent verwijderd.\n\n";
}

// Toon ook users die NIET soft-deleted zijn maar mogelijk duplicaten
echo "--- Alle actieve users ---\n";
$activeUsers = DB::table('users')
    ->whereNull('deleted_at')
    ->get(['id', 'name', 'role', 'is_active', 'email_index_hash', 'created_at']);

foreach ($activeUsers as $user) {
    $status = $user->is_active ? 'actief' : 'inactief';
    echo "  ID: {$user->id} | {$user->name} | {$user->role} | {$status} | {$user->created_at}\n";
}

echo "\n=== KLAAR ===\n";
echo "⚠️  VERWIJDER DIT BESTAND NA GEBRUIK!\n";
echo "</pre>";
