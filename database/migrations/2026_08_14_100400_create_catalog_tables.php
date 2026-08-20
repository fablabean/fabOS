<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Catalogo fisico del laboratorio (§7).
 *
 * Tres distinciones que sostienen el modelo:
 *  - area          : unidad de certificacion, espacio y responsable.
 *  - risk_family   : subgrupo de riesgo DENTRO del area (FDM != resina).
 *  - location      : arbol fisico, independiente del area.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('areas', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
        });

        Schema::create('risk_families', function (Blueprint $table) {
            $table->id();
            $table->foreignId('area_id')->constrained()->cascadeOnDelete();
            $table->string('slug');
            $table->string('name');
            // Nivel de curso exigido como PRERREQUISITO. El certifab es lo que habilita.
            $table->string('required_course_level')->nullable(); // bit|byte|kilo|mega|giga|tera
            $table->boolean('requires_companion')->default(false);
            $table->text('safety_notes')->nullable();
            $table->timestamps();

            $table->unique(['area_id', 'slug']);
        });

        // Arbol: sede > piso > sala > estante > gaveta
        Schema::create('locations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained('locations')->cascadeOnDelete();
            $table->string('name');
            $table->string('path')->nullable();   // ruta materializada, para busqueda
            $table->string('qr_token')->nullable()->unique();
            $table->timestamps();
        });

        Schema::create('spaces', function (Blueprint $table) {
            $table->id();
            $table->foreignId('area_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('location_id')->nullable()->constrained()->nullOnDelete();
            $table->string('slug')->unique();
            $table->string('name');
            $table->string('type')->default('fisico');       // fisico | virtual
            $table->unsignedSmallInteger('capacity')->nullable();
            $table->boolean('is_reservable')->default(true);
            // Donde se asesora, se monta y se produce (§10)
            $table->boolean('is_production_space')->default(false);
            $table->unsignedSmallInteger('setup_minutes')->default(0);
            $table->unsignedSmallInteger('cleanup_minutes')->default(0);
            $table->timestamps();
        });

        Schema::create('assets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('area_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('risk_family_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('location_id')->nullable()->constrained()->nullOnDelete();

            $table->string('name');
            $table->string('kind')->default('fijo');          // fijo | herramienta
            $table->string('brand')->nullable();
            $table->string('model')->nullable();
            $table->string('serial')->nullable();
            $table->string('asset_tag')->nullable()->unique(); // placa
            $table->string('qr_token')->nullable()->unique();

            // operativo | mantenimiento | fuera_de_servicio | baja
            // Un estado distinto de operativo bloquea la agenda (§8).
            $table->string('status')->default('operativo');

            // Inventariable pero no reservable: secadores, compresores, aspiradora (§7)
            $table->boolean('is_reservable')->default(true);
            // Impresion 3D: el trabajo corre sin la persona presente (§7)
            $table->boolean('unattended_use')->default(false);

            // Unidades equivalentes: se reserva "una Creality Hi Combo", no la #3
            $table->string('pool_key')->nullable()->index();

            $table->unsignedSmallInteger('min_minutes')->default(30);
            $table->unsignedSmallInteger('autonomous_minutes')->default(60);   // sin check
            $table->unsignedSmallInteger('max_minutes')->default(720);         // tope tera

            $table->decimal('purchase_cost', 14, 2)->nullable();
            $table->date('purchased_at')->nullable();
            $table->date('warranty_until')->nullable();

            $table->timestamps();
            $table->softDeletes();
        });

        // Sin compresor no hay CNC; sin aspiradora no hay laser (§7)
        Schema::create('asset_dependencies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asset_id')->constrained()->cascadeOnDelete();
            $table->foreignId('depends_on_asset_id')->constrained('assets')->cascadeOnDelete();
            $table->string('note')->nullable();
            $table->timestamps();

            $table->unique(['asset_id', 'depends_on_asset_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_dependencies');
        Schema::dropIfExists('assets');
        Schema::dropIfExists('spaces');
        Schema::dropIfExists('locations');
        Schema::dropIfExists('risk_families');
        Schema::dropIfExists('areas');
    }
};
