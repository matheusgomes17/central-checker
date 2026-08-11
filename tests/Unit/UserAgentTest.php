<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\UserAgent;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class UserAgentTest extends TestCase
{
    /**
     * @return array<string, array{string, string, string}>
     */
    public static function agents(): array
    {
        return [
            'chrome on macos' => [
                'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36',
                'Chrome',
                'macOS',
            ],
            // Edge ships Chrome's token too, and appends its own at the end.
            'edge on windows' => [
                'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36 Edg/131.0.0.0',
                'Edge',
                'Windows',
            ],
            'opera on linux' => [
                'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36 OPR/117.0.0.0',
                'Opera',
                'Linux',
            ],
            'firefox on windows' => [
                'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:133.0) Gecko/20100101 Firefox/133.0',
                'Firefox',
                'Windows',
            ],
            // Safari is the only one of these without a token of its own, so it
            // has to be matched last.
            'safari on iphone' => [
                'Mozilla/5.0 (iPhone; CPU iPhone OS 18_1 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.1 Mobile/15E148 Safari/604.1',
                'Safari',
                'iPhone',
            ],
            // An iPad reports "Macintosh" on iPadOS; the iPad token has to win.
            'safari on ipad' => [
                'Mozilla/5.0 (iPad; CPU OS 18_1 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.1 Mobile/15E148 Safari/604.1',
                'Safari',
                'iPad',
            ],
            'chrome on android' => [
                'Mozilla/5.0 (Linux; Android 15) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Mobile Safari/537.36',
                'Chrome',
                'Android',
            ],
            'unrecognised' => ['curl/8.7.1', 'Unknown', 'Unknown'],
        ];
    }

    #[DataProvider('agents')]
    public function test_it_reads_the_browser_and_platform(string $agent, string $browser, string $platform): void
    {
        $userAgent = new UserAgent($agent);

        $this->assertSame($browser, $userAgent->browser());
        $this->assertSame($platform, $userAgent->platform());
    }

    public function test_it_falls_back_when_the_agent_is_missing(): void
    {
        foreach ([null, ''] as $value) {
            $userAgent = new UserAgent($value);

            $this->assertSame('Unknown', $userAgent->browser());
            $this->assertSame('Unknown', $userAgent->platform());
        }
    }
}
