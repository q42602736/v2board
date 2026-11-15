<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 支付单号别名。
 *
 * 网关（如米哈支付）对同一个 out_trade_no 只允许下单一次，首次下单即使
 * 取码失败也会占用该单号，再次提交就会返回「商户订单号已存在」。
 * 因此支付失败重试时会换一个新 trade_no，旧单号写入本表，
 * 保证万一用户还付了旧单号，回调依然能找回订单。
 */
class OrderTradeAlias extends Model
{
    protected $table = 'v2_order_trade_alias';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];
    public $timestamps = false;
    protected $casts = [
        'created_at' => 'timestamp',
    ];
}
