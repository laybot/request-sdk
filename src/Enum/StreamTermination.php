<?php
declare(strict_types=1);

namespace LayBot\Request\Enum;

enum StreamTermination: string
{
    /**
     * HTTP 响应消息完整结束。
     */
    case MESSAGE_COMPLETE = 'message_complete';

    /**
     * Close-delimited HTTP 响应正常 EOF。
     */
    case EOF = 'eof';

    /**
     * SSE 调用方指定的结束 Token 已出现。
     */
    case DONE_TOKEN = 'done_token';

    /**
     * 数据消费回调返回 false，要求正常提前停止。
     */
    case CALLBACK_STOP = 'callback_stop';

    /**
     * 保留用于显式需要返回取消结果的实现。
     * 当前 HTTP 异步取消默认通过 CancelledException 返回。
     */
    case CANCELLED = 'cancelled';
}
