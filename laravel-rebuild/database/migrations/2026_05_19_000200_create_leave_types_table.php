<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leave_types', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->string('code', 40);
            $table->string('name', 120);
            $table->string('description', 500)->nullable();
            $table->unsignedSmallInteger('max_days_per_year')->nullable();
            $table->boolean('counts_towards_balance')->default(true);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['organization_id', 'code'], 'uq_leave_types_org_code');
            $table->index(['organization_id', 'is_active'], 'idx_leave_types_org_active');

            $table->foreign('organization_id', 'fk_leave_types_org')
                ->references('id')
                ->on('organizations')
                ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_types');
    }
};
