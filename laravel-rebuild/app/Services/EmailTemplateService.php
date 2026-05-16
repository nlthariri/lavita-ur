<?php

namespace App\Services;

use App\Models\EmailTemplate;

class EmailTemplateService
{
    private const ALLOWED_TEMPLATE_TYPES = [
        'account_created',
        'password_reset',
        'monthly_report',
        'work_entry_finalized',
        'objection_submitted',
        'objection_reviewed',
        'reminder_open_entries',
        'atw_daily_limit',
        'atw_weekly_warning',
        'atw_weekly_limit',
        'atw_sixteen_week_average',
        'atw_rest_period',
    ];

    public function upsertTemplate(int $organizationId, string $type, array $payload, int $actorId): EmailTemplate
    {
        $this->assertSupportedType($type);

        return EmailTemplate::updateOrCreate(
            [
                'organization_id' => $organizationId,
                'type' => $type,
            ],
            [
                'subject_template' => (string) $payload['subject_template'],
                'body_text_template' => (string) $payload['body_text_template'],
                'body_html_template' => (string) $payload['body_html_template'],
                'is_active' => (bool) ($payload['is_active'] ?? true),
                'updated_by_actor_id' => $actorId,
            ],
        );
    }

    public function findTemplate(int $organizationId, string $type): ?EmailTemplate
    {
        $this->assertSupportedType($type);

        return EmailTemplate::query()
            ->where('organization_id', $organizationId)
            ->where('type', $type)
            ->first();
    }

    public function applyTemplate(array $input): array
    {
        $organizationId = (int) ($input['organization_id'] ?? 0);
        $type = (string) ($input['type'] ?? 'custom');

        if ($organizationId <= 0 || $type === '' || $type === 'custom') {
            return $input;
        }

        if (!$this->isSupportedType($type)) {
            return $input;
        }

        $template = EmailTemplate::query()
            ->where('organization_id', $organizationId)
            ->where('type', $type)
            ->where('is_active', true)
            ->first();

        if (!$template) {
            return $input;
        }

        $varsText = [];
        $varsHtml = [];
        if (isset($input['template_vars']) && is_array($input['template_vars'])) {
            foreach ($input['template_vars'] as $key => $value) {
                if (is_scalar($value)) {
                    $token = '{{'.(string) $key.'}}';
                    $valueString = (string) $value;
                    $varsText[$token] = $valueString;
                    $varsHtml[$token] = e($valueString);
                }
            }
        }

        $input['subject'] = strtr((string) $template->subject_template, $varsText);
        $input['body_text'] = strtr((string) $template->body_text_template, $varsText);
        $input['body_html'] = strtr((string) $template->body_html_template, $varsHtml);

        return $input;
    }

    public function isSupportedType(string $type): bool
    {
        return in_array($type, self::ALLOWED_TEMPLATE_TYPES, true);
    }

    private function assertSupportedType(string $type): void
    {
        if (!$this->isSupportedType($type)) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'type' => 'Ongeldig e-mailtemplate type.',
            ]);
        }
    }
}
