<?php

namespace MediaWiki\Extension\RemoteWiki;

use Addwiki\Mediawiki\Api\Client\Action\Exception\UsageException;
use Addwiki\Mediawiki\Api\Client\Action\Request\ActionRequest;
use Addwiki\Mediawiki\Api\Client\Auth\UserAndPassword;
use Addwiki\Mediawiki\Api\Client\MediaWiki;
use Config;
use Exception;
use LogicException;
use Parser;
use WANObjectCache;

/**
 * RemoteWiki service, should be retrieved from MediaWikiServices
 */
class RemoteWiki {

	/** @var MediaWiki[] */
	private $apis = [];

	/** @var Config */
	private $config;
	/** @var WANObjectCache */
	private $cache;

	/**
	 * Version number for cached values of extension information - when the
	 * option to retrieve the URLs of extensions was added the old values were
	 * no longer valid. Increment this when the 'extensions' cache key values
	 * change.
	 */
	private const EXTENSIONS_CACHE_VERSION = 2;

	/**
	 * @param Config $config
	 * @param WANObjectCache $cache
	 */
	public function __construct(
		Config $config,
		WANObjectCache $cache
	) {
		$this->config = $config;
		$this->cache = $cache;
	}

	/**
	 * @param Parser $parser
	 *
	 * @return string
	 */
	public function remoteVersion( Parser $parser, $endPoint = null, $type = null ): string {

		if ( !$this->validateEndpoint( $endPoint ) ) {
			return '';
		}

		$api = $this->getWikiApi( $endPoint );
		$result = '';
		switch ( $type ) {
			case 'extensions':
				$result = $this->getExtensionVersions( $api );
				break;
			case 'extension-urls':
				$result = $this->getExtensionURLs( $api );
				break;
			case 'version':
			default:
				$result = $this->getGenerator( $api );
				break;
		}

		return $result;
	}

	/**
	 * Validates wiki API endpoint URL
	 *
	 * @param string $endPoint
	 *
	 * @return bool
	 */
	private function validateEndpoint( string $endPoint ): bool {
		if ( !$endPoint ) {
			return false;
		}
		$parse = parse_url( $endPoint );
		if ( !$parse ) {
			return false;
		}
		if ( !filter_var( $endPoint, FILTER_VALIDATE_URL ) ) {
			return false;
		}
		return true;
	}

	/**
	 * Issues new wiki API instance of fetches from static cache
	 *
	 * @param string $endPoint
	 *
	 * @return MediaWiki
	 */
	private function getWikiApi( string $endPoint ): MediaWiki {
		$parse = parse_url( $endPoint );
		$test = rtrim( $parse['host'] . $parse['path'], '/' );
		if ( array_key_exists( $test, $this->apis ) ) {
			return $this->apis[$test];
		}
		$botPasswords = $this->config->get( 'RemoteWikiBotPasswords' );
		$auth = null;
		if ( array_key_exists( $test, $botPasswords ) ) {
			$auth = new UserAndPassword( $botPasswords[$test]['username'], $botPasswords[$test]['password'] );
		}
		$api = MediaWiki::newFromEndpoint( $endPoint, $auth, [
			'timeout' => (int)$this->config->get( 'RemoteWikiTimeout' ),
			'connect_timeout' => (int)$this->config->get( 'RemoteWikiTimeout' ),
			'noretry' => true,
			'allow_redirects' => [ 'strict' => true ]
		] );
		$this->apis[$test] = $api;
		return $api;
	}

	/**
	 * Fetches wiki generator version (stripped)
	 *
	 * @param MediaWiki $api
	 *
	 * @return string|null
	 */
	private function getGenerator( MediaWiki $api ): ?string {
		$reqKey = $this->cache->makeKey( $api->action()->getApiUrl(), 'version' );
		$value = $this->cache->get( $reqKey );
		$cacheTTL = $this->config->get( 'RemoteWikiCacheTTL' );

		// Either return cached value or skip it if cache TTL is equal to zero
		if ( $cacheTTL != 0 && $value ) {
			return $value;
		}

		$versionReq = ActionRequest::simpleGet(
			'query',
			[
				'meta' => 'siteinfo',
				'siprop' => 'general'
			]
		);

		try {
			$result = $api->action()->request( $versionReq );
			$generator = $result['query']['general']['generator'];
			$version = preg_replace( '/[^0-9\.]/', '', $generator );
			if ( empty( $version ) ) {
				return $this->config->get('RemoteWikiVerbose') ? 'ERROR: empty version response' : '';
			}
			$this->cache->set( $reqKey, $version, $cacheTTL );
			return $version;
		} catch ( Exception $e ) {
			return $this->config->get('RemoteWikiVerbose') ? $e->getMessage() : '';
		}
	}

	/**
	 * Get extensions versions
	 *
	 * @param MediaWiki $api
	 * @return string
	 */
	private function getExtensionVersions( MediaWiki $api ): string {
		$result = $this->getExtensionsInfo( $api );
		if ( is_string( $result ) ) {
			// There was an error
			return $result;
		} else if (
			is_array( $result )
			&& array_key_exists( 'versions', $result )
		) {
			return $result['versions'];
		} else {
			throw new LogicException( 'Invalid getExtensionsInfo() result' );
		}
	}

	/**
	 * Get extensions urls
	 *
	 * @param MediaWiki $api
	 * @return string
	 */
	private function getExtensionURLs( MediaWiki $api ): string {
		$result = $this->getExtensionsInfo( $api );
		if ( is_string( $result ) ) {
			// There was an error
			return $result;
		} else if (
			is_array( $result )
			&& array_key_exists( 'urls', $result )
		) {
			return $result['urls'];
		} else {
			throw new LogicException( 'Invalid getExtensionsInfo() result' );
		}
	}

	/**
	 * Get extensions information (both versions and URLs)
	 *
	 * @param MediaWiki $api
	 * @return array|string
	 */
	private function getExtensionsInfo( MediaWiki $api ) {
		$reqKey = $this->cache->makeKey(
			$api->action()->getApiUrl(),
			'extensions',
			self::EXTENSIONS_CACHE_VERSION
		);
		$value = $this->cache->get( $reqKey );
		$cacheTTL = $this->config->get( 'RemoteWikiCacheTTL' );

		// Either return cached value or skip it if cache TTL is equal to zero
		if ( $cacheTTL != 0 && $value ) {
			return $value;
		}

		$versionReq = ActionRequest::simpleGet(
			'query', [
				'meta' => 'siteinfo',
				'siprop' => 'extensions'
			]
		);
		try {
			$result = $api->action()->request( $versionReq );
			$extensions = $result['query']['extensions'];
			if ( empty( $extensions ) ) {
				return $this->config->get( 'RemoteWikiVerbose' ) ? 'ERROR: empty extensions response' : '';
			}
			// generate extension:version pairs and extension:URL pairs
			$versions = [];
			$urls = [];
			foreach ( $extensions as $extension ) {
				// filter out skins
				if ( isset( $extension['type'] ) && $extension['type'] === 'skin' ) {
					continue;
				}
				$versions[] = $extension['name'] . ':' . ( $extension['version'] ?? $extension['vcs-version'] ?? '?' );
				$urls[] = $extension['name'] . ':' . ( $extension['url'] ?? '?' );
			}
			// URLs cannot be separated by a comma, that is a valid URL
			// character, see
			// https://datatracker.ietf.org/doc/html/rfc3986#section-2.2
			// instead we will use a |, which isn't a valid part of a URL
			$result = [
				'versions' => implode( ',', $versions ),
				'urls' => implode( '|', $urls ),
			];
			$this->cache->set( $reqKey, $result, $cacheTTL );
			return $result;
		} catch ( Exception $e ) {
			return $this->config->get( 'RemoteWikiVerbose' ) ? $e->getMessage() : '';
		}
	}

}
