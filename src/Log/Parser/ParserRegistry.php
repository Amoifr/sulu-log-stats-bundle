<?php

declare(strict_types=1);

namespace Amoifr\SuluLogStatsBundle\Log\Parser;

final class ParserRegistry
{
    /** @var array<string, LogParserInterface> */
    private array $parsers = [];

    /**
     * @param iterable<LogParserInterface> $parsers
     */
    public function __construct(iterable $parsers)
    {
        foreach ($parsers as $parser) {
            $this->parsers[$parser::getFormat()] = $parser;
        }
    }

    public function get(string $format): LogParserInterface
    {
        return $this->parsers[$format] ?? throw new \InvalidArgumentException(\sprintf('No parser reads the "%s" log format. Known formats: "%s".', $format, implode('", "', array_keys($this->parsers))));
    }
}
