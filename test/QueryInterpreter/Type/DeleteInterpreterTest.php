<?php

namespace Silktide\Reposition\Sql\Test\QueryInterpreter\Type;

use Silktide\Reposition\Sql\QueryInterpreter\Type\DeleteInterpreter;
use Silktide\Reposition\QueryBuilder\TokenSequencerInterface;

/**
 * DeleteInterpreterTest
 */
class DeleteInterpreterTest extends \PHPUnit_Framework_TestCase
{

    protected $collection = "test_table";

    /**
     * @dataProvider sequenceProvider
     *
     * @param array $tokens
     * @param $expectedSql
     */
    public function testInterpretation(array $tokens, $expectedSql)
    {
        /** @var TokenSequencerInterface $tokenSequencer */
        $tokenSequencer = $this->createMockTokenSequencer($tokens);

        $interpreter = new DeleteInterpreter();
        $interpreter->setIdentifiedDelimiter("`");

        $this->assertEquals($expectedSql, $interpreter->interpretQuery($tokenSequencer));
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
        $defaultSql = "DELETE FROM `{$this->collection}`";

        return [
            [ // #0 simple full table delete
                [],
                $defaultSql
            ],
            [ // #1 simple delete by ID
                [
                    ["token", "where"],
                    ["reference", "field", "id", ""],
                    ["value", "operator", "="],
                    ["value", "int", "value"]
                ],
                $defaultSql . " WHERE `id` = :int_0"
            ],
            [ // #2 limited delete in order
                [
                    ["token", "sort"],
                    ["reference", "field", "timestamp", ""],
                    ["value", "sort-direction", -1],
                    ["reference", "field", "active", ""],
                    ["token", "limit"],
                    ["value", "int", 5]
                ],
                $defaultSql . " ORDER BY `timestamp` DESC, `active` LIMIT :int_0"
            ]
        ];
    }

}
