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
        Schema::create('long_term_permits', function (Blueprint $table) {
            $table->id();
            $table->boolean('is_active')->default(false);
            $table->text('description');
            $table->date('valid_from')->nullable();
            $table->date('valid_to')->nullable();
            $table->foreignId('fishery_id')
                ->nullable()
                ->constrained()
                ->onDelete('set null');
            $table->decimal('price', 8, 2)->nullable();
            $table->integer('sales_limit')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('additional_services', function (Blueprint $table) {
            $table->id();
            $table->boolean('is_active')->default(false);
            $table->string('description')->nullable();
            $table->decimal('price', 8, 2)->nullable();
            $table->foreignId('fishery_id')
                ->nullable()
                ->constrained()
                ->onDelete('set null');
            $table->string('name');
            $table->integer('available_count')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create(
            'additional_service_long_term_permit', 
            function (Blueprint $table) {
                $table->unsignedBigInteger('additional_service_id');
                $table->unsignedBigInteger('long_term_permit_id');
                $table->foreign(
                    'additional_service_id', 
                    'as_ltp_as_id_foreign',
                )
                    ->references('id')
                    ->on('additional_services')
                    ->onDelete('cascade');
                $table->foreign(
                    'long_term_permit_id', 
                    'as_ltp_ltp_id_foreign',
                )
                    ->references('id')
                    ->on('long_term_permits')
                    ->onDelete('cascade');
                $table->primary(
                    ['additional_service_id', 'long_term_permit_id'], 
                    'as_ltp_primary',
                );
            },
        );

        Schema::create('positions', function (Blueprint $table) {
            $table->id();
            $table->boolean('is_active')->default(false);
            $table->string('name');
            $table->string('description')->nullable();
            $table->foreignId('fishery_id')
                ->nullable()
                ->constrained()
                ->onDelete('set null');
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create(
            'additional_service_position', 
            function (Blueprint $table) {
                $table->unsignedBigInteger('additional_service_id');
                $table->unsignedBigInteger('position_id');
                $table->foreign(
                    'additional_service_id', 
                    'as_pos_as_id_foreign',
                )
                    ->references('id')
                    ->on('additional_services')
                    ->onDelete('cascade');
                $table->foreign('position_id', 'as_pos_pos_id_foreign')
                    ->references('id')
                    ->on('positions')
                    ->onDelete('cascade');
                $table->boolean('is_required')->default(false);
                $table->primary(
                    ['additional_service_id', 'position_id'], 
                    'as_pos_primary',
                );
            }
        );

        Schema::create(
            'long_term_permit_position', 
            function (Blueprint $table) {
                $table->unsignedBigInteger('position_id');
                $table->unsignedBigInteger('long_term_permit_id');
                $table->foreign('position_id', 'ltp_pos_pos_id_foreign')
                    ->references('id')
                    ->on('positions')
                    ->onDelete('cascade');
                $table->foreign(
                    'long_term_permit_id', 
                    'ltp_pos_ltp_id_foreign'
                )
                    ->references('id')
                    ->on('long_term_permits')
                    ->onDelete('cascade');
                $table->primary(
                    ['position_id', 'long_term_permit_id'], 
                    'ltp_pos_primary',
                );
            }
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('long_term_permit_position');
        Schema::dropIfExists('additional_service_position');
        Schema::dropIfExists('positions');
        Schema::dropIfExists('additional_service_long_term_permit');
        Schema::dropIfExists('additional_services');
        Schema::dropIfExists('long_term_permits');
    }
};
