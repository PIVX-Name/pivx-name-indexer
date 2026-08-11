<?php declare(strict_types=1);

use Indexer\LockFileUtils;

/**
 * Sync lock guard / stalled lock watchdog.
 *
 * The includer must set $watchdogLockFile to the lock it is responsible for, and select a
 * mode with $watchdogInspectOnly:
 *
 *   false (guard mode)   - the normal path of a sync run: take the lock, and refuse to
 *                          start when someone else already holds it. Reports a stalled
 *                          lock, then exits so only one run proceeds.
 *
 *   true  (inspect mode) - the systemd watchdog timer. Under systemd a second instance of
 *                          the same unit is never started while the first is still running
 *                          (concurrent activations are coalesced), so the guard branch
 *                          above can never fire there and a stalled lock would go
 *                          unreported. This mode restores that alert from a separate unit.
 *
 *                          It must stay strictly read-only. Acquiring the lock here - even
 *                          for a moment, even only to test it - would create the lock file
 *                          when none exists, and could make the sync unit starting at that
 *                          instant see the lock as taken and silently skip its cycle.
 *
 * Both modes end the script; execution continues past include only when the lock has
 * been acquired, i.e. only in guard mode.
 */

if (!isset($watchdogLockFile) || $watchdogLockFile === '') {
    throw new RuntimeException(
        'The watchdog file is not set!',
        INT_EXC_FATAL
    );
}
$watchdogInspectOnly ??= false;

if ($watchdogInspectOnly) {
    // No lock file at all means no run is in progress and none was left behind: healthy.
    if (($watchdogLockAge = LockFileUtils::lockAge($watchdogLockFile)) === null) {
        exit;
    }
    if ($watchdogLockAge > SYNC_LOCK_STALE_SEC) {
        throw new RuntimeException(
            'The synchronization lock \'' . $watchdogLockFile . '\' has been held for ' .
            $watchdogLockAge . 's. Is the run that owns it stuck?',
            INT_EXC_FATAL
        );
    }
    exit;
}

// Lock the execution more than one script at one moment
if (LockFileUtils::setLock($watchdogLockFile) === false) {
    // lockAge() rather than filemtime(): the holder can finish and unlink the file between
    // the failed acquire above and this check, and filemtime() would then warn and return
    // false, which arithmetic turns into 0 - an age of "since the epoch", i.e. a false
    // stalled-lock alert. A missing file here simply means there is nothing to report.
    $watchdogLockAge = LockFileUtils::lockAge($watchdogLockFile);
    if ($watchdogLockAge !== null && $watchdogLockAge > SYNC_LOCK_STALE_SEC) {
        throw new RuntimeException(
            'Can\'t set the synchronization lock \'' . $watchdogLockFile .
            '\'. Is another run already executing?',
            INT_EXC_FATAL
        );
    }
    exit;
}
