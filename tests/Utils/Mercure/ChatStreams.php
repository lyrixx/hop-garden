<?php

namespace App\Tests\Utils\Mercure;

/**
 * What the page of a conversation does with the Turbo Streams of its topic (templates/chat/stream/), one entry per
 * stream: "start" and "done" for the waiting dots, "answer" in a "new" bubble or the "same" one, "tool_call",
 * "draft_card", "error", and "refresh" on the page of a planting.
 */
final class ChatStreams
{
    /**
     * @return list<array<string, mixed>>
     */
    public static function of(string $topic): array
    {
        $events = [];
        foreach (RecordingHub::published($topic) as $update) {
            foreach (TurboStream::parseAll($update) as $stream) {
                $events[] = self::event($stream);
            }
        }

        return $events;
    }

    /**
     * @return array<string, mixed>
     */
    private static function event(TurboStream $stream): array
    {
        $element = !\in_array($stream->action, ['refresh', 'remove'], true) ? $stream->element() : null;

        return match (true) {
            'refresh' === $stream->action => ['type' => 'refresh'],
            'replace' === $stream->action && 'chat-run' === $stream->target => ['type' => 'true' === $element?->attr('data-busy') ? 'start' : 'done'],
            'update' === $stream->action => ['type' => 'answer', 'bubble' => 'same', 'html' => $stream->content->filter('body')->html()],
            'append' === $stream->action && '#chat-log, #drafting-tool-calls' === $stream->targets => ['type' => 'tool_call', 'summary' => $element?->filter('code')->text()],
            'append' !== $stream->action || 'chat-log' !== $stream->target => throw new \LogicException(\sprintf('Unexpected stream "%s".', $stream->action)),
            str_contains((string) $element?->attr('class'), 'bubble-assistant') => ['type' => 'answer', 'bubble' => 'new', 'html' => $element?->html()],
            str_contains((string) $element?->attr('class'), 'chat-card') => ['type' => 'draft_card', 'plant_id' => (int) preg_replace('/\D/', '', (string) $element?->filter('turbo-frame')->attr('id'))],
            str_contains((string) $element?->attr('class'), 'bubble-error') => ['type' => 'error', 'message' => $element?->text()],
            default => throw new \LogicException('Unexpected stream in the chat log.'),
        };
    }
}
