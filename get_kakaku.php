<?php
/**
 * 最安値更新
 * pre = {
 *   'kakakuとか': {
 *     'price': '',
 *     'expired': '',
 *     'updated_at': '',
 *   }
 * }
 */

$title = 'α99M2';

$url = [
    'kakaku' => 'http://kakaku.com/item/K0000912430/',
    'amazon' => 'https://www.amazon.co.jp/gp/offer-listing/B01LX4PJTX',
];

/********************************************************/

// まず前回の情報を取得
$pre_array = json_decode(@file_get_contents(dirname(__FILE__) . '/pre'), true);


// 商品
$item = [
    'title' => 'α99M2',
    'target' => [
        // リストにしているのは重複商品があるかもだから。
        [
            'type' => 'kakaku',
            'item_id' => 'K0000912430',
        ],
        [
            'type' => 'amazon',
            'item_id' => 'B01LX4PJTX',
        ],
    ],
];


// 最安の取得
foreach ($item['target'] as $target) {
    $key = sha1(serialize($target));
    $pre = $pre_array[$key] ?? [];
    $now = $pre;
    $url_new = null;
    $url_old = null;
    if ($target['type'] == 'kakaku') {
        $url_new = sprintf('http://kakaku.com/item/%s/', $target['item_id']);
        $url_old = sprintf('http://kakaku.com/item/%s/used/', $target['item_id']);
        $expire_normal = 60*10;
        $expire_error = 60*10;
    } elseif ($target['type'] == 'amazon') {
        $url_new = sprintf('https://www.amazon.co.jp/gp/offer-listing/%s?condition=new', $target['item_id']);
        $url_old = sprintf('https://www.amazon.co.jp/gp/offer-listing/%s?condition=old', $target['item_id']);
        $expire_normal = 60*60;
        $expire_error = 60*60*2;
    }

    // 新品
    if (($pre['new']['expire'] ?? 0) < time()) {
        $price = lowPrice($target['type'], $url_new);
        if ($price) {
            $now['new']['price'] = $price;
            $now['new']['updated_at'] = time();
            $expire = time() + $expire_normal;
            echo "{$target['type']} new: " . number_format($price) . "\n";
        } else {
            $expire = time() + $expire_error; // 取れなかったら結構待つ
        }
        $now['new']['expire'] = $expire;
    }

    // 中古
    if (($pre['old']['expire'] ?? 0) < time()) {
        $price = lowPrice($target['type'], $url_old);
        if ($price) {
            $now['old']['price'] = $price;
            $now['old']['updated_at'] = time();
            $expire = time() + $expire_normal;
            echo "{$target['type']} old: " . number_format($price) . "\n";
        } else {
            $expire = time() + $expire_error; // 取れなかったら結構待つ
        }
        $now['old']['expire'] = $expire;
    }

    $now_array[$key] = $now;

    // 比較して通知
    $diff = [
        'new' => ($now['new']['price'] ?? 0) - ($pre['new']['price'] ?? 0),
        'old' => ($now['old']['price'] ?? 0) - ($pre['old']['price'] ?? 0),
    ];

    $is_notice = false;
    foreach ($diff as $val) {
        if ($val != 0) { // 価格が変わったら
//    if ($val < 0) { // 価格が下がったら
            $is_notice = true;
            break;
        }
    }

    if ($is_notice) {
        echo "$title 最安値更新！\n";
        $text = "
{$target['type']}(新品) " . formatDiff($pre['new']['price'], $now['new']['price']) . "
{$target['type']}(中古) " . formatDiff($pre['old']['price'], $now['old']['price']) . "
";
        echo "$text\n";
        mb_internal_encoding('UTF-8');
        mb_send_mail('ishikawam@nifty.com', "$title 最安値更新！", $text);
    }

}

file_put_contents(dirname(__FILE__) . '/pre', json_encode($now_array));


/********************************************************/

function lowPrice(string $type, string $url) {
    if ($type == 'kakaku') {
        return lowPriceKakaku($url);
    } elseif ($type == 'amazon') {
        return lowPriceAmazon($url);
    }
    return null;
}

function lowPriceKakaku(string $url) {
    $str = file_get_contents($url);
    preg_match('/lowPrice">&yen;([^<]+)</', $str, $out);
    return str_replace(',', '', $out[1]);
}

function lowPriceAmazon(string $url) {
    $context = stream_context_create([
            'http' => [
                'method' => 'GET',
//                'ignore_errors' => true, // エラーページも内容取得
//                'timeout' => 10,
                'header' => 'User-Agent: Mozilla/5.0 (Macintosh; Intel Mac OS X 10_12_6) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/61.0.3163.91 Safari/537.36',
            ]
        ]);
    $str = file_get_contents($url, false, $context);
    preg_match_all('/olpOfferPrice[^>]+>[^0-9]*([0-9,]+)/', $str, $out);
    $price = null;
    foreach ($out[1] as $val) {
        $tmp_price = str_replace(',', '', $val);
        $price = $price ? min($price, $tmp_price) : $tmp_price;
    }
    return $price;
}

function formatDiff(int $pre = null, int $now = null) {
    $diff = $now - $pre;
    $now = number_format($now);
    $pre = number_format($pre);
    if ($diff == 0) {
        $text = "→ ±0 ($now)";
    } elseif ($diff > 0) {
        $text = "↑ +$diff ($pre -> $now)";
    } else {
        $text = "↓ $diff ($pre -> $now)";
    }
    return $text;
}
