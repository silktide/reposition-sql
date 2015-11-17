<?php


namespace Silktide\Reposition\Sql\Test\QueryInterpreter\Type;

use Silktide\Reposition\QueryBuilder\TokenSequencerInterface;
use Silktide\Reposition\Sql\QueryInterpreter\Type\SaveInterpreter;

/**
 * SaveInterpreterTest
 */
class SaveInterpreterTest extends \PHPUnit_Framework_TestCase
{

    protected $collection = "save_table";

    /**
     * @dataProvider sequenceProvider
     *
     * @param array $tokens
     * @param $expectedSql
     */
    public function testInterpretation(array $entities, $fields, $expectedSql)
    {
        /** @var TokenSequencerInterface $tokenSequencer */
        $sequence = [];
        foreach ($entities as $entity) {
            $token = \Mockery::mock("Silktide\\Reposition\\QueryBuilder\\QueryToken\\Entity");
            $token->shouldReceive("getType")->andReturn("entity");
            $token->shouldReceive("getEntity")->andReturn($entity);
            $sequence[] = $token;
        }
        $sequence[] = false;

        $metadata = \Mockery::mock("Silktide\\Reposition\\Metadata\\EntityMetadata");
        $metadata->shouldReceive("getCollection")->andReturn($this->collection);
        $metadata->shouldReceive("getPrimaryKey")->andReturn("id");
        $metadata->shouldReceive("getFieldNames")->andReturn($fields);

        $tokenSequencer = \Mockery::mock("Silktide\\Reposition\\QueryBuilder\\TokenSequencerInterface");
        $tokenSequencer->shouldReceive("getNextToken")->andReturnValues($sequence);
        $tokenSequencer->shouldReceive("getEntityMetadata")->andReturn($metadata);

        $interpreter = new SaveInterpreter();
        $interpreter->setIdentifiedDelimiter("`");

        $this->assertEquals($expectedSql, $interpreter->interpretQuery($tokenSequencer));
    }

    protected function createMockTokenSequencer(array $tokens)
    {

    }

    public function sequenceProvider()
    {
        return [
            [ #0 simple insert
                [
                    [
                        "field1" => "value"
                    ]
                ],
                [
                    "field1"
                ],
                "INSERT INTO `{$this->collection}` (`field1`) VALUES (:value_0)"
            ],
            [ #1 insert with additional rows
                [
                    [
                        "field1" => "value",
                        "field2" => true,
                        "field3" => 60
                    ]
                ],
                [
                    "field1",
                    "field2",
                    "field3"
                ],
                "INSERT INTO `{$this->collection}` (`field1`, `field2`, `field3`) VALUES (:value_0, TRUE, :value_1)"
            ],
            [ #2 insert multiple rows
                [
                    [
                        "field1" => "value",
                        "field2" => "value",
                        "field3" => "value"
                    ],
                    [
                        "field1" => "value",
                        "field2" => "value",
                        "field3" => "value"
                    ],
                    [
                        "field1" => "value",
                        "field2" => "value",
                        "field3" => "value"
                    ]
                ],
                [
                    "field1",
                    "field2",
                    "field3"
                ],
                "INSERT INTO `{$this->collection}` (`field1`, `field2`, `field3`) VALUES (:value_0, :value_1, :value_2), " .
                "(:value_3, :value_4, :value_5), (:value_6, :value_7, :value_8)"
            ],
            [ #3 insert with missing fields
                [
                    [
                        "field1" => "value",
                        "field3" => 60
                    ]
                ],
                [
                    "field1",
                    "field2",
                    "field3"
                ],
                "INSERT INTO `{$this->collection}` (`field1`, `field2`, `field3`) VALUES (:value_0, NULL, :value_1)"
            ],
            [ #4 simple update
                [
                    [
                        "id" => 4,
                        "field1" => "value"
                    ]
                ],
                [
                    "id",
                    "field1"
                ],
                "UPDATE `{$this->collection}` SET `field1` = :value_0 WHERE `id` = :searchId"
            ],
            [ #5 update with several fields
                [
                    [
                        "id" => 4,
                        "field1" => "value",
                        "field2" => false,
                        "field3" => 60
                    ]
                ],
                [
                    "id",
                    "field1",
                    "field2",
                    "field3"
                ],
                "UPDATE `{$this->collection}` SET `field1` = :value_0, `field2` = FALSE, `field3` = :value_1 WHERE `id` = :searchId"
            ],
            [ #5 update with missing fields
                [
                    [
                        "id" => 4,
                        "field1" => "value",
                        "field3" => 60
                    ]
                ],
                [
                    "id",
                    "field1",
                    "field2",
                    "field3"
                ],
                "UPDATE `{$this->collection}` SET `field1` = :value_0, `field3` = :value_1 WHERE `id` = :searchId"
            ]
        ];
    }

}
