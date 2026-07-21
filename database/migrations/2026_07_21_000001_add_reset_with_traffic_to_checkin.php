<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 执行迁移
     */
    public function up(): void
    {
        if (Schema::hasTable('v2_checkin_config') && !Schema::hasColumn('v2_checkin_config', 'reset_with_traffic')) {
            Schema::table('v2_checkin_config', function (Blueprint $table) {
                $table->boolean('reset_with_traffic')
                    ->default(false)
                    ->after('enabled')
                    ->comment('签到奖励是否跟随套餐流量重置');
            });
        }

        if (Schema::hasTable('v2_user') && !Schema::hasColumn('v2_user', 'checkin_traffic')) {
            Schema::table('v2_user', function (Blueprint $table) {
                $table->bigInteger('checkin_traffic')
                    ->default(0)
                    ->after('transfer_enable')
                    ->comment('当前未清零的签到奖励流量');
            });
        }
    }

    /**
     * 回滚迁移
     */
    public function down(): void
    {
        if (Schema::hasTable('v2_checkin_config') && Schema::hasColumn('v2_checkin_config', 'reset_with_traffic')) {
            Schema::table('v2_checkin_config', function (Blueprint $table) {
                $table->dropColumn('reset_with_traffic');
            });
        }

        if (Schema::hasTable('v2_user') && Schema::hasColumn('v2_user', 'checkin_traffic')) {
            Schema::table('v2_user', function (Blueprint $table) {
                $table->dropColumn('checkin_traffic');
            });
        }
    }
};
