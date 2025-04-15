<?php

use MediaWiki\Installer\DatabaseUpdater;
use MediaWiki\MediaWikiServices;
use MWStake\MediaWiki\ComponentLoader\Bootstrapper;

if ( defined( 'MWSTAKE_MEDIAWIKI_COMPONENT_DYNAMICCONFIG_VERSION' ) ) {
	return;
}

define( 'MWSTAKE_MEDIAWIKI_COMPONENT_DYNAMICCONFIG_VERSION', '2.0.1' );

Bootstrapper::getInstance()
	->register( 'dynamicconfig', static function () {
		$GLOBALS['wgServiceWiringFiles'][] = __DIR__ . '/includes/ServiceWiring.php';
		$GLOBALS['wgMWStakeDynamicConfigs'] = [];

		$GLOBALS['wgHooks']['SetupAfterCache'][] = static function () {
			$manager = MediaWikiServices::getInstance()->getService( 'MWStakeDynamicConfigManager' );
			$manager->loadConfigs();
		};

		$GLOBALS['wgExtensionFunctions'][] = static function () {
			$hookContainer = MediaWikiServices::getInstance()->getHookContainer();
			$hookContainer->register( 'LoadExtensionSchemaUpdates', static function ( DatabaseUpdater $updater ) {
				$dbType = $updater->getDB()->getType();
				$updater->addExtensionTable(
					'mwstake_dynamic_config', __DIR__ . "/db/$dbType/mwstake_dynamic_config.sql"
				);
				$updater->modifyExtensionField(
					'mwstake_dynamic_config',
					'mwdc_serialized',
					__DIR__ . "/db/$dbType/mwstake_dynamic_config_serialized_patch.sql"
				);
			} );
		};
	} );
