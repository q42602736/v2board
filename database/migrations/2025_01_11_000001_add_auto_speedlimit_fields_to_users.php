<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddAutoSpeedlimitFieldsToUsers extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('v2_user', function (Blueprint $table) {
            // 自动限速状态：0=正常，1-5=限速等级
            $table->integer('auto_speedlimit_status')->default(0)->comment('自动限速状态：0=正常，1-5=限速等级');
            
            // 原始限速值(Mbps)，用于恢复
            $table->integer('original_speedlimit')->nullable()->comment('原始限速值(Mbps)，用于恢复');
            
            // 昨日结束时的总流量，用于计算今日流量
            $table->bigInteger('last_day_t')->default(0)->comment('昨日结束时的总流量，用于计算今日流量');
            
            // 添加索引以提高查询性能
            $table->index('auto_speedlimit_status');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('v2_user', function (Blueprint $table) {
            $table->dropIndex(['auto_speedlimit_status']);
            $table->dropColumn(['auto_speedlimit_status', 'original_speedlimit', 'last_day_t']);
        });
    }
}
