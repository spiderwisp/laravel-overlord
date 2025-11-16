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
        $userModel = config('laravel-overlord.user_model', \App\Models\User::class);
        $userTable = (new $userModel)->getTable();

        Schema::create('overlord_scan_history', function (Blueprint $table) use ($userTable) {
            $table->id();
            $table->string('scan_id')->unique(); // Unique scan identifier
            $table->unsignedBigInteger('user_id')->nullable(); // User who initiated the scan
            $table->enum('status', ['queued', 'scanning', 'completed', 'failed'])->default('queued');
            $table->string('scan_mode')->default('full'); // 'full' or 'selective'
            $table->json('selected_paths')->nullable(); // Selected paths if selective mode
            $table->integer('total_files')->default(0);
            $table->integer('processed_files')->default(0);
            $table->integer('total_batches')->default(0);
            $table->integer('processed_batches')->default(0);
            $table->integer('total_issues_found')->default(0);
            $table->integer('issues_saved')->default(0);
            $table->text('error')->nullable(); // Error message if failed
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            // Foreign key constraints
            if (Schema::hasTable($userTable)) {
                $table->foreign('user_id')->references('id')->on($userTable)->onDelete('set null');
            }

            // Indexes for performance
            $table->index('user_id');
            $table->index('status');
            $table->index('created_at');
            $table->index(['user_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('overlord_scan_history');
    }
};

