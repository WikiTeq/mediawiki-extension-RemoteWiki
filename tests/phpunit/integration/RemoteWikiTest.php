<?php

namespace MediaWiki\Extension\RemoteWiki\Tests\Integration;

use MediaWiki\MediaWikiServices;
use MediaWikiIntegrationTestCase;
use Parser;

/**
 * This class tests against the actual current version and extensions of
 * MediaWiki.org.
 * 
 * @coversDefaultClass \MediaWiki\Extension\RemoteWiki\RemoteWiki
 * @group extension-RemoteWiki
 */
class RemoteWikiTest extends MediaWikiIntegrationTestCase {

    protected function setUp(): void {
        parent::setUp();
        $this->setMwGlobals( [
            // Disable caching
            'wgRemoteWikiCacheTTL' => 0,
            // Set a reasonable time limit
            'wgRemoteWikiTimeout' => 60,
            // Use verbose error messages for failures
            'wgRemoteWikiVerbose' => true,
        ] );
    }

    /**
     * The version should be in the form `1.{x}.0.{y}`, where `x` is the
     * current major version number (eg 41 for 1.41) and `y` is the current
     * weekly branch. The actual version looks like `1.41.0-wmf.11` but
     * RemoteWiki::getGenerator() removes characters other than digits and
     * periods.
     * 
     * @covers ::remoteVersion
     * @covers ::getGenerator
     * @dataProvider provideVersionParams
     */
    public function testVersion( string $parameter ) {
        $parser = $this->createNoOpMock( Parser::class );
        $mwEndpoint = 'https://www.mediawiki.org/w/api.php';
        $remote = MediaWikiServices::getInstance()->getService( 'RemoteWiki' );

        $version = $remote->remoteVersion( $parser, $mwEndpoint, $parameter );
        $this->assertRegExp(
            '/^1\.\d+\.0\.\d+$/',
            $version,
            'Version retrieved'
        );
    }

    public static function provideVersionParams() {
        yield 'default when not provided' => [ '' ];
        yield 'explicit version request' => [ 'version' ];
        yield 'fallback for unknown' => [ 'foobar' ];
    }

    /**
     * This test assumes that MediaWiki.org has the 'Math' extension installed
     * and enabled - since the extension is bundled with MediaWiki it is
     * unlikely that it will become disabled, but if this test starts failing
     * that might be the reason.
     * 
     * @covers ::remoteVersion
     * @covers ::getExtensions
     */
    public function testExtensions() {
        $parser = $this->createNoOpMock( Parser::class );
        $mwEndpoint = 'https://www.mediawiki.org/w/api.php';
        $remote = MediaWikiServices::getInstance()->getService( 'RemoteWiki' );

        $extensions = $remote->remoteVersion(
            $parser,
            $mwEndpoint,
            'extensions'
        );
        $this->assertStringContainsString(
            'Math:',
            $extensions,
            'Math version retrieved'
        );
    }

}
