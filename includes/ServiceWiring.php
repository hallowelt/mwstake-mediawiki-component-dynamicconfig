<?php

return [
	'MWStakeDynamicConifgManager' => static function( \MediaWiki\MediaWikiServices $services ) {
		$logger = \MediaWiki\Logger\LoggerFactory::getInstance( 'dynamic-config' );

		$configObjects = [];
		$configsFromGlobal = $GLOBALS['wgMWStakeDynamicConfigs'] ?? [];
		foreach ( $configsFromGlobal as $key => $spec ) {
			if ( !is_string( $key ) ) {
				$logger->error( 'Invalid key for dynamic config', [ 'key' => $key ] );
				throw new \Exception( 'Invalid key for dynamic config' );
			}
			if ( !is_array( $spec ) ) {
				$logger->error( 'Invalid spec for dynamic config', [ 'key' => $key, 'spec' => $spec ] );
				throw new \Exception( 'Invalid spec for dynamic config' );
			}
			$object = $services->getObjectFactory()->createObject( $spec );
			if ( !( $object instanceof \MWStake\MediaWiki\Component\DynamicConfig\IDynamicConfig ) ) {
				$logger->error( 'Invalid object for dynamic config', [
					'key' => $key, 'object_class' => $object ? get_class( $object ) : null
				] );
				throw new \Exception( 'Invalid object for dynamic config' );
			}
			$configObjects[$key] = $object;
		}
		$hookContainer->run( 'MWStakeDynamicConfigRegisterConfigs', [ &$configObjects ] );
		foreach ( $configs as $config ) {
			if ( !( $config instanceof \MWStake\MediaWiki\Component\DynamicConfig\IDynamicConfig ) ) {
				$logger->error( 'Invalid object for dynamic config', [
					'key' => $key, 'object_class' => $object ? get_class( $object ) : null
				] );
				throw new \Exception( 'Invalid object for dynamic config' );
			}
		}

		return new \MWStake\MediaWiki\Component\DynamicConfig\DynamicConfigManager(
			$services->getDBLoadBalancer(),
			$logger,
			$configObjects
		);
	},
];
