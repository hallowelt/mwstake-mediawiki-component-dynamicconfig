<?php

namespace MWStake\MediaWiki\Component\DynamicConfig;

use DateTime;
use Psr\Log\LoggerInterface;
use Wikimedia\Rdbms\IDatabase;
use Wikimedia\Rdbms\ILoadBalancer;

class DynamicConfigManager {
	private const TABLE = 'mwstake_dynamic_config';

	/**
	 * @var ILoadBalancer
	 */
	private $loadBalancer;

	/** @var LoggerInterface */
	private $logger;

	/**
	 * @var IDynamicConfig[]
	 */
	private $configs;

	/** @var array */
	private $configData = [];

	/** @var bool */
	private $loaded = false;

	/**
	 * @param ILoadBalancer $loadBalancer
	 * @param LoggerInterface $logger
	 * @param IDynamicConfig[] $configs
	 */
	public function __construct(
		ILoadBalancer $loadBalancer, LoggerInterface $logger, array $configs
	) {
		$this->loadBalancer = $loadBalancer;
		$this->logger = $logger;
		foreach ( $configs as $config ) {
			$this->configs[$config->getKey()] = $config;
		}
	}

	/**
	 * @param IDynamicConfig $config
	 * @param array|null $additionalData
	 * @param string|null $serialized
	 *
	 * @return bool
	 */
	public function storeConfig(
		IDynamicConfig $config, ?array $additionalData = [], ?string $serialized = null
	): bool {
		$key = $config->getKey();
		if ( !isset( $this->configs[$key] ) ) {
			$this->logger->error( 'Trying to store config that is not registered: ' . $key );
			return false;
		}
		$serialized = $serialized ?? $config->serialize( $additionalData );
		$db = $this->loadBalancer->getConnection( DB_PRIMARY );
		$db->startAtomic( __METHOD__ );
		$this->backup( $config, $db );
		$this->store( $config, $serialized, $db );
		$db->endAtomic( __METHOD__ );

		return true;
	}

	/**
	 * @return void
	 */
	public function loadConfigs() {
		if ( !$this->loaded ) {
			$dbr = $this->loadBalancer->getConnection( DB_REPLICA );
			$res = $dbr->select(
				self::TABLE,
				[ 'mwdc_key', 'mwdc_serialized' ],
				[ 'mwdc_is_active' => 1 ],
				__METHOD__
			);

			$this->configData = [];

			if ( !$res ) {
				$res = [];
			}
			foreach ( $res as $row ) {
				if ( !isset( $this->configs[$row->mwdc_key] ) ) {
					continue;
				}

				$config = $this->configs[$row->mwdc_key];
				$this->configData[$config->getKey()] = [
					'serialized' => $row->mwdc_serialized,
					'applied' => false,
				];
				$this->logger->debug( 'Loaded config ' . $config->getKey() . ' from database' );
				if ( $config->shouldAutoApply() ) {
					$this->doApply( $config, $row->mwdc_serialized );
				}
			}
			$this->loaded = true;
		}
	}

	/**
	 * @param IDynamicConfig $config
	 *
	 * @return string|null
	 */
	public function retrieveRaw( IDynamicConfig $config ): ?string {
		$this->loadConfigs();
		if ( !isset( $this->configData[$config->getKey()] ) ) {
			return null;
		}
		return $this->configData[$config->getKey()]['serialized'];
	}

	/**
	 * @param IDynamicConfig $config
	 * @param bool $forceApply
	 *
	 * @return bool
	 */
	public function applyConfig( IDynamicConfig $config, bool $forceApply = false ): bool {
		$key = $config->getKey();
		$this->loadConfigs();
		if ( !isset( $this->configData[$key] ) ) {
			$this->logger->debug( 'Trying to apply config that has no data in database: ' . $key );
			return false;
		}
		if ( $this->configData[$key]['applied'] && !$forceApply ) {
			$this->logger->debug( 'Config ' . $key . ' already applied' );
			return true;
		}
		$this->doApply( $this->configs[$key], $this->configData[$key]['serialized'] );
		return true;
	}

	/**
	 * @param string $key
	 *
	 * @return IDynamicConfig|null
	 */
	public function getConfigObject( string $key ): ?IDynamicConfig {
		if ( !isset( $this->configs[$key] ) ) {
			$this->logger->error( 'Trying to get config object that is not registered: ' . $key );
			return null;
		}
		return $this->configs[$key];
	}

	/**
	 * @return array
	 */
	public function listTypes(): array {
		$this->loadConfigs();
		return array_keys( $this->configs );
	}

	/**
	 * @param IDynamicConfig $config
	 *
	 * @return array
	 */
	public function listBackups( IDynamicConfig $config ) {
		$dbr = $this->loadBalancer->getConnection( DB_REPLICA );
		$res = $dbr->select(
			self::TABLE,
			[ 'mwdc_timestamp AS timestamp' ],
			[ 'mwdc_key' => $config->getKey(), 'mwdc_is_active' => 0 ],
			__METHOD__,
			[ 'ORDER BY' => 'mwdc_timestamp DESC' ]
		);
		$backups = [];
		foreach ( $res as $row ) {
			$backups[] = DateTime::createFromFormat( 'YmdHis', $row->timestamp );
		}
		return $backups;
	}

	/**
	 * @param IDynamicConfig $config
	 * @param DateTime $timestamp
	 *
	 * @throws \Exception
	 */
	public function restoreFromBackup( IDynamicConfig $config, DateTime $timestamp ) {
		$key = $config->getKey();
		if ( !isset( $this->configs[$key] ) ) {
			$this->logger->error( 'Trying to restore config that is not registered: ' . $key );
			throw new \Exception( 'Invalid config specified' );
		}
		$db = $this->loadBalancer->getConnection( DB_PRIMARY );

		$hasBackup = $db->selectField(
			self::TABLE,
			'mwdc_key',
			[
				'mwdc_key' => $key,
				'mwdc_is_active' => 0,
				'mwdc_timestamp' => $timestamp->format( 'YmdHis' )
			],
			__METHOD__
		);
		if ( !$hasBackup ) {
			$this->logger->error( 'Trying to restore config that has no backup: ' . $key );
			throw new \Exception( 'Invalid backup timestamp' );
		}
		$db->startAtomic( __METHOD__ );
		$db->delete(
			self::TABLE,
			[ 'mwdc_key' => $key, 'mwdc_is_active' => 1 ],
			__METHOD__
		);
		$db->update(
			self::TABLE,
			[ 'mwdc_is_active' => 1 ],
			[ 'mwdc_key' => $key, 'mwdc_timestamp' => $timestamp->format( 'YmdHis' ) ],
			__METHOD__
		);
		$db->endAtomic( __METHOD__ );
	}

	/**
	 * Delete all traces of a config (active and backup)
	 * @param string $key
	 *
	 * @return bool
	 */
	public function clearConfig( string $key ): bool {
		$db = $this->loadBalancer->getConnection( DB_PRIMARY );
		return $db->delete(
			self::TABLE,
			[ 'mwdc_key' => $key ],
			__METHOD__
		);
	}

	/**
	 * @param IDynamicConfig $config
	 * @param string $value
	 *
	 * @return bool
	 */
	private function doApply( IDynamicConfig $config, string $value ): bool {
		if ( $config->apply( $value ) ) {
			$this->configData[$config->getKey()]['applied'] = true;
			$this->logger->info( 'Applied config ' . $config->getKey() . ' from database' );
			return true;
		}
		$this->logger->error( 'Failed to apply config ' . $config->getKey() . ' from database' );
		return false;
	}

	/**
	 * Backup currently active config and rotate backups
	 *
	 * @param IDynamicConfig $config
	 * @param IDatabase $db
	 *
	 * @return void
	 */
	private function backup( IDynamicConfig $config, IDatabase $db ) {
		$hasActive = $db->selectRow(
			self::TABLE,
			[ 'mwdc_key' ],
			[ 'mwdc_is_active' => 1, 'mwdc_key' => $config->getKey() ],
			__METHOD__
		);
		if ( !$hasActive ) {
			return;
		}
		$db->update(
			self::TABLE,
			[ 'mwdc_is_active' => 0 ],
			[ 'mwdc_key' => $hasActive->mwdc_key ],
			__METHOD__
		);
		$rotationCheck = $db->selectRow(
			self::TABLE,
			[ 'COUNT( mwdc_key ) as backup_count' ],
			[ 'mwdc_key' => $config->getKey(), 'mwdc_is_active' => 0 ],
			__METHOD__,
			[ 'GROUP BY' => 'mwdc_key' ]
		);
		if ( $rotationCheck && (int)$rotationCheck->backup_count > 2 ) {
			// Abstraction function `delete` does not support ORDER BY and LIMIT
			$sql = 'DELETE FROM ' . self::TABLE . ' WHERE mwdc_key = ' . $db->addQuotes( $config->getKey() )
				. ' AND mwdc_is_active = 0 ORDER BY mwdc_timestamp ASC LIMIT 1';
			// Delete oldest backup
			$db->query( $sql );
		}
	}

	/**
	 * Store config in database
	 *
	 * @param IDynamicConfig $config
	 * @param string $serialized
	 * @param IDatabase $db
	 *
	 * @return void
	 */
	private function store( IDynamicConfig $config, string $serialized, IDatabase $db ) {
		$db->insert(
			self::TABLE,
			[
				'mwdc_key' => $config->getKey(),
				'mwdc_serialized' => $serialized,
				'mwdc_timestamp' => $db->timestamp(),
				'mwdc_is_active' => 1,
			],
			__METHOD__
		);
	}
}
