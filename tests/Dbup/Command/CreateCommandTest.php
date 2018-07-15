<?php
namespace Dbup\Tests\Command;

use Dbup\Application;
use Dbup\Command\CreateCommand;
use Symfony\Component\Console\Tester\CommandTester;
use Dbup\Command\InitCommand;

class CreateCommandTest extends \PHPUnit\Framework\TestCase
{
    public function testCreate()
    {
        $application = \Phake::partialMock('Dbup\Application');
        $application->add(new CreateCommand());

        $command = $application->find('create');
        $commandTester = new CommandTester($command);
        $commandTester->execute(
            [
                'command' => $command->getName(),
                'name' => 'foo',
                '--ini' => __DIR__ . '/../.dbup/properties.ini.test',
            ]
        );

        $display = $commandTester->getDisplay();
        $this->assertContains('created', $display);

        preg_match('/\'(.+)\'/', $display, $matches);
        $this->assertEquals(2, count($matches));

        $migration = str_replace("'", "", $matches[1]);
        unlink(__DIR__ . '/../../../' . $migration);
    }
}
