<?php

declare(strict_types=1);

namespace Yiisoft\Db\Pgsql\Tests;

use Closure;
use Yiisoft\Arrays\ArrayHelper;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Exception\Exception;
use Yiisoft\Db\Exception\IntegrityException;
use Yiisoft\Db\Exception\InvalidArgumentException;
use Yiisoft\Db\Exception\InvalidConfigException;
use Yiisoft\Db\Exception\NotSupportedException;
use Yiisoft\Db\Expression\ArrayExpression;
use Yiisoft\Db\Expression\Expression;
use Yiisoft\Db\Expression\JsonExpression;
use Yiisoft\Db\Pgsql\ColumnSchema;
use Yiisoft\Db\Pgsql\PDO\QueryBuilderPDOPgsql;
use Yiisoft\Db\Query\Query;
use Yiisoft\Db\Query\QueryBuilderInterface;
use Yiisoft\Db\TestSupport\TestQueryBuilderTrait;
use Yiisoft\Db\TestSupport\TraversableObject;

use function array_merge;
use function array_replace;
use function is_string;
use function version_compare;

/**
 * @group pgsql
 */
final class QueryBuilderTest extends TestCase
{
    use TestQueryBuilderTrait;

    /**
     * @return QueryBuilderInterface
     */
    protected function getQueryBuilder(ConnectionInterface $db): QueryBuilderInterface
    {
        return new QueryBuilderPDOPgsql($db);
    }

    public function testAlterColumn(): void
    {
        $db = $this->getConnection();
        $qb = $this->getQueryBuilder($db);

        $expected = 'ALTER TABLE "foo1" ALTER COLUMN "bar" TYPE varchar(255)';
        $sql = $qb->alterColumn('foo1', 'bar', 'varchar(255)');
        $this->assertEquals($expected, $sql);

        $expected = 'ALTER TABLE "foo1" ALTER COLUMN "bar" SET NOT null';
        $sql = $qb->alterColumn('foo1', 'bar', 'SET NOT null');
        $this->assertEquals($expected, $sql);

        $expected = 'ALTER TABLE "foo1" ALTER COLUMN "bar" drop default';
        $sql = $qb->alterColumn('foo1', 'bar', 'drop default');
        $this->assertEquals($expected, $sql);

        $expected = 'ALTER TABLE "foo1" ALTER COLUMN "bar" reset xyz';
        $sql = $qb->alterColumn('foo1', 'bar', 'reset xyz');
        $this->assertEquals($expected, $sql);

        $expected = 'ALTER TABLE "foo1" ALTER COLUMN "bar" TYPE varchar(255)';

        $sql = $qb->alterColumn('foo1', 'bar', $this->string(255));
        $this->assertEquals($expected, $sql);

        $expected = 'ALTER TABLE "foo1" ALTER COLUMN "bar" TYPE varchar(255) USING bar::varchar';

        $sql = $qb->alterColumn('foo1', 'bar', 'varchar(255) USING bar::varchar');
        $this->assertEquals($expected, $sql);

        $expected = 'ALTER TABLE "foo1" ALTER COLUMN "bar" TYPE varchar(255) using cast("bar" as varchar)';

        $sql = $qb->alterColumn('foo1', 'bar', 'varchar(255) using cast("bar" as varchar)');
        $this->assertEquals($expected, $sql);

        $expected = 'ALTER TABLE "foo1" ALTER COLUMN "bar" TYPE varchar(255), ALTER COLUMN "bar" SET NOT NULL';
        $sql = $qb->alterColumn('foo1', 'bar', $this->string(255)->notNull());
        $this->assertEquals($expected, $sql);

        $expected = 'ALTER TABLE "foo1" ALTER COLUMN "bar" TYPE varchar(255), ALTER COLUMN "bar" SET DEFAULT NULL, ALTER COLUMN "bar" DROP NOT NULL';
        $sql = $qb->alterColumn('foo1', 'bar', $this->string(255)->null());
        $this->assertEquals($expected, $sql);

        $expected = 'ALTER TABLE "foo1" ALTER COLUMN "bar" TYPE varchar(255), ALTER COLUMN "bar" SET DEFAULT \'xxx\', ALTER COLUMN "bar" DROP NOT NULL';
        $sql = $qb->alterColumn('foo1', 'bar', $this->string(255)->null()->defaultValue('xxx'));
        $this->assertEquals($expected, $sql);

        $expected = 'ALTER TABLE "foo1" ALTER COLUMN "bar" TYPE varchar(255), ADD CONSTRAINT foo1_bar_check CHECK (char_length(bar) > 5)';
        $sql = $qb->alterColumn('foo1', 'bar', $this->string(255)->check('char_length(bar) > 5'));
        $this->assertEquals($expected, $sql);

        $expected = 'ALTER TABLE "foo1" ALTER COLUMN "bar" TYPE varchar(255), ALTER COLUMN "bar" SET DEFAULT \'\'';
        $sql = $qb->alterColumn('foo1', 'bar', $this->string(255)->defaultValue(''));
        $this->assertEquals($expected, $sql);

        $expected = 'ALTER TABLE "foo1" ALTER COLUMN "bar" TYPE varchar(255), ALTER COLUMN "bar" SET DEFAULT \'AbCdE\'';
        $sql = $qb->alterColumn('foo1', 'bar', $this->string(255)->defaultValue('AbCdE'));
        $this->assertEquals($expected, $sql);

        $expected = 'ALTER TABLE "foo1" ALTER COLUMN "bar" TYPE timestamp(0), ALTER COLUMN "bar" SET DEFAULT CURRENT_TIMESTAMP';
        $sql = $qb->alterColumn('foo1', 'bar', $this->timestamp()->defaultExpression('CURRENT_TIMESTAMP'));
        $this->assertEquals($expected, $sql);

        $expected = 'ALTER TABLE "foo1" ALTER COLUMN "bar" TYPE varchar(30), ADD UNIQUE ("bar")';
        $sql = $qb->alterColumn('foo1', 'bar', $this->string(30)->unique());
        $this->assertEquals($expected, $sql);
    }

    public function testDropIndex(): void
    {
        $db = $this->getConnection();
        $qb = $this->getQueryBuilder($db);

        $expected = 'DROP INDEX "index"';
        $sql = $qb->dropIndex('index', '{{table}}');
        $this->assertEquals($expected, $sql);

        $expected = 'DROP INDEX "schema"."index"';
        $sql = $qb->dropIndex('index', '{{schema.table}}');
        $this->assertEquals($expected, $sql);

        $expected = 'DROP INDEX "schema"."index"';
        $sql = $qb->dropIndex('schema.index', '{{schema2.table}}');
        $this->assertEquals($expected, $sql);

        $expected = 'DROP INDEX "schema"."index"';
        $sql = $qb->dropIndex('index', '{{schema.%table}}');
        $this->assertEquals($expected, $sql);

        $expected = 'DROP INDEX {{%schema.index}}';
        $sql = $qb->dropIndex('index', '{{%schema.table}}');
        $this->assertEquals($expected, $sql);
    }

    /**
     * @dataProvider addDropForeignKeysProviderTrait
     *
     * @param string $sql
     * @param Closure $builder
     */
    public function testAddDropForeignKey(string $sql, Closure $builder): void
    {
        $db = $this->getConnection();
        $this->assertSame($db->getQuoter()->quoteSql($sql), $builder($this->getQueryBuilder($db)));
    }

    /**
     * @dataProvider addDropPrimaryKeysProviderTrait
     *
     * @param string $sql
     * @param Closure $builder
     */
    public function testAddDropPrimaryKey(string $sql, Closure $builder): void
    {
        $db = $this->getConnection();
        $this->assertSame($db->getQuoter()->quoteSql($sql), $builder($this->getQueryBuilder($db)));
    }

    /**
     * @dataProvider addDropUniquesProviderTrait
     *
     * @param string $sql
     * @param Closure $builder
     */
    public function testAddDropUnique(string $sql, Closure $builder): void
    {
        $db = $this->getConnection();
        $this->assertSame($db->getQuoter()->quoteSql($sql), $builder($this->getQueryBuilder($db)));
    }

    public function batchInsertProvider(): array
    {
        $data = $this->batchInsertProviderTrait();

        $data['escape-danger-chars']['expected'] = 'INSERT INTO "customer" ("address")'
            . " VALUES ('SQL-danger chars are escaped: ''); --')";

        $data['bool-false, bool2-null']['expected'] = 'INSERT INTO "type" ("bool_col", "bool_col2")'
            . ' VALUES (FALSE, NULL)';

        $data['bool-false, time-now()']['expected'] = 'INSERT INTO {{%type}} ({{%type}}.[[bool_col]], [[time]])'
            . ' VALUES (FALSE, now())';

        return $data;
    }

    /**
     * @dataProvider batchInsertProvider
     *
     * @param string $table
     * @param array $columns
     * @param array $value
     * @param string $expected
     *
     * @throws Exception
     * @throws InvalidArgumentException
     * @throws InvalidConfigException
     * @throws NotSupportedException
     */
    public function testBatchInsert(string $table, array $columns, array $value, string $expected): void
    {
        $db = $this->getConnection();
        $qb = $this->getQueryBuilder($db);
        $sql = $qb->batchInsert($table, $columns, $value);
        $this->assertEquals($expected, $sql);
    }

    public function buildConditionsProvider(): array
    {
        return array_merge($this->buildConditionsProviderTrait(), [
            /**
             * adding conditions for ILIKE i.e. case insensitive LIKE.
             *
             * {@see http://www.postgresql.org/docs/8.3/static/functions-matching.html#FUNCTIONS-LIKE}
             */

            /* empty values */
            [['ilike', 'name', []], '0=1', []],
            [['not ilike', 'name', []], '', []],
            [['or ilike', 'name', []], '0=1', []],
            [['or not ilike', 'name', []], '', []],

            /* simple ilike */
            [['ilike', 'name', 'heyho'], '"name" ILIKE :qp0', [':qp0' => '%heyho%']],
            [['not ilike', 'name', 'heyho'], '"name" NOT ILIKE :qp0', [':qp0' => '%heyho%']],
            [['or ilike', 'name', 'heyho'], '"name" ILIKE :qp0', [':qp0' => '%heyho%']],
            [['or not ilike', 'name', 'heyho'], '"name" NOT ILIKE :qp0', [':qp0' => '%heyho%']],

            /* ilike for many values */
            [
                ['ilike', 'name', ['heyho', 'abc']],
                '"name" ILIKE :qp0 AND "name" ILIKE :qp1',
                [':qp0' => '%heyho%', ':qp1' => '%abc%'],
            ],
            [
                ['not ilike', 'name', ['heyho', 'abc']],
                '"name" NOT ILIKE :qp0 AND "name" NOT ILIKE :qp1',
                [':qp0' => '%heyho%', ':qp1' => '%abc%'],
            ],
            [
                ['or ilike', 'name', ['heyho', 'abc']],
                '"name" ILIKE :qp0 OR "name" ILIKE :qp1', [':qp0' => '%heyho%', ':qp1' => '%abc%'],
            ],
            [
                ['or not ilike', 'name', ['heyho', 'abc']],
                '"name" NOT ILIKE :qp0 OR "name" NOT ILIKE :qp1',
                [':qp0' => '%heyho%', ':qp1' => '%abc%'],
            ],

            /* array condition corner cases */
            [['@>', 'id', new ArrayExpression([1])], '"id" @> ARRAY[:qp0]', [':qp0' => 1]],
            'scalar can not be converted to array #1' => [['@>', 'id', new ArrayExpression(1)], '"id" @> ARRAY[]', []],
            [
                'scalar can not be converted to array #2' => [
                    '@>', 'id', new ArrayExpression(false),
                ],
                '"id" @> ARRAY[]',
                [],
            ],
            [
                ['&&', 'price', new ArrayExpression([12, 14], 'float')],
                '"price" && ARRAY[:qp0, :qp1]::float[]',
                [':qp0' => 12, ':qp1' => 14],
            ],
            [
                ['@>', 'id', new ArrayExpression([2, 3])],
                '"id" @> ARRAY[:qp0, :qp1]',
                [':qp0' => 2, ':qp1' => 3],
            ],
            'array of arrays' => [
                ['@>', 'id', new ArrayExpression([[1,2], [3,4]], 'float', 2)],
                '"id" @> ARRAY[ARRAY[:qp0, :qp1]::float[], ARRAY[:qp2, :qp3]::float[]\\]::float[][]',
                [':qp0' => 1, ':qp1' => 2, ':qp2' => 3, ':qp3' => 4],
            ],
            [['@>', 'id', new ArrayExpression([])], '"id" @> ARRAY[]', []],
            'array can contain nulls' => [
                ['@>', 'id', new ArrayExpression([null])], '"id" @> ARRAY[:qp0]', [':qp0' => null],
            ],
            'traversable objects are supported' => [
                ['@>', 'id', new ArrayExpression(new TraversableObject([1, 2, 3]))],
                '[[id]] @> ARRAY[:qp0, :qp1, :qp2]',
                [':qp0' => 1, ':qp1' => 2, ':qp2' => 3],
            ],
            [['@>', 'time', new ArrayExpression([new Expression('now()')])], '[[time]] @> ARRAY[now()]', []],
            [
                [
                    '@>',
                    'id',
                    new ArrayExpression(
                        (new Query($this->getConnection()))
                            ->select('id')
                            ->from('users')
                            ->where(['active' => 1])
                    ),
                ],
                '[[id]] @> ARRAY(SELECT [[id]] FROM [[users]] WHERE [[active]]=:qp0)',
                [':qp0' => 1],
            ],
            [
                [
                    '@>',
                    'id',
                    new ArrayExpression(
                        [
                            (new Query($this->getConnection()))
                                ->select('id')
                                ->from('users')
                                ->where(['active' => 1]),
                        ],
                        'integer'
                    ),
                ],
                '[[id]] @> ARRAY[ARRAY(SELECT [[id]] FROM [[users]] WHERE [[active]]=:qp0)::integer[]]::integer[]',
                [':qp0' => 1],
            ],

            /* json conditions */
            [
                ['=', 'jsoncol', new JsonExpression(['lang' => 'uk', 'country' => 'UA'])],
                '[[jsoncol]] = :qp0',
                [':qp0' => '{"lang":"uk","country":"UA"}'],
            ],
            [
                ['=', 'jsoncol', new JsonExpression([false])],
                '[[jsoncol]] = :qp0', [':qp0' => '[false]'],
            ],
            [
                ['=', 'prices', new JsonExpression(['seeds' => 15, 'apples' => 25], 'jsonb')],
                '[[prices]] = :qp0::jsonb', [':qp0' => '{"seeds":15,"apples":25}'],
            ],
            'nested json' => [
                [
                    '=',
                    'data',
                    new JsonExpression(
                        [
                            'user' => ['login' => 'silverfire', 'password' => 'c4ny0ur34d17?'],
                            'props' => ['mood' => 'good'],
                        ]
                    ),
                ],
                '"data" = :qp0',
                [':qp0' => '{"user":{"login":"silverfire","password":"c4ny0ur34d17?"},"props":{"mood":"good"}}'],
            ],
            'null value' => [['=', 'jsoncol', new JsonExpression(null)], '"jsoncol" = :qp0', [':qp0' => 'null']],
            'null as array value' => [
                ['=', 'jsoncol', new JsonExpression([null])], '"jsoncol" = :qp0', [':qp0' => '[null]'],
            ],
            'null as object value' => [
                ['=', 'jsoncol', new JsonExpression(['nil' => null])], '"jsoncol" = :qp0', [':qp0' => '{"nil":null}'],
            ],
            'query' => [
                [
                    '=',
                    'jsoncol',
                    new JsonExpression(
                        (new Query($this->getConnection()))
                            ->select('params')
                            ->from('user')
                            ->where(['id' => 1])
                    ),
                ],
                '[[jsoncol]] = (SELECT [[params]] FROM [[user]] WHERE [[id]]=:qp0)',
                [':qp0' => 1],
            ],
            'query with type' => [
                [
                    '=',
                    'jsoncol',
                    new JsonExpression(
                        (new Query($this->getConnection()))
                            ->select('params')
                            ->from('user')
                            ->where(['id' => 1]),
                        'jsonb'
                    ),
                ],
                '[[jsoncol]] = (SELECT [[params]] FROM [[user]] WHERE [[id]]=:qp0)::jsonb',
                [':qp0' => 1],
            ],
            'array of json expressions' => [
                [
                    '=',
                    'colname',
                    new ArrayExpression(
                        [new JsonExpression(['a' => null, 'b' => 123, 'c' => [4, 5]]), new JsonExpression([true])]
                    ),
                ],
                '"colname" = ARRAY[:qp0, :qp1]',
                [':qp0' => '{"a":null,"b":123,"c":[4,5]}', ':qp1' => '[true]'],
            ],
            'Items in ArrayExpression of type json should be casted to Json' => [
                ['=', 'colname', new ArrayExpression([['a' => null, 'b' => 123, 'c' => [4, 5]], [true]], 'json')],
                '"colname" = ARRAY[:qp0, :qp1]::json[]',
                [':qp0' => '{"a":null,"b":123,"c":[4,5]}', ':qp1' => '[true]'],
            ],
            'Two dimension array of text' => [
                [
                    '=',
                    'colname',
                    new ArrayExpression([['text1', 'text2'], ['text3', 'text4'], [null, 'text5']], 'text', 2),
                ],
                '"colname" = ARRAY[ARRAY[:qp0, :qp1]::text[], ARRAY[:qp2, :qp3]::text[], ARRAY[:qp4, :qp5]::text[]]::text[][]',
                [
                    ':qp0' => 'text1',
                    ':qp1' => 'text2',
                    ':qp2' => 'text3',
                    ':qp3' => 'text4',
                    ':qp4' => null,
                    ':qp5' => 'text5',
                ],
            ],
            'Three dimension array of booleans' => [
                [
                    '=',
                    'colname',
                    new ArrayExpression([[[true], [false, null]], [[false], [true], [false]], [['t', 'f']]], 'bool', 3),
                ],
                '"colname" = ARRAY[ARRAY[ARRAY[:qp0]::bool[], ARRAY[:qp1, :qp2]::bool[]]::bool[][], ARRAY[ARRAY[:qp3]::bool[], ARRAY[:qp4]::bool[], ARRAY[:qp5]::bool[]]::bool[][], ARRAY[ARRAY[:qp6, :qp7]::bool[]]::bool[][]]::bool[][][]',
                [
                    ':qp0' => true,
                    ':qp1' => false,
                    ':qp2' => null,
                    ':qp3' => false,
                    ':qp4' => true,
                    ':qp5' => false,
                    ':qp6' => 't',
                    ':qp7' => 'f',
                ],
            ],

            /* Checks to verity that operators work correctly */
            [['@>', 'id', new ArrayExpression([1])], '"id" @> ARRAY[:qp0]', [':qp0' => 1]],
            [['<@', 'id', new ArrayExpression([1])], '"id" <@ ARRAY[:qp0]', [':qp0' => 1]],
            [['=', 'id',  new ArrayExpression([1])], '"id" = ARRAY[:qp0]', [':qp0' => 1]],
            [['<>', 'id', new ArrayExpression([1])], '"id" <> ARRAY[:qp0]', [':qp0' => 1]],
            [['>', 'id',  new ArrayExpression([1])], '"id" > ARRAY[:qp0]', [':qp0' => 1]],
            [['<', 'id',  new ArrayExpression([1])], '"id" < ARRAY[:qp0]', [':qp0' => 1]],
            [['>=', 'id', new ArrayExpression([1])], '"id" >= ARRAY[:qp0]', [':qp0' => 1]],
            [['<=', 'id', new ArrayExpression([1])], '"id" <= ARRAY[:qp0]', [':qp0' => 1]],
            [['&&', 'id', new ArrayExpression([1])], '"id" && ARRAY[:qp0]', [':qp0' => 1]],
        ]);
    }

    /**
     * @dataProvider buildConditionsProvider
     *
     * @param array|ExpressionInterface $condition
     * @param string $expected
     * @param array $expectedParams
     *
     * @throws Exception
     * @throws InvalidArgumentException
     * @throws InvalidConfigException
     * @throws NotSupportedException
     */
    public function testBuildCondition($condition, string $expected, array $expectedParams): void
    {
        $db = $this->getConnection();
        $query = (new Query($db))->where($condition);
        [$sql, $params] = $this->getQueryBuilder($db)->build($query);
        $this->assertEquals('SELECT *' . (empty($expected) ? '' : ' WHERE ' . $this->replaceQuotes($expected)), $sql);
        $this->assertEquals($expectedParams, $params);
    }

    /**
     * @dataProvider buildFilterConditionProviderTrait
     *
     * @param array $condition
     * @param string $expected
     * @param array $expectedParams
     *
     * @throws Exception
     * @throws InvalidArgumentException
     * @throws InvalidConfigException
     * @throws NotSupportedException
     */
    public function testBuildFilterCondition(array $condition, string $expected, array $expectedParams): void
    {
        $db = $this->getConnection();
        $query = (new Query($db))->filterWhere($condition);
        [$sql, $params] = $this->getQueryBuilder($db)->build($query);
        $this->assertEquals('SELECT *' . (empty($expected) ? '' : ' WHERE ' . $this->replaceQuotes($expected)), $sql);
        $this->assertEquals($expectedParams, $params);
    }

    /**
     * @dataProvider buildFromDataProviderTrait
     *
     * @param string $table
     * @param string $expected
     *
     * @throws Exception
     */
    public function testBuildFrom(string $table, string $expected): void
    {
        $db = $this->getConnection();
        $params = [];
        $sql = $this->getQueryBuilder($db)->buildFrom([$table], $params);
        $this->assertEquals('FROM ' . $this->replaceQuotes($expected), $sql);
    }

    /**
     * @dataProvider buildLikeConditionsProviderTrait
     *
     * @param array|object $condition
     * @param string $expected
     * @param array $expectedParams
     *
     * @throws Exception
     * @throws InvalidArgumentException
     * @throws InvalidConfigException
     * @throws NotSupportedException
     */
    public function testBuildLikeCondition($condition, string $expected, array $expectedParams): void
    {
        $db = $this->getConnection();
        $query = (new Query($db))->where($condition);
        [$sql, $params] = $this->getQueryBuilder($db)->build($query);
        $this->assertEquals('SELECT *' . (empty($expected) ? '' : ' WHERE ' . $this->replaceQuotes($expected)), $sql);
        $this->assertEquals($expectedParams, $params);
    }

    /**
     * @dataProvider buildExistsParamsProviderTrait
     *
     * @param string $cond
     * @param string $expectedQuerySql
     *
     * @throws Exception
     * @throws InvalidArgumentException
     * @throws InvalidConfigException
     * @throws NotSupportedException
     */
    public function testBuildWhereExists(string $cond, string $expectedQuerySql): void
    {
        $db = $this->getConnection();
        $expectedQueryParams = [];
        $subQuery = new Query($db);
        $subQuery->select('1')->from('Website w');
        $query = new Query($db);
        $query->select('id')->from('TotalExample t')->where([$cond, $subQuery]);
        [$actualQuerySql, $actualQueryParams] = $this->getQueryBuilder($db)->build($query);
        $this->assertEquals($expectedQuerySql, $actualQuerySql);
        $this->assertEquals($expectedQueryParams, $actualQueryParams);
    }

    public function createDropIndexesProvider(): array
    {
        $result = $this->createDropIndexesProviderTrait();
        $result['drop'][0] = 'DROP INDEX [[CN_constraints_2_single]]';
        return $result;
    }

    /**
     * @dataProvider createDropIndexesProvider
     *
     * @param string $sql
     */
    public function testCreateDropIndex(string $sql, Closure $builder): void
    {
        $db = $this->getConnection();
        $this->assertSame($db->getQuoter()->quoteSql($sql), $builder($this->getQueryBuilder($db)));
    }

    /**
     * @dataProvider deleteProviderTrait
     *
     * @param string $table
     * @param array|string $condition
     * @param string $expectedSQL
     * @param array $expectedParams
     *
     * @throws Exception
     * @throws InvalidArgumentException
     * @throws InvalidConfigException
     * @throws NotSupportedException
     */
    public function testDelete(string $table, $condition, string $expectedSQL, array $expectedParams): void
    {
        $db = $this->getConnection();
        $actualParams = [];
        $actualSQL = $this->getQueryBuilder($db)->delete($table, $condition, $actualParams);
        $this->assertSame($expectedSQL, $actualSQL);
        $this->assertSame($expectedParams, $actualParams);
    }

    public function testCheckIntegrity(): void
    {
        $db = $this->getConnection();
        $db->createCommand()->checkIntegrity('public', 'item', false)->execute();
        $sql = 'INSERT INTO {{item}}([[name]], [[category_id]]) VALUES (\'invalid\', 99999)';
        $command = $db->createCommand($sql);
        $command->execute();
        $db->createCommand()->checkIntegrity('public', 'item', true)->execute();
        $this->expectException(IntegrityException::class);
        $command->execute();
    }
}
