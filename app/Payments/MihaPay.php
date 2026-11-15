<?php

namespace App\Payments;

class MihaPay
{
    private $config;

    public function __construct($config)
    {
        $this->config = $config;
    }

    public function form()
    {
        return [
            'mihapay_url' => [
                'label' => 'MAPI接口地址',
                'description' => '可填写 https://miha.ftykh.top/mapi.php 或站点根地址，根地址会自动追加 /mapi.php',
                'type' => 'input',
            ],
            'mihapay_pid' => [
                'label' => '商户ID',
                'description' => '米哈支付商户中心中的商户ID',
                'type' => 'input',
            ],
            'mihapay_key' => [
                'label' => '商户密钥',
                'description' => '米哈支付商户中心中的商户密钥',
                'type' => 'input',
            ],
            'mihapay_type' => [
                'label' => '支付类型',
                'description' => '支持 alipay、wxpay、qqpay，默认 alipay',
                'type' => 'input',
            ],
        ];
    }

    public function pay($order)
    {
        $pid = trim((string) ($this->config['mihapay_pid'] ?? ''));
        $key = trim((string) ($this->config['mihapay_key'] ?? ''));
        $type = strtolower(trim((string) ($this->config['mihapay_type'] ?? 'alipay')));

        if ($pid === '' || $key === '') {
            abort(500, '米哈支付商户配置不完整');
        }
        if (!in_array($type, ['alipay', 'wxpay', 'qqpay'], true)) {
            abort(500, '米哈支付类型配置无效');
        }

        $params = [
            'pid' => $pid,
            'type' => $type,
            'out_trade_no' => (string) $order['trade_no'],
            'notify_url' => (string) $order['notify_url'],
            'return_url' => (string) $order['return_url'],
            'name' => (string) config('v2board.app_name', 'V2Board') . '订阅',
            'money' => number_format($order['total_amount'] / 100, 2, '.', ''),
            'clientip' => (string) (request()->ip() ?: '127.0.0.1'),
            'device' => $this->getDevice(),
            'sign_type' => 'MD5',
        ];
        $params['sign'] = $this->makeSign($params, $key);

        $result = $this->post($this->getApiUrl(), $params);

        // 网关对同一个 out_trade_no 只允许下单一次：首次即使取码失败
        // （渠道异常、出码失败）也会占用该单号，用户再次支付就报
        // 「商户订单号已存在」，订单从此永远付不了。
        // 失败时换新单号重试，旧单号记入别名表防止掉单。
        if ((int) ($result['code'] ?? 0) !== 1
            && $this->isRetryableFailure(trim((string) ($result['msg'] ?? '')))) {
            for ($attempt = 0; $attempt < 2; $attempt++) {
                if (!$this->reorderWithNewTradeNo($order, $params, $key)) {
                    break;
                }
                $result = $this->post($this->getApiUrl(), $params);
                if ((int) ($result['code'] ?? 0) === 1) {
                    break;
                }
                if (!$this->isRetryableFailure(trim((string) ($result['msg'] ?? '')))) {
                    break;
                }
            }
        }

        if ((int) ($result['code'] ?? 0) !== 1) {
            $message = trim((string) ($result['msg'] ?? ''));
            abort(500, $message === '' ? '米哈支付下单失败' : $message);
        }

        // 网关按 out_trade_no 缓存收银台链接：同一单号先下过微信，用户再切
        // 支付宝时拿到的还是微信那条链接，点开就报「渠道参数校验不通过」。
        // 链接渠道和所选支付方式不一致时换号重新下单。
        for ($attempt = 0; $attempt < 2 && $this->isChannelMismatch($type, $result); $attempt++) {
            if (!$this->reorderWithNewTradeNo($order, $params, $key)) {
                break;
            }
            $result = $this->post($this->getApiUrl(), $params);
            if ((int) ($result['code'] ?? 0) !== 1) {
                $message = trim((string) ($result['msg'] ?? ''));
                abort(500, $message === '' ? '米哈支付下单失败' : $message);
            }
        }

        // 米哈支付返回的 qrcode / cashurl / payurl 都是「收银台网页地址」，
        // 按服务商要求必须直接跳转收银台，禁止自行渲染二维码。
        $cashier = $this->resolveCashierUrl($result);
        if ($cashier !== null) {
            return [
                'type' => 1,
                'data' => $cashier,
            ];
        }

        // 兼容个别通道返回图片型二维码（data:image 或图片直链）
        if (!empty($result['qrcode']) && is_string($result['qrcode']) && $this->isImagePayload($result['qrcode'])) {
            return [
                'type' => 0,
                'data' => $result['qrcode'],
            ];
        }

        if (!empty($result['html']) && is_string($result['html'])) {
            $html = (string) $result['html'];
            $fromHtml = $this->extractCashierFromHtml($html);
            if ($fromHtml !== null) {
                return [
                    'type' => 1,
                    'data' => $fromHtml,
                ];
            }
            $qrcode = $this->extractQrCode($html);
            if ($qrcode !== null) {
                return [
                    'type' => 0,
                    'data' => $qrcode,
                ];
            }
        }

        abort(500, '米哈支付未返回有效的支付地址');
    }

    /**
     * 判断失败是否值得换单号重试。
     * 只有网关侧（单号占用、渠道取码失败、系统繁忙）才换，
     * 金额/配置类错误换号也没用，直接报给用户。
     */
    private function isRetryableFailure($message)
    {
        if ($message === '') {
            return true;
        }

        return (bool) preg_match('/已存在|重复|出码|系统|繁忙|超时|错误|失败|异常|empty|error/i', $message);
    }

    /**
     * 换新单号并就地更新下单参数，供失败重试与渠道纠正复用。
     */
    private function reorderWithNewTradeNo(array &$order, array &$params, $key)
    {
        $newTradeNo = $this->rotateTradeNo($order, (string) $order['trade_no']);
        if ($newTradeNo === null) {
            return false;
        }

        // return_url 里带着旧单号，换号后同步替换，支付完回来才能看到本单
        if (!empty($order['return_url'])) {
            $order['return_url'] = str_replace(
                (string) $params['out_trade_no'],
                $newTradeNo,
                (string) $order['return_url']
            );
            $params['return_url'] = $order['return_url'];
        }

        $order['trade_no'] = $newTradeNo;
        $params['out_trade_no'] = $newTradeNo;
        $params['sign'] = $this->makeSign($params, $key);

        usleep(200000);
        return true;
    }

    /**
     * 收银台链接的渠道和用户所选支付方式是否不一致。
     */
    private function isChannelMismatch($type, array $result)
    {
        $type = (string) $type;
        if ($type === '') {
            return false;
        }
        $url = strtolower((string) ($result['qrcode'] ?? ''));
        if ($url === '') {
            return false;
        }
        if (strpos($url, 'youzan') !== false || strpos($url, 'weixin') !== false) {
            return $type !== 'wxpay';
        }
        if (strpos($url, 'zfb') !== false || strpos($url, 'alipay') !== false) {
            return $type !== 'alipay';
        }
        return false;
    }

    /**
     * 换一个新的订单号并保留旧单号别名。
     * 返回新单号，失败返回 null。
     */
    private function rotateTradeNo(array $order, $oldTradeNo)
    {
        $orderId = (int) ($order['order_id'] ?? 0);
        if ($orderId <= 0) {
            return null;
        }

        for ($i = 0; $i < 3; $i++) {
            $newTradeNo = \App\Utils\Helper::generateOrderNo();
            try {
                $updated = \App\Models\Order::where('id', $orderId)
                    ->where('status', 0)
                    ->update(['trade_no' => $newTradeNo]);
            } catch (\Exception $e) {
                // 单号撞车，换一个再试
                continue;
            }
            if (!$updated) {
                return null;
            }
            try {
                \App\Models\OrderTradeAlias::firstOrCreate(
                    ['trade_no' => $oldTradeNo],
                    ['order_id' => $orderId, 'created_at' => time()]
                );
            } catch (\Exception $e) {
                // 别名写入失败不影响当前支付
            }

            return $newTradeNo;
        }

        return null;
    }

    private function getApiUrl()
    {
        $url = rtrim(trim((string) ($this->config['mihapay_url'] ?? '')), '/');
        if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
            abort(500, '米哈支付接口地址无效');
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if (!in_array($scheme, ['http', 'https'], true)) {
            abort(500, '米哈支付接口地址协议无效');
        }

        $path = (string) parse_url($url, PHP_URL_PATH);
        return preg_match('/\.php$/i', $path) ? $url : $url . '/mapi.php';
    }

    private function post($url, array $params)
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($params, '', '&', PHP_QUERY_RFC3986),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/x-www-form-urlencoded',
            ],
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $body = curl_exec($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($body === false) {
            abort(500, $error !== '' ? '米哈支付网络请求失败: ' . $error : '米哈支付网络请求失败');
        }
        if ($status < 200 || $status >= 300) {
            abort(500, '米哈支付接口返回异常');
        }

        $result = json_decode($body, true);
        if (!is_array($result)) {
            abort(500, '米哈支付接口返回格式错误');
        }

        return $result;
    }

    /**
     * 从 mapi 返回中取出收银台跳转地址。
     * 服务商明确说明：qrcode 字段即收银台地址。
     */
    private function resolveCashierUrl(array $result)
    {
        foreach (['qrcode', 'cashurl', 'payurl', 'qr_code', 'url'] as $field) {
            if (empty($result[$field]) || !is_string($result[$field])) {
                continue;
            }
            $value = trim($result[$field]);
            if ($value === '' || $this->isImagePayload($value)) {
                continue;
            }
            if ($this->isHttpUrl($value)) {
                return $value;
            }
        }

        return null;
    }

    private function isHttpUrl($value)
    {
        return (bool) preg_match('#^https?://#i', trim((string) $value));
    }

    private function isImagePayload($value)
    {
        $value = trim((string) $value);
        if ($value === '') {
            return false;
        }
        if (stripos($value, 'data:image/') === 0) {
            return true;
        }
        if (preg_match('#^https?://#i', $value)) {
            return (bool) preg_match('#\.(png|jpe?g|gif|webp|bmp|svg)(\?|$)#i', $value);
        }

        return false;
    }

    private function extractCashierFromHtml($html)
    {
        if (preg_match('#<meta[^>]+http-equiv=["\']?refresh["\']?[^>]*content=["\'][^"\'>]*url=([^"\'>\s]+)#i', $html, $m)) {
            $url = html_entity_decode(trim($m[1]), ENT_QUOTES);
            if ($this->isHttpUrl($url)) {
                return $url;
            }
        }
        if (preg_match('#(?:window\.)?location(?:\.href)?\s*=\s*["\'](https?://[^"\']+)["\']#i', $html, $m)) {
            return html_entity_decode(trim($m[1]), ENT_QUOTES);
        }

        return null;
    }

    private function getDevice()
    {
        $userAgent = strtolower((string) request()->userAgent());
        if (strpos($userAgent, 'micromessenger') !== false) {
            return 'wechat';
        }
        if (strpos($userAgent, 'alipayclient') !== false) {
            return 'alipay';
        }
        if (strpos($userAgent, 'mqqbrowser') !== false || strpos($userAgent, ' qq/') !== false) {
            return 'qq';
        }
        if (preg_match('/android|iphone|ipad|ipod|mobile/i', $userAgent)) {
            return 'mobile';
        }

        return 'pc';
    }

    private function makeSign(array $params, $key)
    {
        unset($params['sign'], $params['sign_type']);
        ksort($params);

        $pairs = [];
        foreach ($params as $name => $value) {
            if ($value === '' || $value === null) {
                continue;
            }
            $pairs[] = $name . '=' . (string) $value;
        }

        return strtolower(md5(implode('&', $pairs) . $key));
    }

    private function extractQrCode($html)
    {
        if (!class_exists('DOMDocument')) {
            return null;
        }

        $document = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $loaded = $document->loadHTML('<?xml encoding="UTF-8">' . $html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (!$loaded) {
            return null;
        }

        $image = $document->getElementsByTagName('img')->item(0);
        if (!$image) {
            return null;
        }

        $qrcode = trim($image->getAttribute('src'));
        return $qrcode !== '' ? $qrcode : null;
    }

    public function notify($params)
    {
        if (!is_array($params) || !isset($params['sign']) || is_array($params['sign'])) {
            return false;
        }

        $pid = trim((string) ($this->config['mihapay_pid'] ?? ''));
        $key = trim((string) ($this->config['mihapay_key'] ?? ''));
        $sign = strtolower(trim((string) $params['sign']));
        if ($pid === '' || $key === '' || $sign === '') {
            return false;
        }
        if (!isset($params['pid']) || (string) $params['pid'] !== $pid) {
            return false;
        }
        if (!hash_equals($this->makeSign($params, $key), $sign)) {
            return false;
        }
        if (($params['trade_status'] ?? '') !== 'TRADE_SUCCESS') {
            return false;
        }

        $tradeNo = trim((string) ($params['out_trade_no'] ?? ''));
        $callbackNo = trim((string) ($params['trade_no'] ?? ''));
        if ($tradeNo === '' || $callbackNo === '') {
            return false;
        }

        return [
            'trade_no' => $tradeNo,
            'callback_no' => $callbackNo,
        ];
    }
}
