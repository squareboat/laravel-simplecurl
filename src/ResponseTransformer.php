<?php

namespace SquareBoat\SimpleCurl;

use Illuminate\Pagination\Paginator;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class ResponseTransformer
{
    /**
     * Response Variable
     *
     * @var string
     */
    protected $response = '';

    /**
     * Default Data Key In Response For making Collections/Models etc
     *
     * @var string
     */
    protected $dataKey = '';

    /**
     * Find the `errors` key in Response
     *
     * @var bool
     */
    protected $parseErrors = true;

    /**
     * Set the response for this class from the CURL Request
     *
     * @param string $response
     */
    public function setResponse($response, $dataKey = '', $parseErrors = true)
    {
        $this->response = $response;
        $this->parseErrors = $parseErrors;
        $this->dataKey = (!empty($dataKey)) ? $dataKey : '';

        return $this;
    }

    /**
     * Transform to JSON
     *
     * @return JSON
     */
    public function toJson()
    {
        if (is_string($this->response)) {
            $response = json_decode($this->response);

            if($this->parseErrors && !empty($response->errors)) {
                return $response;
            }

            return ($response && !empty($this->dataKey)) ? (!empty($response->{$this->dataKey})) ? $response->{$this->dataKey} : null : $response;
        }

        return null;
    }

    /**
     * Transfrom to Array
     *
     * @return array
     */
    public function toArray()
    {
        if (is_string($this->response)) {
            $response = json_decode($this->response, true);

            if($this->parseErrors && !empty($response['errors'])) {
                return $response;
            }

            return ($response && !empty($this->dataKey)) ? (!empty($response[$this->dataKey])) ? $response[$this->dataKey] : null : $response;
        }

        return null;
    }

    /**
     * Transfrom to Collection
     *
     * @return Collection
     */
    public function toCollection($model = null)
    {
        $response = $this->toJson();

        if($this->parseErrors && !empty($response->errors)) {
            return $response;
        }

        if ($model && $response) {
            return $this->responseArrayToModelCollection($model, $response);
        }

        return ($response) ? ($this->checkIfAllAreArray($response)) ? collect($response) : collect([$response]) : collect([]);
    }

    /**
     * Transfrom to Collection
     *
     * @return Collection
     */
    public function toPaginated($perPage)
    {
        $response = $this->toJson();

        if($this->parseErrors && !empty($response->errors)) {
            return $response;
        }

        if (!isset($response->total, $response->per_page, $response->current_page, $response->data)) {
            throw new \Exception('Missing Required Fields for Pagination');
        }

        return new LengthAwarePaginator(
            collect($response->data), $response->total, $response->per_page,
            Paginator::resolveCurrentPage(), ['path' => Paginator::resolveCurrentPath()]
        );
    }

    /**
     * Check If All Elements in Response Are Array
     *
     * @param  mixed $response
     *
     * @return boolean
     */
    private function checkIfAllAreArray($response)
    {
        if(!empty($this->dataKey) && is_array($response)) return true;

        foreach ($response as $key => $value) {
            if (!is_object($value) || is_string($value)) {
                return false;
                break;
            }
        }

        return true;
    }

    /**
     * Transform to a Specific Model
     *
     * @param  string $modelName
     * @param  array $relations
     *
     * @return Model
     */
    public function toModel($modelName, $relations = [])
    {
        $response = $this->toJson();

        if($this->parseErrors && !empty($response->errors)) {
            return $response;
        }

        return ($response) ? $this->toModelWithRelations($modelName, $response, $relations) : null;
    }

    /**
     * Check if relations were passed as param and return response accordingly
     *
     * @param  string $modelName
     * @param  JSON $response
     * @param  array $relations
     *
     * @return Model
     */
    private function toModelWithRelations($modelName, $response, $relations = [])
    {
        $model = $this->responseToModel($modelName, $response, $relations);

        if (count($relations) > 0) {
            $model = $this->responseToModelRelation($relations, $response, $model);
        }

        return $model;
    }

    /**
     * Transform Response to the modelName passed as param
     *
     * @param  string $modelName
     * @param  JSON $response
     *
     * @return Model
     */
    private function responseToModel($modelName, $response, $relations = [])
    {
        $this->checkIfModelExists($modelName);
        $model = new $modelName;

        if (method_exists($model, 'getApiAttributes')) {
            $fillableElements = $model->getApiAttributes();
        } else {
            $fillableElements = $model->getFillable();
        }

        if (is_array($response)) {
            return $this->responseArrayToModelCollection($modelName, $response, $relations);
        }

        $modelKeys = array_filter($fillableElements, function ($fillable) use ($response) {
            foreach ($response as $key => $value) {
                if ($key == $fillable || $key == 'created_at' || $key == 'updated_at') {
                    return $key;
                }
            }
        });

        if (count($modelKeys) > 0) {
            foreach ($modelKeys as $modelKey) {
                $value = isset($response->$modelKey) ? $response->$modelKey : null;
                $model->setAttribute($modelKey, $value);
            }
        }

        return $model;
    }

    /**
     * Transform Response to Collection of modelName passed as param
     *
     * @param  string $modelName
     * @param  JSON $response
     *
     * @return Collection
     */
    private function responseArrayToModelCollection($modelName, $response, $relations = [])
    {
        $this->checkIfModelExists($modelName);
        $model = new $modelName;

        if (method_exists($model, 'getApiAttributes')) {
            $fillableElements = $model->getApiAttributes();
        } else {
            $fillableElements = $model->getFillable();
        }

        $modelKeys = array_filter($fillableElements, function ($fillable) use ($response) {
            foreach ($response as $values) {
                foreach ($values as $key => $modelValue) {
                    if ($key == $fillable || $key == 'created_at' || $key == 'updated_at') {
                        return $key;
                    }
                }
            }
        });

        $newArray = [];
        if (count($modelKeys) > 0) {
            foreach ($response as $key => $values) {
                $model = new $modelName;
                foreach ($modelKeys as $modelKey) {
                    $value = isset($values->$modelKey) ? $values->$modelKey : null;
                    $model->setAttribute($modelKey, $value);
                }
                $newArray[] = $model;
            }
        }
        return collect($newArray);
    }

    /**
     * Set Relations to Parent Model
     *
     * @param array $relation
     * @param JSON $response
     * @param Model $model
     *
     * @return Model
     */
    private function responseToModelRelation($relations, $response, $model)
    {
        foreach ($relations as $key => $relation) {
            $model = $this->setRelations($relation, $response, $model);
        }
        return $model;
    }

    /**
     * Set Recurring Relations to Model
     *
     * @param array $relation
     * @param JSON $response
     * @param Model $model
     *
     * @return Model
     */
    private function setRelations($relation, $response, $model)
    {
        $modelKey = array_keys($relation)[0];

        if (!isset($response->$modelKey)) {
            return $model;
        }

        if (count($relation) == 1) {
            return $model->setRelation($modelKey, $this->responseToModel(reset($relation), $response->$modelKey));
        }

        $currentRelations = array_keys($model->getRelations());

        if (count($currentRelations) > 0) {
            $relationalModels = array_filter($currentRelations, function ($currentRelation) use ($response, $modelKey) {
                foreach ($response as $key => $value) {
                    if ($key == $modelKey) {
                        return $modelKey;
                    }
                }
            });
            $newModel = $model->$modelKey;
        } else {
            $newModel = $this->responseToModel($relation[$modelKey], $response->$modelKey);
        }

        $newRelation = $relation;

        unset($newRelation[$modelKey]);

        return $model->setRelation($modelKey, $this->setRelations($newRelation, $response->$modelKey, $newModel));
    }

    /**
     * Check if Model Exists
     *
     * @param  string $modelName
     *
     * @return boolean
     */
    private function checkIfModelExists($modelName)
    {
        if (!class_exists($modelName)) {
            throw new ModelNotFoundException('Class ' .$modelName. ' not found');
        }
        return true;
    }
}
