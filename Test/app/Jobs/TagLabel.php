<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Log;
use Exception;

class TagLabel implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * 重试次数
     * The number of times the job may be attempted.
     *
     * @var int
     */
    public $tries = 5;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct()
    {
        //
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        Log::info('开始执行队列');
        //
        echo "调用队列\n\t";
        sleep(2);
        echo "12222\n\t";
        \Cache::forget('dict-tag-labels-industry');
        \Cache::remember(
            'dict-tag-labels-industry',
            60 * 3,
            function () {
                return "Test队列服务";
            }
        );
        echo "指定Redis链接并分发队列\n\t";
        echo \Cache::get('dict-tag-labels-industry');
        Log::info('队列执行结束');
    }
    /**
     * The job failed to process.
     *
     * @param  Exception  $exception
     * @return void
     */
    public function failed(Exception $exception)
    {
        echo "Tag-Label队列消费失败!";
        //写入日志
        Log::debug($exception);
    }
}
