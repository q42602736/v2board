<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateAutoSpeedlimitConfigTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('v2_auto_speedlimit_config', function (Blueprint $table) {
            $table->id();
            
            // 基本配置
            $table->boolean('enable')->default(false)->comment('是否启用');
            $table->enum('traffic_mode', ['daily', 'total', 'both'])->default('daily')->comment('流量计算模式');
            $table->enum('daily_calc_mode', ['total', 'remaining'])->default('total')->comment('每日流量计算基准');
            
            // 5级阈值和限速配置
            $table->decimal('threshold_1', 5, 2)->nullable()->comment('阈值1(%)');
            $table->integer('speed_1')->nullable()->comment('限速1(Mbps)');
            $table->decimal('threshold_2', 5, 2)->nullable()->comment('阈值2(%)');
            $table->integer('speed_2')->nullable()->comment('限速2(Mbps)');
            $table->decimal('threshold_3', 5, 2)->nullable()->comment('阈值3(%)');
            $table->integer('speed_3')->nullable()->comment('限速3(Mbps)');
            $table->decimal('threshold_4', 5, 2)->nullable()->comment('阈值4(%)');
            $table->integer('speed_4')->nullable()->comment('限速4(Mbps)');
            $table->decimal('threshold_5', 5, 2)->nullable()->comment('阈值5(%)');
            $table->integer('speed_5')->nullable()->comment('限速5(Mbps)');
            
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('v2_auto_speedlimit_config');
    }
}
