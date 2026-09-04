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
        if (!Schema::hasTable('v2_user') || Schema::hasColumn('v2_user', 'auto_speedlimit_manual_restore_at')) {
            return;
        }

        Schema::table('v2_user', function (Blueprint $table) {
            $table->bigInteger('auto_speedlimit_manual_restore_at')
                ->nullable()
                ->after('original_speedlimit')
                ->comment('手动恢复自动限速时间（Unix时间戳）');
        });
    }

    /**
     * 回滚迁移
     */
    public function down(): void
    {
        if (!Schema::hasTable('v2_user') || !Schema::hasColumn('v2_user', 'auto_speedlimit_manual_restore_at')) {
            return;
        }

        Schema::table('v2_user', function (Blueprint $table) {
            $table->dropColumn('auto_speedlimit_manual_restore_at');
        });
    }
};
