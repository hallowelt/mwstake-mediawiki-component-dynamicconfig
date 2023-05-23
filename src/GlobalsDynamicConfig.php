<?php

namespace MWStake\MediaWiki\Component\DynamicConfig;

abstract class GlobalsDynamicConfig implements IDynamicConfig, GlobalsAwareDynamicConfig {
	/** @var array */
	private $mwGlobals;

	/**
	 * @param array &$globals
	 *
	 * @return mixed|void
	 */
	public function setMwGlobals( array &$globals ) {
		$this->mwGlobals = &$globals;
	}

	/**
	 * @param string $serialized
	 *
	 * @return bool
	 */
	public function apply( string $serialized ) : bool {
		$parsed = unserialize( $serialized );
		if ( $parsed === null ) {
			return false;
		}
		foreach ( $parsed as $global => $value ) {
			$globalValue = $this->unserializeGlobalValue( $value );
			$this->mwGlobals[$global] = $globalValue;
		}

		return true;
	}

	/**
	 * @param array|null $additionalData
	 *
	 * @return string
	 */
	public function serialize( ?array $additionalData = [] ) : string {
		$serialized = [];
		foreach ( $this->getSupportedGlobals() as $global ) {
			$serialized[$global] = $this->serializeGlobal( $this->mwGlobals[$global] ?? null );
		}
		return serialize( $serialized );
	}

	/**
	 * @param string $name
	 *
	 * @return mixed|null
	 */
	protected function getMwGlobal( string $name ) {
		return $this->mwGlobals[$name] ?? null;
	}

	/**
	 * @param string $name
	 * @param mixed $value
	 *
	 * @return void
	 */
	public function setMwGlobal( string $name, $value ) {
		$this->mwGlobals[$name] = $value;
	}

	/**
	 * @param string $value
	 *
	 * @return mixed
	 */
	protected function unserializeGlobalValue( $value ) {
		// STUB
		return $value;
	}

	/**
	 * @param string $param
	 *
	 * @return mixed
	 */
	protected function serializeGlobal( $param ) {
		if ( !is_array( $param ) && !is_string( $param ) ) {
			throw new \InvalidArgumentException(
				'Cannot natively serialize. Override \'serializeGlobal\' function'
			);
		}
		return $param;
	}

	/**
	 * @return array
	 */
	abstract protected function getSupportedGlobals(): array;
}
