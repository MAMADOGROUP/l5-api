<?php

namespace Specialtactics\L5Api\Transformers;

use League\Fractal\TransformerAbstract;
use Specialtactics\L5Api\Models\RestfulModel;

class RestfulTransformer extends TransformerAbstract
{
    /**
     * @var RestfulModel The model to be transformed
     */
    protected $model = null;

    /**
     * Transform an eloquent object into a jsonable array
     *
     * @param RestfulModel $model
     * @return array
     */
    public function transform(RestfulModel $model)
    {
        $this->model = $model;

        // Begin the transformation!
        $transformed = $model->toArray();

        /**
         * Filter out attributes we don't want to expose to the API
         */
        $filterOutAttributes = $this->getFilteredOutAttributes();

        $transformed = array_filter($transformed, function($key) use ($filterOutAttributes) {
            return ! in_array($key, $filterOutAttributes);
        }, ARRAY_FILTER_USE_KEY);

        /**
         * Format all dates as Iso8601 strings, this includes the created_at and updated_at columns
         */
        foreach($model->getDates() as $dateColumn) {
            if(!empty($model->$dateColumn)) {
                $transformed[$dateColumn] = $model->$dateColumn->toIso8601String();
            }
        }

        /**
         * Transform all keys to CamelCase, recursively
         */
        $transformed = camel_case_array($transformed);

        /**
         * Get the relations for this object and transform then
         */
        $transformed = $this->transformRelations($transformed);

        return $transformed;
    }

    /**
     * Filter out some attributes immediately
     *
     * Some attributes we never want to expose to an API consumer, for security and separation of concerns reasons
     * Feel free to override this function as necessary
     *
     * @return array Array of attributes to filter out
     */
    protected function getFilteredOutAttributes() {
        $filterOutAttributes = array_merge(
            $this->model->getHidden(),
            [
                $this->model->getKeyName(),
                'deleted_at',
            ]
        );

        return array_unique($filterOutAttributes);
    }

    /**
     * Do relation transformations
     *
     * @param array $transformed
     * @return array $transformed
     */
    protected function transformRelations(array $transformed) {
        // Iterate through all relations
        foreach($this->model->getRelations() as $relationKey => $relation) {

            // Skip Pivot
            if ($relation instanceof \Illuminate\Database\Eloquent\Relations\Pivot) {
                continue;
            }

            // Transform Collection
            else if ($relation instanceof \Illuminate\Database\Eloquent\Collection) {
                if( count($relation->getIterator()) > 0) {

                    $relationModel = $relation->first();
                    $relationTransformer = $relationModel::getTransformer();

                    // Transform related model collection
                    if ($this->model->$relationKey) {
                        foreach($relation->getIterator() as $key => $relatedModel) {
                            // Replace the related models with their transformed selves
                            $transformedRelatedModel = $relationTransformer->transform($relatedModel);

                            // We don't really care about pivot information at this stage
                            if ($transformedRelatedModel['pivot']) {
                                unset($transformedRelatedModel['pivot']);
                            }

                            $transformed[camel_case($relationKey)][$key] = $transformedRelatedModel;
                        }
                    }
                }
            }

            // Transformed related model
            else if ($relation instanceof RestfulModel) {
                // Get transformer of relation model
                $relationTransformer = $relation::getTransformer();

                if ($this->model->$relationKey) {
                    $transformed[camel_case($relationKey)] = $relationTransformer->transform($this->model->$relationKey);
                }
            }
        }

        return $transformed;
    }
}