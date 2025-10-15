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
        Schema::table('v2_lottery_config', function (Blueprint $table) {
            $table->boolean('telegram_enabled')->default(false)->comment('是否启用Telegram通知');
            $table->string('telegram_bot_token', 500)->nullable()->comment('Telegram机器人Token');
            $table->string('telegram_chat_id', 100)->nullable()->comment('Telegram群组ID');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('v2_lottery_config', function (Blueprint $table) {
            $table->dropColumn(['telegram_enabled', 'telegram_bot_token', 'telegram_chat_id']);
        });
    }
};
