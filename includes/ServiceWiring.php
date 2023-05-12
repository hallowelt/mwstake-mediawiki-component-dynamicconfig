<?php

use MediaWiki\MediaWikiServices;
use MWStake\MediaWiki\Component\DynamicConfig\DynamicConfigManager;
use MWStake\MediaWiki\Component\DynamicConfig\GlobalsAwareDynamicConfig;
use MWStake\MediaWiki\Component\DynamicConfig\IDynamicConfig;

return [
	'MWStakeDynamicConfigManager' => static function( MediaWikiServices $services ) {
		$logger = \MediaWiki\Logger\LoggerFactory::getInstance( 'dynamic-config' );

		$configObjects = [];
		$configsFromGlobal = $GLOBALS['wgMWStakeDynamicConfigs'] ?? [];
		foreach ( $configsFromGlobal as $key => $spec ) {
			if ( !is_string( $key ) ) {
				$logger->error( 'Invalid key for dynamic config', [ 'key' => $key ] );
				throw new Exception( 'Invalid key for dynamic config' );
			}
			if ( !is_array( $spec ) ) {
				$logger->error( 'Invalid spec for dynamic config', [ 'key' => $key, 'spec' => $spec ] );
				throw new Exception( 'Invalid spec for dynamic config' );
			}
			$object = $services->getObjectFactory()->createObject( $spec );
			if ( !( $object instanceof IDynamicConfig ) ) {
				$logger->error( 'Invalid object for dynamic config', [
					'key' => $key, 'object_class' => $object ? get_class( $object ) : null
				] );
				throw new Exception( 'Invalid object for dynamic config' );
			}
			$configObjects[$key] = $object;
		}
		$services->getHookContainer()->run( 'MWStakeDynamicConfigRegisterConfigs', [ &$configObjects ] );
		foreach ( $configObjects as $config ) {
			if ( !( $config instanceof IDynamicConfig ) ) {
				$logger->error( 'Invalid object for dynamic config', [
					'key' => $key, 'object_class' => $object ? get_class( $object ) : null
				] );
				throw new Exception( 'Invalid object for dynamic config' );
			}
			if ( $config instanceof GlobalsAwareDynamicConfig ) {
				$config->setMwGlobals( $GLOBALS );
			}
		}

		return new DynamicConfigManager(
			$services->getDBLoadBalancer(),
			$logger,
			$configObjects
		);
	},
];
