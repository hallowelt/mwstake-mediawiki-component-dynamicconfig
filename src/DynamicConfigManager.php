<?php

namespace MWStake\MediaWiki\Component\DynamicConfig;

use DateTime;
use MediaWiki\WikiMap\WikiMap;
use ObjectCacheFactory;
use Psr\Log\LoggerInterface;
use Wikimedia\Rdbms\DBConnRef;
use Wikimedia\Rdbms\IconnectionProvider;
use Wikimedia\Rdbms\IDatabase;

class DynamicConfigManager {
	private const TABLE = 'mwstake_dynamic_config';

	/** @var IConnectionProvider */
	private $connectionProvider;

	/** @var ObjectCacheFactory */
	private $objectCacheFactory;

	/** @var LoggerInterface */
	private $logger;

	/** @var IDynamicConfig[] */
	private $configs;

	/** @var array */
	private $configData = [];

	/** @var bool */
	private $loaded = false;

	/** @var string */
	private string $currentWikiId;

	/**
	 * @param IConnectionProvider $connectionProvider
	 * @param ObjectCacheFactory $objectCacheFactory
	 * @param LoggerInterface $logger
	 * @param IDynamicConfig[] $configs
	 */
	public function __construct(
		IconnectionProvider $connectionProvider, ObjectCacheFactory $objectCacheFactory,
		LoggerInterface $logger, array $configs
	) {
		$this->connectionProvider = $connectionProvider;
		$this->objectCacheFactory = $objectCacheFactory;
		$this->logger = $logger;
		foreach ( $configs as $config ) {
			$this->configs[$config->getKey()] = $config;
		}
		$this->currentWikiId = WikiMap::getCurrentWikiId();
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
		$dbw = $this->connectionProvider->getPrimaryDatabase();
		$dbw->startAtomic( __METHOD__ );
		$this->backup( $config, $dbw );
		$this->store( $config, $serialized, $dbw );
		$dbw->endAtomic( __METHOD__ );

		return true;
	}

	/**
	 * @return void
	 */
	public function loadConfigs() {
		if ( !$this->loaded ) {
			$activeConfigs = $this->getActiveConfigs();
			$this->configData = [];

			foreach ( $activeConfigs as $configValue ) {
				$key = $configValue['key'];
				if ( !isset( $this->configs[$key] ) ) {
					continue;
				}
				$serialized = $configValue['serialized'];

				$config = $this->configs[$key];
				if ( $config instanceof ILocalDynamicConfig ) {
					if ( $configValue['wiki_id'] !== $this->currentWikiId ) {
						// Not for current wiki
						continue;
					}
				} elseif ( $configValue['wiki_id'] !== null ) {
					// Invalid
					continue;
				}
				$this->configData[$config->getKey()] = [
					'serialized' => $serialized,
					'applied' => false,
				];

				$this->logger->debug( 'Loaded config ' . $config->getKey() . ' from database' );
				if ( $config->shouldAutoApply() ) {
					$this->doApply( $config, $serialized );
				}
			}
			$this->loaded = true;
		}
	}

	/**
	 * @return array [ mwdc_key => mwdc_serialized ]
	 */
	private function getActiveConfigs() {
		$objectCache = $this->objectCacheFactory->getLocalServerInstance();
		$fname = __METHOD__;

		return $objectCache->getWithSetCallback(
			$objectCache->makeKey( 'mwscomponentdynamicconfig-getActiveConfigs' ),
			$objectCache::TTL_SECOND,
			function () use ( $fname ) {
				$data = [];
				/** @var DBConnRef $dbr */
				$dbr = $this->connectionProvider->getReplicaDatabase();
				if ( !$dbr->tableExists( self::TABLE, $fname ) ) {
					return $data;
				}
				if ( !$dbr->fieldExists( self::TABLE, 'mwdc_wiki_id', $fname ) ) {
					$this->logger->error( 'Table ' . self::TABLE . ' does not have mwdc_wiki_id field' );
					return $data;
				}

				$res = $dbr->newSelectQueryBuilder()
					->table( self::TABLE )
					->fields( [ 'mwdc_key', 'mwdc_serialized', 'mwdc_wiki_id' ] )
					->where( [
						'mwdc_is_active' => 1,
						$dbr->makeList( [ 'mwdc_wiki_id' => $this->currentWikiId, 'mwdc_wiki_id IS NULL' ], LIST_OR )
					] )
					->caller( $fname )
					->fetchResultSet();

				foreach ( $res as $row ) {
					$data[] = [
						'key' => $row->mwdc_key,
						'serialized' => $row->mwdc_serialized,
						'wiki_id' => $row->mwdc_wiki_id,
					];
				}

				return $data;
			}
		);
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
		$dbr = $this->connectionProvider->getReplicaDatabase();
		$res = $dbr->select(
			self::TABLE,
			[ 'mwdc_timestamp AS timestamp' ],
			[
				'mwdc_key' => $config->getKey(),
				'mwdc_is_active' => 0,
				$dbr->makeList( [ 'mwdc_wiki_id' => $this->currentWikiId, 'mwdc_wiki_id IS NULL' ], LIST_OR ),
			],
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
		$dbw = $this->connectionProvider->getPrimaryDatabase();

		$selectConds = [
			'mwdc_key' => $key,
			'mwdc_is_active' => 0,
			'mwdc_timestamp' => $timestamp->format( 'YmdHis' )
		];
		if ( $config instanceof ILocalDynamicConfig ) {
			$selectConds['mwdc_wiki_id'] = $this->currentWikiId;
		} else {
			$selectConds[] = 'mwdc_wiki_id IS NULL';
		}
		$hasBackup = $dbw->selectField(
			self::TABLE,
			'mwdc_key',
			$selectConds,
			__METHOD__
		);
		if ( !$hasBackup ) {
			$this->logger->error( 'Trying to restore config that has no backup: ' . $key );
			throw new \Exception( 'Invalid backup timestamp' );
		}
		$dbw->startAtomic( __METHOD__ );
		$deleteConds = [ 'mwdc_key' => $key, 'mwdc_is_active' => 1 ];
		if ( $config instanceof ILocalDynamicConfig ) {
			$deleteConds['mwdc_wiki_id'] = $this->currentWikiId;
		} else {
			$deleteConds[] = 'mwdc_wiki_id IS NULL';
		}
		$dbw->delete(
			self::TABLE,
			$deleteConds,
			__METHOD__
		);
		$updateConds = [ 'mwdc_key' => $key, 'mwdc_timestamp' => $timestamp->format( 'YmdHis' ) ];
		if ( $config instanceof ILocalDynamicConfig ) {
			$updateConds['mwdc_wiki_id'] = $this->currentWikiId;
		} else {
			$updateConds[] = 'mwdc_wiki_id IS NULL';
		}
		$dbw->update(
			self::TABLE,
			[ 'mwdc_is_active' => 1 ],
			$updateConds,
			__METHOD__
		);
		$dbw->endAtomic( __METHOD__ );
	}

	/**
	 * Delete all traces of a config (active and backup)
	 * @param string $key
	 *
	 * @return bool
	 */
	public function clearConfig( string $key ): bool {
		$dbw = $this->connectionProvider->getPrimaryDatabase();
		return $dbw->delete(
			self::TABLE,
			[
				'mwdc_key' => $key,
				$dbw->makeList( [ 'mwdc_wiki_id' => $this->currentWikiId, 'mwdc_wiki_id IS NULL' ], LIST_OR ),
			],
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
	 * @param IDatabase $dbw
	 *
	 * @return void
	 */
	private function backup( IDynamicConfig $config, IDatabase $dbw ) {
		$activeConds = [ 'mwdc_key' => $config->getKey(), 'mwdc_is_active' => 1 ];
		if ( $config instanceof ILocalDynamicConfig ) {
			$activeConds['mwdc_wiki_id'] = $this->currentWikiId;
		} else {
			$activeConds[] = 'mwdc_wiki_id IS NULL';
		}
		$hasActive = $dbw->selectRow(
			self::TABLE,
			[ 'mwdc_key' ],
			$activeConds,
			__METHOD__
		);
		if ( !$hasActive ) {
			return;
		}
		$updateConds = [ 'mwdc_key' => $hasActive->mwdc_key ];
		if ( $config instanceof ILocalDynamicConfig ) {
			$updateConds['mwdc_wiki_id'] = $this->currentWikiId;
		} else {
			$updateConds[] = 'mwdc_wiki_id IS NULL';
		}
		$dbw->update(
			self::TABLE,
			[ 'mwdc_is_active' => 0 ],
			$updateConds,
			__METHOD__
		);
		$rotationCheckConds = [ 'mwdc_key' => $config->getKey(), 'mwdc_is_active' => 0 ];
		if ( $config instanceof ILocalDynamicConfig ) {
			$rotationCheckConds['mwdc_wiki_id'] = $this->currentWikiId;
		} else {
			$rotationCheckConds[] = 'mwdc_wiki_id IS NULL';
		}
		$rotationCheck = $dbw->selectRow(
			self::TABLE,
			[ 'COUNT( mwdc_key ) as backup_count' ],
			$rotationCheckConds,
			__METHOD__,
			[ 'GROUP BY' => 'mwdc_key' ]
		);
		if ( $rotationCheck && (int)$rotationCheck->backup_count > 2 ) {
			// Abstraction function `delete` does not support ORDER BY and LIMIT
			$sql = 'DELETE FROM ' . $dbw->tablePrefix() . self::TABLE .
				' WHERE mwdc_key = ' . $dbw->addQuotes( $config->getKey() )
				. ' AND mwdc_is_active = 0 ORDER BY mwdc_timestamp ASC LIMIT 1';
			// Delete oldest backup
			$dbw->query( $sql, __METHOD__ );
		}
	}

	/**
	 * Store config in database
	 *
	 * @param IDynamicConfig $config
	 * @param string $serialized
	 * @param IDatabase $dbw
	 *
	 * @return void
	 */
	private function store( IDynamicConfig $config, string $serialized, IDatabase $dbw ) {
		$data = [
			'mwdc_key' => $config->getKey(),
			'mwdc_serialized' => $serialized,
			'mwdc_timestamp' => $dbw->timestamp(),
			'mwdc_is_active' => 1,
		];
		if ( $config instanceof ILocalDynamicConfig ) {
			$data['mwdc_wiki_id'] = $this->currentWikiId;
		}
		$dbw->insert(
			self::TABLE,
			$data,
			__METHOD__
		);
	}
}
