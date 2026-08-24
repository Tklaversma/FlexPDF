<?php

declare(strict_types=1);

namespace FlexPDF\Engine;

/** One declaration block bound to one selector. */
final class CssRule
{
    /**
     * Which sheet the rule came from. CSS Cascade §6.4.1 sorts by origin
     * *before* specificity, so this is not decoration: a UA rule loses to any
     * author rule however specific it is, which is what lets the UA sheet use
     * HTML's own spellings instead of keeping every selector at (0,0,1).
     *
     * {@see StyleResolver::cascadeRank()} turns it into the sort key.
     */
    public const int ORIGIN_USER_AGENT = 0;
    public const int ORIGIN_AUTHOR     = 1;

    /**
     * The `@scope ... to (...)` limits wrapping this rule.
     *
     * A scoping root is compiled into the selector as `:where(root)`, which is
     * all a plain `@scope` needs. A limit cannot go there: "not below an
     * element matching this" is not something a selector can say. So it stays
     * here and {@see StyleResolver::inScope()} walks the element's ancestors
     * for it, the same shape the container-query gate has.
     *
     * @var array<int,array{roots:Selector[],limits:Selector[]}>
     */
    public array $scopeBounds = [];

    /**
     * @param ContainerQuery[] $queries the `@container` preludes wrapping this
     *                                  rule, outermost first. Every one of them
     *                                  has to hold for the rule to apply, which
     *                                  is what nesting two blocks means.
     */
    public function __construct(
        public Selector $selector,
        public array $declarations, // prop => ['value' => string, 'important' => bool]
        public int $order,
        public int $origin = self::ORIGIN_USER_AGENT,
        public array $queries = [],
    ) {}
}
