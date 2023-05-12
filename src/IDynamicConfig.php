<?php

namespace MWStake\MediaWiki\Component\DynamicConfig;

interface IDynamicConfig {

	/**
	 * @return string
	 */
	public function getKey(): string;

	/**
	 * @param string $serialized
	 *
	 * @return bool
	 */
	public function apply( string $serialized ): bool;

	/**
	 * Read out any necessary config and serialize to be stored in the database
	 *
	 * @return string
	 */
	public function serialize(): string;

	/**
	 * Whether to call `apply()` automatically when the config is loaded
	 * @return bool
	 */
	public function shouldAutoApply(): bool;
}
