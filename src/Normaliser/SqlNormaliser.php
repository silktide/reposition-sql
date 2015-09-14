<?php

namespace Silktide\Reposition\Sql\Normaliser;

use Silktide\Reposition\Sql\Exception\NormalisationException;
use Silktide\Reposition\Normaliser\NormaliserInterface;

class SqlNormaliser implements NormaliserInterface
{

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
     * @return array
     */
    public function denormalise(array $data, array $options = [])
    {
        if (!empty($options["relationMap"]) && is_array($options["relationMap"])) {
            foreach ($options["relationMap"] as $relation) {
                $data = $this->denormalise($data, $relation);
            }
        }
        if (!empty($options["keyPrefix"]) && !empty($options["childField"])) {
            $childData = [];
            foreach ($data as $key => $value) {
                if (strpos($key, $options["keyPrefix"]) === 0) {
                    $childData[substr($key, strlen($options["keyPrefix"]) + 1)] = $value;
                }
            }
            if (!empty($options["childCollection"])) {
                if (!isset($data[$options["childField"]])) {
                    $data[$options["childField"]] = [];
                }
                $data[$options["childField"]][] = $childData;
            } else {
                $data[$options["childField"]] = $childData;
            }
        }
        return $data;
    }

} 