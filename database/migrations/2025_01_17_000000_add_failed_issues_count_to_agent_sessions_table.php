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
            $table->integer('failed_issues_count')->default(0)->after('total_issues_fixed');
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
            $table->dropColumn('failed_issues_count');
        });
    }
};

