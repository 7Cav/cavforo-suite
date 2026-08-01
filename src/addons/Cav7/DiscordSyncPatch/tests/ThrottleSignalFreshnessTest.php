<?php

/**
 * Issue #248 — the retry-after has to describe the call just made.
 *
 * The reconciliation sweep tells a throttled Discord call apart from a refused one
 * by asking `Api::getRetryAfter()` immediately afterwards. Every failure hands this
 * addon the same `false`, so that is the only signal there is, and
 * ReconciliationSweep::wasThrottled() is the single place it is interpreted.
 *
 * The vendor writes it per call rather than latching it, but only on the paths that
 * reach `assertNotRateLimited()` — three returns come earlier and leave the PREVIOUS
 * call's value standing. Which three, which of them this add-on's calls can reach, and
 * why the fix is opt-in per instance are all on the class under test,
 * ../NF/Discord/Api.php, and are not repeated here: this file has already had to
 * correct one such restatement in three places at once.
 *
 * What matters to the stub below is only the shape: a 429 records, a connect failure
 * or a Discord 5xx records nothing and clears nothing, everything else clears.
 *
 * ---------------------------------------------------------------------------
 * On the vendor stub below — READ THIS BEFORE "CORRECTING" IT
 * ---------------------------------------------------------------------------
 * NF\Discord\Api is not in this repo, so the parent is a stand-in. It is not a guess:
 * every branch it models was MEASURED against a real XenForo, the real Api and a
 * mocked transport.
 *
 * It follows that this file cannot detect the stub falling out of step with a vendor
 * upgrade — if NF/Discord ever clears on the connect branch itself, the assertion
 * below stays green while proving nothing. An assertion pinning "the parent does not
 * clear" would not help: it would go red when the vendor fixed their own bug, which is
 * a change detector on this model rather than a test of ours. What catches that is
 * re-measuring against the real Api on a dev stack after a vendor upgrade.
 *
 * What is NOT covered here, and where it is covered instead:
 *
 *  - That the override behaves this way over the REAL vendor parent. Dev stack.
 *  - That ReconciliationSweep asks for the fresh signal at all. Its only observable
 *    is the wording of the per-run log line, and two runs compared for difference
 *    would be satisfied by any per-run detail that line ever grows. Dev stack.
 *  - That the class extension row imports and is active, and that Api::factory()
 *    therefore returns this composite rather than the bare vendor class. No test can
 *    see it: nothing hashes _data, so a stale version_id installs the file and
 *    imports nothing while every tool stays green. Dev stack.
 *
 * Self-contained: no XenForo, no framework. Exits non-zero on any failure.
 *
 * Run:
 *   php tests/ThrottleSignalFreshnessTest.php
 */

namespace NF\Discord {

    /**
     * Stand-in for the vendor Api, modelling `request()`'s branches as measured.
     *
     * Written to follow the original's control flow rather than to be tidy, so it can
     * be read against the vendor's own source: the connect branch returns before
     * assertNotRateLimited(), and everything else goes through it.
     */
    class Api
    {
        /** A response the transport never produced — a dropped connection or a 5xx. */
        public const CONNECT_FAILURE = 0;

        /** @var int[] the canned answers to the next calls, in order */
        public static array $answers = [];

        /** Fixed, as XF::$time is for the length of a run. */
        public static int $time = 1700000000;

        /** @var int|null what the last call that reached assertNotRateLimited() saw */
        protected $retryAfter = null;

        public function getRetryAfter(): ?int
        {
            return $this->retryAfter;
        }

        /**
         * The vendor's own reset point, and the ONLY one. Clearing at the top is why a
         * call that reaches this describes itself, and why one that does not describes
         * whatever came before.
         */
        public function assertNotRateLimited(int $status): void
        {
            $this->retryAfter = null;

            if ($status === 429) {
                $this->retryAfter = self::$time + 60;
            }
        }

        public function request(array $conditions, string $path = '', $data = [])
        {
            $status = array_shift(self::$answers);

            if ($status === null) {
                throw new \RuntimeException('the fixture ran out of canned answers');
            }

            // ServerException | ConnectException. Logged and returned from the catch,
            // never reaching assertNotRateLimited(), so nothing is written. A Discord
            // 5xx lands here too — Guzzle raises ServerException and the vendor
            // catches the pair together.
            if ($status === self::CONNECT_FAILURE) {
                return false;
            }

            // Every real 4xx arrives as a ClientException carrying a response, which
            // the vendor hands to assertNotRateLimited() before returning false. That
            // is how a 429 records anything, and how a 403 clears it.
            $this->assertNotRateLimited($status);

            if ($status >= 400) {
                return false;
            }

            return ['ok' => true];
        }

        public function get(string $path = '', array $data = [], array $options = [])
        {
            return $this->request(['method' => 'GET'], $path, $data);
        }
    }
}

namespace Cav7\DiscordSyncPatch\NF\Discord {

    /**
     * Stand-in for the XenForo-generated class-extension parent. In production it is
     * generated on top of the vendor Api, which is what makes parent::request() below
     * the vendor's own.
     */
    class XFCP_Api extends \NF\Discord\Api
    {
    }
}

namespace Cav7\DiscordSyncPatch\Tests {

    require __DIR__ . '/../NF/Discord/Api.php';

    use Cav7\DiscordSyncPatch\NF\Discord\Api as Cav7Api;
    use NF\Discord\Api as VendorApi;

    $failures = 0;

    function check(string $label, bool $ok, string $detail = ''): void
    {
        global $failures;
        if ($ok) {
            echo "PASS: $label\n";
        } else {
            $failures++;
            echo "FAIL: $label" . ($detail !== '' ? " — $detail" : '') . "\n";
        }
    }

    /**
     * The retry-after after each call, for one Api driven through one sequence.
     *
     * @param int[] $answers
     * @return array<int, int|null>
     */
    function retryAftersFor(VendorApi $api, array $answers): array
    {
        VendorApi::$answers = $answers;

        $readings = [];
        foreach ($answers as $ignored) {
            $api->get('guilds/1/members?limit=1');
            $readings[] = $api->getRetryAfter();
        }

        return $readings;
    }

    // -----------------------------------------------------------------------
    // A throttled call followed by one that could not connect.
    // -----------------------------------------------------------------------
    //
    // The sweep's strip loop, in miniature: one Api, two calls, the first
    // rate-limited and the second unable to reach Discord at all. The second call
    // moved no role and Discord never saw it, so it was not throttled — and the
    // sweep decides which of the two it was from this value alone.

    VendorApi::$answers = [429, VendorApi::CONNECT_FAILURE];

    $api = new Cav7Api();
    $api->setRetryAfterPerCall(true);

    $api->get('guilds/1/members?limit=1');
    $throttledSignal = $api->getRetryAfter();

    $api->get('guilds/1/members?limit=1');

    check(
        'the retry-after after a throttled call is the throttled call\'s own',
        $throttledSignal !== null,
        'nothing was recorded for the 429, so the fixture proves nothing about the call after it'
    );

    check(
        'a call that could not connect reports no retry-after of its own',
        $api->getRetryAfter() === null,
        'reported ' . var_export($api->getRetryAfter(), true)
            . ' — the previous call\'s 429, which the sweep would count as a throttled strip'
    );

    // -----------------------------------------------------------------------
    // An Api nobody configured is the vendor's own.
    // -----------------------------------------------------------------------
    //
    // Opting in per instance is the whole reason this is a flag rather than an
    // unconditional reset: the vendor's queue drainer reads the same field to decide
    // whether to stop draining, and it builds its own Api. So an instance that never
    // asked has to behave exactly as the bare vendor class does.
    //
    // Asserted as agreement with the parent rather than as "it does not clear", and
    // the difference matters. "It does not clear" would go RED if NF/Discord ever
    // fixed their own branch — a change detector on this file's model of the vendor.
    // Agreement stays green through that: both would clear, and both would still
    // agree. What it goes red on is the default being flipped, which is the thing
    // that would change the vendor's pacing underneath it.

    $sequence = [429, VendorApi::CONNECT_FAILURE, 200, VendorApi::CONNECT_FAILURE];

    $vendorReadings = retryAftersFor(new VendorApi(), $sequence);
    $unconfiguredReadings = retryAftersFor(new Cav7Api(), $sequence);

    check(
        'an Api that never asked for a per-call signal behaves as the vendor\'s own',
        $unconfiguredReadings === $vendorReadings,
        'vendor ' . json_encode($vendorReadings) . ' vs unconfigured ' . json_encode($unconfiguredReadings)
            . ' — the vendor\'s own queue builds Api objects this addon never configures,'
            . ' and pacing them differently is not this addon\'s to do'
    );

    if ($failures > 0) {
        echo "\n$failures test(s) FAILED\n";
        exit(1);
    }
    echo "\nAll tests passed\n";
    exit(0);
}
