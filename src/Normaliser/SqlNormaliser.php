<?php

namespace Silktide\Reposition\Sql\Normaliser;

use Silktide\Reposition\Exception\NormalisationException;
use Silktide\Reposition\Metadata\EntityMetadata;
use Silktide\Reposition\Normaliser\NormaliserInterface;
use Silktide\Reposition\Metadata\EntityMetadataProviderInterface;

class SqlNormaliser implements NormaliserInterface
{

    /**
     * @var EntityMetadataProviderInterface
     */
    protected $metadataProvider;

    /**
     * format data into the DB format
     *
     * @param array $data
     * @param array $options
     * @throws NormalisationException
     * @return array
     */
    public function normalise(array $data, array $options = [])
    {
        throw new NormalisationException("SQL data sets do not require normalisation");
    }

    /**
     * format DB data into standard format
     *
     * @param array $data
     * @param array $options
     *
     * @throws NormalisationException
     * @return array
     */
    public function denormalise(array $data, array $options = [])
    {
        if (empty($data)) {
            return $data;
        }

        // split fields based on prefix
        $prefixedFields = [];
        foreach ($data[0] as $field => $value) {
            $fieldParts = explode("__", $field);
            // if this field has no prefix, set it to an empty string
            if (count($fieldParts) == 1) {
                array_unshift($fieldParts, "");
            }
            // add the field to the prefix's array
            $prefix = $fieldParts[0];
            if (empty($prefixedFields[$prefix])) {
                $prefixedFields[$prefix] = [];
            }
            $prefixedFields[$prefix][$fieldParts[1]] = $field;
        }

        // handle case where only 1 prefix is present
        if (count($prefixedFields) == 1) {
            $fields = array_pop($prefixedFields);
            return $this->denormaliseData($data, $fields, "");
        }

        // complex result set

        if (!isset($options["entity"])) {
            throw new NormalisationException("Cannot denormalise data without knowing the main entity class");
        }

        if (!isset($options["entityMap"])) {
            throw new NormalisationException("Cannot denormalise data without an entity map");
        }

        if (!isset($options["metadataProvider"])) {
            throw new NormalisationException("Cannot denormalise data without a metadata provider");
        }

        /** @var EntityMetadataProviderInterface $metadataProvider */
        $this->metadataProvider = $options["metadataProvider"];
        $entityMap = $options["entityMap"];

        $metadata = $this->metadataProvider->getEntityMetadata($options["entity"]);
        $collection = $metadata->getCollection();

        if (empty($prefixedFields[$collection])) {
            throw new NormalisationException("The collection '$collection' was not found in the fields array for this record set: '" . implode("', '", array_keys($prefixedFields)) . "'");
        }

        $this->createFieldTree($prefixedFields, $entityMap, $collection, $options["entity"]);

        reset($data);
        $result = $this->denormaliseData($data, $prefixedFields[$collection], "");

        return  $result;
    }

    protected function createFieldTree(array &$fields, array $entityMap, $alias, $entity)
    {
        $metadata = $this->metadataProvider->getEntityMetadata($entity);
        $availableJoins = $metadata->getRelationships();

        foreach ($entityMap as $childAlias => $childMetadata) {
            /** @var EntityMetadata $childMetadata */
            $childEntity = $childMetadata->getEntity();

            $thisJoin = !empty($availableJoins[$childAlias])
                ? $availableJoins[$childAlias]
                : (!empty($availableJoins[$childEntity])
                    ? $availableJoins[$childEntity]
                    : []
                );
            if (empty($thisJoin)) {
                continue;
            }
            if (empty($thisJoin[EntityMetadata::METADATA_RELATIONSHIP_PROPERTY])) {
                throw new NormalisationException("No property for '$entity' defined in the relationship for '$childAlias'");
            }
            $property = $thisJoin[EntityMetadata::METADATA_RELATIONSHIP_PROPERTY];

            if (empty($fields[$childAlias])) {
                // get this entities collection from it's metadata and try that

                $childAlias = $childMetadata->getCollection();
                if (empty($fields[$childAlias])) {
                    continue;
                }
            }

            if ($thisJoin[EntityMetadata::METADATA_RELATIONSHIP_TYPE] == EntityMetadata::RELATIONSHIP_TYPE_ONE_TO_ONE) {
                // mark this branch as a non-collection
                $fields[$childAlias][""] = true;
            }

            $fields[$alias][$property] = &$fields[$childAlias];

            $this->createFieldTree($fields, $entityMap, $childAlias, $childEntity);
        }
    }

    protected function denormaliseData(array &$data, array $fields, $newRowField)
    {
        $row = current($data);
        $allNewRows = false;
        $newRowValue = null;

        // check if these are all new rows or if we have a value that can be used to check for changes, where a change in value signifies a new row
        if (empty($newRowField)) {
            $allNewRows = true;
        } else {
            if (!isset($row[$newRowField])) {
                throw new NormalisationException("Cannot denormalise data. The field '$newRowField' doesn't exist in the result set, so we're unable to check when a new row is required");
            }
            $newRowValue = $row[$newRowField];
        }

        // find the first field eligible to be the new row field for any child relationships
        $firstField = null;
        foreach ($fields as $field) {
            if (!is_array($field)) {
                $firstField = $field;
                break;
            }
        }
        if (is_null($firstField)) {
            // this shouldn't happen as there would have been no field prefix for the relationships to have been added to, but there's no harm in double checking
            throw new NormalisationException("Cannot denormalise data. Entities must have a field defined that is not a child relationship");
        }

        // parse the data and create the denormalised data tree
        $denormalisedData = [];
        $stepDataBackOne = true;
        do {
            $denormalisedRow = [];
            foreach ($fields as $newField => $field) {
                if (empty($newField)) {
                    continue;
                }
                if (is_array($field)) {
                    try {
                        $denormalisedRow[$newField] = $this->denormaliseData($data, $field, $firstField);
                    } catch (NormalisationException $e) {
                        // ignore this child; record set was probably null. Means there was no child object for the row.
                    }
                } else {
                    // handle any JSON values
                    $fieldValue = $row[$field];
                    if (is_string($fieldValue)) {
                        $json = trim($fieldValue);
                        // only attempt decoding if the trimmed string the starts with [ or {
                        // JSON *can* start with other values, but we don't want to decode those as such data may
                        // intentionally be non-JSON
                        if ($json != "" && ($json[0] == "[" || $json[0] == "{")) {
                            $json = json_decode($json, true);
                            if (!empty($json) && json_last_error() == JSON_ERROR_NONE) {
                                $fieldValue = $json;
                            }
                        }
                    }
                    $denormalisedRow[$newField] = $fieldValue;
                }



            }

            if ($this->isEmptyEntityRow($denormalisedRow)) {
                // no non-null element in the row. This is a miss for this entity, so we should stop processing
                $stepDataBackOne = false;
                break;
            }

            $denormalisedData[] = $denormalisedRow;
            $row = next($data);
        } while (!empty($row) && ($allNewRows || $row[$newRowField] == $newRowValue));

        if ($stepDataBackOne) {
            // rewind the data array by 1, so we don't skip any rows if this has been called recursively
            prev($data);
        }

        // if this field set has been marked as a non-collection, return the first row of data or null
        if (!empty($fields[""])) {
            $denormalisedData = empty($denormalisedData)
                ? null
                : $denormalisedData[0];
        }

        return $denormalisedData;
    }

    protected function isEmptyEntityRow($row)
    {
        $filteredRow = array_filter($row, function ($value) {
            return !is_null($value);
        });
        return count($filteredRow) == 0;
    }

} 