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
        Schema::create('overlord_agent_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('agent_session_id');
            $table->string('type'); // info, success, error, warning, scan_start, scan_complete, fix_generated, fix_applied
            $table->text('message');
            $table->json('data')->nullable(); // Additional structured data
            $table->timestamps();

            // Foreign key constraint
            $table->foreign('agent_session_id')
                ->references('id')
                ->on('overlord_agent_sessions')
                ->onDelete('cascade');

            // Indexes for performance
            $table->index('agent_session_id');
            $table->index('type');
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('overlord_agent_logs');
    }
};

