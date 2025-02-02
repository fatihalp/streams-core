<?php

namespace Streams\Core\Criteria\Adapter;

use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Streams\Core\Stream\Stream;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Config;
use Streams\Core\Entry\Contract\EntryInterface;

class FileAdapter extends AbstractAdapter
{

    protected $data = [];
    protected $query = [];

    /**
     * Create a new class instance.
     *
     * @param Stream $stream
     */
    public function __construct(Stream $stream)
    {
        $this->stream = $stream;

        $this->readData();

        $this->query = $this->data;
    }

    /**
     * Order the query by field/direction.
     *
     * @param string $field
     * @param string|null $direction
     * @param string|null $value
     */
    public function orderBy($field, $direction = 'asc')
    {
        $this->query = $this->query->sortBy($field, SORT_REGULAR, strtolower($direction) === 'desc' ? true : false);

        return $this;
    }

    /**
     * Limit the entries returned.
     *
     * @param int $limit
     * @param int|null $offset
     */
    public function limit($limit, $offset = 0)
    {
        if ($offset) {
            $this->query = $this->query->skip($offset);
        }

        $this->query = $this->query->take($limit);

        return $this;
    }

    /**
     * Constrain the query by a typical 
     * field, operator, value argument.
     *
     * @param string $field
     * @param string|null $operator
     * @param string|null $value
     * @param string|null $nested
     */
    public function where($field, $operator = null, $value = null, $nested = null)
    {
        if (!$value) {
            $value = $operator;
            $operator = '=';
        }

        $operator = strtoupper($operator);

        if ($field == 'handle') {
            $field = $this->stream->getPrototypeAttribute('config.handle', 'id');
        }

        $method = $nested ? Str::studly($nested . '_where') : 'where';

        if ($operator == 'LIKE') {
            $this->query = $this->query->filter(function ($entry) use ($field, $value) {
                return Str::is(
                    strtolower(str_replace('%', '*', $value)),
                    strtolower($entry[$field])
                );
            });
        } else {
            $this->query = $this->query->{$method}($field, $operator, $value);
        }

        return $this;
    }

    /**
     * Get the criteria results.
     * 
     * @param array $parameters
     * @return Collection
     */
    public function get(array $parameters = [])
    {
        $this->query = $this->collect($this->query);

        foreach ($parameters as $key => $call) {

            $method = Str::camel($key);

            foreach ($call as $parameters) {
                call_user_func_array([$this, $method], $parameters);
            }
        }

        return $this->query;
    }

    /**
     * Count the criteria results.
     * 
     * @param array $parameters
     * @return int
     */
    public function count(array $parameters = [])
    {
        return $this->get($parameters)->count();
    }

    /**
     * Create a new entry.
     *
     * @param array $attributes
     * @return EntryInterface
     */
    public function create(array $attributes = [])
    {
        $keyName = $this->stream->config('key_name', 'id');

        $key = Arr::get($attributes, $keyName);

        if (array_key_exists($key, $this->data)) {
            throw new \Exception("Entry with key [{$key}] already exists.");
        }

        $format = $this->stream->getPrototypeAttribute('source.format', 'json');

        if ($format == 'csv') {

            $fields = $this->stream->fields->keys()->all();

            $fields = array_combine($fields, array_fill(0, count($fields), null));

            $attributes = array_merge($fields, $attributes);
        }

        $this->data[$key] = $attributes;

        $this->writeData();

        return $this->make($attributes);
    }

    /**
     * Save an entry.
     *
     * @param  EntryInterface $entry
     * @return bool
     */
    public function save($entry)
    {
        $attributes = $entry->getAttributes();

        if (!$id = Arr::pull($attributes, 'id')) {
            throw new \Exception('The ID attribute is required.');
        }

        $format = $this->stream->getPrototypeAttribute('source.format', 'json') ?: 'json';

        if ($format == 'csv') {

            $fields = $this->stream->fields->keys()->all();

            $fields = array_combine($fields, array_fill(0, count($fields), null));

            $this->data[$id] = ['id' => $id] + array_merge($fields, $attributes);
        }

        if ($format == 'json') {
            $this->data[$id] = $attributes;
        }

        return $this->writeData();
    }

    /**
     * Delete results.
     *
     * @param array $parameters
     * @return bool
     */
    public function delete(array $parameters = [])
    {
        $this->get($parameters)->each(function ($entry) {
            unset($this->data[$entry->id]);
        });

        $this->writeData();

        return true;
    }

    /**
     * Truncate all entries.
     *
     * @return void
     */
    public function truncate()
    {
        $this->data = [];

        $this->writeData();
    }

    /**
     * Return an entry interface from a file.
     *
     * @param $entry
     * @return EntryInterface
     */
    protected function make($entry)
    {
        if ($entry instanceof EntryInterface) {
            return $entry;
        }

        return $this->newInstance(array_merge(
            [
                'id' => Arr::get($entry, 'id'),
            ],
            $entry
        ));
    }

    protected function readData()
    {
        $source = $this->stream->expandPrototypeAttribute('source');

        $format = $source->get('format');
        $file = base_path(trim($source->get('file', Config::get('streams.core.streams_data') . '/' . $this->stream->handle . '.' . ($format ?: 'json')), '/\\'));

        $format = $format ?: pathinfo($file, PATHINFO_EXTENSION);

        if (!file_exists($file)) {

            $this->data = [];

            return;
        }

        $keyName = $this->stream->config('key_name', 'id');

        if ($format == 'php') {

            $this->data = include $file;

            array_walk($this->data, function ($item, $key) use ($keyName) {
                $this->data[$key] = [$keyName => $key] + $item;
            });
        }

        if ($format == 'json') {

            $this->data = json_decode(file_get_contents($file), true);

            array_walk($this->data, function ($item, $key) use ($keyName) {
                $this->data[$key] = [$keyName => $key] + $item;
            });
        }

        if ($format == 'csv') {

            $handle = fopen($file, 'r');

            $i = 0;

            while (($row = fgetcsv($handle)) !== false) {

                if ($i == 0) {
                    $fields = $row;
                    $i++;
                    continue;
                }

                $row = array_combine($fields, $row);

                $this->data[Arr::get($row, $keyName, $i)] = $row;

                $i++;
            }

            fclose($handle);
        }
    }

    protected function writeData()
    {
        $source = $this->stream->expandPrototypeAttribute('source');

        $format = $source->get('format', 'json') ?: 'json';
        $file = base_path(trim($source->get('file', 'streams/data/' . $this->stream->handle . '.' . $format), '/\\'));

        if (!file_exists($file)) {
            mkdir(dirname($file), 0755, true);
        }

        if ($format == 'php') {
            file_put_contents($file, "<?php\n\nreturn " . Arr::export($this->data, true) . ';');
        }

        if ($format == 'json') {
            file_put_contents($file, json_encode($this->data, JSON_PRETTY_PRINT));
        }

        if ($format == 'csv') {

            $handle = fopen($file, 'w');

            fputcsv($handle, $this->stream->fields->keys()->all());

            array_map(function ($item) use ($handle) {
                fputcsv($handle, $item);
            }, $this->data);

            fclose($handle);
        }

        return true;
    }
}
