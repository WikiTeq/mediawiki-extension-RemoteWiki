<?php

namespace MediaWiki\Extension\RemoteWiki\Tests\Unit;

use Addwiki\Mediawiki\Api\Client\Action\ActionApi;
use Addwiki\Mediawiki\Api\Client\Auth\NoAuth;
use Addwiki\Mediawiki\Api\Client\Auth\UserAndPassword;
use Addwiki\Mediawiki\Api\Client\MediaWiki;
use Exception;
use HashBagOStuff;
use HashConfig;
use MediaWiki\Extension\RemoteWiki\RemoteWiki;
use MediaWikiUnitTestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Parser;
use WANObjectCache;
use Wikimedia\TestingAccessWrapper;

/**
 * @coversDefaultClass \MediaWiki\Extension\RemoteWiki\RemoteWiki
 * @group extension-RemoteWiki
 */
class RemoteWikiTest extends MediaWikiUnitTestCase {

    private const MW_API = 'https://www.mediawiki.org/w/api.php';

    /**
     * Get a RemoteWiki instance with the given configuration (falling back to
     * reasonable defaults).
     *
     * @param array $config
     * @return TestingAccessWrapper wrapping RemoteWiki
     */
    private function getRemote( array $config = [] ): TestingAccessWrapper {
        $defaults = [
            'RemoteWikiBotPasswords' => [],
            'RemoteWikiCacheTTL' => 3600,
            'RemoteWikiVerbose' => true,
            'RemoteWikiTimeout' => 60,
        ];
        $remote = new RemoteWiki(
            new HashConfig( $config + $defaults ),
            new WANObjectCache( [ 'cache' => new HashBagOStuff() ] )
        );
        return TestingAccessWrapper::newFromObject( $remote );
    }

    /**
     * @covers ::validateEndpoint
     * @dataProvider provideValidateEndpoint
     */
    public function testValidateEndpoint( string $endPoint, bool $expected ) {
        $this->assertSame(
            $expected,
            $this->getRemote()->validateEndpoint( $endPoint )
        );
    }

    public static function provideValidateEndpoint() {
        yield 'empty string' => [ '', false ];
        // It took a while to find something that parse_url() will reject;
        // the string ':' should be rejected per PHP bug #55399
        yield 'parse_url returns false' => [ ':', false ];
        // The string '?' is accepted by parse_url() but not FILTER_VALIDATE_URL
        yield 'filter_var returns false' => [ '?', false ];
        yield 'wiki endpoint' => [ 'https://www.mediawiki.org/w/api.php', true ];
        yield 'valid url' => [ 'https://example.com', true ];
    }

    /**
     * @covers ::getWikiApi
     */
    public function testGetWikiApi_cache() {
        $remote = $this->getRemote();
        $firstApi = $remote->getWikiApi( '//www.mediawiki.org/w/api.php' );
        $secondApi = $remote->getWikiApi( '//www.mediawiki.org/w/api.php' );
        $this->assertSame( $firstApi, $secondApi, 'Api instances are reused' );
        $thirdApi = $remote->getWikiApi( 'https://www.mediawiki.org/w/api.php' );
        $this->assertSame(
            $firstApi,
            $thirdApi,
            'Api caching ignores scheme'
        );
        $fourthApi = $remote->getWikiApi( 'https://en.wikipedia.org/w/api.php' );
        $this->assertNotSame(
            $firstApi,
            $fourthApi,
            'Api caching handles multiple endpoints'
        );
    }

    /**
     * @covers ::getWikiApi
     */
    public function testGetWikiApi_config() {
        $settings = [
            'RemoteWikiBotPasswords' => [
                'www.mediawiki.org/w/api.php' => [
                    'username' => 'Foo',
                    'password' => 'Bar'
                ],
            ],
            'RemoteWikiTimeout' => 173,
        ];
        $remote = $this->getRemote( $settings );
        $enwikiApi = $remote->getWikiApi( '//en.wikipedia.org/w/api.php' );
        // Check timeout and authentication
        $enwikiApiAccess = TestingAccessWrapper::newFromObject( $enwikiApi );
        $this->assertSame(
            173,
            $enwikiApiAccess->config['timeout'],
            'Timeout configuration is used for `timeout`'
        );
        $this->assertSame(
            173,
            $enwikiApiAccess->config['connect_timeout'],
            'Timeout configuration is used for `connect_timeout`'
        );
        $this->assertInstanceOf(
            NoAuth::class,
            $enwikiApiAccess->auth,
            'Wikis can be accessed without bot passwords'
        );

        $mwApi = $remote->getWikiApi( '//www.mediawiki.org/w/api.php' );
        $mwAuth = TestingAccessWrapper::newFromObject( $mwApi )->auth;
        $this->assertInstanceOf(
            UserAndPassword::class,
            $mwAuth,
            'Bot passwords used if configured'
        );
        $this->assertSame( 'Foo', $mwAuth->getUsername(), 'Auth username' );
        $this->assertSame( 'Bar', $mwAuth->getPassword(), 'Auth password' );
    }

    /**
     * @covers ::getGenerator
     * @dataProvider provideTestCache
     */
    public function testGetGenerator_cache( int $ttl, int $apiCalls ) {
        $remote = $this->getRemote( [ 'RemoteWikiCacheTTL' => $ttl ] );
        $actionApi = $this->installApi( $remote, self::MW_API );
        $actionApi->expects( $this->exactly( $apiCalls ) )
            ->method( 'request' )
            ->willReturn( [
                'query' => [
                    'general' => [ 'generator' => 'MediaWiki 1.41.0-wmf.123', ]
                ]
            ] );
        $parser = $this->createNoOpMock( Parser::class );
        $this->assertSame(
            '1.41.0.123',
            $remote->remoteVersion( $parser, self::MW_API ),
            'Version is fetched'
        );
        $this->assertSame(
            '1.41.0.123',
            $remote->remoteVersion( $parser, self::MW_API ),
            'Cache usage'
        );
    }

    /**
     * @covers ::getGenerator
     * @dataProvider provideTestGetGenerator_empty
     */
    public function testGetGenerator_empty( bool $verbose, string $expected ) {
        $remote =  $this->getRemote( [ 'RemoteWikiVerbose' => $verbose ] );
        $actionApi = $this->installApi( $remote, self::MW_API );
        $actionApi->expects( $this->once() )
            ->method( 'request' )
            ->willReturn( 
                [ 'query' => [ 'general' => [ 'generator' => '', ] ] ]
            );
        $parser = $this->createNoOpMock( Parser::class );
        $this->assertSame(
            $expected,
            $remote->remoteVersion( $parser, self::MW_API ),
            'Empty version'
        );
    }
    
    public static function provideTestGetGenerator_empty() {
        yield 'Verbose' => [ true, 'ERROR: empty version response' ];
        yield 'Non-verbose' => [ false, '' ];
    }

    /**
     * @covers ::getGenerator
     * @dataProvider provideTestExceptions
     */
    public function testGetGenerator_error( bool $verbose, string $expected ) {
        $remote =  $this->getRemote( [ 'RemoteWikiVerbose' => $verbose ] );
        $actionApi = $this->installApi( $remote, self::MW_API );
        $actionApi->expects( $this->once() )
            ->method( 'request' )
            ->willThrowException( new Exception( 'TESTING!!!' ) );
        $parser = $this->createNoOpMock( Parser::class );
        $this->assertSame(
            $expected,
            $remote->remoteVersion( $parser, self::MW_API ),
            'Error output'
        );
    }
    
    // Used for both version and extensions    
    public static function provideTestCache() {
        yield 'Cache is used, queried once' => [ 3600, 1 ];
        yield 'Cache is not used, queried twice' => [ 0, 2 ];
    }

    public static function provideTestExceptions() {
        yield 'Verbose' => [ true, 'TESTING!!!' ];
        yield 'Non-verbose' => [ false, '' ];
    }

    /**
     * @covers ::getExtensions
     * @dataProvider provideTestCache
     */
    public function testGetExtensions_cache( int $ttl, int $apiCalls ) {
        $remote = $this->getRemote( [ 'RemoteWikiCacheTTL' => $ttl ] );
        $actionApi = $this->installApi( $remote, self::MW_API );
        $actionApi->expects( $this->exactly( $apiCalls ) )
            ->method( 'request' )
            ->willReturn( [
                'query' => [
                    'extensions' => [
                        [ 'name' => 'foo', 'version' => '123' ],
                        [ 'name' => 'bar', 'vcs-version' => '456' ],
                        [ 'name' => 'baz' ],
                    ]
                ]
            ] );
        $parser = $this->createNoOpMock( Parser::class );
        $this->assertSame(
            'foo:123,bar:456,baz:?',
            $remote->remoteVersion( $parser, self::MW_API, 'extensions' ),
            'Extensions are fetched'
        );
        $this->assertSame(
            'foo:123,bar:456,baz:?',
            $remote->remoteVersion( $parser, self::MW_API, 'extensions' ),
            'Cache usage'
        );
    }

    /**
     * @covers ::getExtensions
     * @dataProvider provideTestGetExtensions_empty
     */
    public function testGetExtensions_empty( bool $verbose, string $expected ) {
        $remote =  $this->getRemote( [ 'RemoteWikiVerbose' => $verbose ] );
        $actionApi = $this->installApi( $remote, self::MW_API );
        $actionApi->expects( $this->once() )
            ->method( 'request' )
            ->willReturn( [ 'query' => [ 'extensions' => [] ] ] );
        $parser = $this->createNoOpMock( Parser::class );
        $this->assertSame(
            $expected,
            $remote->remoteVersion( $parser, self::MW_API, 'extensions' ),
            'Empty extensions'
        );
    }
    
    public static function provideTestGetExtensions_empty() {
        yield 'Verbose' => [ true, 'ERROR: empty extensions response' ];
        yield 'Non-verbose' => [ false, '' ];
    }

    /**
     * @covers ::getExtensions
     * @dataProvider provideTestExceptions
     */
    public function testGetExtensions_error( bool $verbose, string $expected ) {
        $remote =  $this->getRemote( [ 'RemoteWikiVerbose' => $verbose ] );
        $actionApi = $this->installApi( $remote, self::MW_API );
        $actionApi->expects( $this->once() )
            ->method( 'request' )
            ->willThrowException( new Exception( 'TESTING!!!' ) );
        $parser = $this->createNoOpMock( Parser::class );
        $this->assertSame(
            $expected,
            $remote->remoteVersion( $parser, self::MW_API, 'extensions' ),
            'Error output'
        );
    }

    /**
     * We cannot intercept the *creation* of the `MediaWiki` api objects, but
     * we can access the cache to install a fake version that will be used
     * instead of creating a new real instance. This method is used to create
     * and install that fake (mocked) version, and returns the ActionApi
     * mock so that the `request` method can be configured in a more
     * fine-grained manner.
     *
     * @param TestingAccessWrapper $remote MUST wrap a RemoteWiki instance
     * @param string $endPoint
     * @return ActionApi|MockObject
     */
    private function installApi(
        TestingAccessWrapper $remote,
        string $endPoint
    ) {
        $actionApi = $this->createNoOpMock(
            ActionApi::class,
            [ 'getApiUrl', 'request' ]
        );
        $actionApi->method( 'getApiUrl' )->willReturn( $endPoint );

        $api = $this->createNoOpMock( MediaWiki::class, [ 'action' ] );
        $api->method( 'action' )->willReturn( $actionApi );

        // Based on getWikiApi()
        $parsed = parse_url( $endPoint );
        $apiKey = rtrim( $parsed['host'] . $parsed['path'], '/' );

        // Cannot indirectly modify overloaded property
        $remote->apis = [ $apiKey => $api ];
        return $actionApi;
    }

}
