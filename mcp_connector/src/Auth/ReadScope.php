<?php

namespace PerfexMcp\Auth;

/**
 * The row-visibility scope of one read, resolved by Visibility::scope() for
 * the impersonated staff member.
 *
 * $sql is a bare boolean expression over the feature's table (null means
 * unrestricted: global view, or admin). Lists apply it through
 * AbstractTools::paginate(); single reads re-check it with the SAME string via
 * AbstractTools::assertVisible(), so a list and a get can never disagree
 * about what a staff member may see.
 *
 * $lite flags a read that must project to a reduced column set (the staff
 * directory without the staff view capability).
 */
final class ReadScope
{
    public function __construct(
        public readonly string $feature,
        public readonly ?string $sql,
        public readonly bool $lite = false
    ) {
    }

    public function isGlobal(): bool
    {
        return $this->sql === null;
    }

    /** Adds the predicate to a CI3 query builder; no-op when unrestricted. */
    public function apply($db): void
    {
        if ($this->sql !== null) {
            // One outer paren pair so an OR-chain composes with CI3's AND
            // chaining. escape=false: this is trusted SQL built from an
            // int-cast staff id and db_prefix(), never from caller input.
            $db->where('(' . $this->sql . ')', null, false);
        }
    }
}
