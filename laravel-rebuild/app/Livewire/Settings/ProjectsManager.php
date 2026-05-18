<?php

declare(strict_types=1);

namespace App\Livewire\Settings;

use App\Services\CostCentersService;
use App\Services\ProjectsService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Livewire-component — `Settings\ProjectsManager`.
 *
 * Beheerscherm voor Projecten en Kostenplaatsen. Biedt twee tabbladen
 * met CRUD-functionaliteit (aanmaken, bewerken, archiveren) via de
 * bestaande `ProjectsService` en `CostCentersService`.
 *
 * Autorisatie:
 *  - Owner: volledige CRUD.
 *  - Manager: alleen lezen (lijst bekijken).
 *  - Employee/boekhouder: geen toegang (403).
 */
#[Layout('layouts.app')]
#[Title('Projecten & Kostenplaatsen — LaVita Urenregistratie')]
final class ProjectsManager extends Component
{
    // ─── Tab-state ───────────────────────────────────────────────────────

    public string $activeTab = 'projects';

    // ─── Zoekfilters ─────────────────────────────────────────────────────

    public string $projectSearch = '';

    public string $costCenterSearch = '';

    // ─── Project-formulier ───────────────────────────────────────────────

    public bool $showProjectForm = false;

    public ?int $editingProjectId = null;

    public string $projectCode = '';

    public string $projectName = '';

    public ?string $projectDescription = null;

    public ?string $projectHourlyRate = null;

    public bool $projectIsActive = true;

    // ─── Kostenplaats-formulier ──────────────────────────────────────────

    public bool $showCostCenterForm = false;

    public ?int $editingCostCenterId = null;

    public string $costCenterCode = '';

    public string $costCenterName = '';

    public ?string $costCenterDescription = null;

    public bool $costCenterIsActive = true;

    // ─── Feedback ────────────────────────────────────────────────────────

    public ?string $confirmation = null;

    // ─── Rol-check ───────────────────────────────────────────────────────

    public bool $isOwner = false;

    public function mount(): void
    {
        $user = Auth::user();

        if ($user === null) {
            abort(403, 'Geen toegang.');
        }

        $role = (string) $user->role;
        if ($role === 'employee' || $role === 'boekhouder') {
            abort(403, 'Geen toegang tot projectbeheer.');
        }

        $this->isOwner = $role === 'owner';
    }

    // ─── Tab-navigatie ───────────────────────────────────────────────────

    public function switchTab(string $tab): void
    {
        $this->activeTab = $tab;
        $this->confirmation = null;
        $this->resetForms();
    }

    // ─── Projecten — CRUD ────────────────────────────────────────────────

    public function getProjectsProperty(ProjectsService $projectsService): array
    {
        $user = Auth::user();
        if ($user === null) {
            return [];
        }

        $filters = ['with_archived' => true];
        if ($this->projectSearch !== '') {
            $filters['search'] = $this->projectSearch;
        }

        return $projectsService->list((int) $user->id, $filters);
    }

    public function openProjectForm(): void
    {
        $this->resetProjectForm();
        $this->showProjectForm = true;
    }

    public function editProject(int $projectId, ProjectsService $projectsService): void
    {
        $user = Auth::user();
        if ($user === null) {
            return;
        }

        $project = $projectsService->find($projectId, (int) $user->id);

        $this->editingProjectId = (int) $project['id'];
        $this->projectCode = (string) $project['code'];
        $this->projectName = (string) $project['name'];
        $this->projectDescription = $project['description'];
        $this->projectHourlyRate = $project['hourly_rate'];
        $this->projectIsActive = (bool) $project['is_active'];
        $this->showProjectForm = true;
        $this->confirmation = null;
        $this->resetErrorBag();
    }

    public function saveProject(ProjectsService $projectsService): void
    {
        $this->confirmation = null;
        $this->resetErrorBag();

        $user = Auth::user();
        if ($user === null) {
            abort(403);
        }

        if (! $this->isOwner) {
            $this->addError('projectCode', 'Alleen de eigenaar kan projecten beheren.');

            return;
        }

        $input = [
            'code' => $this->projectCode,
            'name' => $this->projectName,
            'description' => $this->projectDescription,
            'hourly_rate' => $this->projectHourlyRate !== null && $this->projectHourlyRate !== ''
                ? $this->projectHourlyRate
                : null,
            'is_active' => $this->projectIsActive,
        ];

        try {
            if ($this->editingProjectId !== null) {
                $projectsService->update($this->editingProjectId, $input, (int) $user->id);
                $this->confirmation = 'Project bijgewerkt.';
            } else {
                $projectsService->create($input, (int) $user->id);
                $this->confirmation = 'Project aangemaakt.';
            }

            $this->resetProjectForm();
            $this->showProjectForm = false;
        } catch (ValidationException $e) {
            foreach ($e->errors() as $field => $messages) {
                $livewireField = match ($field) {
                    'code' => 'projectCode',
                    'name' => 'projectName',
                    'description' => 'projectDescription',
                    'hourly_rate' => 'projectHourlyRate',
                    default => $field,
                };
                $this->addError($livewireField, $messages[0]);
            }
        }
    }

    public function archiveProject(int $projectId, ProjectsService $projectsService): void
    {
        $this->confirmation = null;
        $this->resetErrorBag();

        $user = Auth::user();
        if ($user === null) {
            abort(403);
        }

        if (! $this->isOwner) {
            return;
        }

        try {
            $projectsService->archive($projectId, (int) $user->id);
            $this->confirmation = 'Project gearchiveerd.';
            $this->resetProjectForm();
            $this->showProjectForm = false;
        } catch (ValidationException $e) {
            $this->addError('projectCode', $e->getMessage());
        }
    }

    // ─── Kostenplaatsen — CRUD ───────────────────────────────────────────

    public function getCostCentersProperty(CostCentersService $costCentersService): array
    {
        $user = Auth::user();
        if ($user === null) {
            return [];
        }

        $filters = ['with_archived' => true];
        if ($this->costCenterSearch !== '') {
            $filters['search'] = $this->costCenterSearch;
        }

        return $costCentersService->list((int) $user->id, $filters);
    }

    public function openCostCenterForm(): void
    {
        $this->resetCostCenterForm();
        $this->showCostCenterForm = true;
    }

    public function editCostCenter(int $costCenterId, CostCentersService $costCentersService): void
    {
        $user = Auth::user();
        if ($user === null) {
            return;
        }

        $costCenter = $costCentersService->find($costCenterId, (int) $user->id);

        $this->editingCostCenterId = (int) $costCenter['id'];
        $this->costCenterCode = (string) $costCenter['code'];
        $this->costCenterName = (string) $costCenter['name'];
        $this->costCenterDescription = $costCenter['description'];
        $this->costCenterIsActive = (bool) $costCenter['is_active'];
        $this->showCostCenterForm = true;
        $this->confirmation = null;
        $this->resetErrorBag();
    }

    public function saveCostCenter(CostCentersService $costCentersService): void
    {
        $this->confirmation = null;
        $this->resetErrorBag();

        $user = Auth::user();
        if ($user === null) {
            abort(403);
        }

        if (! $this->isOwner) {
            $this->addError('costCenterCode', 'Alleen de eigenaar kan kostenplaatsen beheren.');

            return;
        }

        $input = [
            'code' => $this->costCenterCode,
            'name' => $this->costCenterName,
            'description' => $this->costCenterDescription,
            'is_active' => $this->costCenterIsActive,
        ];

        try {
            if ($this->editingCostCenterId !== null) {
                $costCentersService->update($this->editingCostCenterId, $input, (int) $user->id);
                $this->confirmation = 'Kostenplaats bijgewerkt.';
            } else {
                $costCentersService->create($input, (int) $user->id);
                $this->confirmation = 'Kostenplaats aangemaakt.';
            }

            $this->resetCostCenterForm();
            $this->showCostCenterForm = false;
        } catch (ValidationException $e) {
            foreach ($e->errors() as $field => $messages) {
                $livewireField = match ($field) {
                    'code' => 'costCenterCode',
                    'name' => 'costCenterName',
                    'description' => 'costCenterDescription',
                    default => $field,
                };
                $this->addError($livewireField, $messages[0]);
            }
        }
    }

    public function archiveCostCenter(int $costCenterId, CostCentersService $costCentersService): void
    {
        $this->confirmation = null;
        $this->resetErrorBag();

        $user = Auth::user();
        if ($user === null) {
            abort(403);
        }

        if (! $this->isOwner) {
            return;
        }

        try {
            $costCentersService->archive($costCenterId, (int) $user->id);
            $this->confirmation = 'Kostenplaats gearchiveerd.';
            $this->resetCostCenterForm();
            $this->showCostCenterForm = false;
        } catch (ValidationException $e) {
            $this->addError('costCenterCode', $e->getMessage());
        }
    }

    // ─── Formulier-cancel ────────────────────────────────────────────────

    public function cancelProjectForm(): void
    {
        $this->resetProjectForm();
        $this->showProjectForm = false;
        $this->resetErrorBag();
    }

    public function cancelCostCenterForm(): void
    {
        $this->resetCostCenterForm();
        $this->showCostCenterForm = false;
        $this->resetErrorBag();
    }

    // ─── Render ──────────────────────────────────────────────────────────

    public function render(): View
    {
        return view('livewire.settings.projects-manager');
    }

    // ─── Helpers ─────────────────────────────────────────────────────────

    private function resetForms(): void
    {
        $this->resetProjectForm();
        $this->resetCostCenterForm();
        $this->showProjectForm = false;
        $this->showCostCenterForm = false;
        $this->resetErrorBag();
    }

    private function resetProjectForm(): void
    {
        $this->editingProjectId = null;
        $this->projectCode = '';
        $this->projectName = '';
        $this->projectDescription = null;
        $this->projectHourlyRate = null;
        $this->projectIsActive = true;
    }

    private function resetCostCenterForm(): void
    {
        $this->editingCostCenterId = null;
        $this->costCenterCode = '';
        $this->costCenterName = '';
        $this->costCenterDescription = null;
        $this->costCenterIsActive = true;
    }
}
