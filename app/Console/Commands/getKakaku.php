<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class getKakaku extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'command:get-kakaku';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '最安値を取得して更新';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        \Log::info(__CLASS__.':'.__LINE__, ['start']);

        // まず前回の情報を取得
        $price_path = storage_path('app/price.json');
        $pre_prices = file_exists($price_path) ? json_decode(file_get_contents($price_path), true) : [];

        // 商品
        $config_path = config_path(sprintf('json/%s.json', config('app.env')));
        $items = json_decode(file_get_contents($config_path), true);

        foreach ($items as $index => $item) {
            $now_prices[$index] = [];

            // 最安の取得
            foreach ($item['target'] as $target) {
                $key = sprintf('%s-%s', $target['type'], $target['item_id']);
                $pre = $pre_prices[$index][$key] ?? [];
                $now = $pre;
                $url_new = null;
                $url_old = null;

                switch ($target['type']) {
                    case 'kakaku':
                        $url_new = sprintf('http://kakaku.com/item/%s/', $target['item_id']);
                        $url_old = sprintf('http://kakaku.com/item/%s/used/', $target['item_id']);
                        $expire_normal = 60*10;
                        $expire_error = 60*10;
                        break;
                    case 'amazon':
                        $url_new = sprintf('https://www.amazon.co.jp/gp/offer-listing/%s?condition=new', $target['item_id']);
                        $url_old = sprintf('https://www.amazon.co.jp/gp/offer-listing/%s?condition=old', $target['item_id']);
                        $expire_normal = 60*60;
                        $expire_error = 60*60*2;
                        break;
                }

                // 新品
                if (($pre['new']['expire'] ?? 0) < time()) {
                    $price = self::lowPrice($target['type'], $url_new);
                    if ($price) {
                        $now['new']['price'] = $price;
                        $now['new']['updated_at'] = time();
                        $expire = time() + $expire_normal;
                        \Log::info("{$target['type']} new: " . number_format($price));
                    } else {
                        $expire = time() + $expire_error; // 取れなかったら結構待つ
                    }
                    $now['new']['expire'] = $expire;
                }

                // 中古
                if (($pre['old']['expire'] ?? 0) < time()) {
                    $price = self::lowPrice($target['type'], $url_old);
                    if ($price) {
                        $now['old']['price'] = $price;
                        $now['old']['updated_at'] = time();
                        $expire = time() + $expire_normal;
                        \Log::info("{$target['type']} old: " . number_format($price));
                    } else {
                        $expire = time() + $expire_error; // 取れなかったら結構待つ
                    }
                    $now['old']['expire'] = $expire;
                }

                $now_prices[$index][$key] = $now;

                // 比較して通知
                $diff = [
                    'new' => ($now['new']['price'] ?? 0) - ($pre['new']['price'] ?? 0),
                    'old' => ($now['old']['price'] ?? 0) - ($pre['old']['price'] ?? 0),
                ];

                $is_notice = false;
                foreach ($diff as $val) {
                    if ($val != 0) { // 価格が変わったら。下がったらでもいいかも？
                        $is_notice = true;
                        break;
                    }
                }

                if ($is_notice) {
                    \Log::info("{$item['title']} 最安値更新！");
                    $text = "
{$target['type']}(新品) " . self::formatDiff(($pre['new']['price'] ?? null), $now['new']['price']) . "
{$target['type']}(中古) " . self::formatDiff(($pre['old']['price'] ?? null), $now['old']['price']) . "
";
                    \Log::info($text);
                    mb_internal_encoding('UTF-8');
                    mb_send_mail('ishikawam@nifty.com', "{$item['title']} 最安値更新！", $text);
                }
            }
        }

        file_put_contents($price_path, json_encode($now_prices));
    }


    /**
     * 最安値を取得
     * @param string $type
     * @param string $url
     * @return string|null
     */
    private static function lowPrice(string $type, string $url)
    {
        if ($type == 'kakaku') {
            return self::lowPriceKakaku($url);
        } elseif ($type == 'amazon') {
            return self::lowPriceAmazon($url);
        }

        return null;
    }

    private static function lowPriceKakaku(string $url)
    {
        $str = file_get_contents($url);
        preg_match('/lowPrice">&yen;([^<]+)</', $str, $out);

        return str_replace(',', '', $out[1]);
    }

    private static function lowPriceAmazon(string $url)
    {
        $context = stream_context_create([
                'http' => [
                    'method' => 'GET',
//                    'ignore_errors' => true, // エラーページも内容取得
//                    'timeout' => 10,
//                    'header' => 'User-Agent: Mozilla/5.0 (Macintosh; Intel Mac OS X 10_12_6) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/61.0.3163.91 Safari/537.36',
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

    /**
     * 価格変動の文整形
     * @param int|null $pre
     * @param int $now
     */
    private static function formatDiff(int $pre = null, int $now)
    {
        if (is_null($pre)) {
            return number_format($now);
        }

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
}
