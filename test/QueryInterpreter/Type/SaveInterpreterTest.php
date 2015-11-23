<?php


namespace Silktide\Reposition\Sql\Test\QueryInterpreter\Type;

use Silktide\Reposition\Metadata\EntityMetadata;
use Silktide\Reposition\QueryBuilder\TokenSequencerInterface;
use Silktide\Reposition\Sql\QueryInterpreter\Type\SaveInterpreter;

/**
 * SaveInterpreterTest
 */
class SaveInterpreterTest extends \PHPUnit_Framework_TestCase
{

    protected $collection = "save_table";

    protected $childId = 6;

    protected $childTheirField = 22;

    /**
     * @dataProvider sequenceProvider
     *
     * @param array $entities
     * @param $fields
     * @param $expectedSql
     * @param array $expectedValues
     * @throws \Silktide\Reposition\Exception\InterpretationException
     */
    public function testInterpretation(array $entities, $fields, $expectedSql, $expectedValues = [])
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
        $metadata->shouldReceive("getPrimaryKeyMetadata")->andReturn([EntityMetadata::METADATA_FIELD_AUTO_INCREMENTING => true]);
        $metadata->shouldReceive("getFieldNames")->andReturn($fields);
        $metadata->shouldReceive("getRelationships")->andReturn([]);

        $tokenSequencer = \Mockery::mock("Silktide\\Reposition\\QueryBuilder\\TokenSequencerInterface");
        $tokenSequencer->shouldReceive("getNextToken")->andReturnValues($sequence);
        $tokenSequencer->shouldReceive("getEntityMetadata")->andReturn($metadata);
        $tokenSequencer->shouldReceive("getOptions")->andReturn([]);

        $interpreter = new SaveInterpreter();
        $interpreter->setIdentifiedDelimiter("`");

        if (!empty($expectedSql)) {
            $this->assertEquals($expectedSql, $interpreter->interpretQuery($tokenSequencer));
        }
        if (!empty($expectedValues)) {
            $values = $interpreter->getValues();
            foreach ($expectedValues as $placeholder => $expected) {
                $this->assertArrayHasKey($placeholder, $values);
                $this->assertEquals($expected, $values[$placeholder]);
            }
        }
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
            ],
            [ #6 inserting array data
                [
                    [
                        "field1" => ["one", "two", "three"]
                    ]
                ],
                ["field1"],
                "INSERT INTO `{$this->collection}` (`field1`) VALUES (:array_0)",
                ["array_0" => '["one","two","three"]']
            ],
            [ #7 updating array data
                [
                    [
                        "id" => 4,
                        "field1" => ["one", "two", "three"]
                    ]
                ],
                ["id", "field1"],
                "UPDATE `{$this->collection}` SET `field1` = :array_0 WHERE `id` = :searchId",
                ["array_0" => '["one","two","three"]']
            ]
        ];
    }

    /**
     * @dataProvider relationshipProvider
     *
     * @param array $entities
     * @param $fields
     * @param $expectedSql
     * @param array $expectedValues
     * @throws \Silktide\Reposition\Exception\InterpretationException
     */
    public function testSavingRelationships(array $entities, $relationships, $expectedSql, $expectedValues = [])
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
        $metadata->shouldReceive("getPrimaryKeyMetadata")->andReturn([EntityMetadata::METADATA_FIELD_AUTO_INCREMENTING => true]);
        $metadata->shouldReceive("getFieldNames")->andReturn(["field_1"]);
        $metadata->shouldReceive("getRelationships")->andReturn($relationships);

        $tokenSequencer = \Mockery::mock("Silktide\\Reposition\\QueryBuilder\\TokenSequencerInterface");
        $tokenSequencer->shouldReceive("getNextToken")->andReturnValues($sequence);
        $tokenSequencer->shouldReceive("getEntityMetadata")->andReturn($metadata);
        $tokenSequencer->shouldReceive("getOptions")->andReturn([]);

        $interpreter = new SaveInterpreter();
        $interpreter->setIdentifiedDelimiter("`");

        if (!empty($expectedSql)) {
            $this->assertEquals($expectedSql, $interpreter->interpretQuery($tokenSequencer));
        }
        if (!empty($expectedValues)) {
            $values = $interpreter->getValues();
            foreach ($expectedValues as $placeholder => $expected) {
                $this->assertArrayHasKey($placeholder, $values);
                $this->assertEquals($expected, $values[$placeholder]);
            }
        }
    }

    public function relationshipProvider()
    {
        $child = new Child();
        $child->setId($this->childId);
        $child->setTheirField($this->childTheirField);

        $insertEntity = new Entity();
        $insertEntity->setChild($child);


        $updateEntity = new Entity();
        $updateEntity->setId(9);
        $updateEntity->setChild($child);

        return [
            [ #0 one to one, standard setup
                [$insertEntity],
                [
                    "Silktide\\Reposition\\Sql\\Test\\QueryInterpreter\\Type\\Child" => [
                        EntityMetadata::METADATA_RELATIONSHIP_TYPE => EntityMetadata::RELATIONSHIP_TYPE_ONE_TO_ONE,
                        EntityMetadata::METADATA_RELATIONSHIP_PROPERTY => "child",
                        EntityMetadata::METADATA_RELATIONSHIP_OUR_FIELD => "child_id",
                        EntityMetadata::METADATA_RELATIONSHIP_THEIR_FIELD => null
                    ]
                ],
                "INSERT INTO `{$this->collection}` (`field_1`, `child_id`) VALUES (:value_0, :value_1)",
                ["value_1" => $this->childId]
            ],
            [ #1 one to one, empty "our" field
                [$insertEntity],
                [
                    [
                        EntityMetadata::METADATA_RELATIONSHIP_TYPE => EntityMetadata::RELATIONSHIP_TYPE_ONE_TO_ONE,
                        EntityMetadata::METADATA_RELATIONSHIP_PROPERTY => "child",
                        EntityMetadata::METADATA_RELATIONSHIP_OUR_FIELD => "",
                        EntityMetadata::METADATA_RELATIONSHIP_THEIR_FIELD => null
                    ]
                ],
                "INSERT INTO `{$this->collection}` (`field_1`) VALUES (:value_0)"
            ],
            [ #2 not one to one
                [$insertEntity],
                [
                    [
                        EntityMetadata::METADATA_RELATIONSHIP_TYPE => EntityMetadata::RELATIONSHIP_TYPE_ONE_TO_MANY,
                        EntityMetadata::METADATA_RELATIONSHIP_PROPERTY => "child",
                        EntityMetadata::METADATA_RELATIONSHIP_THEIR_FIELD => "whatever"
                    ]
                ],
                "INSERT INTO `{$this->collection}` (`field_1`) VALUES (:value_0)"
            ],
            [ #3 one to one, including their field
                [$insertEntity],
                [
                    [
                        EntityMetadata::METADATA_RELATIONSHIP_TYPE => EntityMetadata::RELATIONSHIP_TYPE_ONE_TO_ONE,
                        EntityMetadata::METADATA_RELATIONSHIP_PROPERTY => "child",
                        EntityMetadata::METADATA_RELATIONSHIP_OUR_FIELD => "child_id",
                        EntityMetadata::METADATA_RELATIONSHIP_THEIR_FIELD => "their_field"
                    ]
                ],
                "INSERT INTO `{$this->collection}` (`field_1`, `child_id`) VALUES (:value_0, :value_1)",
                ["value_1" => $this->childTheirField]
            ],
            [ #4 update one to one, standard setup
                [$updateEntity],
                [
                    [
                        EntityMetadata::METADATA_RELATIONSHIP_TYPE => EntityMetadata::RELATIONSHIP_TYPE_ONE_TO_ONE,
                        EntityMetadata::METADATA_RELATIONSHIP_PROPERTY => "child",
                        EntityMetadata::METADATA_RELATIONSHIP_OUR_FIELD => "child_id",
                        EntityMetadata::METADATA_RELATIONSHIP_THEIR_FIELD => null
                    ]
                ],
                "UPDATE `{$this->collection}` SET `field_1` = :value_0, `child_id` = :value_1 WHERE `id` = :searchId",
                ["value_1" => $this->childId]
            ],
        ];
    }

}
