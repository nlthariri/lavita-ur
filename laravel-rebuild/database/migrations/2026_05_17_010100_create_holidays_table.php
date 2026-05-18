<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('holidays', function (Blueprint $table) {
            $table->id();
            $table->smallInteger('year')->unsigned();
            $table->date('date');
            $table->string('name', 80);
            $table->boolean('is_national')->default(true);
            $table->timestamps();

            $table->unique(['year', 'date'], 'uq_holidays_year_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('holidays');
    }
};
