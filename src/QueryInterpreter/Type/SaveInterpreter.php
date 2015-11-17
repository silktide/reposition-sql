<?php


namespace Silktide\Reposition\Sql\QueryInterpreter\Type;
use Downsider\Clay\Model\NameConverterTrait;
use Silktide\Reposition\Exception\InterpretationException;
use Silktide\Reposition\QueryBuilder\QueryToken\Entity;
use Silktide\Reposition\QueryBuilder\QueryToken\Value;
use Silktide\Reposition\QueryBuilder\TokenSequencerInterface;
use Silktide\Reposition\Metadata\EntityMetadata;

/**
 * SaveInterpreter
 */
class SaveInterpreter extends AbstractSqlQueryTypeInterpreter
{

    use NameConverterTrait;

    protected $sqlCommand;

    protected $primaryKey = "";

    protected $fieldSql = [];

    protected $relatedProperties = [];

    public function supportedQueryType()
    {
        return TokenSequencerInterface::TYPE_SAVE;
    }

    public function interpretQuery(TokenSequencerInterface $query)
    {
        $this->query = $query;

        $metadata = $this->query->getEntityMetadata();

        // is this an update or insert query
        /** @var Entity $token */
        $token = $this->query->getNextToken();
        $entity = $token->getEntity();
        $this->primaryKey = $metadata->getPrimaryKey();
        if (is_object($entity)) {
            $pkGetter = "get" . $this->toStudlyCaps($this->primaryKey);
            if (!method_exists($entity, $pkGetter)) {
                throw new InterpretationException("Could not get the primary key '{$this->primaryKey}'. The method '$pkGetter' does not exist on the entity");
            }
            $id = $entity->{$pkGetter}();
        } else {
            $id = isset($entity[$this->primaryKey])? $entity[$this->primaryKey]: null;
        }

        // cache the field references if required
        if (empty($this->fieldSql)) {
            $this->fieldSql = [];
            foreach ($metadata->getFieldNames() as $field) {
                $this->fieldSql[$field] = $this->renderArbitraryReference($field);
            }
            // if any of this entities relationships are one to one, check if we need to set "our" field
            foreach ($metadata->getRelationships() as $alias => $relationship) {
                if (
                    $relationship[EntityMetadata::METADATA_RELATIONSHIP_TYPE] == EntityMetadata::RELATIONSHIP_TYPE_ONE_TO_ONE &&
                    !empty($relationship[EntityMetadata::METADATA_RELATIONSHIP_OUR_FIELD])
                ) {
                    $field = $relationship[EntityMetadata::METADATA_RELATIONSHIP_OUR_FIELD];
                    $this->fieldSql[$field] = $this->renderArbitraryReference($field);
                    $theirField = $relationship[EntityMetadata::METADATA_RELATIONSHIP_THEIR_FIELD];
                    if (empty($theirField)) {
                        $theirField = "id";
                    }
                    $this->relatedProperties[$field] = [
                        "property" => $relationship[EntityMetadata::METADATA_RELATIONSHIP_PROPERTY],
                        "theirField" => $theirField
                    ];
                }
            }
        }

        $collection = $this->renderArbitraryReference($metadata->getCollection());

        if (empty($id)) {
            // insert;
            $this->sqlCommand = "insert";
            $sql = "INSERT INTO $collection";

            $sql .= " (" . implode(", ", $this->fieldSql) . ") VALUES ";

            $entities = [];

            do {
                $entities[] = $this->renderToken($token);
            } while ($token = $this->query->getNextToken());

            $sql .= implode(", ", $entities);

        } else {
            // update
            $this->sqlCommand = "update";
            $sql = "UPDATE $collection SET ";
            $sql .= $this->renderToken($token);
            $sql .= " WHERE " . $this->renderArbitraryReference($this->primaryKey) . " = :searchId";
            $this->values["searchId"] = $id;
        }

        return $sql;
    }

    protected function renderEntity(Entity $token)
    {
        $entity = $token->getEntity();
        if (is_object($entity)) {
            if (!method_exists($entity, "toArray")) {
                throw new InterpretationException("Encountered an entity object instance which does not implement 'toArray'");
            }
            $entityReferences = [];
            foreach ($this->relatedProperties as $field => $config) {
                $childGetter = "get" . $this->toStudlyCaps($config["theirField"]);
                $entityGetter = "get" . $this->toStudlyCaps($config["property"]);
                $entityReferences[$field] = $entity->{$entityGetter}()->{$childGetter}();
            }
            $entity = array_merge($entity->toArray(), $entityReferences);
        }

        $sql = "";
        if ($this->sqlCommand == "insert") {
            $values = [];
            foreach ($this->fieldSql as $field => $fieldSql) {
                $values[] = $this->renderEntityField($field, $entity);
            }
            $sql = "(" . implode(", ", $values) . ")";
        } elseif ($this->sqlCommand == "update") {
            $kvps = [];
            foreach ($this->fieldSql as $field => $fieldSql) {
                if ($field == $this->primaryKey || !array_key_exists($field, $entity)) {
                    continue;
                }
                $kvps[] = "$fieldSql = " . $this->renderEntityField($field, $entity);
            }
            $sql = implode(", ", $kvps);
        }
        return $sql;
    }

    protected function renderEntityField($field, $entity)
    {
        $value = (array_key_exists($field, $entity))
            ? $entity[$field]
            : null;

        $type = null;
        if (is_null($value)) {
            $type = Value::TYPE_NULL;
        } elseif (is_bool($value)) {
            $type = Value::TYPE_BOOL;
        } elseif (is_array($value)) {
            $type = Value::TYPE_ARRAY;
        }

        return $this->renderValueParameter($value, $type);
    }

}