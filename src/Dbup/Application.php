<?php

/*
 * This file is part of Dbup.
 *
 * (c) Masao Maeda <brt.river@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Dbup;

use Dbup\Database\PdoDatabase;
use Symfony\Component\Console\Application as BaseApplication;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;
use Dbup\Exception\RuntimeException;
use Dotenv\Dotenv;

/**
 * @author Masao Maeda <brt.river@gmail.com>
 */
class Application extends BaseApplication
{
    const NAME = 'dbup';
    const VERSION = '0.5.1';
    /** sql file pattern */
    const PATTERN = '/^V(\d+?)__.*\.sql$/i';
    /** @var null PDO  */
    public $pdo = null;
    public $baseDir = '.';
    public $sqlFilesDir;
    public $appliedFilesDir;
    /** @var string Logo AA */
    private static $logo =<<<EOL
       _ _
     | | |
   __| | |__  _   _ _ __
  / _` | '_ \| | | | '_ \
 | (_| | |_) | |_| | |_) |
  \__,_|_.__/ \__,_| .__/
                   | |
                   |_|
 simple migration tool

EOL;

    public function __construct()
    {
        parent::__construct(self::NAME, self::VERSION);
        $this->sqlFilesDir =  $this->baseDir . '/sql';
        $this->appliedFilesDir =  $this->baseDir . '/.dbup/applied';
    }

    public function getDotEnv()
    {
        return $this->baseDir . '/.env';
    }

    public function getFinder()
    {
        return new Finder();
    }

    public function createPdo($dsn, $user, $password, $driverOptions)
    {
        $this->pdo = new PdoDatabase($dsn, $user, $password, $driverOptions);
    }

    public function parseDotEnv($path)
    {
        (new Dotenv(dirname($path), basename($path)))->overload();

        return null;
    }

    public function setConfigFromDotEnv($path)
    {
        $parse = $this->parseDotEnv($path);
        if (empty(getenv('DB_URI'))) {
            throw new RuntimeException('cannot find DB_URI  in your .env file');
        }

        $uri = (explode(',', getenv('DB_URI')))[0];

        $parseAry = \parse_url($uri);

        if (!isset($parseAry['host'], $parseAry['port'], $parseAry['path'], $parseAry['query'])) {
            throw new RuntimeException('Uri format error uri=' . $uri);
        }

        $parseAry['database'] = \str_replace('/', '', $parseAry['path']);
        $query                = $parseAry['query'];

        \parse_str($query, $options);

        if (!isset($options['user'], $options['password'])) {
            throw new RuntimeException('Lack of username and password，uri=' . $uri);
        }

        if (!isset($options['charset'])) {
            $options['charset'] = '';
        }

        $configs = \array_merge($parseAry, $options);
        unset($configs['path'], $configs['query']);

        $dsn = 'mysql:dbname=' . $configs['database']
                               .';host=' . $configs['host'] . ( !empty($configs['port']) ? (':' . $configs['port']) : '' )
                               . ( !empty($configs['charset']) ? ';charset=' . $configs['charset'] : '' );
        $user = $configs['user'];
        $password =$configs['password'];
        $driverOptions = (isset($configs['pdo_options']))? $configs['pdo_options']: [];

        if (!empty(getenv('DBUP_SQL_DIR'))) {
            $this->sqlFilesDir = getenv('DBUP_SQL_DIR');
        }
        if (!empty(getenv('DBUP_APPLIED_DIR'))) {
            $this->appliedFilesDir = getenv('DBUP_APPLIED_DIR');
        }

        $this->createPdo($dsn, $user, $password, $driverOptions);
    }

    public function getHelp()
    {
        return self::$logo . parent::getHelp();
    }

    /**
     * sort closure for Finder
     * @return callable sort closure for Finder
     */
    public function sort()
    {
        return function (\SplFileInfo $a, \SplFileInfo $b) {
            preg_match(self::PATTERN, $a->getFileName(), $version_a);
            preg_match(self::PATTERN, $b->getFileName(), $version_b);
            return ((int)$version_a[1] < (int)$version_b[1]) ? -1 : 1;
        };
    }

    /**
     * get sql files
     * @return Finder
     */
    public function getSqlFiles()
    {
        $sqlFinder = $this->getFinder();

        $files = $sqlFinder->files()
            ->in($this->sqlFilesDir)
            ->name(self::PATTERN)
            ->sort($this->sort())
        ;

        return $files;
    }

    /**
     * find sql file by the file name
     * @param $fileName
     * @return mixed
     * @throws Exception\RuntimeException
     */
    public function getSqlFileByName($fileName)
    {
        $sqlFinder = $this->getFinder();

        $files = $sqlFinder->files()
            ->in($this->sqlFilesDir)
            ->name($fileName)
        ;

        if ($files->count() !== 1) {
            throw new RuntimeException('cannot find File:' . $fileName);
        }

        foreach ($files as $file){
            return $file;
        }
    }

    /**
     * get applied files
     * @return Finder
     */
    public function getAppliedFiles()
    {
        $appliedFinder = $this->getFinder();

        $files = $appliedFinder->files()
            ->in($this->appliedFilesDir)
            ->name(self::PATTERN)
            ->sort($this->sort())
        ;

        return $files;
    }

    /**
     * get migration status
     * @return array Statuses with applied datetime and file name
     */
    public function getStatuses()
    {
        $files = $this->getSqlFiles();
        $appliedFiles = $this->getAppliedFiles();

        /**
         * is file applied or not
         * @param $file
         * @return bool if applied, return true.
         */
        $isApplied = function($file) use ($appliedFiles){
            foreach ($appliedFiles as $appliedFile) {
                if ($appliedFile->getFileName() === $file->getFileName()){
                    return true;
                }
            }
            return false;
        };

        $statuses = [];

        foreach($files as $file){
            $appliedAt = $isApplied($file)? date('Y-m-d H:i:s', $file->getMTime()): "";
            $statuses[] = new Status($appliedAt, $file);
        }

        return $statuses;
    }

    /**
     * get up candidates sql files
     */
    public function getUpCandidates()
    {
        $statuses = $this->getStatuses();

        // search latest applied migration
        $latest = '';
        foreach ($statuses as $status) {
            if ($status->appliedAt !== "") {
                $latest = $status->file->getFileName();
            }
        }

        // make statuses without being applied
        $candidates = [];
        $isSkipped = ($latest === '')? false: true;
        foreach ($statuses as $status) {
            if (false === $isSkipped) {
                $candidates[] = $status;
            }
            if($status->file->getFileName() !== $latest) {
                continue;
            } else {
                $isSkipped = false;
            }
        }

        return $candidates;
    }

    /**
     * update database
     * @param $file sql file to apply
     */
    public function up($file)
    {
        if (false === ($contents = file_get_contents($file->getPathName()))) {
            throw new RuntimeException($file->getPathName() . ' is not found.');
        }
        $queries = explode(';', $contents);
        try {
            $dbh = $this->pdo->connection(true);
            $dbh->beginTransaction();
            foreach($queries as $query) {
                $cleanedQuery = trim($query);
                if ('' === $cleanedQuery) {
                    continue;
                }
                $stmt = $dbh->prepare($cleanedQuery);
                $stmt->execute();
            }
            $dbh->commit();
        } catch(\PDOException $e) {
            $dbh->rollBack();
            throw new RuntimeException($e->getMessage() . PHP_EOL . $query);
        }

        $this->copyToAppliedDir($file);
    }

    /**
     * copy applied sql file to the applied directory.
     *
     * @param SplFileInfo $file
     */
    public function copyToAppliedDir($file)
    {
        if (false === @copy($file->getPathName(), $this->appliedFilesDir . '/' . $file->getFileName())) {
            throw new RuntimeException('cannot copy the sql file to applied directory. check the <info>'. $this->appliedFilesDir . '</info> directory.');
        }
    }
}
