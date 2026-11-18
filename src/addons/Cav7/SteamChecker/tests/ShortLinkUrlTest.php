<?php

/**
 * An s.team short link in an enlistment post makes the forum server fetch a URL.
 * Whatever the applicant typed, that fetch must only ever go to https://s.team.
 *
 * Drives resolveSteamId() and records the URL handed to the fetch instead of
 * making it. Self-contained: no XenForo, no framework, no network. Exits
 * non-zero on any failure.
 *
 * Run:
 *   php tests/ShortLinkUrlTest.php
 */

namespace {
    class XF
    {
        public static function options()
        {
            return (object) [
                'steamCheckerApiKey'    => 'TESTKEY',
                'steamCheckerBotUserId' => 99,
                'steamCheckerDebugLog'  => false,
            ];
        }
    }
}

namespace XF\Entity {
    class Thread
    {
    }
}

namespace Cav7\SteamChecker\Tests {
    require __DIR__ . '/../SteamChecker.php';

    use Cav7\SteamChecker\SteamChecker;

    final class RecordingSteamChecker extends SteamChecker
    {
        /** @var list<string> */
        public array $fetched = [];

        public function resolve(string $raw): ?string
        {
            return $this->resolveSteamId($raw);
        }

        protected function resolveSteamShortLink(string $url): ?string
        {
            $this->fetched[] = $url;
            return null;
        }
    }

    // [what the applicant typed, the URL fetched or null for no fetch]
    $cases = [
        // Real short links, as applicants paste them.
        ['s.team/p/abcd-efgh', 'https://s.team/p/abcd-efgh'],
        ['https://s.team/p/abcd-efgh', 'https://s.team/p/abcd-efgh'],
        ['HTTP://S.TEAM/p/abcd-efgh', 'https://s.team/p/abcd-efgh'],

        // "s.team" in a URL on another host.
        ['https://laui.dev/s.team/stuff', null],
        ['http://169.254.169.254/latest/s.team/', null],
        ['https://s.team.example/p/x', null],
        ['https://s.team:8080/p/x', null],
        ['https://s.team@evil.example/p/x', null],
        ['https://user@evil.example/s.team/p/x', null],
        ['https://evil.example/?u=s.team/p/x', null],
        // Host-looking text after s.team stays in the path on s.team.
        ['https://s.team/@evil.example/', 'https://s.team/@evil.example/'],
    ];

    $failures = 0;
    foreach ($cases as [$input, $expected]) {
        $checker = new RecordingSteamChecker(new \XF\Entity\Thread());
        $checker->resolve($input);
        $want = $expected === null ? [] : [$expected];
        if ($checker->fetched === $want) {
            echo 'PASS: ' . var_export($input, true) . "\n";
        } else {
            $failures++;
            echo 'FAIL: ' . var_export($input, true)
                . ' expected fetch ' . var_export($want, true)
                . ' got ' . var_export($checker->fetched, true) . "\n";
        }
    }

    exit($failures === 0 ? 0 : 1);
}
