<?php

use MediaWiki\MediaWikiServices;
use MWStake\MediaWiki\Component\DynamicConfig\DynamicConfigManager;
use MWStake\MediaWiki\Component\DynamicConfig\IDynamicConfig;

return [
	'MWStakeDynamicConfigManager' => static function ( MediaWikiServices $services ) {
		$logger = \MediaWiki\Logger\LoggerFactory::getInstance( 'dynamic-config' );

		$configObjects = [];
		$configsFromGlobal = $GLOBALS['wgMWStakeDynamicConfigs'] ?? [];
		foreach ( $configsFromGlobal as $spec ) {
			if ( !is_array( $spec ) ) {
				$logger->error( 'Invalid spec for dynamic config', [ 'spec' => json_encode( $spec ) ] );
				throw new Exception( 'Invalid spec for dynamic config' );
			}
			$object = $services->getObjectFactory()->createObject( $spec );
			if ( !( $object instanceof IDynamicConfig ) ) {
				$logger->error( 'Invalid object for dynamic config', [
					'object_class' => $object ? get_class( $object ) : null
				] );
				throw new Exception( 'Invalid object for dynamic config' );
			}
			$configObjects[] = $object;
		}
		$services->getHookContainer()->run( 'MWStakeDynamicConfigRegisterConfigs', [ &$configObjects ] );
		foreach ( $configObjects as $config ) {
			if ( !( $config instanceof IDynamicConfig ) ) {
				$logger->error( 'Invalid object for dynamic config', [
					'object_class' => $object ? get_class( $object ) : null
				] );
				throw new Exception( 'Invalid object for dynamic config' );
			}
		}

		return new DynamicConfigManager(
			$services->getConnectionProvider(),
			$services->getObjectCacheFactory(),
			$logger,
			$configObjects
		);
	},
];
