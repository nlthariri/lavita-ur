<?php

namespace App\Http\Controllers\Transitie\EmailFlowsModule;

use App\Http\Controllers\Controller;
use App\Services\EmailOutboxService;
use App\Services\EmailTemplateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class EmailFlowsModuleController extends Controller
{
    public function __construct(
        private readonly EmailOutboxService $emailOutboxService,
        private readonly EmailTemplateService $emailTemplateService,
    ) {}

    public function postInternalEmailDispatch(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'recipient' => ['required', 'email'],
            'subject' => ['required', 'string', 'max:500'],
            'body_text' => ['required', 'string'],
            'body_html' => ['required', 'string'],
            'type' => ['sometimes', 'string', 'max:80'],
            'organization_id' => ['sometimes', 'integer'],
            'user_id' => ['sometimes', 'integer'],
            'idempotency_key' => ['sometimes', 'string', 'max:128'],
            'attachments' => ['sometimes', 'array'],
            'template_vars' => ['sometimes', 'array'],
        ]);

        $actor = $request->user();
        if (! in_array($actor->role, ['owner', 'manager'], true)) {
            return response()->json([
                'message' => 'Onvoldoende rechten voor e-mail dispatch.',
            ], 403);
        }

        if (isset($validated['organization_id']) && (int) $validated['organization_id'] !== (int) $actor->organization_id) {
            return response()->json([
                'message' => 'U mag geen e-mail dispatchen voor een andere organisatie.',
            ], 403);
        }

        $correlationId = $request->header('x-correlation-id') ?: (string) Str::uuid();
        $validated['organization_id'] = (int) $actor->organization_id;
        $actorContext = [
            'actor_id' => (int) $actor->id,
            'role' => (string) $actor->role,
            'organization_id' => (int) $actor->organization_id,
            'request_id' => $request->header('x-request-id'),
            'source_ip' => $request->header('x-forwarded-for')
                ? trim(explode(',', (string) $request->header('x-forwarded-for'))[0])
                : $request->ip(),
            'user_agent' => $request->userAgent(),
            'correlation_id' => $correlationId,
        ];

        $result = $this->emailOutboxService->dispatch($validated, $actorContext);

        return response()->json($result, 202);
    }

    public function putInternalEmailTemplate(Request $request, string $type): JsonResponse
    {
        $actor = $request->user();
        if (! in_array($actor->role, ['owner', 'manager'], true)) {
            return response()->json([
                'message' => 'Onvoldoende rechten voor e-mailtemplates.',
            ], 403);
        }

        $validated = $request->validate([
            'subject_template' => ['required', 'string', 'max:500'],
            'body_text_template' => ['required', 'string'],
            'body_html_template' => ['required', 'string'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $template = $this->emailTemplateService->upsertTemplate(
            (int) $actor->organization_id,
            $type,
            $validated,
            (int) $actor->id,
        );

        return response()->json([
            'status' => 'ok',
            'template' => [
                'type' => (string) $template->type,
                'subject_template' => (string) $template->subject_template,
                'body_text_template' => (string) $template->body_text_template,
                'body_html_template' => (string) $template->body_html_template,
                'is_active' => (bool) $template->is_active,
                'updated_by_actor_id' => $template->updated_by_actor_id !== null ? (int) $template->updated_by_actor_id : null,
            ],
        ]);
    }

    public function getInternalEmailTemplate(Request $request, string $type): JsonResponse
    {
        $actor = $request->user();
        if (! in_array($actor->role, ['owner', 'manager'], true)) {
            return response()->json([
                'message' => 'Onvoldoende rechten voor e-mailtemplates.',
            ], 403);
        }

        $template = $this->emailTemplateService->findTemplate((int) $actor->organization_id, $type);

        if (! $template) {
            return response()->json([
                'message' => 'Template niet gevonden.',
            ], 404);
        }

        return response()->json([
            'type' => (string) $template->type,
            'subject_template' => (string) $template->subject_template,
            'body_text_template' => (string) $template->body_text_template,
            'body_html_template' => (string) $template->body_html_template,
            'is_active' => (bool) $template->is_active,
            'updated_by_actor_id' => $template->updated_by_actor_id !== null ? (int) $template->updated_by_actor_id : null,
        ]);
    }

    public function postInternalJobsMonthlyReport(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'organization_id' => ['required', 'integer'],
            'period_month' => ['required', 'string', 'regex:/^\d{4}-\d{2}$/'],
        ]);

        $actor = $request->user();
        if (! in_array($actor->role, ['owner', 'manager'], true)) {
            return response()->json([
                'message' => 'Onvoldoende rechten voor maandrapportage.',
            ], 403);
        }

        if ($actor->organization_id !== (int) $validated['organization_id']) {
            return response()->json([
                'message' => 'U mag geen jobs starten voor een andere organisatie.',
            ], 403);
        }

        $correlationId = $request->header('x-correlation-id') ?: (string) Str::uuid();
        $actorContext = [
            'actor_id' => (int) $actor->id,
            'role' => (string) $actor->role,
            'organization_id' => (int) $actor->organization_id,
            'request_id' => $request->header('x-request-id'),
            'source_ip' => $request->header('x-forwarded-for')
                ? trim(explode(',', (string) $request->header('x-forwarded-for'))[0])
                : $request->ip(),
            'user_agent' => $request->userAgent(),
            'correlation_id' => $correlationId,
        ];

        $result = $this->emailOutboxService->queueMonthlyReports(
            (int) $validated['organization_id'],
            $validated['period_month'],
            (int) $actor->id,
            $actorContext,
        );

        return response()->json($result, 202);
    }
}
