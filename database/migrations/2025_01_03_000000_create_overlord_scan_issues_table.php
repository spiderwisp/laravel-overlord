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
        
        Schema::create('overlord_scan_issues', function (Blueprint $table) use ($userTable) {
            $table->id();
            $table->string('scan_id'); // The scan ID from the scan job
            $table->unsignedBigInteger('user_id')->nullable(); // User who ran the scan
            $table->string('file_path'); // Path to the file with the issue
            $table->integer('line')->nullable(); // Line number where issue occurs
            $table->string('type')->default('general'); // Issue type (bug, security, quality, etc.)
            $table->enum('severity', ['low', 'medium', 'high', 'critical'])->default('medium');
            $table->text('message'); // Issue message/description
            $table->json('raw_data')->nullable(); // Raw issue data from AI analysis
            $table->boolean('resolved')->default(false);
            $table->unsignedBigInteger('resolved_by_id')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            // Foreign key constraints (only if users table exists)
            if (Schema::hasTable($userTable)) {
                $table->foreign('user_id')->references('id')->on($userTable)->onDelete('set null');
                $table->foreign('resolved_by_id')->references('id')->on($userTable)->onDelete('set null');
            }

            // Indexes for performance
            $table->index('scan_id');
            $table->index('user_id');
            $table->index('file_path');
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
        Schema::dropIfExists('overlord_scan_issues');
    }
};

