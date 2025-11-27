<?php

namespace MWStake\MediaWiki\Component\DynamicConfig\Tests\Unit;

use MWStake\MediaWiki\Component\DynamicConfig\DynamicConfigManager;
use MWStake\MediaWiki\Component\DynamicConfig\IDynamicConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Wikimedia\Rdbms\IDatabase;
use Wikimedia\Rdbms\LoadBalancer;

class DynamicConfigManagerTest extends TestCase {
	/**
	 * @covers \MWStake\MediaWiki\Component\DynamicConfig\DynamicConfigManager::getConfigObject
	 * @covers \MWStake\MediaWiki\Component\DynamicConfig\DynamicConfigManager::applyConfig
	 * @return void
	 */
	public function testApply() {
		$lbMock = $this->getLBMock();
		$config1 = $this->getConfigMock();
		$config1->expects( $this->once() )->method( 'apply' )->with( 'dummy' );
		$manager = new DynamicConfigManager( $lbMock, $this->getLoggerMock(), [ $config1 ] );
		$c1 = $manager->getConfigObject( 'config1' );
		$this->assertInstanceOf( IDynamicConfig::class, $c1 );
		$c2 = $manager->getConfigObject( 'configInvalid' );
		$this->assertNull( $c2 );

		$lbMock->getConnection( DB_PRIMARY )->expects( $this->once() )->method( 'select' )->with(
			'mwstake_dynamic_config',
			[ 'mwdc_key', 'mwdc_serialized' ],
			[ 'mwdc_is_active' => 1 ],
			DynamicConfigManager::class . '::loadConfigs'
		)->willReturn( [
			(object)[ 'mwdc_key' => 'config1', 'mwdc_serialized' => 'dummy' ]
		] );
		$manager->applyConfig( $c1 );
	}

	/**
	 * @covers \MWStake\MediaWiki\Component\DynamicConfig\DynamicConfigManager::storeConfig
	 * @return void
	 */
	public function testStore() {
		$lbMock = $this->getLBMock();
		$config = $this->getConfigMock();
		$config->expects( $this->once() )->method( 'serialize' )->willReturn( 'dummy' );

		$manager = new DynamicConfigManager( $lbMock, $this->getLoggerMock(), [ $config ] );
		$c1 = $manager->getConfigObject( 'config1' );
		$lbMock->getConnection( DB_PRIMARY )->method( 'timestamp' )->willReturn( '0000' );
		$lbMock->getConnection( DB_PRIMARY )->expects( $this->once() )->method( 'insert' )->with(
			'mwstake_dynamic_config',
			[
				'mwdc_key' => 'config1',
				'mwdc_serialized' => 'dummy',
				'mwdc_is_active' => 1,
				'mwdc_timestamp' => '0000'
			],
			DynamicConfigManager::class . '::store'
		);

		$manager->storeConfig( $c1 );
	}

	/**
	 * @return IDatabase&\PHPUnit\Framework\MockObject\MockObject
	 */
	public function getDatabaseMock() {
		$mock = $this->getMockBuilder( IDatabase::class )
			->disableOriginalConstructor()
			->getMock();
		$mock->method( 'startAtomic' )->willReturn( true );
		$mock->method( 'endAtomic' )->willReturn( true );

		return $mock;
	}

	/**
	 * @return LoadBalancer&\PHPUnit\Framework\MockObject\MockObject
	 */
	public function getLBMock() {
		$mock = $this->getMockBuilder( LoadBalancer::class )
			->disableOriginalConstructor()
			->getMock();
		$mock->method( 'getConnection' )->willReturn( $this->getDatabaseMock() );
		return $mock;
	}

	/**
	 * @return \PHPUnit\Framework\MockObject\MockObject|LoggerInterface
	 */
	private function getLoggerMock() {
		return $this->getMockBuilder( LoggerInterface::class )
			->disableOriginalConstructor()
			->getMock();
	}

	/**
	 * @return IDynamicConfig&\PHPUnit\Framework\MockObject\MockObject
	 */
	private function getConfigMock() {
		$config = $this->getMockBuilder( IDynamicConfig::class )
			->disableOriginalConstructor()
			->getMock();
		$config->method( 'getKey' )->willReturn( 'config1' );

		return $config;
	}
}
