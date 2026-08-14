<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('fisheries', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->foreignId('state_id')
                ->nullable()
                ->constrained()
                ->onDelete('set null');
            $table->foreignId('user_id')
                ->nullable()
                ->constrained()
                ->onDelete('set null');
            $table->foreignId('company_id')
                ->nullable()
                ->constrained()
                ->onDelete('set null');
            $table->string('town');
            $table->string('street');
            $table->string('building_number');
            $table->string('directions')->nullable();
            $table->text('description')->nullable();
            $table->string('zip_code');
            $table->decimal('area', 10, 2);
            $table->decimal('avg_depth', 5, 2)->nullable();
            $table->decimal('max_depth', 5, 2)->nullable();
            $table->integer('positions_count');
            $table->foreignId('dominant_fish_id')
                ->nullable()
                ->constrained('fish')
                ->onDelete('set null');
            $table->string('records')->nullable();
            $table->string('map_image_path')->nullable();
            $table->json('gallery_images')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('fishery_fishery_type', function (Blueprint $table) {
            $table->foreignId('fishery_id')
                ->constrained()
                ->onDelete('cascade');
            $table->foreignId('fishery_type_id')
                ->constrained()
                ->onDelete('cascade');
            $table->primary(['fishery_id', 'fishery_type_id']);
        });

        Schema::create('fishery_fishing_method', function (Blueprint $table) {
            $table->foreignId('fishery_id')
                ->constrained()
                ->onDelete('cascade');
            $table->foreignId('fishing_method_id')
                ->constrained()
                ->onDelete('cascade');
            $table->primary(['fishery_id', 'fishing_method_id']);
        });

        Schema::create('fish_fishery', function (Blueprint $table) {
            $table->foreignId('fishery_id')
                ->constrained()
                ->onDelete('cascade');
            $table->foreignId('fish_id')
                ->constrained()
                ->onDelete('cascade');
            $table->primary(['fishery_id', 'fish_id']);
        });

        Schema::create('convenience_fishery', function (Blueprint $table) {
            $table->foreignId('fishery_id')
                ->constrained()
                ->onDelete('cascade');
            $table->foreignId('convenience_id')
                ->constrained()
                ->onDelete('cascade');
            $table->primary(['fishery_id', 'convenience_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('convenience_fishery');
        Schema::dropIfExists('fish_fishery');
        Schema::dropIfExists('fishery_fishing_method');
        Schema::dropIfExists('fishery_fishery_type');
        Schema::dropIfExists('fisheries');
    }
};
