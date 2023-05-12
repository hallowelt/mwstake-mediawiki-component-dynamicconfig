<?php

namespace MWStake\MediaWiki\Component\DynamicConfig;

interface GlobalsAwareDynamicConfig extends IDynamicConfig {

	/**
	 * @param array &$globals
	 *
	 * @return mixed
	 */
	public function setMwGlobals( array &$globals );
}
