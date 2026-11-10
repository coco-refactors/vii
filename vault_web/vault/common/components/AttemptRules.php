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

    const MAX_HOOK_TRIES = 10;

    /** Retry delays: 1, 2, 5, 10, 30 minutes, then hourly. */
    public static function backoffSeconds($tries)
    {
        $steps = [60, 120, 300, 600, 1800];
        $i = max(1, (int)$tries) - 1;

        return $i < count($steps) ? $steps[$i] : 3600;
    }

    /** Retry network failures, temporary HTTP errors, and server errors. */
    public static function isRetryable($httpStatus)
    {
        $s = (int)$httpStatus;

        return $s === 0 ||
            $s === 408 ||
            $s === 425 ||
            $s === 429 ||
            $s >= 500;
    }
}