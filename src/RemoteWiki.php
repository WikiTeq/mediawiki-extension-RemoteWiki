<?php

namespace MediaWiki\Extension\RemoteWiki;

use Addwiki\Mediawiki\Api\Client\Action\Exception\UsageException;
use Addwiki\Mediawiki\Api\Client\Action\Request\ActionRequest;
use Addwiki\Mediawiki\Api\Client\Auth\UserAndPassword;
use Addwiki\Mediawiki\Api\Client\MediaWiki;
use Config;
use Exception;
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
				$result = $this->getExtensions( $api );
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
	 *
	 * @return string|null
	 */
	private function getExtensions( MediaWiki $api ): ?string {
		$reqKey = $this->cache->makeKey( $api->action()->getApiUrl(), 'extensions' );
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
				return $this->config->get('RemoteWikiVerbose') ? 'ERROR: empty version response' : '';
			}
			// generate extension:version pairs
			$ret = [];
			foreach ( $extensions as $extension ) {
				$ret[] = $extension['name'] . ':' . ( $extension['version'] ?? $extension['vcs-version'] ?? '?' );
			}
			$ret = implode( ',', $ret );
			$this->cache->set( $reqKey, $ret, $cacheTTL );
			return $ret;
		} catch ( Exception $e ) {
			return $this->config->get('RemoteWikiVerbose') ? $e->getMessage() : '';
		}
	}

}
