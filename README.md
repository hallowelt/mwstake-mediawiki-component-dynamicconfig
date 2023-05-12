# Events

This component allows you to emit notification events to consumers

## Use in a MediaWiki extension

**MediaWiki 1.35**

- Add `"mwstake/mediawiki-component-events": "~1"` to the `require` section of your `composer.json` file.

**MediaWiki 1.39**

- Add `"mwstake/mediawiki-component-events": "~2"` to the `require` section of your `composer.json` file.

## Register consumer

```php
$GLOBALS['wgMWStakeNotificationEventConsumers'][] = [
	'class' => MyConsumer::class,
	'services' => [
		'UserFactory'
	]
];
```

## Create Event

```php
class MyEvent implements \MWStake\MediaWiki\Component\Events\INotificationEvent {
 ....
}

$event = new MyEvent( $user );
```


## Emit Event

```php
$notifier = MediaWikiServices::getInstance()->getService( 'MWStake.Notifier' );
$notifier->emit( $event );

// Will call MyConsumer::consume( $event )
```

