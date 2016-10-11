<?php
namespace App\Console\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;
use \Illuminate\Support\Facades\DB;
use \Illuminate\Support\Str;

class MakesqltoMigration extends Command
{

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'make:sqltomigrations {db} {--O|only=} {--I|ignore=} {--C|comment}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '把SQL转换成migrations文件,如：php artisan make:sqltomigrations dbName';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $options = $this->option();
        $arguements = $this->argument();
        
        if (empty($arguements['db'])) {
            echo "\nThe DB name is required.\n\n";
            exit();
        } else {
            // make sure the DB exists
            $databaseExists = DB::select("SHOW DATABASES LIKE '{$arguements['db']}'");
            if (count($databaseExists) === 0) {
                echo "\nThe '{$arguements['db']}' database does NOT exist..\n\n";
                exit();
            }
        }
        
        // can't use --only and --ignore together
        if (! empty($options['only']) && ! (empty($options['ignore']))) {
            echo "\n--only & --ignore can NOT be used together.  Choose one or the other.\n\n";
            exit();
        }
        
        // ignore option
        $ignoreTables = array();
        if (! empty($options['ignore'])) {
            $ignoreTables = explode(',', $options['ignore']);
        }
        
        // only option
        $onlyTables = array();
        if (! empty($options['only'])) {
            $onlyTables = explode(',', $options['only']);
        }
        
        // run it
        $migrate = new SqlMigrations();
        $migrate->ignore($ignoreTables);
        $migrate->only($onlyTables);
        $migrate->comment($options['comment']);
        $migrate->convert($arguements['db']);
        $migrate->write();
    }

    /**
     * Get the console command arguments.
     *
     * @return array
     */
    protected function getArguments()
    {
        return [
            [
                'db',
                InputOption::VALUE_REQUIRED,
                'DB to build migration files from.'
            ]
        ];
    }

    /**
     * Get the console command options.
     *
     * @return array
     */
    protected function getOptions()
    {
        return [
            [
                'only',
                null,
                InputOption::VALUE_OPTIONAL,
                'Only create from these tables. Comma separated, no spaces.',
                null
            ],
            [
                'ignore',
                null,
                InputOption::VALUE_OPTIONAL,
                'Tables to skip. Comma separated, no spaces.',
                null
            ]
        ];
    }
}

class SqlMigrations
{

    private static $ignore = array(
        'migrations'
    );

    private static $only = array();

    private static $database = "";

    private static $migrations = false;

    private static $schema = array();

    private static $selects = array(
        'column_name as Field',
        'column_type as Type',
        'is_nullable as Null',
        'column_key as Key',
        'column_default as Default',
        'extra as Extra',
        'data_type as Data_Type',
        'column_comment as comment'
    );

    private static $instance;

    private static $up = "";

    private static $down = "";
    
    private static $comment = true;

    private static function getTables()
    {
        return DB::select('SELECT table_name FROM information_schema.tables WHERE table_schema="' . self::$database . '"');
    }

    private static function getTableDescribes($table)
    {
        return DB::table('information_schema.columns')->where('table_schema', '=', self::$database)
            ->where('table_name', '=', $table)
            ->get(self::$selects);
    }

    private static function getForeignTables()
    {
        return DB::table('information_schema.KEY_COLUMN_USAGE')->where('CONSTRAINT_SCHEMA', '=', self::$database)
            ->where('REFERENCED_TABLE_SCHEMA', '=', self::$database)
            ->select('TABLE_NAME')
            ->distinct()
            ->get();
    }

    private static function getForeigns($table)
    {
        return DB::table('information_schema.KEY_COLUMN_USAGE')->where('CONSTRAINT_SCHEMA', '=', self::$database)
            ->where('REFERENCED_TABLE_SCHEMA', '=', self::$database)
            ->where('TABLE_NAME', '=', $table)
            ->select('COLUMN_NAME', 'REFERENCED_TABLE_NAME', 'REFERENCED_COLUMN_NAME')
            ->get();
    }
    
    private static function getIndexs($table)
    {
        return DB::table('information_schema.STATISTICS')->where('table_schema', '=', self::$database)
        ->where('table_name', '=', $table)
        ->select('INDEX_NAME', 'INDEX_TYPE', 'COLUMN_NAME')
        ->get();
    }

    private static function compileSchema($name, $values)
    {
        $schema = "<?php

use Illuminate\\Database\\Schema\\Blueprint;
use Illuminate\\Database\\Migrations\\Migration;

//
// Auto-generated Migration Created: " . date("Y-m-d H:i:s") . "
// ------------------------------------------------------------

class Create" . str_replace('_', '', Str::title($name)) . "Table extends Migration {

\t/**
\t * Run the migrations.
\t *
\t * @return void
\t*/
\tpublic function up()
\t{
{$values['up']}
\t}

\t/**
\t * Reverse the migrations.
\t *
\t * @return void
\t*/
\tpublic function down()
\t{
{$values['down']}
\t}
    }";
        
        return $schema;
    }

    public function up($up)
    {
        self::$up = $up;
        return self::$instance;
    }

    public function down($down)
    {
        self::$down = $down;
        return self::$instance;
    }

    public function ignore($tables)
    {
        self::$ignore = array_merge($tables, self::$ignore);
        return self::$instance;
    }

    public function only($tables)
    {
        self::$only = array_merge($tables, self::$only);
        return self::$instance;
    }

    public function migrations()
    {
        self::$migrations = true;
        return self::$instance;
    }
    
    public function comment($options)
    {
        self::$comment = $options;
        return self::$comment;
    }

    /**
     * Iterates over the schemas and writes each one individually
     *
     * @return void
     */
    public function write()
    {
        echo "\nStarting schema migration.\n";
        echo "--------------------------\n";
        // print_r(self::$ignore);
        // print_r(self::$only); exit;
        foreach (self::$schema as $name => $values) {
            // determine if only or ignore is required
            if (count(self::$only) > 0) {
                if (! in_array($name, self::$only)) {
                    continue;
                }
            } else {
                if (in_array($name, self::$ignore)) {
                    continue;
                }
            }
            
            $schema = self::compileSchema($name, $values);
            $filename = date('Y_m_d_His') . "_create_" . $name . "_table.php";
            $path = 'database/migrations/'.date('Y-m-d').'/';
            if(!is_dir($path)){
                mkdir($path,0777,true);
                chmod($path,0777);
            }
            file_put_contents("{$path}{$filename}", $schema);
            chmod("{$path}{$filename}",0777);
            echo "Writing {$path}/{$filename}...\n";
        }
        
        echo "--------------------------\n";
        echo "Schema migration COMPLETE.\n\n";
    }

    public function convert($database)
    {
        self::$instance = new self();
        self::$database = $database;
        $table_headers = array(
            'Field',
            'Type',
            'Null',
            'Key',
            'Default',
            'Extra'
        );
        $tables = self::getTables();
        foreach ($tables as $key => $value) {
            if (in_array($value->table_name, self::$ignore)) {
                continue;
            }
            
            $down = "\t\tSchema::drop('{$value->table_name}');";
            $up = "\t\tSchema::create('{$value->table_name}', function(Blueprint $" . "table) {\n";
            $tableDescribes = self::getTableDescribes($value->table_name);
            $tableKeys = $tableIndexs = [];
            $timestamps = false;
            foreach ($tableDescribes as $values) {
                $method = "";
                $para = strpos($values->Type, '(');
                $type = $para > - 1 ? substr($values->Type, 0, $para) : $values->Type;
                $numbers = "";
                $nullable = $values->Null == "NO" ? "" : "->nullable()";
                $default = empty($values->Default) ? "" : "->default(\"{$values->Default}\")";
                $unsigned = strpos($values->Type, "unsigned") === false ? '' : '->unsigned()';
                $unique = $values->Key == 'UNI' ? "->unique()" : "";
                switch ($type) {
                    // bigIncrements
                    
                    case 'bigint':
                        $method = 'bigInteger';
                        break;
                    case 'blob':
                        $method = 'binary';
                        break;
                    case 'boolean':
                        $method = 'boolean';
                        break;
                    case 'char':
                    case 'varchar':
                        $para = strpos($values->Type, '(');
                        $numbers = ", " . substr($values->Type, $para + 1, - 1);
                        $method = 'string';
                        break;
                    case 'date':
                        $method = 'date';
                        break;
                    case 'datetime':
                        $method = 'dateTime';
                        break;
                    case 'decimal':
                        $para = strpos($values->Type, '(');
                        $numbers = ", " . substr($values->Type, $para + 1, - 1);
                        $method = 'decimal';
                        break;
                    
                    // double
                    
                    // enum
                    
                    case 'float':
                        $method = 'float';
                        break;
                    
                    // increments
                    
                    case 'int':
                        $method = 'integer';
                        $para = strpos($values->Type, '(');
                        $paraend = strpos(strrev($values->Type), ')');
                        $type = substr($values->Type, $para + 1, - ($paraend+1));
                        //$numbers = ", ".$type;
                        break;
                    
                    // longText
                    
                    // mediumInteger
                    
                    case 'mediumtext':
                        $method = 'mediumtext';
                        break;
                    
                    case 'smallint':
                        $method = 'smallInteger';
                        break;
                    case 'tinyint':
                        $para = strpos($values->Type, '(');
                        $paraend = strpos(strrev($values->Type), ')');
                        $type = substr($values->Type, $para + 1, - ($paraend+1));
                        $method = 'tinyInteger';
                        //$numbers = ", ".$type;
                        break;
                    case 'text':
                        $method = 'text';
                        break;
                    
                    // time
                    
                    // nullableTimestamps
                    case 'timestamp':
                        $method = 'timestamp';
                        break;
                    case 'enum':
                        $method = 'enum';
                        $para = strpos($values->Type, '(');
                        $paraend = strpos(strrev($values->Type), ')');
                        $type = substr($values->Type, $para + 1, - ($paraend+1));
                        $numbers = " , [".$type."]";
                        break;
                }
                if ($values->Key == 'PRI') {
                    if($values->Extra == 'auto_increment'){
                        $method = 'increments';
                    }else{
                        $tableKeys[] = $values->Field;
                    }
                }elseif(!empty($values->Key))
                {
                    $tableIndexs[$values->Key][] = $values->Field;
                }
                
                //字段注释
                $comment = '';
                if(self::$comment){
                    if($values->comment){
                        $comment = "->comment('{$values->comment}')";
                    }
                }
                
                
                switch ($values->Field){
                    case 'created_at':
                        if(!$timestamps){
                            $up.= "\t\t\t$"."table->timestamps();\n";
                        }
                        $timestamps = true;
                        break;
                    case 'updated_at':
                        if(!$timestamps){
                            $up.= "\t\t\t$"."table->timestamps();\n";
                        }
                        $timestamps = true;
                        break;
                    case 'deleted_at':
                        $up.= "\t\t\t$"."table->softDeletes();\n";
                        break;
                    default:
                        $up .= "\t\t\t$" . "table->{$method}('{$values->Field}'{$numbers}){$nullable}{$default}{$unsigned}{$unique}{$comment};\n";
                        break;
                }
                
            }
            
            //主键
            if(!empty($tableKeys)){
                if(count($tableKeys)>1){
                    $tablek = "'".implode("','", $tableKeys)."'";
                    $tablek = '['.$tablek.']';
                }else{
                    $tablek = "'".implode(',', $tableKeys)."'";
                }
                $up.= "\t\t\t$". "table->primary({$tablek});\n";
            }
            
            
            //索引
            if(!empty($tableIndexs)){
                $tableIndexs = $this->getIndexs($value->table_name);
                $indexarray = [];
                foreach ($tableIndexs as $indexs){
                    if($indexs->INDEX_NAME=='PRIMARY'){
                        continue;
                    }
                    
                    $indexarray[$indexs->INDEX_NAME][] = $indexs->COLUMN_NAME;
                }
                
                if($indexarray){
                    foreach ($indexarray as $indexName =>$vals){
                        if(count($vals)>1){
                            $indexs = "'".implode("','", $vals)."'";
                            $indexs = '['.$indexs.']';
                        }else{
                            $indexs = "'".implode(',', $vals)."'";
                        }
                        $up.= "\t\t\t$". "table->index({$indexs});\n";
                    }
                }
            }
            
            
            $up .= "\t\t});\n";
            self::$schema[$value->table_name] = array(
                'up' => $up,
                'down' => $down
            );
        }
        
        //外键
        // add foreign constraints, if any
        $tableForeigns = self::getForeignTables();
        if (sizeof($tableForeigns) !== 0) {
            foreach ($tableForeigns as $key => $value) {
                $up = "Schema::table('{$value->TABLE_NAME}', function($" . "table) {\n";
                $foreign = self::getForeigns($value->TABLE_NAME);
                foreach ($foreign as $k => $v) {
                    $up .= "\t\t\t$" . "table->foreign('{$v->COLUMN_NAME}')->references('{$v->REFERENCED_COLUMN_NAME}')->on('{$v->REFERENCED_TABLE_NAME}');\n";
                }
                $up .= "\t\t});\n";
                self::$schema[$value->TABLE_NAME . '_foreign'] = array(
                    'up' => $up,
                    'down' => $down
                );
            }
        }
        
        return self::$instance;
    }
}

