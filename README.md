# prado-mongodb
MongoDB integration with PRADO


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