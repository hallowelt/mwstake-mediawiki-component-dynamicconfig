<?php

use MediaWiki\Maintenance\Maintenance;

/**
 * @return string
 */
function getMaintenancePath() { //phpcs:ignore MediaWiki.NamingConventions.PrefixedGlobalFunctions.allowedPrefix
	if ( isset( $argv[1] ) && file_exists( $argv[1] ) ) {
		return $argv[1];
	}
	return dirname( dirname( dirname( dirname( __DIR__ ) ) ) ) . '/maintenance/Maintenance.php';
}

require_once getMaintenancePath();

class RestoreFromBackup extends Maintenance {
	public function __construct() {
		parent::__construct();
		$this->addDescription( 'Restore dynamic config file from backup' );
		$this->addOption( 'list-types', 'List types of dynamic configs' );
		$this->addOption( 'list-backups', 'List backups for config specified' );
		$this->addOption( 'config', 'Config name', false, true );
		$this->addOption( 'backup-timestamp', 'Timestamp of backup to restore from (YmdHis)', false, true );
	}

	/**
	 * @return bool|void|null
	 */
	public function execute() {
		/** @var \MWStake\MediaWiki\Component\DynamicConfig\DynamicConfigManager $manager */
		$manager = \MediaWiki\MediaWikiServices::getInstance()->getService( 'MWStakeDynamicConfigManager' );
		if ( $this->hasOption( 'list-types' ) ) {
			$types = $manager->listTypes();
			$this->output( "Available config types:\n" );
			foreach ( $types as $type ) {
				$this->output( "- $type\n" );
			}
			return;
		}
		if ( !$this->hasOption( 'config' ) ) {
			$this->error( 'Config name not specified' );
			return;
		}
		$config = $manager->getConfigObject( $this->getOption( 'config' ) );
		if ( !$config ) {
			$this->fatalError( 'Config not found' );
		}
		if ( $this->hasOption( 'list-backups' ) ) {
			$backups = $manager->listBackups( $config );
			if ( empty( $backups ) ) {
				$this->output( "No backups found for '{$config->getKey()}'\n" );
				return;
			}
			$this->output( "Available backups for '{$config->getKey()}':\n" );
			foreach ( $backups as $backupTime ) {
				$this->output(
					"- {$backupTime->format( 'Y-m.d H:i:s')} (specify: {$backupTime->format( 'YmdHis' )})\n"
				);
			}
			return;
		}
		if ( !$this->hasOption( 'backup-timestamp' ) ) {
			$this->error( 'Backup timestamp not specified' );
			return;
		}
		$dt = DateTime::createFromFormat( 'YmdHis', $this->getOption( 'backup-timestamp' ) );
		if ( !$dt ) {
			$this->error( 'Invalid backup timestamp' );
			return;
		}

		try {
			$manager->restoreFromBackup( $config, $dt );
			$this->output( 'Backup restored' );
		} catch ( Exception $e ) {
			$this->error( $e->getMessage() );
			return;
		}
	}
}

$maintClass = RestoreFromBackup::class;
require_once RUN_MAINTENANCE_IF_MAIN;
