<?php


namespace Silktide\Reposition\Sql\Test\QueryInterpreter\Type;
use Silktide\Reposition\Sql\QueryInterpreter\Type\UpdateInterpreter;

/**
 * UpdateInterpreterTest
 */
class UpdateInterpreterTest extends \PHPUnit_Framework_TestCase
{

    protected $collection = "update_table";

    /**
     * @dataProvider sequenceProvider
     *
     * @param array $tokens
     * @param $expectedSql
     */
    public function testInterpretation(array $tokens, $expectedSql, $expectedValues = [])
    {
        $sequencer = $this->createMockTokenSequencer($tokens);

        $interpreter = new UpdateInterpreter();
        $interpreter->setIdentifiedDelimiter("`");

        $this->assertEquals($expectedSql, $interpreter->interpretQuery($sequencer));

        $values = $interpreter->getValues();
        foreach ($expectedValues as $key => $expected) {
            $this->assertArrayHasKey($key, $values);
            $this->assertEquals($expected, $values[$key]);
        }
    }

    protected function createMockTokenSequencer(array $tokens)
    {
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

        $metadata = \Mockery::mock("Silktide\\Reposition\\Metadata\\EntityMetadata");
        $metadata->shouldReceive("getCollection")->andReturn($this->collection);

        $tokenSequencer = \Mockery::mock("Silktide\\Reposition\\QueryBuilder\\TokenSequencerInterface");
        $tokenSequencer->shouldReceive("getNextToken")->andReturnValues($sequence);
        $tokenSequencer->shouldReceive("getEntityMetadata")->andReturn($metadata);

        return $tokenSequencer;
    }

    public function sequenceProvider()
    {
        return [
            [ #0 update all entities with a static value
                [
                    ["reference", "field", "field1", ""],
                    ["value", "operator", "="],
                    ["value", "string", "value"]
                ],
                "UPDATE `{$this->collection}` SET `field1` = :string_0"
            ],
            [ #1 update single entity with a value
                [
                    ["reference", "field", "field1", ""],
                    ["value", "operator", "="],
                    ["value", "string", "value"],
                    ["token", "where"],
                    ["reference", "field", "id", ""],
                    ["value", "operator", "="],
                    ["value", "int", "1"],
                ],
                "UPDATE `{$this->collection}` SET `field1` = :string_0 WHERE `id` = :int_1"
            ],
            [ #2 update 2 fields on all entities with a relative value
                [
                    ["reference", "field", "field1", ""],
                    ["value", "operator", "="],
                    ["reference", "field", "field1", ""],
                    ["value", "operator", "+"],
                    ["value", "int", "1"],
                    ["reference", "field", "field2", ""],
                    ["value", "operator", "="],
                    ["reference", "field", "field2", ""],
                    ["value", "operator", "+"],
                    ["value", "int", "1"],
                ],
                "UPDATE `{$this->collection}` SET `field1` = `field1` + :int_0, `field2` = `field2` + :int_1"
            ],
            [ #3 update all entities with a function
                [
                    ["reference", "field", "field1", ""],
                    ["value", "operator", "="],
                    ["value", "function", "maximum"],
                    ["token", "open"],
                    ["reference", "field", "field2", ""],
                    ["token", "close"]
                ],
                "UPDATE `{$this->collection}` SET `field1` = MAX(`field2`)"
            ],
            [ #4 update one entity with array data
                [
                    ["reference", "field", "field1", ""],
                    ["value", "operator", "="],
                    ["value", "array", ["one", "two", "three"]],
                    ["token", "where"],
                    ["reference", "field", "id", ""],
                    ["value", "operator", "="],
                    ["value", "int", "1"]
                ],
                "UPDATE `{$this->collection}` SET `field1` = :array_0 WHERE `id` = :int_1",
                ["array_0" => '["one","two","three"]']
            ]
        ];
    }

}
