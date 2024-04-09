<?php

use MediaWiki\MediaWikiServices;
use MWStake\MediaWiki\ComponentLoader\Bootstrapper;

if ( defined( 'MWSTAKE_MEDIAWIKI_COMPONENT_DYNAMICCONFIG_VERSION' ) ) {
	return;
}

define( 'MWSTAKE_MEDIAWIKI_COMPONENT_DYNAMICCONFIG_VERSION', '1.0.8' );

Bootstrapper::getInstance()
	->register( 'dynamicconfig', static function () {
		$GLOBALS['wgServiceWiringFiles'][] = __DIR__ . '/includes/ServiceWiring.php';
		$GLOBALS['wgMWStakeDynamicConfigs'] = [];

		$GLOBALS['wgHooks']['LoadExtensionSchemaUpdates'][] = static function ( DatabaseUpdater $updater ) {
			$updater->addExtensionTable(
				'mwstake_dynamic_config', __DIR__ . '/db/mwstake_dynamic_config.sql'
			);
			$updater->modifyExtensionField(
				'mwstake_dynamic_config',
				'mwdc_serialized',
				__DIR__ . '/db/mwstake_dynamic_config_serialized_patch.sql'
			);
		};

		$GLOBALS['wgHooks']['SetupAfterCache'] = $GLOBALS['wgHooks']['SetupAfterCache'] ?? [];
		array_unshift( $GLOBALS['wgHooks']['SetupAfterCache'], static function () {
			// Earliest point we can access services I found
			$manager = MediaWikiServices::getInstance()->getService( 'MWStakeDynamicConfigManager' );
			$manager->loadConfigs();
		} );
	} );
