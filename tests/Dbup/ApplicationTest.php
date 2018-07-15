<?php
namespace Dbup\Tests;

use Dbup\Application;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;

class ApplicationTest extends \PHPUnit\Framework\TestCase
{
    public $app;
    public $pdo;

    public function setUP()
    {
        $this->app = \Phake::partialMock('Dbup\Application');
        $this->pdo = \Phake::mock('Dbup\Database\PdoDatabase');
        $this->dbh = \Phake::mock('\Pdo');
        \Phake::when($this->pdo)->connection(\Phake::anyParameters())->thenReturn($this->dbh);
        $this->stmt = \Phake::mock('\PDOStatement');
        \Phake::when($this->dbh)->prepare(\Phake::anyParameters())->thenReturn($this->stmt);
    }

    public function testSetPropertiesWhenInstanceIsMade()
    {
        $this->assertEquals($this->app->sqlFilesDir, './sql');
        $this->assertEquals($this->app->appliedFilesDir, './.dbup/applied');
    }

    public function testGetDotEnvFilePath()
    {
        $this->assertEquals($this->app->getDotEnv(), './.env');
    }

    public function testParseDotEnvReplaceVariables()
    {
        $envReplace = '192.168.0.1:3306/test?user=admin&password=pass&charset=utf8mb4,192.168.0.1:3316/test?user=root&password=root&charset=utf8mb4';

        $ini = __DIR__ . '/.env.replace';
        $parsed = $this->app->parseDotEnv($ini);

        $this->assertEquals(getenv('DB_URI'), $envReplace);
    }

    public function testSetConfigFromDotEnv()
    {
        $ini = __DIR__ . '/.env';
        $this->app->setConfigFromDotEnv($ini);

        $this->assertEquals($this->app->sqlFilesDir, '/etc/dbup/sql');
        $this->assertEquals($this->app->appliedFilesDir, '/etc/dbup/applied');
        \Phake::verify($this->app)->createPdo('mysql:dbname=test;host=192.168.0.1:3316;charset=utf8mb4', 'root', 'root', []);
    }

    /**
     * @expectedException Dbup\Exception\RuntimeException
     */
    public function testCatchExceptionSetConfigFromEmptyDotEnv()
    {
        $ini = __DIR__ . '/.env.empty';
        $this->app->setConfigFromDotEnv($ini);
    }

    public function testSetConfigFromMinDotEnv()
    {
        $ini = __DIR__ . '/.env.min';
        $this->app->setConfigFromDotEnv($ini);

        \Phake::verify($this->app)->createPdo('mysql:dbname=testdatabase;host=localhost:3306', '', '', []);
    }

    public function testGetSqlFileByName()
    {
        $this->app->sqlFilesDir = __DIR__ . '/sql';
        $file = $this->app->getSqlFileByName('V1__sample_select.sql');
        $this->assertEquals($file->getFileName(), 'V1__sample_select.sql');
    }

    /**
     * @expectedException Dbup\Exception\RuntimeException
     */
    public function testCatchExceptionWhenNotFoundSqlFileByName()
    {
        $this->app->sqlFilesDir = __DIR__ . '/sql';
        $this->app->getSqlFileByName('hoge');
    }

    public function testGetStatuses()
    {
        $this->app->sqlFilesDir = __DIR__ . '/sql';
        $this->app->appliedFilesDir = __DIR__ . '/.dbup/applied';

        $statuses = $this->app->getStatuses();

        $this->assertEquals(count($statuses), 3);
        $this->assertNotEquals($statuses[0]->appliedAt, '');
        $this->assertEquals($statuses[0]->file->getFileName(), 'V1__sample_select.sql');
        $this->assertEquals($statuses[1]->appliedAt, '');
        $this->assertEquals($statuses[1]->file->getFileName(), 'V3__sample3_select.sql');
        $this->assertEquals($statuses[2]->appliedAt, '');
        $this->assertEquals($statuses[2]->file->getFileName(), 'V12__sample12_select.sql');
    }

    public function testGetUpCandidates()
    {
        $this->app->sqlFilesDir = __DIR__ . '/sql';
        $this->app->appliedFilesDir = __DIR__ . '/.dbup/applied';

        $candidates = $this->app->getUpCandidates();

        $this->assertEquals(count($candidates), 2);
        $this->assertEquals($candidates[0]->file->getFileName(), 'V3__sample3_select.sql');
        $this->assertEquals($candidates[1]->file->getFileName(), 'V12__sample12_select.sql');
    }

    /**
     * @expectedException Dbup\Exception\RuntimeException
     */
    public function testCatchExceptionCopyToAppliedDir()
    {
        $this->app->appliedFilesDir = __DIR__ . '/nondir';
        $file = new \SplFileInfo(__DIR__ . '/samples/plural.sql');
        $this->app->copyToAppliedDir($file);
    }

    public function testCopyToAppliedDir()
    {
        @unlink(__DIR__ . '/.dbup/applied/single.sql');

        $this->app->appliedFilesDir = __DIR__ . '/.dbup/applied';
        $file = new \SplFileInfo(__DIR__ . '/samples/single.sql');
        $this->app->copyToAppliedDir($file);

        $this->assertEquals(file_exists(__DIR__ . '/.dbup/applied/single.sql'), true);

        @unlink(__DIR__ . '/.dbup/applied/single.sql');
    }

    public function testUpWithSingleStatementSqlFile()
    {
        $this->app->appliedFilesDir = __DIR__ . '/.dbup/applied';
                $this->app->pdo = $this->pdo;
        $file = new \SplFileInfo(__DIR__ . '/samples/single.sql');

        $this->app->up($file);

        \Phake::verify($this->dbh, \Phake::times(1))->prepare('select 1+1');

        @unlink(__DIR__ . '/.dbup/applied/single.sql');
    }

    public function testUpWithPluralStatementsSqlFile()
    {
        $this->app->appliedFilesDir = __DIR__ . '/.dbup/applied';
        $this->app->pdo = $this->pdo;
        $file = new \SplFileInfo(__DIR__ . '/samples/plural.sql');

        $this->app->up($file);

        \Phake::verify($this->dbh, \Phake::times(1))->prepare('select 1+1');
        \Phake::verify($this->dbh, \Phake::times(1))->prepare('select 2+2');

        @unlink(__DIR__ . '/.dbup/applied/plural.sql');
    }

    /**
     * @expectedException Dbup\Exception\RuntimeException
     */
    public function testCatchExceptionWhenUp()
    {
        $this->app->pdo = $this->pdo;
        $file = new \SplFileInfo(__DIR__ . '/samples/single.sql');

        \Phake::when($this->dbh)->prepare(\Phake::anyParameters())->thenThrow(new \PDOException);

        $this->app->up($file);
    }

    /**
     * issue #1
     */
    public function testUpWithSingleStatementWithEmptyLineSqlFile()
    {
        $this->app->appliedFilesDir = __DIR__ . '/.dbup/applied';
        $this->app->pdo = $this->pdo;
        $file = new \SplFileInfo(__DIR__ . '/samples/singleWithEmpty.sql');

        $this->app->up($file);

        \Phake::verify($this->dbh, \Phake::times(1))->prepare(\Phake::anyParameters());

        @unlink(__DIR__ . '/.dbup/applied/single.sql');
    }
}
