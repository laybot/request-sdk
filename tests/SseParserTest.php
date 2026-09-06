<?php
declare(strict_types=1);

namespace LayBot\Request\Tests;

use LayBot\Request\DTO\SseEvent;
use LayBot\Request\Stream\SseParser;
use PHPUnit\Framework\TestCase;

final class SseParserTest extends TestCase
{
    public function testSplitAndMultipleEvents(): void
    {
        $parser = new SseParser('[DONE]');
        $events = [];

        $emit = static function (
            SseEvent $event
        ) use (&$events): void {
            if (!$event->comment) {
                $events[] = $event;
            }
        };

        $parser->feed("event: delta\ndata: 你", $emit);
        $parser->feed("好\ndata: 世界\n\n", $emit);
        $parser->feed(
            "data: second\n\ndata: [DONE]\n\n",
            $emit
        );

        self::assertCount(2, $events);
        self::assertSame("你好\n世界", $events[0]->data);
        self::assertSame('delta', $events[0]->event);
        self::assertSame('second', $events[1]->data);
        self::assertTrue($parser->doneSeen());
    }

    public function testCommentAndLastLineWithoutNewline(): void
    {
        $parser = new SseParser();
        $events = [];

        $parser->feed(
            ": heartbeat\ndata: final",
            static function (SseEvent $event) use (&$events): void {
                $events[] = $event;
            }
        );

        $parser->finish(
            static function (SseEvent $event) use (&$events): void {
                $events[] = $event;
            }
        );

        self::assertCount(2, $events);
        self::assertTrue($events[0]->comment);
        self::assertSame('final', $events[1]->data);
    }

    public function testDoneTokenIsDetected(): void
    {
        $parser = new SseParser('[DONE]');
        $events = [];

        $parser->feed(
            "data: one\n\ndata: [DONE]\n\n",
            static function (SseEvent $event) use (&$events): void {
                $events[] = $event;
            }
        );

        self::assertTrue($parser->doneSeen());
        self::assertCount(1, $events);
        self::assertSame('one', $events[0]->data);
    }

}
