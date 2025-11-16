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
        
        Schema::create('overlord_database_scan_issues', function (Blueprint $table) use ($userTable) {
            $table->id();
            $table->unsignedBigInteger('scan_history_id'); // Reference to scan history
            $table->unsignedBigInteger('user_id')->nullable(); // User who ran the scan
            $table->string('table_name'); // Table where issue was found
            $table->enum('issue_type', ['schema', 'data'])->default('schema'); // Issue type
            $table->enum('severity', ['low', 'medium', 'high', 'critical'])->default('medium');
            $table->string('title'); // Issue title
            $table->text('description'); // Issue description
            $table->json('location')->nullable(); // Location details (column, constraint, etc.)
            $table->text('suggestion')->nullable(); // Suggested fix
            $table->boolean('resolved')->default(false);
            $table->unsignedBigInteger('resolved_by_id')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            // Foreign key constraints (only if users table exists)
            if (Schema::hasTable($userTable)) {
                $table->foreign('user_id')->references('id')->on($userTable)->onDelete('set null');
                $table->foreign('resolved_by_id')->references('id')->on($userTable)->onDelete('set null');
            }
            
            // Foreign key to scan history
            $table->foreign('scan_history_id')->references('id')->on('overlord_database_scan_history')->onDelete('cascade');

            // Indexes for performance
            $table->index('scan_history_id');
            $table->index('user_id');
            $table->index('resolved_by_id');
            $table->index('table_name');
            $table->index('issue_type');
            $table->index('severity');
            $table->index('resolved');
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
        Schema::dropIfExists('overlord_database_scan_issues');
    }
};

