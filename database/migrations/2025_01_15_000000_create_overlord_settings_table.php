<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('overlord_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique(); // e.g., 'ai_api_key'
            $table->text('value'); // Encrypted value
            $table->text('description')->nullable(); // Optional description
            $table->timestamps();

            // Indexes for performance
            $table->index('key');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('overlord_settings');
    }
};

