<?php

// file generated with AI assistance: Claude Code - 2026-10-14 01:40:00 UTC

declare(strict_types=1);

namespace Dmstr\Firecrawl\Tests\Unit\Onboarding;

use ApiPlatform\Metadata\McpTool;
use Dmstr\Firecrawl\ApiResource\Onboarding\ReplyInput;
use Dmstr\Firecrawl\Onboarding\BoilerplateForm;
use Dmstr\Firecrawl\Onboarding\FormStore;
use Dmstr\Firecrawl\State\Onboarding\ReplyProcessor;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

/**
 * The answer tool puts in the stored boilerplate form of the sample job; a
 * mangled or missing schema from the model does not reach the answer.
 * (The functional tests cannot cover it: `cache.app` is an ArrayAdapter in
 * the test kernel and is reset between requests.)
 */
final class ReplyProcessorTest extends TestCase
{
    public function testBoilerplateFormIsTakenFromTheStore(): void
    {
        $store = new FormStore(new ArrayAdapter());
        $form = BoilerplateForm::build([
            ['selector' => 'footer', 'category' => 'tag:footer', 'tag' => 'footer', 'pages' => 2, 'identicalText' => true, 'sample' => '©', 'preselected' => true],
        ], 2);
        $store->save('crawl-1', $form);

        $answer = new ReplyProcessor($store)->process(self::input(['id' => 'boilerplate', 'schema' => ['type' => 'object', 'properties' => []]], ['sampleJob' => ['jobId' => 'crawl-1', 'kind' => 'crawl']]), new McpTool());

        self::assertSame(json_encode($form, \JSON_THROW_ON_ERROR), json_encode($answer['form'], \JSON_THROW_ON_ERROR));
        self::assertSame(['footer'], $answer['form']->value->exclude);
    }

    public function testUnknownJobAndOtherFormsStayAsGiven(): void
    {
        $processor = new ReplyProcessor(new FormStore(new ArrayAdapter()));

        $unknown = $processor->process(self::input(['id' => 'boilerplate'], ['sampleJob' => ['jobId' => 'gone', 'kind' => 'crawl']]), new McpTool());
        self::assertSame('boilerplate', $unknown['form']->id);

        $scope = $processor->process(self::input(['id' => 'scope', 'schema' => ['type' => 'object'], 'value' => ['limit' => 100]], []), new McpTool());
        self::assertSame('scope', $scope['form']->id);
        self::assertSame(100, $scope['form']->value->limit);
    }

    /**
     * @param array<string, mixed> $form
     * @param array<string, mixed> $plan
     */
    private static function input(array $form, array $plan): ReplyInput
    {
        $input = new ReplyInput();
        $input->step = 'boilerplate';
        $input->message = 'Bitte auswählen.';
        $input->form = $form;
        $input->plan = $plan;
        $input->openQuestions = [];

        return $input;
    }
}
