<?php

namespace MWStake\MediaWiki\Component\DynamicConfig\Hook;

interface MWStakeDynamicConfigManagerLoadedHook {

	/**
	 * @return void
	 */
	public function onMWStakeDynamicConfigManagerLoaded(): void;
}
