<?php

namespace App\Tests\Integration\Chat;

use App\Chat\ChatPublisher;
use App\Chat\Model\ChatEvent;
use App\Tests\Mercure\ChatStreams;
use App\Tests\Mercure\RecordingHub;
use App\Tests\Mercure\UnreachableHub;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\Logger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Twig\Environment;

/**
 * The streams are rendered by the Twig of the application: they use its Twig Components.
 */
final class ChatPublisherTest extends KernelTestCase
{
    public function testAnEventGoesToTheTopicOfItsConversation(): void
    {
        RecordingHub::reset();
        $publisher = new ChatPublisher(new RecordingHub(), self::twig(), new Logger('test'));

        $publisher->publish('0193a5a0-0000-7000-8000-000000000000', ChatEvent::ToolCall, ['summary' => 'search_plant(query: "hortensia")']);

        self::assertSame('chat/0193a5a0-0000-7000-8000-000000000000', ChatPublisher::topic('0193a5a0-0000-7000-8000-000000000000'));
        self::assertSame(
            [['type' => 'tool_call', 'summary' => 'search_plant(query: "hortensia")']],
            ChatStreams::of('0193a5a0-0000-7000-8000-000000000000'),
        );
    }

    public function testAHubDownIsAWarningOnly(): void
    {
        $logs = new TestHandler();
        $publisher = new ChatPublisher(new UnreachableHub(), self::twig(), new Logger('test', [$logs]));

        $publisher->publish('0193a5a0-0000-7000-8000-000000000000', ChatEvent::Done);

        self::assertTrue($logs->hasRecordThatContains('Could not push a chat event.', Level::Warning));
        self::assertSame('done', $logs->getRecords()[0]->context['event']);
    }

    private static function twig(): Environment
    {
        return self::getContainer()->get(Environment::class);
    }
}
