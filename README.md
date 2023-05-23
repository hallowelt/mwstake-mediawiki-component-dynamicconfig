# Dynamic config

This component is designed to store configuration variables in Database and load (and apply) them from there.
It is primarely meant to replace `config` directory in BlueSpiceFoundation and configs stored in actual static PHP files.
It can be used to store any number of configurations, basically everything that goes into `LocalSettings.php`, except for
core configs, like DB connection and similar.

## Registering configs
- Implement a class that implements `MWStake\MediaWiki\Component\DynamicConfig\IDynamicConfig` interface.
- Use `MWStakeDynamicConfigRegisterConfigs` Hook to register your configs.

In case your config sets/reads MW globals (`$GLOBALS`), make it implement
`MWStake\MediaWiki\Component\DynamicConfig\GlobalsAwareDynamicConfig` interface as well.

## Using configs

To store config to DB, use

```php
    $manager = \MediaWiki\MediaWikiServices::getInstance()->getService( 'MWStakeDynamicConfigManager' );
    $manager->storeConfig( $config, $dataToBePassedToTheConfig );
```

This will call `serialize` method on the `IDynamicConfig` object with `$dataToBePassedToTheConfig` as an argument.
This method must return a string to be stored to the Database.

If your config's `shouldAutoApply` method returns `true`, the config will be auto-applied on `SetupAfterCache` hook.
Otherwise, you can apply it manually by calling

```php
    $manager = \MediaWiki\MediaWikiServices::getInstance()->getService( 'MWStakeDynamicConfigManager' );
    $manager->applyConfig( $config );
```

When applying, method `apply` will be called on the `IDynamicConfig` object with the data from the Database as an argument.
Config itself is responsible for parsing the data and applying it.

## Backups
On every change of a config value, a backup will be made. System will create up to 5 backups, after which it will rotate,
deleting the oldest one.

### Restoring backups

#### From code

```php
    $manager = \MediaWiki\MediaWikiServices::getInstance()->getService( 'MWStakeDynamicConfigManager' );
    $manager->restoreBackup( $config, $dataTime ); // DateTime object matching the timestamp of available backup
```

#### From CLI

```bash
  // List available config types
  php vendor/mwstake/mediawiki-component-dynamicconfig/maintenance/restoreFromBackup.php --list-types

  // List avilable backups for a type
  php vendor/mwstake/mediawiki-component-dynamicconfig/maintenance/restoreFromBackup.php --list-backups --config={key}

  // Restore a backup (timestamp in YmdHis format)
  php vendor/mwstake/mediawiki-component-dynamicconfig/maintenance/restoreFromBackup.php --backup-timestamp=20230523104627 --config={key}
```

Note: This will assume component is installed in the root `vendor` directory. If its not, specify path to `Maintenance.php`, as the first
argument of the script.

```bash
  // List available config types
  php vendor/mwstake/mediawiki-component-dynamicconfig/maintenance/restoreFromBackup.php some/path/Maintenance.php --list-types
```
