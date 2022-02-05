<?php

declare(strict_types=1);

namespace Yiisoft\Db\Pgsql\Tests;

use InvalidArgumentException;
use Yiisoft\Db\Pgsql\DMLCommand;

/**
 * @group pgsql
 */
final class DMLCommandTest extends TestCase
{

    public function testResetSequence(): void
    {
        $db = $this->getConnection();
        $dml = new DMLCommand($db->getQuoter(), $db->getSchema());

        $expected = "SELECT SETVAL('\"item_id_seq\"',(SELECT COALESCE(MAX(\"id\"),0) FROM \"item\")+1,false)";
        $sql = $dml->resetSequence('item');
        $this->assertEquals($expected, $sql);

        $expected = "SELECT SETVAL('\"item_id_seq\"',4,false)";
        $sql = $dml->resetSequence('item', 4);
        $this->assertEquals($expected, $sql);
    }

    public function testResetSequencePostgres12(): void
    {
        if (version_compare($this->getConnection()->getServerVersion(), '12.0', '<')) {
            $this->markTestSkipped('PostgreSQL < 12.0 does not support GENERATED AS IDENTITY columns.');
        }

        $db = $this->getConnection(true, null, __DIR__ . '/Fixture/postgres12.sql');
        $dml = new DMLCommand($db->getQuoter(), $db->getSchema());

        $expected = "SELECT SETVAL('\"item_12_id_seq\"',(SELECT COALESCE(MAX(\"id\"),0) FROM \"item_12\")+1,false)";
        $sql = $dml->resetSequence('item_12');
        $this->assertEquals($expected, $sql);

        $expected = "SELECT SETVAL('\"item_12_id_seq\"',4,false)";
        $sql = $dml->resetSequence('item_12', 4);
        $this->assertEquals($expected, $sql);
    }
}
