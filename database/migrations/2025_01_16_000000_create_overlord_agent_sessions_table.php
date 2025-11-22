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
        
        Schema::create('overlord_agent_sessions', function (Blueprint $table) use ($userTable) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('status')->default('pending'); // pending, running, paused, completed, stopped, failed
            $table->integer('larastan_level')->default(1);
            $table->boolean('auto_apply')->default(true);
            $table->integer('total_scans')->default(0);
            $table->integer('total_issues_found')->default(0);
            $table->integer('total_issues_fixed')->default(0);
            $table->integer('current_iteration')->default(0);
            $table->integer('max_iterations')->default(50);
            $table->text('error_message')->nullable();
            $table->timestamps();

            // Foreign key constraint (only if users table exists)
            if (Schema::hasTable($userTable)) {
                $table->foreign('user_id')->references('id')->on($userTable)->onDelete('set null');
            }

            // Indexes for performance
            $table->index('user_id');
            $table->index('status');
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
        Schema::dropIfExists('overlord_agent_sessions');
    }
};

