<?php
use LayBot\{Chat,Doc};

/* 非流式一行调用 */
function lb_chat(string $key,array $body): array
{
    return (new Chat($key))->completions($body);
}

/* 文档解析一行调用（仅 LayBot base） */
function lb_doc(string $key,string $url,string $mode='text',bool $math=false): array
{
    return (new Doc($key))->extract($url,$mode,$math);
}
