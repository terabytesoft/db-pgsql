<?php

declare(strict_types=1);

namespace Yiisoft\Db\Pgsql\Tests;

use InvalidArgumentException;
use Yiisoft\Arrays\ArrayHelper;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Expression\Expression;
use Yiisoft\Db\Expression\JsonExpression;
use Yiisoft\Db\Pgsql\DMLCommand;
use Yiisoft\Db\Query\Query;

/**
 * @group pgsql
 */
final class DMLCommandTest extends TestCase
{
    public function insertProviderTrait(): array
    {
        return [
            'regular-values' => [
                'customer',
                [
                    'email' => 'test@example.com',
                    'name' => 'silverfire',
                    'address' => 'Kyiv {{city}}, Ukraine',
                    'is_active' => false,
                    'related_id' => null,
                ],
                [],
                $this->replaceQuotes(
                    'INSERT INTO [[customer]] ([[email]], [[name]], [[address]], [[is_active]], [[related_id]])'
                    . ' VALUES (:qp0, :qp1, :qp2, :qp3, :qp4)'
                ),
                [
                    ':qp0' => 'test@example.com',
                    ':qp1' => 'silverfire',
                    ':qp2' => 'Kyiv {{city}}, Ukraine',
                    ':qp3' => false,
                    ':qp4' => null,
                ],
            ],
            'params-and-expressions' => [
                '{{%type}}',
                [
                    '{{%type}}.[[related_id]]' => null,
                    '[[time]]' => new Expression('now()'),
                ],
                [],
                'INSERT INTO {{%type}} ({{%type}}.[[related_id]], [[time]]) VALUES (:qp0, now())',
                [
                    ':qp0' => null,
                ],
            ],
            'carry passed params' => [
                'customer',
                [
                    'email' => 'test@example.com',
                    'name' => 'sergeymakinen',
                    'address' => '{{city}}',
                    'is_active' => false,
                    'related_id' => null,
                    'col' => new Expression('CONCAT(:phFoo, :phBar)', [':phFoo' => 'foo']),
                ],
                [':phBar' => 'bar'],
                $this->replaceQuotes(
                    'INSERT INTO [[customer]] ([[email]], [[name]], [[address]], [[is_active]], [[related_id]], [[col]])'
                    . ' VALUES (:qp1, :qp2, :qp3, :qp4, :qp5, CONCAT(:phFoo, :phBar))'
                ),
                [
                    ':phBar' => 'bar',
                    ':qp1' => 'test@example.com',
                    ':qp2' => 'sergeymakinen',
                    ':qp3' => '{{city}}',
                    ':qp4' => false,
                    ':qp5' => null,
                    ':phFoo' => 'foo',
                ],
            ],
            'carry passed params (query)' => [
                'customer',
                (new Query($this->getConnection()))
                    ->select([
                        'email',
                        'name',
                        'address',
                        'is_active',
                        'related_id',
                    ])
                    ->from('customer')
                    ->where([
                        'email' => 'test@example.com',
                        'name' => 'sergeymakinen',
                        'address' => '{{city}}',
                        'is_active' => false,
                        'related_id' => null,
                        'col' => new Expression('CONCAT(:phFoo, :phBar)', [':phFoo' => 'foo']),
                    ]),
                [':phBar' => 'bar'],
                $this->replaceQuotes(
                    'INSERT INTO [[customer]] ([[email]], [[name]], [[address]], [[is_active]], [[related_id]])'
                    . ' SELECT [[email]], [[name]], [[address]], [[is_active]], [[related_id]] FROM [[customer]]'
                    . ' WHERE ([[email]]=:qp1) AND ([[name]]=:qp2) AND ([[address]]=:qp3) AND ([[is_active]]=:qp4)'
                    . ' AND ([[related_id]] IS NULL) AND ([[col]]=CONCAT(:phFoo, :phBar))'
                ),
                [
                    ':phBar' => 'bar',
                    ':qp1' => 'test@example.com',
                    ':qp2' => 'sergeymakinen',
                    ':qp3' => '{{city}}',
                    ':qp4' => false,
                    ':phFoo' => 'foo',
                ],
            ],
        ];
    }

    /**
     * @dataProvider insertProviderTrait
     *
     * @param string $table
     * @param array|ColumnSchema $columns
     * @param array $params
     * @param string $expectedSQL
     * @param array $expectedParams
     *
     * @throws Exception
     * @throws InvalidArgumentException
     * @throws InvalidConfigException
     * @throws NotSupportedException
     */
    public function testInsert(string $table, $columns, array $params, string $expectedSQL, array $expectedParams): void
    {
        $actualParams = $params;
        $db = $this->getConnection();
        $dml = new DMLCommand($db->getQueryBuilder(), $db->getQuoter(), $db->getSchema());
        $this->assertSame($expectedSQL, $dml->insert($table, $columns, $actualParams));
        $this->assertSame($expectedParams, $actualParams);
    }


    public function testResetSequence(): void
    {
        $db = $this->getConnection();
        $dml = new DMLCommand($db->getQueryBuilder(), $db->getQuoter(), $db->getSchema());

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
        $dml = new DMLCommand($db->getQueryBuilder(), $db->getQuoter(), $db->getSchema());

        $expected = "SELECT SETVAL('\"item_12_id_seq\"',(SELECT COALESCE(MAX(\"id\"),0) FROM \"item_12\")+1,false)";
        $sql = $dml->resetSequence('item_12');
        $this->assertEquals($expected, $sql);

        $expected = "SELECT SETVAL('\"item_12_id_seq\"',4,false)";
        $sql = $dml->resetSequence('item_12', 4);
        $this->assertEquals($expected, $sql);
    }

    public function updateProvider(): array
    {
        $items = $this->updateProviderTrait();
        $items[] = [
            'profile',
            [
                'description' => new JsonExpression(['abc' => 'def', 123, null]),
            ],
            [
                'id' => 1,
            ],
            $this->replaceQuotes('UPDATE [[profile]] SET [[description]]=:qp0 WHERE [[id]]=:qp1'),
            [
                ':qp0' => '{"abc":"def","0":123,"1":null}',
                ':qp1' => 1,
            ],
        ];
        return $items;
    }

    public function updateProviderTrait(): array
    {
        return [
            [
                'customer',
                [
                    'status' => 1,
                    'updated_at' => new Expression('now()'),
                ],
                [
                    'id' => 100,
                ],
                $this->replaceQuotes(
                    'UPDATE [[customer]] SET [[status]]=:qp0, [[updated_at]]=now() WHERE [[id]]=:qp1'
                ),
                [
                    ':qp0' => 1,
                    ':qp1' => 100,
                ],
            ],
        ];
    }

    /**
     * @dataProvider updateProvider
     *
     * @param string $table
     * @param array $columns
     * @param array|string $condition
     * @param string $expectedSQL
     * @param array $expectedParams
     *
     * @throws Exception
     * @throws InvalidArgumentException
     * @throws InvalidConfigException
     * @throws NotSupportedException
     */
    public function testUpdate(
        string $table,
        array $columns,
        $condition,
        string $expectedSQL,
        array $expectedParams
    ): void {
        $actualParams = [];
        $db = $this->getConnection();
        $dml = new DMLCommand($db->getQueryBuilder(), $db->getQuoter(), $db->getSchema());
        $actualSQL = $dml->update($table, $columns, $condition, $actualParams);
        $this->assertSame($expectedSQL, $actualSQL);
        $this->assertSame($expectedParams, $actualParams);
    }

    public function upsertProvider(): array
    {
        $concreteData = [
            'regular values' => [
                3 => [
                    'WITH "EXCLUDED" ("email", "address", "status", "profile_id") AS (VALUES (CAST(:qp0 AS varchar), CAST(:qp1 AS text), CAST(:qp2 AS int2), CAST(:qp3 AS int4))), "upsert" AS (UPDATE "T_upsert" SET "address"="EXCLUDED"."address", "status"="EXCLUDED"."status", "profile_id"="EXCLUDED"."profile_id" FROM "EXCLUDED" WHERE (("T_upsert"."email"="EXCLUDED"."email")) RETURNING "T_upsert".*) INSERT INTO "T_upsert" ("email", "address", "status", "profile_id") SELECT "email", "address", "status", "profile_id" FROM "EXCLUDED" WHERE NOT EXISTS (SELECT 1 FROM "upsert" WHERE (("upsert"."email"="EXCLUDED"."email")))',
                    'INSERT INTO "T_upsert" ("email", "address", "status", "profile_id") VALUES (:qp0, :qp1, :qp2, :qp3) ON CONFLICT ("email") DO UPDATE SET "address"=EXCLUDED."address", "status"=EXCLUDED."status", "profile_id"=EXCLUDED."profile_id"',
                ],
                4 => [
                    [
                        ':qp0' => 'test@example.com',
                        ':qp1' => 'bar {{city}}',
                        ':qp2' => 1,
                        ':qp3' => null,
                    ],
                ],
            ],
            'regular values with update part' => [
                3 => [
                    'WITH "EXCLUDED" ("email", "address", "status", "profile_id") AS (VALUES (CAST(:qp0 AS varchar), CAST(:qp1 AS text), CAST(:qp2 AS int2), CAST(:qp3 AS int4))), "upsert" AS (UPDATE "T_upsert" SET "address"=:qp4, "status"=:qp5, "orders"=T_upsert.orders + 1 FROM "EXCLUDED" WHERE (("T_upsert"."email"="EXCLUDED"."email")) RETURNING "T_upsert".*) INSERT INTO "T_upsert" ("email", "address", "status", "profile_id") SELECT "email", "address", "status", "profile_id" FROM "EXCLUDED" WHERE NOT EXISTS (SELECT 1 FROM "upsert" WHERE (("upsert"."email"="EXCLUDED"."email")))',
                    'INSERT INTO "T_upsert" ("email", "address", "status", "profile_id") VALUES (:qp0, :qp1, :qp2, :qp3) ON CONFLICT ("email") DO UPDATE SET "address"=:qp4, "status"=:qp5, "orders"=T_upsert.orders + 1',
                ],
                4 => [
                    [
                        ':qp0' => 'test@example.com',
                        ':qp1' => 'bar {{city}}',
                        ':qp2' => 1,
                        ':qp3' => null,
                        ':qp4' => 'foo {{city}}',
                        ':qp5' => 2,
                    ],
                ],
            ],
            'regular values without update part' => [
                3 => [
                    'WITH "EXCLUDED" ("email", "address", "status", "profile_id") AS (VALUES (CAST(:qp0 AS varchar), CAST(:qp1 AS text), CAST(:qp2 AS int2), CAST(:qp3 AS int4))) INSERT INTO "T_upsert" ("email", "address", "status", "profile_id") SELECT "email", "address", "status", "profile_id" FROM "EXCLUDED" WHERE NOT EXISTS (SELECT 1 FROM "T_upsert" WHERE (("T_upsert"."email"="EXCLUDED"."email")))',
                    'INSERT INTO "T_upsert" ("email", "address", "status", "profile_id") VALUES (:qp0, :qp1, :qp2, :qp3) ON CONFLICT DO NOTHING',
                ],
                4 => [
                    [
                        ':qp0' => 'test@example.com',
                        ':qp1' => 'bar {{city}}',
                        ':qp2' => 1,
                        ':qp3' => null,
                    ],
                ],
            ],
            'query' => [
                3 => [
                    'WITH "EXCLUDED" ("email", "status") AS (SELECT "email", 2 AS "status" FROM "customer" WHERE "name"=:qp0 LIMIT 1), "upsert" AS (UPDATE "T_upsert" SET "status"="EXCLUDED"."status" FROM "EXCLUDED" WHERE (("T_upsert"."email"="EXCLUDED"."email")) RETURNING "T_upsert".*) INSERT INTO "T_upsert" ("email", "status") SELECT "email", "status" FROM "EXCLUDED" WHERE NOT EXISTS (SELECT 1 FROM "upsert" WHERE (("upsert"."email"="EXCLUDED"."email")))',
                    'INSERT INTO "T_upsert" ("email", "status") SELECT "email", 2 AS "status" FROM "customer" WHERE "name"=:qp0 LIMIT 1 ON CONFLICT ("email") DO UPDATE SET "status"=EXCLUDED."status"',
                ],
            ],
            'query with update part' => [
                3 => [
                    'WITH "EXCLUDED" ("email", "status") AS (SELECT "email", 2 AS "status" FROM "customer" WHERE "name"=:qp0 LIMIT 1), "upsert" AS (UPDATE "T_upsert" SET "address"=:qp1, "status"=:qp2, "orders"=T_upsert.orders + 1 FROM "EXCLUDED" WHERE (("T_upsert"."email"="EXCLUDED"."email")) RETURNING "T_upsert".*) INSERT INTO "T_upsert" ("email", "status") SELECT "email", "status" FROM "EXCLUDED" WHERE NOT EXISTS (SELECT 1 FROM "upsert" WHERE (("upsert"."email"="EXCLUDED"."email")))',
                    'INSERT INTO "T_upsert" ("email", "status") SELECT "email", 2 AS "status" FROM "customer" WHERE "name"=:qp0 LIMIT 1 ON CONFLICT ("email") DO UPDATE SET "address"=:qp1, "status"=:qp2, "orders"=T_upsert.orders + 1',
                ],
            ],
            'query without update part' => [
                3 => [
                    'WITH "EXCLUDED" ("email", "status") AS (SELECT "email", 2 AS "status" FROM "customer" WHERE "name"=:qp0 LIMIT 1) INSERT INTO "T_upsert" ("email", "status") SELECT "email", "status" FROM "EXCLUDED" WHERE NOT EXISTS (SELECT 1 FROM "T_upsert" WHERE (("T_upsert"."email"="EXCLUDED"."email")))',
                    'INSERT INTO "T_upsert" ("email", "status") SELECT "email", 2 AS "status" FROM "customer" WHERE "name"=:qp0 LIMIT 1 ON CONFLICT DO NOTHING',
                ],
            ],
            'values and expressions' => [
                3 => 'INSERT INTO {{%T_upsert}} ({{%T_upsert}}.[[email]], [[ts]]) VALUES (:qp0, now())',
            ],
            'values and expressions with update part' => [
                3 => 'INSERT INTO {{%T_upsert}} ({{%T_upsert}}.[[email]], [[ts]]) VALUES (:qp0, now())',
            ],
            'values and expressions without update part' => [
                3 => 'INSERT INTO {{%T_upsert}} ({{%T_upsert}}.[[email]], [[ts]]) VALUES (:qp0, now())',
            ],
            'query, values and expressions with update part' => [
                3 => [
                    'WITH "EXCLUDED" ("email", [[time]]) AS (SELECT :phEmail AS "email", now() AS [[time]]), "upsert" AS (UPDATE {{%T_upsert}} SET "ts"=:qp1, [[orders]]=T_upsert.orders + 1 FROM "EXCLUDED" WHERE (({{%T_upsert}}."email"="EXCLUDED"."email")) RETURNING {{%T_upsert}}.*) INSERT INTO {{%T_upsert}} ("email", [[time]]) SELECT "email", [[time]] FROM "EXCLUDED" WHERE NOT EXISTS (SELECT 1 FROM "upsert" WHERE (("upsert"."email"="EXCLUDED"."email")))',
                    'INSERT INTO {{%T_upsert}} ("email", [[time]]) SELECT :phEmail AS "email", now() AS [[time]] ON CONFLICT ("email") DO UPDATE SET "ts"=:qp1, [[orders]]=T_upsert.orders + 1',
                ],
            ],
            'query, values and expressions without update part' => [
                3 => [
                    'WITH "EXCLUDED" ("email", [[time]]) AS (SELECT :phEmail AS "email", now() AS [[time]]), "upsert" AS (UPDATE {{%T_upsert}} SET "ts"=:qp1, [[orders]]=T_upsert.orders + 1 FROM "EXCLUDED" WHERE (({{%T_upsert}}."email"="EXCLUDED"."email")) RETURNING {{%T_upsert}}.*) INSERT INTO {{%T_upsert}} ("email", [[time]]) SELECT "email", [[time]] FROM "EXCLUDED" WHERE NOT EXISTS (SELECT 1 FROM "upsert" WHERE (("upsert"."email"="EXCLUDED"."email")))',
                    'INSERT INTO {{%T_upsert}} ("email", [[time]]) SELECT :phEmail AS "email", now() AS [[time]] ON CONFLICT ("email") DO UPDATE SET "ts"=:qp1, [[orders]]=T_upsert.orders + 1',
                ],
            ],
            'no columns to update' => [
                3 => [
                    'WITH "EXCLUDED" ("a") AS (VALUES (CAST(:qp0 AS int2))) INSERT INTO "T_upsert_1" ("a") SELECT "a" FROM "EXCLUDED" WHERE NOT EXISTS (SELECT 1 FROM "T_upsert_1" WHERE (("T_upsert_1"."a"="EXCLUDED"."a")))',
                    'INSERT INTO "T_upsert_1" ("a") VALUES (:qp0) ON CONFLICT DO NOTHING',
                ],
            ],
        ];

        $newData = $this->upsertProviderTrait();

        foreach ($concreteData as $testName => $data) {
            $newData[$testName] = array_replace($newData[$testName], $data);
        }

        return $newData;
    }

    public function upsertProviderTrait(): array
    {
        return [
            'regular values' => [
                'T_upsert',
                [
                    'email' => 'test@example.com',
                    'address' => 'bar {{city}}',
                    'status' => 1,
                    'profile_id' => null,
                ],
                true,
                null,
                [
                    ':qp0' => 'test@example.com',
                    ':qp1' => 'bar {{city}}',
                    ':qp2' => 1,
                    ':qp3' => null,
                ],
            ],
            'regular values with update part' => [
                'T_upsert',
                [
                    'email' => 'test@example.com',
                    'address' => 'bar {{city}}',
                    'status' => 1,
                    'profile_id' => null,
                ],
                [
                    'address' => 'foo {{city}}',
                    'status' => 2,
                    'orders' => new Expression('T_upsert.orders + 1'),
                ],
                null,
                [
                    ':qp0' => 'test@example.com',
                    ':qp1' => 'bar {{city}}',
                    ':qp2' => 1,
                    ':qp3' => null,
                    ':qp4' => 'foo {{city}}',
                    ':qp5' => 2,
                ],
            ],
            'regular values without update part' => [
                'T_upsert',
                [
                    'email' => 'test@example.com',
                    'address' => 'bar {{city}}',
                    'status' => 1,
                    'profile_id' => null,
                ],
                false,
                null,
                [
                    ':qp0' => 'test@example.com',
                    ':qp1' => 'bar {{city}}',
                    ':qp2' => 1,
                    ':qp3' => null,
                ],
            ],
            'query' => [
                'T_upsert',
                (new Query($this->getConnection()))
                    ->select([
                        'email',
                        'status' => new Expression('2'),
                    ])
                    ->from('customer')
                    ->where(['name' => 'user1'])
                    ->limit(1),
                true,
                null,
                [
                    ':qp0' => 'user1',
                ],
            ],
            'query with update part' => [
                'T_upsert',
                (new Query($this->getConnection()))
                    ->select([
                        'email',
                        'status' => new Expression('2'),
                    ])
                    ->from('customer')
                    ->where(['name' => 'user1'])
                    ->limit(1),
                [
                    'address' => 'foo {{city}}',
                    'status' => 2,
                    'orders' => new Expression('T_upsert.orders + 1'),
                ],
                null,
                [
                    ':qp0' => 'user1',
                    ':qp1' => 'foo {{city}}',
                    ':qp2' => 2,
                ],
            ],
            'query without update part' => [
                'T_upsert',
                (new Query($this->getConnection()))
                    ->select([
                        'email',
                        'status' => new Expression('2'),
                    ])
                    ->from('customer')
                    ->where(['name' => 'user1'])
                    ->limit(1),
                false,
                null,
                [
                    ':qp0' => 'user1',
                ],
            ],
            'values and expressions' => [
                '{{%T_upsert}}',
                [
                    '{{%T_upsert}}.[[email]]' => 'dynamic@example.com',
                    '[[ts]]' => new Expression('now()'),
                ],
                true,
                null,
                [
                    ':qp0' => 'dynamic@example.com',
                ],
            ],
            'values and expressions with update part' => [
                '{{%T_upsert}}',
                [
                    '{{%T_upsert}}.[[email]]' => 'dynamic@example.com',
                    '[[ts]]' => new Expression('now()'),
                ],
                [
                    '[[orders]]' => new Expression('T_upsert.orders + 1'),
                ],
                null,
                [
                    ':qp0' => 'dynamic@example.com',
                ],
            ],
            'values and expressions without update part' => [
                '{{%T_upsert}}',
                [
                    '{{%T_upsert}}.[[email]]' => 'dynamic@example.com',
                    '[[ts]]' => new Expression('now()'),
                ],
                false,
                null,
                [
                    ':qp0' => 'dynamic@example.com',
                ],
            ],
            'query, values and expressions with update part' => [
                '{{%T_upsert}}',
                (new Query($this->getConnection()))
                    ->select([
                        'email' => new Expression(':phEmail', [':phEmail' => 'dynamic@example.com']),
                        '[[time]]' => new Expression('now()'),
                    ]),
                [
                    'ts' => 0,
                    '[[orders]]' => new Expression('T_upsert.orders + 1'),
                ],
                null,
                [
                    ':phEmail' => 'dynamic@example.com',
                    ':qp1' => 0,
                ],
            ],
            'query, values and expressions without update part' => [
                '{{%T_upsert}}',
                (new Query($this->getConnection()))
                    ->select([
                        'email' => new Expression(':phEmail', [':phEmail' => 'dynamic@example.com']),
                        '[[time]]' => new Expression('now()'),
                    ]),
                [
                    'ts' => 0,
                    '[[orders]]' => new Expression('T_upsert.orders + 1'),
                ],
                null,
                [
                    ':phEmail' => 'dynamic@example.com',
                    ':qp1' => 0,
                ],
            ],
            'no columns to update' => [
                'T_upsert_1',
                [
                    'a' => 1,
                ],
                false,
                null,
                [
                    ':qp0' => 1,
                ],
            ],
        ];
    }

    /**
     * @dataProvider upsertProvider
     *
     * @param string $table
     * @param array|ColumnSchema $insertColumns
     * @param array|bool|null $updateColumns
     * @param string|string[] $expectedSQL
     * @param array $expectedParams
     *
     * @throws NotSupportedException
     * @throws Exception
     */
    public function testUpsert(string $table, $insertColumns, $updateColumns, $expectedSQL, array $expectedParams): void
    {
        $actualParams = [];
        $db = $this->getConnection();
        $dml = new DMLCommand($db->getQueryBuilder(), $db->getQuoter(), $db->getSchema());
        $actualSQL = $dml->upsert($table, $insertColumns, $updateColumns, $actualParams);

        if (is_string($expectedSQL)) {
            $this->assertSame($expectedSQL, $actualSQL);
        } else {
            $this->assertContains($actualSQL, $expectedSQL);
        }

        if (ArrayHelper::isAssociative($expectedParams)) {
            $this->assertSame($expectedParams, $actualParams);
        } else {
            $this->assertIsOneOf($actualParams, $expectedParams);
        }
    }
}
