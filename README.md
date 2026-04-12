# PRADO MongoDB
MongoDB integration with PRADO

## Installation by Composer

Enter the following command into your project to install:
```sh
composer require belisoful/prado-mongodb
```


## Brew installation of Mongo

```sh
brew tap mongodb/brew
brew install mongodb-community

brew services start mongodb-community

		#or 

mongod --config /opt/homebrew/etc/mongod.conf
```

Verification:
```sh
ps aux | grep mongod
		#or
mongosh
```

This may require the installation of PHP's `mongodb` extension.
```php
pecl install mongodb
```
There may be `brew` formulas of the mongodb extension for PHP.
