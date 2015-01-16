<?php
 \Patchwork\Interceptor\applyScheduledPatches();
/**
 * Ruckusing
 *
 * @category  Ruckusing
 * @package   Task
 * @subpackage Db
 * @author    Cody Caughlan <codycaughlan % gmail . com>
 * @link      https://github.com/ruckus/ruckusing-migrations
 */

/**
 * Task_DB_Generate
 * generic task which acts as a Generator for migrations.
 *
 * @category Ruckusing
 * @package  Task
 * @subpackage Db
 * @author   Cody Caughlan <codycaughlan % gmail . com>
 * @author   Salimane Adjao Moustapha <me@salimane.com>
 */
class Task_Db_Generate extends Ruckusing_Task_Base implements Ruckusing_Task_Interface
{
    /**
     * Current Adapter
     *
     * @var Ruckusing_Adapter_Base
     */
    private $_adapter = null;

    /**
     * Creates an instance of Task_DB_Generate
     *
     * @param Ruckusing_Adapter_Base $adapter The current adapter being used
     *
     * @return Task_DB_Generate
     */
    public function __construct($adapter)
    {$__pwClosureName=__NAMESPACE__?__NAMESPACE__."\{closure}":"{closure}";$__pwClass=(__CLASS__&&__FUNCTION__!==$__pwClosureName)?__CLASS__:null;$__pwCalledClass=$__pwClass?\get_called_class():null;if(!empty(\Patchwork\Interceptor\State::$patches[$__pwClass][__FUNCTION__])){$__pwFrame=\count(\debug_backtrace(false));if(\Patchwork\Interceptor\intercept($__pwClass,$__pwCalledClass,__FUNCTION__,$__pwFrame,$__pwResult)){return$__pwResult;}}unset($__pwClass,$__pwCalledClass,$__pwResult,$__pwClosureName,$__pwFrame);
        parent::__construct($adapter);
        $this->_adapter = $adapter;
    }

    /**
     * Primary task entry point
     *
     * @param array $args The current supplied options.
     */
    public function execute($args)
    {$__pwClosureName=__NAMESPACE__?__NAMESPACE__."\{closure}":"{closure}";$__pwClass=(__CLASS__&&__FUNCTION__!==$__pwClosureName)?__CLASS__:null;$__pwCalledClass=$__pwClass?\get_called_class():null;if(!empty(\Patchwork\Interceptor\State::$patches[$__pwClass][__FUNCTION__])){$__pwFrame=\count(\debug_backtrace(false));if(\Patchwork\Interceptor\intercept($__pwClass,$__pwCalledClass,__FUNCTION__,$__pwFrame,$__pwResult)){return$__pwResult;}}unset($__pwClass,$__pwCalledClass,$__pwResult,$__pwClosureName,$__pwFrame);
        $output = '';
        // Add support for old migration style
        if (!is_array($args) || !array_key_exists('name', $args)) {
            $cargs = $this->parse_args($_SERVER['argv']);
            //input sanity check
            if (!is_array($cargs) || !array_key_exists('name', $cargs)) {
                $output .= $this->help();

                return $output;
            }
            $migration_name = $cargs['name'];
        }
        // Add NAME= parameter for db:generate
        else {
            $migration_name = $args['name'];
        }
        if (!array_key_exists('module', $args)) {
            $args['module'] = '';
        }

        //clear any filesystem stats cache
        clearstatcache();

        $framework = $this->get_framework();
        $migrations_dir = $framework->migrations_directory($args['module']);

        if (!is_dir($migrations_dir)) {
            $output .= "\n\tMigrations directory (" . $migrations_dir . " doesn't exist, attempting to create.\n";
            if (mkdir($migrations_dir, 0755, true) === FALSE) {
                $output .= "\n\tUnable to create migrations directory at " . $migrations_dir . ", check permissions?\n";
            } else {
                $output .= "\n\tCreated OK\n";
            }
        }

        //generate a complete migration file
        $next_version = Ruckusing_Util_Migrator::generate_timestamp();
        $class = Ruckusing_Util_Naming::camelcase($migration_name);
        $all_dirs = $framework->migrations_directories();

        if ($re = self::classNameIsDuplicated($class, $all_dirs)) {
            throw new Ruckusing_Exception(
                    "This migration name is already used in the \"$re\" directory. Please, choose another name.",
                    Ruckusing_Exception::INVALID_ARGUMENT
            );
        }

        $file_name = $next_version . '_' . $class . '.php';

        //check to make sure our destination directory is writable
        if (!is_writable($migrations_dir)) {
            throw new Ruckusing_Exception(
                    "ERROR: migration directory '"
                    . $migrations_dir
                    . "' is not writable by the current user. Check permissions and try again.",
                    Ruckusing_Exception::INVALID_MIGRATION_DIR
            );
        }

        //write it out!
        $full_path = $migrations_dir . DIRECTORY_SEPARATOR . $file_name;
        $template_str = self::get_template($class);
        $file_result = file_put_contents($full_path, $template_str);
        if ($file_result === FALSE) {
            throw new Ruckusing_Exception(
                    "Error writing to migrations directory/file. Do you have sufficient privileges?",
                    Ruckusing_Exception::INVALID_MIGRATION_DIR
            );
        } else {
            $output .= "\n\tCreated migration: {$file_name}\n\n";
        }

        return $output;
    }

    /**
     * Parse command line arguments.
     *
     * @param array $argv The current supplied command line arguments.
     *
     * @return array ('name' => 'name')
     */
    public function parse_args($argv)
    {$__pwClosureName=__NAMESPACE__?__NAMESPACE__."\{closure}":"{closure}";$__pwClass=(__CLASS__&&__FUNCTION__!==$__pwClosureName)?__CLASS__:null;$__pwCalledClass=$__pwClass?\get_called_class():null;if(!empty(\Patchwork\Interceptor\State::$patches[$__pwClass][__FUNCTION__])){$__pwFrame=\count(\debug_backtrace(false));if(\Patchwork\Interceptor\intercept($__pwClass,$__pwCalledClass,__FUNCTION__,$__pwFrame,$__pwResult)){return$__pwResult;}}unset($__pwClass,$__pwCalledClass,$__pwResult,$__pwClosureName,$__pwFrame);
        foreach ($argv as $i => $arg) {
            if (strpos($arg, '=') !== FALSE) {
                unset($argv[$i]);
            }
        }
        $num_args = count($argv);
        if ($num_args < 3) {
            return array();
        }
        $migration_name = $argv[2];

        return array('name' => $migration_name);
    }

    /**
     * Indicate if a class name is already used
     *
     * @param string $classname      The class name to test
     * @param string $migrationsDirs The array with directories of migration files (in simplest case - just array with one element)
     *
     * @return bool
     */
    public static function classNameIsDuplicated($classname, $migrationsDirs)
    {$__pwClosureName=__NAMESPACE__?__NAMESPACE__."\{closure}":"{closure}";$__pwClass=(__CLASS__&&__FUNCTION__!==$__pwClosureName)?__CLASS__:null;$__pwCalledClass=$__pwClass?\get_called_class():null;if(!empty(\Patchwork\Interceptor\State::$patches[$__pwClass][__FUNCTION__])){$__pwFrame=\count(\debug_backtrace(false));if(\Patchwork\Interceptor\intercept($__pwClass,$__pwCalledClass,__FUNCTION__,$__pwFrame,$__pwResult)){return$__pwResult;}}unset($__pwClass,$__pwCalledClass,$__pwResult,$__pwClosureName,$__pwFrame);
        $migrationFiles = Ruckusing_Util_Migrator::get_migration_files($migrationsDirs, 'up');
        $classname = strtolower($classname);
        foreach ($migrationFiles as $file) {
            if (strtolower($file['class']) == $classname) {
                return $file['module'];
            }
        }

        return false;
    }

    /**
     * generate a migration template string
     *
     * @param  string $klass class name to create
     * @return string
     */
    public static function get_template($klass)
    {$__pwClosureName=__NAMESPACE__?__NAMESPACE__."\{closure}":"{closure}";$__pwClass=(__CLASS__&&__FUNCTION__!==$__pwClosureName)?__CLASS__:null;$__pwCalledClass=$__pwClass?\get_called_class():null;if(!empty(\Patchwork\Interceptor\State::$patches[$__pwClass][__FUNCTION__])){$__pwFrame=\count(\debug_backtrace(false));if(\Patchwork\Interceptor\intercept($__pwClass,$__pwCalledClass,__FUNCTION__,$__pwFrame,$__pwResult)){return$__pwResult;}}unset($__pwClass,$__pwCalledClass,$__pwResult,$__pwClosureName,$__pwFrame);
        $template = <<<TPL
<?php

class $klass extends Ruckusing_Migration_Base
{
    public function up()
    {
    }//up()

    public function down()
    {
    }//down()
}

TPL;

        return $template;
    }

    /**
     * Return the usage of the task
     *
     * @return string
     */
    public function help()
    {$__pwClosureName=__NAMESPACE__?__NAMESPACE__."\{closure}":"{closure}";$__pwClass=(__CLASS__&&__FUNCTION__!==$__pwClosureName)?__CLASS__:null;$__pwCalledClass=$__pwClass?\get_called_class():null;if(!empty(\Patchwork\Interceptor\State::$patches[$__pwClass][__FUNCTION__])){$__pwFrame=\count(\debug_backtrace(false));if(\Patchwork\Interceptor\intercept($__pwClass,$__pwCalledClass,__FUNCTION__,$__pwFrame,$__pwResult)){return$__pwResult;}}unset($__pwClass,$__pwCalledClass,$__pwResult,$__pwClosureName,$__pwFrame);
        $output =<<<USAGE

\tTask: db:generate <migration name>

\tGenerator for migrations.

\t<migration name> is a descriptive name of the migration,
\tjoined with underscores. e.g.: add_index_to_users | create_users_table

\tExample :

\t\tphp {$_SERVER['argv'][0]} db:generate add_index_to_users

USAGE;

        return $output;
    }

}\Patchwork\Interceptor\applyScheduledPatches();