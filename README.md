# laravel5_migrated
Creates individual migration files from a MYSQL DB

基于laravel5.1把mysql数据库表信息转换成laravel框架迁移文件。方便快速开发。

本脚本是从[laravel5-migrate-mysql](https://github.com/pringlized/laravel5-migrate-mysql)而来，并修复了如下问题：

* 修复enum字段类型，无法识别问题。
* 增加复合主键功能转换。
* 增加索引和复合索引转换。
* 增加字段注释文字转换。

###安装 
1.  Copy Makesqltomigration.php 文件到 app\Console\Commands
2.  增加 'App\Console\Commands\MakesqltoMigration' 到 app\Console\Kernel.php的$commands 数组里。

```php
class Kernel extends ConsoleKernel
{
    /**
     * The Artisan commands provided by your application.
     *
     * @var array
     */
    protected $commands = [
        'App\Console\Commands\MakesqltoMigration',
    ];
```

###如何使用？

** 转换整个库 **

```php artisan make:sqltomigrations dbName```

** 转换整个库，包括字段注释 **

```php artisan make:sqltomigrations dbName -C```


** 只转换指定的表 **

```php php artisan make:sqltomigrations dbName --only=table1,table2 -C```


** 转换整个库，忽略指定的表 **

```php php artisan make:sqltomigrations dbName --ignore=table1,table2 -C```


###BUG反馈

email: <hzhihu@gmail.com> 