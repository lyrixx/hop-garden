<?php

namespace App\Chat\Model;

/**
 * The answer the agent streams in a bubble of the chat. A tool call line or a draft card closes the bubble: what the
 * agent says next goes in a new one.
 */
final class StreamedAnswer
{
    private string $id;
    private string $text = '';

    public function __construct()
    {
        $this->id = self::newId();
    }

    /**
     * @return bool whether the text opens a new bubble
     */
    public function append(string $text): bool
    {
        $opens = '' === $this->text;
        $this->text .= $text;

        return $opens;
    }

    public function close(): void
    {
        if ('' !== $this->text) {
            $this->id = self::newId();
            $this->text = '';
        }
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getText(): string
    {
        return $this->text;
    }

    private static function newId(): string
    {
        return 'chat-answer-' . bin2hex(random_bytes(6));
    }
}
