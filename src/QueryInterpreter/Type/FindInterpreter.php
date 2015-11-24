<?php

namespace Silktide\Reposition\Sql\QueryInterpreter\Type;

use Silktide\Reposition\Exception\InterpretationException;
use Silktide\Reposition\QueryBuilder\QueryToken\Entity;
use Silktide\Reposition\QueryBuilder\QueryToken\Token;
use Silktide\Reposition\QueryBuilder\QueryToken\Value;
use Silktide\Reposition\QueryBuilder\QueryToken\Reference;
use Silktide\Reposition\QueryBuilder\TokenSequencerInterface;
use Silktide\Reposition\Metadata\EntityMetadata;

class FindInterpreter extends AbstractSqlQueryTypeInterpreter
{

    public function supportedQueryType()
    {
        return TokenSequencerInterface::TYPE_FIND;
    }

    public function interpretQuery(TokenSequencerInterface $query)
    {
        $this->reset();
        $this->query = $query;

        $includes = $query->getIncludes();
        $mainMetadata = $query->getEntityMetadata();
        $mainCollection = $mainMetadata->getCollection();

        $token = $query->getNextToken();

        $this->fields = [];
        while (!empty($token) && $token->getType() == "function") {
            // render the aggregate function SQL e.g. COUNT(*), SUM(field), etc...
            /** @var Value $token */
            $fieldSql = $this->renderFunction($token);

            // create an alias for this aggregate and append the SQL
            $collectionAlias = $this->findFreeAlias($token->getValue(), $this->fields);
            $fieldSql .= $this->renderAlias($collectionAlias);

            // add the sql to the fields array and load the next token
            $this->fields[$collectionAlias] = $fieldSql;

            $token = $query->getNextToken();
        }

        // if we had no aggregate functions in the sequence, then this is a standard select query
        // so, we need to get the fields to return from the entity metadata for each
        if (empty($this->fields)) {
            $metadata = $mainMetadata;
            $collectionAlias = $mainCollection;

            do {
                if (empty($metadata)) {
                    continue;
                }

                // for each entity field, create an aliased SQL reference
                $entityFields = $metadata->getFieldNames();
                sort($entityFields);
                foreach ($entityFields as $field) {
                    $field = $collectionAlias . "." . $field;
                    $thisFieldAlias = $this->getSelectFieldAlias($field);
                    $this->fields[$thisFieldAlias] = $this->renderArbitraryReference($field, $thisFieldAlias);
                }

            } while (list($collectionAlias, $metadata) = each($includes));

        }

        if (empty($this->fields)) {
            throw new InterpretationException("Cannot interpret find query, there are no fields to return");
        }

        // if this query has more than one include, it's more complex and requires separate processing
        if (count($includes) > 1) {
            return $this->renderIncludeQuery($token);
        }

        // simple, no include query. Render all remaining tokens
        $sql = "SELECT " . implode(", ", $this->fields) . " FROM " . $this->renderArbitraryReference($mainCollection);

        // render all other tokens
        do {
            if (empty($token)) {
                break;
            }

            $sql .= " " . $this->renderToken($token);
        } while ($token = $this->query->getNextToken());

        return $sql;
    }

    protected function findFreeAlias($reference, $aliases)
    {
        $ordinal = 0;
        $alias = $reference;
        while(!empty($aliases[$alias])) {
            $alias = $reference . "_" . ++$ordinal;
        };
        return $alias;
    }

    protected function getSelectFieldAlias($fieldRef)
    {
        return str_replace(".", "__", $fieldRef);
    }

    /**
     * queries with 2 or more includes require a series of select queries, unioned together, then sorted to produce an
     * array format that can be used to generate entities from
     *
     * @param Token $token
     *
     * @return string
     */
    protected function renderIncludeQuery(Token $token)
    {
        $collections = $this->query->getIncludes();

        $mainMetadata = $this->query->getEntityMetadata();
        $mainCollection = $mainMetadata->getCollection();


        $fieldList = implode(", ", $this->fields);

        $subqueryTemplateSql = "SELECT $fieldList FROM " . $this->renderArbitraryReference($mainCollection);

        // parse the tokens for join conditions
        $joinConditions = [];
        $joinMap = [];

        while (!empty($token) && !in_array($token->getType(), ["group", "sort", "limit"])) {
            $subqueryTemplateSql .= " " . $this->renderToken($token);
            if ($token->getType() == "join") {
                /** @var Reference $ref */
                $ref = $this->query->getNextToken();
                $alias = $this->getReferenceAlias($ref);;
                $subqueryTemplateSql .= " " . $this->renderToken($ref);
                $subqueryTemplateSql .= " " . $this->renderToken($this->query->getNextToken()); // ON
                $subqueryTemplateSql .= " " . $this->renderToken($this->query->getNextToken()); // (

                // write the join condition to a separate array, so we can replace it into the SQL when appropriate
                $level = 1; // counter to keep track of how deep we go into any closures
                $joinCondition = "";
                $joinCollectionsFound = [];
                while (($token = $this->query->getNextToken()) && !($token->getType() == "close" && $level == 1)) {
                    /** @var Token $token */
                    $joinCondition .= " " . $this->renderToken($token);
                    $type = $token->getType();
                    switch ($type) {
                        case "field":
                            /** @var Reference $token */
                            // save the collection from this reference
                            $collection = $this->getCollectionFromReference($token);
                            if (!empty($collection)) {
                                $joinCollectionsFound[] = $collection;
                            }
                            break;
                        case "open":
                            ++$level;
                            break;
                        case "close":
                            --$level;
                            break;
                    }

                }

                // if we found enough collections, create a mapping between them
                if (count($joinCollectionsFound) > 1) {
                    $this->mapJoins($joinCollectionsFound, $joinMap, $collections);
                }

                // save the join condition and write a placeholder to the template SQL
                $joinConditions[$alias] = $joinCondition;
                $subqueryTemplateSql .= "%{$alias}Condition%";

                $subqueryTemplateSql .= $this->renderToken($token); // )
            }

            $token = $this->query->getNextToken();
        };

        // add target entity to the collections array (as the first element to make sorting easier later)
        unset($collections[$mainCollection]);
        $collections = array_merge([$mainCollection => $mainMetadata], $collections);

        $primaryKeys = $this->getPrimaryKeys($collections);

        // recursively construct the join tree
        $joinTree = $this->constructJoinTree($joinMap, $mainCollection);
        // recursively parse the tree into join lists
        $joinLists = [];
        $this->parseJoinTree($joinLists, $joinTree);

        // create the SQL for each subquery
        $subqueries = [];
        foreach ($joinLists as $collectionList) {
            $templateJoinConditions = $joinConditions;
            $replacements = [];
            foreach ($templateJoinConditions as $collection => $condition) {
                // if this is a collection that were including and that isn't in the collection list, set it's join condition to "[primary key] = NULL"
                if (isset($collections[$collection]) && !in_array($collection, $collectionList)) {
                    $condition = $this->renderArbitraryReference($collection . "." . $primaryKeys[$collection]) . " IS NULL";
                }
                $replacements["%{$collection}Condition%"] = $condition;
            }
            $subqueries[] = strtr($subqueryTemplateSql, $replacements);
        }

        $sql = "SELECT * FROM (" . implode(" UNION ", $subqueries) . ") s";

        $sort = $this->processSortFields($token, $collections, $mainCollection, $primaryKeys);

        if (!empty($sort)) {
            // shouldn't be possible to not have any sort fields, but hey!
            $sql .= " ORDER BY " . implode(", ", $sort);
        }

        return $sql;
    }

    protected function getCollectionFromReference(Reference $ref) {
        $refParts = explode(".", $ref->getValue());
        $partCount = count($refParts);
        if ($partCount > 1) {
            // always use the 2nd from last element, so 'database.table.field' and 'table.field' will both return 'table';
            return $refParts[$partCount - 2];
        }
        return null;
    }

    protected function getReferenceAlias(Reference $ref)
    {
        return empty($ref->getAlias())? $ref->getValue(): $ref->getAlias();
    }

    protected function getPrimaryKeys(array $collections)
    {
        $primaryKeys = [];
        foreach ($collections as $alias => $metadata) {
            /** @var EntityMetadata $metadata */
            if (empty($metadata)) {
                $primaryKeys[$alias] = "id";
                continue;
            }
            $primaryKeys[$alias] = $metadata->getPrimaryKey();
        }
        return $primaryKeys;
    }

    protected function mapJoins($collectionsFound, array &$joinMap, array &$collections)
    {
        // create from and to mappings for each collection
        $toMap = [
            $collectionsFound[0] => $collectionsFound[1],
            $collectionsFound[1] => $collectionsFound[0],
        ];

        foreach ($toMap as $from => $to) {
            // create the mapping
            if (empty($joinMap[$from])) {
                $joinMap[$from] = [];
            }
            $joinMap[$from][] = $to;

            // if 'from' is missing from the collections array, add it now
            if (!isset($collections[$from])) {
                $collections[$from] = "";
            }
        }
    }

    protected function constructJoinTree(&$includeMap, $collection)
    {
        $tree = [];
        foreach ($includeMap[$collection] as $alias) {
            if (!isset($includeMap[$alias])) {
                continue;
            }
            // remove this collection from the children
            $children = [];
            foreach ($includeMap[$alias] as $child) {
                if ($child != $collection) {
                    $children[] = $child;
                }
            }
            $includeMap[$alias] = $children;
            $tree[$alias] = $this->constructJoinTree($includeMap, $alias);
        }
        return $tree;
    }

    protected function parseJoinTree(array &$joinLists, array $joinTree, $currentBranch = [])
    {
        foreach ($joinTree as $branch => $leaves) {
            $thisBranch = $currentBranch;
            $thisBranch[] = $branch;
            if (!empty($leaves)) {
                $this->parseJoinTree($joinLists, $leaves, $thisBranch);
            } else {
                // reached leaf node, add join branch to the list
                $joinLists[] = $thisBranch;
            }
        }
    }

    protected function processSortFields($token, array $collections, $mainCollection, array $primaryKeys)
    {
        // parse order by, taking any field related to the main collection, plus the first field from the included collections, wrapped in an IFNULL()
        $sort = [];
        do {
            /** @var Token $token */
            if (!empty($token) && $token->getType() == 'sort') {
                $appendDirection = false;
                while(($token = $this->query->getNextToken()) && $token->getType() != 'limit') {
                    /** @var Reference $token */
                    if ($token->getType() == "field" && $this->getCollectionFromReference($token) == $mainCollection) {
                        $sort[] = $this->renderArbitraryReference($this->getSelectFieldAlias($token->getValue()));
                        $appendDirection = true;
                    } elseif ($token->getType() == "sort-direction" && $appendDirection) {
                        // append the sort direction to the last sort field we processed
                        $sort[count($sort) - 1] .= " " . $this->renderToken($token);
                        $appendDirection = false;
                    } else {
                        $appendDirection = false;
                    }
                }
            }
        } while (!empty($token) && $token->getType() != 'limit' && ($token = $this->query->getNextToken()));

        $mainCollectionSortFieldsExist = false;
        if (!empty($sort)) {
            // if main collection sort fields were found, don't add it's PK to the list in the next step
            $mainCollectionSortFieldsExist = true;
        }

        foreach ($collections as $alias => $metadata) {
            /** @var EntityMetadata $metadata */
            if (empty($metadata) || ($alias == $mainCollection && $mainCollectionSortFieldsExist)) {
                continue;
            }

            // get the largest value for the field type of the PK
            $largestValue = "999999999999999999999999";
            $pk = $primaryKeys[$alias];
            if ($metadata->getFieldType($pk) == "string") {
                $largestValue = "0xFFFF";
            }

            $sort[] = "IFNULL(" . $this->renderArbitraryReference($this->getSelectFieldAlias($alias. "." . $primaryKeys[$alias])) . ", $largestValue)";
        }
        return $sort;
    }

    /**
     * @param Entity $token
     *
     * @return string
     */
    protected function renderEntity(Entity $token)
    {
        return "";
    }


} 