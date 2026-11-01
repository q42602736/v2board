<?php
namespace App\Protocols\Singbox;

trait FillsGroups
{
    /**
     * 模板里留空的 selector / urltest 分组，按名称关键字填节点；
     * 空分组会让 sing-box 直接报 initialize outbound[n]: missing tags。
     */
    protected function fillEmptyGroups(array $proxies)
    {
        $tags = [];
        foreach ($proxies as $p) {
            if (!empty($p['tag'])) {
                $tags[] = $p['tag'];
            }
        }
        if (!$tags) {
            return;
        }

        $directTag = 'DIRECT';
        foreach ($this->config['outbounds'] as $o) {
            if (isset($o['type']) && $o['type'] === 'direct' && !empty($o['tag'])) {
                $directTag = $o['tag'];
                break;
            }
        }

        $regions = [
            'HK' => ['香港', 'HK'],
            'TW' => ['台湾', 'TW'],
            'SG' => ['新加坡', 'SG'],
            'JP' => ['日本', '大阪', 'JP'],
            'US' => ['美国', '洛杉矶', '圣何塞', '纽约', '堪萨斯', 'US'],
        ];

        foreach ($this->config['outbounds'] as &$outbound) {
            if (!isset($outbound['type']) || !in_array($outbound['type'], ['selector', 'urltest'], true)) {
                continue;
            }
            if (!empty($outbound['outbounds'])) {
                continue;
            }
            $tag = (string) $outbound['tag'];
            $list = $tags;

            foreach ($regions as $key => $words) {
                if (mb_stripos($tag, $key) === false && mb_stripos($tag, $words[0]) === false) {
                    continue;
                }
                $filtered = [];
                foreach ($tags as $t) {
                    foreach ($words as $w) {
                        if (mb_stripos($t, $w) !== false) {
                            $filtered[] = $t;
                            break;
                        }
                    }
                }
                if ($filtered) {
                    $list = $filtered;
                }
                break;
            }

            if (mb_stripos($tag, '手动选择') !== false || mb_stripos($tag, '节点选择') !== false) {
                $list[] = $directTag;
            }

            $outbound['outbounds'] = array_values(array_unique($list));
        }
        unset($outbound);
    }
}
