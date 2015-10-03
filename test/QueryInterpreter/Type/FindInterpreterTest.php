<?php

namespace Silktide\Reposition\Sql\Test\QueryInterpreter\Type;

use Silktide\Reposition\Sql\QueryInterpreter\Type\FindInterpreter;
use Silktide\Reposition\QueryBuilder\TokenSequencerInterface;
use Silktide\Reposition\Metadata\EntityMetadataProviderInterface;

class FindInterpreterTest extends \PHPUnit_Framework_TestCase {


    /**
     * @dataProvider tokenSequenceProvider
     *
     * @param array $tokens
     * @param $expectedSql
     */
    public function testInterpretation(array $tokens, $expectedSql)
    {
        $entityClass = "ClassName";
        $entityCollection = "table_name";
        $entityFieldNames = ["string_field", "int_field", "bool_field"];

        $metadata = \Mockery::mock("Silktide\\Reposition\\Metadata\\EntityMetadata");
        $metadata->shouldReceive("getCollection")->andReturn($entityCollection);
        $metadata->shouldReceive("getFieldNames")->andReturn($entityFieldNames);

        $metadataProvider = \Mockery::mock("Silktide\\Reposition\\Metadata\\EntityMetadataProviderInterface");
        $metadataProvider->shouldReceive("getEntityMetadata")->andReturn($metadata);

        $sequence = [];
        foreach ($tokens as $tokenArgs) {
            $token = \Mockery::mock("Silktide\\Reposition\\QueryBuilder\\QueryToken\\" . ucfirst($tokenArgs[0]));
            if (isset($tokenArgs[1])) {
                $token->shouldReceive("getType")->andReturn($tokenArgs[1]);
            }
            if (isset($tokenArgs[2])) {
                $token->shouldReceive("getValue")->andReturn($tokenArgs[2]);
            }
            if (isset($tokenArgs[3])) {
                $token->shouldReceive("getAlias")->andReturn($tokenArgs[3]);
            }
            $sequence[] = $token;
        }
        $sequence[] = false;


        $tokenSequencer = \Mockery::mock("Silktide\\Reposition\\QueryBuilder\\TokenSequencerInterface");
        $tokenSequencer->shouldReceive("getNextToken")->andReturnValues($sequence);
        $tokenSequencer->shouldReceive("getEntityName")->andReturn($entityClass);
        $tokenSequencer->shouldReceive("getIncludes")->andReturn([]);

        /** @var EntityMetadataProviderInterface  $metadataProvider */
        $interpreter = new FindInterpreter();
        $interpreter->setEntityMetadataProvider($metadataProvider);

        /** @var TokenSequencerInterface $tokenSequencer */
        $this->assertEquals($expectedSql, $interpreter->interpretQuery($tokenSequencer));
    }

    public function tokenSequenceProvider()
    {
        $defaultSql = "SELECT " .
                      "`table_name`.`string_field` AS `table_name__string_field`, " .
                      "`table_name`.`int_field` AS `table_name__int_field`, " .
                      "`table_name`.`bool_field` AS `table_name__bool_field` " .
                      "FROM `table_name`";

        return [
            [ // no tokens, the simplest select
                [],
                $defaultSql
            ],
            [ // simple where clause
                [
                    ["token", "where"],
                    ["reference", "field", "other_field", ""],
                    ["value", "operator", "="],
                    ["value", "string", "value"]
                ],
                $defaultSql . " WHERE `other_field` = :string_0"
            ],
            [ // selecting aggregate functions, with expressions as arguments and multiple functions of the same name
                [
                    ["value", "function", "sum"],
                    ["token", "open"],
                    ["reference", "field", "field1", ""],
                    ["value", "operator", "+"],
                    ["reference", "field", "field2", ""],
                    ["token", "close"],
                    ["value", "function", "sum"],
                    ["token", "open"],
                    ["reference", "field", "field3", ""],
                    ["token", "close"]
                ],
                "SELECT SUM(`field1` + `field2`) AS `sum`, SUM(`field3`) AS `sum_1` FROM `table_name`"
            ],
            [ // selecting aggregate functions with arbitrary nested closures and functions
                [
                    ["value", "function", "avg"],
                    ["token", "open"],
                    ["reference", "field", "field1", ""],
                    ["value", "operator", "+"],
                    ["token", "open"],
                    ["reference", "field", "field2", ""],
                    ["value", "operator", "-"],
                    ["value", "function", "unix_timestamp"],
                    ["token", "open"],
                    ["token", "close"],
                    ["token", "close"],
                    ["token", "close"]
                ],
                "SELECT AVG(`field1` + ( `field2` - UNIX_TIMESTAMP() )) AS `avg` FROM `table_name`"
            ],
            [ // selecting aggregate functions with arbitrary nested closures and functions, where the closure is the first operand
                [
                    ["value", "function", "avg"],
                    ["token", "open"],
                    ["token", "open"],
                    ["reference", "field", "field2", ""],
                    ["value", "operator", "-"],
                    ["value", "function", "unix_timestamp"],
                    ["token", "open"],
                    ["token", "close"],
                    ["token", "close"],
                    ["value", "operator", "+"],
                    ["reference", "field", "field1", ""],
                    ["token", "close"]
                ],
                "SELECT AVG(( `field2` - UNIX_TIMESTAMP() ) + `field1`) AS `avg` FROM `table_name`"
            ],
            [ // using functions with multiple arguments
                [
                    ["token", "where"],
                    ["reference", "field", "other_field", ""],
                    ["value", "operator", ">"],
                    ["value", "function", "ifnull"],
                    ["token", "open"],
                    ["reference", "field", "int_field", ""],
                    ["value", "int", 0],
                    ["token", "close"],
                ],
                $defaultSql . " WHERE `other_field` > IFNULL(`int_field`, :int_0)"
            ]
        ];
    }

}
 