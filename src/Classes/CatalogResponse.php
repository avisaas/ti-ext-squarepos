<?php

namespace IgniterLabs\SquarePOS\Classes;

class CatalogResponse
{
    protected $response;

    protected $idMappings;

    protected $catalogObjects;

    public function __construct($response)
    {
        $this->response = $response;

        if (method_exists($response, 'getIdMappings')) {
            $this->idMappings = collect($response->getIdMappings() ?? [])
                ->keyBy(function ($idMapping) {
                    return $idMapping->getClientObjectId();
                });
        }

        if (method_exists($response, 'getObjects')) {
            $this->catalogObjects = collect($response->getObjects() ?? [])
                ->keyBy(function ($catalogObject) {
                    return $catalogObject->getId();
                });
        }
    }

    /**
     * @param $clientId
     * @return null|\Square\Models\CatalogIdMapping
     */
    public function getMapping($clientId)
    {
        return optional($this->idMappings)->get($clientId);
    }

    /**
     * @param $id
     * @return null|\Square\Models\CatalogObject
     */
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

    public function getResponse()
    {
        return $this->response;
    }
}
