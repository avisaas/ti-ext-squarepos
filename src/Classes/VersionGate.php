<?php

declare(strict_types=1);

namespace IgniterLabs\SquarePOS\Classes;

class VersionGate
{
    /**
     * A linked record is clean only when Square's version still matches and
     * TastyIgniter has not edited it since the last sync. Local edits keep the
     * stored Square version, so version equality alone must not skip the push.
     */
    public static function shouldPush($localVersion, $remoteVersion, bool $dirty, bool $linked): bool
    {
        if (!$linked || $remoteVersion === null) {
            return true;
        }

        if ($dirty) {
            return true;
        }

        return (int)$localVersion !== (int)$remoteVersion;
    }
}
