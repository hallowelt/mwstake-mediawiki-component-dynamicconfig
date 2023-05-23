<?php

namespace MWStake\MediaWiki\Component\DynamicConfig\Hook;

interface MWStakeDynamicConfigRegisterConfigsHook {
	/**
	 * @param array &$configs
	 */
	public function onMWStakeDynamicConfigRegisterConfigs( array &$configs ): void;
}
