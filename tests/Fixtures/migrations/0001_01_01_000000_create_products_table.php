<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->nullable();
            // Nullable + unique: os testes antigos não preenchem sku (vários
            // NULLs são aceitos), e firstOrCreate/updateOrCreate precisam de uma
            // constraint real para detectar criação concorrente.
            $table->string('sku')->nullable()->unique();
            $table->integer('stock')->default(0);
            $table->string('status')->default('active');
            // Coluna com cast de enum: (string) em enum nativo lança Error.
            $table->string('gender')->nullable();
            $table->text('description')->nullable();
            // jsonb no Postgres; o SQLiteGrammar mapeia para text.
            $table->jsonb('meta')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
