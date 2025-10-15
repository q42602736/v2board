<?php

namespace App\Services;

use App\Models\Plan;
use App\Models\Order;
use App\Models\User;

class PurchaseLimitService
{
    /**
     * 检查用户购买资格
     * 周期性限购：在套餐有效期内限购N次，到期后重置可重新购买
     */
    public function checkPurchaseEligibility($userId, $planId)
    {
        $plan = Plan::find($planId);

        if (!$plan) {
            return ['eligible' => false, 'reason' => 'plan_not_found'];
        }

        // 检查是否设置了购买限制
        if ($plan->purchase_limit_count == 0) {
            return ['eligible' => true, 'reason' => 'no_limit'];
        }

        // 检查用户当前是否有该套餐且在有效期内
        $user = User::find($userId);
        if (!$user) {
            return ['eligible' => false, 'reason' => 'user_not_found'];
        }

        $hasActivePlan = ($user->plan_id == $planId && $user->expired_at > time());

        if ($hasActivePlan) {
            // 用户当前有该套餐且在有效期内，检查购买次数限制

            // 统计用户购买该套餐的总次数
            $totalPurchaseCount = Order::where('user_id', $userId)
                                      ->where('plan_id', $planId)
                                      ->where('status', 3)
                                      ->count();

            // 检查是否超过购买限制
            if ($totalPurchaseCount >= $plan->purchase_limit_count) {
                return [
                    'eligible' => false,
                    'reason' => 'limit_exceeded_in_period',
                    'purchased_count' => $totalPurchaseCount,
                    'limit_count' => $plan->purchase_limit_count,
                    'expired_at' => $user->expired_at
                ];
            }

            // 在限制次数内，允许购买
            return [
                'eligible' => true,
                'reason' => 'within_period_limit',
                'purchased_count' => $totalPurchaseCount,
                'limit_count' => $plan->purchase_limit_count,
                'expired_at' => $user->expired_at
            ];
        }

        // 用户没有该套餐或套餐已到期，允许购买（开始新的周期）
        return [
            'eligible' => true,
            'reason' => 'new_period_start',
            'purchased_count' => 0,
            'limit_count' => $plan->purchase_limit_count
        ];
    }
    
    /**
     * 获取用户购买统计
     */
    public function getUserPurchaseStats($userId, $planId)
    {
        $plan = Plan::find($planId);
        
        if (!$plan) {
            return null;
        }
        
        $purchaseCount = Order::where('user_id', $userId)
                             ->where('plan_id', $planId)
                             ->where('status', 3)
                             ->count();
        
        return [
            'plan_name' => $plan->name,
            'purchased_count' => $purchaseCount,
            'limit_count' => $plan->purchase_limit_count,
            'remaining_count' => max(0, $plan->purchase_limit_count - $purchaseCount)
        ];
    }



}
