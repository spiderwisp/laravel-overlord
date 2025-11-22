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
        Schema::create('overlord_agent_file_changes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('agent_session_id');
            $table->string('file_path');
            $table->text('original_content')->nullable();
            $table->text('new_content')->nullable();
            $table->string('status')->default('pending'); // pending, approved, rejected, applied
            $table->text('rejection_reason')->nullable();
            $table->string('backup_path')->nullable();
            $table->json('change_summary')->nullable(); // Description of what changed
            $table->timestamps();

            // Foreign key constraint
            $table->foreign('agent_session_id')
                ->references('id')
                ->on('overlord_agent_sessions')
                ->onDelete('cascade');

            // Indexes for performance
            $table->index('agent_session_id');
            $table->index('status');
            $table->index('file_path');
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
        Schema::dropIfExists('overlord_agent_file_changes');
    }
};

