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

        Schema::create('overlord_database_scan_history', function (Blueprint $table) use ($userTable) {
            $table->id();
            $table->string('scan_id')->unique(); // Unique scan identifier
            $table->unsignedBigInteger('user_id')->nullable(); // User who initiated the scan
            $table->enum('status', ['queued', 'scanning', 'completed', 'failed'])->default('queued');
            $table->enum('scan_type', ['schema', 'data'])->default('schema'); // 'schema' or 'data'
            $table->string('scan_mode')->default('full'); // 'full' or 'selective'
            $table->json('selected_tables')->nullable(); // Selected tables if selective mode
            $table->integer('sample_size')->default(100); // Sample size for data scans
            $table->integer('total_tables')->default(0);
            $table->integer('processed_tables')->default(0);
            $table->bigInteger('total_batches')->default(0);
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
            $table->index('scan_type');
            $table->index('created_at');
            $table->index(['user_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('overlord_database_scan_history');
    }
};

