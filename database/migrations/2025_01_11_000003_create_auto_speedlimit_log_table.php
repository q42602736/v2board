<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateAutoSpeedlimitLogTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('v2_auto_speedlimit_log', function (Blueprint $table) {
            $table->id();
            
            // 基本信息
            $table->integer('user_id')->comment('用户ID');
            $table->enum('action', ['limit', 'restore'])->comment('操作类型');
            
            // 状态变化
            $table->integer('old_status')->comment('原状态');
            $table->integer('new_status')->comment('新状态');
            $table->integer('old_speedlimit')->nullable()->comment('原限速值');
            $table->integer('new_speedlimit')->nullable()->comment('新限速值');
            
            // 触发信息
            $table->string('trigger_info')->nullable()->comment('触发信息');
            $table->decimal('daily_percent', 5, 2)->nullable()->comment('当日流量百分比');
            $table->decimal('total_percent', 5, 2)->nullable()->comment('总流量百分比');
            
            $table->timestamps();
            
            // 添加索引
            $table->index('user_id');
            $table->index('created_at');
            $table->index(['user_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('v2_auto_speedlimit_log');
    }
}
