<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Jobs\TagLabel;
use Illuminate\Support\Facades\Redis;

class TestController extends Controller
{
    //
    public function test($uid = 0, $score = 0)
    {
        $data['uid'] = $uid;
        $data['score'] = $score;
        $date = date('Y-m-d', time());
//        $redisKey = 'pintu:scoredevice-' . $date;
        $redisKey = 'pintu:scoredevice-1';
        dump(Redis::hgetall($redisKey));
        $result = Redis::hget($redisKey, $data['uid']);
        dump($result);
        if ($result) {
            return 0;
        }

        dump(Redis::exists($redisKey));
        dump(Redis::hget($redisKey, '530'));
        if (!Redis::exists($redisKey)) {
            Redis::hset($redisKey, $data['uid'], $data['score']);
            dump('Test1');
            Redis::expireAt($redisKey, time()+500);
        } else {
            Redis::hset($redisKey, $data['uid'], $data['score']);
            dump('Test2');
        }
        if (true) {
            Redis::hset($redisKey, $data['uid'], $data['score']);
        }
//        echo $redisKey;
        dump(Redis::exists($redisKey));
        dump(Redis::KEYS('*'));

//        return Redis::get('key');
//        $job = (new TagLabel())->onConnection('redis')->onQueue('pintu:tag-label');
//        $this->dispatch($job);
//        $this->dispatch(new TagLabel());
    }

    public function test1()
    {
        echo "fffff";
    }
}
