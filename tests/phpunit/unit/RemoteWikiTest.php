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
	 *
	 * @return TestingAccessWrapper wrapping RemoteWiki
	 */
	private function getRemote( array $config = [], WANObjectCache $wanCache = null ): TestingAccessWrapper {
		$defaults = [
			'RemoteWikiBotPasswords' => [],
			'RemoteWikiCacheTTL' => 3600,
			'RemoteWikiVerbose' => true,
			'RemoteWikiTimeout' => 60,
		];
		$remote = new RemoteWiki(
			new HashConfig( $config + $defaults ),
			$wanCache ?? new WANObjectCache( [ 'cache' => new HashBagOStuff() ] )
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
		yield 'empty string' => [
			'',
			false
		];
		// It took a while to find something that parse_url() will reject;
		// the string ':' should be rejected per PHP bug #55399
		yield 'parse_url returns false' => [
			':',
			false
		];
		// The string '?' is accepted by parse_url() but not FILTER_VALIDATE_URL
		yield 'filter_var returns false' => [
			'?',
			false
		];
		yield 'wiki endpoint' => [
			'https://www.mediawiki.org/w/api.php',
			true
		];
		yield 'valid url' => [
			'https://example.com',
			true
		];
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
	 * @dataProvider provideTestGetGenerator_cache
	 */
	public function testGetGenerator_cache( int $ttl, int $apiCalls ) {
		$remote = $this->getRemote( [ 'RemoteWikiCacheTTL' => $ttl ] );
		$actionApi = $this->installApi( $remote, self::MW_API );
		$actionApi->expects( $this->exactly( $apiCalls ) )->method( 'request' )->willReturn( [
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

	public static function provideTestGetGenerator_cache() {
		yield 'Cache is used, queried once' => [
			3600,
			1
		];
		yield 'Cache is not used, queried twice' => [
			0,
			2
		];
	}

	/**
	 * A TTL of zero disables the cache, so nothing may ever be written to the
	 * store: WANObjectCache::set() treats a TTL of zero as "indefinite", and
	 * entries written while caching is disabled would be served forever once
	 * caching is re-enabled.
	 *
	 * Proven cross-instance: a second RemoteWiki with caching re-enabled and
	 * sharing the same WANObjectCache must not see any value from the first.
	 *
	 * @covers ::getGenerator
	 */
	public function testGetGenerator_zeroTtlWritesNothing() {
		$wanCache = new WANObjectCache( [ 'cache' => new HashBagOStuff() ] );

		// Caching disabled: every call must hit the API and write nothing
		$remoteNoCache = $this->getRemote( [ 'RemoteWikiCacheTTL' => 0 ], $wanCache );
		$actionApi = $this->installApi( $remoteNoCache, self::MW_API );
		$actionApi->expects( $this->exactly( 2 ) )->method( 'request' )->willReturn( [
				'query' => [
					'general' => [ 'generator' => 'MediaWiki 1.41.0-wmf.123', ]
				]
			] );
		$parser = $this->createNoOpMock( Parser::class );
		$this->assertSame(
			'1.41.0.123',
			$remoteNoCache->remoteVersion( $parser, self::MW_API ),
			'Version is fetched with caching disabled'
		);
		$this->assertSame(
			'1.41.0.123',
			$remoteNoCache->remoteVersion( $parser, self::MW_API ),
			'Second fetch also hits the API, nothing was cached'
		);

		// Caching re-enabled on a fresh instance sharing the same store
		$remoteCached = $this->getRemote( [], $wanCache );
		$actionApiCached = $this->installApi( $remoteCached, self::MW_API );
		$actionApiCached->expects( $this->once() )->method( 'request' )->willReturn( [
				'query' => [
					'general' => [ 'generator' => 'MediaWiki 2.0.0-wmf.777', ]
				]
			] );

		$this->assertSame(
			'2.0.0.777',
			$remoteCached->remoteVersion( $parser, self::MW_API ),
			'Re-enabled cache must not serve data fetched while caching was disabled'
		);
	}

	/**
	 * @covers ::getGenerator
	 */
	public function testGetGenerator_nonZeroTtlIsShared() {
		$wanCache = new WANObjectCache( [ 'cache' => new HashBagOStuff() ] );
		$parser = $this->createNoOpMock( Parser::class );

		// First instance populates the cache (one API call)
		$remoteOne = $this->getRemote( [], $wanCache );
		$actionApiOne = $this->installApi( $remoteOne, self::MW_API );
		$actionApiOne->expects( $this->once() )->method( 'request' )->willReturn( [
				'query' => [
					'general' => [ 'generator' => 'MediaWiki 1.41.0-wmf.123', ]
				]
			] );
		$this->assertSame(
			'1.41.0.123',
			$remoteOne->remoteVersion( $parser, self::MW_API ),
			'Version is fetched'
		);

		// Second instance sharing the same store gets the cached version
		// without any further API call
		$remoteTwo = $this->getRemote( [], $wanCache );
		$actionApiTwo = $this->installApi( $remoteTwo, self::MW_API );
		$actionApiTwo->expects( $this->never() )->method( 'request' );
		$this->assertSame(
			'1.41.0.123',
			$remoteTwo->remoteVersion( $parser, self::MW_API ),
			'Caching works across instances with a non-zero TTL'
		);
	}

	/**
	 * @covers ::getGenerator
	 * @dataProvider provideTestGetGenerator_empty
	 */
	public function testGetGenerator_empty( bool $verbose, string $expected ) {
		$remote = $this->getRemote( [ 'RemoteWikiVerbose' => $verbose ] );
		$actionApi = $this->installApi( $remote, self::MW_API );
		$actionApi->expects( $this->once() )->method( 'request' )->willReturn(
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
		yield 'Verbose' => [
			true,
			'ERROR: empty version response'
		];
		yield 'Non-verbose' => [
			false,
			''
		];
	}

	/**
	 * @covers ::getGenerator
	 * @dataProvider provideTestGetGenerator_error
	 */
	public function testGetGenerator_error( bool $verbose, string $expected ) {
		$remote = $this->getRemote( [ 'RemoteWikiVerbose' => $verbose ] );
		$actionApi = $this->installApi( $remote, self::MW_API );
		$actionApi->expects( $this->once() )->method( 'request' )->willThrowException( new Exception( 'TESTING!!!' ) );
		$parser = $this->createNoOpMock( Parser::class );
		$this->assertSame(
			$expected,
			$remote->remoteVersion( $parser, self::MW_API ),
			'Error output'
		);
	}

	public static function provideTestGetGenerator_error() {
		yield 'Verbose' => [
			true,
			'TESTING!!!'
		];
		yield 'Non-verbose' => [
			false,
			''
		];
	}

	/**
	 * @covers ::getExtensionsInfo
	 * @covers ::getExtensionVersions
	 * @covers ::getExtensionURLs
	 * @dataProvider provideTestGetExtensions_cache
	 */
	public function testGetExtensions_cache( int $ttl, int $apiCalls ) {
		$remote = $this->getRemote( [ 'RemoteWikiCacheTTL' => $ttl ] );
		$actionApi = $this->installApi( $remote, self::MW_API );
		$actionApi->expects( $this->exactly( $apiCalls ) )->method( 'request' )->willReturn( [
				'query' => [
					'extensions' => [
						[
							'name' => 'foo',
							'version' => '123',
							'url' => 'abc'
						],
						[
							'name' => 'bar',
							'vcs-version' => '456',
							'url' => 'xyz'
						],
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
		$this->assertSame(
			'foo:abc|bar:xyz|baz:?',
			$remote->remoteVersion( $parser, self::MW_API, 'extension-urls' ),
			'Cache usage'
		);
	}

	public static function provideTestGetExtensions_cache() {
		yield 'Cache is used, queried once' => [
			3600,
			1
		];
		yield 'Cache is not used, queried three times' => [
			0,
			3
		];
	}

	/**
	 * A TTL of zero disables the cache, so nothing may ever be written to the
	 * store: WANObjectCache::set() treats a TTL of zero as "indefinite", and
	 * entries written while caching is disabled would be served forever once
	 * caching is re-enabled.
	 *
	 * Proven cross-instance: a second RemoteWiki with caching re-enabled and
	 * sharing the same WANObjectCache must not see any value from the first.
	 *
	 * @covers ::getExtensionsData
	 */
	public function testGetExtensionsData_zeroTtlWritesNothing() {
		$wanCache = new WANObjectCache( [ 'cache' => new HashBagOStuff() ] );
		$parser = $this->createNoOpMock( Parser::class );

		// Caching disabled: every call must hit the API and write nothing
		$remoteNoCache = $this->getRemote( [ 'RemoteWikiCacheTTL' => 0 ], $wanCache );
		$actionApi = $this->installApi( $remoteNoCache, self::MW_API );
		$actionApi->expects( $this->exactly( 3 ) )->method( 'request' )->willReturn( [
				'query' => [
					'extensions' => [
						[ 'name' => 'foo', 'version' => '123', 'url' => 'abc' ],
					]
				]
			] );
		$this->assertSame(
			'foo:123',
			$remoteNoCache->remoteVersion( $parser, self::MW_API, 'extensions' ),
			'Extensions are fetched with caching disabled'
		);
		$this->assertSame(
			'foo:123',
			$remoteNoCache->remoteVersion( $parser, self::MW_API, 'extensions' ),
			'Second fetch also hits the API, nothing was cached'
		);
		$this->assertSame(
			'foo:abc',
			$remoteNoCache->remoteVersion( $parser, self::MW_API, 'extension-urls' ),
			'URL fetch also hits the API, nothing was cached'
		);

		// Caching re-enabled on a fresh instance sharing the same store
		$remoteCached = $this->getRemote( [], $wanCache );
		$actionApiCached = $this->installApi( $remoteCached, self::MW_API );
		$actionApiCached->expects( $this->once() )->method( 'request' )->willReturn( [
				'query' => [
					'extensions' => [
						[ 'name' => 'stale', 'version' => '999', 'url' => 'stale-url' ],
					]
				]
			] );

		$this->assertSame(
			'stale:999',
			$remoteCached->remoteVersion( $parser, self::MW_API, 'extensions' ),
			'Re-enabled cache must not serve data fetched while caching was disabled'
		);
	}

	/**
	 * @covers ::getExtensionsData
	 */
	public function testGetExtensionsData_nonZeroTtlIsShared() {
		$wanCache = new WANObjectCache( [ 'cache' => new HashBagOStuff() ] );
		$parser = $this->createNoOpMock( Parser::class );

		// First instance populates the cache (one API call)
		$remoteOne = $this->getRemote( [], $wanCache );
		$actionApiOne = $this->installApi( $remoteOne, self::MW_API );
		$actionApiOne->expects( $this->once() )->method( 'request' )->willReturn( [
				'query' => [
					'extensions' => [
						[ 'name' => 'foo', 'version' => '123', 'url' => 'abc' ],
					]
				]
			] );
		$this->assertSame(
			'foo:123',
			$remoteOne->remoteVersion( $parser, self::MW_API, 'extensions' ),
			'Extensions are fetched'
		);

		// Second instance sharing the same store gets the cached extensions
		// without any further API call
		$remoteTwo = $this->getRemote( [], $wanCache );
		$actionApiTwo = $this->installApi( $remoteTwo, self::MW_API );
		$actionApiTwo->expects( $this->never() )->method( 'request' );
		$this->assertSame(
			'foo:123',
			$remoteTwo->remoteVersion( $parser, self::MW_API, 'extensions' ),
			'Caching works across instances with a non-zero TTL'
		);
	}

	/**
	 * @covers ::getExtensionsInfo
	 * @covers ::getExtensionVersions
	 * @covers ::getExtensionURLs
	 * @dataProvider provideTestGetExtensions_empty
	 */
	public function testGetExtensions_empty(
		bool $verbose,
		string $type,
		string $expected
	) {
		$remote = $this->getRemote( [ 'RemoteWikiVerbose' => $verbose ] );
		$actionApi = $this->installApi( $remote, self::MW_API );
		$actionApi->expects( $this->once() )->method( 'request' )->willReturn( [ 'query' => [ 'extensions' => [] ] ] );
		$parser = $this->createNoOpMock( Parser::class );
		$this->assertSame(
			$expected,
			$remote->remoteVersion( $parser, self::MW_API, $type ),
			'Empty extensions'
		);
	}

	public static function provideTestGetExtensions_empty() {
		$error = 'ERROR: empty extensions response';
		yield 'Verbose versions' => [
			true,
			'extensions',
			$error
		];
		yield 'Verbose URLs' => [
			true,
			'extension-urls',
			$error
		];
		yield 'Non-verbose versions' => [
			false,
			'extensions',
			''
		];
		yield 'Non-verbose URLs' => [
			false,
			'extension-urls',
			''
		];
	}

	/**
	 * @covers ::getExtensionsInfo
	 * @covers ::getExtensionVersions
	 * @covers ::getExtensionURLs
	 * @dataProvider provideTestGetExtensions_error
	 */
	public function testGetExtensions_error(
		bool $verbose,
		string $type,
		string $expected
	) {
		$remote = $this->getRemote( [ 'RemoteWikiVerbose' => $verbose ] );
		$actionApi = $this->installApi( $remote, self::MW_API );
		$actionApi->expects( $this->once() )->method( 'request' )->willThrowException( new Exception( 'TESTING!!!' ) );
		$parser = $this->createNoOpMock( Parser::class );
		$this->assertSame(
			$expected,
			$remote->remoteVersion( $parser, self::MW_API, $type ),
			'Error output'
		);
	}

	public static function provideTestGetExtensions_error() {
		yield 'Verbose versions' => [
			true,
			'extensions',
			'ERROR: empty extensions response'
		];
		yield 'Verbose URLs' => [
			true,
			'extension-urls',
			'ERROR: empty extensions response'
		];
		yield 'Non-verbose versions' => [
			false,
			'extensions',
			''
		];
		yield 'Non-verbose URLs' => [
			false,
			'extension-urls',
			''
		];
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
	 *
	 * @return ActionApi|MockObject
	 */
	private function installApi(
		TestingAccessWrapper $remote,
		string $endPoint
	) {
		$actionApi = $this->createNoOpMock(
			ActionApi::class, [
				'getApiUrl',
				'request'
			]
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
