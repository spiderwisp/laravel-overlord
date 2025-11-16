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
        
        Schema::create('overlord_command_logs', function (Blueprint $table) use ($userTable) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->text('command');
            $table->text('output')->nullable();
            $table->text('error')->nullable();
            $table->decimal('execution_time', 8, 2)->nullable(); // milliseconds
            $table->integer('memory_usage')->nullable(); // bytes
            $table->boolean('success')->default(false);
            $table->string('output_type')->nullable(); // json, object, text, error
            $table->string('ip_address')->nullable();
            $table->timestamps();

            // Foreign key constraint (only if users table exists)
            if (Schema::hasTable($userTable)) {
                $table->foreign('user_id')->references('id')->on($userTable)->onDelete('set null');
            }

            // Indexes for performance
            $table->index('user_id');
            $table->index('created_at');
            $table->index('success');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('overlord_command_logs');
    }
};

