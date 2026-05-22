<?php

namespace App\Services;

use App\Models\AtwViolation;
use App\Models\Objection;
use App\Models\User;
use App\Models\WorkEntry;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class WorkEntriesService
{
    private const ALLOWED_ROLES = ['owner', 'manager'];

    /**
     * Work entry types die als verlof/ziekte/feestdag worden behandeld.
     * Bij deze types is start_time/end_time optioneel (default 00:00/23:59)
     * en wordt pause_minutes geforceerd naar 0.
     *
     * Requirements: 7.1, 7.2, 7.3
     */
    private const ABSENCE_TYPES = ['SICK', 'LEAVE', 'HOLIDAY'];

    /**
     * ATW art. 5:4 — bij een aaneengesloten dienst van meer dan 5,5 uur
     * (`LONG_SHIFT_THRESHOLD_MINUTES = 330`) is minimaal 30 minuten pauze
     * verplicht. De waarde was tijdelijk 60 minuten in eerdere iteraties;
     * task 5.1 zet deze terug naar de wettelijke ondergrens van 30 min.
     *
     * Requirements: 4.1
     */
    private const MIN_PAUSE_FOR_LONG_SHIFT_MINUTES = 30;

    private const LONG_SHIFT_THRESHOLD_MINUTES = 330;

    private const PAUSE_REQUIRED_CODE = 'ATW_PAUSE_REQUIRED';

    private const PAUSE_REQUIRED_MESSAGE = 'Bij meer dan 5,5 uur werken is minimaal 30 minuten pauze verplicht.';

    /**
     * Lijst met velden die via PATCH op `/api/internal/work-entries/{id}`
     * gemuteerd mogen worden. Dient voor documentatie en discoverability;
     * de daadwerkelijke validatie van het request-body vindt plaats in
     * `WorkEntriesModuleController` (task 4.3).
     *
     * Requirements: 1.4
     */
    public const UPDATABLE_FIELDS = [
        'entry_date',
        'start_time',
        'end_time',
        'pause_minutes',
        'type',
        'note',
        'project_id',
        'cost_center_id',
    ];

    public function __construct(
        private readonly EmailOutboxService $emailOutboxService,
        private readonly AtwService $atwService,
        private readonly ProjectsService $projectsService,
        private readonly CostCentersService $costCentersService,
        private readonly AuditService $auditService,
    ) {}

    public function create(array $input, int $registrarId): array
    {
        $registrar = User::findOrFail($registrarId);
        $type = strtoupper((string) ($input['type'] ?? 'WORK'));
        $isAbsence = in_array($type, self::ABSENCE_TYPES, true);

        // Employees mogen SICK/LEAVE voor zichzelf registreren, maar niet HOLIDAY.
        // Requirements: 7.1, 7.2
        if ($registrar->role === 'employee') {
            if (! $isAbsence) {
                $this->assertAllowedRegistrar($registrar);
            }
            if ($type === 'HOLIDAY') {
                throw new HttpResponseException(
                    response()->json([
                        'error' => 'Medewerkers mogen geen feestdagen registreren.',
                        'code' => 'INVALID_TYPE_FOR_ROLE',
                        'errors' => ['type' => ['Medewerkers mogen geen feestdagen registreren.']],
                    ], 422)
                );
            }
            // Employee mag alleen voor zichzelf (employee_id = zichzelf)
            if ($isAbsence && isset($input['employee_id']) && (int) $input['employee_id'] !== (int) $registrar->id) {
                $this->assertAllowedRegistrar($registrar);
            }
            // Bij SICK/LEAVE voor employee is note verplicht (Req 7.2)
            if ($isAbsence && in_array($type, ['SICK', 'LEAVE'], true)) {
                $note = isset($input['note']) ? trim((string) $input['note']) : '';
                if ($note === '') {
                    throw ValidationException::withMessages([
                        'note' => 'Toelichting is verplicht bij ziekte of verlof.',
                    ]);
                }
            }
        } else {
            $this->assertAllowedRegistrar($registrar);
        }

        // Bij absence-types: default start_time/end_time en forceer pause=0
        // Requirements: 7.1
        if ($isAbsence) {
            $input['start_time'] = $input['start_time'] ?? '00:00';
            $input['end_time'] = $input['end_time'] ?? '23:59';
            $input['pause_minutes'] = 0;
        }

        // Voor employee die SICK/LEAVE registreert: employee_id = zichzelf
        if ($registrar->role === 'employee' && $isAbsence) {
            $input['employee_id'] = (int) $registrar->id;
        }

        $employee = User::findOrFail($input['employee_id']);
        $this->assertSameOrganization($registrar, $employee);
        $this->assertTeamScope($registrar, $employee);

        $startAt = Carbon::createFromFormat(
            'Y-m-d H:i',
            $input['entry_date'].' '.$input['start_time'],
            'Europe/Amsterdam'
        )->utc();

        $endAt = Carbon::createFromFormat(
            'Y-m-d H:i',
            $input['entry_date'].' '.$input['end_time'],
            'Europe/Amsterdam'
        )->utc();

        if ($endAt->lte($startAt)) {
            throw ValidationException::withMessages([
                'end_time' => 'Eindtijd moet na begintijd liggen.',
            ]);
        }

        $grossMinutes = (int) $startAt->diffInMinutes($endAt);
        $pauseMinutes = (int) ($input['pause_minutes'] ?? 0);

        if ($grossMinutes > self::LONG_SHIFT_THRESHOLD_MINUTES
            && $pauseMinutes < self::MIN_PAUSE_FOR_LONG_SHIFT_MINUTES
        ) {
            // Schrijf één `ATW_VIOLATION_BLOCKED` audit-event voordat we
            // de 422 gooien. De expliciete pauze-check loopt naast
            // `throwOnCriticalSignals` (die voor `validateProposedShift`-
            // signalen al audit produceert), dus we moeten hier inline
            // auditen om aan Requirement 4.7 te voldoen.
            // Requirements: 4.7
            $this->auditService->record([
                'organization_id' => (int) $registrar->organization_id,
                'actor_id' => (int) $registrar->id,
                'action' => 'ATW_VIOLATION_BLOCKED',
                'target_type' => 'work_entry',
                'target_id' => '',
                'before_data' => [
                    'signal_type' => 'PAUSE_REQUIRED',
                    'current_minutes' => $pauseMinutes,
                    'threshold_minutes' => self::MIN_PAUSE_FOR_LONG_SHIFT_MINUTES,
                    'employee_id' => (int) $employee->id,
                ],
                'after_data' => null,
            ]);

            throw $this->buildPauseRequiredException($grossMinutes, $pauseMinutes);
        }

        $netMinutes = max(0, $grossMinutes - $pauseMinutes);
        $entryDate = Carbon::parse($input['entry_date'])->toDateString();
        $atwValidation = $this->atwService->validateProposedShift([
            'employee_id' => (int) $employee->id,
            'entry_date' => $entryDate,
            'start_time' => $input['start_time'],
            'end_time' => $input['end_time'],
            'pause_minutes' => $pauseMinutes,
        ], (int) $registrar->id);

        // Werp 422 met `{ error, code, errors }`-formaat zodra het
        // proposed-shift een kritiek ATW-signaal oplevert (DAILY_LIMIT,
        // WEEKLY_LIMIT, REST_PERIOD, PAUSE_REQUIRED). Warnings en het
        // 16-weken gemiddelde blijven non-blocking en leveren een
        // `atw_violations`-record op via `dispatchSignalsForCreatedEntry`
        // verderop. Bewust vóór de DB-transactie zodat een geblokkeerde
        // poging geen werkregel achterlaat in de database. De helper
        // schrijft per kritiek signaal één `ATW_VIOLATION_BLOCKED`-
        // audit-event vanwege de meegegeven context.
        // Requirements: 4.1, 4.3, 4.4, 4.5, 4.7
        $this->atwService->throwOnCriticalSignals(
            $atwValidation['signals'] ?? [],
            [
                'organization_id' => (int) $registrar->organization_id,
                'actor_id' => (int) $registrar->id,
                'employee_id' => (int) $employee->id,
                'target_id' => null,
            ],
        );

        $projectId = $this->resolveOptionalId($input, 'project_id');
        $costCenterId = $this->resolveOptionalId($input, 'cost_center_id');

        if ($projectId !== null) {
            $this->projectsService->assertUsableForWorkEntry(
                $projectId,
                (int) $registrar->organization_id,
            );
        }

        if ($costCenterId !== null) {
            $this->costCentersService->assertUsableForWorkEntry(
                $costCenterId,
                (int) $registrar->organization_id,
            );
        }

        return DB::transaction(function () use ($input, $registrar, $employee, $startAt, $endAt, $pauseMinutes, $netMinutes, $entryDate, $atwValidation, $projectId, $costCenterId): array {
            $type = strtoupper((string) ($input['type'] ?? 'WORK'));

            // Verlof/ziekte ingediend door een employee zelf → in afwachting (niet automatisch goedgekeurd).
            // Manager/owner die het registreert → direct goedgekeurd.
            $isLeaveType = in_array($type, ['SICK', 'LEAVE', 'HOLIDAY'], true);
            $isEmployeeSelfSubmit = $isLeaveType && (int) $registrar->id === (int) $employee->id && (string) $registrar->role === 'employee';
            $isFinalized = $isEmployeeSelfSubmit ? false : true;

            $entryData = [
                'organization_id' => $registrar->organization_id,
                'employee_id' => $employee->id,
                'team_id' => $employee->team_id,
                'registered_by_id' => $registrar->id,
                'entry_date' => $entryDate,
                'start_at' => $startAt,
                'end_at' => $endAt,
                'pause_minutes' => $pauseMinutes,
                'net_minutes' => $netMinutes,
                'type' => $type,
                'note' => isset($input['note']) ? strip_tags(substr(trim($input['note']), 0, 500)) : null,
                'project_id' => $projectId,
                'cost_center_id' => $costCenterId,
                'is_finalized' => $isFinalized,
            ];

            // leave_type_id alleen meesturen als de kolom bestaat in de database.
            // Dit voorkomt crashes bij gefaseerde deployments waar de migratie
            // nog niet is uitgevoerd. Zodra de migratie draait, werkt dit automatisch.
            if (\Illuminate\Support\Facades\Schema::hasColumn('work_entries', 'leave_type_id')) {
                $entryData['leave_type_id'] = ($type === 'LEAVE' && isset($input['leave_type_id']))
                    ? (int) $input['leave_type_id']
                    : null;
            }

            try {
                $entry = WorkEntry::create($entryData);
            } catch (UniqueConstraintViolationException $e) {
                throw ValidationException::withMessages([
                    'entry_date' => 'Er bestaat al een werkregel voor deze medewerker op ' . $entryDate . ' met dezelfde begintijd. Pas de begintijd aan of bewerk de bestaande regel.',
                ]);
            }

            // Alleen "vastgesteld"-mail sturen als de entry direct is goedgekeurd.
            // Verlofmeldingen in afwachting krijgen pas een mail bij goedkeuring.
            if ($isFinalized) {
                $this->emailOutboxService->dispatch([
                    'idempotency_key' => 'work-entry-finalized-'.$entry->id,
                    'organization_id' => (int) $registrar->organization_id,
                    'user_id' => (int) $employee->id,
                    'recipient' => (string) $employee->email,
                    'subject' => 'Uren zijn vastgesteld',
                    'body_text' => 'Uw uren voor '.$entryDate.' zijn vastgesteld. Netto minuten: '.$netMinutes.'.',
                    'body_html' => '<p>Uw uren voor <strong>'.$entryDate.'</strong> zijn vastgesteld.</p><p>Netto minuten: <strong>'.$netMinutes.'</strong>.</p>',
                    'type' => 'work_entry_finalized',
                ], [
                    'actor_id' => (int) $registrar->id,
                    'organization_id' => (int) $registrar->organization_id,
                ]);
            }

            $this->atwService->dispatchSignalsForCreatedEntry(
                $employee,
                $registrar,
                $atwValidation['signals'] ?? [],
                (int) $entry->id,
            );

            return $this->toApiArray($entry);
        });
    }

    /**
     * Werk een bestaande, niet-soft-deleted werkregel bij. Identieke
     * autorisatie- en scope-controles als `create`, plus een 409
     * `OBJECTION_OPEN` zodra er een actief bezwaar bestaat. Velden die
     * niet in `$input` voorkomen, behouden de huidige waarde van de
     * werkregel; ontbrekende `start_time`/`end_time`/`pause_minutes`
     * worden via Europe/Amsterdam afgeleid uit de bestaande UTC-stamps.
     *
     * Recomputeert `net_minutes` op basis van de effectieve waarden en
     * herhaalt de pauze-regel (>5,5u bruto vereist ≥30 min pauze; deze
     * drempel is per task 5.1 verlaagd van 60 naar 30 min conform ATW
     * art. 5:4). Schrijft een `WORK_ENTRY_UPDATED` audit-event met
     * before/after-snapshot.
     *
     * Requirements: 1.4, 1.7, 1.8, 1.9
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed> Canonieke API-representatie van de bijgewerkte werkregel.
     */
    public function update(int $id, array $input, int $registrarId): array
    {
        $registrar = User::findOrFail($registrarId);

        $entry = $this->findActiveEntry($id);
        $this->assertEntryInRegistrarOrg($registrar, $entry);

        $employee = User::findOrFail($entry->employee_id);

        // Bepaal het effectieve type (input of bestaand).
        $type = array_key_exists('type', $input)
            ? strtoupper((string) $input['type'])
            : strtoupper((string) $entry->type);
        $isAbsence = in_array($type, self::ABSENCE_TYPES, true);

        // Autorisatie: employee mag alleen SICK/LEAVE voor zichzelf updaten
        // Requirements: 7.1, 7.2
        if ($registrar->role === 'employee') {
            if (! $isAbsence) {
                $this->assertAllowedRegistrar($registrar);
            }
            if ($type === 'HOLIDAY') {
                throw new HttpResponseException(
                    response()->json([
                        'error' => 'Medewerkers mogen geen feestdagen registreren.',
                        'code' => 'INVALID_TYPE_FOR_ROLE',
                        'errors' => ['type' => ['Medewerkers mogen geen feestdagen registreren.']],
                    ], 422)
                );
            }
            // Employee mag alleen eigen entries bewerken
            if ((int) $entry->employee_id !== (int) $registrar->id) {
                $this->assertAllowedRegistrar($registrar);
            }
            // Bij SICK/LEAVE voor employee is note verplicht (Req 7.2)
            if ($isAbsence && in_array($type, ['SICK', 'LEAVE'], true)) {
                $note = array_key_exists('note', $input) ? trim((string) ($input['note'] ?? '')) : trim((string) ($entry->note ?? ''));
                if ($note === '') {
                    throw ValidationException::withMessages([
                        'note' => 'Toelichting is verplicht bij ziekte of verlof.',
                    ]);
                }
            }
        } else {
            $this->assertAllowedRegistrar($registrar);
        }

        $this->assertTeamScope($registrar, $employee);
        $this->assertNoOpenObjection((int) $entry->id);

        // Effectieve waarden samenstellen: input wint, anders de bestaande
        // waarde uit de werkregel. Tijden worden in Europe/Amsterdam
        // weergegeven (consistent met `create`); de opslag blijft UTC.
        $entryDate = isset($input['entry_date'])
            ? Carbon::parse($input['entry_date'])->toDateString()
            : $entry->entry_date->toDateString();

        // Bij absence-types: default start_time/end_time en forceer pause=0
        // Requirements: 7.1
        if ($isAbsence) {
            $startTime = $input['start_time'] ?? '00:00';
            $endTime = $input['end_time'] ?? '23:59';
            $pauseMinutes = 0;
        } else {
            $startTime = $input['start_time']
                ?? $entry->start_at->copy()->setTimezone('Europe/Amsterdam')->format('H:i');

            $endTime = $input['end_time']
                ?? $entry->end_at->copy()->setTimezone('Europe/Amsterdam')->format('H:i');

            $pauseMinutes = array_key_exists('pause_minutes', $input)
                ? (int) $input['pause_minutes']
                : (int) $entry->pause_minutes;
        }

        $startAt = Carbon::createFromFormat(
            'Y-m-d H:i',
            $entryDate.' '.$startTime,
            'Europe/Amsterdam'
        )->utc();

        $endAt = Carbon::createFromFormat(
            'Y-m-d H:i',
            $entryDate.' '.$endTime,
            'Europe/Amsterdam'
        )->utc();

        if ($endAt->lte($startAt)) {
            throw ValidationException::withMessages([
                'end_time' => 'Eindtijd moet na begintijd liggen.',
            ]);
        }

        $grossMinutes = (int) $startAt->diffInMinutes($endAt);

        if ($grossMinutes > self::LONG_SHIFT_THRESHOLD_MINUTES
            && $pauseMinutes < self::MIN_PAUSE_FOR_LONG_SHIFT_MINUTES
        ) {
            // Schrijf één `ATW_VIOLATION_BLOCKED` audit-event voordat we
            // de 422 gooien — analoog aan `create`. Op het update-pad is
            // de werkregel-id wél bekend, dus zetten we die als
            // `target_id`.
            // Requirements: 4.7
            $this->auditService->record([
                'organization_id' => (int) $registrar->organization_id,
                'actor_id' => (int) $registrar->id,
                'action' => 'ATW_VIOLATION_BLOCKED',
                'target_type' => 'work_entry',
                'target_id' => (string) $entry->id,
                'before_data' => [
                    'signal_type' => 'PAUSE_REQUIRED',
                    'current_minutes' => $pauseMinutes,
                    'threshold_minutes' => self::MIN_PAUSE_FOR_LONG_SHIFT_MINUTES,
                    'employee_id' => (int) $employee->id,
                ],
                'after_data' => null,
            ]);

            throw $this->buildPauseRequiredException($grossMinutes, $pauseMinutes);
        }

        $netMinutes = max(0, $grossMinutes - $pauseMinutes);

        // ATW-validatie van het effectieve proposed-shift, analoog aan
        // `create`. Kritieke signalen leveren 422 op vóór de DB-
        // transactie; non-blocking signalen (`WEEKLY_WARNING`,
        // `SIXTEEN_WEEK_AVERAGE`) blijven non-blocking. Deze taak (5.4)
        // richt zich op de fail-fast-route; de signaal-dispatch bij
        // update kan in een latere taak worden toegevoegd.
        // Requirements: 4.1, 4.3, 4.4, 4.5, 4.7
        $atwValidation = $this->atwService->validateProposedShift([
            'employee_id' => (int) $employee->id,
            'entry_date' => $entryDate,
            'start_time' => $startTime,
            'end_time' => $endTime,
            'pause_minutes' => $pauseMinutes,
        ], (int) $registrar->id);

        $this->atwService->throwOnCriticalSignals(
            $atwValidation['signals'] ?? [],
            [
                'organization_id' => (int) $registrar->organization_id,
                'actor_id' => (int) $registrar->id,
                'employee_id' => (int) $employee->id,
                'target_id' => (int) $entry->id,
            ],
        );

        // project_id / cost_center_id: gebruik de input-waarde wanneer expliciet
        // meegegeven (inclusief expliciet ontkoppelen via null/0/''), anders de
        // huidige FK behouden. Daarna her-valideren wanneer er een waarde is.
        $projectId = array_key_exists('project_id', $input)
            ? $this->resolveOptionalId($input, 'project_id')
            : ($entry->project_id !== null ? (int) $entry->project_id : null);

        $costCenterId = array_key_exists('cost_center_id', $input)
            ? $this->resolveOptionalId($input, 'cost_center_id')
            : ($entry->cost_center_id !== null ? (int) $entry->cost_center_id : null);

        if ($projectId !== null) {
            $this->projectsService->assertUsableForWorkEntry(
                $projectId,
                (int) $registrar->organization_id,
            );
        }

        if ($costCenterId !== null) {
            $this->costCentersService->assertUsableForWorkEntry(
                $costCenterId,
                (int) $registrar->organization_id,
            );
        }

        $note = array_key_exists('note', $input)
            ? ($input['note'] === null
                ? null
                : strip_tags(substr(trim((string) $input['note']), 0, 500)))
            : $entry->note;

        $beforeSnapshot = $this->snapshotEntry($entry);

        return DB::transaction(function () use (
            $entry,
            $registrar,
            $employee,
            $entryDate,
            $startAt,
            $endAt,
            $pauseMinutes,
            $netMinutes,
            $type,
            $note,
            $projectId,
            $costCenterId,
            $beforeSnapshot
        ): array {
            $entry->forceFill([
                'entry_date' => $entryDate,
                'start_at' => $startAt,
                'end_at' => $endAt,
                'pause_minutes' => $pauseMinutes,
                'net_minutes' => $netMinutes,
                'type' => $type,
                'note' => $note,
                'project_id' => $projectId,
                'cost_center_id' => $costCenterId,
            ])->save();

            $fresh = $entry->fresh();

            $this->auditService->record([
                'organization_id' => (int) $registrar->organization_id,
                'actor_id' => (int) $registrar->id,
                'action' => 'WORK_ENTRY_UPDATED',
                'target_type' => 'work_entry',
                'target_id' => (string) $entry->id,
                'before_data' => $beforeSnapshot,
                'after_data' => $this->snapshotEntry($fresh),
            ]);

            // Notificatiemail naar de betrokken medewerker. De idempotency-
            // key bevat de updated_at-timestamp zodat opeenvolgende edits
            // elk een aparte mail opleveren, terwijl een herhaalde dispatch
            // binnen dezelfde update geen duplicate aanmaakt.
            // Requirements: 1.9
            $this->emailOutboxService->dispatch([
                'idempotency_key' => 'work-entry-updated-'.$entry->id.'-'.$fresh->updated_at->timestamp,
                'organization_id' => (int) $registrar->organization_id,
                'user_id' => (int) $employee->id,
                'recipient' => (string) $employee->email,
                'subject' => 'Uren zijn aangepast',
                'body_text' => 'Uw uren voor '.$entryDate.' zijn aangepast. Netto minuten: '.$netMinutes.'.',
                'body_html' => '<p>Uw uren voor <strong>'.$entryDate.'</strong> zijn aangepast.</p><p>Netto minuten: <strong>'.$netMinutes.'</strong>.</p>',
                'type' => 'work_entry_updated',
            ], [
                'actor_id' => (int) $registrar->id,
                'organization_id' => (int) $registrar->organization_id,
            ]);

            return $this->toApiArray($fresh);
        });
    }

    /**
     * Soft-delete een werkregel (`deleted_at = now()`). Identieke
     * autorisatie- en scope-controles als `create`, plus 409
     * `OBJECTION_OPEN` zodra er een actief bezwaar bestaat. Schrijft
     * een `WORK_ENTRY_DELETED` audit-event met before-snapshot.
     *
     * Requirements: 1.7, 1.8, 1.9
     */
    public function delete(int $id, int $registrarId): void
    {
        $registrar = User::findOrFail($registrarId);
        $this->assertAllowedRegistrar($registrar);

        $entry = $this->findActiveEntry($id);
        $this->assertEntryInRegistrarOrg($registrar, $entry);

        $employee = User::findOrFail($entry->employee_id);
        $this->assertTeamScope($registrar, $employee);

        $this->assertNoOpenObjection((int) $entry->id);

        $beforeSnapshot = $this->snapshotEntry($entry);

        DB::transaction(function () use ($entry, $registrar, $employee, $beforeSnapshot): void {
            // SoftDeletes trait zet `deleted_at` automatisch via `delete()`.
            $entry->delete();

            $this->auditService->record([
                'organization_id' => (int) $registrar->organization_id,
                'actor_id' => (int) $registrar->id,
                'action' => 'WORK_ENTRY_DELETED',
                'target_type' => 'work_entry',
                'target_id' => (string) $entry->id,
                'before_data' => $beforeSnapshot,
            ]);

            // Notificatiemail naar de betrokken medewerker. De entry-id is
            // uniek per werkregel en wordt na soft-delete niet hergebruikt,
            // dus de idempotency-key heeft hier geen extra timestamp nodig.
            // Requirements: 1.9
            $entryDate = $entry->entry_date instanceof Carbon
                ? $entry->entry_date->toDateString()
                : (string) $entry->entry_date;

            $this->emailOutboxService->dispatch([
                'idempotency_key' => 'work-entry-deleted-'.$entry->id,
                'organization_id' => (int) $registrar->organization_id,
                'user_id' => (int) $employee->id,
                'recipient' => (string) $employee->email,
                'subject' => 'Uren zijn verwijderd',
                'body_text' => 'Uw uren voor '.$entryDate.' zijn verwijderd.',
                'body_html' => '<p>Uw uren voor <strong>'.$entryDate.'</strong> zijn verwijderd.</p>',
                'type' => 'work_entry_deleted',
            ], [
                'actor_id' => (int) $registrar->id,
                'organization_id' => (int) $registrar->organization_id,
            ]);

            // Markeer alle nog actieve `atw_violations` voor deze
            // werkregel als achterhaald: de bron-werkregel bestaat niet
            // meer, dus eventuele dag-/week-/rust-/pauze-signalen die op
            // basis daarvan zijn vastgelegd zijn niet langer geldig. We
            // overschrijven nooit een al ingevulde `superseded_at`-stamp,
            // zodat de eerste markering leidend blijft.
            // Requirements: 1.7
            AtwViolation::query()
                ->where('work_entry_id', $entry->id)
                ->whereNull('superseded_at')
                ->update(['superseded_at' => now()]);
        });
    }

    /**
     * Vind een actieve werkregel binnen de scope van de aanvrager en
     * retourneer de canonieke API-representatie. Bedoeld voor
     * `GET /api/internal/work-entries/{id}` (task 4.3).
     *
     * Toegangsregels (zie design.md §Components and Interfaces en
     * Requirements 1.1 t/m 1.3):
     *
     * - `owner` en `boekhouder`: mogen iedere werkregel binnen de eigen
     *   organisatie inzien. Werkregels uit een andere organisatie
     *   leveren 404 op via `findActiveEntry` + `assertEntryInRegistrarOrg`,
     *   zodat het bestaan van de werkregel niet over organisatiegrenzen
     *   heen lekt.
     * - `manager`: mag uitsluitend werkregels van het eigen team inzien.
     *   Een ontbrekende `team_id` op de manager of een mismatch met
     *   `entry.team_id` levert 403 `FORBIDDEN_TEAM_SCOPE`.
     * - `employee`: mag uitsluitend de eigen werkregels inzien. Een
     *   werkregel van een andere medewerker levert 403 `FORBIDDEN_OWNER_SCOPE`.
     *
     * Requirements: 1.1, 1.2, 1.3
     *
     * @return array<string, mixed> Canonieke API-representatie van de werkregel.
     */
    public function find(int $id, int $requesterId): array
    {
        $requester = User::findOrFail($requesterId);

        $entry = $this->findActiveEntry($id);
        $this->assertEntryInRegistrarOrg($requester, $entry);

        $role = (string) $requester->role;

        if ($role === 'owner' || $role === 'boekhouder') {
            return $this->toApiArray($entry);
        }

        if ($role === 'manager') {
            if ($requester->team_id === null
                || (int) $requester->team_id !== (int) $entry->team_id
            ) {
                throw new HttpResponseException(
                    response()->json([
                        'error' => 'Manager mag alleen werkregels van het eigen team inzien.',
                        'code' => 'FORBIDDEN_TEAM_SCOPE',
                    ], 403)
                );
            }

            return $this->toApiArray($entry);
        }

        if ($role === 'employee') {
            if ((int) $requester->id !== (int) $entry->employee_id) {
                throw new HttpResponseException(
                    response()->json([
                        'error' => 'Medewerker mag alleen eigen werkregels inzien.',
                        'code' => 'FORBIDDEN_OWNER_SCOPE',
                    ], 403)
                );
            }

            return $this->toApiArray($entry);
        }

        // Onbekende of niet-toegestane rol: weiger expliciet.
        throw new HttpResponseException(
            response()->json([
                'error' => 'Geen toegang tot werkregels.',
                'code' => 'FORBIDDEN_ROLE',
            ], 403)
        );
    }

    public function list(int $registrarId, array $filters = []): array
    {
        $registrar = User::findOrFail($registrarId);

        // Employees mogen hun eigen werkregels opvragen (Req 1.1);
        // owner/manager mogen bredere lijsten zien. Boekhouder mag
        // alles binnen de organisatie lezen (read-only, Req 3.3).
        if (! in_array($registrar->role, ['owner', 'manager', 'employee', 'boekhouder'], true)) {
            $this->assertAllowedRegistrar($registrar);
        }

        $query = WorkEntry::where('organization_id', $registrar->organization_id)
            ->orderBy('entry_date', 'desc')
            ->orderBy('start_at', 'desc');

        // Scope-beperkingen per rol:
        // - manager: alleen eigen team (ALTIJD scopen, ook als team_id null)
        // - employee: alleen eigen werkregels
        if ($registrar->role === 'manager') {
            if ($registrar->team_id) {
                $query->where('team_id', $registrar->team_id);
            } else {
                // Manager zonder team ziet NIETS (niet alles)
                $query->where('team_id', -1);
            }
        } elseif ($registrar->role === 'employee') {
            $query->where('employee_id', $registrar->id);
        }

        if (! empty($filters['employee_id'])) {
            $query->where('employee_id', (int) $filters['employee_id']);
        }

        if (! empty($filters['from'])) {
            $query->whereDate('entry_date', '>=', $filters['from']);
        }

        if (! empty($filters['to'])) {
            $query->whereDate('entry_date', '<=', $filters['to']);
        }

        return $query->limit(200)->get()->map(fn (WorkEntry $e) => [
            'id' => $e->id,
            'employee_id' => $e->employee_id,
            'entry_date' => $e->entry_date->toDateString(),
            'start_at' => $e->start_at->toIso8601String(),
            'end_at' => $e->end_at->toIso8601String(),
            'net_minutes' => $e->net_minutes,
            'type' => $e->type,
            'project_id' => $e->project_id !== null ? (int) $e->project_id : null,
            'cost_center_id' => $e->cost_center_id !== null ? (int) $e->cost_center_id : null,
            'is_finalized' => $e->is_finalized,
        ])->all();
    }

    /**
     * Bouw een 422 `ValidationException` met de pauze-foutcode en
     * meta-informatie zoals afgesproken in design.md §Foutcodes en
     * Requirements 4.1.
     *
     * De response volgt het uniforme error-formaat
     * `{ error, code, errors, meta }`. We overrulen
     * `ValidationException::$response` zodat het uitgaande JSON-payload
     * onafhankelijk is van Laravel's default validation-responder en de
     * frontend de `code` en `meta` betrouwbaar kan parsen.
     */
    private function buildPauseRequiredException(int $grossMinutes, int $pauseMinutes): ValidationException
    {
        $exception = ValidationException::withMessages([
            'pause_minutes' => self::PAUSE_REQUIRED_MESSAGE,
        ]);

        $exception->response = response()->json([
            'error' => self::PAUSE_REQUIRED_MESSAGE,
            'code' => self::PAUSE_REQUIRED_CODE,
            'errors' => [
                'pause_minutes' => [self::PAUSE_REQUIRED_MESSAGE],
            ],
            'meta' => [
                'gross_minutes' => $grossMinutes,
                'pause_minutes' => $pauseMinutes,
                'threshold_minutes' => self::LONG_SHIFT_THRESHOLD_MINUTES,
                'required_pause_minutes' => self::MIN_PAUSE_FOR_LONG_SHIFT_MINUTES,
            ],
        ], 422);

        return $exception;
    }

    /**
     * Normaliseer optionele FK-velden uit de request-input.
     *
     * Behandelt `null`, lege string, `0` en `'0'` als "geen koppeling" en
     * retourneert `null` zodat de werkregel zonder project- of kostenplaats-
     * koppeling kan worden opgeslagen. Geldige waarden worden teruggegeven
     * als `int`.
     */
    private function resolveOptionalId(array $input, string $key): ?int
    {
        if (! array_key_exists($key, $input)) {
            return null;
        }

        $value = $input[$key];

        if ($value === null || $value === '' || $value === 0 || $value === '0') {
            return null;
        }

        return (int) $value;
    }

    /**
     * Vind een werkregel die nog niet soft-deleted is. Met de SoftDeletes
     * trait filtert Eloquent automatisch op `deleted_at IS NULL`, dus we
     * hoeven alleen `find()` te gebruiken. Een verwijderde of niet-bestaande
     * werkregel resulteert in een 404 via Laravel's standaard
     * `ModelNotFoundException`-handler.
     */
    private function findActiveEntry(int $id): WorkEntry
    {
        $entry = WorkEntry::find($id);

        if ($entry === null) {
            throw (new ModelNotFoundException)->setModel(WorkEntry::class, [$id]);
        }

        return $entry;
    }

    /**
     * Voorkom dat een actor een werkregel uit een andere organisatie kan
     * benaderen. We werpen bewust een 404 in plaats van 403, zodat het
     * bestaan van de werkregel niet over organisatiegrenzen heen lekt.
     */
    private function assertEntryInRegistrarOrg(User $registrar, WorkEntry $entry): void
    {
        if ((int) $registrar->organization_id !== (int) $entry->organization_id) {
            throw (new ModelNotFoundException)->setModel(WorkEntry::class, [$entry->id]);
        }
    }

    /**
     * Werp een 409 `OBJECTION_OPEN` wanneer er voor de werkregel reeds een
     * actief bezwaar (`status = 'OPEN'`) bestaat. Het response-formaat volgt
     * de afspraak uit design.md §Error Handling: `{ error, code }`.
     *
     * Requirements: 1.8
     */
    private function assertNoOpenObjection(int $workEntryId): void
    {
        $exists = Objection::query()
            ->where('work_entry_id', $workEntryId)
            ->where('status', 'OPEN')
            ->exists();

        if ($exists) {
            throw new HttpResponseException(
                response()->json([
                    'error' => 'Er is een openstaand bezwaar op deze werkregel.',
                    'code' => 'OBJECTION_OPEN',
                ], 409)
            );
        }
    }

    /**
     * Genereer een serialiseerbare snapshot van een werkregel ten behoeve
     * van audit before/after-data. Bevat alle relevante kolommen inclusief
     * de optionele FK's project/cost-center en de soft-delete-stamp.
     *
     * @return array<string, mixed>
     */
    private function snapshotEntry(WorkEntry $entry): array
    {
        return [
            'id' => (int) $entry->id,
            'organization_id' => (int) $entry->organization_id,
            'employee_id' => (int) $entry->employee_id,
            'team_id' => $entry->team_id !== null ? (int) $entry->team_id : null,
            'registered_by_id' => (int) $entry->registered_by_id,
            'entry_date' => $entry->entry_date instanceof Carbon
                ? $entry->entry_date->toDateString()
                : (string) $entry->entry_date,
            'start_at' => $entry->start_at?->toIso8601String(),
            'end_at' => $entry->end_at?->toIso8601String(),
            'pause_minutes' => (int) $entry->pause_minutes,
            'net_minutes' => (int) $entry->net_minutes,
            'type' => (string) $entry->type,
            'note' => $entry->note,
            'project_id' => $entry->project_id !== null ? (int) $entry->project_id : null,
            'cost_center_id' => $entry->cost_center_id !== null ? (int) $entry->cost_center_id : null,
            'is_finalized' => (bool) $entry->is_finalized,
            'deleted_at' => $entry->getAttribute('deleted_at') instanceof Carbon
                ? $entry->getAttribute('deleted_at')->toIso8601String()
                : ($entry->getAttribute('deleted_at') !== null
                    ? (string) $entry->getAttribute('deleted_at')
                    : null),
        ];
    }

    /**
     * Canonieke API-representatie van een werkregel zoals die naar de
     * frontend wordt geretourneerd door `create`/`update`/`find`.
     *
     * @return array<string, mixed>
     */
    private function toApiArray(WorkEntry $entry): array
    {
        return [
            'id' => (int) $entry->id,
            'employee_id' => (int) $entry->employee_id,
            'entry_date' => $entry->entry_date instanceof Carbon
                ? $entry->entry_date->toDateString()
                : (string) $entry->entry_date,
            'start_at' => $entry->start_at?->toIso8601String(),
            'end_at' => $entry->end_at?->toIso8601String(),
            'pause_minutes' => (int) $entry->pause_minutes,
            'net_minutes' => (int) $entry->net_minutes,
            'type' => (string) $entry->type,
            'note' => $entry->note,
            'project_id' => $entry->project_id !== null ? (int) $entry->project_id : null,
            'cost_center_id' => $entry->cost_center_id !== null ? (int) $entry->cost_center_id : null,
            'is_finalized' => (bool) $entry->is_finalized,
        ];
    }

    private function assertAllowedRegistrar(User $registrar): void
    {
        if (! in_array($registrar->role, self::ALLOWED_ROLES, true)) {
            throw ValidationException::withMessages([
                'registrar' => 'Alleen eigenaar of manager mag uren registreren.',
            ]);
        }
    }

    private function assertSameOrganization(User $registrar, User $employee): void
    {
        if ($registrar->organization_id !== $employee->organization_id) {
            throw ValidationException::withMessages([
                'employee_id' => 'Medewerker behoort niet tot dezelfde organisatie.',
            ]);
        }
    }

    private function assertTeamScope(User $registrar, User $employee): void
    {
        if ($registrar->role !== 'manager') {
            return;
        }

        if (! $registrar->team_id) {
            throw ValidationException::withMessages([
                'registrar' => 'Manager moet gekoppeld zijn aan een team.',
            ]);
        }

        if ($registrar->team_id !== $employee->team_id) {
            throw ValidationException::withMessages([
                'employee_id' => 'Manager mag alleen uren registreren voor eigen team.',
            ]);
        }
    }
}
