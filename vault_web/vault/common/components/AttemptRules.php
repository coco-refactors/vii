<?php

namespace common\components;

/**
 * Rules for quest attempt scoring and hook delivery.
 */
class AttemptRules
{
    /**
     * Return the completed share, rounded to four decimal places.
     * A quest with no active tasks scores zero.
     */
    public static function shareOf($completed, $total)
    {
        $completed = max(0, (int)$completed);
        $total = (int)$total;

        if ($total <= 0) {
            return 0.0;
        }

        return round(min($completed, $total) / $total, 4);
    }
}