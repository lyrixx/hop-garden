<?php

namespace App\Tests\Unit\Garden;

use App\DataSource\DataSourceCache;
use App\DataSource\Demo\RecordedLatinNames;
use App\Garden\LatinNameResolver;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Symfony\AI\Platform\Exception\RuntimeException;
use Symfony\AI\Platform\Result\TextResult;
use Symfony\AI\Platform\Test\MockPlatformFactory;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

/**
 * The model is simulated: what is asked, what is kept of its answer, and what is cached.
 */
final class LatinNameResolverTest extends TestCase
{
    /** @var list<array{payload: mixed, options: array<string, mixed>}> */
    private array $calls = [];

    /** @var list<string> */
    private array $answers = [];

    /** @var list<string> */
    private array $warnings = [];

    public function testTheLlmIsAskedOncePerNameAndLanguage(): void
    {
        $this->answers = ['["Rosa"]', '["Rosa"]'];
        $resolver = $this->resolver();

        self::assertSame(['Rosa'], $resolver->resolve('rosier', 'fr'));
        self::assertSame(['Rosa'], $resolver->resolve('Rosier', 'fr'), 'Cached, whatever its case.');
        self::assertCount(1, $this->calls);

        $resolver->resolve('rosier', 'it');
        self::assertCount(2, $this->calls, 'Another language, another question.');
    }

    public function testTheQuestionIsShort(): void
    {
        $this->answers = ['["Actinidia deliciosa"]'];

        $this->resolver()->resolve('arbre à kiwi', 'fr');

        $messages = $this->calls[0]['payload']['messages'] ?? self::fail('No conversation.');
        self::assertSame('system', $messages[0]['role']);
        $prompt = (string) preg_replace('/\s+/', ' ', $messages[0]['content']);
        self::assertStringContainsString('A gardener types the name of a plant, in French.', $prompt);
        self::assertStringContainsString('3 at most. Answer with a JSON array of strings and nothing else', $prompt);
        self::assertSame(['role' => 'user', 'content' => 'arbre à kiwi'], [...$messages[1], 'content' => $messages[1]['content'][0]['text'] ?? $messages[1]['content']]);
        self::assertSame(100, $this->calls[0]['options']['max_output_tokens'] ?? null);
    }

    public function testOnlyTheNamesOfTheAnswerAreKept(): void
    {
        $this->answers = ["Here they are:\n```json\n[\"Rosa\", \" Rosa \", \"\", 3, \"Rosa canina\", \"Rosa gallica\", \"Rosa rugosa\"]\n```"];

        self::assertSame(['Rosa', 'Rosa canina', 'Rosa gallica'], $this->resolver()->resolve('rosier', 'fr'), 'Three at most.');
    }

    public function testNoPlantIsAnAnswerToo(): void
    {
        $this->answers = ['[]'];
        $resolver = $this->resolver();

        self::assertSame([], $resolver->resolve('triffide', 'fr'));
        self::assertSame([], $resolver->resolve('triffide', 'fr'));
        self::assertCount(1, $this->calls, 'Cached: not asked again for the same name.');
    }

    public function testAFailureIsNotCached(): void
    {
        $this->answers = ['the provider does not answer', 'Rosa, I think', '["Rosa"]'];
        $resolver = $this->resolver();

        self::assertSame([], $resolver->resolve('rosier', 'fr'));
        self::assertSame([], $resolver->resolve('rosier', 'fr'), 'An answer that is no list.');
        self::assertSame(['Rosa'], $resolver->resolve('rosier', 'fr'));
        self::assertCount(3, $this->calls);
        self::assertSame(['The Latin names of a plant could not be asked to the LLM.', 'The Latin names of a plant could not be asked to the LLM.'], $this->warnings);
    }

    public function testInDemoModeTheNamesAreRecorded(): void
    {
        $resolver = $this->resolver(demo: true);

        self::assertSame(['Rosa'], $resolver->resolve('Rosier grimpant', 'fr'));
        self::assertSame(['Actinidia deliciosa'], $resolver->resolve('kiwier', 'fr'));
        self::assertSame([], $resolver->resolve('triffide', 'fr'));
        self::assertSame([], $this->calls, 'No LLM, no network.');
    }

    private function resolver(bool $demo = false): LatinNameResolver
    {
        $platform = MockPlatformFactory::createPlatform(function (mixed $model, array|string|object $payload, array $options): TextResult {
            $this->calls[] = ['payload' => $payload, 'options' => $options];
            $answer = array_shift($this->answers) ?? self::fail('Not asked that many times.');
            if ('the provider does not answer' === $answer) {
                throw new RuntimeException('The model provider does not answer.');
            }

            return new TextResult($answer);
        });
        $logger = new class($this->warnings) extends AbstractLogger {
            /**
             * @param list<string> $warnings
             */
            public function __construct(
                private array &$warnings,
            ) {
            }

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->warnings[] = (string) $message;
            }
        };

        return new LatinNameResolver($platform, 'claude-sonnet-5-5', new RecordedLatinNames(\dirname(__DIR__, 3) . '/fixtures/llm/latin-names.json'), new DataSourceCache(new ArrayAdapter(), $demo), $logger, $demo);
    }
}
