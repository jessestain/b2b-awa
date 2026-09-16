<?php

declare(strict_types=1);

namespace GrupoAwamotos\Theme\Test\Unit\Model;

use GrupoAwamotos\Theme\Model\MinicartAssetVersion;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Locks the public contract of the asset-version helper so that:
 *  - RUNTIME stays a non-empty, query-safe string;
 *  - the query() helper builds a proper ?v= suffix with rawurlencode.
 *
 * @covers \GrupoAwamotos\Theme\Model\MinicartAssetVersion
 */
#[CoversClass(MinicartAssetVersion::class)]
class MinicartAssetVersionTest extends TestCase
{
    public function testRuntimeIsNonEmptyAndStable(): void
    {
        $runtime = MinicartAssetVersion::runtime();

        $this->assertNotEmpty($runtime, 'RUNTIME must never be empty.');
        $this->assertMatchesRegularExpression(
            '/^[A-Za-z0-9_.-]+$/',
            $runtime,
            'RUNTIME should be safe to embed into URLs without further encoding.'
        );
    }

    public function testQueryBuildsVersionedQueryString(): void
    {
        $query = MinicartAssetVersion::query();

        $this->assertStringStartsWith('?v=', $query);
        $this->assertSame(
            '?v=' . rawurlencode(MinicartAssetVersion::RUNTIME),
            $query
        );
    }

    public function testRuntimeAndQueryRemainStableAcrossCalls(): void
    {
        // Cache-bust tokens must be deterministic for the lifetime of a request.
        $this->assertSame(MinicartAssetVersion::runtime(), MinicartAssetVersion::runtime());
        $this->assertSame(MinicartAssetVersion::query(), MinicartAssetVersion::query());
    }
}
