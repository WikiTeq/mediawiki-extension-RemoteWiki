<?php

/**
 * Hooks functions for RemoteWiki extension.
 *
 * @file
 */

namespace MediaWiki\Extension\RemoteWiki\Hooks;

use MediaWiki\Extension\RemoteWiki\RemoteWiki;
use MediaWiki\Hook\ParserFirstCallInitHook;
use Parser;

class ParserFirstCallInit implements ParserFirstCallInitHook {

	/** @var RemoteWiki */
	private $remoteWiki;

	/** @param RemoteWiki $remoteWiki */
	public function __construct( RemoteWiki $remoteWiki ) {
		$this->remoteWiki = $remoteWiki;
	}

	/**
	 * @inheritDoc
	 * @throws \MWException
	 */
	public function onParserFirstCallInit( $parser ) {
		$parser->setFunctionHook( 'remote_version', function () {
			return $this->remoteWiki->remoteVersion( ...func_get_args() );
		} );
	}

}
