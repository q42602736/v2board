<?php

namespace App\Http\Controllers\V1\Guest;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderTradeAlias;
use App\Services\OrderService;
use App\Services\PaymentService;
use App\Services\TelegramService;
use Illuminate\Http\Request;

class PaymentController extends Controller
{
    public function notify($method, $uuid, Request $request)
    {
        try {
            $paymentService = new PaymentService($method, null, $uuid);
            $verify = $paymentService->notify($request->input());
            if (!$verify) abort(500, 'verify error');
            if (!$this->handle($verify['trade_no'], $verify['callback_no'])) {
                abort(500, 'handle error');
            }
            return(isset($verify['custom_result']) ? $verify['custom_result'] : 'success');
        } catch (\Exception $e) {
            abort(500, 'fail');
        }
    }

    private function handle($tradeNo, $callbackNo)
    {
        $order = Order::where('trade_no', $tradeNo)->first();
        if (!$order) {
            // 支付失败重试时订单会换成新单号，旧单号挂在别名表里，
            // 用户若仍付了旧单号，这里把订单找回来，避免掉单。
            $alias = OrderTradeAlias::where('trade_no', $tradeNo)->first();
            if ($alias) {
                $order = Order::find($alias->order_id);
            }
        }
        if (!$order) {
            abort(500, 'order is not found');
        }
        if ($order->status !== 0) return true;
        $orderService = new OrderService($order);
        if (!$orderService->paid($callbackNo)) {
            return false;
        }
        $telegramService = new TelegramService();
        $message = sprintf(
            "💰成功收款%s元\n———————————————\n订单号：%s",
            $order->total_amount / 100,
            $order->trade_no
        );
        $telegramService->sendMessageWithAdmin($message);
        return true;
    }
}
