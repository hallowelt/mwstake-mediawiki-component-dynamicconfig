<?php

use MWStake\MediaWiki\ComponentLoader\Bootstrapper;

if ( defined( 'MWSTAKE_MEDIAWIKI_COMPONENT_DYNAMICCONFIG_VERSION' ) ) {
	return;
}

define( 'MWSTAKE_MEDIAWIKI_COMPONENT_DYNAMICCONFIG_VERSION', '1.0.0' );

Bootstrapper::getInstance()
	->register( 'dynamicconfig', function () {
		$GLOBALS['wgServiceWiringFiles'][] = __DIR__ . '/includes/ServiceWiring.php';
		$GLOBALS['wgMWStakeDynamicConfigs'] = [];

		$GLOBALS['wgExtensionFunctions'][] = static function() {
			$hookContainer = \MediaWiki\MediaWikiServices::getInstance()->getHookContainer();
			$hookContainer->register( 'LoadExtensionSchemaUpdates', static function ( DatabaseUpdater $updater ) {
				$updater->addExtensionTable( 'mwstake_dynamic_config', __DIR__ . '/db/mwstake_dynamic_config.sql' );
			} );
		};
	} );
