<?php

namespace Cav7\DiscordSyncPatch\NF\Discord;

/**
 * Issue #248 — makes the vendor's retry-after describe the call just made.
 *
 * Every way a Discord call can fail hands this addon the same `false`, so the
 * retry-after is the only thing that tells a throttle apart from a refusal.
 * ReconciliationSweep::wasThrottled() is the single place it is interpreted, and it
 * has to be asked immediately after the call it is about.
 *
 * The vendor writes it per call rather than latching it, but only on the paths that
 * reach `assertNotRateLimited()`, where it is cleared at the top. `Api::request()`
 * returns earlier on three, and those leave the PREVIOUS call's value standing: the
 * `ServerException | ConnectException` branch, the `204` arm, and the
 * `{304, 400, 401, 403}` arm. Only the first is reachable by this add-on's calls —
 * Guzzle 7 turns a real 4xx into a ClientException before the third arm, and neither
 * endpoint the sweep uses answers 204 or 304 — but a dropped connection or a Discord
 * 5xx both land there, and the sweep issues up to ninety calls through one Api. A
 * 429 partway through was therefore reported again by every following call until one
 * reached the reset, which the per-run log line counted as that many throttled
 * strips.
 *
 * This clears the field before delegating, so the answer after any call is that
 * call's own on every path at once, rather than on the paths the vendor happens to
 * reset.
 *
 * OPT-IN PER INSTANCE, and that is the whole reason this is a flag rather than an
 * unconditional reset. `Repository\Queue::run()` reads the same field after each
 * queued message and stops draining when it is set, so an instance whose last call
 * took the connect branch after a 429 currently makes the vendor back off. Clearing
 * underneath instances this addon did not make would have the vendor's queue keep
 * draining into a guild that just throttled it — trading the vendor's pacing for
 * this addon's log line. Instances the vendor builds never ask, so nothing about
 * its behaviour changes.
 *
 * Set once on the Api the sweep builds, rather than before each call, so a Discord
 * call added to the sweep later cannot quietly reintroduce this.
 *
 * The reset sits in `request()`, so the granularity is one HTTP request. A vendor
 * method spending several of them therefore reports its LAST request, not the worst
 * one — a throttle on an earlier request inside the same method would be forgotten.
 * The only such method the sweep uses is `getCurrentGuildMember()`, and it cannot
 * reach that: it calls `getCurrentUser()` first and returns `false` without making the
 * second request when that one fails, so no sequence exists where a 429 it saw is
 * overwritten by a later request. Vendor `Api.php:216-224`.
 *
 * Every branch named above was measured rather than reasoned about, against a real
 * XenForo and a mocked transport:
 * docs/verification/reconciliation-sweep-guards.md. That is the place to re-run
 * after a vendor upgrade, and the authority for the stub in
 * tests/ThrottleSignalFreshnessTest.php.
 */
class Api extends XFCP_Api
{
    /**
     * Whether the retry-after should describe only the call just made. Off by
     * default, so an instance this addon did not configure behaves exactly as the
     * vendor's does.
     */
    protected bool $retryAfterPerCall = false;

    public function setRetryAfterPerCall(bool $perCall): void
    {
        $this->retryAfterPerCall = $perCall;
    }

    public function request(array $conditions, string $path = '', $data = [])
    {
        // Before the call, not after it. The vendor's own reset sits at the top of
        // assertNotRateLimited() for the same reason: a value written by the call in
        // hand is the only one worth keeping, and the paths that skip the reset are
        // exactly the ones that never write a replacement.
        if ($this->retryAfterPerCall) {
            $this->retryAfter = null;
        }

        return parent::request($conditions, $path, $data);
    }
}
