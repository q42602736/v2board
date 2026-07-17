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
        if (Schema::hasColumn('v2_auto_speedlimit_config', 'limit_basis')) {
            return;
        }

        Schema::table('v2_auto_speedlimit_config', function (Blueprint $table) {
            $table->string('limit_basis', 20)
                ->default('ratio')
                ->after('enable')
                ->comment('限速依据：ratio=流量比例，daily_fixed=当日固定流量');
        });
    }

    /**
     * 回滚迁移
     */
    public function down(): void
    {
        if (!Schema::hasColumn('v2_auto_speedlimit_config', 'limit_basis')) {
            return;
        }

        Schema::table('v2_auto_speedlimit_config', function (Blueprint $table) {
            $table->dropColumn('limit_basis');
        });
    }
};
