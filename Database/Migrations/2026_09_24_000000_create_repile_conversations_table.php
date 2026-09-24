<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateRepileConversationsTable extends Migration
{
    public function up()
    {
        Schema::create('repile_conversations', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('conversation_id')->unique();
            $table->string('repile_thread_id', 191)->nullable();
            $table->string('repile_thread_path', 255)->nullable();
            $table->string('last_event', 64)->nullable();
            $table->timestamp('last_delivered_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('last_error_at')->nullable();
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('repile_conversations');
    }
}
