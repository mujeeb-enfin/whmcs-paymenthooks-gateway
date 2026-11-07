<?php
/**
 * Maps the subscriptions a completed checkout opened to the billing groups
 * the module planned (RecurringPlanner groups, stored in plan_json). Pure PHP
 * so every rule is unit-tested in tests/run.php.
 *
 * PaymentHooks opens one subscription per (interval, intervalCount, startsAt)
 * group and lists them on the session as `subscriptions` (EventParser). Groups
 * with a startsAt are matched first, then groups starting now. A subscription
 * is a candidate for a group when
 *   - its cadence is the same (12 months = 1 year), and
 *   - its startsAt is the group's (null for a group starting now) when the
 *     event carries it; otherwise, for a dated group, its next charge date is
 *     the group's startsAt, and
 *   - when it lists its items, they are the group's (description and renewal
 *     price, in any order).
 * A group is bound only to a single candidate. Nothing is guessed: a group
 * without exactly one candidate is reported unmatched and the caller flags
 * it (Needs Attention) instead of binding it.
 */

namespace PaymentHooks;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

class SubscriptionMatcher
{
    /**
     * @param array<int, array{interval:string, intervalCount:int, startsAt:?string, items:array}> $groups
     * @param array<int, array{id:string, interval:string, intervalCount:int, startsAtKnown:bool, startsAt:?string,
     *                         nextCharge:string, items:array}> $subs from EventParser::sessionSubscriptions
     * @return array{matched: array<int, string>, unmatched: int[], unused: string[]} matched = group index => subscription id
     */
    public static function match(array $groups, array $subs): array
    {
        $order = array_keys($groups);
        usort($order, static function ($a, $b) use ($groups) {
            return (($groups[$a]['startsAt'] ?? null) === null) <=> (($groups[$b]['startsAt'] ?? null) === null) ?: $a <=> $b;
        });
        $matched = [];
        $used = [];
        foreach ($order as $g) {
            $group = $groups[$g];
            $candidates = [];
            foreach ($subs as $s => $sub) {
                if (!isset($used[$s]) && (string) $sub['id'] !== '' && self::fits($group, $sub)) {
                    $candidates[] = $s;
                }
            }
            if (count($candidates) === 1) {
                $matched[$g] = (string) $subs[$candidates[0]]['id'];
                $used[$candidates[0]] = true;
            }
        }
        ksort($matched);
        $unused = [];
        foreach ($subs as $s => $sub) {
            if (!isset($used[$s])) {
                $unused[] = (string) $sub['id'];
            }
        }
        return ['matched' => $matched, 'unmatched' => array_values(array_diff(array_keys($groups), array_keys($matched))), 'unused' => $unused];
    }

    private static function fits(array $group, array $sub): bool
    {
        [$gi, $gc] = RecurringPlanner::canonicalCadence((string) $group['interval'], (int) $group['intervalCount']);
        [$si, $sc] = RecurringPlanner::canonicalCadence((string) $sub['interval'], (int) $sub['intervalCount']);
        if ($gi !== $si || $gc !== $sc) {
            return false;
        }
        $startsAt = $group['startsAt'] ?? null;
        if (!empty($sub['startsAtKnown'])) {
            if (($sub['startsAt'] ?? null) !== $startsAt) {
                return false;
            }
        } elseif ($startsAt !== null && (string) $sub['nextCharge'] !== '' && (string) $sub['nextCharge'] !== $startsAt) {
            return false;
        }
        return !$sub['items'] || self::itemKeys($sub['items'], 'unitPrice') === self::itemKeys($group['items'], 'renewalMinor');
    }

    /** Sorted "description|price" keys, so item order does not matter. */
    private static function itemKeys(array $items, string $priceKey): array
    {
        $keys = [];
        foreach ($items as $item) {
            $description = strtolower(trim((string) preg_replace('/\s+/', ' ', (string) ($item['description'] ?? ''))));
            $keys[] = $description . '|' . (int) ($item[$priceKey] ?? 0);
        }
        sort($keys);
        return $keys;
    }
}
