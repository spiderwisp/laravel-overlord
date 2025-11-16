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
        $userModel = config('laravel-overlord.user_model', \App\Models\User::class);
        $userTable = (new $userModel)->getTable();
        
        Schema::create('overlord_issues', function (Blueprint $table) use ($userTable) {
            $table->id();
            $table->string('title');
            $table->text('description'); // NOT NULL - application should provide empty string if needed
            $table->enum('status', ['open', 'in_progress', 'resolved', 'closed'])->default('open');
            $table->enum('priority', ['low', 'medium', 'high', 'critical'])->default('medium');
            $table->unsignedBigInteger('creator_id')->nullable();
            $table->unsignedBigInteger('assignee_id')->nullable();
            $table->string('source_type')->nullable(); // 'log', 'terminal', 'ai', 'manual'
            $table->string('source_id')->nullable(); // ID/reference to source
            $table->json('source_data')->nullable(); // Additional context
            $table->json('tags')->nullable(); // Array of tags
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->unsignedBigInteger('resolved_by_id')->nullable();
            $table->unsignedBigInteger('closed_by_id')->nullable();
            $table->timestamps();

            // Foreign key constraints (only if users table exists)
            if (Schema::hasTable($userTable)) {
                $table->foreign('creator_id')->references('id')->on($userTable)->onDelete('set null');
                $table->foreign('assignee_id')->references('id')->on($userTable)->onDelete('set null');
                $table->foreign('resolved_by_id')->references('id')->on($userTable)->onDelete('set null');
                $table->foreign('closed_by_id')->references('id')->on($userTable)->onDelete('set null');
            }

            // Indexes for performance
            $table->index('status');
            $table->index('priority');
            $table->index('creator_id');
            $table->index('assignee_id');
            $table->index('source_type');
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
        Schema::dropIfExists('overlord_issues');
    }
};

