<?php namespace Anomaly\Streams\Platform\Entry;

use Anomaly\Streams\Addon\Module\Users\User\Contract\UserInterface;
use Anomaly\Streams\Platform\Addon\FieldType\FieldType;
use Anomaly\Streams\Platform\Assignment\AssignmentModel;
use Anomaly\Streams\Platform\Model\EloquentModel;
use Anomaly\Streams\Platform\Stream\StreamModel;
use Anomaly\Streams\Platform\Support\Transformer;

class EntryModel extends EloquentModel implements EntryInterface
{

    /**
     * Stream data
     *
     * @var array/null
     */
    protected $stream = [];

    /**
     * Create a new EntryModel instance.
     */
    public function __construct($attributes = [])
    {
        parent::__construct($attributes);

        $this->stream = (new StreamModel())->object($this->stream);

        $this->stream->parent = $this;
    }

    public static function boot()
    {
        parent::boot();

        /**
         * We HAVE to override observing here
         * because we have a construct.
         *
         * Mock similar behavior.
         */

        // Observing is a must.
        $observer = 'Anomaly\Streams\Platform\Entry\EntryModelObserver';

        // If the called class has it's own use it.
        if ($override = (new Transformer())->toObserver(get_called_class())) {

            $observer = $override;
        }

        self::observe(new $observer);
    }

    /**
     * Return the default columns.
     *
     * @return array
     */
    public function defaultColumns()
    {
        return [$this->getKeyName(), $this->CREATED_AT, 'createdByUser'];
    }

    /**
     * Set a given attribute on the model.
     *
     * @param  string $key
     * @param  mixed  $value
     * @return void
     */
    public function setAttribute($key, $value, $mutate = true)
    {
        /**
         * If we have a field type for this key fire it's
         * onSet callback to allow changing the value
         * before storage.
         */
        if ($mutate and $type = $this->getTypeFromField($key)) {

            $value = $type->fire('set', [$value]);

            $type->fire('after_set', [$this]);
        }

        parent::setAttribute($key, $value);
    }

    /**
     * Get a given attribute on the model.
     *
     * @param  string $key
     * @return void
     */
    public function getAttribute($key, $mutate = true)
    {
        $value = parent::getAttribute($key);

        /**
         * If we have a field type for this key fire it's
         * onGet callback to allow changing the value
         * retrieved from storage.
         */
        if ($mutate and $type = $this->getTypeFromField($key)) {

            $value = $type->fire('get', [$value]);
        }

        return $value;
    }

    /**
     * Set entry information that every record needs.
     *
     * @return $this
     */
    public function setMetaAttributes()
    {
        $userId = null;

        if ($user = app('auth')->check() and $user instanceof UserInterface) {

            $userId = $user->getId();
        }

        if (!$this->exists) {
            $this->setAttribute('created_by', $userId);
            $this->setAttribute('updated_at', null);
            $this->setAttribute('ordering_count', $this->count('id') + 1);
        } else {
            $this->setAttribute('updated_by', $userId);
            $this->setAttribute('updated_at', time());
        }

        return $this;
    }

    /**
     * Find an assignment by it's slug.
     *
     * @param $slug
     * @return mixed
     */
    public function findAssignmentByFieldSlug($slug)
    {
        return $this
            ->stream
            ->assignments
            ->findByFieldSlug($slug);
    }

    /**
     * Return a bootstrapped field type object.
     *
     * @param $slug
     * @return mixed
     */
    public function fieldType($slug)
    {
        if ($assignment = $this->findAssignmentByFieldSlug($slug)) {

            return $assignment->type();
        }

        return null;
    }

    /**
     * Get the stream data.
     *
     * @return array
     */
    public function getStream()
    {
        return $this->stream;
    }

    /**
     * Return the table name with the stream prefix.
     *
     * @return string
     */
    public function getTable()
    {
        $stream = $this->getStream();

        return $stream->prefix . $stream->slug;
    }

    /**
     * Return a new collection instance.
     *
     * @param array $items
     * @return \Illuminate\Database\Eloquent\Collection|EntryCollection
     */
    public function newCollection(array $items = [])
    {
        return new EntryCollection($items);
    }

    public function decorate()
    {
        return new EntryPresenter($this);
    }

    /**
     * Get the assignment object for a field.
     *
     * @param $field
     * @return mixed
     */
    public function getAssignmentFromField($field)
    {
        return $this->stream->assignments->findByFieldSlug($field);
    }

    /**
     * Get the field from a field.
     *
     * @param $field
     * @return mixed|null
     */
    public function getTypeFromField($field)
    {
        $assignment = $this->getAssignmentFromField($field);

        if ($assignment instanceof AssignmentModel) {

            return $assignment->type($this);
        }

        return null;
    }

    /**
     * Return a value from a field.
     *
     * @param $field
     * @return mixed
     */
    public function getValueFromField($field)
    {
        $fieldType = $this->getTypeFromField($field);

        if ($fieldType instanceof FieldType) {

            return $fieldType->decorate();
        }

        return null;
    }

    /**
     * Get the name of a field.
     *
     * @param $field
     * @return mixed
     */
    public function getFieldName($field)
    {
        $assignment = $this->getAssignmentFromField($field);

        if ($assignment instanceof AssignmentModel) {

            return $assignment->getFieldName();
        }
    }

    /**
     * Get the heading for a field.
     *
     * @param $field
     * @return mixed
     */
    public function getFieldHeading($field)
    {
        return $this->getFieldName($field);
    }

    /**
     * Get the label for a field.
     *
     * @param $field
     * @return mixed
     */
    public function getFieldLabel($field)
    {
        $assignment = $this->getAssignmentFromField($field);

        if ($assignment instanceof AssignmentModel) {

            if ($label = $assignment->getFieldLabel()) {

                return $label;
            }
        }

        return $this->getFieldName($field);
    }

    /**
     * Get the placeholder for a field.
     *
     * @param $field
     * @return mixed
     */
    public function getFieldPlaceholder($field)
    {
        $name = $this->getFieldName($field);

        $placeholder = str_replace('.name', '.placeholder', $name);

        if ($translated = trans($placeholder) and $translated != $placeholder) {

            return $placeholder;
        }

        return null;
    }

    /**
     * Get the default foreign key name for the model.
     *
     * @return string
     */
    public function getForeignKey()
    {
        return str_singular($this->stream->slug) . '_id';
    }

    /**
     * Get a specified relationship.
     *
     * @param  string $relation
     * @return mixed
     */
    public function getRelation($relation)
    {
        if (isset($this->relations[$relation])) {

            return parent::getRelation($relation);
        }

        return null;
    }

    public function isTranslatable()
    {
        return ($this->stream->is_translatable);
    }
}
