<?php

namespace Silktide\Reposition\Sql\QueryInterpreter\Type;

use Silktide\Reposition\Exception\QueryException;
use Silktide\Reposition\QueryBuilder\QueryToken\Entity;
use Silktide\Reposition\QueryBuilder\QueryToken\Token;
use Silktide\Reposition\QueryBuilder\QueryToken\Value;
use Silktide\Reposition\QueryBuilder\QueryToken\Reference;
use Silktide\Reposition\QueryBuilder\TokenSequencerInterface;

class FindInterpreter extends AbstractSqlQueryTypeInterpreter
{

    public function supportedQueryType()
    {
        return TokenSequencerInterface::TYPE_FIND;
    }

    public function interpretQuery(TokenSequencerInterface $query)
    {
        if (empty($this->entityMetadataProvider)) {
            throw new QueryException("Cannot interpret this 'find' query without an EntityMetadataProvider");
        }

        $this->query = $query;

        $includes = $query->getIncludes();
        $targetEntity = $query->getEntityName();
        $metadata = $this->getEntityMetadata($targetEntity);
        $mainCollection = $metadata->getCollection();

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
        $entities = [];
        if (empty($this->fields)) {
            $entity = $targetEntity;
            $collectionAlias = $entity;

            do {
                if (empty($entity)) {
                    continue;
                }
                $metadata = $this->getEntityMetadata($entity);

                // get the collection
                $collection = $metadata->getCollection();

                // if the alias is the same as the entity name, use the collection instead
                if ($collectionAlias == $entity) {
                    $collectionAlias = $collection;
                }

                if ($entity != $targetEntity) {
                    $entities[] = $entity;
                }

                // for each entity field, create an aliased SQL reference
                $entityFields = $metadata->getFieldNames();
                foreach ($entityFields as $field) {
                    $field = $collectionAlias . "." . $field;
                    $thisFieldAlias = $this->getSelectFieldAlias($field);
                    $this->fields[$thisFieldAlias] = $this->renderArbitraryReference($field, $thisFieldAlias);
                }

            } while (list($collectionAlias, $entity) = each($includes));

        } else {
            // if we have aggregate functions to return, includes don't make any sense
            $includes = [];
        }

        if (empty($this->fields)) {
            throw new QueryException("Cannot interpret find query, there are no fields to return");
        }

        // if this query has more than one include, it's more complex and requires separate processing
        if (count($entities) > 1) {
            return $this->renderIncludeQuery($token);
        }

        // simple, no include query. Render all remaining tokens
        $sql = "SELECT " . implode(", ", $this->fields) . " FROM " . $this->renderArbitraryReference($mainCollection);

        // render all other tokens
        do {
            if (empty($token)) {
                break;
            }
            if ($token->getType() == "sort") {
                $this->renderSort($token);
                continue;
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
        $includes = $this->query->getIncludes();
        foreach ($includes as $alias => $entity) {
            if ($alias == $entity) {
                unset($includes[$alias]);
                $alias =  $this->getEntityMetadata($entity)->getCollection();
                $includes[$alias] = $entity;
            }
        }

        $mainEntity = $this->query->getEntityName();
        $metadata = $this->getEntityMetadata($mainEntity);
        $mainCollection = $metadata->getCollection();


        $fieldList = implode(", ", $this->fields);

        $subqueryTemplateSql = "SELECT $fieldList FROM " . $this->renderArbitraryReference($mainCollection);

        // parse the tokens for join conditions
        $joinConditions = [];
        $includeMap = [];

        while (!empty($token) && !in_array($token->getType(), ["group", "sort", "limit"])) {
            $subqueryTemplateSql .= " " . $this->renderToken($token);
            if ($token->getType() == "join") {
                /** @var Reference $ref */
                $ref = $this->query->getNextToken();
                $collection = $this->getReferenceAlias($ref);;
                $subqueryTemplateSql .= " " . $this->renderToken($ref);
                $subqueryTemplateSql .= " " . $this->renderToken($this->query->getNextToken()); // ON
                $subqueryTemplateSql .= " " . $this->renderToken($this->query->getNextToken()); // (
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
                            $refParts = explode(".", $token->getValue());
                            $partCount = count($refParts);
                            if ($partCount > 1) {
                                // capture the 2nd to last element e.g. the collection name
                                $joinCollectionsFound[] = $refParts[$partCount - 2];
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
                    $colOne = $joinCollectionsFound[0];
                    $colTwo = $joinCollectionsFound[1];

                    // first one way ...
                    if (empty($includeMap[$colOne])) {
                        $includeMap[$colOne] = [];
                    }
                    $includeMap[$colOne][] = $colTwo;

                    // ... then the other
                    if (empty($includeMap[$colTwo])) {
                        $includeMap[$colTwo] = [];
                    }
                    $includeMap[$colTwo][] = $colOne;

                    // if either are missing from the includes array, add them now (with no entity)
                    if (!isset($includes[$colOne]) && $colOne != $mainCollection) {
                        $includes[$colOne] = "";
                    }
                    if (!isset($includes[$colTwo]) && $colTwo != $mainCollection) {
                        $includes[$colTwo] = "";
                    }
                }

                $joinConditions[$collection] = $joinCondition;
                $subqueryTemplateSql .= "%{$collection}Condition%";
                $subqueryTemplateSql .= $this->renderToken($token); // )
            }

            $token = $this->query->getNextToken();
        };

        // add target entity to the include array
        $includes = array_merge([$mainCollection => $mainEntity], $includes);

        // recursively construct the join tree
        $joinTree = $this->constructJoinTree($includeMap, $mainCollection);
        // recursively parse the tree into join lists
        $joinLists = [];
        $this->parseJoinTree($joinLists, $joinTree);

        // get primary keys
        $primaryKeys = [];
        foreach ($includes as $alias => $entity) {
            if (empty($entity)) {
                $primaryKeys[$alias] = "id";
                continue;
            }
            $metadata = $this->getEntityMetadata($entity);
            $primaryKeys[$alias] = $metadata->getPrimaryKey();
        }

        $subqueries = [];
        foreach ($joinLists as $collectionList) {
            $templateJoinConditions = $joinConditions;
            $replacements = [];
            foreach ($templateJoinConditions as $collection => $condition) {
                // if this is a collection that were including and that isn't in the collection list, set it's join condition to "[primary key] = NULL"
                if (isset($includes[$collection]) && !in_array($collection, $collectionList)) {
                    $condition = $this->renderArbitraryReference($collection . "." . $primaryKeys[$collection]) . " IS NULL";
                }
                $replacements["%{$collection}Condition%"] = $condition;
            }
            $subqueries[] = strtr($subqueryTemplateSql, $replacements);
        }

        $sql = "SELECT * FROM (" . implode(" UNION ", $subqueries) . ") s";

        // parse order by, taking any field related to the main collection, plus the first field from the included collections, wrapped in an IFNULL()
        $sort = [];
        do {
            if (!empty($token) && $token->getType() == 'sort') {
                $appendDirection = false;
                while(($token = $this->query->getNextToken()) && $token->getType() != 'limit') {
                    /** @var Reference $token */
                    if ($token->getType() == "field" && strpos($token->getValue(), $mainCollection . ".") !== false) {
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

        if (!empty($sort)) {
            // if main collection sort fields were found, don't add it's PK to the list in the next step
            $includes[$mainCollection] = "";
        }

        foreach ($includes as $alias => $entity) {
            if (empty($entity)) {
                continue;
            }
            $sort[] = "IFNULL(" . $this->renderArbitraryReference($this->getSelectFieldAlias($alias. "." . $primaryKeys[$alias])) . ", :largest_value)";
            $this->values["largest_value"] = 4294967295;
        }

        $sql .= " ORDER BY " . implode(", ", $sort);

        return $sql;
    }

    protected function getReferenceAlias(Reference $ref)
    {
        return empty($ref->getAlias())? $ref->getValue(): $ref->getAlias();
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