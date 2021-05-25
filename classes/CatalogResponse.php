<?php

namespace IgniterLabs\SquarePOS\Classes;

class CatalogResponse
{
    protected $idMappings;

    protected $catalogObjects;

    public function __construct($response)
    {
        $result = optional($response->getResult());

        if (method_exists($result, 'getIdMappings')) {
            $this->idMappings = collect($result->getIdMappings() ?? [])
                ->keyBy(function ($idMapping) {
                    return $idMapping->getClientObjectId();
                });
        }

        $this->catalogObjects = collect($result->getObjects() ?? [])
            ->keyBy(function ($catalogObject) {
                return $catalogObject->getId();
            });
    }

    public function getMapping($clientId)
    {
        return optional($this->idMappings)->get($clientId);
    }

    public function getObject($id)
    {
        return optional($this->catalogObjects)->get($id);
    }

    public function getMappings()
    {
        return $this->idMappings;
    }

    public function getObjects()
    {
        return $this->catalogObjects;
    }
}
