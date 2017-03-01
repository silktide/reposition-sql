<?php

namespace Silktide\Reposition\Sql\Test\Normaliser;

use Silktide\Reposition\Exception\MetadataException;
use Silktide\Reposition\Metadata\EntityMetadata;
use Silktide\Reposition\Sql\Normaliser\SqlNormaliser;

class SqlNormaliserTest extends \PHPUnit_Framework_TestCase {

    protected $metadataMocks = [];

    /**
     * @dataProvider dataSetProvider
     *
     * @param array $dataSet
     * @param array $expectedData
     * @param array $entityMap
     * @param string $thisEntity
     */
    public function testDenormalisation(array $dataSet, array $expectedData, array $entityMap = [], $thisEntity = "one")
    {
        $type = EntityMetadata::METADATA_RELATIONSHIP_TYPE;
        $propField = EntityMetadata::METADATA_RELATIONSHIP_PROPERTY;
        $o2m = EntityMetadata::RELATIONSHIP_TYPE_ONE_TO_MANY;

        $relationships = [
            "one" => [
                "children" => [
                    "two" => [
                        $type => $o2m,
                        $propField => "twos"
                    ],
                    "three" => [
                        $type => $o2m,
                        $propField => "threes"
                    ]
                ],
                "primaryKey" => "field1"
            ],
            "two" => [
                "children" => [],
                "primaryKey" => "field3"
            ],
            "three" => [
                "children" => [
                    "four" => [
                        $type => $o2m,
                        $propField => "fours"
                    ],
                    "two" => [
                        $type => $o2m,
                        $propField => "twos"
                    ]
                ],
                "primaryKey" => "field5"
            ],
            "four" => [
                "children" => [],
                "primaryKey" => "field3"
            ]
        ];

        $this->metadataMocks = [];
        foreach ($relationships as $entity => $meta) {
            $metadataMock = \Mockery::mock("Silktide\\Reposition\\Metadata\\EntityMetadata");
            $metadataMock->shouldReceive("getCollection")->andReturn($entity);
            $metadataMock->shouldReceive("getRelationships")->andReturn($meta["children"]);
            $metadataMock->shouldReceive("getPrimaryKey")->andReturn($meta["primaryKey"]);
            $this->metadataMocks[$entity] = $metadataMock;
        }

        $metadataProvider = \Mockery::mock("Silktide\\Reposition\\Metadata\\EntityMetadataProviderInterface");
        $metadataProvider->shouldReceive("getEntityMetadata")->andReturnUsing([$this, "getMetadataMock"]);

        $normaliser = new SqlNormaliser();
        $options = [
            "metadataProvider" => $metadataProvider,
            "entityMap" => $entityMap,
            "entityClass" => $thisEntity
        ];

        $result = $normaliser->denormalise($dataSet, $options);

        $this->assertEquals($expectedData, $result);

    }

    public function getMetadataMock($entity)
    {
        if (!isset($this->metadataMocks[$entity])) {
            throw new MetadataException("TEST: could not find metadata for '$entity'");
        }
        return $this->metadataMocks[$entity];
    }

    public function dataSetProvider()
    {
        $two = \Mockery::mock("Silktide\\Reposition\\Metadata\\EntityMetadata");
        $two->shouldReceive("getEntity")->andReturn("two");
        $two->shouldReceive("getCollection")->andReturn("two");
        $two->shouldReceive("getPrimaryKey")->andReturn("field3");

        $three = \Mockery::mock("Silktide\\Reposition\\Metadata\\EntityMetadata");
        $three->shouldReceive("getEntity")->andReturn("three");
        $three->shouldReceive("getCollection")->andReturn("three");
        $three->shouldReceive("getPrimaryKey")->andReturn("field5");

        $four = \Mockery::mock("Silktide\\Reposition\\Metadata\\EntityMetadata");
        $four->shouldReceive("getEntity")->andReturn("four");
        $four->shouldReceive("getCollection")->andReturn("four");
        $four->shouldReceive("getPrimaryKey")->andReturn("field3");

        return [
            [ // #0 simplest mapping possible
                [["field1" => "value1", "field2" => "value2", "field3" => "value3"]],
                [["field1" => "value1", "field2" => "value2", "field3" => "value3"]]
            ],
            [ // #1 simple mapping, removing prefix
                [["a__field1" => "value1", "a__field2" => "value2", "a__field3" => "value3"]],
                [["field1" => "value1", "field2" => "value2", "field3" => "value3"]]
            ],
            [ // #2 single record, single child
                [["one__field1" => "value1", "one__field2" => "value2", "two__field3" => "value3", "two__field4" => "value4"]],
                [["field1" => "value1", "field2" => "value2", "twos" => [["field3" => "value3", "field4" => "value4"]]]],
                ["two" => $two],
                "one"
            ],
            [ // #3 single record, multiple children
                [
                    ["one__field1" => "value1", "one__field2" => "value2", "two__field3" => "value3", "two__field4" => "value4"],
                    ["one__field1" => "value1", "one__field2" => "value2", "two__field3" => "value5", "two__field4" => "value6"],
                    ["one__field1" => "value1", "one__field2" => "value2", "two__field3" => "value7", "two__field4" => "value8"]
                ],
                [["field1" => "value1", "field2" => "value2", "twos" => [
                    ["field3" => "value3", "field4" => "value4"],
                    ["field3" => "value5", "field4" => "value6"],
                    ["field3" => "value7", "field4" => "value8"]
                ]]],
                ["two" => $two],
                "one"
            ],
            [ // #4 multiple records, multiple children
                [
                    ["one__field1" => "value1", "one__field2" => "value2", "two__field3" => "value3", "two__field4" => "value4"],
                    ["one__field1" => "value1", "one__field2" => "value2", "two__field3" => "value5", "two__field4" => "value6"],
                    ["one__field1" => "value7", "one__field2" => "value8", "two__field3" => "value9", "two__field4" => "value10"],
                    ["one__field1" => "value7", "one__field2" => "value8", "two__field3" => "value11", "two__field4" => "value12"]
                ],
                [
                    ["field1" => "value1", "field2" => "value2", "twos" => [
                        ["field3" => "value3", "field4" => "value4"],
                        ["field3" => "value5", "field4" => "value6"]
                    ]],
                    ["field1" => "value7", "field2" => "value8", "twos" => [
                        ["field3" => "value9", "field4" => "value10"],
                        ["field3" => "value11", "field4" => "value12"]
                    ]]
                ],
                ["two" => $two],
                "one"
            ],
            [ // #5 ignoring children
                [
                    ["one__field1" => "value1", "one__field2" => "value2", "two__field3" => "value3", "two__field4" => "value4"]
                ],
                [
                    ["field1" => "value1", "field2" => "value2"]
                ],
                [],
                "one"
            ],
            [ // #6 single record, multiple relationships
                [
                    ["one__field1" => "value1", "one__field2" => "value2", "two__field3" => "value3", "two__field4" => "value4", "three__field5" => null, "three__field6" => null],
                    ["one__field1" => "value1", "one__field2" => "value2", "two__field3" => null, "two__field4" => null, "three__field5" => "value5", "three__field6" => "value6"]
                ],
                [["field1" => "value1", "field2" => "value2", "twos" => [
                        ["field3" => "value3", "field4" => "value4"]
                    ], "threes" => [
                        ["field5" => "value5", "field6" => "value6"]
                    ]
                ]],
                ["two" => $two, "three" => $three],
                "one"
            ],
            [ // #7 multiple records, multiple relationships
                [
                    ["one__field1" => "value1", "one__field2" => "value2", "two__field3" => "value3", "two__field4" => "value4", "three__field5" => null, "three__field6" => null],
                    ["one__field1" => "value1", "one__field2" => "value2", "two__field3" => null, "two__field4" => null, "three__field5" => "value5", "three__field6" => "value6"],
                    ["one__field1" => "value7", "one__field2" => "value8", "two__field3" => null, "two__field4" => null, "three__field5" => "value9", "three__field6" => "value10"],
                    ["one__field1" => "value7", "one__field2" => "value8", "two__field3" => null, "two__field4" => null, "three__field5" => "value11", "three__field6" => "value12"],
                    ["one__field1" => "value7", "one__field2" => "value8", "two__field3" => null, "two__field4" => null, "three__field5" => null, "three__field6" => null]
                ],
                [
                    ["field1" => "value1", "field2" => "value2", "twos" => [
                            ["field3" => "value3", "field4" => "value4"]
                        ], "threes" => [
                            ["field5" => "value5", "field6" => "value6"]
                        ]
                    ],
                    ["field1" => "value7", "field2" => "value8", "twos" => [], "threes" => [
                        ["field5" => "value9", "field6" => "value10"],
                        ["field5" => "value11", "field6" => "value12"]
                    ]
                    ]
                ],
                ["two" => $two, "three" => $three],
                "one"
            ],
            [ // #8 multiple records, multi-level relationships
                [
                    ["one__field1" => "value1", "one__field2" => "value2", "four__field3" => "value3", "four__field4" => "value4", "three__field5" => "value5", "three__field6" => "value6"],
                    ["one__field1" => "value1", "one__field2" => "value2", "four__field3" => "value7", "four__field4" => "value8", "three__field5" => "value5", "three__field6" => "value6"],
                    ["one__field1" => "value9", "one__field2" => "value10", "four__field3" => "value11", "four__field4" => "value12", "three__field5" => "value13", "three__field6" => "value14"],
                    ["one__field1" => "value9", "one__field2" => "value10", "four__field3" => "value15", "four__field4" => "value16", "three__field5" => "value13", "three__field6" => "value14"],
                    ["one__field1" => "value9", "one__field2" => "value10", "four__field3" => "value17", "four__field4" => "value18", "three__field5" => "value13", "three__field6" => "value14"]
                ],
                [
                    ["field1" => "value1", "field2" => "value2", "threes" => [
                        ["field5" => "value5", "field6" => "value6", "fours" => [
                            ["field3" => "value3", "field4" => "value4"],
                            ["field3" => "value7", "field4" => "value8"]
                        ]]
                    ]],
                    ["field1" => "value9", "field2" => "value10", "threes" => [
                        ["field5" => "value13", "field6" => "value14", "fours" => [
                            ["field3" => "value11", "field4" => "value12"],
                            ["field3" => "value15", "field4" => "value16"],
                            ["field3" => "value17", "field4" => "value18"]
                        ]]
                    ]]
                ],
                ["four" => $four, "three" => $three],
                "one"
            ],
            [ // #9 decode valid JSON fields
                [
                    ["field1" => '["one", "two", "three", "four"]', "field2" => '{"one": "one", "two": "two", "three": "three", "four": "four"}']
                ],
                [
                    [
                        "field1" => ["one", "two", "three", "four"],
                        "field2" => ["one" => "one", "two" => "two", "three" => "three", "four" => "four"],
                    ]
                ]
            ],
            [ // #10 handle invalid JSON and non-JSON
                [
                    ["field1" => '["this": "is", invalid: json]', "field2" => '"this is an encapsulated string"']
                ],
                [
                    ["field1" => '["this": "is", invalid: json]', "field2" => '"this is an encapsulated string"']
                ]
            ],
            [ // #11 multiple records, no children
                [
                    ["one__field1" => "value1", "one__field2" => "value2"],
                    ["one__field1" => "value3", "one__field2" => "value4"],
                    ["one__field1" => "value5", "one__field2" => "value6"],
                    ["one__field1" => "value7", "one__field2" => "value8"]
                ],
                [
                    ["field1" => "value1", "field2" => "value2"],
                    ["field1" => "value3", "field2" => "value4"],
                    ["field1" => "value5", "field2" => "value6"],
                    ["field1" => "value7", "field2" => "value8"]
                ]
            ],
            [ // #12 no children, no primary key
                [
                    ["one__field2" => "value1", "one__field3" => "value2"],
                    ["one__field2" => "value3", "one__field3" => "value4"],
                    ["one__field2" => "value5", "one__field3" => "value6"]
                ],
                [
                    ["field2" => "value1", "field3" => "value2"],
                    ["field2" => "value3", "field3" => "value4"],
                    ["field2" => "value5", "field3" => "value6"]
                ]
            ]

        ];
    }

}
 