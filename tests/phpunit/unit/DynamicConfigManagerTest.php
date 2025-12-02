<?php

namespace MWStake\MediaWiki\Component\DynamicConfig\Tests\Unit;

use MediaWikiUnitTestCase;
use MWStake\MediaWiki\Component\DynamicConfig\DynamicConfigManager;
use MWStake\MediaWiki\Component\DynamicConfig\IDynamicConfig;
use ObjectCacheFactory;
use Psr\Log\LoggerInterface;
use Wikimedia\ObjectCache\EmptyBagOStuff;
use Wikimedia\Rdbms\DBConnRef;
use Wikimedia\Rdbms\FakeResultWrapper;
use Wikimedia\Rdbms\IConnectionProvider;
use Wikimedia\Rdbms\SelectQueryBuilder;

/**
 * @group Database
 */
class DynamicConfigManagerTest extends MediaWikiUnitTestCase {

	private const TABLE = 'mwstake_dynamic_config';

	/**
	 * @covers \MWStake\MediaWiki\Component\DynamicConfig\DynamicConfigManager::getConfigObject
	 * @covers \MWStake\MediaWiki\Component\DynamicConfig\DynamicConfigManager::applyConfig
	 * @return void
	 */
	public function testApply() {
		$connectionProviderMock = $this->getConnectionProviderMock();
		$objectCacheFactory = $this->getObjectCacheFactoryMock();
		$config1 = $this->getConfigMock();
		$config1->expects( $this->once() )->method( 'apply' )->with( 'dummy' );

		$manager = new DynamicConfigManager(
			$connectionProviderMock,
			$objectCacheFactory,
			$this->getLoggerMock(),
			[ $config1 ]
		);

		$c1 = $manager->getConfigObject( 'config1' );
		$this->assertInstanceOf( IDynamicConfig::class, $c1 );
		$c2 = $manager->getConfigObject( 'configInvalid' );
		$this->assertNull( $c2 );

		$connectionProviderMock->getReplicaDatabase()->newSelectQueryBuilder()->expects( $this->once() )
			->method( 'fields' )
			->with( [ 'mwdc_key', 'mwdc_serialized' ] );
		$connectionProviderMock->getReplicaDatabase()->newSelectQueryBuilder()->expects( $this->once() )
			->method( 'where' )
			->with( [ 'mwdc_is_active' => 1 ] );

		$manager->applyConfig( $c1 );
	}

	/**
	 * @covers \MWStake\MediaWiki\Component\DynamicConfig\DynamicConfigManager::storeConfig
	 * @return void
	 */
	public function testStore() {
		$connectionProviderMock = $this->getConnectionProviderMock();
		$objectCacheFactory = $this->getObjectCacheFactoryMock();
		$config = $this->getConfigMock();
		$config->expects( $this->once() )->method( 'serialize' )->willReturn( 'dummy' );

		$manager = new DynamicConfigManager(
			$connectionProviderMock,
			$objectCacheFactory,
			$this->getLoggerMock(),
			[ $config ]
		);

		$c1 = $manager->getConfigObject( 'config1' );

		$connectionProviderMock->getPrimaryDatabase()->method( 'timestamp' )->willReturn( '0000' );
		$connectionProviderMock->getPrimaryDatabase()->expects( $this->once() )->method( 'insert' )->with(
			self::TABLE,
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
	 * @return IConnectionProvider&\PHPUnit\Framework\MockObject\MockObject
	 */
	public function getConnectionProviderMock() {
		$mock = $this->getMockBuilder( IConnectionProvider::class )
			->disableOriginalConstructor()
			->getMock();
		$mock->method( 'getPrimaryDatabase' )->willReturn( $this->getDatabaseMock() );
		$mock->method( 'getReplicaDatabase' )->willReturn( $this->getDatabaseMock() );

		return $mock;
	}

	/**
	 * @return ObjectCacheFactory&\PHPUnit\Framework\MockObject\MockObject
	 */
	public function getObjectCacheFactoryMock() {
		$cache = new EmptyBagOStuff();

		$objectCacheFactoryMock = $this->getMockBuilder( ObjectCacheFactory::class )
			->disableOriginalConstructor()
			->getMock();
		$objectCacheFactoryMock->method( 'getLocalServerInstance' )->willReturn( $cache );

		return $objectCacheFactoryMock;
	}

	/**
	 * @return DBConnRef&\PHPUnit\Framework\MockObject\MockObject
	 */
	public function getDatabaseMock() {
		$mock = $this->getMockBuilder( DBConnRef::class )
			->disableOriginalConstructor()
			->getMock();
		$mock->method( 'tableExists' )->willReturn( true );

		$mock->method( 'newSelectQueryBuilder' )->willReturn( $this->getSelectQueryBuilderMock() );

		return $mock;
	}

	/**
	 * @return DBConnRef&\PHPUnit\Framework\MockObject\MockObject
	 */
	public function getSelectQueryBuilderMock() {
		$mock = $this->getMockBuilder( SelectQueryBuilder::class )
			->disableOriginalConstructor()
			->getMock();
		$mock->method( 'table' )->willReturn( $mock );
		$mock->method( 'fields' )->willReturn( $mock );
		$mock->method( 'where' )->willReturn( $mock );
		$mock->method( 'caller' )->willReturn( $mock );

		$fakeResultWrapperMock = new FakeResultWrapper( [
			(object)[ 'mwdc_key' => 'config1', 'mwdc_serialized' => 'dummy' ]
		] );

		$mock->method( 'fetchResultSet' )->willReturn( $fakeResultWrapperMock );

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
