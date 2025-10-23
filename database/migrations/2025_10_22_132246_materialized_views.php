<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('materialized_views', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->uuid('user_id')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('last_refreshed_at')->nullable();
            $table->boolean('active')->default(true);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('materialized_views');
    }
};
