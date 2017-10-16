<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

use Abraham\TwitterOAuth\TwitterOAuth;

class IndexController extends Controller
{
    // index
    public function index()
    {
        // callback型のoauthはできない。httpなので。PINでaccess_tokenを取得する。

        // PINが送信されたら
        $pin = \Request::input('oauth_verifier');
        if ($pin) {
            $request_token = \Session::pull('oauth_token'); // すぐ消す
            $connection = new TwitterOAuth(config('app.CONSUMER_KEY'), config('app.CONSUMER_SECRET'), $request_token['oauth_token'], $request_token['oauth_token_secret']);
            $access_token = $connection->oauth('oauth/access_token', ['oauth_verifier' => $pin]);

            var_dump($access_token);
            return;
        }

        if (! \Session::get('oauth_token')) {
            // 最初
            $connection = new TwitterOAuth(config('app.CONSUMER_KEY'), config('app.CONSUMER_SECRET'));
            $request_token = $connection->oauth('oauth/request_token');
            \Session::put('oauth_token', $request_token);
            $url = $connection->url('oauth/authenticate', [
                    'oauth_token' => $request_token['oauth_token'],
                ]);
        }

        return view('welcome', [
                'url' => $url ?? '',
            ]);
    }
}
