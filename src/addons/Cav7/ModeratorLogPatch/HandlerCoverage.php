<?php

namespace Cav7\ModeratorLogPatch;

use ReflectionClass;

use function in_array;

/**
 * Issue #187 — what the verification command asks about a resolved handler's class
 * chain.
 *
 * Two questions, both answerable from the chain alone: is this addon's rule in
 * force for this handler, and does anything in the chain declare the user gate
 * besides the two classes entitled to. Neither needs XenForo, a database or an
 * install, which is why they live here rather than on the command: the command can
 * only be run by hand on a live forum, and these are the predicates every result it
 * prints rests on.
 *
 * Pure: reflection and the class-hierarchy functions, nothing else.
 */
final class HandlerCoverage
{
    /**
     * The user-level gate this addon replaces outright. Named once because two of
     * the checks below have to agree on which method they are talking about.
     */
    public const USER_GATE = 'isLoggableUser';

    /**
     * $handler's class chain, the object's own class first and the root of the
     * hierarchy last.
     *
     * @return list<class-string>
     */
    public static function chain(object $handler): array
    {
        return array_merge(
            [\get_class($handler)],
            array_values(class_parents($handler) ?: [])
        );
    }

    /**
     * Whether this addon's rule is anywhere in $handler's class chain.
     *
     * Asked of the trait rather than of the class name. The name only tells you
     * which extension XenForo put last, and another addon extending the same
     * handler after this one is a working install; the trait is the thing that has
     * to be there.
     */
    public static function carriesRule(object $handler): bool
    {
        return self::traitBearer($handler) !== null;
    }

    /**
     * The class in $handler's chain that composes this addon's trait, or null when
     * none does.
     *
     * @return class-string|null
     */
    public static function traitBearer(object $handler): ?string
    {
        foreach (self::chain($handler) as $class) {
            if (in_array(AuthorshipLogging::class, self::traitsOf($class), true)) {
                return $class;
            }
        }

        return null;
    }

    /**
     * Every trait composed into $class, including the ones reached through another
     * trait.
     *
     * `class_uses()` is not transitive: it answers with the traits named in the
     * class body and stops there, so a trait that composes this addon's would look
     * like a class with no rule on it. That reads as a coverage gap on an install
     * that has none, from the command whose whole job is saying whether the
     * coverage is real.
     *
     * @return list<class-string>
     */
    public static function traitsOf(string $class): array
    {
        $found = [];
        $pending = array_values(class_uses($class) ?: []);

        while ($pending) {
            $trait = array_pop($pending);
            if (isset($found[$trait])) {
                continue;
            }
            $found[$trait] = true;

            foreach (class_uses($trait) ?: [] as $nested) {
                $pending[] = $nested;
            }
        }

        return array_keys($found);
    }

    /**
     * The classes in $handler's chain that declare the user gate themselves, as
     * opposed to inheriting it.
     *
     * @return list<class-string>
     */
    public static function userGateDeclarers(object $handler): array
    {
        $declarers = [];

        foreach (self::chain($handler) as $class) {
            $reflection = new ReflectionClass($class);
            if (!$reflection->hasMethod(self::USER_GATE)) {
                continue;
            }

            // A trait's method reflects as declared by the class composing it, so
            // this finds our own subclass as well as any concrete override.
            if ($reflection->getMethod(self::USER_GATE)->getDeclaringClass()->getName() === $class) {
                $declarers[] = $class;
            }
        }

        return $declarers;
    }

    /**
     * Declarations of the user gate that are talking past this addon's, in either
     * direction. Empty is the healthy answer.
     *
     * Two classes are entitled to declare it: the one composing our trait, and the
     * root of the chain, which is the moderator log's abstract handler and the
     * `user_id && is_moderator` gate ADR 0001 set out to replace. Both are read off
     * the chain rather than named, so no vendor class name appears here.
     *
     * Anything else is somebody's rule about who may write to the log, and which
     * rule is lost depends on where the declaration sits. Below the class composing
     * our trait, theirs goes: we replace the method outright rather than deferring
     * to it. Above it, ours goes, because a later addon extending the same handler
     * after this one has the last word — and that is this addon's own failure mode,
     * with the log going quiet again for that content type while every other check
     * here still passes. Either way one of the two rules is discarded with nothing
     * said.
     *
     * Nothing declares one today, which is why this is a check rather than a fix: it
     * passes until the day an upgrade at either end adds one.
     *
     * @return list<class-string>
     */
    public static function discardedUserGates(object $handler): array
    {
        return array_values(array_diff(
            self::userGateDeclarers($handler),
            array_filter([self::traitBearer($handler), self::frameworkBase($handler)])
        ));
    }

    /**
     * The root of $handler's chain when it is the framework's own base class for
     * these handlers, and null when the chain has no such root.
     *
     * The root being abstract is what says so, and it is the whole premise the
     * entitlement above rests on: every registered handler extends a base class it
     * cannot instantiate, and that base is where the gate ADR 0001 replaces is
     * declared. A registered handler that extends no such class has to declare the
     * gate itself to work at all, and its declaration is the last class in the
     * chain too — so trusting the position alone would call that handler's own rule
     * entitled and report nothing while our trait discarded it. Read by reflection
     * rather than by name: the class this recognises belongs to XenForo, and naming
     * it would put a fact about one install inside a predicate.
     *
     * @return class-string|null
     */
    public static function frameworkBase(object $handler): ?string
    {
        $chain = self::chain($handler);
        if (!$chain) {
            return null;
        }

        $root = $chain[count($chain) - 1];

        return (new ReflectionClass($root))->isAbstract() ? $root : null;
    }
}
