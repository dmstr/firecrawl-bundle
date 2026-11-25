<?php

// file generated with AI assistance: Claude Code - 2026-10-02 23:51:30 UTC

declare(strict_types=1);

namespace Dmstr\Firecrawl\Validator;

use Dmstr\ApiConfiguration\Entity\ApiConfiguration;
use Dmstr\Firecrawl\Connection\FirecrawlExtension;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Exception\UnexpectedValueException;

/**
 * Validates {@see FirecrawlConnectionNameUnique}: acts only on connections
 * whose configJson type is `firecrawl`, ignores the validated entity itself
 * (update) and reports on the property path `name`.
 */
final class FirecrawlConnectionNameUniqueValidator extends ConstraintValidator
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof FirecrawlConnectionNameUnique) {
            throw new UnexpectedTypeException($constraint, FirecrawlConnectionNameUnique::class);
        }
        if (null === $value) {
            return;
        }
        if (!$value instanceof ApiConfiguration) {
            throw new UnexpectedValueException($value, ApiConfiguration::class);
        }

        if (FirecrawlExtension::NAME !== ($value->getConfigJson()['type'] ?? null)) {
            return;
        }

        // A missing name is reported by the entity's own NotBlank constraint.
        if (!(new \ReflectionProperty(ApiConfiguration::class, 'name'))->isInitialized($value)) {
            return;
        }
        $name = $value->getName();
        if ('' === $name) {
            return;
        }

        $others = $this->entityManager->getRepository(ApiConfiguration::class)
            ->findBy(['type' => FirecrawlExtension::NAME, 'name' => $name]);

        foreach ($others as $other) {
            if ($other === $value || $other->getId()->equals($value->getId())) {
                continue;
            }

            $this->context->buildViolation($constraint->message)
                ->setParameter('{{ name }}', $this->formatValue($name))
                ->setCode(FirecrawlConnectionNameUnique::NOT_UNIQUE_ERROR)
                ->atPath('name')
                ->setInvalidValue($name)
                ->addViolation();

            return;
        }
    }
}
