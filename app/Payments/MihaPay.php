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
        if ((int) ($result['code'] ?? 0) !== 1) {
            $message = trim((string) ($result['msg'] ?? ''));
            abort(500, $message !== '' ? $message : '米哈支付下单失败');
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
