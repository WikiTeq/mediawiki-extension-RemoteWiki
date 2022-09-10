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

	/**
	 * @inheritDoc
	 * @throws \MWException
	 */
	public function onParserFirstCallInit( $parser ) {
		$parser->setFunctionHook( 'remote_version', function () {
			return RemoteWiki::getInstance()->remoteVersion( ...func_get_args() );
		} );
	}

}
