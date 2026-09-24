<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddWorkingToRepileConversationsTable extends Migration
{
    public function up()
    {
        Schema::table('repile_conversations', function (Blueprint $table) {
            $table->timestamp('working_since')->nullable();
            $table->string('working_for', 191)->nullable();
        });
    }

    public function down()
    {
        Schema::table('repile_conversations', function (Blueprint $table) {
            $table->dropColumn(['working_since', 'working_for']);
        });
    }
}
