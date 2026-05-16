<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_templates', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->string('type', 80);
            $table->string('subject_template', 500);
            $table->text('body_text_template');
            $table->text('body_html_template');
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('updated_by_actor_id')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'type'], 'uniq_email_template_org_type');
            $table->index(['organization_id', 'is_active'], 'idx_email_template_org_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_templates');
    }
};
