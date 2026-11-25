<?php

// file generated with AI assistance: Claude Code - 2026-10-14 00:40:00 UTC

declare(strict_types=1);

namespace Dmstr\Firecrawl\ApiResource\Onboarding;

use ApiPlatform\Metadata\ApiProperty;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Arguments of the MCP tool `crawl_onboarding_reply`: the answer of one
 * onboarding turn. The schemas stay flat (`form` and `plan` plain objects),
 * because Neuron passes only the top level of a tool's input schema to the
 * model; the agent's `responseSchema` describes and validates the inner
 * structure.
 */
final class ReplyInput
{
    public const array STEPS = ['boilerplate', 'discovery', 'topics', 'review'];

    #[Assert\NotBlank]
    #[Assert\Choice(choices: self::STEPS)]
    #[ApiProperty(
        description: 'The onboarding step of this answer.',
        required: true,
        schema: ['type' => 'string', 'enum' => self::STEPS],
    )]
    public mixed $step = null;

    #[Assert\NotBlank]
    #[Assert\Type('string')]
    #[ApiProperty(
        description: 'What was found and what the operator is asked to decide.',
        required: true,
        schema: ['type' => 'string', 'minLength' => 1],
    )]
    public mixed $message = null;

    // Not type-checked: anything but a non-empty object means "no form"
    // (ReplyProcessor). Models send the string "null" here, because the
    // tool schema says object and the response schema object or null.
    #[ApiProperty(
        description: 'The form of this step {id, schema, value}; omit it when nothing is to be chosen.',
        schema: ['type' => 'object'],
    )]
    public mixed $form = null;

    #[Assert\NotNull]
    #[Assert\Type('array')]
    #[ApiProperty(
        description: 'The crawl plan as far as it is decided.',
        required: true,
        schema: ['type' => 'object'],
    )]
    public mixed $plan = null;

    #[Assert\NotNull]
    #[Assert\Type('array')]
    #[Assert\Count(max: 5)]
    #[Assert\All([new Assert\Type('string')])]
    #[ApiProperty(
        description: 'At most five open questions, the most important first.',
        required: true,
        schema: ['type' => 'array', 'maxItems' => 5, 'items' => ['type' => 'string']],
    )]
    public mixed $openQuestions = null;
}
