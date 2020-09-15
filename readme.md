# MySQL Snapshots For Laravel

```console
composer require ziffmedia/laravel-mysql-snapshots
```

Features:

- create with mysqldump, load with mysql command line tools
- specify multiple plans (daily, monthly, different connections, etc)
- each plan can specify how many to keep (default 1)
- list backups, delete backup(s)
- keep last restored backup locally ("cached", as to avoid re-download)
