<?php

// file generated with AI assistance: Claude Code - 2026-10-14 00:40:00 UTC

declare(strict_types=1);

namespace Dmstr\Firecrawl\State\Onboarding;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use Dmstr\Firecrawl\ApiResource\Onboarding\ReplyInput;
use Dmstr\Firecrawl\Onboarding\BoilerplateForm;
use Dmstr\Firecrawl\Onboarding\FormStore;

/**
 * MCP tool `crawl_onboarding_reply`: returns the answer unchanged, so the
 * agent's terminal call becomes the answer of the turn.
 *
 * Why a tool at all: with tools on offer and the answer asked for as JSON
 * text, Claude Sonnet 5.5 often ended the turn after a tool result by
 * calling a tool that does not exist (`__none__`, `__noop`), which the
 * Messages API drops: `stop_reason: tool_use`, no content, no answer. With
 * a real tool to answer through it answered in every probe (2026-10-14).
 *
 * The form `boilerplate` is replaced by the one stored for the sample job
 * ({@see FormStore}), so the model only has to name it.
 *
 * The arguments arrive decoded as PHP arrays, so an empty JSON object has
 * become `[]`; the objects of the answer are turned back into objects, or
 * the agent's `responseSchema` would reject them.
 *
 * @implements ProcessorInterface<ReplyInput, array<string, mixed>>
 */
final readonly class ReplyProcessor implements ProcessorInterface
{
    /** Paths of the answer that are JSON objects. */
    private const array OBJECTS = [['plan'], ['plan', 'scrapeOptions'], ['form'], ['form', 'schema'], ['form', 'value']];

    public function __construct(private FormStore $forms)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): array
    {
        if (!$data instanceof ReplyInput) {
            throw new \InvalidArgumentException('Expected ReplyInput payload');
        }

        $answer = [
            'step' => $data->step,
            'message' => $data->message,
            'form' => \is_array($data->form) && [] !== $data->form ? $data->form : null,
            'plan' => $data->plan,
            'openQuestions' => array_values($data->openQuestions),
        ];

        // The boilerplate form is the server's, schema and preselection
        // alike: a model does not copy a large schema reliably.
        $answer['form'] = $this->serverForm($answer['form'], $answer['plan']);

        // Deepest paths first, so a parent sees its converted children.
        foreach (array_reverse(self::OBJECTS) as $path) {
            self::objectify($answer, $path);
        }

        return $answer;
    }

    /**
     * The stored form of the sample job in `plan.sampleJob.jobId` when the
     * answer offers the form `boilerplate`; any other form as given.
     *
     * @param array<string, mixed>|null $form
     *
     * @return array<string, mixed>|null
     */
    private function serverForm(?array $form, mixed $plan): ?array
    {
        if (BoilerplateForm::FORM_ID !== ($form['id'] ?? null)) {
            return $form;
        }
        $jobId = \is_array($plan) && \is_array($plan['sampleJob'] ?? null) && \is_string($plan['sampleJob']['jobId'] ?? null) ? $plan['sampleJob']['jobId'] : null;
        $stored = null !== $jobId ? $this->forms->find($jobId) : null;

        return $stored ?? $form;
    }

    /**
     * Replaces the array at $path by an object (an empty one stays `{}`
     * when encoded); a missing or null value is left alone.
     *
     * @param array<string, mixed> $answer
     * @param list<string>         $path
     */
    private static function objectify(array &$answer, array $path): void
    {
        $node = &$answer;
        foreach ($path as $key) {
            if (\is_array($node) && \array_key_exists($key, $node)) {
                $node = &$node[$key];
            } elseif ($node instanceof \stdClass && property_exists($node, $key)) {
                $node = &$node->{$key};
            } else {
                return;
            }
        }
        if (\is_array($node) && ([] === $node || !array_is_list($node))) {
            $node = (object) $node;
        }
    }
}
// - revised 2026-10-14
