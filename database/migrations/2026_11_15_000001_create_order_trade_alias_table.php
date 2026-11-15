<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 支付单号别名表。
 *
 * 网关对同一个 out_trade_no 只允许下单一次，首次下单即使取码失败也会占用
 * 该单号，用户再次支付就报「商户订单号已存在」。支付失败重试时会换新单号，
 * 旧单号记录在这里，保证回调仍能找回订单，不会掉单。
 */
class CreateOrderTradeAliasTable extends Migration
{
    public function up()
    {
        if (Schema::hasTable('v2_order_trade_alias')) {
            return;
        }

        Schema::create('v2_order_trade_alias', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('order_id');
            $table->string('trade_no', 36)->unique();
            $table->integer('created_at');
            $table->index('order_id');
        });
    }

    public function down()
    {
        Schema::dropIfExists('v2_order_trade_alias');
    }
}
