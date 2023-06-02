<?php

use MediaWiki\Extension\RemoteWiki\RemoteWiki;
use MediaWiki\MediaWikiServices;

return [
    'RemoteWiki' => static function ( MediaWikiServices $services ): RemoteWiki {
        return new RemoteWiki(
            $services->getConfigFactory()->makeConfig( 'RemoteWiki' ),
            $services->getMainWANObjectCache()
        );
    },
];
