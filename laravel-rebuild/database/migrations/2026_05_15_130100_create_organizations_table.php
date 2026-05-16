<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organizations', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('kvk_number', 8)->nullable();
            $table->string('loonheffingennummer', 12)->nullable();
            $table->string('default_timezone', 50)->default('Europe/Amsterdam');
            $table->unsignedSmallInteger('atw_daily_max_minutes')->default(720);
            $table->unsignedSmallInteger('atw_weekly_max_minutes')->default(3600);
            $table->unsignedSmallInteger('atw_weekly_warning_minutes')->default(2880);
            $table->unsignedSmallInteger('atw_average_16_week_minutes')->default(2880);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organizations');
    }
};
