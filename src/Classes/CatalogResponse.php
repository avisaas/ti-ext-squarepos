<?php

declare(strict_types=1);

namespace IgniterLabs\SquarePOS\Classes;

use Square\Types\CatalogIdMapping;
use Square\Types\CatalogObject;

class CatalogResponse
{
    protected $idMappings;

    protected $catalogObjects;

    public function __construct(protected $response)
    {
        if (method_exists($this->response, 'getIdMappings')) {
            $this->idMappings = collect($this->response->getIdMappings() ?? [])
                ->keyBy(fn($idMapping) => $idMapping->getClientObjectId());
        }

        if (method_exists($this->response, 'getObjects')) {
            $this->catalogObjects = collect($this->response->getObjects() ?? [])
                ->keyBy(fn(object $catalogObject) => CatalogRecord::unwrap($catalogObject)->getId());
        }

        if (method_exists($this->response, 'getRelatedObjects')) {
            foreach ($this->response->getRelatedObjects() ?? [] as $catalogObject) {
                $this->catalogObjects ??= collect();
                $this->catalogObjects->put(CatalogRecord::unwrap($catalogObject)->getId(), $catalogObject);
            }
        }
    }

    /**
     * @return null|CatalogIdMapping
     */
    public function getMapping($clientId)
    {
        return optional($this->idMappings)->get($clientId);
    }

    /**
     * @return null|CatalogObject
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
