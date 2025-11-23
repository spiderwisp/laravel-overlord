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
        Schema::table('overlord_agent_sessions', function (Blueprint $table) {
            $table->integer('max_retries')->default(3)->after('max_iterations');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('overlord_agent_sessions', function (Blueprint $table) {
            $table->dropColumn('max_retries');
        });
    }
};

