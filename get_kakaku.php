<?php
/**
 * 最安値更新
 * pre = {
 *   'kakakuとか': {
 *     'price': '',
 *     'expired': '',
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
$pre = json_decode(@file_get_contents(dirname(__FILE__) . '/pre'), true);
//$updated_at = $pre['updated_at'];
//$pre = $pre['price'];


// 最安の取得
$now = [];
$price = lowPriceKakaku($url['kakaku']);
$now['kakaku_new']['price'] = $price;
$now['kakaku_new']['expire'] = time() + 600;
echo "kakaku new: " . number_format($price) . "\n";

if ($pre['amazon_new']['expire'] < time()) {
    $price = lowPriceAmazon($url['amazon'] . '?condition=new');
    $expire = time() + ($price ? 60*30 : 60*60); // 取れなかったら1時間まつ
    echo "amazon new: " . number_format($price) . "\n";
} else {
    $price = $pre['amazon_new']['price'];
    $expire = $pre['amazon_new']['expire'];
    echo "skip: amazon new: " . number_format($price) . "\n";
}
$now['amazon_new']['price'] = $price;
$now['amazon_new']['expire'] = $expire;

sleep(5);

$price = lowPriceKakaku($url['kakaku'] . 'used/');
$now['kakaku_old']['price'] = $price;
$now['kakaku_old']['expire'] = time() + 600;
echo "kakaku old: " . number_format($price) . "\n";

if ($pre['amazon_old']['expire'] < time()) {
    $price = lowPriceAmazon($url['amazon'] . '?condition=old');
    $expire = time() + ($price ? 60*30 : 60*60); // 取れなかったら1時間まつ
    echo "amazon old: " . number_format($price) . "\n";
} else {
    $price = $pre['amazon_old']['price'];
    $expire = $pre['amazon_old']['expire'];
    echo "skip: amazon old: " . number_format($price) . "\n";
}
$now['amazon_old']['price'] = $price;
$now['amazon_old']['expire'] = $expire;

file_put_contents(dirname(__FILE__) . '/pre', json_encode($now));

// 比較して通知
$diff = [
    'kakaku_new' => $now['kakaku_new']['price'] - $pre['kakaku_new']['price'],
    'amazon_new' => $now['amazon_new']['price'] - $pre['amazon_new']['price'],
    'kakaku_old' => $now['kakaku_old']['price'] - $pre['kakaku_old']['price'],
    'amazon_old' => $now['amazon_old']['price'] - $pre['amazon_old']['price'],
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
価格.com(新品) " . formatDiff($pre['kakaku_new']['price'], $now['kakaku_new']['price']) . "
アマゾン(新品) " . formatDiff($pre['amazon_new']['price'], $now['amazon_new']['price']) . "
価格.com(中古) " . formatDiff($pre['kakaku_old']['price'], $now['kakaku_old']['price']) . "
アマゾン(中古) " . formatDiff($pre['amazon_old']['price'], $now['amazon_old']['price']) . "
";
    mb_internal_encoding('UTF-8');
    mb_send_mail('ishikawam@nifty.com', "$title 最安値更新！", $text);
}

/********************************************************/

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
        $text = "→ ±0 $now";
    } elseif ($diff > 0) {
        $text = "↑ +$diff ($pre -> $now)";
    } else {
        $text = "↓ $diff ($pre -> $now)";
    }
    return $text;
}
