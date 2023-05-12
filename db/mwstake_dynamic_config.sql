CREATE TABLE IF NOT EXISTS /*$wgDBprefix*/mwstake_dynamic_config (
	mwdc_key VARCHAR(255) NOT NULL,
    mwdc_serialized TEXT NULL,
    mwdc_timestamp VARCHAR(14) NULL,
    mwdc_is_active TINYINT(1) NOT NULL DEFAULT 1
) /*$wgDBTableOptions*/;
